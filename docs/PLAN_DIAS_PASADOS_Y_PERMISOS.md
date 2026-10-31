# Plan: ventas de días pasados, cajas cerradas y permisos por persona

> **Pedido del dueño (2026-10-31):** cargar los registros de antes de que existiera el sistema; poder cargar a mano las
> ventas de un día en que el sistema falle; corregir cajas ya cerradas; y dar esos privilegios, y también Finanzas, a la
> persona que él elija, sea haciéndola super usuario o dándole solo ese módulo a un administrador.
> **Estado:** la Fase 0 está hecha en la rama `fix/caja-cerrada`, sin publicar. Las demás fases son plan.
> **Base:** código de `main` al 2026-10-31 (`bff4a71`). Las referencias a archivos son de ese commit.

---

## 0. Resumen

- Hay **tres casos distintos** de «ventas de otro día»: el histórico de antes del sistema, el día en que el sistema falló
  y el ticket olvidado o con error. No deben tratarse igual. Lo que cambia entre ellos es si se descuenta stock, si se
  suman puntos y a qué caja van las ventas (sección 1).
- Hoy todos se cargan por **Control diario** con las mismas reglas: siempre se descuenta el stock *de hoy* y siempre
  se suman puntos. Además, el admin no puede elegir a qué cajero cargar la venta, y una caja cerrada no se puede corregir.
- Los permisos son **solo por rol**. Finanzas, Usuarios, Auditoría y Respaldos están fijos para el super admin. Hacer
  super admin a otra persona ya es posible, pero le da todo, incluido restaurar respaldos, y nada impide quitarle el rol
  al último super admin.
- Propuesta:
  1. **Permisos por persona:** una excepción por usuario encima de la matriz del rol.
  2. **Tres módulos nuevos** que se asignan a quien el dueño elija: *Ventas de días pasados*, *Corregir cajas cerradas*
     y *Finanzas*.
  3. **Reglas automáticas por fecha** para stock y puntos, en vez de un interruptor manual.
  4. **«Reabrir caja para corregir»,** con historial de cierres.

---

## 1. Los tres casos

| | **A. Histórico (antes del sistema)** | **B. Día en que falló el sistema** | **C. Ticket olvidado o con error** |
|---|---|---|---|
| Ejemplo | Comandas de agosto, antes del primer día con el POS | Se cortó la luz o se rompió la portátil y se vendió en papel | Una venta que no se cargó, o se cargó mal, ayer |
| ¿Descuenta stock? | **No:** el stock de hoy ya no tiene esa mercadería | **Sí, salvo** que después se haya hecho un conteo físico de ese insumo | Igual que B |
| ¿Suma puntos al cliente? | No, por defecto (D4) | Sí | Sí |
| ¿Caja? | No hace falta. Quedan «sin caja» y cuentan en Finanzas por su fecha | La de ese día: la que estaba abierta, o una abierta después **a nombre del cajero real** | La caja donde cayó. Si está cerrada, aparece el aviso de la Fase 0 y se corrige con la Fase 4 |
| ¿Quién la carga? | Quien tenga *Ventas de días pasados* | Igual | Igual |

**Regla de stock propuesta (sirve para los tres casos):** cada insumo tiene una *línea base*: la fecha de su último
conteo físico o de su carga inicial (movimiento `initial`). Una venta con fecha **anterior** a la línea base no descuenta
ese insumo, porque el conteo ya lo reflejó. En una venta posterior, sí. Así el histórico queda sin descuento solo, sin que
nadie tenga que acordarse de marcar nada.

---

## 2. Diagnóstico (verificado en el código)

