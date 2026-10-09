# Auditoría — Inventario, mermas y punto de reorden

> Fecha: 08/10/2026 · Rama: `feat/inventario-reorden` (sale de `feat/costos-punto-equilibrio`, que ya incluye
> Finanzas y Costos y margen) · Plan derivado: [PLAN_INVENTARIO_REORDEN.md](PLAN_INVENTARIO_REORDEN.md).
>
> **Método.** Lectura del código con el grafo (`graphify-out/`) confirmada con `grep`. Pruebas automáticas
> (`php artisan test`: 175 en verde). Datos analizados en una **copia** de la base de la Mac tomada el
> 08/10/2026: 78 ventas, del 17/08 al 04/10. La base real **no** se modificó. Recorrido de punta a punta del
> stock en otra copia, por la API, con 20 pasos (§6).
>
> **Términos.**
> - **Stock teórico:** lo que el sistema cree que hay.
> - **CPP (costo promedio ponderado):** costo por unidad que mezcla compras a distintos precios.
> - **Merma:** lo que se pierde (se vence, se cae, se daña). Es **oculta** cuando no se registra y solo aparece
>   al contar.
> - **Punto de reorden:** cantidad en la que hay que volver a comprar para no quedarse sin insumo mientras llega
>   el pedido.

## 1. Resumen ejecutivo

**¿El sistema gestiona hoy correctamente el stock y las mermas? No.** La estructura existe: insumos, recetas,
movimientos con referencia a cada venta y reversos al anular o editar. Pero el stock que muestra no representa
lo que hay en el local, por cinco razones:

1. **Las ventas no descuentan insumos reales.** Ninguna receta vigente usa insumos con stock. Los 3 insumos de
   la base (pulpa 50 kg, granola 10 kg, banana 100 u) no cambiaron desde que se cargaron el 21/07, y no hay
   ni un solo movimiento de inventario. Las recetas base nuevas usan componentes de costeo, que por decisión
   del dueño no mueven stock.
2. **Compras e inventario no están conectados.** Una compra cargada en Finanzas no sube el stock, y un ingreso
   cargado en Inventario no llega al Estado de Resultados. Para tener las dos cosas hay que cargar todo dos veces.
3. **El costo promedio (CPP) se calcula mal en casos comunes.** Una compra de 10 kg a Bs 24 sobre 50 kg a Bs 12
   da Bs 4 en lugar de Bs 14. Con ese costo, cualquier receta con insumos reales mostraría márgenes inflados.
4. **Las mermas no tienen valor ni se descubren.** Solo el admin las registra, no guardan cuánto costaron y no
   hay conteo físico que compare el consumo teórico con el real.
5. **No hay alertas de compra.** Solo una marca roja en la tabla de Inventario cuando el stock está bajo el
   mínimo (y ningún insumo tiene mínimo cargado). No hay punto de reorden, proveedores, tiempos de entrega,
   lista de compras ni avisos de vencimiento.

**Lo que funciona bien y conviene conservar:**

- Anular y editar una venta revierten el stock exactamente una vez.
- Las opciones suman o restan insumos («Sin granola» devuelve la granola).
- Las ventas retroactivas y las creadas por la importación de PedidosYa descuentan como una venta normal;
  vincular un pedido a una venta ya cargada no descuenta de nuevo.
- La sincronización del POS es idempotente: reenviar una venta no la duplica.
- Cada movimiento queda ligado a la línea de venta que lo causó.

**Corregido durante la auditoría.** Eran fallas de la rama de costos, recién entregada, que habrían frenado las
ventas al usar «Cargar recetas base»:

- `16212b4`: el POS daba por agotados todos los productos con receta base.
- `9996d95`: los componentes de costeo aparecían en Inventario y se podían eliminar. Eliminar uno hacía fallar
  (error 500) la sincronización de toda venta de los productos que lo usaban.

El resto queda en el plan, a la espera de las decisiones del dueño.

## 2. Cómo se mueve el stock hoy

