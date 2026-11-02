# Plan: descuentos manuales en caja

> Versión 3, 2026-11-02. Reemplaza la propuesta inicial del enlace expandible en el cobro. Incorpora las decisiones del
> dueño y los huecos que aparecieron al revisarla contra el código (`main`, `d6d0cec`). Cambios de la v3: el
> administrador puede descontar hasta el 100 % (cortesía), y el descuento también se aplica en Control diario con las
> mismas reglas, adaptado a la portátil de caja.

## Resumen

- Quien tenga el permiso *Descuentos en caja* puede descontar un monto en Bs al cobrar. Escribir el motivo es obligatorio.
- Un cajero puede descontar hasta el 20 % del subtotal. Un administrador puede llegar al 100 %, es decir, una cortesía
  que no se cobra.
- El servidor nunca rechaza una venta ya cobrada. La guarda y deja en auditoría cualquier descuento fuera de regla.
- El descuento aparece en el cierre de turno, el reporte de caja, Control diario y el detalle de la venta.

---

## 1. Decisiones

| # | Pregunta | Decisión del dueño |
|---|---|---|
| D1 | ¿Quién puede descontar? | Solo quien tenga el permiso. Se da **por persona** en Usuarios. El Super Admin lo tiene siempre. |
| D2 | ¿Se permite la cortesía del 100 %? | **Solo a administradores** (y al Super Admin). Un cajero no, porque su tope es el 20 %. *Primero se decidió «no»; el 02/11 se cambió a 100 % para el administrador.* |
| D3 | ¿Hay tope? | **Cajeros: 20 % del subtotal. Administradores: 100 %.** |
| D4 | ¿Cómo se escribe el motivo? | **Texto libre**, obligatorio. |
| D5 | ¿Quién tiene el permiso por defecto? | Nadie, tampoco los administradores. Solo el Super Admin. |
| D6 | ¿Cuántos caracteres lleva el motivo? | De 10 a 255, igual que el de reabrir una caja. |
| D7 | ¿En qué ventas se puede descontar? | Solo en las del local. En PedidosYa el enlace no aparece. Los descuentos de esa plataforma ya se registran en las liquidaciones. |

Los topes por rol están en una sola constante del servidor (`DiscountPolicy`).

---

## 2. Diagnóstico (verificado en el código)

| Capa | Estado | Detalle |
|---|---|---|
| `sales.discount_amount` | Existe | `decimal(12,2)`, valor por defecto 0. No hay columna para el motivo. |
| `SalePricingService::normalizeTotals` | Existe | Limita el descuento al subtotal. Total = subtotal − descuento. |
| `SyncSalesRequest` / `SaleSyncService` | Existe, sin control | Aceptan y guardan `discount_amount`. `authorize()` devuelve `true` y la ruta solo exige `perm:pos,edit`: no controla permiso, tope ni motivo. |
| Arqueo | **Parcial** | `CashRegisterSessionController` (L646) y `SaleController` (L95) calculan `total_discounts`, pero **ninguna pantalla lo muestra** (solo aparece como valor inicial en `turno.js`). El editor guarda hoy el recargo dentro del subtotal, pero las ventas viejas pueden tener un `discount_amount` negativo (el editor todavía lo lee así), y esa suma lo restaría. |
| Editor retroactivo | Parcial | Tiene descuento y recargo (negativo). El motivo es el `edit_reason` general de la edición. |
| `CheckoutModal.vue` | **Falta** | No emite descuento (`cart.js` lo deja en 0). Usa `props.total` en 14 lugares. |
| `ReceiptModal.vue` | Falta | Solo muestra el total y los pagos. |
| `SaleDetailDrawer.vue` | Parcial | Muestra el monto, no el motivo. |
| Permisos por persona | **Falta** | Hoy solo los puede recibir un administrador (`PersonPermissions.vue` → `canReceive`; `personRoleLevel` y `personBadges` en `permissionModules.js`). |

**Riesgos de la propuesta inicial:**