| # | Hallazgo | Dónde | Efecto |
|---|---|---|---|
| H1 | Control diario exige **rol admin** + `perm:pos,edit`. Abrir una caja con fecha pasada y forzar un cierre usan `isAdmin()`. | `routes/api.php` (grupo `admin, perm:pos,edit`), `AdminSaleRequest::authorize`, `CashRegisterSessionController` L88 y L234 | No se puede dar a una persona sin hacerla admin, ni quitárselo a un admin concreto. |
| H2 | El selector de cajero del editor carga `GET /users`, que es solo del super admin. | `AdminSaleEditor.vue` `loadUsers()` | Un admin no puede cargar la venta a nombre del cajero real: queda a su propio nombre. |
| H3 | Toda venta manual descuenta el stock actual, sin mirar la fecha. | `SaleSyncService::insertSaleItems` → `deductFromRecipe` | Cargar el histórico descuadra el inventario de hoy, aunque haya conteos posteriores. |
| H4 | Toda venta manual con cliente suma puntos. | `adminCreateSale` → `updateLoyaltyPoints` | Puntos regalados por compras de hace meses. |
| H5 | Una caja de fecha pasada queda a nombre de quien la abre. | `CashRegisterSessionController::store` (`cashier_id = $userId`) | El arqueo del día con falla figura del admin, no del cajero. |
| H6 | Una caja cerrada no acepta egresos ni un nuevo conteo. Al editar sus ventas, el cierre guardado no cuadra. | `recordMovement` (422), sin endpoint de corrección | No se puede corregir un error de tipeo ni un egreso olvidado. |
| H7 | Una caja de fecha pasada cerrada días después absorbía las ventas de todo ese período. | `CashSessionResolver` | **Corregido en la Fase 0.** En el VPS: cajas #9 y #10. |
| H8 | Finanzas cuenta los egresos de caja por `created_at`. | `FinanceReportService` | Un egreso de septiembre cargado hoy caería en octubre. |
| H9 | Los permisos son solo por rol (`role_permissions`). Finanzas, Usuarios, Auditoría y Respaldos están fijos para el super admin. | `PermissionService`, `routes/api.php` (grupo `super_admin`), `router/index.js` (`superAdminOnly`), `roleScreens.js` | No se puede dar Finanzas a un admin sin hacerlo super admin. |
| H10 | `UserRequest` acepta `super_admin`: hoy ya se puede nombrar super admin a otra persona desde Usuarios. Pero nada impide degradar o borrar al último super admin, o a uno mismo, no se pide confirmar la contraseña y el cambio de rol no queda como acción propia en la auditoría. | `UserRequest`, `UserController::update/destroy` | Riesgo de quedarse sin dueño en el sistema o de dar todo por error. |

---

## 3. Diseño

### 3.1 Permisos por persona

```
nivel(usuario, módulo) =
    si es super_admin → edit
    si no             → mín( techo[rol][módulo],
                             excepción[usuario][módulo] ?? matriz[rol][módulo] ?? por_defecto[rol][módulo] )
```

- **Tabla nueva** `user_permissions`: `user_id`, `module`, `access_level`, `granted_by`, timestamps; única por
  (`user_id`, `module`).
- La excepción **reemplaza** el valor del rol para esa persona, pero **nunca supera el techo del rol**. Sirve para dar
  más (Finanzas a Lourdes) o menos (Rox sin Inventario).
- **Techos:** Cocina y Cajero siguen sin acceso a los módulos sensibles (techo `none`). Solo el rol Administrador puede
  recibirlos. Para darle Finanzas a un cajero, primero hay que hacerlo administrador.
- **Fijo para el super admin, fuera de la matriz:** gestionar usuarios y permisos, auditoría, y descargar o restaurar
  respaldos.
- `/login` y `/me` ya envían `effective_permissions`. Como el cálculo incluye la excepción, el frontend no cambia su
  lógica: solo deja de usar `superAdminOnly` y `isSuperAdmin` donde ahora hay módulo.

### 3.2 Módulos nuevos

