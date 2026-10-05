# Plan: canal PedidosYa — arqueo separado, precios por canal y registro desde el POS

> Prompt técnico para el agente que implemente el cambio. Antes de empezar, lee `AGENTS.md` y
> `docs/BITACORA.md` y sigue sus reglas (rama, plan aprobado, respaldo, tests, CHANGELOG y bitácora).

## 1. Objetivo

Los pedidos de PedidosYa se registran en el mismo sistema que las ventas del local, con la misma
estructura (producto, tamaño, toppings), pero:

1. **No entran al arqueo de caja.** El cajero solo rinde el dinero del local. PedidosYa aparece como un
   bloque informativo aparte, con **un solo total**.
2. **Usan su propio precio.** El precio PedidosYa se edita sin tocar el precio de mostrador.
3. **El cajero los registra desde el POS**, copiándolos de la tablet de PedidosYa (ingreso manual).

## 2. Lo que ya existe (no volver a crearlo)

| Necesidad | Ya está en el código |
|---|---|
| Campo de canal en la venta | `sales.source` (`pos`, `pedidosya`, `manual_retroactive`). Validado en `SyncSalesRequest` y `AdminSaleRequest`. |
| Precio PedidosYa del producto | `products.delivery_price` (nullable). Se edita en `Menu/ProductForm.vue` ("🛵 Precio PedidosYa") y se ve en `AdminProductCard.vue`. |
| Precio PedidosYa de tamaños y toppings | `options.delivery_price`. Los tamaños y toppings son opciones de grupos (`Tamaño Acai`, `Tamaño Cafe`, `Toppings`, `Frutas`). Se edita en `Menu/OptionGroupDetail.vue`. |
| Método de pago de plataforma | `sale_payments.method = 'delivery_platform'`. |
| Registro con tarifa PedidosYa | Solo para admin, en Control Diario (`AdminSaleEditor.vue`): aplica `delivery_price` y, si falta, usa el precio local con un aviso. |
| Etiquetas PedidosYa en cocina y despacho | `KdsController`, `Delivery.vue`, `KdsOrderDetailModal.vue`, `SaleDetailDrawer.vue`. |
| Base para una futura API | Tablas `delivery_orders` y `platform_catalog_mappings` (sin uso real todavía). |

`product_variants` no se usa: la tabla no existe en la base actual. No la uses para los precios.

## 3. Decisiones tomadas

| Pregunta | Decisión |
|---|---|
| ¿Columna o lista de precios separada? | **Columna** (`delivery_price`), que ya existe en productos y opciones. |
| ¿Los toppings cambian de precio en PedidosYa? | Sí, cuando tienen `options.delivery_price`. Si está vacío, se usa `additional_price`. Lo mismo para productos (`delivery_price` vacío → `price`). |
| ¿Desglose por método en el bloque PedidosYa? | **No.** Un solo "Total PedidosYa" (cantidad de pedidos y monto). Los pedidos se pagan con `delivery_platform`. |
| ¿Hay que crear el campo de canal? | No, existe `sales.source`. |
| ¿Ingreso manual o por API? | **Manual**, por el cajero desde el POS. La API queda fuera de alcance. |
| ¿Cómo se separa en el arqueo? | **Por `source`, no por método de pago**, para que un pedido PedidosYa mal registrado como "efectivo" nunca sume a la gaveta. |

Nota sobre la fórmula: el arqueo no debe quedar como `Ingresos locales − Egresos`. Se mantiene la fórmula
actual, que ya considera el fondo y solo el efectivo:
`Efectivo esperado = Fondo inicial + Ventas en efectivo del local − Egresos en efectivo + Ingresos extra en efectivo`.

## 4. Cambios

### 4.1 Arqueo (backend)

`CashRegisterSessionController::calculateSessionStats()`:

- Separa las ventas completadas de la sesión en **locales** (`source != 'pedidosya'`) y **PedidosYa**
  (`source = 'pedidosya'`).
- `sales_count`, `total_sales`, `total_discounts`, `cash_sales`, `qr_sales`, `card_sales` y
  `other_sales` se calculan solo con las ventas locales.
- Agrega `pedidosya_count` y `pedidosya_total` (suma de `total_amount` de las ventas PedidosYa).
- `expected_cash` usa solo `cash_sales` del local (fórmula sin otros cambios).
- Las cajas ya cerradas conservan el arqueo guardado al cierre (decisión vigente en la bitácora).

### 4.2 Pantallas de arqueo (frontend)

En `CashSessionReport.vue` (reporte impreso), `CashCloseWizard.vue`, `Turno.vue` y `RetroactiveSales.vue`:

- El bloque de ventas pasa a llamarse **Ventas del local** (efectivo, QR, tarjeta y total del local).
- Debajo, un bloque aparte **PedidosYa (informativo, no se rinde en caja)** con cantidad de pedidos y total.
- Quita la fila actual "Ventas Plataformas (PedidosYa)", que hoy depende de `other_sales`.
- El arqueo de efectivo (gaveta) no cambia visualmente.

