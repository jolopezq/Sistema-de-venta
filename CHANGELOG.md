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

- Inventario (plan [PLAN_INVENTARIO_REORDEN.md](docs/PLAN_INVENTARIO_REORDEN.md), F0): **Conteo físico** en lugar de
  «Ajuste» (se escribe lo que hay y el servidor calcula la diferencia contra el stock del momento);
  movimiento `initial` al crear un insumo con stock; comando `php artisan inventory:fix-initial-cost`
  (muestra o, con `--confirm`, corrige el CPP en 0 de insumos con stock).
- POS: aviso **«stock bajo»** en productos y opciones cuando el stock teórico no alcanza; no bloquea la venta.
- Inventario (plan, F1): **compras ligadas a insumos y proveedores**. Una compra es un gasto de Finanzas con líneas
  (`finance_expense_items`): cada línea con insumo sube el stock, actualiza el costo promedio (CPP) y deja un
  movimiento `restock` con su costo; las líneas sin insumo (gas, limpieza) cuentan solo como gasto. Se escribe lo
  pagado por línea y el sistema calcula el costo por unidad; si se compra en bolsas o cajas se indica cuántos kg
  o unidades trae cada una. Al **editar o eliminar** una compra el stock y el CPP se rehacen (movimiento
  `purchase_reversal`); si algún insumo tiene ingresos antiguos sin costo, el stock se corrige y el CPP se deja
  como estaba con un aviso. El estado de resultados no cuenta la compra dos veces.
- Un mismo formulario de compra en Finanzas → «Registrar compra» (y editar desde la lista, con la etiqueta
  «Compra · N líneas») e Inventario → «Ingreso» / «Registrar compra». Lo pueden usar admin y dueño
  (`GET /api/purchases/options`, `/api/purchases`).
- **Proveedores** (Inventario → «Proveedores»): nombre, teléfono, días de entrega y de pedido, notas. Se crean
  solos al escribir un proveedor nuevo en una compra (sin duplicar por mayúsculas, tildes ni espacios);
  `/api/suppliers`. Cada insumo guarda proveedor habitual, unidad y factor de compra y pedido mínimo (Editar insumo).
- Migraciones `2026_10_10_000002_create_suppliers_and_purchase_items` y
  `2026_10_10_000003_add_purchase_fields_to_ingredients_and_movements` (tablas y columnas nuevas; no modifican datos).
- Inventario (plan, F2): **composición de los componentes de costeo** (Finanzas → Costos y margen → Componentes de costo →
  «Composición»). Una porción como «Porción base açaí» o «Kit descartable chico» dice qué insumos reales consume
  (180 g de pulpa; 1 vaso + 1 tapa), en g, ml o unidades. Al vender, el sistema descuenta esos insumos (un solo nivel) y
  anular una venta devuelve cada movimiento tal como se registró; cambiar una composición no recalcula las ventas
  pasadas. El costo del componente pasa a ser la suma de sus insumos a su costo promedio, así que cambia solo cuando
  cambia el CPP; un componente sin composición sigue como antes (costo cargado, sin stock). `PUT
  /api/finance/costs/components/{id}/composition` (solo dueño).
- El catálogo del POS envía la receta de stock ya expandida (`stock_recipe` en productos y opciones); el POS, también sin
  conexión, solo suma y resta (`frontend/src/utils/stockRecipe.js`). El aviso de «stock bajo» de una opción cuenta lo que
  pide el producto base más el ajuste, así un tamaño Ohana avisa aunque alcance para el chico.
- No se puede eliminar un insumo que forma parte de la composición de un componente.
- Migración `2026_10_11_000001_create_ingredient_compositions` (solo crea la tabla; no modifica datos).
- Inventario (plan, F3): **mermas con motivo y valor**. «Registrar merma» en Caja (cajero) y en Inventario (admin): insumo,
  cantidad en g/ml/unidades, motivo obligatorio (vencido, dañado, derrame, error de preparación, prueba o cortesía,
  consumo del personal) y nota. La merma resta stock y queda valorada al costo promedio del momento; no se bloquea por falta
  de stock (avisa). El cajero solo ve lo que anotó él y no ve costos. `GET/POST /api/inventory/waste`.
