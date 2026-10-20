# Auditoría: Roles y permisos (Opción C v2)

> **Fecha:** 2026-10-15 · **Rama:** `feat/pos-caja-arranque` · **Alcance:** código de la copia de trabajo (sin commit)
> contrastado con [PLAN_ROLES_PERMISOS.md](PLAN_ROLES_PERMISOS.md).
> Contexto: [AGENTS.md](../AGENTS.md), [BITACORA.md](BITACORA.md), [CHANGELOG.md](../CHANGELOG.md).

---

## 0. Veredicto

- **El núcleo del backend está bien hecho y se comporta como dice el plan:** el techo por rol se aplica al decidir,
  los valores desconocidos fallan cerrado, la lectura y la escritura están separadas en ventas, caja y pedidos,
  `/login` y `/me` devuelven los permisos efectivos y el guardado es transaccional. Lo confirmé con peticiones reales
  (sección 6).
- **Todavía no conviene fusionar a `main`.** Hay una **regresión alta en el frontend**: el refresco de permisos "al
  volver el foco" cierra la sesión si en ese momento no hay conexión. Además, **la migración ahora condiciona la venta**:
  sin ella el cajero no puede vender ni abrir caja. Y la **Fase 0 no se hizo**: todo sigue sin commit, mezclado con
  cambios ajenos y con 7 imágenes que no deben subirse.
- El resto son ajustes de coherencia (lectura parcial en cajas ajenas, "Solo lectura" sin efecto en tres módulos, costos
  de insumos visibles para todos, guardado de celdas sin cargar) y pruebas que no cubren lo que dicen.
- El documento del plan había sido borrado aunque lo citan la bitácora, la migración y los tests. **Se restauró** con su
  estado actualizado.

---

## 1. Cómo se verificó

| Verificación | Resultado |
|---|---|
| Lectura del código: servicio, middleware, request, controladores, rutas, migración, tests, store, router, menú, matriz, `SinAcceso.vue`, `api.js`, `syncService.js` | Completa |
| **Sondas HTTP**: un script arranca la app (Laravel 13.20) sobre una copia del backend con SQLite temporal, ejecuta todas las migraciones y hace peticiones reales por el kernel HTTP con tokens Sanctum. Sin datos reales: la base de la Mac no se copió. | Sección 6 |
| `npx vite build` sobre una copia del frontend | OK (4 s, PWA con service worker generada) |
| Build de producción en Chromium headless con la API simulada (prueba de foco sin conexión) | Reproduce el hallazgo A1 |
| `php artisan test` | **No se pudo ejecutar aquí.** Las dependencias exigen PHP ≥ 8.4.1; el único PHP 8.4 disponible fue una versión WebAssembly y PHPUnit se cae en ella. Las cifras "340 tests / 2.102 aserciones" de la bitácora quedan **sin verificar**. |
| Base de esta Mac (solo lectura) | `role_permissions` sigue con las 25 filas de julio; la migración `2026_10_15_000001` **no corrió**. |

---

## 2. Cumplimiento del plan

✔ hecho · ◐ parcial · ✘ no hecho

| Punto del plan | Estado | Nota |
|---|---|---|
| **Fase 0** — commits separados por tema | ✘ | Nada commiteado. Siguen mezclados `KdsController` (keep-alive SSE), `UserResource` (`ci`, `phone`, `start_date`), `ManageProductImages`, `deploy/vps/`, scripts de imágenes y 7 `.jpg` en `backend/storage/app/public/products/`. |
| Fase 0 — respaldo antes de migrar | ? | No verificable; la migración aún no corrió en la Mac. |
| A1 — techo explícito | ✔ | `PermissionService::CEILINGS`, validación contra el techo y celdas dibujadas desde `ceilings`. |
| A2 — "Solo lectura" honesto | ◐ | Separación por verbo hecha en `pos`, `register_self` y `delivery_ops`. Quedan B1 y B2. |
| A3 — sin bucles de redirección | ✔ | `landingFor()` + `/sin-acceso`; si el destino seguro es la misma ruta, va a `/sin-acceso`. |
| A4 — migración robusta | ◐ | `upsert` con valores literales ✔. Ahora la venta depende de ella (A2 de esta auditoría). Deja 2 filas `delivery_config` sobrantes. |
| M1 — techo dentro de `can()` | ✔ | Sonda 6 y test 1. |
| M2 — falla cerrado | ◐ | Backend ✔ (excepción). Frontend: un nivel desconocido sigue valiendo `read` (`auth.js` L24). |
| M3 — lógica duplicada | ◐ | `fallbackPermission()` eliminado ✔. `AdminUsers.vue` conserva una copia fija de techos y restricciones (L383–394). |
| M4 — módulos decorativos | ✔ | `crm_read` en `GET /customers`, `register_others` en el controlador de caja, `delivery_config` fuera de `MODULES`. |
| M5 — etiquetas | ✔ | Coinciden con la sección 5 del plan. |
| M6 — Usuarios y Auditoría para admin | ✔ | Celdas "Solo Super Admin". |
| M7 — menú y router alineados | ✔ | Control diario con `pos,edit`; Delivery sin excepción para Cocina. |
| M8 — transacción y pares únicos | ✔ | Con un caso de error 500 (B5). |
| M9 — refresco de permisos | ✘ | Implementado de forma que introduce la regresión A1. |
| 6.2 — decidir "solo lectura" de Inventario | ✘ | No se decidió; ver B2. |
| Tests 1–9 | ◐ | 7 de 9 prueban lo que dicen; el 4 y el 8 no (B4). Faltan casos. |
| D1–D6 | ✔ | Se aplicaron las recomendaciones del plan. Si no las aprobaste expresamente, revísalas en la sección 8 del plan. |
| AGENTS.md, CHANGELOG.md | ✔ | Actualizados. |
| BITACORA.md | ◐ | Enlazaba a un plan borrado y declara cifras de tests no verificadas. |

