# Bitácora del proyecto

Registro vivo para que cualquier agente o persona retome el trabajo. Actualízala en el mismo commit
que el cambio que la motiva. Reglas de trabajo: [AGENTS.md](../AGENTS.md). Historial: [CHANGELOG.md](../CHANGELOG.md).

## Estado actual (2026-10-09)
- **Rama de trabajo:** `feat/inventario-reorden` (auditoría, plan aprobado y Fases 0, 1 y 2 del plan de inventario; sale de `feat/costos-punto-equilibrio`, que sale de `feat/modulo-finanzas`).
- **Tests:** 229 en verde (`php artisan test`, 1235 aserciones, con `APP_TIMEZONE=America/La_Paz`). Build de Vite sin errores (`npx vite build`).
- **Contraste:** 0 textos bajo WCAG AA en POS e Inventario (dark y light). En la copia de prueba salen 1 aviso en Turno (botón deshabilitado sobre degradado) y 7 en Delivery y Control Diario (dependen de los datos de la copia); no pertenecen a esta rama.
- **Sin commit a propósito:** `graphify-out/` (grafo, caché y `obsidian/`, ~2.000 archivos) y la
  eliminación de `.graphify_detect.json`, a la espera de decisión (ver Pendientes).

## Plan de mejora

| Fase | Estado | Commit |
|---|---|---|
| 1. La sincronización conserva notas, alérgenos y número de ticket | ✅ | `852ae61` |
| 2. Seguridad: roles en backend, propiedad de caja, login, tokens, backups | ✅ | `938c31b` |
| 3. El servidor recalcula importes y normaliza pagos | ✅ | `7005e0e` |
| 4. Ventas vinculadas a su sesión de caja + zona horaria | ✅ | `35ba606` |
| 5. Stock atómico, puntos de fidelización y sincronización robusta | ⏸️ Aparcada por el usuario | — |
| 6. Refactor y rendimiento | Pendiente | — |
| UI. Modo oscuro, modo claro (WCAG AA) y fuentes locales | ✅ | `2802a36`, `b7b6aa4`, `624702e` |
| Canal PedidosYa: arqueo aparte, precios por canal, registro desde el POS ([plan](PLAN_PEDIDOSYA.md)) | ✅ en rama `feat/canal-pedidosya` | `a15b50b`, `a2e3f96` |
| Precios PedidosYa solo en tamaños ([plan](PLAN_PRECIOS_TAMANOS_PEDIDOSYA.md)): grupo `pedidosya_pricing` | ✅ en rama `feat/precios-pedidosya` | `b81d960` |
| Módulo de Finanzas: Fase 1 P&L mensual, compras, gastos fijos y categorías ([plan](PLAN_MODULO_FINANZAS.md)) | ✅ en rama `feat/modulo-finanzas` | `4cdc459` |
| Módulo de Finanzas: Fase 2 Importación semanal PedidosYa, conciliación y análisis de canal ([plan](PLAN_MODULO_FINANZAS.md)) | ✅ en rama `feat/modulo-finanzas` | ver registro |
| Finanzas — corrección de la [auditoría](AUDITORIA_FINANZAS.md): fases 0 (bloqueantes), 1 (cálculos), 2 (interfaz) y 3 (importación crea ventas) | ✅ en rama `feat/modulo-finanzas` | `9a5f871`, `f2319e1`, `36ec4ad`, ver registro |
| Finanzas — auditoría fase 4 (optimización) | ✅ en rama `feat/modulo-finanzas` | ver registro |
| Finanzas — costos por receta, margen de contribución y punto de equilibrio ([plan](PLAN_COSTOS_PUNTO_EQUILIBRIO.md)) | ✅ en rama `feat/costos-punto-equilibrio` | ver registro |
| Inventario confiable, mermas y punto de reorden ([auditoría](AUDITORIA_INVENTARIO_MERMAS.md), [plan](PLAN_INVENTARIO_REORDEN.md)): plan aprobado el 09/10 (D1–D7) | En curso en `feat/inventario-reorden` |  |
| ↳ F0 Correcciones de base (CPP, stock solo por movimientos, peso, validaciones, atomicidad, POS avisa) | ✅ | ver registro |
| ↳ F1 Compras ligadas a insumos y proveedores | ✅ | ver registro |
| ↳ F2 Recetas que mueven stock (composición de componentes) | ✅ | ver registro |
| ↳ F3 Mermas y conteo físico | Pendiente | — |
| ↳ F4 Punto de reorden y alertas | Pendiente | — |
| ↳ F5 Indicadores | Pendiente | — |

