# Bitácora del proyecto

Registro vivo para que cualquier agente o persona retome el trabajo. Actualízala en el mismo commit
que el cambio que la motiva. Reglas de trabajo: [AGENTS.md](../AGENTS.md). Historial: [CHANGELOG.md](../CHANGELOG.md).

## Estado actual (2026-10-30)
- **Rama de trabajo:** `feat/recuperar-contrasena` (desde `main` local `62c6f51`).
- **Último cambio (2026-10-30):** Recuperar la contraseña con un código por correo ([PLAN_RECUPERAR_CONTRASENA.md](PLAN_RECUPERAR_CONTRASENA.md)). Probado contra el backend real (22/22), envío a Resend con `Http::fake` (11/11), navegador de punta a punta en 1366 × 611, 1093 × 489 y 390 × 844 (51/51), 15 pruebas PHPUnit en verde y 12 E2E (48 ejecuciones en 4 perfiles) en verde.
  - **Pendiente:** Configurar Resend (sección 4 del plan: dominio `avisos.ohana-pos.com`, clave en `.env` del VPS y de la laptop) y probar envío real con `php artisan ohana:probar-correo tu@correo.com`.

- **Cambio anterior (2026-10-23):** Control diario con el configurador de Ventas y diseño para la portátil ([PLAN_VENTAS_MULTI_ITEM_Y_TOPPINGS.md](PLAN_VENTAS_MULTI_ITEM_Y_TOPPINGS.md), secciones 4.5 y 8):
  - `AdminSaleEditor.vue`: productos con opciones abren `ModifierModal`; filas tipo ticket con píldoras, edición con fusión, «Precio y nota» plegado; datos de la venta en una línea con el origen visible; pestañas `CategoryFilter`; barra fija (`.cd-dock`, z-index 30) con total y Registrar venta; motivos rápidos; `--primary` mapeado a açaí.
  - `ModifierModal.vue`: precio base 0 exige un extra con precio (afecta Ventas).
  - `RetroactiveSales.vue`: `.editor-wrapper` con `overflow: clip` (con `hidden` la barra fija no funciona); cabecera en una línea en la portátil y título del editor oculto a 125 %.
  - Retirado `catalog:sync-toppings` y su prueba.
  - Pruebas: `e2e/control-diario-apilado.spec.js` (26 casos, 4 perfiles, 104/104 en verde); backend `php artisan test` (346/346 en verde); auditoría de contraste `scripts/audit-contrast.cjs` (0 textos ilegibles bajo AA fuera de degradados). Pendiente: prueba en la Toshiba y decidir si la fruta es obligatoria en Control diario.