---

## 3. Hallazgos de prioridad alta

### A1. Se cierra la sesión al volver el foco sin conexión
- **Evidencia:** `auth.js` L86–94 registra `window.addEventListener('focus', …)` que llama a `fetchUser()`. `fetchUser()`
  (L66–81), ante **cualquier** error, borra el token y el usuario. `fetch()` lanza un error cuando no hay red, así que
  cae en ese `catch`. El refresco por 403 de `api.js` (L70–76) llama a la misma función.
- **Reproducción** (build de producción en Chromium, API simulada):
  1. Con servidor disponible: ruta `/turno`, token presente.
  2. Se corta el servidor y la ventana vuelve a tener el foco: **token `null`**.
  3. Siguiente navegación a `/pos`: **termina en `/login`**.
- **Impacto:** el POS es una PWA offline-first. Basta que se caiga el túnel o la red y la cajera cambie de ventana para
  que quede fuera en pleno turno, sin poder volver a entrar hasta que regrese la conexión. Las ventas pendientes no se
  pierden (quedan en IndexedDB), pero no se puede seguir vendiendo. También pasa ante un 500 o un timeout. Antes de
  este cambio, `fetchUser()` solo se llamaba al arrancar.
- **Corrección:**
  - En `fetchUser()`, cerrar la sesión **solo si la respuesta es 401** (`apiFetch` ya lo hace por su cuenta); ante errores
    de red o 5xx, conservar `user` y `effective_permissions`.
  - En el listener de foco: no hacer nada si `navigator.onLine === false`, y no refrescar más de una vez por minuto.
- **Aceptación:** repetir la reproducción y comprobar que el token sigue y la navegación no va a `/login`; con el servidor
  de vuelta, el siguiente foco actualiza los permisos.

### A2. Sin la migración nueva, el cajero no puede vender ni abrir caja
- **Evidencia (sonda 6):** con las filas de julio, que son las que tiene hoy la base de la Mac, y el código nuevo:

  | Petición | Respuesta |
  |---|---|
  | Cajero `POST /sales/sync` (`pos = read`) | **403** |
  | Cajero `POST /cash-sessions` (`register_self = read`) | **403** |
  | Admin `POST /categories` (`catalog = read`) | **403** |
  | Tras ejecutar la migración: admin `POST /categories` · cajero `POST /cash-sessions` | 201 · 409 (pasa el permiso; ya tenía un turno abierto) |

- **Impacto:** en la v1 solo afectaba al admin. Con la separación por verbo, ahora también **detiene la caja**. Las
  ventas no se pierden (`syncService.js` L28 trata el 403 como reintento), pero sin turno abierto no se vende.
  - **Vigilante de Windows:** `git pull` → `migrate --force`; si la migración falla, registra el error y sigue con el
    código nuevo.
  - **Mac:** `iniciar-pos.command` no migra, así que al arrancar la Mac con este código el cajero queda bloqueado.
  - **VPS:** `deploy/vps/04-actualizar.sh` respalda y migra (correcto).
- **Corrección:**
  - En la Mac, antes de usarla: respaldo de la base y `php artisan migrate`.
  - Vigilante: si `migrate` falla, detener la actualización y avisar en vez de seguir.
  - Procedimiento de la Fase 4 del plan (respaldo → migración → `migrate:status` → `GET /api/me` del cajero con
    `pos: edit`) anotado en la bitácora.

### A3. Fase 0 sin hacer (bloqueante de proceso)
- Todo el trabajo sigue sin commit y mezclado con cambios ajenos. Un `git add -A` subiría las 7 imágenes de
  `backend/storage/app/public/products/`, que según AGENTS.md el vigilante borraría o cambiaría en producción.
