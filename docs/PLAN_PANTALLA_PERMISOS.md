# Plan: nueva pantalla de Roles y permisos para la portátil de caja

> **Contexto:** [PLAN_ROLES_PERMISOS.md](PLAN_ROLES_PERMISOS.md) (modelo techo + matriz),
> [AUDITORIA_ROLES_PERMISOS.md](AUDITORIA_ROLES_PERMISOS.md) (hallazgos B1–B5),
> [AGENTS.md](../AGENTS.md) (reglas de trabajo) y la maqueta aprobada
> ([lienzo «Roles y permisos — rediseño»](https://claude.ai/artifact/2jkmcNYRHtZaxVFHLCbHLm), privado).
> **Rama:** `feat/pos-caja-arranque`. **Estado (2026-10-16):** plan, sin implementar.

---

## 0. Resumen

Reemplazar la tabla de 13 × 5 de **Usuarios → Roles y permisos** por la pantalla de la maqueta:

- **Un rol a la vez:** Cocina, Cajero o Administrador; Super Admin aparece como dato fijo.
- **Una frase arriba** que resume lo que puede hacer ese rol.
- **Una fila por módulo,** con solo los niveles que de verdad cambian algo, nombrados como acciones ("Ver ventas", "Vender").
- **Una vista previa del menú** que verá ese rol, con la pantalla donde abre.

Debe caber completa en la portátil de caja **sin scroll horizontal ni vertical**.

El trabajo tiene una parte de backend (niveles válidos por módulo y lectura de cajas ajenas) porque la pantalla no puede
ofrecer opciones que la API no respete.

---

## 1. Requisitos de pantalla

**Equipo:** portátil de 15,6" con 1366 × 768 (16:9, unos 100 ppp; 1 px ≈ 0,25 mm), **sin pantalla táctil**, con touchpad o
mouse, Windows en escala 100% y navegador en zoom 100%.

Alto útil para la página (medido):

| Cómo se abre el sistema | Viewport | Contenido (sin menú lateral ni barra superior) |
|---|---|---|
| Chrome maximizado con barra de favoritos (**peor caso**) | 1366 × 611 | 1191 × 570 |
| Chrome maximizado | 1366 × 641 | 1191 × 600 |
| Instalado como app | 1366 × 697 | 1191 × 656 |
| Pantalla completa (F11) | 1366 × 768 | 1191 × 727 |

A ese tamaño la app ya usa su modo compacto (`AppShell.vue`, `@media (max-width: 1366px), (max-height: 800px)`):
menú lateral de 175 px y barra superior de 41 px.

**Criterios de ajuste:**
1. **Nunca** scroll horizontal, en ningún ancho, ni de la página ni dentro de `.oh-shell-content`.
2. En **1366 × 611** el rol Administrador (10 módulos), con cambios sin guardar y un aviso visible, entra completo sin
   scroll vertical. Cocina y Cajero, con más razón.
3. Por debajo de 1366 de ancho se permite scroll **vertical** (la vista previa baja debajo de la lista), nunca horizontal.
4. Texto de 12,5 px como mínimo para secundarios y 14 px para nombres (3,2–3,5 mm en esta pantalla); botones
   segmentados de 30 px de alto (suficiente sin pantalla táctil).

Estado actual, para comparar: no tiene scroll horizontal, pero la tabla empieza en y = 323 y obliga a bajar 484 px; solo se
ven 5 de los 12 módulos.

---

## 2. Diseño a implementar

Medidas tomadas de la maqueta renderizada en 1366 × 611 (Administrador con 3 cambios y aviso: alto total 611, sin scroll).

```
┌────────┬───────────────────────────────────────────────────────────────────────────────┐
│ Menú   │ Barra superior de la app (41 px)                                    En línea  │
│ lateral├───────────────────────────────────────────────────────────────────────────────┤
│ 175 px │ Usuarios y permisos   Usuarios  [Roles y permisos]   3 cambios sin guardar    │ ~44 px
│        │                                                      [Descartar] [Guardar]    │
│        │ [● Cocina][● Cajero][● Administrador]  Super Admin   El administrador puede…  │ ~44 px
│        │ ─────────────────────────────────────────────────────┐  Así lo verá el admin. │
│        │ Ventas                     [Sin acceso|Ver ventas|Vender]│ ┌─────────────────┐ │
│        │ Cobrar, revisar las ventas…                           │  │ Ohana POS        │ │
│        │ … 10 filas de 40 px …                                 │  │ Vender  Abre aquí│ │ 10 × 40 px
│        │ Usuarios, auditoría… solo del Super Admin.  Volver a… │  └─────────────────┘ │
└────────┴───────────────────────────────────────────────────────────────────────────────┘
```

| Bloque | Medidas |
|---|---|
| Contenido | `padding: 12px 24px`; columna con `gap: 10px`; `max-width: 1240px` |
| Encabezado (común a las dos pestañas) | Título Baloo 2 600 22 px; pestañas Inter 13,5 px de 36 px; a la derecha, las acciones de la pestaña activa (Usuarios: buscador y "Nuevo usuario"; Permisos: estado, Descartar y Guardar cambios de 34 px). Se quita el subtítulo. |
| Franja de rol | Selector segmentado de botones de 40 px (nombre 14 px y conteo 11,5 px), nota fija "Super Admin: puede todo, no se configura" y la frase en Baloo 2 600 20 px / 1,25, máximo 2 líneas |
| Lista | Filas `min-height: 38px; padding: 2px 0` con borde inferior; nombre 14 px / 18; descripción 12,5 px / 16; control en una columna de `flex: 0 1 336px` para que los controles queden alineados |
| Control segmentado | Pista `--cream-200`, botones de 30 px, 13 px, `padding: 0 12px`. Nivel con acceso seleccionado: fondo `--acai-700` y texto blanco. "Sin acceso" seleccionado: fondo `--surface` con `--shadow-card` (el morado marca solo lo que el rol sí puede hacer). |
| Vista previa | `flex: 1 1 236px; max-width: 280px`; réplica del menú lateral compacto (fondo `--acai-800`, ítems de 30 px, 12,5 px); etiquetas "Abre aquí" (`--lime-100` / `--lime-700`) y "Solo ver"; debajo, "No verá …". Si el rol no tiene pantallas, tarjeta "Acceso no disponible" (`--danger-100` / `--danger-text`). |
| Pie de la lista | Límites fijos del rol (12,5 px) y "Volver a lo recomendado" cuando el rol difiere de los valores recomendados |

**Colores:** solo variables de `frontend/src/style.css`; todas tienen versión oscura. Texto sobre naranja con
`--on-accent` ("Guardar cambios" con fondo `--passion-500`). Cambios en `--passion-text` con punto `--passion-600`. Avisos en
`--danger-text`. Los colores de rol salen de `ROLE_META` de `AdminUsers.vue`.

**Comportamiento:**
- **Cambios:** una fila cambiada muestra un punto y "Cambiado. Antes: …" en lugar de la descripción. El encabezado cuenta
  los cambios de los tres roles.
- **Aviso de rol sin pantallas:** si algún rol queda sin pantallas, debajo del contador aparece "La cuenta de cocina quedará
  sin ninguna pantalla." No hay diálogos de confirmación.
- **Guardar:** "Guardar cambios" y "Descartar" quedan deshabilitados sin cambios, mientras carga y si la carga falló
  (hallazgo B4). Al guardar se envían **solo las filas cambiadas** y el estado pasa a "Cambios guardados. Se aplican desde
  la próxima acción de cada persona." durante 5 s.
- **Error de carga:** tarjeta "No se pudo cargar la configuración de permisos." con "Reintentar"; no se muestra la matriz.
- **Cambio de rol:** no pierde el borrador; los tres roles se guardan juntos.

### 2.1 Catálogo de módulos y niveles válidos

Orden en pantalla. Solo se ofrecen los niveles válidos del módulo que además no superen el techo del rol.

| Módulo | Nombre | Descripción | Niveles válidos y etiqueta |
|---|---|---|---|
| `pos` | Ventas | Cobrar en el punto de venta y revisar las ventas del día. (Admin: "…y cargar ventas en Control diario.") | none Sin acceso · read Ver ventas · edit Vender |
| `register_self` | Su propia caja | Abrir y cerrar su turno, registrar movimientos y mermas. | none · read Ver su turno · edit Abrir y cerrar |
| `delivery_ops` | Pedidos y comandas | La cola de pedidos y la pantalla de cocina. | none · read Ver pedidos · edit Cambiar estados |
| `crm_read` | Clientes | Buscar clientes y ver su historial y puntos. | none · read Consultar |
| `voids` | Anular ventas | Anular un ticket indicando el motivo. | none · edit Anular |
| `register_others` | Cajas de otros | Revisar o cerrar el turno de otra persona. | none · read Ver · edit Ver y cerrar |
| `catalog` | Menú y precios | Productos, opciones, recetas y precios de PedidosYa. | none · read Ver · edit Editar |
| `inventory` | Inventario y compras | Insumos, conteos, compras y proveedores. | none · edit Gestionar |
| `crm_config` | Fidelización | Crear clientes y definir el cashback. | none · edit Configurar |
| `reports` | Reporte de mermas | Cuánto se perdió y por qué. | none · read Consultar |

`users` y `audit` no aparecen: son solo del Super Admin y lo dice el pie de la lista.

### 2.2 Frase resumen

- **Cocina y Cajero:** sujeto + verbos de los módulos con acceso, unidos con comas e "y". Ejemplo: "El cajero vende, abre
  y cierra su caja, marca el avance de los pedidos y consulta clientes."
- **Administrador con todo:** "El administrador puede todo lo operativo: ventas, cajas, pedidos, menú, inventario y
  clientes."
- **Administrador con hasta 4 recortes:** "…puede todo lo operativo excepto cobrar y editar el menú." Con más recortes se
  usa la lista de verbos.
- **Sin ningún permiso:** "Cocina no tiene ningún permiso: al entrar verá «Acceso no disponible»."

Textos para `permissionModules.js` (los mismos de la maqueta):

| Módulo | Verbo (read / edit) | Recorte en la frase del admin (none / read) | Límite fijo ("nunca puede …") |
|---|---|---|---|
| `pos` | ve las ventas sin poder cobrar / vende | vender / cobrar | vender |
| `register_self` | ve su turno / abre y cierra su caja | usar su caja / abrir y cerrar su caja | usar una caja |
| `delivery_ops` | ve los pedidos / marca el avance de los pedidos | ver pedidos / cambiar el estado de los pedidos | ver pedidos |
| `crm_read` | consulta clientes / — | consultar clientes / — | consultar clientes |
| `voids` | — / anula ventas | anular ventas / — | anular ventas |
| `register_others` | ve las cajas de otros / revisa y cierra cajas de otros | ver cajas de otros / cerrar cajas de otros | ver cajas de otros |
| `catalog` | consulta el menú / edita el menú y los precios | ver el menú / editar el menú | ver el menú |
| `inventory` | — / maneja inventario y compras | manejar inventario / — | manejar inventario |
| `crm_config` | — / configura la fidelización | configurar la fidelización / — | configurar la fidelización |
| `reports` | revisa el reporte de mermas / — | ver el reporte de mermas / — | ver el reporte de mermas |

Sujetos: "Cocina", "El cajero", "El administrador". En la vista previa y los avisos: "la cuenta de cocina", "el cajero",
"el administrador". Los límites fijos se unen con "ni" y cierran con ": son límites fijos del sistema." Siempre sigue
"Usuarios, auditoría, finanzas y respaldos son solo del Super Admin."

---

## 3. Antes de empezar

| Requisito | Por qué |
|---|---|
| Fase 0 de la auditoría: el trabajo de permisos actual en commits separados por tema y sin las 7 imágenes de `backend/storage/app/public/products/` | Para no mezclar este cambio con lo que hoy está sin commit (regla de AGENTS.md). |
| Respaldo de la base de la Mac y `php artisan migrate` | La migración `2026_10_15_000001` aún no corrió en la Mac (sigue con las 25 filas de julio); sin ella el cajero no puede vender ni abrir caja (auditoría A2). |
| `php artisan test` en verde en la Mac | Punto de partida verificado (auditoría B6). |

---

## 4. Fases

### Fase 1 — Backend: niveles válidos y lectura de cajas ajenas

1. **`PermissionService`**
   - Constante `ALLOWED_LEVELS` con los niveles válidos de la tabla 2.1.
   - `levelsFor(string $role, string $module): array`: niveles válidos que no superan el techo.
   - En `level()`, si la fila guardada tiene un nivel no válido para el módulo, usar el mayor nivel válido que no lo supere.
     Así, `inventory = read` cuenta como `none`, que es lo que hoy hace la API: las rutas de inventario exigen `edit`.
2. **`RolePermissionRequest`**
   - Comprobar que `permissions` sea un arreglo antes de recorrerlo (hoy responde 500).
   - Validar que cada nivel esté en `levelsFor($role, $module)`; reemplaza la comparación con el techo.
3. **`RolePermissionController@index`:** agregar `levels` (rol → módulo → niveles). Mantener `ceilings`, `defaults` y
   `restrictions`.
4. **Migración nueva** `2026_10_16_000001_normalize_role_permission_levels.php`, idempotente y con `DB::table()`:
   - `read → none` en `inventory`, `voids` y `crm_config`;
   - `edit → read` en `crm_read` y `reports`;
   - borrar las filas `delivery_config`.
   No cambia lo que cada rol puede hacer hoy.
5. **`CashRegisterSessionController`** (auditoría B1): `ensureCanAccess()` recibe el nivel.
   - `show`, `report` y `movements` piden `read`;
   - `close` y `recordMovement` piden `edit`.
   Sin esto, "Cajas de otros: Ver" no permite abrir un turno ajeno.

**Tests nuevos** (`PermissionServiceTest`, `CashRegisterSessionTest`):
- `PUT /permissions` con `inventory = read` → 422;
- `"permissions": "x"` → 422;
- una fila guardada con `inventory = read` da `none` en `level()`;
- `GET /permissions` trae `levels`;
- admin con `register_others = read` abre el arqueo ajeno pero recibe 403 al cerrarlo.

**Aceptación:** `php artisan test` en verde; las sondas B1 y B5 de la auditoría responden lo esperado.

### Fase 2 — Frontend: reglas de pantallas en un solo lugar

La vista previa tiene que mostrar exactamente lo que el sistema hará, así que sus reglas no pueden ser una copia.

1. **`frontend/src/utils/roleScreens.js`:** función pura `screensFor(role, levelOf)` que devuelve las pantallas visibles
   (con "solo ver") y la de inicio. `levelOf` es una función módulo → nivel.
2. **`AppShell.vue`:** `navItems` calcula `show` con `screensFor(auth.user.role, …)` usando los permisos efectivos.
3. **`router/index.js`:** `landingFor()` usa la misma función.
4. **`frontend/src/utils/permissionModules.js`:** catálogo de la tabla 2.1, verbos y la función de la frase resumen.

**Aceptación:** `npx vite build` sin errores; menú lateral y pantalla de inicio idénticos para los 4 roles (capturas antes
y después).

### Fase 3 — Frontend: la pantalla

1. **`frontend/src/components/permissions/RolePermissionsPanel.vue`**
   - Carga `GET /permissions`.
   - Mantiene `saved` y `draft` y calcula los cambios.
   - Guarda solo las filas cambiadas.
   - Muestra los estados: cargando, error con reintento, sin cambios, con cambios y guardado.
   - Usa `defaults` para "Volver a lo recomendado".
2. **`PermissionRow.vue`**
   - Nombre, descripción o "Cambiado. Antes: …" y el control segmentado.
   - Botones con `aria-pressed` y `aria-label` "Módulo: nivel".
   - Foco visible: no quitar el `outline`.
3. **`RolePreview.vue`:** la vista previa a partir de `screensFor()`.
4. **`AdminUsers.vue`**
   - Encabezado compacto común a las dos pestañas.
   - La pestaña de permisos monta el panel.
   - Se elimina la tabla vieja, `restrictions`/`ceilings` fijos, el anillo de progreso, la leyenda y el párrafo inicial.
   - Los conteos de personas por rol salen de la lista de usuarios que la vista ya carga.
5. **Estilos:**
   - CSS `scoped` con variables de `style.css`. En modo oscuro, `html.dark .clase`, nunca `:global(html.dark)` (trampa de
     AGENTS.md).
   - Cortes de ancho:
     - **contenido < 1100 px:** la vista previa baja debajo de la lista;
     - **≤ 760 px:** el control pasa debajo del texto y las acciones del encabezado se envuelven.
   - Todo con `flex-wrap` y `min-width: 0`; ningún ancho fijo mayor que la pantalla.
6. **Sin dependencias npm nuevas:** producción nunca ejecuta `npm install`.

**Aceptación:** los criterios de la sección 1 y el comportamiento de la sección 2 en la app real.

### Fase 4 — Verificación

| Qué | Cómo |
|---|---|
| Backend | `cd backend && php artisan test` |
| Build | `cd frontend && npx vite build` |
| Contraste | `node scripts/audit-contrast.cjs <carpeta> dark` y `… light` (objetivo: 0 textos bajo WCAG AA) |
| Ajuste a la portátil | Nuevo `frontend/e2e/permisos-portatil.spec.js` con proyecto "Portátil de caja" en `playwright.config.js` |
| Flujo completo | Login → abrir caja → vender → cocina → anular → cerrar caja, contra una **copia** de la base (AGENTS.md) |

El spec nuevo **simula la API con `page.route`**, así que no escribe en ninguna base; el spec móvil actual sí entra con un
usuario real. Para cada viewport (1366 × 611, 641, 697, 768) y cada rol, comprueba:

- `document.documentElement.scrollWidth <= innerWidth` y lo mismo dentro de `.oh-shell-content`;
- con el Administrador, `.oh-shell-content` sin scroll vertical, también con 3 cambios y el aviso visible;
- en 1024 × 768 y 390 × 844: sin scroll horizontal;
- el comportamiento:
  - el contador sube y baja;
  - "Descartar" restaura;
  - con `GET /permissions` en 500, Guardar queda deshabilitado y aparece "Reintentar";
  - el payload del `PUT` lleva solo las filas cambiadas;
  - la vista previa coincide con `screensFor()`.

### Fase 5 — Documentación y commits

- `CHANGELOG.md` (Sin publicar) y `docs/BITACORA.md` en el mismo commit de cada cambio funcional.
- `graphify update .` al terminar.
- Commits en español, uno por tema:
  1. `feat(permisos): niveles válidos por módulo y normalización de la matriz`
  2. `fix(caja): ver turnos ajenos con permiso de lectura`
  3. `refactor(menu): reglas de pantallas por rol en un solo lugar`
  4. `feat(usuarios): nueva pantalla de roles y permisos para la portátil de caja`
  5. `test(e2e): ajuste de Roles y permisos a 1366 × 768`
- Revisar `git status` antes de cada `git add`: nada de `backend/storage/app/public/`.

---

## 5. Archivos

| Archivo | Cambio |
|---|---|
| `backend/app/Services/PermissionService.php` | `ALLOWED_LEVELS`, `levelsFor()`, normalización en `level()` |
| `backend/app/Http/Requests/RolePermissionRequest.php` | Arreglo obligatorio y niveles válidos por módulo |
| `backend/app/Http/Controllers/RolePermissionController.php` | `levels` en `index()` |
| `backend/app/Http/Controllers/CashRegisterSessionController.php` | `ensureCanAccess()` con nivel |
| `backend/database/migrations/2026_10_16_000001_normalize_role_permission_levels.php` | Nuevo |
| `backend/tests/Feature/PermissionServiceTest.php`, `CashRegisterSessionTest.php` | Tests de la fase 1 |
| `frontend/src/utils/roleScreens.js` | Nuevo: reglas de pantallas por rol |
| `frontend/src/utils/permissionModules.js` | Nuevo: catálogo, etiquetas y frase |
| `frontend/src/components/AppShell.vue` | `navItems` con `screensFor()` |
| `frontend/src/router/index.js` | `landingFor()` con `screensFor()` |
| `frontend/src/components/permissions/RolePermissionsPanel.vue` | Nuevo |
| `frontend/src/components/permissions/PermissionRow.vue` | Nuevo |
| `frontend/src/components/permissions/RolePreview.vue` | Nuevo |
| `frontend/src/views/AdminUsers.vue` | Encabezado compacto, panel nuevo, se retira la tabla vieja (≈ −250 líneas) |
| `frontend/e2e/permisos-portatil.spec.js`, `frontend/playwright.config.js` | Spec y proyecto "Portátil de caja" |
| `CHANGELOG.md`, `docs/BITACORA.md` | Registro |

---

## 6. Riesgos

| Riesgo | Mitigación |
|---|---|
| Windows en escala 125% o zoom del navegador > 100%: el viewport baja a ~1093 × 490 y deja de caber | Documentarlo en la bitácora como configuración de la portátil de caja; el diseño sigue sin scroll horizontal y solo agrega vertical |
| Filas guardadas con niveles que ya no se ofrecen | Migración de normalización y normalización en `level()` |
| Refactor del menú lateral cambia lo que ve alguien | Capturas antes y después para los 4 roles; `screensFor()` cubierto por el spec |
| La frase o la vista previa no coinciden con la realidad | Las dos salen de `permissionModules.js` y `roleScreens.js`, las mismas que usa la app |
| Un guardado parcial deja la matriz a medias | El controlador ya guarda dentro de `DB::transaction`; se envían solo filas cambiadas |
| Pruebas que escriben en la base real | El spec nuevo simula la API; el flujo completo se prueba contra una copia |

---

## 7. Lista de aceptación

- [ ] Sin scroll horizontal en 1366 × 611, 641, 697 y 768, en 1024 × 768 y en 390 × 844.
- [ ] Administrador completo, con cambios y aviso, sin scroll vertical en 1366 × 611.
- [ ] Ninguna celda ofrece un nivel que no cambie nada; la API rechaza esos niveles con 422.
- [ ] "Cajas de otros: Ver" permite abrir turno, arqueo y movimientos ajenos, y no cerrarlos.
- [ ] Sin cargar la matriz no se puede guardar; "Reintentar" funciona.
- [ ] El `PUT` lleva solo las filas cambiadas.
- [ ] La vista previa coincide con el menú real de cada rol.
- [ ] Contraste AA en claro y oscuro; foco visible con teclado.
- [ ] `php artisan test` y `npx vite build` en verde; spec "Portátil de caja" en verde.
- [ ] CHANGELOG, bitácora y grafo actualizados; commits por tema.
