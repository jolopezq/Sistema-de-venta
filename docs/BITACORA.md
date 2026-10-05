# Bitácora del proyecto

Registro vivo para que cualquier agente o persona retome el trabajo. Actualízala en el mismo commit
que el cambio que la motiva. Reglas de trabajo: [AGENTS.md](../AGENTS.md). Historial: [CHANGELOG.md](../CHANGELOG.md).

## Estado actual (2026-10-04)
- **Rama de trabajo:** `mejoras/analisis-integral` (10 commits sobre `main` + este registro). Sin push.
  **No fusionada en `main`**: al fusionar, el vigilante actualiza producción y ejecuta la migración
  `2026_10_04_000001_add_cash_register_session_id_to_sales_table` automáticamente.
- **Tests:** 67 en verde (`php artisan test`). Build del frontend correcto.
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

## Pendientes

### Decisiones del usuario
- [ ] Fusionar `mejoras/analisis-integral` en `main` (dispara la actualización de producción).
- [ ] `graphify-out/`: regenerar el grafo (está desactualizado respecto al código) y commitearlo, o
      ignorar `cache/` y `obsidian/` en git.
- [ ] Matriz `role_permissions` (pantalla Usuarios → Roles y permisos): conectarla al backend o eliminarla.
      Hoy no se aplica y sus datos dicen que el admin solo tiene lectura en todo.
- [ ] Botones naranjas/verdes en modo claro usan texto oscuro (decisión aplicada). Alternativa si se
      prefiere texto blanco: oscurecer el naranja a `#C2410C`.

### Correcciones de datos (requieren confirmación explícita, no aplicar sin ella)
- [ ] Venta `16e52f9b-17d9-40ac-b6d3-a585b2882716`: registrada como efectivo 200 + QR 43 con total 143
      (bug de sobrepago ya corregido). El efectivo debería ser 100; infla en 100 Bs el arqueo de ese día.
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

- **2026-10-04** — Análisis integral del sistema; fases 1–4 implementadas con tests; fase 5 diseñada y
  aparcada; auditoría y corrección de contraste en modo oscuro y claro (se descubrió que las reglas
  `:global(html.dark)` nunca se aplicaban); fuentes locales; bug de `fetchUser` que borraba el rol al
  recargar; commits organizados por tema en `mejoras/analisis-integral`; se crean `AGENTS.md`, esta
  bitácora y `CHANGELOG.md`. — `76b07bd`…`624702e` + commit de documentación.