## Pendientes

### Decisiones del usuario
- [ ] Fusionar `feat/canal-pedidosya` en `mejoras/analisis-integral` (sale de esa rama).
- [ ] Confirmar antes de fusionar `feat/precios-pedidosya`: las migraciones
      `2026_10_07_000001_add_available_on_pedidosya_to_products_table` y
      `2026_10_07_000003_add_pedidosya_pricing_to_option_groups_table` **cambian datos** al instalarse
      (deja los productos por peso como no disponibles en PedidosYa y marca los grupos 'Tamaño...' con precio propio).
- [ ] Completar el tarifario PedidosYa (Menú → Tarifario PedidosYa): al 07/10 la base local tiene precio en
      los bowls (30) pero no en Arma tu Bowl, Capuchino, 4 toppings ni Tamaño Café Grande.
- [ ] Fusionar `feat/precios-pedidosya` en `feat/canal-pedidosya` (sale de esa rama) y ejecutar
      `php artisan migrate` en la Mac con respaldo previo (dos migraciones nuevas).
- [ ] Fusionar `mejoras/analisis-integral` en `main` (dispara la actualización de producción).
- [ ] `graphify-out/`: regenerar el grafo (está desactualizado respecto al código) y commitearlo, o
      ignorar `cache/` y `obsidian/` en git.
- [ ] Finanzas: ejecutar `php artisan migrate` en la Mac (con respaldo previo) para las 4 migraciones
      `2026_10_08_*` antes de probar la rama `feat/modulo-finanzas`.
- [ ] Finanzas: cargar en la pestaña Gastos fijos el mes de inicio real de cada gasto y generar los meses
      pasados que se quieran ver en el Estado de Resultados (los fijos ya no se suman solos).
- [ ] Tarifario PedidosYa: Tamaño Mediano +8 (hoy +7) y Arma tu Bowl 32 (hoy 35), según la semana 14–20/09.
- [ ] Pedir una nueva auditoría de `feat/modulo-finanzas` (fases 0 a 4 terminadas) antes de fusionarla.
- [x] Inventario: decisiones D1–D7 y preguntas de [PLAN_INVENTARIO_REORDEN.md](PLAN_INVENTARIO_REORDEN.md) respondidas
      el 09/10 (ver §1 y §6 del plan).
- [ ] Inventario (dueño): cuando termine de probar, ejecutar en la laptop `php artisan inventory:fix-initial-cost`
      (muestra qué cambiaría) y luego con `--confirm`, con respaldo previo. Corrige el CPP en 0 de pulpa, granola y
      banana. Hasta entonces el cálculo de costos y las compras ya usan el último costo como respaldo.
- [ ] Inventario (dueño): pasar los gramos de pulpa, granola, etc. y los descartables de cada tamaño y receta
      (se cargan en Finanzas → Costos y margen → «Composición», desde F2: hasta que un componente tenga composición
      no descuenta stock); proveedor, unidad y lote de compra de cada insumo clave (se cargan en
      Inventario → Editar insumo, desde F1) y, si se quiere, el consumo diario estimado para arrancar el punto de
      reorden (F4).
- [ ] Inventario (dueño): desde F1 los ingresos de stock se registran como **Compra** (Finanzas → Registrar compra o
      Inventario → Ingreso). Una compra de mercado con precios que varían se anota con lo pagado por línea; el CPP
      absorbe la variación. Las compras antiguas de Finanzas (sin líneas) siguen como gasto y no mueven stock.
- [ ] Inventario: `php artisan migrate` en la Mac y en la laptop con respaldo previo
      (`2026_10_10_000001_relax_inventory_movement_columns`: solo cambia la definición de columnas de
      `inventory_movements`; `2026_10_10_000002_create_suppliers_and_purchase_items`,
      `2026_10_10_000003_add_purchase_fields_to_ingredients_and_movements` y
      `2026_10_11_000001_create_ingredient_compositions`: tablas y columnas nuevas; ninguna modifica datos).
      Ya aplicadas en la base de la Mac el 09/10 (con las de finanzas y costos que estaban pendientes), salvo la
      de composición; falta repetirlo en la laptop.
