# Análisis del Funcionamiento del Sistema de Pedidos y Comandas (Ohana Acai V3)

Este documento detalla la lógica completa detrás de la toma de pedidos, generación de comandas y el procesamiento final de las ventas, basado en la arquitectura Offline-First del sistema. Está diseñado como referencia para la futura implementación de un cliente de escritorio o módulo de registro manual y arqueo de caja.

## 1. Arquitectura General (Offline-First)
El sistema opera bajo un enfoque **Offline-First**, lo que significa que el Punto de Venta (POS) en el frontend puede funcionar y registrar ventas sin conexión a internet. 
- **Capa Local (Frontend):** Usa Vue.js, Pinia (manejo de estado) y Dexie.js (IndexedDB) como base de datos local.
- **Capa Servidor (Backend):** Usa Laravel y MySQL/MariaDB como la fuente de verdad.
- **Sincronización:** El frontend envía las ventas en lotes al backend cuando detecta conexión a internet, y el backend consolida la información (inventario, lealtad, reportes).

---

## 2. Flujo de Toma de Pedidos (Frontend)

El flujo principal ocurre en el store de Pinia `cart.js`.

### 2.1. Gestión del Carrito (`cart.js`)
1.  **Agrupación Inteligente:** Cuando se añade un producto (`addItem`), se genera un identificador único llamado `cart_key`. Este key se construye a partir del ID del producto, los modificadores seleccionados, notas de cocina, alérgenos y si es para llevar (`takeaway`). Si se añade un producto con exactamente la misma configuración, en lugar de crear una nueva línea en el ticket, se incrementa la cantidad del ítem existente.
2.  **Destino del Pedido:** El sistema soporta pedidos mixtos. Calcula dinámicamente si el pedido completo es para consumir en el local (`dine_in`), para llevar (`takeaway`), o mixto (`mixed`), basándose en las propiedades individuales de cada ítem.

### 2.2. Proceso de Checkout y Generación de la Comanda Local
Cuando el cajero finaliza la venta (`checkout`):
1.  **Generación de IDs (UUIDv4):** Se genera un UUID versión 4 localmente para identificar la venta de forma única. Esto evita colisiones de IDs cuando múltiples cajas operan offline.
2.  **Secuencia Diaria y Número de Orden:** Se genera un `order_number` con el formato `DDMMAA-XXXX` (Ej: 140926-0001). El correlativo (`XXXX`) se calcula consultando la base de datos local (IndexedDB) para buscar el número más alto del día en curso.
3.  **Persistencia Local:** Se crea el objeto de venta completo (con ítems, modificadores, subtotales y pagos) y se guarda en IndexedDB (`db.sales`) con el estado `sync_status = 'pending'`, listos para ser enviados al servidor.
4.  **Descuento de Inventario Local (Optimista):** El sistema descuenta *inmediatamente* el stock de los ingredientes en la base de datos local (IndexedDB -> `db.ingredients`) basándose en las recetas del producto y sus modificadores. Esto permite que el cajero vea el stock actualizado incluso sin internet.
5.  **Limpieza:** Se vacía el carrito y se emite la orden para imprimir la comanda/ticket de cocina a través de la interfaz.

---

## 3. Lógica de Sincronización (Red)

El store `network.js` y el servicio `syncService.js` manejan la comunicación con el servidor.

1.  **Detección de Red:** El sistema escucha constantemente los eventos `online` y `offline` del navegador.
2.  **Envío en Lotes (Batching):** Cuando hay conexión, se recogen todas las ventas en IndexedDB con `sync_status = 'pending'` y se envían al backend a la ruta `POST /sales/sync`. Además, existe un proceso en segundo plano que reintenta la sincronización cada 30 segundos si detecta conexión y hay ventas pendientes.
3.  **Confirmación:** El backend procesa las ventas y devuelve una lista de los UUIDs que se insertaron correctamente. El frontend actualiza estos registros en IndexedDB a `sync_status = 'synced'`.
4.  **Limpieza (Pruning):** Periódicamente, el frontend ejecuta una rutina para eliminar de la base de datos local (IndexedDB) aquellas ventas que ya están sincronizadas (`synced`) y que tienen más de 7 días de antigüedad, evitando así saturar el almacenamiento del navegador del dispositivo.