- **Conteo físico** (Inventario → «Conteo»): se escribe lo que hay y el sistema muestra la diferencia en unidades y Bs; se
  revisa y, al confirmar, deja el stock igual a lo contado con un ajuste ligado al conteo. Filtro «Claves» / «Todos»,
  «contado hace N días» y un historial de conteos con sus líneas. La diferencia se calcula contra el stock del momento de
  confirmar. El primer conteo de un insumo ordena el stock de partida y no cuenta como merma oculta. Tablas
  `inventory_counts` e `inventory_count_lines`; `GET/POST /api/inventory/counts` (solo admin).
- Insumo clave (`ingredients.is_key`, casilla en Editar insumo): los que entran en el conteo semanal.
- **Historial por insumo** (Inventario → «Historial»): todos los movimientos, del más reciente al más antiguo, con tipo,
  cantidad, valor, detalle y quién; filtro por tipo y «Cargar más». `GET /api/ingredients/{id}/movements` ahora está
  paginado y es solo para admin (antes devolvía todo y lo podía leer cualquier usuario).
- **Mermas del mes** (Inventario): merma anotada por motivo, insumo y persona, y merma oculta (lo que los conteos
  encontraron de menos sin que nadie lo anotara). `GET /api/inventory/waste/report`.
- Finanzas → Punto de equilibrio: el costo real (compras del mes) frente al de las recetas se reparte en merma anotada,
  merma oculta y compras que siguen en stock y otros (`cost_gap`).
- Migración `2026_10_12_000001_create_inventory_counts` (tablas nuevas y la columna `is_key`; no modifica datos).
- Inventario (plan, F4): **punto de reorden y compras sugeridas**. Para cada insumo con stock el sistema calcula, con las
  ventas reales (menos los reversos de anulaciones y ediciones), el consumo diario de los últimos 28 días con ventas, el
  stock de seguridad (`1,65 × desvío × √días de entrega`; con menos de 28 días, días de seguridad × consumo), el punto de
  reorden y la cantidad a pedir para cubrir los días objetivo, redondeada a unidades de compra (bolsa, caja) y al pedido
  mínimo del proveedor, y limitada por la vida útil en los perecederos. Con 8 semanas de historia proyecta por día de la
  semana. Estados: OK, Reordenar, Crítico, Agotado, Compra diaria, Sin datos y Sin control, más aviso de vencimiento.
  `GET /api/inventory/reorder` y `/api/inventory/reorder/summary` (solo admin); las alertas se calculan al consultar, no
  se guardan.
- Arranque sin historial: mientras no hay 28 días de ventas se usa el consumo diario estimado por el dueño (o, con 3 a 27
  días, el promedio de lo vendido) y el stock mínimo hace de punto de reorden manual (nunca baja el calculado). Cada insumo
  indica si usa datos reales o una estimación. Un insumo cuenta sus días desde que se usó por primera vez.
- Inventario → **«Compras sugeridas»**: lista agrupada por proveedor (días de entrega, días de pedido, teléfono) con
  cantidades ajustables, estimado en Bs, y para cada grupo «Copiar», «WhatsApp» (enlace `wa.me`, sin servicios externos) y
  «Registrar compra» (abre el formulario de compra con proveedor y líneas ya puestos; el importe lo escribe quien compra).
  «Imprimir» y «Copiar todo» para la lista completa. La compra diaria (fruta) va en su propio grupo.
- Inventario: columna **Reposición** con el estado de cada insumo, el punto de reorden y los días que alcanza el stock; el
  formulario del insumo suma «Reposición y vencimiento» (cómo se repone, consumo diario estimado, punto de reorden manual,
  días de cobertura, días de seguridad, vida útil y fecha de vencimiento).
