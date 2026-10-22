# Plan: Venta Multi-Ítem en Control Diario y Venta Directa de Toppings

> **Archivos:** [AdminSaleEditor.vue](../frontend/src/components/AdminSaleEditor.vue), [RetroactiveSales.vue](../frontend/src/views/RetroactiveSales.vue), [Pos.vue](../frontend/src/views/Pos.vue), [catalog.js](../frontend/src/stores/catalog.js), [cart.js](../frontend/src/stores/cart.js), base de datos / catálogo backend.  
> **Reglas:** [AGENTS.md](../AGENTS.md).  
> **Rama:** `fix/modal-gasto-saldo-sesion` (o rama dedicada de feature `feat/control-diario-multi-item-toppings`).  
> **Estado:** Propuesta de diseño y plan técnico para aprobación del usuario.

---

## 1. Contexto y Objetivos

En el negocio real de Ohana Açaí se presentan dos situaciones operativas clave:

1. **En Control Diario («Registrar Venta o Comanda del Día»):**
   - Cuando se cargan ventas acumuladas (talonario manual, contingencia o reconstrucción de turno), la comanda de un cliente habitualmente contiene **más de un ítem** (ej: 2 Classic Bowls, 1 Jugo y 2 porciones de toppings).
   - **Problema actual:** El selector de productos se cierra inmediatamente tras agregar un solo ítem (`showProductPicker = false`), y el ítem agregado se renderiza como una tarjeta masiva y expandida (con decenas de checkboxes de opciones, frutas, modo de precio manual/peso, notas, etc.). Para agregar un segundo o tercer ítem, el usuario debe desplazarse hacia abajo, presionar de nuevo `＋ Añadir Producto`, buscarlo y repetir el proceso. Es un flujo lento, verticalmente saturado e interrumpido.
   - **Objetivo:** Permitir un flujo de agregado continuo y ágil como en la pantalla de **Ventas (POS)**, donde se puedan **apilar múltiples productos en la comanda** de forma rápida, verlos organizados en una lista compacta, ajustar cantidades y opciones, y luego proceder a registrar el pago y la venta.