| # | Riesgo | Efecto |
|---|---|---|
| R1 | Un descuento del 100 % deja el total en 0. `handleConfirm` descarta los pagos de 0 y la venta se guarda sin pagos. | El servidor responde «La venta no tiene pagos» y la venta queda **fallida en el equipo**. Como D2 permite la cortesía, la sección 3.6 define cómo se registra. |
| R2 | El motivo y el tope solo se validaban en el navegador. | Cualquier cajero podía descontar cualquier monto con «aaaaa». |
| R3 | No había forma de verlo. | El dueño no se enteraría de los descuentos. `audit_logs` solo lo ve el Super Admin. |
| R4 | No se excluía PedidosYa. | Descuento en un pedido que cobra la plataforma. |
| R5 | La lista de cambios del modal no cubría todos los usos de `props.total` y no reiniciaba el descuento. | Vuelto o pagos calculados sobre el total sin descuento, o un descuento heredado de la venta anterior. |
| R6 | El editor retroactivo no conoce el motivo. | Si un admin quita el descuento, queda guardado un motivo sin descuento. |
| R7 | El grafo es del 23/10: todavía muestra `customerSearch` en `CheckoutModal` y no incluye `SalePricingService`. | Medir el impacto sobre un grafo viejo. |

---

## 3. Diseño

### 3.1 Reglas

- El descuento se escribe en Bs y tiene que ser mayor que 0.
- **Máximo:**
  - cajero: 20 % del subtotal, redondeado hacia abajo al centavo (subtotal 150 → máx. Bs 30,00; 47,50 → Bs 9,50);
  - administrador y Super Admin: 100 % del subtotal. Llegar al 100 % es una cortesía (3.6).
- **Motivo:** de 10 a 255 caracteres, sin contar los espacios de los extremos.
- Solo en ventas del local (`source = 'pos'`).
- El motivo **no** va a la comanda de cocina. Por eso se guarda en su propia columna y no en `notes`.

### 3.2 Permiso *Descuentos en caja* (`pos_discounts`)

- **`PermissionService`:**
  - agregar el módulo a `MODULES`, `PERSON_MODULES` y `ALLOWED_LEVELS` (`none`, `edit`);
  - `CEILINGS`: cocina `none`, cajero `edit`, administrador `edit`;
  - `DEFAULTS`: `none` para los tres roles (S2).
- **Es el primer permiso por persona que puede recibir un cajero.** Hay que generalizar la pantalla:
  - en `permissionModules.js`, cada módulo de `PERSON_PERMISSION_MODULES` declara qué roles pueden recibirlo y su valor por
    defecto en cada rol. Hay que adaptar `personRoleLevel` y `personBadges`;
  - `PersonPermissions.vue`: `canReceive` pasa a ser por módulo. El aviso «solo se dan a administradores» queda solo
    para Finanzas, Días pasados y Corregir cajas;
  - `AdminUsers.vue` (L429) y `RolePermissionsPanel.vue`: dejan de suponer que solo el rol `admin` recibe estos permisos.
- **`UserPermissionController::MODULE_NAMES`:** agregar «Descuentos en caja». Dar o quitar el permiso ya queda en la
  auditoría como `user_permissions_changed`.

### 3.3 La política, escrita en un solo lugar

- **Nuevo `App\Services\DiscountPolicy`:**
  - `MAX_PERCENT = ['cashier' => 20, 'admin' => 100]`. El Super Admin tiene 100;
  - `for(User $user): array{allowed: bool, max_percent: int}`;
  - `maxAmount(User $user, float $subtotal): float`;
  - `review(User $user, array $sale): string[]`: devuelve la lista de infracciones (sin permiso, supera el tope de su
    rol, sin motivo o motivo corto, pedido de PedidosYa).
- **`/login` y `/me`:** envían `discount_policy` junto a `effective_permissions`. El frontend lo guarda en el store de
  `auth` y **no repite el 20 %**.