- Marca con el número de insumos por reponer en el menú «Inventario» (admin), tarjeta «Reposición del inventario» en
  Finanzas → Resumen y aviso «Antes de abrir» (agotados, críticos y por vencer) al administrador, una vez por turno, al
  abrir caja o al entrar a Caja con el turno ya abierto.
- Migración `2026_10_13_000001_add_reorder_fields_to_ingredients` (cinco columnas opcionales: `reorder_policy`,
  `target_days`, `safety_days`, `shelf_life_days` y `daily_estimate`; no modifica datos).
- Seeder `ReorderDefaultsSeeder`: precarga de proveedores (Distribuidora Açaí, Granola Artesanal, Mercado Central) y parámetros de reorden y vida útil de los insumos clave, marcando la fruta como compra diaria.
- Inventario (plan, F5): **indicadores de inventario** en Finanzas → Resumen (solo dueño), `GET /api/finance/inventory-indicators`
  (`year`, `month`). Seis indicadores, cada uno con su estado frente a la meta (en meta, cerca, fuera de meta o sin medir),
  la meta, un detalle y el mes anterior para comparar:
  - **Valor del inventario**: stock × costo promedio, a hoy (informativo), con los insumos sin costo o en negativo avisados.
  - **Días de cobertura**: lo que dura el insumo clave que primero se acaba, a hoy (si no hay claves marcados usa todos los
    que tienen consumo); el estado sale del punto de reorden de F4.
  - **Quiebres de stock**: días de venta del mes en que un insumo clave llegó a cero o menos, reconstruidos con los
    movimientos; meta: ninguno. No incluye productos marcados «agotado» a mano (no se guarda su historial).
  - **Merma sobre el consumo**: merma anotada en Bs ÷ consumo en Bs de las ventas (menos los reversos), total y por insumo;
    meta 5 % (10 % en perecederos).
  - **Diferencia de costo**: costo real (compras) menos costo por recetas, en puntos de las ventas; meta ±5 puntos.
  - **Exactitud del conteo**: insumos contados con diferencia de hasta 2 % ÷ insumos contados (sin los primeros conteos);
    meta 90 % o más.
- Finanzas → Resumen → **«Informe del mes»**: los seis indicadores con su meta más el detalle (cobertura de los insumos
  clave, quiebres con sus fechas, merma por insumo y la merma oculta de los conteos, conteos con más diferencia y dónde está
  el valor del inventario), listo para **imprimir** (A4, sin depender de la pantalla). Las metas son iniciales y están
  fijas en el servidor (`InventoryIndicatorsService::TARGETS`).