| Evento | Dónde | Efecto en el stock | Movimiento | Observación |
|---|---|---|---|---|
| Alta de insumo | `IngredientController@store` | Fija el stock inicial | Ninguno | El CPP queda en 0 aunque se cargue un costo (origen de H5) |
| Edición de insumo | `IngredientRepository::update` | **Sobrescribe** stock y CPP | Ninguno (solo `audit_logs`) | H10 |
| Ingreso de compra (Inventario) | `InventoryService::recordRestock` (líneas 18-44) | Suma y recalcula el CPP | `restock` | No crea gasto, no pide proveedor ni comprobante (H4) |
| Compra en Finanzas | `FinanceExpenseController` | Ninguno | Ninguno | Va al costo de ventas del Estado de Resultados (H4) |
| Venta del POS (local o PedidosYa) | `SaleSyncService::insertSaleItems` → `InventoryService::deductFromRecipe` / `deductFromOptionRecipes` | Resta receta × cantidad, más los ajustes de las opciones | `sale`, ligado a `sale_item` / `sale_item_option` | Los componentes de costeo no mueven stock; los productos por peso restan por unidad, no por gramos (H7) |
| Venta retroactiva (Control Diario) | `SaleSyncService::adminCreateSale` | Igual que una venta | `sale` | |
| Importación semanal PedidosYa | `PedidosYa/SalesLinker.php:247-255` | Descuenta solo en los pedidos que crea; los vinculados a una venta existente no | `sale` | Correcto |
| Edición de venta | `SaleSyncService::adminUpdateSale` (357-400) | Revierte y vuelve a descontar | `adjustment` (reverso) + `sale` | Correcto (probado) |
| Anulación | `SaleSyncService::voidSale` (639-701) | Revierte | `adjustment` | Correcto; una segunda anulación se rechaza |
| Merma | `InventoryService::recordManualMovement` | Suma la cantidad (la pantalla la envía negativa) | `waste` + motivo | Sin valor en Bs; solo admin (H11-H13) |
| Ajuste | Igual | Suma la diferencia que se escribe | `adjustment` | Hay que calcular la diferencia a mano; no hay conteo (H14) |
| POS sin conexión | `stores/cart.js:320-355` | Resta en la copia local para mostrar disponibilidad | — | Las mismas reglas, repetidas en el navegador (H21) |
| Disponibilidad en el POS | `views/Pos.vue:89-104`, botón en 330 | Desactiva el producto si un insumo de su receta no alcanza para 1 unidad | — | Ignora las opciones y bloquea ventas con stock teórico negativo (H8, H20) |

## 3. Hallazgos

Severidad: **crítico** (detiene ventas o deja datos falsos), **alto** (decisiones con números equivocados),
**medio** (falta de control o trazabilidad), **bajo** (mejora).