### 4.3 POS del cajero: selector de canal

- `stores/cart.js`: estado `channel` (`'pos'` | `'pedidosya'`). En el payload de la venta,
  `source: this.channel` (hoy está fijo en `'pos'`).
- Crea un helper único de precios por canal (producto y opción: `delivery_price > 0` → ese, si no → precio
  local) y úsalo en `cart.js` (alta y edición de ítems) y en `ModifierModal.vue` (cálculo de extras, precio
  base y las etiquetas `+Bs` de tamaños y toppings). Reutiliza la misma regla que `AdminSaleEditor.vue`.
- El canal se cambia **con el carrito vacío**; si hay ítems, pide confirmación para vaciarlo. Así no se
  mezclan precios en un mismo pedido.
- Mientras PedidosYa está activo, el POS lo muestra claramente (banner o color) y avisa si algún producto
  o topping no tiene precio PedidosYa.
- Al cobrar en modo PedidosYa: un solo pago `delivery_platform` por el total, sin pantalla de efectivo ni
  cambio, sin precio VIP ni puntos de fidelización.
- Después de cada venta el canal vuelve a **Local**, para que la siguiente venta de mostrador no salga con
  precios PedidosYa.
- Recomendado: campo opcional "Nº de pedido PedidosYa" que se guarda en la nota de la venta
  (o en `delivery_orders.external_id`, que cocina y despacho ya muestran como `#PY-…`). Si agregas un campo
  al payload, agrega su regla en `SyncSalesRequest` o se descarta.

### 4.4 Validación de precios (backend)

- `SalePricingService::catalogPriceMismatch()` compara siempre contra el precio local. Recibe el canal y,
  para `pedidosya`, compara contra `delivery_price` (con la misma regla de respaldo). Si no, cada pedido
  PedidosYa genera una discrepancia falsa en `audit_logs` (`sale_sync_discrepancy`). Llamada en
  `SaleSyncService.php` (~línea 192).
- En `SaleSyncService`, si `source = 'pedidosya'`, normaliza los pagos a un único `delivery_platform` por el
  total (POS y Control Diario) y registra la discrepancia si llegaron con otro método.
- El inventario se descuenta igual que en una venta local (sin cambios).

### 4.5 Datos existentes

- Antes de fusionar, revisa en una **copia** de la base de producción si hay ventas PedidosYa con pagos
  distintos de `delivery_platform`:
  `SELECT s.id, s.order_number, p.method, p.amount FROM sales s JOIN sale_payments p ON p.sale_id = s.id WHERE s.source = 'pedidosya' AND p.method <> 'delivery_platform';`
- No corrijas datos reales sin confirmación explícita del usuario.

## 5. Tests (backend/tests/Feature)

1. Arqueo: fondo 100, venta local en efectivo 50, PedidosYa `delivery_platform` 38 y PedidosYa con pago
   `cash` 20 → `expected_cash = 150`, `total_sales = 50`, `pedidosya_count = 2`, `pedidosya_total = 58`.
2. Sincronización desde el POS con `source = 'pedidosya'` y precio `delivery_price` → sin discrepancia.
3. PedidosYa con producto o topping sin `delivery_price` cobrado a precio local → sin discrepancia.
4. Pagos de PedidosYa enviados como `cash` → quedan como `delivery_platform`.
5. Los tests existentes (`AdminSaleManagementTest`, `KdsApiTest`, `SaleSyncTest`) siguen en verde.

## 6. Criterios de aceptación

- [ ] El cajero puede registrar un pedido PedidosYa desde el POS con precios PedidosYa en producto, tamaño y toppings.
- [ ] Cambiar el precio PedidosYa en el menú no altera el precio de mostrador.
- [ ] El efectivo esperado y el total del local no incluyen pedidos PedidosYa, aunque tengan un método distinto.
- [ ] El reporte de cierre muestra el bloque PedidosYa aparte, con cantidad y total.
- [ ] Tras una venta PedidosYa, el POS vuelve a canal Local.
- [ ] `php artisan test` en verde, `npx vite build` correcto y flujo completo probado contra una copia de la base.

## 7. Fuera de alcance

- Integración por API o webhook con PedidosYa.
- Comisiones de la plataforma y conciliación de liquidaciones.
- Desglose por método de pago dentro de PedidosYa.
- Otras plataformas (el diseño por `source` permite agregarlas después).

## 8. Forma de trabajo

- Rama nueva desde `mejoras/analisis-integral` (este cambio usa `sales.cash_register_session_id`, que aún
  no está en `main`).
- Consulta `graphify-out/` antes de tocar código; está desactualizado, regenéralo primero.
- Presenta el plan y espera aprobación. Respaldo de la base antes de migrar.
- Un commit por tema, en español, con `CHANGELOG.md` ("Sin publicar") y `docs/BITACORA.md` actualizados.