- **Frontend, `utils/discount.js`** (funciones puras, como `splitPayments.js`):
  - `maxDiscount(subtotal, maxPercent)`;
  - `discountProblem({ amount, reason, subtotal, maxPercent })`: devuelve el mensaje para el cajero, o `null` si no hay
    problema.

### 3.4 Servidor (las ventas pueden llegar tiempo después de cobradas)

1. **Migración** `2026_11_02_000001_add_discount_reason_to_sales_table`: `discount_reason string(255) nullable`,
   después de `discount_amount`. Es aditiva.
2. **`Sale`:** agregar `discount_reason` a `$fillable`.
3. **`SyncSalesRequest`:** `'sales.*.discount_reason' => ['nullable', 'string', 'max:255']`. Sin `min` en el servidor:
   un motivo corto no rechaza la venta, se audita.
4. **`SaleSyncService::processSale`:**
   - guarda `discount_reason` sin espacios en los extremos, o `null` si no hay descuento;
   - si el descuento es mayor que 0, llama a `DiscountPolicy::review()`:
     - sin infracciones → `sale_discount_applied`: «Ticket #X: descuento Bs 20,00 (13,3 %) sobre Bs 150,00, por Ana.
       Motivo: …»;
     - con infracciones → `sale_discount_violation`, con la lista de infracciones.
   - **La venta se guarda igual.** Ya se cobró: rechazarla descuadraría la caja y dejaría la venta trabada en el
     equipo. Es el mismo criterio que ya se usa con `sale_sync_discrepancy`.
5. **El arqueo no cambia:** el efectivo esperado sale de los pagos, que ya llegan con el descuento aplicado.

### 3.5 Cobro (`CheckoutModal.vue`)

```
┌ Cobrar ───────────────────────────────────────────── ✕ ┐
│ Total a cobrar                                          │
│ Bs 130,00                                               │
│ 4 productos · Subtotal Bs 150,00 · Descuento −Bs 20,00   │
│ [etiqueta] Quitar descuento                              │
│ Descuento  [Bs 20,00]  máx. Bs 30,00 (20 %) · 13,3 %     │
│ Motivo     [Pedido de 10 bowls para evento del colegio ] │
│            [                                       ] 43/255│
│ Cómo paga  [Efectivo] [QR] [Tarjeta]   Dividir el pago   │
│ Recibe     [Bs 130]  Bs 200 · Justo                      │
│ Vuelto Bs 70,00                                          │
├──────────────────────────────────────────────────────────┤
│ [Cancelar]                          [Cobrar Bs 130,00]   │
└──────────────────────────────────────────────────────────┘
```

- **Props nuevos:** `discountPolicy`, que `Pos.vue` toma de `auth.discount_policy`.
- **Enlace «Aplicar descuento»:** usa la clase `co-link` y un ícono SVG de etiqueta (el modal no usa emojis). Va debajo
  del total y solo aparece si `allowed` y el canal no es PedidosYa.
- **Panel en dos filas:**
  - fila 1: monto, con el máximo y el porcentaje equivalente;
  - fila 2: el motivo, en un área de texto de 2 líneas con `maxlength="255"` y contador;
  - «Quitar descuento» lo cierra y lo deja en 0.
- **Total:** en grande va lo que hay que cobrar. Debajo, en chico: «Subtotal · Descuento».
- **Un solo `computed` con el total a cobrar** reemplaza los 14 usos de `props.total`: pagos iniciales, `remaining`,
  `cashDue`, `change`, `isReady`, `rebalance`, `removePayment`, `addPayment`, el exceso en `handleConfirm` y la
  etiqueta del botón.
- **`watch` sobre el total a cobrar:**
  - con un solo pago, su monto pasa a ser el total nuevo;
  - con el pago dividido, `fillRemainder(payments, total)`;
  - lo recibido, el vuelto y los billetes se recalculan con el `watch` que ya existe de `cashNeededAmount`.