- **Corrección:** commits por tema (permisos; keep-alive del KDS; `UserResource`; documentos; imágenes y scripts de
  respaldo de imágenes por separado o fuera del repo), revisando `git status` antes de cada `git add`.

---

## 4. Hallazgos de prioridad media

| # | Hallazgo | Evidencia | Corrección |
|---|---|---|---|
| B1 | **"Lectura" de cajas ajenas a medias.** Con `register_others = read`, el admin ve en la lista los turnos de otros cajeros, pero abrir uno, su arqueo o sus movimientos responde 403. `ensureCanAccess()` exige `edit` en todos los casos. | Sonda 4; `CashRegisterSessionController` L399–405. | Pasar el nivel a `ensureCanAccess()`: `read` en `show`, `report` y `movements`; `edit` en `close` y `recordMovement`. |
| B2 | **"Solo lectura" sin efecto o que rompe pantallas.** La matriz ofrece "Solo lectura" en Inventario, Anular ventas y Clientes — configurar. Inventario en `read`: el menú muestra la pantalla, pero conteos, reorden y proveedores responden 403. Anular y Configurar clientes no tienen ninguna ruta de lectura. | Sonda 3; `routes/api.php` L63–79, L87–93. | Declarar por módulo los niveles válidos (p. ej. `ALLOWED_LEVELS`) y dibujar solo esos botones: Inventario, Anular y Clientes — configurar con "Sin acceso / Edición". O, en Inventario, abrir con `read` los `GET` de conteos, reorden, movimientos y proveedores. |
| B3 | **Costos de insumos visibles para todos.** `GET /ingredients` y `GET /ingredients/{id}` (con movimientos) siguen abiertos a cualquier usuario autenticado y devuelven `unit_cost`, `weighted_avg_cost`, stock y proveedor. Cocina y Cajero reciben 200 aunque su techo en Inventario es `none`. Ninguna pantalla de ellos lo usa: las mermas de Turno usan `/inventory/waste`. | Sonda 2; `routes/api.php` L42; `IngredientResource`. | Proteger esas lecturas con "inventario **o** catálogo en lectura" (Menú las usa para las recetas). Por ejemplo, un middleware `perm_any:inventory,read\|catalog,read`. |
| B4 | **Guardar la matriz convierte en `none` las celdas sin cargar.** `doSavePermissions()` usa `mod.admin ?? 'none'` (también para cajero y cocina). Si `GET /permissions` falla, la pantalla queda toda "pendiente" y guardar escribe `none` para todos. Los avisos previos solo cubren a Cocina y Cajero, no al admin. | `AdminUsers.vue` L598, L604, L610, L577. | No permitir guardar hasta que la matriz cargue; para una celda nula, usar `defaults` (la API ya los envía y la pantalla no los usa) o no enviarla. |
| B5 | **Pruebas que no prueban lo que dicen.** `test_route_access_matches_ceiling` solo consulta niveles del servicio y no hace ninguna petición, así que no detecta divergencias con `admin` o `role:`. `test_update_is_atomic_and_rejects_duplicates` prueba la validación, no la transacción. Faltan: `register_others` (read / none / edit), `GET /customers` para Cocina, `POST /cash-sessions` con `register_self = read` y el caso de foco sin conexión. | `PermissionServiceTest.php` L310–331, L453–478. | Agregar esos casos; el test 4 debe recorrer las rutas `perm:` por rol con la matriz al máximo. |
| B6 | **Cifras de tests sin verificar.** La bitácora y el CHANGELOG declaran 340 tests y 2.102 aserciones. | Sección 1. | Ejecutar `cd backend && php artisan test` en la Mac antes del commit y anotar el resultado real. |

---

## 5. Hallazgos de prioridad baja

- `PUT /permissions` con `"permissions": "x"` responde **500**: `withValidator` recorre la entrada sin comprobar que sea un
  arreglo (sonda 5; `RolePermissionRequest` L45). Añadir `is_array`.
- La migración deja 2 filas `delivery_config` (admin y cajero) que ya no corresponden a ningún módulo (sonda 6).
  Borrarlas en la misma migración.
- `auth.js` L24: un nivel desconocido equivale a `read`; debería denegar, como el backend.
- `AdminUsers.vue` L383–394 mantiene una copia fija de techos que se usa si la API falla. Mejor mostrar un error y no
  permitir editar.
- `CashRegisterSessionController` y `AuthController` reciben `PermissionService` por constructor. Laravel guarda la
  instancia del controlador en la ruta, así que en un proceso con varias peticiones (un test que hace más de una, u
  Octane en el futuro) la caché de permisos queda vieja. En producción, con un proceso por petición, no tiene efecto.
  Resolverlo en el método (`app(PermissionService::class)` o inyección en la firma).
