# Bitácora del proyecto

Registro vivo para que cualquier agente o persona retome el trabajo. Actualízala en el mismo commit
que el cambio que la motiva. Reglas de trabajo: [AGENTS.md](../AGENTS.md). Historial: [CHANGELOG.md](../CHANGELOG.md).

## Estado actual (2026-10-07)
- **Rama de trabajo:** `feat/modulo-finanzas` (a partir de `feat/precios-pedidosya`).
- **Tests:** 100 en verde (`php artisan test`, 446 aserciones). Build de Vite sin errores (`npx vite build`).
- **Contraste:** modo oscuro y claro con 0 textos medibles bajo WCAG AA.
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