| Módulo | Nombre en pantalla | Niveles | Techo Admin | Por defecto Admin | Qué habilita |
|---|---|---|---|---|---|
| `sales_history` | Ventas de días pasados | Sin acceso · Cargar y corregir | edit | **edit** (D1) | Control diario: cargar, editar y anular ventas de otros días, abrir una caja con fecha pasada a nombre de un cajero, forzar un cierre con ventas sin sincronizar |
| `register_corrections` | Corregir cajas cerradas | Sin acceso · Corregir | edit | **none** | Reabrir una caja cerrada con motivo, registrar egresos de ese día y volver a cerrarla (Fase 4) |
| `finance` | Finanzas | Sin acceso · Ver · Editar | edit | **none** | Ver: resultados, punto de equilibrio, costos, indicadores y liquidaciones. Editar: gastos, categorías, gastos fijos, liquidaciones de PedidosYa y costos de recetas |

Con el valor por defecto `sales_history = edit` para Administrador, nadie pierde lo que hoy puede hacer. Luego el dueño
puede quitárselo a una persona concreta.

### 3.3 Ventas de días pasados

- **Stock:** la regla de la línea base de la sección 1, aplicada por insumo dentro de `InventoryService::deductFromRecipe`
  (venta, opciones y composición).
  - Al editar o anular una venta se revierte solo lo que de verdad se descontó, porque la reversión ya lee los
    movimientos de esa venta.
  - El editor lo dice antes de guardar: «No descuenta stock: es anterior al conteo del 12/10» o «Descuenta stock».
- **Fecha de inicio del sistema:** por defecto, la fecha de la primera venta del POS; el super admin puede ajustarla.
  Antes de esa fecha la venta se marca **histórica** (`entry_mode = historical`): no suma puntos (D4) y no necesita caja.
- **Cajero:** endpoint `GET /users/sellers` (id, nombre, rol; solo usuarios activos) para quien tenga `sales_history`.
  Reemplaza a `GET /users` en el editor.
- **Caja del día con falla:** al abrir una caja con fecha pasada se elige **a nombre de quién**. El fondo inicial sugerido
  es el «fondo para mañana» de la caja anterior.
- **N.º de comanda en papel:** campo opcional en la venta (`paper_ref`). Avisa, sin bloquear, si ese número ya existe
  en el mismo día. Es la defensa contra cargar dos veces la misma comanda.
- **Egresos de un día pasado:** campo `occurred_at`, dentro del día de la caja. Finanzas y el reporte de caja usan esa
  fecha (H8).
- **Duplicados con ventas sin conexión:** antes de cargar un día con falla, Control diario muestra si algún dispositivo
  tiene ventas pendientes de sincronizar de esa fecha.

### 3.4 Corregir cajas cerradas: «Reabrir para corregir»

Un solo mecanismo, en lugar de un ajuste de cierre nuevo, egresos en cajas cerradas y un recálculo automático:

1. **Reabrir** (`POST /cash-sessions/{id}/reopen`, motivo obligatorio de 10 caracteres o más, `register_corrections`):
   - guarda el cierre actual en una tabla nueva, `cash_session_closings`: teórico, contado, diferencia, billetes, retiro,
     fondo, nota, egresos, quién cerró y cuándo;
   - deja la caja abierta.
2. **Mientras está abierta** se usan las pantallas de siempre: egresos (con la fecha de ese día), tickets y conteo.
   - El POS no la ve: `active()` solo devuelve cajas de hoy.
   - Las ventas nuevas no entran, por la regla de la Fase 0.
3. **Volver a cerrar** con la misma pantalla de cierre, que ya valida billetes y retiro + fondo:
   - pide el motivo de la corrección;
   - el cierre conserva la fecha original del día, no la del momento de la corrección.
4. **Reporte:** sello «Caja rectificada N veces», con la lista de cierres anteriores (fecha, quién, motivo, diferencia).
5. **Cajas encadenadas:** si cambió el «fondo para mañana», se avisa con qué fondo abrió la caja siguiente. No se toca sola.
6. **Auditoría:** `cash_session_reopened` y `cash_session_reclosed`, con los valores antes y después.

El arqueo **nunca se recalcula solo**. La diferencia guardada es el faltante o sobrante a nombre del cajero. Solo cambia
cuando alguien con permiso reabre la caja y vuelve a cerrarla con un motivo.