- [ ] Al subir a un VPS: activar WAL (`DB_JOURNAL_MODE=wal` en `.env`) y revisar que el respaldo copie
      también `-wal`/`-shm` (el respaldo de la app usa `VACUUM INTO`, que ya es seguro). En la laptop con
      Windows queda sin WAL por `respaldo-diario.bat`.
- [ ] `graphify update .` en la Mac tras la Fase 0 (el entorno de la nube no tiene graphify).
- [ ] Costos y punto de equilibrio: `php artisan migrate` en la Mac con respaldo (migración `2026_10_09_000001`);
      luego Finanzas → Costos y margen → «Cargar recetas base», cargar los precios del café (kg de café, litro de
      leche, azúcar, vasos y tapas, matcha, bombilla, siropes) y los gastos fijos reales. Revisar el costo por kg del
      helado del Autoservicio (25 es un valor inicial) y qué categorías son fijas o variables.
- [ ] Matriz `role_permissions` (pantalla Usuarios → Roles y permisos): conectarla al backend o eliminarla.
      Hoy no se aplica y sus datos dicen que el admin solo tiene lectura en todo.
- [ ] Botones naranjas/verdes en modo claro usan texto oscuro (decisión aplicada). Alternativa si se
      prefiere texto blanco: oscurecer el naranja a `#C2410C`.

### Correcciones de datos (requieren confirmación explícita, no aplicar sin ella)
- [ ] **Datos de prueba en la base local real** (2026-10-04 22:24): una prueba E2E usó
      `DB_DATABASE=… php artisan serve`, que ignora la variable, y escribió en `database.sqlite`:
      caja #7 (Cajero Test, Bs 100, abierta), venta PedidosYa `041026-0001` (id `b4200de7-…`, Bs 72) con
      `sale_items` 131, `sale_item_options` 132–134, `sale_payments` 87, `audit_logs` 892–898 y tokens
      186–189. Sin movimientos de inventario. Hay que borrarlos (la venta aparece en cocina/Delivery).
      El método correcto quedó documentado en AGENTS.md.
- [ ] Venta `16e52f9b-17d9-40ac-b6d3-a585b2882716`: registrada como efectivo 200 + QR 43 con total 143
      (bug de sobrepago ya corregido). El efectivo debería ser 100; infla en 100 Bs el arqueo de ese día.
- [ ] Caja #6 (16/09/2026, abierta con fecha pasada a las 08:00): la venta PedidosYa `160926-0008`
      (04:12, Bs 38, id `39071145-…`) quedó sin caja y el arqueo muestra Bs 290 en vez de 328. Corrección
      propuesta: pasar `opened_at` de la caja #6 a 00:00 y vincular la venta, con respaldo y registro en
      auditoría. El usuario la pidió, pero el permiso de escritura en la base fue denegado; falta aplicarla.
      Respaldo ya hecho: `pre_fix_sep16_20261004_213451.sqlite`.
- [ ] Producción: las ventas del POS sincronizadas antes de `35ba606` se guardaron en UTC (+4 h).
      Corregirlas requiere un comando con modo de prueba y reasignar su sesión de caja.

### Fase 5 (diseñada, aparcada) — problemas vigentes
- `CustomerRepository::incrementPoints()` ignora valores ≤ 0: anular o editar una venta **nunca descuenta
  puntos**, y editar los suma de nuevo.
- `SyncSalesRequest` valida el lote entero: **una venta inválida bloquea toda la cola** del POS
  (reintenta cada 30 s); el POS ignora `failed` y no muestra el error.
- Stock con lectura-modificación-escritura (`current_stock -= …`) y SQLite en modo `DEFERRED` sin
  `busy_timeout`: con varios workers se pueden perder descuentos o fallar con "database is locked".
- Ventas cobradas offline fallan si mientras tanto se dio de baja el producto (`findOrFail`) o cambiaron
  los mínimos de opciones.
- Números de orden repetidos en datos históricos (`040926-0001` ×5); no hay índice único.