- Quitar `pos = edit` a un cajero que tiene ventas pendientes en su equipo las deja en cola indefinidamente (el 403 se
  reintenta) y bloquean su cierre de caja. Conviene documentarlo junto a la matriz.
- `GET /kds/stream` valida el permiso solo al conectar: una revocación no corta una conexión abierta.
- La rama `super_admin` de `withValidator` sigue siendo inalcanzable (la regla `in:` ya lo rechaza).
- El aviso previo al guardar no contempla un admin sin pantallas y, si Cocina y Cajero quedan sin acceso a la vez, solo
  muestra el primer aviso.
- El plan decía "Laravel 11"; la app corre Laravel 13.20. Corregido en el plan.

---

## 6. Evidencia: sondas contra una base temporal

Base SQLite nueva con todas las migraciones aplicadas (36 filas en `role_permissions`), usuarios de prueba por rol y
peticiones reales por el kernel HTTP. Para el caso de cajas ajenas, el controlador se reinstanció en cada petición,
como en producción.

| # | Caso | Resultado |
|---|---|---|
| 1 | Permisos efectivos (`/me`) | admin: todo `edit` salvo `crm_read`/`reports` `read` y `users`/`audit` `none` · cajero: `pos`, `register_self`, `delivery_ops` `edit`, `crm_read` `read` · cocina: solo `delivery_ops` `edit`. Coincide con la sección 5 del plan. |
| 2 | Cocina / Cajero `GET /ingredients` | **200 / 200** (B3) |
| 2 | Cocina / Cajero `GET /customers` | 403 / 200 ✔ |
| 3 | Admin con `inventory = read`: `GET /inventory/counts`, `/inventory/reorder`, `/suppliers` | **403 / 403 / 403** (B2) |
| 4 | Admin con `register_others = read`: lista de turnos / ver turno ajeno / arqueo / movimientos | 200 (incluye el ajeno) / **403 / 403 / 403** (B1) |
| 4 | Admin con `register_others = none`: lista | 200, sin el turno ajeno ✔ |
| 4 | Admin con `register_others = edit`: arqueo ajeno | 200 ✔ |
| 5 | `PUT /permissions` con `"permissions": "x"` | **500** (baja) |
| 5 | `PUT /permissions` con cajero/catálogo `edit` · con módulo `delivery_config` | 422 · 422 ✔ |
| 6 | Datos de julio, sin migración: cajero `POST /sales/sync` · cajero `POST /cash-sessions` · admin `POST /categories` | **403 · 403 · 403** (A2) |
| 6 | Tras ejecutar la migración sobre esos datos | admin `POST /categories` 201 ✔; cajero `POST /cash-sessions` 409, pasa el permiso ✔; quedan 38 filas, 2 de `delivery_config` |
| 7 | Cocina `PATCH /order-queue/{id}/status` · `PATCH /kds/orders/{id}/status` · `GET /kds/orders` | 200 · 200 · 200 ✔ |

**Navegador** (build de producción, API simulada, service worker bloqueado): con servidor `/turno` y token presente →
sin servidor, evento `focus` → token `null` → navegación a `/pos` termina en `/login` (A1).

---

## 7. Orden de corrección antes de fusionar

| Paso | Qué | Aceptación |
|---|---|---|
| 1 | A1: `fetchUser()` solo cierra sesión con 401; foco ignorado sin conexión y limitado a 1 por minuto. | La reproducción de la sección 6 mantiene el token. |
| 2 | B1: niveles en `ensureCanAccess()`. | Admin con `register_others = read` abre turno, arqueo y movimientos ajenos, y no puede cerrarlos. |
| 3 | B2: niveles válidos por módulo en la matriz. | Ninguna celda ofrece un nivel que no cambie nada. |
| 4 | B4: no guardar sin matriz cargada; nulos con `defaults`. | Con `GET /permissions` fallando, el botón Guardar queda deshabilitado. |
| 5 | B3: lecturas de insumos con inventario o catálogo. | Cocina y Cajero reciben 403 en `GET /ingredients`; Menú sigue cargando recetas. |
| 6 | Bajas rápidas: `is_array` en el request, borrar filas `delivery_config`, nivel desconocido = denegar en `auth.js`. | Sondas 5 y 6 sin 500 ni sobrantes. |
| 7 | B5: tests faltantes y test 4 real. | `php artisan test` en verde en la Mac, con la cifra anotada en la bitácora (B6). |
| 8 | A3: commits por tema, sin imágenes. | `git log` con un commit por tema; `backend/storage/app/public/` fuera. |
| 9 | A2: respaldo + migración en la Mac; vigilante que se detiene si la migración falla; procedimiento en la bitácora. | `migrate:status` con `2026_10_15_000001` aplicada; flujo completo de AGENTS.md (login → abrir caja → vender → cocina → anular → cerrar caja) contra una copia de la base. |