- **Cambio anterior (2026-10-21, 2):** Venta multi-ítem en Control Diario (catálogo abierto y fusión de ítems iguales); el comando de toppings sueltos de ese cambio quedó retirado el 2026-10-23.
- **Cambio anterior (2026-10-21):** Corrección de saldo de gaveta en modal de gastos (`CashExpenseModal.vue` y `RetroactiveSales.vue`): el modal leía ciegamente el turno activo global (`turnoStore.currentCashInDrawer`) en lugar de la sesión específica seleccionada en Control Diario. Se agregó el prop `expectedCash` para sincronizar la gaveta y evitar desfasajes en la validación de fondos y el saldo visible.
- **Cambio anterior (2026-10-20, 4):** Scroll del sistema ([PLAN_CORRECCION_SCROLL_SISTEMA.md](PLAN_CORRECCION_SCROLL_SISTEMA.md)): la franja blanca al final de Costos venía de `.sr-only` posicionados respecto de la ventana (no de «altura de flexbox» como decía la v1); contención en `.oh-shell-content` y envoltorios de tabla, `overscroll-behavior`, ventana fija solo con AppShell; además, pantallas que se encogían (Finanzas, Caja, Inventario) y `100vh` dentro del contenido (Auditoría, Sin acceso). E2E `layout-scroll.spec.js`. Pendiente: probar en la caja real.
- **Último cambio (2026-10-20, 3):** Finanzas para la portátil ([PLAN_REDISENO_FINANZAS_PORTATIL.md](PLAN_REDISENO_FINANZAS_PORTATIL.md)): navegación pegada, hero compacto, Categorías 2×2, tablas y modal de compra compactos; arreglo de las pestañas aplastadas a 1 px. E2E `finanzas-portatil.spec.js` (27) con API simulada. Pendiente: contraste AA con la copia de BD y prueba en la caja real.
- **Último cambio (2026-10-20, 2):** Cerrar caja con conteo directo ([PLAN_CIERRE_CAJA_CONTEO_DIRECTO.md](PLAN_CIERRE_CAJA_CONTEO_DIRECTO.md)): conteo en dos columnas con total en el pie, teclado completo, resultado compacto, indicador de turno en el menú que lleva al cierre, y validación del desglose en el servidor. E2E `cierre-caja-portatil.spec.js` (23) y `cashCount.test.mjs`. Pendiente: prueba manual en la caja real.
- **Último cambio (2026-10-20):** Cobro dividido con montos que se completan solos, con 2 y 3 métodos ([PLAN_COBRO_DIVIDIDO_AUTOCOMPLETAR.md](PLAN_COBRO_DIVIDIDO_AUTOCOMPLETAR.md)): corrige el bloqueo "faltan Bs X en efectivo" y el vuelto falso al combinar efectivo + QR/tarjeta. Pendiente: probar en la caja real y contra una copia de la base (guardado de los dos pagos y arqueo).
- **Cambio anterior (2026-10-19):** Pantalla de Cobro ajustada a la portátil de caja ([PLAN_PANTALLA_COBRO_PORTATIL.md](PLAN_PANTALLA_COBRO_PORTATIL.md)): sin desborde en pago dividido y sin scroll en 1366 × 611; suite `checkout-portatil.spec.js` 13/13. Pendiente: confirmar la escala de Windows de la Toshiba (100 % o 125 %) y probar el cobro en la caja real.
- **Cambio anterior:** Implementación de la nueva pantalla de Roles y permisos para la portátil de caja ([PLAN_PANTALLA_PERMISOS.md](PLAN_PANTALLA_PERMISOS.md)):
  - **Interfaz de usuario**:
    - Un rol a la vez (Cocina, Cajero, Administrador; Super Admin fijo informativo).
    - Frase resumen dinámica en Baloo 2 alineada en la franja superior (`role-strip`).
    - 10 módulos operativos con selector segmentado de 336 px alineado que ofrece exclusivamente los niveles válidos (`ALLOWED_LEVELS`) sin exceder el techo.
    - Vista previa en vivo (`RolePreview.vue`) réplica del menú lateral compacto basada en la función pura `screensFor()`, con indicadores "Abre aquí", "Solo ver", pantallas no visibles y tarjeta "Acceso no disponible".
    - Encabezado compacto (`AdminUsers.vue`) que integra las acciones dinámicas: contador de cambios pendientes, aviso de rol sin pantallas, mensaje de guardado, botón "Descartar" y "Guardar cambios" (envío diferencial: solo filas modificadas).
    - Restablecimiento a valores por defecto con "Volver a lo recomendado".
    - Ergonomía para la portátil de caja Toshiba (1366 × 768): **cero scroll horizontal** en 1366, 1024 y 390 px, y **cero scroll vertical** en 1366 × 611 para Administrador con 3 cambios pendientes y aviso visible.
  - **Backend y reglas de negocio**:
    - `PermissionService`: constante `ALLOWED_LEVELS`, método `levelsFor($role, $module)` respetando techos, y normalización hacia abajo en `level()`.
    - `RolePermissionRequest`: validación estricta de array y validación de niveles contra `levelsFor()`.
    - `RolePermissionController@index`: entrega el mapa `levels` rol → módulo → niveles válidos.
    - `CashRegisterSessionController@ensureCanAccess`: granularidad `read` para `show`, `report` y `movements`, y `edit` para `close` y `recordMovement`.
    - Migración `2026_10_16_000001_normalize_role_permission_levels.php`: normalización idempotente en BD.
  - **Verificación**:
    - 344 tests PHPUnit en verde (2.134 aserciones).
    - Suite E2E Playwright `frontend/e2e/permisos-portatil.spec.js` con proyecto "Portátil de caja" (6/6 tests en verde).
    - Auditoría de contraste WCAG 2.1 AA (`scripts/audit-contrast.cjs`): 0 textos bajo el mínimo tanto en tema claro como oscuro (`09b_roles_permisos issues: 0`).
    - Build de producción de Vite exitoso en 1.7s.
