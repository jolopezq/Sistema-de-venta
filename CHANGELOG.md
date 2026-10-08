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
- Finanzas (auditoría, fase 1): `GET /finance/recurring-expenses/pending` y `POST …/generate` (idempotente;
  un gasto generado y eliminado no se vuelve a proponer salvo que se pida); plantillas con `start_month` y
  forma de pago.
- Finanzas: avisos en el Estado de Resultados (`warnings`): mes sin compras, egresos de caja sin clasificar,
  gastos fijos sin generar, semanas PedidosYa sin cargar y semanas con diferencias de conciliación.
- Finanzas: `GET /finance/cash-movements` y `PATCH /finance/cash-movements/{id}/category` para clasificar
  desde Finanzas los egresos de caja que quedaron sin categoría.
- PedidosYa: `POST /finance/pedidosya-settlements/preview` y semana tomada del CSV (lunes a domingo) si no se
  indica; se avisan los pedidos fuera del rango y se rechazan los que ya pertenecen a otra semana.
- PedidosYa: conciliación completa por semana (`reconciliation`): total liquidado, comisión del CSV = servicios
  e (informativa) ventas brutas − descuentos PedidosYa = ventas netas; diferencias mayores a Bs 1.
- PedidosYa: una semana que cruza dos meses se prorratea por la fecha de cada pedido entregado; las ventas
  PedidosYa del POS de días sin semana importada se suman mientras tanto (con aviso).
- **PedidosYa (auditoría, fase 3): la importación semanal crea o vincula las ventas** (plan de Finanzas §6.2–6.4).
  Cada pedido entregado queda como venta PedidosYa con la fecha y hora del pedido, sus artículos y opciones, el
  monto de PedidosYa (si difiere del Tarifario la línea lleva el motivo «Precio PedidosYa (importado)» y queda en
  auditoría), un único pago de plataforma y estado entregado; descuenta inventario y no entra al arqueo. Una venta
  ingresada a mano se **vincula** (Nº de pedido en la nota, o misma hora ±30 min y mismo monto; el dueño puede
  elegir otra o crear una nueva). Los cancelados no crean venta. Reimportar no duplica.
- Equivalencias de nombres PedidosYa ↔ POS en `platform_catalog_mappings` (`/finance/pedidosya-mappings`): se
  proponen productos parecidos; una opción puede quedar «sin equivalencia» (se anota en la venta). La importación
  no se confirma mientras falten equivalencias, salvo que se elija importar solo los montos
  (`sales_mode=skip`); las ventas se pueden crear después (`GET/POST /finance/pedidosya-settlements/{id}/sales`).
- Informe de la semana (`GET …/{id}/report`, plan §6.6): ventas por forma de pago y más vendidos, gastos de
  PedidosYa con % sobre ventas, descuentos y reintegros, lo que se queda PedidosYa, costo de productos y ganancia
  estimada (con el % de costo de ventas del mes), conciliación, pedidos a otro precio que el Tarifario y pedidos
  sin venta. Se puede imprimir.
- `pedidosya_orders.sale_link` y `catalog_total` (migración `2026_10_08_000003`).
- Índices para los reportes por mes: `sales (status, created_at)`, `sales (source, created_at)` y
  `cash_movements (type, created_at)` (migración `2026_10_08_000004`).
- Finanzas: **registrar lo que no está en la lista**. Los desplegables de categoría (compras y gastos, gastos
  fijos y clasificación de egresos de caja) terminan en «+ Nueva categoría…»: se escribe el nombre, se elige la
  sección del Estado de Resultados (costo de ventas, operativos, financieros o impuestos) y queda creada y
  elegida. Avisa si ya existe una parecida para usarla en vez de duplicarla; el backend rechaza nombres repetidos
  aunque cambien mayúsculas, acentos o espacios (también contra categorías desactivadas). Solo el dueño crea
  categorías: en la caja, el cajero elige «Otro / no está en la lista», describe el egreso y el dueño lo clasifica.