---

## 4. Procesamiento Final (Backend - Laravel)

El controlador `SaleController` y el servicio `SaleSyncService` reciben el lote de ventas y consolidan la información de manera segura.

### 4.1. Recepción y Transaccionalidad
1.  **Idempotencia:** Por cada venta en el lote, el servidor verifica primero si el UUID ya existe en la base de datos. Si existe, la asume como sincronizada y la ignora. Esto evita cobrar o descontar inventario doblemente si la red falló justo durante la confirmación de la petición HTTP.
2.  **Transacciones ACID:** Todo el proceso de almacenamiento de una venta (insertar cabecera, detalles de ítems, pagos, descontar inventario) se envuelve en un bloque `DB::transaction`. Si algo falla internamente, se hace *rollback* únicamente de esa venta, sin afectar al resto de ventas procesadas en el mismo lote.

### 4.2. Registro y Consolidación
Por cada venta procesada exitosamente en el servidor:
1.  **Cabecera e Ítems:** Se inserta el registro principal en la tabla `sales` y todos sus detalles en `sale_items` y `sale_item_options` (modificadores).
2.  **Pagos (SalePayments):** Se registran los métodos de pago (Efectivo, QR, Tarjeta, etc.) en la tabla `sale_payments`. Un mismo ticket soporta múltiples métodos de pago concurrentes.
3.  **Descuento de Inventario (Real):** El `InventoryService` calcula el consumo de ingredientes basándose en las recetas asociadas a los productos e inserta registros de trazabilidad en la tabla `inventory_movements` (tipo `sale`).
4.  **Fidelización:** Si la venta está asociada a un cliente registrado, se calculan automáticamente los puntos de lealtad (`LoyaltyConfig`) y se acreditan en su perfil.

---

## 5. Control, Edición y Arqueo (Casos de Uso para Nuevo Proyecto)

Para el desarrollo del nuevo módulo de escritorio destinado al registro manual de ventas y operaciones de arqueo, es vital integrar y comprender las capacidades actuales del backend para la modificación de ventas cerradas:

1.  **Registro Retroactivo (`adminCreateSale`):** El backend provee un endpoint especial para que un Super Admin inserte ventas en el pasado de forma manual. La lógica del servidor se encarga de recalcular el correlativo del día (`daily_sequence`) para la fecha especificada y genera registros de seguimiento en el `AuditLog`.
2.  **Edición Manual (`adminUpdateSale`):** Si se detecta un error de caja durante el arqueo, el sistema permite editar una venta cerrada. Para mantener la integridad referencial, el backend ejecuta los siguientes pasos transaccionalmente:
    *   **Reverso de Inventario:** Revierte los movimientos de inventario de la venta original (sumando de nuevo el stock a los ingredientes).
    *   **Reverso de Puntos:** Resta los puntos de lealtad originalmente otorgados al cliente.
    *   **Limpieza y Reescritura:** Elimina los ítems y pagos anteriores en base de datos, actualiza los nuevos totales, y vuelve a insertar los nuevos ítems, descontando el inventario corregido y recalculando puntos.
    *   **Auditoría:** Obliga a proporcionar un motivo de edición (`edit_reason`) y registra el cambio detallado y los montos anteriores/nuevos en el `AuditLog`.
3.  **Anulación (`voidSale`):** Al anular un ticket, la aplicación prohíbe el uso de `DELETE` directo. En su lugar, el sistema cambia el estado de la venta a `voided` (usando Soft Deletes conceptuales para auditoría), revierte los movimientos de inventario y descuenta los puntos de fidelización, exigiendo registrar qué usuario autorizó la anulación y por qué motivo.
