# Guía para agentes — Ohana POS

Léela completa antes de tocar código. El estado vivo del trabajo está en
[docs/BITACORA.md](docs/BITACORA.md) y el historial en [CHANGELOG.md](CHANGELOG.md).

## Al empezar una sesión
1. Lee `docs/BITACORA.md`: "Estado actual", "Pendientes" y la última entrada del registro.
2. Revisa `git status` y `git log --oneline main..HEAD` para ver la rama de trabajo y lo que falta integrar.
3. Si hay cambios sin commit que no son tuyos, no los mezcles con los tuyos: pregunta o sepáralos.

## Reglas de trabajo
- **Antes de modificar código, consulta el grafo de dependencias** en `graphify-out/GRAPH_REPORT.md`
  o la nota del archivo en `graphify-out/obsidian/<Nombre>.md` (también `graph.json`) para medir el impacto.
  El grafo no detecta imports dinámicos (`await import(...)`): confirma con `grep`. Si el grafo está
  desactualizado respecto al código, dilo y regenéralo antes de apoyarte en él.
- **Presenta un plan y espera aprobación antes de cambios grandes** o que afecten datos.
- **No modifiques datos reales sin confirmación explícita** (base SQLite, correcciones históricas).
  Haz respaldo antes de migrar: `backend/storage/app/backups/` (ignorado por git).
- **Trabaja en una rama, nunca directo en `main`**: el vigilante de producción
  (`vigilante-actualizaciones.ps1`) actualiza la laptop desde `main` y ejecuta `migrate --force`.
- **Commits** en español, estilo convencional (`feat(scope): …`, `fix(…)`, `docs: …`), uno por tema.
  No hagas push ni fusiones en `main` sin que el usuario lo pida.
- **Cada cambio funcional actualiza en el mismo commit** `CHANGELOG.md` (sección "Sin publicar") y,
  si cambia el estado del plan o surge una decisión/pendiente, `docs/BITACORA.md`.
- Escribe en español, como el resto del código y la documentación.

## Verificación obligatoria
| Qué cambiaste | Cómo verificar |
|---|---|
| Backend | `cd backend && php artisan test` (todo en verde) |
| Frontend | `cd frontend && npx vite build` |
| Estilos / colores | Con backend y frontend levantados: `node scripts/audit-contrast.cjs <carpeta> dark` y `... light` desde `frontend/` (objetivo: 0 textos bajo WCAG AA; los marcados `img=true` van sobre degradados y se revisan a ojo) |
| Flujo de venta completo | Login → abrir caja → vender → cocina → anular (admin) → cerrar caja, contra una **copia** de la base (`DB_DATABASE=<copia> php artisan serve --port=8001`) |

## Datos del sistema que no son obvios
- **Base de datos: SQLite** (`backend/database/database.sqlite`), no MySQL como dice el README.
  Zona horaria de la app: `America/La_Paz`. El POS envía fechas en UTC; el backend las convierte.
- **Producción (Windows)**: `actualizar-pos.bat` y el vigilante ejecutan `migrate --force` y
  `npm run build`, **pero nunca `npm install`**. No agregues dependencias npm sin cambiar esos scripts;
  por eso las fuentes van como archivos en `frontend/src/assets/fonts/`.
- `npm install` falla hoy por un conflicto de peers previo (vue-router 5 pide Vite ≥7; el proyecto usa Vite 5).
- **Roles**: `super_admin`, `admin`, `cashier`, `kitchen`. Se aplican en backend con los middlewares
  `admin`, `super_admin` y `role:...` (`routes/api.php`). La matriz `role_permissions` que se edita en
  Usuarios **no se aplica** en ningún lado (decisión pendiente, ver bitácora).
- **Ventas ↔ caja**: cada venta guarda `cash_register_session_id`, asignado en `Sale::saving` por
  `App\Services\CashSessionResolver`. El arqueo suma por ese vínculo, no por rango de horas.
- **Sincronización offline**: `SyncSalesRequest` define qué campos llegan al servicio; un campo sin
  regla se descarta en `validated()`. Si agregas un campo al payload del POS, agrega su regla.
- **Precios**: `SalePricingService` recalcula importes; las diferencias con el catálogo se aceptan y se
  registran en `audit_logs` (`sale_sync_discrepancy`).

## Trampas conocidas
- En componentes Vue `scoped`, **no uses `:global(html.dark) .x`**: compila a `html.dark{}` y no aplica.
  Usa `html.dark .x` (Vue añade el atributo de alcance al último selector).
- Colores: usa las variables de `frontend/src/style.css`. Para texto sobre naranja/verde usa
  `var(--on-accent)`; para texto naranja `var(--passion-text)`; dorado `var(--gold-text)`;
  morado sobre fondos suaves `var(--acai-text)`. Control Diario usa el puente `--tw-*`.
- En un `git worktree`, **no enlaces `backend/vendor` con symlink**: el autoloader resuelve la ruta real
  y ejecuta el código (y los tests) del repositorio principal. Copia `vendor/` y corre `composer dump-autoload`.
- `vite build --outDir` fuera del proyecto no vacía la carpeta: usa `--emptyOutDir`.

## Mapa rápido
- Backend: `backend/app/Services/` (SaleSyncService, SalePricingService, CashSessionResolver,
  InventoryService, BackupService), `backend/routes/api.php`, tests en `backend/tests/Feature/`.
- Frontend: `frontend/src/views/` (Pos, Turno, Delivery, Menu, InventoryList, RetroactiveSales,
  AdminUsers, AuditLogs), `frontend/src/stores/` (cart, turno, auth, network), `frontend/src/style.css`.
- Documentación previa (puede estar desactualizada): `README.md`, `docs/architecture.md`,
  `docs/ANALISIS_SISTEMA_PEDIDOS.md`.
