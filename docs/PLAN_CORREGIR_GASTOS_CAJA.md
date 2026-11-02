# Plan: corregir y anular gastos de caja

> Versión 2, 2026-11-02. Reemplaza la propuesta inicial («Edición y anulación de gastos»). Incorpora las decisiones del
> dueño y lo que apareció al revisarla contra el código (rama `feat/descuentos-manuales-pos`, `f383309`).

## Resumen

- Un gasto o ingreso de caja mal registrado se puede **corregir** (método, monto, descripción, categoría) o **anular**,
  siempre con un **motivo obligatorio**.
- El cajero corrige los de su propia caja mientras está abierta, desde una lista nueva **«Gastos del turno»** en Turno.
  El administrador lo hace también desde Control diario.
- **Solo en cajas abiertas.** Una caja cerrada se corrige como hasta ahora: se reabre con un motivo, se corrige y se
  vuelve a cerrar.
- **Nada desaparece.** Un movimiento anulado queda tachado y visible. Uno corregido muestra qué cambió y por qué, en
  Turno, Control diario y el reporte de caja.

Caso que lo motivó: un gasto de Bs 74 en frutas se registró como «QR o banco», pero se pagó con efectivo de la gaveta.
La gaveta mostraba Bs 396 (294 de fondo + 102 de ventas); con la corrección muestra Bs 322 y el cierre cuadra.

---

## 1. Decisiones

| # | Pregunta | Decisión |
|---|---|---|
| D1 | ¿El cajero puede corregir o anular sus propios gastos? | **Sí**, los de su propia caja mientras está abierta. |
| D2 | ¿Dónde los corrige el cajero? | En una lista **«Gastos del turno»** en Turno. |
| D3 | ¿Un gasto anulado se oculta? | **No:** queda tachado y visible, como una venta anulada. |
| D4 | ¿Y una caja cerrada? | **Cambio del 02/11 (pedido del dueño):** se corrige **directo en Control diario**, con el permiso *Corregir cajas cerradas*. Es el mismo «reabrir y volver a cerrar», hecho en un paso y sin recontar: el cierre anterior queda en el historial (§3.8). Antes se pedía reabrir a mano. |
| D5 | ¿Cuántos caracteres lleva el motivo? | De 10 a 255, igual que el de reabrir una caja o el de un descuento. |
| D6 | ¿Qué se puede cambiar? | Método, monto, descripción y categoría. La fecha, solo dentro del día de la caja. **El tipo (gasto ↔ ingreso) no:** si estaba mal, se anula y se registra de nuevo. |
| D7 | ¿De qué rama se parte? | De `feat/descuentos-manuales-pos`, que toca los mismos archivos y todavía no está en `main`. Si los descuentos se publican antes, se parte de `main`. |

---

## 2. Diagnóstico (verificado en el código)

| Capa | Estado | Detalle |
|---|---|---|
| Rutas | Falta | Solo existen `GET` y `POST /cash-sessions/{id}/movements`. Finanzas ya puede cambiar la **categoría** (`PATCH /cash-movements/{id}/category`), pero nada más. |
| `CashMovement` | Listo para anular | Ya usa `SoftDeletes` y la tabla ya tiene `deleted_at`. El arqueo (`calculateSessionStats`) y Finanzas (`FinanceReportService`, `FinanceCashMovementController`) usan Eloquent, así que un movimiento anulado deja de sumar solo. |
| Auditoría (`Auditable`) | Insuficiente | Al editar guarda solo los valores **nuevos** (`getDirty()`), no los anteriores, y la ve solo el Super Admin. No sirve para mostrar «QR → Efectivo». |
| Caja cerrada | Ya resuelto | `recordMovement` rechaza cajas cerradas (422). Una caja cerrada se reabre con `register_corrections` y vuelve a quedar `open`; al cerrarla otra vez se guarda el historial (`cash_session_closings`). |
| `CashExpenseModal.vue` | Falta | Solo registra movimientos nuevos. Su aviso «no alcanza el efectivo de la gaveta» no sirve al editar: tiene que sumar de vuelta el monto original si ya era en efectivo. |
| Control diario | Falta | La pestaña «Gastos e Insumos» no tiene columna de acciones. |
| Turno | Falta | **No hay ninguna lista** de los movimientos del turno: solo el botón «Registrar gasto o ingreso». `turnoStore.fetchSessionMovements` ya existe. |
| Reporte de caja | Falta | El backend envía `movements`, pero `CashSessionReport.vue` no los muestra. |