- Compras y gastos: el proveedor sugiere los ya usados (`GET /finance/suppliers`), propone la categoría de su
  última compra y se guarda con la forma ya registrada («frutas del valle» → «Frutas del Valle»).
- Finanzas: **Costos y margen** (`docs/PLAN_COSTOS_PUNTO_EQUILIBRIO.md`). Nueva pestaña con el costo por receta de
  cada producto, su margen en el local y en PedidosYa (después del % que se queda la plataforma) y el costo de cada
  tamaño. El asistente «Cargar recetas base» crea los componentes de costeo del análisis (porción base, mix de
  frutas, pack de toppings, kits descartables por tamaño, helado por kg y envase del Autoservicio, café en grano,
  leche, azúcar, vasos de café, matcha, bombilla y siropes) con sus costos como valores iniciales editables, y las
  recetas de bowls, Arma tu Bowl, Autoservicio y café, más los ajustes por opción (tamaños, «Sin …», toppings extra y
  cada fruta de Arma tu Bowl). Editor de recetas con cantidades en g/ml y «rinde» (18 g de café para 2 tazas = 9 g por
  taza). Los componentes de costeo (`ingredients.tracks_stock = false`) no mueven el stock de Inventario.
- Finanzas: **Punto de equilibrio** en el Resumen: margen de contribución (por canal), costos fijos del mes (gastos
  fijos mensualizados + otros gastos en categorías fijas), ventas necesarias al mes y por día (y productos por día),
  avance del mes, ritmo, día en que se alcanza y proyección al cierre; un mes cerrado muestra su resultado.
  Sin recetas usa el costo de las compras del mes; sin ventas, el margen del mes anterior. `GET /finance/break-even`.
- Categorías con **tipo de costo fijo o variable** (`expense_categories.cost_behavior`; el costo de ventas queda
  variable). Se elige en Categorías y al crear una.
- Las ventas de productos por peso guardan los gramos pesados (`sale_items.weight_grams`) desde el POS y desde
  Control Diario; las ventas anteriores se costean con los gramos deducidos del precio.
- Migración `2026_10_09_000001_add_costing_fields` (`tracks_stock`, `weight_grams`, `cost_behavior`).

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
- Finanzas (auditoría, fase 2): pantalla rediseñada. El **Estado de Resultados** es la vista principal (tabla
  línea · Bs · % de ventas, con desglose por categoría y por movimiento), 4 indicadores arriba, avisos con acceso
  directo («Clasificar», «Revisar y generar», «Cargar semana») y resumen del canal PedidosYa. Selector de mes
  compacto, pestañas con contadores que se cargan con la página y scroll horizontal en pantallas angostas;
  en celular las listas se muestran como tarjetas y los formularios como hoja inferior.
- Finanzas: `Finance.vue` (2.554 líneas) se divide en `components/Finance/` (`FinanceSummary`,
  `FinanceStatementTable`, `FinanceExpenses`, `FinanceRecurring`, `FinanceCategories`,
  `PedidosYaSettlements`, `PedidosYaImportModal`, `FinanceModal`, `FinanceConfirm`, `finance.css`) y el
  store `stores/finance.js`. Solo tokens de `style.css` (nuevo `--danger-text`), modo oscuro incluido; 0
  textos bajo WCAG AA en las 5 pestañas y sus ventanas, en ambos temas, a 1440 y 390 px.
- Finanzas: confirmaciones y avisos con ventanas del sistema (sin `alert`/`confirm`); la fecha de un gasto
  nuevo es la fecha local (hoy, o el último día si el mes ya pasó).
- Compras y gastos: totales del filtro completo por sección (`totals` en `GET /finance/expenses`), aviso al
  elegir pago en efectivo y sección de egresos de caja con su categoría editable.
- Gastos fijos: panel del mes con pendientes, generados y descartados («Generar», «Recuperar»); las
  plantillas indican primer mes y forma de pago.
