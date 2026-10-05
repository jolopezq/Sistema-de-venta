# Bitácora del proyecto

Registro vivo para que cualquier agente o persona retome el trabajo. Actualízala en el mismo commit
que el cambio que la motiva. Reglas de trabajo: [AGENTS.md](../AGENTS.md). Historial: [CHANGELOG.md](../CHANGELOG.md).

## Estado actual (2026-10-04)
- **Rama de trabajo:** `mejoras/analisis-integral` (10 commits sobre `main` + este registro). Sin push.
  **No fusionada en `main`**: al fusionar, el vigilante actualiza producción y ejecuta la migración
  `2026_10_04_000001_add_cash_register_session_id_to_sales_table` automáticamente.
- **Tests:** 73 en verde (`php artisan test`). Build del frontend correcto.
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

## Pendientes

### Decisiones del usuario
- [ ] Fusionar `feat/canal-pedidosya` en `mejoras/analisis-integral` (sale de esa rama).
- [ ] Cargar los precios PedidosYa de los **productos** en Menú: hoy ninguno tiene `delivery_price` (solo
      13 opciones), así que el POS cobra el precio local y lo avisa en el banner.
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