---

## 3. Diseño

### 3.1 Datos (migración aditiva)

`2026_11_02_000002_add_correction_fields_to_cash_movements`:

| Columna | Para qué |
|---|---|
| `edited_by` (fk users, nullable), `edited_at` | Quién corrigió y cuándo (la última corrección). |
| `edit_reason` (string 255, nullable) | Motivo de la última corrección. |
| `edit_previous` (json, nullable) | Valores anteriores a la última corrección: `{payment_method, amount, description, expense_category_id, occurred_at}`. Es lo que permite mostrar «QR → Efectivo». |
| `edit_count` (unsigned small int, default 0) | Cuántas veces se corrigió. |
| `voided_by` (fk users, nullable), `void_reason` (string 255, nullable) | Quién anuló y por qué. La fecha es `deleted_at`. |

La auditoría deja además el historial completo (ver 3.3).

### 3.2 Servidor

- **Rutas nuevas**, en el grupo `perm:register_self,edit`:
  - `PUT /cash-sessions/{cashSession}/movements/{cashMovement}` → `updateMovement`;
  - `POST /cash-sessions/{cashSession}/movements/{cashMovement}/void` → `voidMovement`. Es `POST` con motivo, como `POST /sales/{sale}/void`.
- **Validación común** (`CashMovementCorrectionRequest` o un helper en el controlador):
  - `ensureCanAccess($request, $cashSession, 'edit')`: el dueño de la caja, o alguien con `register_others,edit` (los administradores por defecto);
  - el movimiento pertenece a esa caja (404 si no);
  - la caja está `open`. Si no: 422, «Esta caja está cerrada. Reábrela para corregirla.»;
  - el movimiento no está anulado (422).
- **`updateMovement`:**
  - `reason` obligatorio (10 a 255);
  - campos opcionales: `payment_method` (`cash`, `qr`, `card`), `amount` (≥ 0,01), `description` (3 a 500), `expense_category_id`, `occurred_at`;
  - la fecha se valida dentro del día de la caja y no puede ser futura: se reutiliza la validación de `recordMovement`, sacada a un método;
  - si nada cambió: 422, «No hay cambios para guardar.»;
  - en una transacción: guarda `edit_previous`, `edit_reason`, `edited_by`, `edited_at` e incrementa `edit_count`;
  - devuelve el movimiento y los `stats` recalculados, como `recordMovement`.
- **`voidMovement`:**
  - `reason` obligatorio (10 a 255);
  - guarda `voided_by` y `void_reason`, y luego aplica el borrado lógico (`delete()`);
  - devuelve los `stats`.
- **`movements()`** (el listado) pasa a incluir los anulados (`withTrashed()`), con `editedBy:id,name` y `voidedBy:id,name`. Los totales siguen sin contarlos.
- **Reporte de caja** (`buildReportPayload`): `movements` incluye los anulados y los campos de corrección; se agrega `movement_corrections_count`.
- **Caja reabierta:** las correcciones se hacen con la caja abierta; al volver a cerrarla, el nuevo cierre ya las refleja.

### 3.3 Auditoría

`Auditable` sigue dejando su registro automático. Además, la corrección y la anulación dejan registros propios, legibles:

- `cash_movement_updated`: «Caja #10, egreso #55 corregido por Ana: método QR → Efectivo; monto Bs 74,00 (sin cambio). Motivo: …»;
- `cash_movement_voided`: «Caja #10, egreso #56 de Bs 20,00 (Efectivo) anulado por Ana. Motivo: …».

### 3.4 Turno: «Gastos del turno»