- **Sin commit a propósito:** `graphify-out/` (grafo, caché y `obsidian/`, ~2.000 archivos) y las 7 imágenes de productos en `backend/storage/app/public/products/`.

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
| Inventario confiable, mermas y punto de reorden ([auditoría](AUDITORIA_INVENTARIO_MERMAS.md), [plan](PLAN_INVENTARIO_REORDEN.md)): plan aprobado el 09/10 (D1–D7) | ✅ completo (F0 a F5) en `feat/inventario-reorden` | ver fases |
| ↳ F0 Correcciones de base (CPP, stock solo por movimientos, peso, validaciones, atomicidad, POS avisa) | ✅ | ver registro |
| ↳ F1 Compras ligadas a insumos y proveedores | ✅ | ver registro |
| ↳ F2 Recetas que mueven stock (composición de componentes) | ✅ | ver registro |
| ↳ F3 Mermas y conteo físico | ✅ | ver registro |
| ↳ F4 Punto de reorden y alertas | ✅ | ver registro |
| Roles y permisos: conexión dinámica con protecciones ([plan](PLAN_ROLES_PERMISOS.md)) | ✅ en rama `feat/pos-caja-arranque` | ver registro |
| Roles y permisos: nueva pantalla para portátil de caja ([plan](PLAN_PANTALLA_PERMISOS.md)) | ✅ en rama `feat/pos-caja-arranque` | ver registro |

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
- [ ] Inventario (dueño): cuando termine de probar, ejecutar en producción (la laptop hoy, el VPS después) `php artisan inventory:fix-initial-cost`
      (muestra qué cambiaría) y luego con `--confirm`, con respaldo previo. Corrige el CPP en 0 de pulpa, granola y
      banana. Hasta entonces el cálculo de costos y las compras ya usan el último costo como respaldo.
      En la Mac ya no queda ningún insumo por corregir (comprobado el 09/10).
- [ ] Inventario (dueño): pasar los gramos de pulpa, granola, etc. y los descartables de cada tamaño y receta
      (se cargan en Finanzas → Costos y margen → «Composición», desde F2: hasta que un componente tenga composición
      no descuenta stock); proveedor, unidad y lote de compra de cada insumo clave (se cargan en
      Inventario → Editar insumo, desde F1) y, si se quiere, el consumo diario estimado para arrancar el punto de
      reorden (F4).
- [ ] Inventario (dueño, F3): marcar los insumos clave (pulpa, helado, granola, leche en polvo, leche condensada, vasos…) en
      Inventario → Editar → «Insumo clave» y hacer el **primer conteo** (Inventario → «Conteo», idealmente un martes al
      cerrar). Ese primer conteo ordena el stock de partida y no cuenta como merma oculta; la merma oculta se mide desde el
      segundo conteo de cada insumo. Las mermas de antes de F3 no tienen valor en Bs (se avisa en el reporte).
- [x] Inventario (dueño, F4): cargados parámetros de referencia y proveedores para los insumos clave en la base de la Mac (Pulpa: 2.96 kg/día, 7 días cobertura, 180 días vida útil; Granola: 0.30 kg/día, 14 días cobertura; Banana: compra diaria, 3 días vida útil). Seeder disponible en `ReorderDefaultsSeeder` para replicar en la laptop.
- [ ] Inventario (dueño, F5): para que los **quiebres** y la **cobertura** midan lo que importa, marcar los insumos clave
      (si no hay ninguno marcado se miden todos los que tienen consumo) y cargar el consumo diario estimado hasta tener
      4 semanas de ventas. La **exactitud del conteo** y la **merma oculta** necesitan al menos dos conteos del mismo insumo
      (el primero solo ordena el stock de partida). Las metas (merma 5 %, perecederos 10 %, diferencia de costo ±5 puntos,
      exactitud 90 %, 0 quiebres) son iniciales y están fijas en `InventoryIndicatorsService::TARGETS`; si se quieren
      ajustar desde la pantalla hace falta una tabla de configuración (no incluida).
- [ ] Inventario (dueño): desde F1 los ingresos de stock se registran como **Compra** (Finanzas → Registrar compra o
      Inventario → Ingreso). Una compra de mercado con precios que varían se anota con lo pagado por línea; el CPP
      absorbe la variación. Las compras antiguas de Finanzas (sin líneas) siguen como gasto y no mueven stock.
- [ ] Inventario: `php artisan migrate` en producción (laptop hoy, VPS después) con respaldo previo
      (`2026_10_10_000001_relax_inventory_movement_columns`,
      `2026_10_10_000002_create_suppliers_and_purchase_items`,
      `2026_10_10_000003_add_purchase_fields_to_ingredients_and_movements`,
      `2026_10_11_000001_create_ingredient_compositions` y
      `2026_10_12_000001_create_inventory_counts` [F3: tablas de conteos y columna `is_key`] y
      `2026_10_13_000001_add_reorder_fields_to_ingredients` [F4: cinco columnas opcionales]: tablas y columnas
      nuevas; ninguna modifica datos). Todas aplicadas ya en la base de la Mac el 09/10 (con respaldo `pre_migrate_reorder_20261009_035825.sqlite`); falta repetirlas en producción (en la laptop las aplica el vigilante al fusionar en `main`; en el VPS, al desplegar
      sobre la copia de la base de la laptop, siempre con respaldo previo). El 09/10 se comprobó en la Mac: 63 de 63 migraciones aplicadas.