### 3.5 Super admin

- Nombrar a otra persona super admin sigue siendo posible, solo para un super admin.
- Al hacerlo, la pantalla explica que tendrá **todo**, incluido restaurar respaldos, y propone dar solo los módulos.
- Pide **confirmar la contraseña** de quien hace el cambio.
- No se puede degradar, desactivar ni borrar **al último super admin activo**, ni a uno mismo.
- El cambio queda en la auditoría como `role_changed` y la sesión de la persona afectada se cierra para que entre con el
  rol nuevo.

---

## 4. Fases

### Fase 0 — Hecha: cajas de fecha pasada y aviso de cierre recalculado (`fix/caja-cerrada`)
- `CashSessionResolver`: una venta solo entra a una caja abierta el mismo día (H7).
- El reporte y Control diario muestran el cierre guardado y, aparte, el teórico y la diferencia de hoy, con los cambios
  auditados.
- **Falta publicarla.**

### Fase 1 — Permisos por persona y módulos nuevos *(mediana)*
**Backend**
- Migración `user_permissions`.
- Migración que agrega los tres módulos a `role_permissions`, con los valores por defecto de 3.2, usando `DB::table()`.
- `PermissionService`:
  - `MODULES`, `ALLOWED_LEVELS`, `CEILINGS` y `DEFAULTS` con los módulos nuevos;
  - `level()` con la excepción por persona;
  - `effectiveFor()` sin cambio de contrato.
- `UserPermissionController`:
  - `GET /users/{id}/permissions`: nivel del rol, excepción y nivel efectivo por módulo;
  - `PUT /users/{id}/permissions`: solo super admin, en una transacción, validando niveles y techo;
  - todo queda auditado.
- `GET /users/sellers` (3.3).
- Protecciones de super admin (3.5) en `UserRequest` y `UserController`.

**Frontend**
- Usuarios → editar persona → sección **«Permisos de esta persona»**:
  - una fila por módulo con «Como su rol (…)» / Sin acceso / Ver / Editar;
  - las excepciones resaltadas;
  - la lista de usuarios muestra insignias como «+ Finanzas».
- Diálogo de super admin con contraseña (3.5).

**Pruebas**
- La excepción da y quita, y nunca supera el techo; un cajero no puede recibir Finanzas.
- Al último super admin no se lo puede degradar.
- `effective_permissions` refleja las excepciones.
- El selector de vendedores responde a quien tiene `sales_history` y da 403 sin él.

### Fase 2 — Finanzas por permiso *(mediana)*
- Rutas `finance/*`:
  - los `GET` pasan a `perm:finance,read` y las escrituras a `perm:finance,edit`;
  - salen del grupo `super_admin`, y en su lugar queda `admin` como segunda llave.
- `router/index.js`: `/finanzas` con `requiredPermission: { module: 'finance', level: 'read' }`.
- `roleScreens.js`: Finanzas visible según el permiso.
- `Finance.vue` y sus pestañas: en modo «Ver», sin botones de crear, editar ni eliminar, con el aviso «Solo lectura».
- **Pruebas:**
  - PHPUnit recorre cada ruta de `finance/*`: admin sin permiso → 403, con «Ver» → 200 en GET y 403 al escribir, con
    «Editar» → 200;
  - E2E de Finanzas en modo lectura a 1366 × 611.

### Fase 3 — Cargar días pasados: histórico y contingencia *(grande)*
**Backend**
- Rutas de Control diario y `AdminSaleRequest` con `perm:sales_history,edit`, en lugar de `isAdmin()` (H1).
- Regla de stock por línea base en `InventoryService` (3.3), con una prueba por caso: histórico, día con falla y después
  de un conteo.
- Ventas: `entry_mode` (`normal` o `historical`, calculado por fecha) y `paper_ref`. Sin puntos si es histórica (D4).
- Caja con fecha pasada: `cashier_id` elegible (H5).
- Movimientos con `occurred_at`. Finanzas y el reporte de caja usan esa fecha (H8).