- **Al abrir el modal** (`watch` de `show`): descuento cerrado, en 0 y sin motivo.
- **`isReady`** exige, además, que `discountProblem(...)` sea `null`.
- **Mensajes** bajo el campo, con `aria-live`:
  - «Máximo Bs 30,00 (20 % del subtotal)»;
  - «Escribe el motivo del descuento (mínimo 10 caracteres)».
  Si el cajero pulsa Enter en «Recibe» y hay un problema con el descuento, el foco pasa al campo con el problema en vez
  de no hacer nada.
- **`@confirm`** envía `discountAmount` y `discountReason` (0 y `null` si no hay descuento).
- **`cart.js`:**
  - guarda `discount_reason`;
  - redondea `total_amount` al centavo;
  - fuerza el descuento a 0 en el canal PedidosYa.
- **Portátil al 125 % (1093 × 489):** el pie es fijo, así que Cobrar siempre queda a la vista. El panel en dos filas
  busca que el vuelto no salga de la pantalla. Se mide con E2E.

### 3.6 Cortesía (descuento del 100 %)

Solo la puede hacer un administrador o el Super Admin con el permiso. Un cajero no llega, por su tope del 20 %.

- **En el modal:** cuando el total a cobrar queda en 0:
  - se ocultan «Cómo paga», «Recibe» y el vuelto;
  - aparece «Cortesía: esta venta no se cobra»;
  - el botón dice «Registrar cortesía».
  El motivo es obligatorio, como en cualquier descuento.
- **Pago:** se envía **una línea en efectivo de Bs 0**. `handleConfirm` la conserva cuando el total es 0, en vez de
  descartarla como hoy. Así se cumple la regla de al menos un pago (`payments min:1`) y el arqueo suma 0.
  - **No se agrega un método «cortesía».** `sale_payments.method` es un `enum`, y en SQLite cambiarlo obliga a
    reconstruir la tabla.
- **Servidor:** no necesita cambios para aceptarla. `normalizeTotals` ya permite un descuento igual al subtotal y
  `SyncSalesRequest` acepta pagos de 0. La auditoría la registra como `sale_discount_applied` con la marca «cortesía
  (100 %)».
- **Efectos:**
  - el inventario se descuenta, porque el producto salió;
  - no suma puntos, porque el total es 0;
  - en Finanzas cuenta el costo pero no hay ingreso, que es lo correcto.
- **Cómo se ve:** `utils/paymentDisplay.js` muestra la insignia «🎁 Cortesía» cuando el total es 0 y hay descuento,
  en lugar de «Efectivo». El cierre y el reporte la cuentan aparte: «Descuentos: Bs X · N ventas (M cortesías)».

### 3.7 Dónde se ve

| Pantalla | Cambio |
|---|---|
| `ReceiptModal.vue` | Subtotal, Descuento y Total, más «Motivo: …» |
| `SaleDetailDrawer.vue` | El motivo, debajo de «− Bs XX,XX» |
| `Turno.vue` (resumen) | «Descuentos: Bs X · N ventas», solo si hay alguno |
| `CashCloseWizard.vue` | La misma línea en la revisión del cierre. Es solo informativa: no cambia el efectivo esperado |
| `CashSessionReport.vue` | Tabla de descuentos: comanda, hora, cajero, subtotal, descuento, % y motivo |
| Control diario (`RetroactiveSales.vue`) | Marca de descuento en la fila y total del día |
| `AdminSaleEditor.vue` (Control diario) | El mismo bloque de descuento que el cobro (ver 3.8) |
| Listas de ventas (`paymentDisplay.js`) | Insignia «Cortesía» (ver 3.6) |

**Backend del resumen** (`CashRegisterSessionController` y `SaleController`):

- `total_discounts` pasa a sumar solo los descuentos positivos. Hoy no lo usa ninguna pantalla, así que el cambio no
  rompe nada;
- se agregan `discount_count` y `courtesy_count`;
- el reporte de caja agrega la lista `discounts`.

### 3.8 Control diario (editor de ventas)