- [ ] Al subir al VPS (se prevé contratarlo): activar WAL (`DB_JOURNAL_MODE=wal` en `.env`) y revisar que el respaldo copie
      también `-wal`/`-shm` (el respaldo de la app usa `VACUUM INTO`, que ya es seguro). En la laptop con
      Windows queda sin WAL por `respaldo-diario.bat`.
- [ ] **Migración a VPS (sin plan escrito):** definir proveedor y tamaño, sistema operativo, dominio y HTTPS, cómo se despliega sin el
      vigilante de Windows (`vigilante-actualizaciones.ps1` y los `.bat` no aplican allí), dónde se guardan los respaldos fuera del
      servidor y cómo se traspasa la base desde la laptop (con respaldo y verificación). No usar la base de la Mac como base de
      producción. Al terminar, actualizar `AGENTS.md` (sección «Producción») y esta Bitácora.
- [x] POS y cierre de caja: decisiones del 09/10 sobre el [análisis](https://claude.ai/code/artifact/0a10e4d8-9e98-424e-8ffe-8cc39486eaef):
      bloquear el cobro sin turno; bloquear el cierre con ventas pendientes (un admin puede forzarlo con motivo); ningún
      turno pasa de medianoche; a veces se pagan insumos con la caja; categorías de egresos revisadas al cierre; conteo de
      insumos clave en día fijo (martes, por confirmar). Prioridad 1 hecha en `feat/pos-cierre-caja`.
- [x] Venta del dueño o de un admin con el turno de un cajero abierto: entra a ese turno (una sola gaveta). Decidido el 09/10.
- [x] Sin conexión y sin datos del turno de hoy en el equipo, el POS deja cobrar con aviso (confirmado el 09/10).
- [x] Menú: «Vender» y «Caja» (antes «POS · Ventas» y «Caja / Turno»), decidido el 09/10.
- [ ] POS y cierre de caja, prioridad 2: «Compra de insumos» desde Ingreso / Egreso que mueva stock y cuente una sola vez en
      Finanzas (cambio 6; hoy una compra pagada con la caja no entra al stock); egresos sin categoría en la revisión previa
      (cambio 7; los cajeros no pueden usar hoy el PATCH de Finanzas); caja olvidada visible en Turno con cierre guiado
      (cambio 8).
- [ ] POS y cierre de caja, prioridad 3: conteo rápido de insumos clave al cerrar el día fijo; merma sin conexión; reporte de
      caja cerrada con los cambios posteriores aparte; «qué pedir mañana» en el reporte de cierre; historial de cajas sin
      recalcular sesión por sesión.
- [ ] `graphify update .` en la Mac tras la rama `feat/pos-cierre-caja` (nuevo componente `SyncIssuesPanel` y cambios de flujo).
- [ ] Rediseño visual: Finanzas → Resumen hecho en `feat/finanzas-rediseno`; Vender, Caja, cobro, comprobante, cierre y menú
      lateral hechos en `feat/pos-caja-arranque`. Las demás pestañas de Finanzas solo tomaron los estilos base. Siguientes
      candidatas según la [propuesta](https://claude.ai/code/artifact/0c92c842-cec0-44be-87bc-56c91315f2a2): Login, Control
      Diario, Delivery, Menú e Inventario. `graphify update .` en la Mac (componentes nuevos `FinanceMonthHero`,
      `FinanceStatus` y `StatusTag`; cambio de regla en `CashSessionResolver`).
- [ ] `graphify update .` en la Mac tras la Fase 0 (el entorno de la nube no tiene graphify).
- [ ] Costos y punto de equilibrio: `php artisan migrate` en la Mac con respaldo (migración `2026_10_09_000001`);
      luego Finanzas → Costos y margen → «Cargar recetas base», cargar los precios del café (kg de café, litro de
      leche, azúcar, vasos y tapas, matcha, bombilla, siropes) y los gastos fijos reales. Revisar el costo por kg del
      helado del Autoservicio (25 es un valor inicial) y qué categorías son fijas o variables.
- [x] Matriz `role_permissions` (pantalla Usuarios → Roles y permisos): conectada al backend con protecciones de seguridad (Opción C, 2026-10-15). Ahora controla el acceso real tanto en backend (`PermissionService`, middleware `perm`) como en frontend (`authStore.hasPermission`, guards de router y menú).
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

- **2026-10-15 (Módulo de Usuarios, Roles y Permisos — Opción C v2: Techos por rol y congruencia integral)**:
  - Diagnóstico y auditoría: la v1 inicial conectó la tabla pero presentaba hallazgos de seguridad y consistencia (la matriz no podía ampliar accesos que los middlewares de rol bloqueaban, lecturas permitían operar en ventas y caja, bucles potenciales en el router, falta de atomicidad en guardado y falta de sincronización inter-sesiones).
  - Implementación del modelo v2 ([docs/PLAN_ROLES_PERMISOS.md](PLAN_ROLES_PERMISOS.md)):
    - **Techos por rol inamovibles (`CEILINGS`)**: declarados en `PermissionService` (registrado como `scoped`). La matriz solo recorta dentro del techo de cada rol; ninguna fila de base de datos puede otorgar más que el techo.
    - **Falla cerrado**: módulos o niveles desconocidos lanzan `InvalidArgumentException` detectables de inmediato.
    - **Separación estricta por verbo HTTP**: lectura (`read`) y escritura (`edit`) desacopladas en Ventas POS (`pos`), Caja y Mermas (`register_self`), y Pedidos/KDS (`delivery_ops`); `GET /customers` protegido con `perm:crm_read,read`.
    - **Caja de otros cajeros (`register_others`)**: conectada en `CashRegisterSessionController` para lectura y edición de sesiones ajenas.
    - **Contrato de API enriquecido**: `/login` y `/me` devuelven `effective_permissions` precalculados; respuestas 403 incluyen `reason: 'permission_denied'`; `/permissions` expone `ceilings` y `defaults`.
    - **Validación y transacciones**: `RolePermissionRequest` valida contra `CEILINGS` y rechaza pares duplicados con 422; guardado en lote atómico mediante `DB::transaction`.
    - **Migración robusta**: `2026_10_15_000001_update_role_permissions_to_match_reality.php` reescrita con `DB::table('role_permissions')->upsert()` y valores literales sin dependencias de Eloquent.
    - **Frontend resiliente**: vista `SinAcceso.vue`, guard de router con `landingFor()` libre de bucles, `auth.js` sin duplicación de lógica local y refresco de permisos por foco y error 403, `AppShell.vue` alineado con rutas, y `AdminUsers.vue` con celdas dinámicas según techos y confirmación preventiva ante roles sin pantallas.
  - Verificación: 340 tests en verde (`php artisan test`, 2.102 aserciones; 18 tests en `PermissionServiceTest` cubriendo todas las fases y casos límite), build de producción Vite limpio (`npx vite build`).

- **2026-10-14 (Finanzas: corrección de costos, margen y layout)** — Solución integral de la distorsión
  en el análisis de costos del módulo de Finanzas y correcciones visuales de la interfaz:
  - Diagnóstico: los bowls marcaban ~85% de margen y Bs 4,11 de costo (en vez de ~67% y Bs 9,20). Causa:
    en `CompositionService`, al existir composición para `Mix de frutas` (1 banana) y `Pack toppings base`
    (granola), el costo financiero del componente se forzaba al CPP de esos pocos insumos descontados en vez
    de respetar su costo completo de porción.
  - Base de datos y backend: respaldo tomado en `storage/app/backups/backup_pre_finance_fix_20261014_033403.sqlite`.
    Migración `2026_10_14_033606_add_use_manual_cost_to_ingredients_table` añade `use_manual_cost` a `ingredients`.
    `CompositionService` añade `compositionCost()` y respeta `unit_cost` cuando `use_manual_cost` es true.
    `FinanceCostController` devuelve `profit_local`, `profit_pya`, `use_manual_cost`, `composition_cost`, y permite
    alternar y actualizar componentes y composiciones con ambos modos.
  - Corrección de datos de referencia en desarrollo: Porción base açaí (Bs 4,50), Mix de frutas (Bs 2,00),
    Pack toppings base (Bs 1,50), Kit descartable (Bs 1,20) e insumos de cafetería. Resultado: Classic Bowl
    queda en Bs 9,20 de costo, margen 67,1% y ganancia Bs 18,80; Capuchino en Bs 3,20, margen 80,0% y ganancia Bs 12,80.
  - Frontend: `FinanceCosts.vue` muestra la ganancia neta en Bs debajo del margen porcentual, colores
    semánticos (`margin-good`, `margin-fair`, `margin-low`), filtro segmentado en componentes ("Todos",
    "Bases y packs", "Insumos directos") y botón para alternar entre costo manual y costo automático con edición
    directa de costo. `CompositionEditor.vue` incorpora selector interactivo de modo de costeo financiero.
  - Corrección visual del layout: se corrigió el fondo residual `#20112F` en `style.css` y la altura del shell
    en `AppShell.vue` y `finance.css`, eliminando el vacío morado inferior al hacer zoom o en pantallas altas.
  - Verificación: 322 tests en verde (`php artisan test`), build de Vite sin errores (`npx vite build`),
    auditoría de contraste con 0 textos bajo WCAG AA en tema oscuro, inspección visual en navegador y grafo
    sincronizado con `graphify update .`.

- **2026-10-09 (Vender y Caja para empezar a usar el sistema)** — Sobre `docs/MENU_LATERAL_SISTEMA.md` y un recorrido como
  cajero y como dueño a 1366×768: el buscador del POS no buscaba, el cobro tenía una clienta de ejemplo y un «Descuento VIP»
  fijo, no mostraba el total y el botón de confirmar quedaba fuera de la vista, Vender/Caja/Menú compartían el ⚙️, el cajero
  figuraba como «cashier» y el dueño quedaba bloqueado si el turno abierto era de un cajero. Decisiones del dueño: la venta
  del admin entra al turno abierto (`CashSessionResolver`, 4 tests), menú «Vender» y «Caja», sin conexión se cobra con aviso.
  Rediseño con la dirección de Finanzas sin mover la pantalla de venta: cobro con total arriba, billetes sugeridos y vuelto en
  grande; comprobante con «Entrega de vuelto»; ticket que cuenta productos; Caja con el turno en una frase y la gaveta como
  cuenta; gastos y apertura en las ventanas de Finanzas; cierre con el dueño del turno y veredicto; `StatusTag`. Verificado
  contra una copia: 321 tests, build, contraste 0 en claro y oscuro en todos los estados de Vender y Caja, celular a 390 px y
  flujo completo (abrir caja, vender en efectivo y QR, cocina, anular como admin, cerrar con faltante y volver a abrir).

- **2026-10-09 (Finanzas: rediseño del Resumen)** — Etapa 2 del rediseño sobre la dirección aprobada (evolución: mismos tonos y
  fuentes, otra jerarquía). `FinanceMonthHero` (frase del mes, barra de cada Bs 100 con marca de pérdida, avisos de datos
  faltantes con su acción, «Actualizando…»), `FinanceBreakEven` compacto en la columna lateral con el día de equilibrio
  (`BreakEvenService::reachedDay`, ventas diarias del POS escaladas al total del mes; 2 tests nuevos) y el detalle a un clic,
  `FinanceInventoryIndicators` como tabla ordenada por estado, `FinanceStatus` (estado con icono y texto) y PedidosYa como
  panel debajo del Estado de resultados. `finance.css`: tokens de la dirección (`--fin-band`, `--fin-keep`, `--fin-seg-1…4`,
  claro y oscuro), títulos, encabezados de tabla sin mayúsculas y botón principal en maracuyá. Verificado contra una copia con
  gastos de ejemplo (octubre en curso con equilibrio el día 8, septiembre en pérdida, agosto con equilibrio el 23, julio sin
  ventas, noviembre futuro): 317 tests, build, contraste 0 en claro y oscuro (Resumen, las otras 5 pestañas y el modal de
  compra) y celular a 390 px.

- **2026-10-09 (POS: cierre de caja confiable, prioridad 1)** — Sobre el análisis del POS y las decisiones del dueño:
  `active()` informa `sales_session_id` (la caja que recibiría una venta, con `CashSessionResolver`) y el POS bloquea el cobro
  sin caja («Abrir turno» lleva a Turno con la apertura abierta; sin conexión usa la copia del día y, sin dato, deja vender con
  aviso). La sincronización marca «rechazada» una venta tras 3 rechazos, con su motivo, y aísla un lote con 422 enviando venta
  por venta; Turno, el indicador de red y la revisión del cierre las muestran con «Reintentar envío». El asistente de cierre
  suma un paso «Revisión» (envía, trae cifras, bloquea con pendientes; admin con motivo → nota + auditoría `cash_close_forced`),
  envía `expected_seen` y maneja el 409 mostrando el esperado nuevo, y su desglose (igual que Turno y el reporte) es solo
  efectivo y suma el esperado. Arqueo en progreso ligado a la caja. Motivos de validación de la sincronización en español.
  Verificado: 315 tests (10 nuevos en `CashCloseSafetyTest`), build, y flujo completo en navegador contra una copia (cobro sin
  turno, apertura desde el POS, venta, venta rechazada aislada, revisión bloqueada, 409 por un egreso tardío, cierre con +5 de
  diferencia correcta, cierre forzado por admin), contraste 0 en claro y oscuro salvo el botón con degradado «Venta
  Retroactiva» (revisado a ojo), y celular a 390 px.

- **2026-10-09 (producción en VPS)** — Decisión del dueño: la producción pasará de la laptop a un VPS (todavía no contratado). Se revisó
  la base de la Mac en solo lectura: 63 de 63 migraciones aplicadas; 3 insumos con seguimiento de stock, 3 marcados clave, 3 con
  proveedor y 3 con consumo diario estimado; 3 composiciones; 2 conteos con 6 líneas; 3 proveedores; 0 insumos con stock y CPP en 0;
  journal en modo `delete` (sin WAL). Se anotaron el destino de producción y la migración a VPS como pendientes. Solo documentación,
  sin cambios de código. Docs: Bitácora y plan.

- **2026-10-09 (ensayo de arranque de inventario en Mac)** — Ejecutado el checklist completo de
  `docs/Checklist de arranque del inventario.md` en la base de la Mac para validar el circuito de inventario antes de producción.
  Respaldo atómico previo: `checklist_arranque_20261009_124500.sqlite`. Paso 1 verificado (0 migraciones pendientes);
  Paso 2 CPP inicial verificado al día; Paso 3 recetas base aplicadas (15 componentes, 10 productos, 29 opciones) y
  composiciones vinculadas a insumos reales (180 g pulpa en base açaí, 1 banana en mix de frutas, 50 g granola en toppings);
  Paso 4 insumos clave verificados (`is_key`), primer conteo de partida (`is_baseline`), merma de prueba anotada (2 bananas,
  Bs 1,00) y segundo conteo semanal comparativo (-0,2 kg pulpa, -0,05 kg granola, 0 dif banana); Paso 5 indicadores de
  inventario (10/2026) comprobados: valor Bs 696,35, cobertura ok (8,2 a 33,2 d), 0 quiebres, merma anotada Bs 1,00, merma
  oculta Bs 2,65 y exactitud 100 % (desvíos dentro del 2 % de tolerancia). Tests: 305 en verde. Build de Vite sin errores.

- **2026-10-09 (inventario F5)** — Fase 5 hecha: indicadores de inventario e informe mensual. `InventoryIndicatorsService` arma, para
  un mes, el valor del inventario (stock × CPP, a hoy), la cobertura (de `ReorderService`; insumos clave o todos si no hay
  marcados), los quiebres (días de venta en que un insumo clave llegó a ≤ 0; se reconstruye el saldo hacia atrás desde el stock
  actual con los movimientos; un insumo cargado dentro del mes no cuenta los días anteriores a su carga), el % de merma (anotada
  ÷ consumo valorado de las ventas menos reversos, total y por insumo, con meta 10 % para perecederos), la merma oculta
  (`WasteReportService`), la diferencia de costo en puntos de las ventas (reutiliza `BreakEvenService::cost_gap`) y la exactitud
  del conteo (≤ 2 % de diferencia, sin primeros conteos), cada uno con estado y el mes anterior. Endpoint
  `GET /finance/inventory-indicators` (solo dueño). Pantallas: tarjeta «Indicadores de inventario» en Finanzas → Resumen (6
  indicadores, 3 × 2, con insignia de estado, meta, detalle y pista de qué revisar) e «Informe del mes» (tablas de cobertura,
  quiebres, merma por insumo, conteos y valor) con «Imprimir» (marco oculto, 2 hojas A4). Las etiquetas de la tarjeta usan el
  mes de los propios datos para no mezclar cifras de un mes con el nombre de otro mientras recarga. Límites a tener presentes:
  los quiebres por «producto marcado agotado» no se miden (no hay historial), los quiebres de meses pasados son aproximados si
  el stock y los movimientos no coinciden, y las metas son constantes. Verificado: 305 tests (21 nuevos), build, contraste 0 en
  8 estados nuevos (tarjeta e informe, claro y oscuro, 1440 y 390 px), mes vacío (enero) sin errores, impresión revisada y, en
  el navegador contra una copia con datos sembrados, tarjeta, informe, cambio de mes y cierre con Esc. Con esta fase queda
  terminado el plan F0 → F5.

- **2026-10-09 (inventario migración F4 y datos de referencia)** — Aplicada en la Mac la migración
  `2026_10_13_000001_add_reorder_fields_to_ingredients` con respaldo previo (`pre_migrate_reorder_20261009_035825.sqlite`).
  Creado `ReorderDefaultsSeeder`: insumos clave (Pulpa, Granola y Banana) actualizados con consumo diario estimado, días de
  cobertura, días de seguridad, vida útil, fruta como «Compra diaria» y vinculados a sus proveedores (Distribuidora Açaí, Granola
  Artesanal, Mercado Central) con días de entrega y teléfono. Tests: 284 en verde.

- **2026-10-09 (inventario F4)** — Fase 4 hecha: punto de reorden, compras sugeridas y alertas. `ReorderService` calcula por
  insumo el consumo diario real (movimientos `sale` y reversos `adjustment` de líneas de venta, H19 sin tipo nuevo: cada reverso
  se resta del día de la venta original y el consumo se fecha con la venta), el stock de seguridad (`1,65 × desvío × √L` con 28
  días con ventas; con menos, días de seguridad × consumo), el punto de reorden, la cantidad sugerida (redondeada a unidades de
  compra y pedido mínimo, limitada por la vida útil) y el estado (OK, Reordenar, Crítico, Agotado, Compra diaria, Sin datos, Sin
  control) más el vencimiento. Sin historial usa el consumo estimado del dueño o el punto manual (`minimum_stock`, que hace de
  piso). Con 8 semanas proyecta por día de la semana; cada insumo cuenta sus días desde su primer consumo. Cinco columnas nuevas
  en `ingredients`. Endpoints `GET /inventory/reorder` y `/summary` (solo admin; las alertas no se guardan). Pantallas:
  Inventario → «Compras sugeridas» (por proveedor, cantidades ajustables, copiar, WhatsApp `wa.me`, imprimir, registrar compra
  con el formulario de F1 ya precargado), columna «Reposición» y campos nuevos en Editar insumo, marca en el menú, tarjeta en
  Finanzas → Resumen y aviso «Antes de abrir» al administrador una vez por turno. El cajero nunca consulta estas alertas.
  Decisión: pedidos en camino no existen (la compra entra al stock al registrarla). Verificado: 284 tests (30 nuevos, con las
  cifras del ejemplo del plan §2.3), build, contraste 0 en 24 estados nuevos (claro y oscuro, 1440 y 390 px; el único aviso, el
  botón «Venta Retroactiva» de Turno en oscuro, ya existía), y en el navegador contra una copia con datos sembrados: chips en la
  lista, lista de compras agrupada con cantidad ajustada y copiada, compra precargada, aviso de apertura una sola vez por
  turno, tarjeta de Finanzas con su enlace, y venta + anulación sin romper el informe.

- **2026-10-09 (inventario F3)** — Fase 3 hecha: mermas con costo y motivo, conteo físico y reporte. Los movimientos de venta,
  merma y conteo guardan el CPP del momento (`InventoryService::costColumns`; `null` si el insumo no tiene costo) y el reverso
  de una venta anulada o editada copia el valor del original. Merma: `POST /inventory/waste` para cajero, admin y dueño (la
  cocina no), seis motivos obligatorios, nunca se bloquea por falta de stock (devuelve un aviso) y el cajero no recibe costos.
  Conteo: `inventory_counts` + `inventory_count_lines` y `InventoryCountService`: la diferencia se calcula contra el stock al
  confirmar, cada diferencia deja un ajuste ligado al conteo y el primer conteo de un insumo queda marcado `is_baseline` (ordena
  el stock de partida y no cuenta como merma oculta). El «Ajuste» manual por diferencia desapareció: `adjustment` exige
  `counted_stock` y crea un conteo de un insumo. `WasteReportService`: merma anotada (por motivo, insumo y persona) y merma
  oculta (suma de diferencias de los conteos del mes sin las líneas de partida); equivale a consumo real − teórico − anotada.
  Punto de equilibrio: `cost_gap` reparte compras − recetas en merma anotada, oculta y resto. Pantallas: `WasteModal` (Caja e
  Inventario), `CountModal` (hoja, revisión y resultado + historial), `MovementHistoryModal`, `WasteReportModal` y casilla
  «Insumo clave». El historial `GET /ingredients/{id}/movements` pasó a admin y paginado. Decisiones: merma del cajero solo con
  conexión (se anota en el servidor, no en la cola offline del POS); el cajero no ve costos. Verificado: 254 tests (25 nuevos),
  build, contraste 0 en 36 estados de las ventanas nuevas (claro y oscuro, 1440 y 390 px) y en el navegador contra una copia:
  merma como cajero y como admin, conteo completo (hoja → revisión → resultado → historial), historial por insumo con filtro,
  reporte del mes, desglose en Finanzas, y una venta con su anulación (costo guardado y reverso al mismo valor).

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