| ID | Módulo | Severidad | Evidencia | Impacto en el negocio | Recomendación |
|---|---|---|---|---|---|
| H1 | POS / Costos | Crítico · **corregido** `16212b4` | `CatalogController.php:33` enviaba todos los insumos; `Pos.vue:99` comparaba con el stock 0 de los componentes de costeo | Tras «Cargar recetas base», el POS no dejaba vender bowls, café ni Autoservicio | Catálogo solo con insumos que llevan stock. Prueba `FinanceCostApiTest::test_pos_catalog_does_not_send_costing_components_so_products_stay_sellable` |
| H2 | Inventario / Ventas | Crítico · **corregido** `9996d95` | `InventoryService.php:72` (antes asignaba sobre `null`); `SaleSyncService.php:61` captura `Exception` pero no `Error`. Paso 18: HTTP 500 | Un insumo eliminado que seguía en una receta hacía fallar la sincronización y frenaba la cola del POS. Los componentes de costeo se podían eliminar desde Inventario | Inventario solo lista lo que lleva stock, no se elimina un insumo en uso (422) y la venta se guarda igual. Pruebas `InventoryIntegrityTest` |
| H3 | Recetas → stock | Crítico | 0 filas en `inventory_movements`; la única receta con insumos reales es de un producto eliminado; recetas base con `tracks_stock = false` | El stock mostrado es ficción, así que no hay punto de reorden posible | Decisión D1 del plan: componentes con composición (sub-receta) para los insumos clave |
| H4 | Compras ↔ Inventario ↔ Finanzas | Alto | `FinanceExpense` sin líneas de insumo; `recordRestock` no crea gasto. Pasos 4-5: la compra en Finanzas no movió el stock y el ingreso de Inventario no movió el costo de ventas | Doble carga o cifras que no cuadran entre Inventario y Finanzas | Una sola «Compra» con líneas: sube el stock, actualiza el CPP y crea el gasto (F1) |
| H5 | Costos | Alto | `InventoryService.php:21-33` usa `weighted_avg_cost = 0` del stock inicial. Paso 3: CPP 4 en lugar de 14 | Costos de receta y márgenes inflados apenas se use el CPP (`Ingredient::costPerUnit`) | El CPP inicial es el costo del alta; corregir los datos existentes con confirmación (F0) |
| H6 | Costos | Alto | Misma fórmula con stock negativo. Paso 16: 5 kg a Bs 18 sobre −1,25 kg da CPP 24 | Igual que H5 después de vender sin stock | Con stock ≤ 0, el CPP pasa a ser el costo de la compra nueva (F0) |
| H7 | Productos por peso | Alto | `InventoryService.php:67` (receta × cantidad) y `cart.js:326`. Paso 8: Autoservicio de 400 g restó 1 kg de helado | El helado del Autoservicio se descontaría 2-3 veces de más | Descontar por `sale_items.weight_grams` ÷ 1000, como ya hace el costeo (F0) |
| H8 | POS / Stock | Alto | Paso 15: se vende sin stock y queda en −1,25 kg sin aviso; `Pos.vue:99` desactiva el producto | Si no se registran todas las compras, el POS deja de vender productos que sí hay | Decisión D3: avisar sin bloquear. Stock negativo marcado como «revisar» (F0/F4) |
| H9 | Concurrencia | Alto (supuesto) | `current_stock -= …` lee, modifica y escribe: `InventoryService.php:77, 133, 169`; `SaleSyncService.php:397, 681`. No se reprodujo porque el servidor de prueba atiende de a una petición | Con dos ventas simultáneas se puede perder un descuento (Fase 5b de la bitácora) | Resta atómica en SQL + SQLite `IMMEDIATE`, `busy_timeout` y WAL (Fases 5a/5b) |
| H10 | Inventario | Medio | `IngredientRequest.php:29, 32`. Paso 13: stock 999 sin movimiento (solo `audit_logs`) | El stock cambia sin rastro en el historial del insumo | El stock solo cambia por movimientos; el alta crea un movimiento «stock inicial» (F0) |
| H11 | Mermas | Medio | `InventoryMovementRequest.php:26-28`. Paso 11: merma de +2 aceptada (HTTP 201), el stock subió | Una llamada mal hecha infla el stock | Validar en el servidor: merma siempre negativa (F0) |
| H12 | Mermas | Medio | `inventory_movements` sin columna de costo; solo 3 motivos (vencido, dañado, derrame) | No se sabe cuántos Bs se pierden por mes ni por qué | Guardar el CPP en cada movimiento y ampliar los motivos (F3) |
| H13 | Mermas / Roles | Medio | `routes/api.php:48-64` (solo admin). Paso 12: cajero 403 | El que ve el derrame no lo puede registrar y la merma queda oculta | Decisión D4: el cajero registra mermas con motivo y el dueño las revisa (F3) |
| H14 | Conteo | Medio | «Ajuste» pide la diferencia (`InventoryList.vue:476-482`); no hay cierre de inventario | La merma oculta (porciones más grandes, fruta que se pasa) nunca aparece | Conteo físico: se escribe lo contado y se ve la diferencia en unidades y Bs (F3) |
| H15 | Unidades | Medio | `IngredientRequest.php:26` (texto libre); columna `varchar`. Paso 2: «gramos» aceptado | Recetas y costos en unidades distintas; no se convierte el saco de 25 kg ni la caja de 50 vasos | Lista cerrada de unidades + unidad de compra con factor (F0/F1) |
| H16 | Alertas | Medio | Solo `InventoryList.vue:303-314`; `Ingredient::isBelowMinimumStock()` (Ingredient.php:87) sin uso; 0 mínimos cargados; `expiration_date` sin campo en la pantalla | Nadie se entera de que falta algo hasta que se acaba | Punto de reorden, estados y lista de compras (F4) |
| H17 | Proveedores | Medio | El proveedor es texto en Finanzas; Inventario no lo pide | No se puede calcular cuándo pedir sin días de entrega | Tabla de proveedores con días de entrega y lote mínimo (F1) |
| H18 | Inventario | Bajo | `GET /ingredients/{id}/movements` sin paginar (`InventoryMovementController.php:26-29`) y sin pantalla | No se puede revisar qué pasó con un insumo | Historial en la pantalla, paginado (F3) |
| H19 | Movimientos | Bajo | Reversos de ventas y ajustes manuales usan el mismo tipo `adjustment` | Para medir consumo hay que distinguirlos por `reference_type` | Tipo propio `sale_reversal` o filtrar por referencia (F4) |
| H20 | POS | Bajo | `Pos.vue:89-104` mira solo la receta del producto, no la del tamaño | Un Ohana (3 porciones) se ofrece aunque solo alcance para un chico | Contar opciones al avisar (F2) |
| H21 | Mantenimiento | Bajo | Descuento repetido en `cart.js:320-355` y en el servidor | Un cambio de reglas hay que hacerlo en dos lados | Que el catálogo envíe la receta ya expandida (F2) |
| H22 | Datos | Medio | Solo 10 días con ventas entre el 17/08 y el 04/10 (78 ventas; 2 días con 1 venta parecen pruebas) | Sin historial confiable no hay consumo promedio estadístico | Confirmar si todas las ventas se registran. Arranque con consumo estimado por el dueño (Plan §2.4) |
| H23 | Pruebas | Medio | Ningún test cubre ingreso de compra/CPP, mermas, ajustes, productos por peso ni stock negativo | Estos errores pasaron sin detectarse | Agregar las pruebas en cada fase |