**Frontend**
- Editor de Control diario:
  - selector de vendedor con `/users/sellers`;
  - aviso de stock y puntos según la fecha;
  - campo «N.º de comanda» con aviso de repetido;
  - se conservan fecha, cajero y método de pago entre comandas para cargar rápido.
- Al elegir un día sin caja:
  - «Abrir caja de ese día a nombre de…» con el fondo sugerido;
  - en un día histórico, la opción de no abrir caja.
- Aviso de ventas sin sincronizar del dispositivo en esa fecha.
- Textos sin «Super Admin», porque ahora puede ser cualquier persona con el permiso.

**Pruebas**
- PHPUnit de la regla de stock y de puntos.
- Caja a nombre del cajero.
- Egreso con fecha pasada en el mes correcto de Finanzas.
- E2E del flujo «día con falla» completo: abrir caja, 3 comandas, egreso y cierre.

### Fase 4 — Corregir cajas cerradas *(mediana)*
- Tabla `cash_session_closings`.
- `reopen` y re-cierre con motivo; el cierre conserva la fecha del día (3.4).
- Sello e historial en `CashSessionReport.vue`.
- En Control diario, botón «Reabrir para corregir» visible con `register_corrections`.
- Aviso de caja encadenada.
- **Pruebas:**
  - reabrir guarda el cierre previo y re-cerrar crea el nuevo;
  - sin permiso → 403;
  - el POS no ve la caja reabierta;
  - las ventas nuevas no entran en ella;
  - la diferencia cambia solo al re-cerrar.

### Fase 5 — Opcional: importar el histórico desde una planilla *(mediana)*
Solo si los registros previos están en Excel (D3):
- importación CSV: fecha, producto, cantidad, precio y método de pago;
- vista previa y equivalencias de nombres (como las de PedidosYa);
- todo entra como histórico.

Si están en papel, alcanza con la Fase 3.

---

## 5. Procedimiento para el día en que falle el sistema

Esto vale desde ya, aunque las fases no estén publicadas:

1. **Si solo falla internet:** se sigue vendiendo en el POS. Guarda las ventas sin conexión y las envía al volver.
   No hace falta papel.
2. **Si el POS no se puede usar:**
   - comandas en papel **numeradas**, con método de pago y hora aproximada;
   - los egresos anotados aparte;
   - al cierre, el conteo de billetes en papel.
3. **Al volver el sistema:** revisar en cada dispositivo que no queden ventas pendientes de sincronizar de ese día.
   Si las hay, sincronizarlas primero.
4. **En Control diario,** con la fecha de ese día:
   - si no hay caja, abrirla (con la Fase 3, a nombre del cajero);
   - cargar las comandas con su número;
   - registrar los egresos;
   - cerrar con el conteo del papel.

---

## 6. Decisiones del dueño

| # | Pregunta | Recomendación |
|---|---|---|
| D1 | ¿Los administradores actuales (Rox, Lourdes) mantienen *Ventas de días pasados*, que hoy tienen por ser admin? | Sí, por defecto. Se puede quitar a una persona concreta. |
| D2 | ¿Finanzas con dos niveles (Ver / Editar) o uno solo? | Dos: ver resultados no es lo mismo que registrar gastos. |
| D3 | Los registros previos al sistema, ¿están en papel o en una planilla? ¿Cuántos días o comandas son? | Define si hace falta la Fase 5. |
| D4 | Las ventas históricas, ¿suman puntos a los clientes? | No. |
| D5 | ¿Desde qué fecha se usa el inventario en producción? | Confirmarlo con la consulta de abajo antes de la Fase 3. |
| D6 | Al nombrar super admin, ¿pedir la contraseña? | Sí. |

Consulta de solo lectura para D5 (fecha de la primera venta del POS, carga inicial de stock y conteos):