- **Dónde:** una sección nueva debajo de «La gaveta», visible solo con la caja abierta. Se carga con `fetchSessionMovements(activeSession.id)` al abrir Turno y después de registrar, corregir o anular.
- **Cada fila:**
  - hora, tipo (Egreso / Ingreso), método, concepto y monto;
  - un movimiento corregido lleva la etiqueta «Corregido» y la línea «Antes: QR · Bs 74,00 — Motivo: …»;
  - un movimiento anulado queda tachado, con «Anulado — Motivo: …» y sin acciones.
- **Acciones:** «Corregir» y «Anular», con objetivos táctiles de 32 px como el resto de la portátil. Necesitan conexión, igual que «Anotar merma»: sin red se ven deshabilitadas con su `title`.
- **Sin movimientos:** «Todavía no registraste gastos ni ingresos en este turno.»
- **Portátil:** la lista no agranda la página. Con más de 4 filas se desplaza dentro de su propio contenedor.

### 3.5 Modal: corregir y anular

- **`CashExpenseModal.vue`** recibe la prop `movement`. Si viene:
  - título «Corregir gasto» o «Corregir ingreso»; el tipo no se puede cambiar;
  - los campos vienen precargados;
  - aparece el campo **«Motivo de la corrección»** (obligatorio, de 10 a 255 caracteres);
  - el botón dice «Guardar corrección» y se habilita solo si algo cambió y el motivo es válido;
  - **el aviso de efectivo:** el disponible es la gaveta de hoy más el monto original si ya era en efectivo. Así, pasar Bs 74 de QR a efectivo avisa solo si de verdad no alcanza;
  - debajo del método, una vista previa: «La gaveta pasa de Bs 396,00 a Bs 322,00».
- **Anular:** un modal de confirmación pequeño (`FinanceModal`, el patrón que ya usa Turno) con el resumen del movimiento, el motivo obligatorio y «Anular movimiento». La explicación dice que el movimiento queda tachado y deja de contar en la gaveta.
- **Store (`turno.js`):** `updateCashMovement(sessionId, movementId, payload)` y `voidCashMovement(sessionId, movementId, reason)` actualizan `sessionStats` con lo que responde el servidor, igual que `recordCashMovement`.

### 3.6 Control diario

- La tabla de «Gastos e Insumos» agrega la columna **Acciones** («Corregir», «Anular»), con el mismo modal.
- Las acciones se ven solo si la caja está abierta y la persona puede operarla. En una caja cerrada, el lugar de las
  acciones muestra «Reabre la caja para corregir»; el botón para reabrir ya existe para quien tiene *Corregir cajas cerradas*.
- Las filas corregidas y anuladas se ven igual que en Turno.
- Después de corregir o anular, se recargan la tabla y la tarjeta del arqueo.

### 3.7 Reporte de caja

- **`CashSessionReport.vue`:** sección nueva **«MOVIMIENTOS CORREGIDOS (N)»**, solo si hay alguno. En cada línea: hora, concepto, antes → ahora o «Anulado», quién y el motivo.
- Los totales del arqueo no cambian de forma: siguen saliendo de `summary`, que ya excluye los anulados.

---

## 4. Fases

Cada fase es un commit en la rama `feat/editar-gastos-caja`. Cada commit actualiza el CHANGELOG («Sin publicar») y, si corresponde, la BITÁCORA.

- **F0 · Preparación:** crear la rama (D7), respaldar la base local y copiar este plan a `docs/PLAN_CORREGIR_GASTOS_CAJA.md`.
- **F1 · Servidor:** migración, rutas, `updateMovement` y `voidMovement`, listado y reporte con anulados, auditoría propia y pruebas.
- **F2 · Modal y store:** modo corrección de `CashExpenseModal`, modal de anulación y acciones del store.
- **F3 · Turno:** la lista «Gastos del turno».
- **F4 · Control diario y reporte:** columna de acciones, filas corregidas y anuladas, y sección del reporte.
- **F5 · Verificación y cierre:** la sección 5 completa, la BITÁCORA, y **preguntar si se publica en el VPS**.

---

## 5. Pruebas

**PHPUnit: nuevo `CashMovementCorrectionTest.php`.**

- **El caso real:** fondo 294 y ventas en efectivo 102; gasto QR de 74 → gaveta 396; corregido a efectivo → 322.
  Guarda `edit_previous`, el motivo y `cash_movement_updated`.