El dueño pidió que el descuento también se pueda aplicar en Control diario. El editor (`AdminSaleEditor.vue`, que usan
Control diario y Turno) ya tenía un campo de descuento libre. Pasa a tener **las mismas reglas que el cobro**:

- **Mismo bloque:** monto en Bs, el máximo y el porcentaje equivalente, y el motivo obligatorio (10 a 255 caracteres).
- **Solo con el permiso *Descuentos en caja*.** El tope sale de `DiscountPolicy`. Control diario es de
  administradores, así que en la práctica llega al 100 %, con cortesía incluida. Sin el permiso:
  - el campo se ve deshabilitado, con el aviso «Necesitas el permiso Descuentos en caja»;
  - el «Total a cobrar» editable no puede quedar por debajo del subtotal: solo sirve para el recargo.
- **Editar otros datos de una venta que ya tiene descuento** no exige el permiso, siempre que no cambien ni el monto ni
  el motivo.
- **Servidor (`AdminSaleRequest` + `DiscountPolicy`):** aquí sí se rechaza con un error de validación, porque es un
  formulario en línea y no una venta ya cobrada:
  - descuento nuevo o cambiado sin el permiso;
  - por encima del tope de su rol;
  - motivo ausente o corto.
- **`adminCreateSale` y `adminUpdateSale`:** guardan el motivo. Si el descuento es 0, lo dejan en `null`.
  Auditoría: `sale_discount_applied` cuando el descuento es nuevo o cambió.
- **Portátil de caja (1093 × 489):** el bloque de totales se reorganiza para que monto y motivo quepan sin que el
  pie de Guardar salga de la pantalla. Se prueba con E2E a esa medida, igual que el cobro.

---

## 4. Fases

Cada fase es un commit en la rama. Cada commit actualiza el CHANGELOG («Sin publicar») y, si corresponde, la BITÁCORA.

### F0 · Preparación

- Crear la rama `feat/descuentos-manuales-pos` desde `main` (`d6d0cec`).
- Correr `graphify update .` antes de tocar código (R7) y revisar las notas de `CheckoutModal`, `SaleSyncService`,
  `PermissionService` y `PersonPermissions`.
- Copiar este plan a `docs/PLAN_DESCUENTOS_CAJA.md`.
- **No incluir en ningún commit:** las imágenes sin seguimiento de `backend/storage/app/public/products/`, ni
  `deploy/vps/*` y los documentos sin seguimiento que no son de esta rama. Hacer `git add` por ruta, nunca `-A`.
- Respaldar la base local antes de migrar (`backend/storage/app/backups/`).

### F1 · Servidor y permiso — `feat(ventas): descuento manual con permiso, tope del 20 % y motivo`

Secciones 3.2 (backend), 3.3, 3.4, 3.6 (servidor) y 3.8 (backend), y el resumen del arqueo de 3.7. Con sus pruebas.

### F2 · Usuarios — `feat(usuarios): permiso «Descuentos en caja» por persona, también para cajeros`

La generalización de `permissionModules.js`, `PersonPermissions.vue`, `AdminUsers.vue` y `RolePermissionsPanel.vue`.

### F3 · Cobro — `feat(pos): aplicar un descuento al cobrar`

`utils/discount.js` y sus pruebas, `CheckoutModal.vue` (incluida la cortesía de 3.6), `Pos.vue`, `cart.js` y
`ReceiptModal.vue`.

### F4 · Dónde se ve — `feat(caja): descuentos del turno en el cierre, el reporte y Control diario`

`Turno.vue`, `CashCloseWizard.vue`, `CashSessionReport.vue`, `RetroactiveSales.vue`, `SaleDetailDrawer.vue`,
`AdminSaleEditor.vue` y `paymentDisplay.js` (insignia «Cortesía»).

### F5 · Verificación y cierre

La sección 5 completa, `graphify update .`, la BITÁCORA, y **preguntar al dueño si publica en el VPS**.

---

## 5. Pruebas

**PHPUnit: nuevo `SaleDiscountTest.php`.**