2. **Venta directa de Toppings sueltos (sin base de açaí):**
   - En ocasiones los clientes compran **únicamente toppings** (ej: un vasito de ositos de gomita, chubi, chispas de chocolate o fruta extra), sin ordenar un bowl de açaí.
   - **Problema actual:** En el sistema, los toppings solo existen en la tabla `options` (grupo de opciones `option_groups` #4 «Toppings»), asociados exclusivamente como modificadores a los productos de açaí (Bowls de Bs 28/30). No existe ninguna forma en el POS (`Pos.vue`) ni en Control Diario (`AdminSaleEditor.vue`) de cobrar un topping sin seleccionar antes un envase de açaí.
   - **Objetivo:** Habilitar la venta directa de toppings a precio de porción (ej: Bs 4.00 c/u) tanto en **Ventas (POS)** como en **Control Diario**, descontando adecuadamente el stock del insumo correspondiente en inventario.

---

## 2. Análisis del Flujo Actual vs. Flujo Propuesto

### 2.1 Flujo en Control Diario (`AdminSaleEditor.vue`)

```
FLUJO ACTUAL (Lento e interrumpido):
[Buscar producto] ──> Clic en "Classic Bowl" ──> [SE CIERRA CATÁLOGO] ──> [Tarjeta gigante expandida] ──>
Scroll hacia abajo ──> Clic en "+ Añadir Producto" ──> [ABRIR CATÁLOGO] ──> Clic en "Jugo de Piña" ──>
[SE CIERRA CATÁLOGO] ──> [Segunda tarjeta gigante expandida] ──> Repetir...

FLUJO PROPUESTO (Ágil, apilado continuo como en POS):
[Catálogo abierto con categorías/búsqueda rápida]
 ├── Clic en "Classic Bowl"  ──> Se apila en comanda (fila compacta x1, Bs 30.00)
 ├── Clic en "Classic Bowl"  ──> Aumenta cantidad (x2, Bs 60.00) o añade otra variante
 ├── Clic en "Jugo de Piña"  ──> Se apila en comanda (fila compacta x1, Bs 10.00)
 └── Clic en "Gomitas"       ──> Se apila en comanda (fila compacta x1, Bs 4.00)
[Comanda visible al lado / pie con lista de ítems, botones + / −, editar opciones si se requiere, subtotal en vivo]
 └── [Proceder al Pago / Registrar Venta]
```

### 2.2 Anatomía de la Comanda Apilada en Control Diario
En lugar de tarjetas de 500 px de alto por cada producto agregado:
- **Fila de ítem colapsada por defecto:**
  - Nombre del producto en negrita (ej: **Classic Bowl**).
  - Resumen breve de opciones seleccionadas en texto suave (ej: *Tamaño: Mediano | Fruta: Frutilla | Toppings: Granola, Leche en polvo*).
  - Stepper de cantidad compacto: `[ − ] [ 2 ] [ ＋ ]`.
  - Subtotal calculado en vivo: **Bs 60.00**.
  - Botón desplegable `[⚙ Opciones]` para ajustar frutas/toppings o modo de precio si es necesario.
  - Botón `[🗑️]` para quitar ítem.
- **Catálogo de selección persistente:**
  - El selector de productos no se cierra al hacer clic en un producto; permite seguir haciendo clic en otros productos para apilarlos de inmediato en el ticket.
  - Se puede cerrar o colapsar con un botón `[✕ Cerrar catálogo]` cuando se termine de armar la comanda, o tener un botón claro `[＋ Añadir más productos]`.

---

## 3. Análisis Técnico: Cómo Vender Toppings Sueltos

Los toppings en Ohana Açaí tienen:
- Registro en `options` (id, name, additional_price = 4.00, delivery_price = 5.00, option_group_id = 4).
- Vínculo a inventario en `option_recipes` (option_id -> ingredient_id, quantity_delta, ej. 20g de gomitas).
- Insumos en `ingredients` con control de stock (`tracks_stock = 1`).

Para que los toppings puedan venderse solos en POS y en Control Diario, evaluamos dos alternativas:

### Alternativa A: Categoría «Toppings / Extras» en el Catálogo de Productos (Recomendada)
- Se crea una categoría en `categories` llamada **«Toppings»** (o «Extras y Toppings»).
- Se registran los toppings individuales en la tabla `products`:
  - `name`: "Topping: Ositos de Gomita" (o "Ositos de Gomita"), etc.
  - `price`: Bs 4.00 (precio mostrador).
  - `delivery_price`: Bs 5.00 (PedidosYa).
  - `category_id`: ID de la categoría Toppings.
  - `recipes`: Vincula al insumo correspondiente en `recipes` con su gramaje de porción.
- **Ventajas:**
  - **100% nativo y sin parches:** Un producto es un producto. No requiere alterar la lógica de `sales`, `sale_items`, `cart.js`, ni migraciones complejas.
  - **En Ventas (POS):** Aparece automáticamente la pestaña «Toppings» en el filtro de categorías. El cajero toca «Toppings», ve los botones de los toppings con sus fotos/nombres y precio (Bs 4.00), toca «Gomitas» y entra directo al carrito en 1 segundo.
  - **En Control Diario:** Aparecen en la búsqueda y en la categoría «Toppings» del catálogo, listos para apilarse con un clic.
  - **En Cocina / KDS:** El ticket imprime claramente: `1x Ositos de gomita`.
  - **En Inventario y Costos:** Cada venta descuenta automáticamente el stock mediante el motor estándar de recetas (`recipes`).
  - **En PedidosYa:** Si se activan, se pueden vender también por delivery a su precio correspondiente.

### Alternativa B: Producto comodín «Porción de Topping» con grupo de opciones
- Se crea un único producto "Porción de Topping" (Bs 0 o Bs 4) con el grupo de opciones `Toppings`.
- **Desventaja:** Obliga a abrir el modal de modificadores (`ModifierModal`) cada vez que se quiere vender un topping suelto, lo cual ralentiza el cobro de algo tan rápido como una bolsita/vasito de gomitas.

> **Recomendación:** Implementar la **Alternativa A**. Es más rápida para el cajero, genera tickets limpios y no introduce código ad-hoc ni excepciones en el carrito.

---

## 4. Fases de Implementación

### Fase 1: Catálogo de Toppings Sueltos (Backend y Base de Datos)
1. **Creación/Sincronización de Categoría y Productos de Toppings:**
   - Asegurar la categoría `Toppings` (id, name: 'Toppings', sort_order).
   - Registrar los productos de toppings con su precio unitario estándar (Bs 4.00 para local, Bs 5.00 para delivery si aplica).
   - Asociar sus recetas en `recipes` apuntando al insumo en `ingredients` (usando las cantidades ya definidas en `option_recipes`).
   - Seeder o migración idempotente para asegurar que no se dupliquen al desplegar.
2. **Pruebas en POS (`Pos.vue`):**
   - Verificar que al pulsar la categoría «Toppings» en el POS se muestren los ítems.
   - Probar que hacer clic en un topping lo añada directo al carrito (sin requerir elegir envase de açaí).
   - Verificar cálculo de subtotal, cobro en efectivo/QR y ticket final.

### Fase 2: Rediseño del Flujo de Apilado en Control Diario (`AdminSaleEditor.vue`)
1. **Catálogo Persistente (No auto-cierre):**
   - Modificar `addProductToSale(product)`: no cerrar `showProductPicker` tras cada clic.
   - Permitir seguir seleccionando productos consecutivos.
   - Añadir feedback visual inmediato (un badge o micro-animación en la tarjeta del producto o notificación de "Añadido a la comanda").
2. **Estructura de Ítems Apilados (Modo Comanda Compacta):**
   - Cada ítem agregado se muestra en una fila compacta (`item-compact-row`) con:
     - Orden / Número (#1, #2...).
     - Nombre del producto.
     - Botón `[Modificar / Opciones]` que solo abre el modal o panel de opciones si el usuario necesita cambiar algo.
     - Stepper de cantidad `[−] [cant] [＋]`.
     - Subtotal.
     - Botón eliminar `[🗑️]`.
   - Si el producto no tiene grupos de opciones (o si es un topping / jugo simple), no muestra bloques de opciones vacíos.
3. **Sección de Pago y Cierre de Venta:**
   - Al terminar de apilar los productos, la sección inferior muestra el resumen de comanda (Total ítems, Total Bs).
   - Selección de método de pago (Efectivo, QR, Tarjeta) y botón destacado `[💾 Registrar Venta de la Comanda]`.
   - Limpieza y reinicio rápido para la siguiente venta sin recargar la página.

### Fase 3: Pruebas y Verificación
1. **Prueba funcional en Control Diario:**
   - Crear una venta con 3 ítems distintos (ej: Bowl + Bebida + Topping suelto) en una fecha seleccionada.
   - Comprobar que los 3 ítems se apilen correctamente en la comanda.
   - Registrar la venta y validar que el Arqueo de Caja (`RetroactiveSales.vue`) actualice el total vendido y el desglose de efectivo.
2. **Prueba funcional en Ventas (POS):**
   - Vender un topping suelto directamente desde el POS.
   - Vender un bowl con toppings + un topping suelto adicional.
   - Validar ticket y sincronización.
3. **Validación técnica obligatoria:**
   - `cd backend && php artisan test` (todo en verde).
   - `cd frontend && npx vite build` (build sin errores).
   - `scripts/audit-contrast.cjs` si se añaden nuevos componentes visuales.

---

## 5. Preguntas de Decisión para el Usuario

Antes de comenzar a programar:
1. **Precio de los Toppings sueltos:** ¿Todos los toppings sueltos tienen un precio único (ej. Bs 4.00) o hay algunos con precios distintos?
2. **Lista de Toppings vendibles:** ¿Deseas que se incluyan todos los toppings actuales del menú (Gomitas, Leche condensada, Chispas, Chubi, Granola, Coco, etc.) en la categoría «Toppings»?
3. **Distribución en pantalla en Control Diario:** ¿Prefieres que el catálogo de productos esté en una cuadrícula siempre visible arriba/a un lado de la lista de ítems apilados, o mediante un panel desplegable que permanezca abierto mientras vas añadiendo?


---

## 6. Revisión contra el código (2026-10-21) y decisiones

**El plan se cumple en lo esencial.** Verificado: el catálogo de Control diario se cerraba tras cada ítem (`addProductToSale`),
los toppings solo existen como opciones, y un producto sin grupos de opciones entra directo al carrito del POS (`handleAddToCart`),
por lo que la Alternativa A funciona sin tocar el carrito. Correcciones al plan:

1. **También afecta a `Turno.vue`**, que monta el mismo `AdminSaleEditor`. Una sola edición cubre Control diario, Turno y editar venta.
2. **No usar migración con ids fijos**: los ids de opciones difieren entre la base local y producción. Se creó el comando idempotente
   `catalog:sync-toppings` que busca por nombre; sin `--apply` solo simula.
3. **No todas las opciones del grupo «Toppings» son vendibles**: «Sin granola» y «Sin leche en polvo» devuelven stock (receta negativa) y se excluyen;
   hay nombres repetidos (p. ej. «Moritas» dos veces) y se toma uno.
4. **Inventario**: los toppings con receta usan el insumo «Topping extra (porción)» (cantidad 1). «Leche condensada», «Leche en polvo» y la segunda
   «Moritas» **no tienen receta**: se crean, pero no descuentan stock hasta que se les asigne.
5. **Precio**: todas las opciones valen Bs 4; el precio delivery se copia de la opción o, si no hay, el de mostrador (no Bs 5 fijo).
   `available_on_pedidosya` queda en falso.
6. **Las tres preguntas** quedaron resueltas: precio único Bs 4 (tomado de cada opción), se incluyen todos los toppings vendibles,
   y en Control diario el catálogo queda abierto sobre la lista con resumen de comanda en el propio catálogo (en la portátil no cabe una cuadrícula al lado).
7. **Aún no hay edición de precio en bloque**: si cambia el precio de una opción, el producto suelto no se actualiza solo; se cambia en Menú.
