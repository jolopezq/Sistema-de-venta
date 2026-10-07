# Changelog

Cambios relevantes del sistema. Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Cada cambio funcional se agrega a "Sin publicar" en el mismo commit; al fusionar en `main`, la sección
se renombra con la fecha de la actualización de producción.

## [Sin publicar] — rama `mejoras/analisis-integral`

### Agregado
- Movimientos de caja (egresos e ingresos extra) con método de pago; distribución del efectivo al cierre
  y desglose de billetes; Centro de Control Diario para ventas retroactivas. (`7644554`)
- Cada venta queda vinculada a su sesión de caja (`sales.cash_register_session_id`, con backfill que
  reproduce el cálculo anterior). (`35ba606`)
- Recálculo de importes en el servidor (`SalePricingService`) y auditoría de diferencias con el catálogo. (`7005e0e`)
- Rol cocina disponible en el formulario de usuarios. (`2ecb266`)
- Fuentes Inter, Baloo 2 y JetBrains Mono alojadas localmente y precacheadas (funcionan sin internet). (`624702e`)
- `frontend/scripts/audit-contrast.cjs`: auditoría de contraste WCAG por pantalla. (`2802a36`)
- `AGENTS.md`, `CLAUDE.md`, `docs/BITACORA.md` y este archivo para la gestión de cambios.
- Arqueo con PedidosYa aparte: `pedidosya_count` y `pedidosya_total` en las estadísticas de la caja.
- POS: selector de canal **Local / PedidosYa**. En PedidosYa se aplican los precios de plataforma de
  producto, tamaño y toppings, se avisa qué ítems no tienen precio PedidosYa, el cobro es un único pago de
  plataforma (sin efectivo, cambio, cliente ni puntos), se puede anotar el Nº de pedido de la tablet y,
  tras la venta, el POS vuelve a Local. Cambiar de canal con productos en el ticket pide vaciarlo.
- Reporte de cierre, asistente de cierre, Turno y Control Diario muestran "Ventas del local" y un bloque
  PedidosYa aparte (informativo, no se rinde en caja).
- Productos con casilla **"Disponible en PedidosYa"** (`products.available_on_pedidosya`). En modo PedidosYa
  el POS solo muestra los disponibles; la migración deja fuera los productos por peso (Autoservicio). Una
  venta PedidosYa de un producto no disponible (POS con catálogo viejo) se acepta y queda en auditoría.
- En modo PedidosYa, las tarjetas sin precio de plataforma muestran la etiqueta "precio local" antes de
  agregarlas al ticket.
- **Ajuste de precio por línea en pedidos PedidosYa**: en el ticket, "💲 Ajustar" permite cobrar otro precio
  (promo de la plataforma, precio desactualizado) con motivo obligatorio. La línea muestra el precio del
  tarifario tachado y el motivo; el recibo y el detalle de la venta lo indican. Se guarda en
  `sale_items.price_override_reason` y queda en auditoría como `pedidosya_price_override` (no como
  discrepancia). En ventas del local el cajero no puede ajustar precios y un motivo recibido se ignora y se
  audita. En Control Diario, el modo "✏️ Manual Libre" de un pedido PedidosYa acepta el mismo motivo.
- **Tarifario PedidosYa** (Menú → "🛵 Tarifario PedidosYa"): tabla con todos los productos, tamaños y toppings
  con su precio de mostrador y su precio PedidosYa editable, la disponibilidad de cada producto y un resumen
  de cuántos usan el precio local. "Aplicar +X %" (a los vacíos o a todos, redondeo a 0,50) llena una vista
  previa que se revisa antes de guardar. Se guarda en lote con `PUT /api/admin/pricing/pedidosya` (solo
  admin), que nunca toca el precio de mostrador y deja cada cambio en auditoría.
- **Módulo de Finanzas — Fase 1 (Estado de Resultados mensual y gestión contable)** (`docs/PLAN_MODULO_FINANZAS.md`):
  - Nuevo módulo `/finanzas` exclusivo para `super_admin` con protección en router, backend y menú lateral (`AppShell.vue`).
  - Plan de cuentas y categorías (`expense_categories`): precarga de 14 categorías estándar organizadas por sección (`cost_of_sales`, `operating`, `financial`, `taxes`) y grupos contables.
  - Compras y gastos pagados fuera de caja (`finance_expenses`): registro rápido con fecha, categoría, proveedor, monto, método de pago, comprobante y notas.
  - Gastos fijos recurrentes (`recurring_expenses`): plantillas de costos fijos (alquiler, salarios, internet, régimen simplificado) con frecuencia y día estimado, prorrateados en el cálculo mensual.
  - Clasificación de egresos en turnos de caja (`cash_movements.expense_category_id`): selector opcional de categoría contable en `CashExpenseModal.vue` para clasificar egresos en tiempo real.
  - Cálculo automatizado del Estado de Resultados (`FinanceReportService` / `FinanceReportController`):
    - Ventas del local (excluye PedidosYa para evitar duplicación).
    - Costo de Ventas (COGS - Método A: compras registradas del mes + egresos clasificados).
    - Utilidad Bruta y margen bruto (%).
    - Gastos Operativos (OPEX: compras directas + gastos fijos + egresos de caja clasificados y sin clasificar).
    - Utilidad Operativa (EBITDA) y margen operativo (%).
    - Gastos Financieros e Impuestos fijos del régimen simplificado.
    - Utilidad Neta y margen neto (%).
  - Pantalla `Finance.vue` con 4 pestañas interactivas, KPIs visuales, tabla jerárquica del P&L, selector de período mensual y modales de gestión.
  - Fixture anonimizado (`semana_pedidosya_anonimizada.csv`) para pruebas de PedidosYa sin datos personales ni NIT.