Diseño propuesto (pendiente de aprobación): 5a SQLite `IMMEDIATE` + `busy_timeout` + WAL (revisar
`respaldo-diario.bat` por los archivos `-wal/-shm`); 5b `adjustStock()` atómico y reversión de inventario
centralizada; 5c columna `sales.loyalty_points_earned` y reversión exacta; 5d validación por venta,
`withTrashed` para productos, reglas de opciones como discrepancia auditada, idempotencia dentro de la
transacción y cuarentena en servidor (`sale_sync_failures`) con pantalla de admin; 5e índice único de
número de orden solo para ventas nuevas.

### Fase 6 (sin empezar)
- `Turno.vue` monta `AdminSaleEditor` también para el cajero y este pide `/users` (403 en consola, sin efecto).
- Botón `btn-primary` de `AdminSaleEditor` (blanco sobre `#8B5CF6`, 4,23) bajo AA en modo claro.
- N+1 en `CashRegisterSessionController::index` (≈8 consultas por sesión) y sumas de `CashMovement` repetidas.
- KDS SSE: `$lastCheck = now()` se asigna después de la consulta (puede perder cambios) y cada conexión
  ocupa un worker de `artisan serve` 60 s.
- `OrderQueueController::index` ejecuta la consulta dos veces y los conteos salen mal con filtro.
- `SaleController::index` carga todas las ventas completadas para sumarlas.
- Mensajes de excepción internos devueltos al cliente (`$e->getMessage()`).
- Código muerto: `app/Jobs/ProcessOfflineSaleJob.php` (nadie lo despacha), `components/HelloWorld.vue`.
- Componentes enormes: `AdminSaleEditor.vue` (≈2.600 líneas), `RetroactiveSales.vue`, `ModifierModal.vue`.
- README desactualizado (dice MySQL y colas).
- Conflicto de peers npm (vue-router 5 / Vite 5).

## Decisiones tomadas
- **Precios en ventas del POS:** se respeta el precio cobrado y se audita la diferencia con el catálogo,
  en vez de rechazar la venta. Motivo: las 23 diferencias reales eran cambios legítimos de precio y
  rechazar bloquearía ventas ya cobradas.
- **Número de ticket (opción A):** el servidor acepta el número del POS y solo reasigna si ya existe.
  Riesgo conocido: varios dispositivos offline pueden generar el mismo número (se resolverá en Fase 5e).
- **Cajas cerradas:** no se bloquean cambios (las ventas retroactivas sirven para corregir días pasados);
  se audita `closed_session_sale_change` y el arqueo guardado al cierre no se modifica.
- **Roles:** el backend replica lo que ya mostraba la interfaz (escritura de catálogo/inventario y
  anulaciones solo admin; cocina sin ventas ni caja), no la matriz `role_permissions`.
- **Tokens Sanctum:** caducan a los 7 días (`SANCTUM_EXPIRATION`). Las ventas offline pendientes no se pierden.
- **Fuentes:** archivos en el repositorio en vez de paquete npm, porque producción no ejecuta `npm install`.
- **Commits:** el trabajo previo del usuario (`7644554`) se reconstruyó deshaciendo los cambios de las
  fases; pasa los mismos 47 tests que al inicio. En líneas de color añadidas por el usuario y luego
  modificadas, el valor original se aproximó (p. ej. `white` por `#fff`); el estado final es idéntico.

## Registro de sesiones
Formato: fecha — resumen — commits. Lo más reciente arriba.

- **2026-10-09 (inventario F2)** — Fase 2 hecha: las recetas por porciones ya mueven stock. Nueva tabla
  `ingredient_compositions` (componente de costeo → insumo real, cantidad en la unidad del insumo) y `CompositionService`,
  la única que sabe expandir un componente en sus insumos (un solo nivel); lo usan la venta (`InventoryService`), el
  costeo (`RecipeCostService`: el costo del componente es Σ cantidad × CPP del insumo, incompleto si a un insumo le falta
  costo) y el catálogo (`stock_recipe` ya expandido en productos y opciones). Un componente sin composición no mueve
  stock y conserva su costo. Anular revierte los movimientos originales, así cambiar una composición no toca las ventas
  pasadas. Pantalla «Composición» en Costos y margen (g/ml/unidades, costo resultante en vivo, aviso de que lo anterior no
  se recalcula) y botón en cada componente. POS: un solo módulo con la regla (`utils/stockRecipe.js`) para el aviso, el
  descuento y la restauración sin conexión; el aviso de una opción suma el producto base más su ajuste (H20) y se
  corrigieron dos fallas del descuento local (NaN con opciones y kilos al anular). En productos por peso solo las filas en
  kg escalan con los kilos (como el costeo). Decisión: la composición la edita solo el dueño (está en Finanzas).
  Verificado: 229 tests (16 nuevos), build, y en el navegador contra una copia: composición armada desde la pantalla
  (180 g de pulpa = Bs 2,98), venta de un bowl Mediano desde el POS → −252 g de pulpa en el stock local y en el servidor,
  anular lo devolvió, y el aviso por tamaño (Mediano sin aviso, Grande y Ohana con aviso con 0,3 kg).