- **Anular un gasto en efectivo:** la gaveta sube por el monto, el movimiento queda con `deleted_at`, `voided_by` y `void_reason`, sigue en el listado y no suma en Finanzas.
- **Permisos:**
  - el dueño de la caja puede corregir y anular;
  - otro cajero no (403);
  - un administrador con `register_others` sí.
- **Caja cerrada:** 422; después de reabrirla, sí se puede.
- **Validaciones:** sin motivo o con motivo corto, sin cambios, fecha fuera del día, movimiento de otra caja (404) y movimiento ya anulado.
- **Reporte:** trae los anulados y los corregidos con sus campos de corrección.

**E2E: nuevo `corregir-gastos.spec.js`, en la portátil (1366 × 611 y 1093 × 489).**

- Turno: la lista aparece con la caja abierta; corregir de QR a efectivo actualiza la gaveta en pantalla (396 → 322) y la fila muestra «Corregido» con el «Antes».
- Anular: la fila queda tachada y sin acciones.
- El aviso de efectivo no salta al corregir un gasto que ya era en efectivo.
- Sin conexión, las acciones están deshabilitadas.
- Control diario: acciones en caja abierta; «Reabre la caja para corregir» en caja cerrada.
- La lista y los modales caben en las dos medidas, sin desplazamiento horizontal, con el botón principal a la vista.

**Verificación obligatoria (AGENTS.md).**

- `php artisan test`, `npm run test:unit`, la suite E2E y `npx vite build`.
- Contraste AA en claro y oscuro de la lista, las filas corregidas y anuladas, y los modales.
- **Flujo completo contra una copia de la base:**
  1. abrir caja;
  2. registrar un gasto QR de 74;
  3. corregirlo a efectivo y comprobar la gaveta;
  4. anular otro gasto;
  5. vender, pasar por cocina y anular una venta;
  6. cerrar caja (debe cuadrar) y revisar el reporte;
  7. reabrir, corregir otro gasto y volver a cerrar.

---

## 6. Al publicar

- La migración es aditiva. Se publica con `deploy/vps/publicar.sh`: primero el ensayo y luego `--confirmar`.
- **Los Bs 74 que motivaron el cambio:** si esa caja ya está cerrada, quien tenga *Corregir cajas cerradas* la reabre
  en Control diario, corrige el gasto con su motivo y la vuelve a cerrar.

---

## 3.8 Corrección directa en una caja cerrada (añadido el 2026-11-02)

El dueño no veía «Corregir» en Control diario: la caja de los Bs 74 ya estaba cerrada y la pantalla pedía reabrirla.
Pidió corregir directo ahí.

- **Quién:** quien tenga *Corregir cajas cerradas* (el Super Admin siempre). Sin el permiso, la fila dice «Requiere
  «Corregir cajas cerradas»» y el servidor responde 403. El cajero no corrige cajas cerradas.
- **Qué hace el servidor** (`updateMovement` y `voidMovement` → `recloseWithSameCount`), en la misma transacción que la
  corrección:
  1. guarda el cierre anterior en `cash_session_closings`, reabierto y vuelto a cerrar por la misma persona y con el
     mismo motivo («Corrección sin recontar (…). Motivo: …»);
  2. recalcula el teórico y la diferencia con el **mismo efectivo contado**;
  3. conserva la fecha del cierre, el contado, el desglose, el retiro y el fondo para mañana: la caja siguiente no cambia;
  4. rehace la nota automática de faltante; una nota escrita por el cajero se conserva;
  5. deja `cash_session_reclosed` en la auditoría: «corregida sin recontar … diferencia Bs −74.00 → Bs 0.00».
- **Pantalla:** el modal de corregir y el de anular avisan «Esta caja ya está cerrada» y muestran el teórico y la
  diferencia antes → después (`utils/closedCorrection.js`). No muestran la gaveta ni el aviso de efectivo insuficiente:
  el efectivo ya se contó.
- **Reporte:** «Caja rectificada 1 vez» con el cierre anterior, y la nueva diferencia.
- **Límite conocido (previo):** Control diario muestra **una caja por día**, la última abierta. Si un día tuvo dos cajas,
  la otra no se ve ahí.