- **Módulo de Finanzas — Fase 2 (Liquidaciones semanales de PedidosYa, conciliación bancaria y análisis de canal)** (`docs/PLAN_MODULO_FINANZAS.md`):
  - Tablas `pedidosya_settlements` y `pedidosya_orders` creadas por migración con almacenamiento JSON de datos crudos.
  - Servicio `PedidosYaImportService`: lectura nativa de CSV semanal de PedidosYa, detección de BOM, formateo de importes con comas decimales, conversión de fechas a hora boliviana (`America/La_Paz`) e idempotencia por `order_number` y `settlement_id`.
  - Conciliación de 8 montos del estado de cuenta PDF (ventas netas, envío, comisiones de servicio, cargos operativos, publicidad, reintegros, impuestos y total pagado al banco).
  - Integración en `FinanceReportService`:
    - Incorporación de ventas netas de liquidaciones en ingresos totales (`revenue.pedidosya_net`).
    - Costos netos de plataforma (comisiones + publicidad + impuestos retenidos − reintegros) integrados a gastos operativos (OPEX).
    - Bloque de análisis de rentabilidad PedidosYa (`pedidosya_analysis`): ventas brutas en app, total depositado en cuenta bancaria, retención de plataforma (monto y %), y desglose de pedidos entregados vs cancelados.
  - Controlador `PedidosYaSettlementController` con endpoints protegidos para `super_admin`.
  - Frontend:
    - Pestaña "🛵 Liquidaciones PedidosYa" en `Finance.vue` con resumen mensual y tabla de semanas.
    - Banner de rentabilidad PedidosYa en el Estado de Resultados.
    - Modal de importación con subida de CSV y comprobante ZIP/PDF, y validador de conciliación en vivo (alerta visual según discrepancia).
    - Modal/drawer para visualizar todas las órdenes de la semana con filtros por estado (Todos / Entregados / Cancelados).
  - Suite de pruebas `PedidosYaSettlementTest` en verde.
- **Precio PedidosYa solo en tamaños** (`docs/PLAN_PRECIOS_TAMANOS_PEDIDOSYA.md`):
  - Nueva columna `option_groups.pedidosya_pricing` (migración activa por defecto para grupos "Tamaño...").
  - En PedidosYa, solo los grupos marcados aplican `delivery_price`; toppings y frutas cobran siempre el precio local en ambos canales.
  - `SalePricingService` y `utils/channelPricing.js` sincronizados con la nueva regla.
  - `PedidosYaPricingController` rechaza con 422 cualquier intento de asignar tarifa de delivery a opciones de grupos no marcados.
  - El POS no alerta de "Sin precio PedidosYa" para toppings ni frutas.
  - En Opcionales (Menú), casilla "🛵 Precio distinto en PedidosYa", campo exclusivo en el modal de edición de opcionales e indicadores visuales claros en la lista de opciones.
  - El Tarifario PedidosYa lista únicamente los tamaños de grupos marcados en su sección de opcionales.

### Cambiado
- El arqueo de caja suma las ventas por sesión en lugar de por rango de horas (sin doble conteo). (`35ba606`)
- El sobrepago en efectivo se registra como cambio, no como ingreso. (`7005e0e`)
- Los pedidos PedidosYa (`source = 'pedidosya'`) ya no suman a las ventas del local ni al efectivo esperado,
  aunque se hayan registrado con otro método de pago; se guardan siempre con un único pago
  `delivery_platform` por el total (POS y Control Diario) y el ajuste queda en auditoría.
- La verificación de precios contra el catálogo usa el precio PedidosYa (`delivery_price`, o el local si
  está vacío) en los pedidos de ese canal; el precio guardado de cada topping también es el del canal.
- Botones naranjas y verdes con texto oscuro; textos naranjas y grises más contrastados. (`b7b6aa4`)
- Usuarios y Auditoría usan la tipografía del sistema; el tema respeta la preferencia del sistema operativo. (`2802a36`)

### Corregido
- La sincronización descartaba notas, alérgenos, estado de preparación, número de ticket y cantidad de
  modificadores. (`852ae61`)
- Las ventas del POS se guardaban en UTC (+4 h) en lugar de la hora de La Paz. (`35ba606`)
- Al recargar la página, los administradores perdían su rol y su menú. (`2ecb266`)
- El chip PedidosYa de Delivery y cocina no alcanzaba el contraste AA (4,46); el método `delivery_platform`
  se muestra como "PedidosYa" en el recibo y en el historial de Turno.
- Una caja abierta con fecha pasada empezaba a las 08:00 y dejaba fuera del arqueo las ventas de madrugada
  de ese día; ahora empieza a las 00:00.
- Las reglas de modo oscuro de los componentes nunca se aplicaban (`:global(html.dark)` en estilos scoped);
  pantallas de cocina, cobro y Control Diario con texto ilegible. (`2802a36`)

### Seguridad
- Permisos por rol aplicados en el backend; cocina sin acceso a ventas ni caja. (`938c31b`)
- Solo el dueño de una caja o un admin pueden operarla; abrir caja con fecha pasada es solo para admin. (`938c31b`)
- Límite de intentos de login, caducidad de tokens (7 días) y bloqueo de imágenes SVG. (`938c31b`)
- Restauración de backups validada, con copia previa y reemplazo atómico. (`938c31b`)