- **2026-10-09 (inventario F1)** — Fase 1 hecha: las compras ahora mueven stock y costo. Una compra es un gasto de
  Finanzas con líneas (`finance_expense_items`); cada línea con insumo suma stock, actualiza el CPP y deja un
  movimiento `restock` con costo y total (unidad de compra × factor = unidades de stock; costo unitario = pagado ÷
  unidades). Editar o eliminar revierte con `purchase_reversal` y rehace el CPP repasando los movimientos; si hay
  ingresos antiguos sin costo, el stock se corrige y el CPP se deja igual con un aviso (devuelto en `warnings`). El
  ingreso suelto de stock desapareció de `/inventory/movements` (solo merma y conteo): todo entra por Compra, así
  Inventario y Finanzas cuadran sin doble carga. Proveedores (tabla propia, creados al vuelo por nombre sin
  duplicar), con días de entrega para F4; el insumo guarda proveedor, unidad/factor de compra y pedido mínimo.
  Decisión: las rutas de compras y proveedores están fuera de `/finance` (que es solo del dueño) para que el admin
  pueda registrar compras desde Inventario; el cajero no. Un mismo `PurchaseModal` sirve a Finanzas e Inventario.
  Verificado: 213 tests (19 nuevos de compras y proveedores), build, y en el navegador contra una copia (compra de
  2 bolsas de 2 kg + gas desde Inventario → stock +4 kg, costo Bs 22,50/kg; la compra aparece en Finanzas con la
  etiqueta «Compra · 2 líneas» y se abre para editar; proveedor creado una sola vez), en claro/oscuro y a 1440 y
  390 px, sin errores en consola.

- **2026-10-09 (inventario F0)** — El dueño aprobó D1–D7 y respondió las preguntas (conteo los martes; compras en
  mercados con precios variables; gramos y descartables los cargará él; el CPP de los 3 insumos lo corrige él
  después de probar; todas las ventas pasan ya por el sistema; la producción seguirá en la laptop hasta subir
  a un VPS). Fase 0 hecha: CPP correcto (alta = costo; con stock ≤ 0 la compra fija el CPP; sin CPP guardado se
  usa el último costo); el stock solo cambia por movimientos (alta crea `initial`, la edición no lo toca; Ajuste
  pasó a «Conteo» con `counted_stock`); productos por peso descuentan por gramos ÷ 1000, también en el POS sin
  conexión; merma/ingreso con signo validado; unidades en lista cerrada (kg, litros, unidades; una antigua se
  conserva al editar); descuento atómico en SQL con `ROUND`, `busy_timeout` 5 s y transacciones IMMEDIATE (WAL
  queda opcional, ver Pendientes); el POS avisa «stock bajo» sin bloquear (productos y opciones; el «agotado a
  mano» es «Pausar producto» del Menú). Nuevo comando `inventory:fix-initial-cost` (solo escribe con
  `--confirm`). La migración solo relaja el enum de `inventory_movements` (conserva filas e índice; probada
  sobre una copia con 25 movimientos). Verificado: 193 tests, build, API y navegador contra una copia
  (alta, ingreso, merma, conteo, venta de 400 g, anulación, venta desde el POS con «stock bajo»). También se
  fijó la hora en una prueba de caja que fallaba de madrugada.