## 4. Datos que faltan cargar (el dueño)

- **Recetas reales de los insumos clave.** El análisis de costos ya trae gramos de referencia: 180 g de pulpa
  por bowl chico, 25 g de granola, 15 g de leche en polvo, 25 g de leche condensada, 40-50 g por fruta.
  Faltan confirmarlos y agregar los descartables por tamaño.
- **Stock inicial real:** un primer conteo físico. Hoy hay 3 insumos con valores de prueba del 21/07.
- **Por insumo:** proveedor, días de entrega, unidad y lote de compra (bolsa de pulpa de cuántos kg, caja de
  cuántos vasos) y vida útil de los perecederos.
- **Consumo estimado para arrancar:** bowls por día habitual y por fin de semana, mientras se junta historial.
- **Confirmar** si todas las ventas pasan por el sistema. Si no, el consumo calculado quedará corto.

## 5. Datos de consumo observados

Copia de la base del 08/10. Sirven solo de referencia por la escasez de datos (H22).

| Medida | Valor |
|---|---|
| Días con ventas de bowls | 10 de 49 |
| Bowls por día con ventas | 11,9 en promedio (desvío 10,8; máximo 36) |
| Mezcla de tamaños | Chico 55 %, Grande 20 %, Mediano 18 %, Ohana 8 % |
| Pulpa estimada con 180 g por chico (+40 %, +80 % y +200 % por tamaño) | 2,96 kg por día con ventas (desvío 2,59; máximo 8,78) |
| Productos más vendidos | Classic Bowl 56, Hawaian Bowl 40, Capuchino 11, Arma tu Bowl 11 |

## 6. Recorrido de punta a punta en la copia

Recetas reales de prueba, agregadas a las recetas base: Classic Bowl 180 g pulpa + 25 g granola; Mediano
+72 g pulpa; «Sin granola» −25 g; Autoservicio 1 kg de helado por kg vendido. El script de la auditoría no
se incluye en el repositorio; los pasos se repiten como pruebas automáticas en cada fase del plan.

| # | Paso | Esperado | Resultado |
|---|---|---|---|
| 1 | Receta real desde Costos y margen | Se guarda | ✅ |
| 2 | Alta con unidad «gramos» | Rechazo | ❌ Se acepta (H15) |
| 3 | Ingreso 10 kg a Bs 24 sobre 50 kg a Bs 12 | CPP 14 | ❌ CPP 4 (H5) |
| 4 | La misma compra en Finanzas | Debería mover el stock | ❌ No lo mueve (H4) |
| 5 | Ingreso de Inventario en el Estado de Resultados | Debería sumarse | ❌ Solo suma el gasto de Finanzas (H4) |
| 6 | 2 Classic Bowl Mediano (local) | Pulpa −0,504 / granola −0,05 | ✅ |
| 7 | 1 Classic «Sin granola» (PedidosYa) | Pulpa −0,18 / granola 0 | ✅ |
| 8 | Autoservicio de 400 g | Helado −0,4 kg | ❌ −1 kg (H7) |
| 9 | Anular la venta del paso 6 | Pulpa +0,504 | ✅ |
| 10 | Anular otra vez | Rechazo sin cambio | ✅ |
| 11 | Merma con cantidad positiva por la API | Rechazo | ❌ Suma +2 kg (H11) |
| 12 | Derrame registrado por el cajero | Permitido | ❌ 403 (H13; decisión D4) |
| 13 | Editar el insumo con stock 999 | Movimiento de ajuste | ❌ Sin movimiento (H10) |
| 14 | Editar venta PedidosYa de 1 a 2 bowls | Pulpa −0,18 | ✅ |
| 15 | Vender más granola de la que hay | Aviso | ❌ Queda en −1,25 sin aviso; el POS desactiva el Classic Bowl (H8) |
| 16 | Compra de 5 kg a Bs 18 con stock negativo | CPP 18 | ❌ CPP 24 (H6) |
| 17 | Venta retroactiva | Pulpa −0,18 | ✅ |
| 18 | Venta con un insumo eliminado en la receta | Se guarda | ❌ HTTP 500 → **corregido** (`9996d95`) |
| 19 | Venta normal después | Se guarda | ✅ |
| 20 | Historial de movimientos por API | Lista | ✅ (sin pantalla, H18) |

Además se comprobó en el navegador que, tras «Cargar recetas base», el POS desactivaba los 10 productos
(corregido en `16212b4`) y que Inventario mostraba 18 componentes de costeo como «stock bajo» (corregido en
`9996d95`).
