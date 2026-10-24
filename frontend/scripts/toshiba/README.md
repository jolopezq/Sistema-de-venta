# Simulación de la portátil de caja (Toshiba)

Herramientas usadas el 2026-10-23 para la Fase 5 de Inventario y la Fase 1 de Finanzas v2
(ver `docs/PLAN_REDISENO_INVENTARIO_PORTATIL.md` §13 y `docs/PLAN_MEJORA_UI_FINANZAS_PORTATIL.md` §7).
Prueban la interfaz contra el **backend real** con datos reales, en los dos perfiles de la Toshiba:

| Perfil | Ventana | Escala |
| :-- | :-- | :-- |
| 100 % | 1366 × 611 | 1 |
| 125 % | 1093 × 489 | 1,25 |

## Regla de oro

**Siempre sobre una COPIA de la base**, nunca sobre la de producción. Los scripts `datos-*.py` y las compras de prueba escriben datos.

## Requisitos

1. **Copia de la base y backend:**
   `cp backend/database/database.sqlite /tmp/copia.sqlite` y luego arrancar el backend con `DB_DATABASE=/tmp/copia.sqlite php artisan serve --port=8000` (PHP 8.4).
   Si no hay PHP 8.4 (por ejemplo, en una nube), usar `php-wasm-server.mjs`:
   - `npm i @php-wasm/node@3.1.60 @php-wasm/universal@3.1.60`
   - `node php-wasm-server.mjs <ruta-absoluta-al-backend> 8000`
   - Usa `vendor` real, no un enlace simbólico.
2. **Frontend:** `cd frontend && npx vite --port 5173`. Hace de proxy de `/api` al 8000.
3. **Credenciales de un super admin de la COPIA**, en variables de entorno: `AUDIT_EMAIL` y `AUDIT_PASSWORD`. Nunca se escriben en archivos.
4. **Opcionales:**
   - `AUDIT_URL` (por defecto `http://127.0.0.1:5173`).
   - `PW_CHROMIUM`: ruta a un Chromium si Playwright no trae el suyo.
   - `API_URL` (por defecto `http://127.0.0.1:8000/api`).

## Scripts

Se corren desde `frontend/`, así: `node scripts/toshiba/<script> <carpeta-salida>`.

| Script | Qué hace |
| :-- | :-- |
| `datos-inventario.py` | Carga en la copia 20 insumos, 5 categorías y 3 proveedores: nombres largos, agotados, de compra diaria. |
| `datos-finanzas.py` | Carga en la copia 12 gastos de octubre de 2026 y 8 gastos fijos. |
| `simular-inventario.cjs` | Recorre los 16 estados de Inventario: lista, «Más», nuevo, editar, Categorías, Proveedores, Registrar compra (4 variantes), merma, conteo, compras sugeridas, historial, mermas del mes y borrado.<br>Lo hace en los dos perfiles, en claro y oscuro, con la CPU 4× más lenta.<br>Mide: ventana dentro de la pantalla, pie al alcance, scroll horizontal, foco y contraste AA.<br>Con el argumento `completo` además guarda una compra real. |
| `teclado-inventario.cjs` | 18 comprobaciones solo con teclado al 125 %, incluida una compra real. |
| `medir-finanzas.cjs` | Mide las 6 pestañas y 7 ventanas de Finanzas en los dos perfiles y audita el contraste recorriendo todo el contenido. |
| `finanzas-fase1.cjs` | 32 comprobaciones de la Fase 1 de Finanzas v2 contra el backend real (categoría vacía, avisos junto al campo, sugerencia por proveedor, foco). |

La salida de cada script es un resumen en consola (✔/✘), capturas PNG y un `*-report.json` en la carpeta de salida.