- PedidosYa: la carga de una semana lee primero el CSV (semana sugerida, totales, solapes, pedidos fuera de
  rango) y muestra la conciliación mientras se escriben los montos; el detalle de la semana muestra el estado de
  cuenta, las comprobaciones y los pedidos paginados con su hora local.
- `scripts/audit-contrast.cjs` recorre también las pestañas de Finanzas.

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
- Finanzas: la pantalla se dibujaba dentro de otro menú lateral (doble menú y doble encabezado, contenido
  cortado a la derecha). Ahora usa el marco común de la app.
- Finanzas: el Estado de Resultados cortaba los meses en hora UTC aunque las ventas se guardan en hora de La
  Paz (las ventas de las 00:00–04:00 del día 1 caían en el mes anterior). Los tests corren ahora con
  `APP_TIMEZONE=America/La_Paz`.
- Finanzas: la pestaña de liquidaciones PedidosYa aparecía siempre vacía (la respuesta paginada no se leía) y no
  filtraba por mes.
- Se restauran 10 imágenes de productos que un commit de graphify había borrado del repositorio (al fusionar en
  `main` se habrían borrado en producción).
- Finanzas: los gastos fijos se sumaban en todos los meses (incluso antes de existir, y cambiar el monto
  reescribía la historia; bimestrales y anuales se dividían). Ahora cada mes se **generan** como gastos reales
  (`finance_expenses.recurring_expense_id` + `period`, únicos por plantilla y mes) desde `start_month`; el
  bimestral, trimestral o anual entra completo en su mes. El Estado de Resultados solo lee gastos reales.
- Finanzas: editar o eliminar una compra o gasto respondía "actualizado" sin cambiar nada (el parámetro de la
  ruta no coincidía con el del controlador); lo mismo al ver o eliminar una semana PedidosYa.
- Finanzas: los gastos del último día del mes no aparecían en la lista ni en el Estado de Resultados (las
  columnas `date` se guardan con hora `00:00:00`). Las fechas se devuelven como `AAAA-MM-DD`.
- PedidosYa: se podían cargar semanas que se solapan (y sumarse dos veces); un CSV con columnas faltantes
  respondía error 500. Ahora se rechazan con 422 y un mensaje claro.
- Finanzas: el detalle de pedidos de una semana PedidosYa no cargaba (la respuesta paginada no se leía) y la
  conciliación del formulario sumaba los envíos en vez de restarlos.
- PedidosYa (revisión de la fase 3): una venta del POS vinculada a un pedido de una semana importada pero
  registrada un día fuera de esa semana se sumaba dos veces en el Estado de Resultados; un pedido con artículos
  ilegibles o un producto por peso creaba una venta vacía o a precio 0 (ahora se informa para registrarlo a
  mano); al reimportar una semana sin un pedido, se avisa que su venta sigue registrada; el ZIP subido se borra
  si la importación falla y el ZIP reemplazado se elimina.
- Finanzas: al reabrir un formulario mientras se recargaban las listas tras guardar, quedaba en «Guardando…»
  (botones desactivados y sin cerrar con Escape).
- Finanzas: al cambiar de mes rápido, una respuesta atrasada del mes anterior podía quedar en pantalla (el Estado de
  Resultados de septiembre con agosto elegido); ahora cada bloque muestra solo su última petición.
- Finanzas: en las ventanas con tablas (asistente de recetas) la tabla se encogía hasta desaparecer.
- Control Diario: al editar una venta por peso, los gramos se precargaban con la cantidad (1 g) en lugar de los
  gramos vendidos.

### Seguridad
- Permisos por rol aplicados en el backend; cocina sin acceso a ventas ni caja. (`938c31b`)
- Solo el dueño de una caja o un admin pueden operarla; abrir caja con fecha pasada es solo para admin. (`938c31b`)
- Límite de intentos de login, caducidad de tokens (7 días) y bloqueo de imágenes SVG. (`938c31b`)
- Restauración de backups validada, con copia previa y reemplazo atómico. (`938c31b`)