```bash
ssh root@64.176.3.152 'bash -s' <<'EOF'
sqlite3 -header -column /var/www/ohana-pos/datos/database.sqlite "
select 'primera venta POS' as que, min(date(created_at)) as fecha from sales where source = 'pos'
union all select 'carga inicial de stock', min(date(created_at)) from inventory_movements where type = 'initial'
union all select 'último conteo', max(date(created_at)) from inventory_counts;"
EOF
```

---

## 7. Riesgos

| Riesgo | Mitigación |
|---|---|
| Dar Finanzas o correcciones a quien no corresponde | Solo el super admin asigna; cada cambio queda en la auditoría; la ficha de la persona muestra sus excepciones. |
| Quedarse sin super admin | No se puede degradar ni borrar al último super admin activo, ni a uno mismo. |
| Cargar dos veces la misma comanda (papel + venta sin conexión) | Aviso de pendientes por sincronizar y aviso de N.º de comanda repetido. |
| Borrar un faltante del cajero «corrigiendo» la caja | El arqueo no se recalcula solo; reabrir pide motivo y deja historial visible en el ticket. |
| Inventario descuadrado por el histórico | Regla de línea base por insumo, automática. |
| Que un admin pierda acceso al publicar | Los valores por defecto mantienen lo de hoy (D1); la migración no toca las excepciones. |

---

## 8. Archivos

| Archivo | Fase | Cambio |
|---|---|---|
| `backend/app/Services/CashSessionResolver.php` | 0 | Hecho |
| `backend/app/Http/Controllers/CashRegisterSessionController.php` | 0, 3, 4 | Hecho: `recalculated`. Falta: caja a nombre de un cajero, `occurred_at`, `reopen` y re-cierre |
| `backend/app/Services/PermissionService.php` | 1 | Módulos nuevos y excepción por persona |
| `backend/database/migrations/…_create_user_permissions_table.php` | 1 | Nuevo |
| `backend/database/migrations/…_add_history_finance_modules.php` | 1 | Nuevo: valores por defecto de 3.2 |
| `backend/app/Http/Controllers/UserPermissionController.php` | 1 | Nuevo |
| `backend/app/Http/Requests/UserRequest.php`, `UserController.php` | 1 | Protecciones de super admin; `GET /users/sellers` |
| `backend/routes/api.php` | 1–4 | `finance/*` por permiso; Control diario con `sales_history`; `reopen` |
| `backend/app/Http/Requests/AdminSaleRequest.php` | 3 | `sales_history` en lugar de `isAdmin()` |
| `backend/app/Services/InventoryService.php` | 3 | Regla de línea base |
| `backend/app/Services/SaleSyncService.php` | 3 | `entry_mode`, `paper_ref`, puntos según el modo |
| `backend/app/Services/FinanceReportService.php` | 3 | Egresos por `occurred_at` |
| `backend/database/migrations/…` | 3, 4 | `sales.entry_mode`, `sales.paper_ref`, `cash_movements.occurred_at`, `cash_session_closings` |
| `frontend/src/views/AdminUsers.vue` + componentes | 1 | Permisos de esta persona; diálogo de super admin |
| `frontend/src/router/index.js`, `utils/roleScreens.js`, `components/AppShell.vue` | 2, 3 | Finanzas y Control diario por permiso |
| `frontend/src/views/Finance.vue` y `components/Finance/*` | 2 | Modo solo lectura |
| `frontend/src/components/AdminSaleEditor.vue`, `views/RetroactiveSales.vue` | 3, 4 | Vendedor, avisos, N.º de comanda, caja a nombre de, reabrir |
| `frontend/src/components/CashSessionReport.vue` | 0, 4 | Hecho: cierre recalculado. Falta: historial de cierres |

---

## 9. Despliegue

- Cada fase se publica por separado con el paquete y `deploy/vps/04-actualizar.sh`, que corre `migrate`.
- Antes de cada fase:
  - respaldo de la base;
  - `php artisan test` en verde;
  - E2E a 1366 × 611 y 1093 × 489.
- Después de la Fase 1, el super admin asigna los permisos desde Usuarios. Nadie gana ni pierde nada al publicar,
  salvo lo que diga D1.