- Un cajero con el permiso descuenta el 20 %: la venta se guarda con el motivo y queda `sale_discount_applied`.
- Un cajero con el permiso descuenta el 25 %: la venta se guarda y queda `sale_discount_violation`.
- Un cajero sin el permiso, o un descuento sin motivo o con motivo corto: se guarda, con infracción.
- Un administrador con el permiso descuenta el 50 %: se guarda sin infracción.
- **Cortesía de un administrador** (100 %, un pago en efectivo de Bs 0): se sincroniza sin error. El total queda en 0,
  descuenta inventario, no suma puntos y el efectivo esperado del arqueo no cambia.
- Un cajero que envía una cortesía: se guarda, con la infracción «supera el 20 %».
- Un pedido de PedidosYa con descuento: infracción.
- El arqueo: `total_discounts` solo suma los positivos, `discount_count`,
  `courtesy_count` y la lista del reporte de caja.
- `/login` y `/me` devuelven `discount_policy` según el rol y el permiso.

**PHPUnit: pruebas existentes que hay que actualizar.**

- `PermissionServiceTest`: el módulo nuevo, sus techos y sus valores por defecto.
- `UserPermissionsTest`: se le puede dar el permiso a un cajero y no a cocina.
- `AdminSaleManagementTest`: el motivo es obligatorio con descuento y se borra al quitar el descuento. Un admin sin
  *Descuentos en caja* no puede crear ni cambiar un descuento, pero sí editar otros datos de una venta que ya lo tiene.
- `SalePricingTest` / `SaleSyncTest`: lo que haga falta.

**Unitarias: `src/utils/discount.test.mjs`, agregada a `npm run test:unit`.**

- Máximos: subtotal 150 al 20 % → 30,00; 47,50 → 9,50; al 100 % → el subtotal completo.
- Mensajes: supera el máximo, sin motivo, motivo de 9 caracteres.
- `paymentDisplay.test.mjs`: una venta con total 0 y descuento muestra «Cortesía», no «Efectivo».

**E2E: nuevo `e2e/descuento-caja.spec.js`.**

- Sin el permiso, el enlace no aparece. En PedidosYa tampoco.
- Un cajero no puede pasar del 20 %: aparece el aviso y Cobrar queda deshabilitado.
- Sin motivo, Cobrar queda deshabilitado. Con el motivo se habilita.
- Efectivo: los billetes sugeridos y el vuelto se calculan sobre el total con descuento. QR: el monto es el total
  con descuento. Pago dividido: los pagos suman el total con descuento.
- Al cerrar y volver a abrir el modal, el descuento empieza en 0.
- Cortesía: un administrador descuenta el 100 %. Desaparecen «Cómo paga» y el vuelto, el botón dice «Registrar
  cortesía» y la venta se sincroniza.
- La pantalla de venta cobrada muestra el subtotal, el descuento, el total y el motivo.
- A 1093 × 489, con el panel abierto, el vuelto y Cobrar se ven sin desplazar.
- Control diario: con el permiso, el descuento con motivo se guarda. Sin el permiso, el campo está deshabilitado. A
  1093 × 489 el bloque de descuento y Guardar se ven.

**Verificación obligatoria (AGENTS.md).**

- `php artisan test`, `npm run test:unit` y la suite E2E en verde. `npx vite build` sin errores.
- Contraste en claro y oscuro, a 1366 × 611 y 1093 × 489: 0 textos bajo AA.
- **Flujo completo contra una copia de la base:** login → abrir caja → vender con descuento (efectivo y mixto) y una
  cortesía (admin) → cocina → anular (admin) → cerrar caja. Verificar los descuentos y la cortesía en el cierre y en el
  reporte.

---

## 6. Al publicar

- La migración es aditiva. Se publica con `deploy/vps/publicar.sh`: primero el ensayo y luego `--confirmar`.
- **Tarea del dueño:** en Usuarios → editar persona, dar *Descuentos en caja* a quien corresponda. Por defecto
  nadie lo tiene, salvo el Super Admin.
