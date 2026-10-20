# Publicación a producción — lista de control (2026-10-20)

> Rama: `feat/pos-caja-arranque`. Producción (laptop de caja, Windows) se actualiza desde `main` con `actualizar-pos.bat` o el vigilante: `git pull origin main` → `migrate --force` → `npm run build` (sin `npm install`). **Esta lista no fusiona ni sube nada: eso lo decide el dueño.**

## 1. Qué incluye (resumen para el dueño)
| Tema | Qué cambia para el equipo |
|---|---|
| Permisos y roles | Pantalla de permisos en Usuarios; techo por rol + matriz editable; las rutas del servidor validan el módulo (`perm:módulo,nivel`); pantalla «Sin acceso». |
| Cierre de caja | Conteo de billetes en dos columnas con total en el pie y teclado completo; resultado compacto; indicador «Turno abierto» en el menú; el servidor valida que el desglose sume lo contado. |
| Cobro | Cabe en la portátil (1366×611 y 125 %); en pago dividido los montos se completan solos (2 y 3 métodos). |
| Finanzas | Navegación pegada, resumen compacto, Categorías 2×2; **corrige las pestañas que se aplastaban a 1 px**. |
| Scroll | Sin franja blanca ni menú despegado al final de páginas largas (Costos, Caja, Inventario…). |
Detalle: `CHANGELOG.md` → «Sin publicar».

## 2. Riesgos a revisar ANTES de fusionar
1. **Migración `2026_10_15_000001_update_role_permissions_to_match_reality`**: hace `upsert` de los permisos de `admin`, `cashier` y `kitchen` con una matriz fija. **Sobrescribe lo que se haya editado en la tabla `role_permissions`** de producción. Si el dueño ya ajustó permisos, anótalos antes y vuelve a aplicarlos desde Usuarios después. `down()` no revierte: la vuelta atrás es el respaldo de la base.
2. **Migración `2026_10_16_000001_normalize_role_permission_levels`**: baja `read→none` (inventory, voids, crm_config), `edit→read` (crm_read, reports) y borra filas `delivery_config`.
3. **Backend sin probar por mí**: el entorno donde trabajo no tiene PHP 8.4. Los tests `PermissionServiceTest`, `CashRegisterSessionTest` (2 nuevos) y toda la suite deben correrse en la Mac: `cd backend && php artisan test`. No fusionar con algún rojo.
4. Las rutas del servidor cambiaron de `admin` a `perm:…` (152 líneas en `routes/api.php`): un usuario con permisos recortados puede perder una pantalla que antes veía. Revisar con la matriz real (paso 5).
5. Prueba pendiente en la **caja real** (Toshiba): cobro dividido, cierre con billetes, final de Costos y de Caja.

## 3. Verificación ya hecha (en la nube, frontend)
- `npx vite build` correcto; `npm run test:unit` 16/16.
- E2E Playwright: 85 de 87 en verde. Las 2 que fallan (`responsive-mobile.spec.js` 2 y 3) hacen login real y no tienen backend en la nube; no tocan lo cambiado.

## 4. Antes de fusionar (en la Mac)
1. `git status`: no incluir `backend/storage/app/public/`, `graphify-out/`, `deploy/` (viven en otra rama/otro tema).
2. `cd backend && php artisan test` → todo verde.
3. `cd frontend && npx vite build` y `npm run test:unit`.
4. `graphify update .` (AGENTS.md) y, si se quiere, un commit aparte con `graphify-out/`.
5. **Probar contra una COPIA de la base** (procedimiento de AGENTS.md; nunca `artisan serve` con la base real): copiar `database.sqlite`, correr `migrate` en la copia, entrar con cada rol (super_admin, admin, cashier, kitchen) y recorrer: Vender → Cobro dividido → Caja → Cierre con billetes → Finanzas → Usuarios/Permisos.
6. En `CHANGELOG.md` renombrar «Sin publicar» con la fecha de la actualización.

## 5. Día de la publicación (local cerrado, idealmente lunes)
1. **Respaldo** de `backend/database/database.sqlite` (copiar a `backend/storage/app/backups/` con fecha) y, si cambia, `backend/storage/app/public/`.
2. Fusionar la rama en `main` y subir (`git push origin main`). El vigilante hará `git pull`, `migrate --force` y `npm run build`; o ejecutar `actualizar-pos.bat` a mano.
3. Revisar que `migrate` terminó sin error (`php artisan migrate:status`).
4. Humo (5 min): login de cada rol; abrir caja; una venta en efectivo y una dividida (efectivo + QR); cerrar caja contando billetes; Finanzas → Costos hasta el final; Usuarios → permisos de un cajero.
5. Cambiar las contraseñas de las cuentas de ejemplo si siguen siendo `password` (ver `claude/publicacion-cloudflare.md`).

## 6. Vuelta atrás
1. `git revert` del merge (o `git reset --hard <commit anterior>` en la laptop con el vigilante detenido) y `npm run build`.
2. Restaurar el respaldo de la base (las migraciones de permisos no se revierten solas).
3. Avisar al local de que el cierre de caja y el cobro vuelven al diseño anterior.