- **2026-10-08 (auditoría de inventario)** — Auditoría de stock, mermas y punto de reorden
  ([informe](AUDITORIA_INVENTARIO_MERMAS.md), [plan](PLAN_INVENTARIO_REORDEN.md)). Conclusión: el stock no es
  confiable (las ventas no descuentan insumos reales, compras e inventario no están conectados, el CPP se calcula
  mal con stock inicial o negativo, el Autoservicio descuenta por unidad y no por gramos, sin conteo ni alertas).
  Recorrido de 20 pasos en una copia de la base de la Mac (la real no se tocó). Se corrigieron dos fallas de la rama
  de costos que habrían frenado las ventas: `16212b4` (POS daba por agotados los productos con recetas base) y
  `9996d95` (componentes de costeo en Inventario; insumo eliminado que rompía la sincronización). 175 tests en verde.
  El plan (F0 a F5, 10-15 días) espera las decisiones del dueño.

- **2026-10-08 (costos)** — Costos por receta, margen de contribución y punto de equilibrio
  ([plan](PLAN_COSTOS_PUNTO_EQUILIBRIO.md); decisiones del dueño: porciones solo para costeo, fijos = gastos fijos
  mensualizados + otros marcados como fijos, café con receta propia en unidades reales, Autoservicio por peso +
  envase + cucharilla, cifras del análisis como valores iniciales). `RecipeCostService`, `BaseRecipesService`
  (asistente idempotente que no pisa recetas existentes salvo que se marquen), `BreakEvenService`,
  `FinanceCostController`; pestaña «Costos y margen», editor de recetas con g/ml y «rinde», tarjeta «Punto de
  equilibrio» en el Resumen y tipo de costo en Categorías. Los componentes de costeo no mueven stock
  (`ingredients.tracks_stock`). Los gramos de las ventas por peso se guardan (`sale_items.weight_grams`).
  Corregido además: respuestas atrasadas al cambiar de mes rápido, tablas que se encogían dentro de las ventanas y
  los gramos precargados al editar una venta por peso en Control Diario. Después, en la auditoría de inventario, se
  encontró que el POS daba por agotados los productos con recetas base (componentes de costeo con stock 0 en el
  catálogo): corregido filtrando el catálogo por `tracks_stock`. También: Inventario ya no lista los componentes de
  costeo, no se elimina un insumo en uso y una receta con un insumo eliminado no rompe la sincronización.
  Probado en una copia de la base de la Mac (migrada; la real **no**): flujo en el navegador (asistente, editor,
  resumen, categorías) en claro/oscuro a 1440 y 390 px con contraste 0, y flujo de venta por API (abrir caja →
  vender Autoservicio 400 g + Classic Bowl → cocina → anular → cerrar caja sin diferencia; los componentes de
  costeo no movieron stock). Hallazgo en los datos: Capuchino y Frapu de cafe se eliminaron el 17/08 y Capuchino se
  volvió a crear; las ventas del producto viejo se costean con la receta del nuevo y Frapu queda fuera del margen.

- **2026-10-08** — Registrar lo que no está en la lista (decisiones del dueño: crear la categoría ahí mismo;
  solo el dueño crea categorías, el cajero usa «Otro / no está en la lista»; también sugerir proveedores; las
  equivalencias de PedidosYa quedan como estaban). `CategorySelect.vue` con «+ Nueva categoría…» en compras, gastos
  fijos y egresos de caja; nombres únicos sin importar mayúsculas/acentos (`App\Support\NameKey`); proveedores
  sugeridos y unificados (`GET /finance/suppliers`). 148 tests en verde; flujo probado en el navegador (claro 1440,
  oscuro 390) con contraste en 0.