- Caja ([análisis del POS](https://claude.ai/code/artifact/0a10e4d8-9e98-424e-8ffe-8cc39486eaef), prioridad 1):
  **turno obligatorio para cobrar**. `GET /api/cash-sessions/active` devuelve `sales_session_id`, la caja que recibiría una
  venta ahora (la propia o la compartida de un admin, con la misma regla de `CashSessionResolver`). Sin caja, el POS cambia
  «Cobrar venta» por un aviso y «Abrir turno», que lleva a Turno con el formulario de apertura abierto. Sin conexión usa la
  copia del día; si no hay dato de hoy deja vender y avisa, para que el local no se quede sin vender.
- Caja: **revisión previa al cierre** (paso «Revisión» del asistente). Envía las ventas del dispositivo, trae las cifras al
  día y no deja cerrar mientras haya ventas pendientes o rechazadas. Un admin puede cerrar igual escribiendo el motivo, que
  queda en la nota del cierre y en la auditoría (`cash_close_forced`).
- Caja: `POST /api/cash-sessions/{id}/close` acepta `expected_seen` (el esperado que vio el cajero: si el servidor calcula
  otro, responde 409 con las cifras nuevas y el asistente las muestra para confirmar otra vez), `unsynced_sales` y
  `force_reason` (cajero: 422; admin: motivo de al menos 5 caracteres).
- Ventas **rechazadas a la vista**: tras 3 rechazos seguidos una venta pasa a «rechazada» con su motivo, deja de reintentarse
  sola y aparece en Turno (panel con «Reintentar envío»), en el indicador de red («N rechazadas») y en la revisión del cierre.
  Los motivos de validación de la sincronización están en español.
- Finanzas → **Resumen rediseñado** (dirección visual del 09/10: evolución de la identidad de Ohana, aprobada sobre la
  maqueta «Finanzas: dirección y maqueta»). El resultado del mes se lee como una frase («Septiembre cerró con Bs 9.094 de
  utilidad», «Octubre va con…», en pérdida o sin ventas), con la barra de qué queda de cada Bs 100 vendidos (los costos en
  tonos neutros y lo que queda en açaí; con pérdida, una marca muestra hasta dónde llegaron las ventas). Pegado al resultado
  va lo que falta cargar para que sea definitivo, cada aviso con su botón. Debajo: Estado de resultados y PedidosYa a la
  izquierda, punto de equilibrio y reposición a la derecha, y los indicadores de inventario como tabla, del que está peor al
  que está mejor, con estados de icono y texto. Mientras carga otro mes, el resultado avisa «Actualizando…».
- Punto de equilibrio: `progress.reached_day`, el día en que las ventas acumuladas cubrieron los costos fijos (las ventas
  diarias del POS escaladas al total del Estado de Resultados). El bloque lo dice en una frase («Cubriste los costos fijos
  el 8 de octubre») con la barra de los días del mes; el detalle (márgenes por canal, costo por recetas frente al real y
  merma) queda en «Ver de dónde sale».

### Cambiado
- Venta del dueño o de un admin **con el turno de un cajero abierto**: entra a ese turno (el local tiene una sola gaveta).
  `CashSessionResolver` agrega un tercer paso: si quien vende es admin o super_admin y no tiene caja propia ni hay una caja
  compartida de admin, la venta va al turno abierto en ese momento. Antes el POS bloqueaba al dueño con «No hay un turno
  abierto» y Caja le mostraba el turno del cajero sin forma de abrir otro. Un cajero sigue sin vender en el turno de otro
  cajero. 4 tests nuevos.
- Finanzas, todas las pestañas: título y encabezados de bloque más grandes, encabezados de tabla sin mayúsculas, cifras
  tabulares y botón principal en maracuyá como el resto del POS (antes açaí, que queda para «lo que te queda»). El Resumen
  ya no tiene las cuatro tarjetas de cifras ni la tarjeta «Para que el resultado sea real»: su contenido pasó al resultado
  del mes.
- Arqueo: el asistente de cierre, la tarjeta «Efectivo en gaveta» de Turno y el reporte impreso muestran solo efectivo
  (fondo + ventas + ingresos − egresos en efectivo = esperado) y los egresos con QR o tarjeta aparte. Antes se restaban los
  egresos totales y se omitían los ingresos extra, así que las filas no sumaban el esperado.
- El arqueo en progreso guardado en el navegador queda ligado a su caja: uno abandonado ya no aparece al cerrar otra caja.
- Inventario (F3): los movimientos de venta, merma y conteo guardan el costo promedio del momento (`unit_cost`,
  `total_cost`; vacío si el insumo no tiene costo). El reverso de una venta anulada o editada conserva el valor del
  movimiento original.
- Inventario (F3): el ajuste manual por diferencia ya no existe; `POST /api/inventory/movements` con `type=adjustment`
  exige `counted_stock` y deja un conteo de un solo insumo. La merma acepta los seis motivos nuevos.
- Inventario: el stock de un insumo ya no se edita desde el formulario (cambia con ingresos, mermas, conteos
  y ventas); el costo inicial fija el CPP; una unidad antigua se conserva al editar y las nuevas son kg,
  litros o unidades.
- El stock se descuenta con una sola sentencia SQL y SQLite espera hasta 5 s por el bloqueo de escritura
  (`DB_BUSY_TIMEOUT`, `DB_TRANSACTION_MODE=IMMEDIATE`; WAL opcional con `DB_JOURNAL_MODE=wal`).
- `inventory_movements.type`, `waste_category` y `reference_type` pasan de enum a texto (migración sin cambio de datos).
- En un producto por peso, las filas de la receta en kg se multiplican por los kilos vendidos y las demás (envase,
  cucharilla) van una vez por unidad, igual que el costeo; antes el descuento multiplicaba todas por los kilos.
- POS: el descuento y la restauración del stock local usan la misma regla que el servidor; se corrige que un ajuste de
  opción con receta dejara `NaN` en el stock local (leía `quantity_required` en vez de `quantity_delta`) y que anular un
  ticket pendiente de un producto por peso devolviera una unidad en vez de los kilos.
- Inventario: los ingresos de stock ya no se registran como movimiento suelto; `POST /api/inventory/movements`
  solo acepta `waste` y `adjustment` (conteo). Todo ingreso entra por una Compra, para que Inventario y Finanzas
  cuadren. Un gasto con líneas no se edita como gasto simple (se edita como compra); al eliminarlo desde
  Finanzas se devuelve el stock.
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
- Sincronización: una venta que no pasaba la validación hacía fallar todo el lote (422) y frenaba la cola entera. Ahora se
  reenvía venta por venta y solo queda retenida la que falla. La cola de cocina sigue mostrando las ventas rechazadas.
- Asistente de cierre: los botones deshabilitados («Continuar», «Confirmar cierre») se veían activos, había colores sin
  definir (`--lime-800`, `--danger-700`) y el distintivo «Arqueo de turno» quedaba bajo AA. En celular, el encabezado del
  asistente y los botones de Turno ya no se cortan.
- Costo promedio (CPP) mal calculado con stock inicial o negativo (10 kg a Bs 24 sobre 50 kg a Bs 12 daba
  Bs 4 en lugar de Bs 14; con stock negativo daba 24 en lugar de 18).
- Los productos por peso (Autoservicio) descontaban 1 kg de insumo por venta en lugar de los gramos vendidos,
  en el servidor y en el POS sin conexión.
- Una merma con cantidad positiva o un ingreso negativo ya no se aceptan (inflaban o vaciaban el stock).
- Prueba de caja que fallaba entre las 00:00 y las 02:00 por depender de la hora actual.
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
- POS: tras «Cargar recetas base», todos los productos con receta aparecían sin stock y no se podían vender (el
  catálogo enviaba los componentes de costeo, con stock 0, como insumos). El catálogo del POS ahora solo envía los
  insumos que llevan stock.
- Inventario: los componentes de costeo aparecían en la lista de insumos como «stock bajo» y se podían eliminar
  (stock 0); al eliminar uno, toda venta de un producto que lo usaba fallaba al sincronizar (error 500 que
  frenaba la cola del POS). Ahora Inventario solo lista lo que lleva stock, no se puede eliminar un insumo que
  está en una receta vigente (aviso 422 con cuántas recetas lo usan) y una venta se guarda aunque su receta tenga
  un insumo ya eliminado.

### Seguridad
- Permisos por rol aplicados en el backend; cocina sin acceso a ventas ni caja. (`938c31b`)
- Solo el dueño de una caja o un admin pueden operarla; abrir caja con fecha pasada es solo para admin. (`938c31b`)
- Límite de intentos de login, caducidad de tokens (7 días) y bloqueo de imágenes SVG. (`938c31b`)
- Restauración de backups validada, con copia previa y reemplazo atómico. (`938c31b`)