- **2026-10-07 (auditoría Finanzas)** — Auditoría de `feat/modulo-finanzas` ([informe](AUDITORIA_FINANZAS.md)) y
  corrección por fases. Fase 0: imágenes restauradas, doble marco de la pantalla, bordes de mes en hora de La
  Paz (test nuevo) y lista de liquidaciones.
  Fase 1 (cálculos): gastos fijos generados por mes (`RecurringExpenseService`, migración
  `2026_10_08_000001`), avisos del resumen, clasificación de egresos de caja desde Finanzas, semanas PedidosYa con
  rango del CSV, sin solapes, prorrateo entre meses y conciliación completa (migración `2026_10_08_000002`, que
  calcula los descuentos de las semanas ya importadas). Además: editar/eliminar gastos no hacía nada (parámetro de
  ruta), y el último día del mes quedaba fuera de listas y del Estado de Resultados. 126 tests en verde.
  Fase 2 (interfaz): `Finance.vue` dividido en `components/Finance/` + `stores/finance.js`, Estado de Resultados
  como vista principal, avisos con acceso directo, generación de gastos fijos, clasificación de egresos y carga de
  semanas con vista previa y conciliación en vivo. Contraste de Finanzas en 0 (claro y oscuro, 1440 y 390 px);
  la auditoría general marca 6 textos fuera de Finanzas que dependen de los datos del momento (insignia de tiempo
  urgente en Delivery, botón "Añadir primer producto" en Control Diario) y textos sobre degradados (`img=true`).
  Fase 3 (importación crea ventas): `app/Services/PedidosYa/` (`ItemsParser`, `CatalogResolver`, `SalesLinker`,
  `WeeklyReport`), equivalencias en `platform_catalog_mappings` (opción con `internal_id = 0` = sin equivalencia),
  vínculo en `pedidosya_orders.sale_id` + `sale_link` (no se usa `delivery_orders`, que queda para el webhook).
  Probado con la semana real en una copia: 2 productos a mapear ("Arma Tu Ohana Acai" → Arma tu Bowl, "Patriota
  Bowl" → Patriota), 3 opciones sin equivalencia, 10 ventas creadas y 1 vinculada a mano. El informe marca 8
  pedidos con otro precio que el Tarifario: **Mediano en PedidosYa es +8 (el POS tiene +7) y Arma tu Bowl 32 (el POS
  tiene 35)** — corregir en Menú → Tarifario PedidosYa (decisión del dueño).
  Fase 4: índices de reportes (migración `2026_10_08_000004`), B1 verificado sin N+1, grafo actualizado (sin la
  caché). Estado por hallazgo en [AUDITORIA_FINANZAS.md §5](AUDITORIA_FINANZAS.md). Las 4 migraciones nuevas se
  probaron en una copia de la base de la Mac (también `rollback`); la base real **no** se migró.

- **2026-10-07 (4)** — Módulo de Finanzas (Fase 2: Liquidaciones semanales de PedidosYa, conciliación y análisis de canal):
  - Migración `2026_10_07_000008_create_pedidosya_settlements_tables.php` creando `pedidosya_settlements` y
    `pedidosya_orders` con persistencia de campos normalizados y `raw_data` JSON.
  - Modelos `PedidosYaSettlement` y `PedidosYaOrder` con nombres de tabla explícitos y relaciones.
  - Servicio `PedidosYaImportService`: lectura nativa de CSV de órdenes (soporte de BOM, separadores, comas decimales),
    parsing de fechas a `America/La_Paz`, conciliación de los 8 montos del estado de cuenta PDF e idempotencia
    completa por número de pedido para permitir reimportaciones sin duplicados.
  - Servicio `FinanceReportService` actualizado para incluir ventas netas de liquidaciones en ingresos totales,
    costos operativos de plataforma (comisiones, publicidad, retención IVA − reembolsos) en OPEX, y cálculo
    específico de rentabilidad del canal (`pedidosya_analysis`).
  - Endpoints RESTful en `PedidosYaSettlementController` bajo middleware `super_admin`.
  - Frontend `Finance.vue`:
    - Pestaña "🛵 Liquidaciones PedidosYa" con métricas mensuales y tabla de liquidaciones.
    - Banner de rentabilidad PedidosYa en el Estado de Resultados.
    - Modal de carga de semana con conciliación en tiempo real (alerta de discrepancia si la suma difiere del total a pagar).
    - Modal/drawer para explorar los pedidos de la semana con filtros por estado (Todos, Entregados, Cancelados).
  - Test feature `PedidosYaSettlementTest` (4 tests pasados, 21 aserciones).
  - 100 tests totales en verde (`php artisan test`), build Vite correcto (`npx vite build`), grafo sincronizado (`graphify update .`).

- **2026-10-07 (3)** — Módulo de Finanzas (Fase 1: P&L mensual, compras, gastos fijos y categorías) implementado
  en la rama `feat/modulo-finanzas` (a partir de `feat/precios-pedidosya`): — `4cdc459`
  - Respaldo previo de base de datos en `storage/app/backups/pre_finanzas_20261007_160418.sqlite`.
  - Migraciones para `expense_categories` (14 categorías estándar precargadas en `cost_of_sales`, `operating`,
    `financial`, `taxes`), `finance_expenses` (compras y gastos fuera de caja), `recurring_expenses` (gastos
    fijos y plantillas periódicas) y `cash_movements.expense_category_id` (clasificación en gaveta).
  - Modelos Eloquent con relaciones, soft deletes y auditoría.
  - Servicio `FinanceReportService` y controlador `FinanceReportController`: cálculo de Ventas Locales, Costo
    de Ventas (Método A), Utilidad Bruta, Gastos Operativos, Utilidad Operativa (EBITDA), Impuestos y Utilidad
    Neta con porcentajes y desgloses.
  - Exclusión de ventas PedidosYa del POS en el P&L (se alimentarán en Fase 2 de las liquidaciones).
  - Endpoints protegidos para rol `super_admin` (`403` para cajeros y administradores).
  - Selector de categoría contable en modal de egreso de caja (`CashExpenseModal.vue`).
  - Pantalla completa `Finance.vue` con KPIs, tabla de P&L, gestión de compras, gastos fijos y categorías.
  - Ítem y ruta `/finanzas` en `AppShell.vue` y `router/index.js` condicionados a `super_admin`.
  - Fixture CSV anonimizado `semana_pedidosya_anonimizada.csv` para pruebas sin datos personales ni NIT.
  - 96 tests en verde (`php artisan test`), build exitoso (`npx vite build`).

- **2026-10-07 (2)** — Plan de precios PedidosYa solo en los tamaños (`PLAN_PRECIOS_TAMANOS_PEDIDOSYA.md`)
  ejecutado en `feat/precios-pedidosya`: migración `add_pedidosya_pricing_to_option_groups_table` (activa
  por defecto para grupos 'Tamaño...'), regla de precios sincronizada en backend y frontend (toppings y
  frutas cobran siempre precio local en ambos canales), validación y rechazo 422 en Tarifario para opcionales
  no marcados, eliminación de avisos espurios en el POS por toppings, casilla "🛵 Precio distinto en PedidosYa"
  e indicadores de precio PedidosYa en Menú (Opcionales y Tarifario). 92 tests en verde (5 tests nuevos de
  cobertura), build y auditoría de contraste (0 textos bajo AA en Menú y POS).

- **2026-10-07** — Plan de precios PedidosYa ejecutado en `feat/precios-pedidosya` (desde
  `feat/canal-pedidosya`): casilla "Disponible en PedidosYa", etiqueta "precio local" en el POS, ajuste de
  precio por línea con motivo (`price_override_reason`, auditoría `pedidosya_price_override`) y pantalla
  Tarifario con guardado en lote. 87 tests en verde, build correcto, migraciones probadas sobre una copia de
  la base local y flujo PedidosYa completo (tarifario → venta con ajuste → sincronización) contra esa copia.
  Contraste: 0 textos bajo AA en las pantallas nuevas (dark y light); queda 1 preexistente en el hover de
  `.chip-price` del modal de producto (4,39). Los tests se corrieron con PHP 8.4 en WebAssembly (sin PHP
  nativo en el entorno); en la Mac basta `php artisan test`.

- **2026-10-04 (3)** — Plan PedidosYa ejecutado en `feat/canal-pedidosya`: arqueo separado por `source`,
  pagos de plataforma normalizados, verificación de precios por canal, selector de canal en el POS y bloque
  PedidosYa en reportes. 73 tests, build y E2E contra copia correctos. Incidente: la primera E2E escribió en
  la base real (ver Correcciones de datos). — `a15b50b`, `a2e3f96`.

- **2026-10-04 (2)** — Ventas del 16/09 "desaparecidas": estaban todas (8) en la pestaña Ventas del Día,
  debajo del formulario. Diferencia real: el arqueo excluía una venta de madrugada porque las cajas
  retroactivas empezaban a las 08:00; corregido en código (inicio 00:00). Corrección del dato pendiente.

- **2026-10-04** — Análisis integral del sistema; fases 1–4 implementadas con tests; fase 5 diseñada y
  aparcada; auditoría y corrección de contraste en modo oscuro y claro (se descubrió que las reglas
  `:global(html.dark)` nunca se aplicaban); fuentes locales; bug de `fetchUser` que borraba el rol al
  recargar; commits organizados por tema en `mejoras/analisis-integral`; se crean `AGENTS.md`, esta
  bitácora y `CHANGELOG.md`. — `76b07bd`…`624702e` + commit de documentación.
