# Plan: gasto de caja pagado en parte con efectivo y en parte por QR

> Versión 2, 2026-11-02. Reemplaza la propuesta inicial («pago mixto en Registrar gasto»). Incorpora las decisiones del
> dueño y lo que apareció al revisarla contra el código (producción en `182f1bee`, rama `fix/corregir-gasto-caja-cerrada`).

## Resumen

- Un **gasto de caja** se puede pagar en dos partes: una con **efectivo de la gaveta** y el resto por **QR o banco**.
  Queda como **un solo gasto** (una línea en Finanzas, una descripción, una corrección).
- El arqueo resta de la gaveta **solo la parte en efectivo**. La parte por QR cuenta como «gastos pagados con QR».
- En el modal se activa con el enlace **«Dividir pago (efectivo + QR)»**, como «Dividir el pago» del cobro. El caso
  común, un solo método, no cambia.

Ejemplo: compra de Bs 100; hay Bs 40 en la gaveta y el dueño transfiere Bs 60. Hoy se registran dos gastos. Con esto,
uno solo: «Bs 100 — Bs 40 efectivo + Bs 60 QR».

---

## 1. Decisiones

| # | Pregunta | Decisión |
|---|---|---|
| D1 | ¿Dónde? | **Solo en los gastos de caja** (Turno y Control diario). No en Compras de Inventario: una compra no toca la gaveta. |
| D2 | ¿Cómo se activa? | **Opción B:** enlace «Dividir pago (efectivo + QR)» debajo del monto. |
| D3 | ¿Qué métodos se combinan? | **Efectivo + QR o banco.** No tarjeta. |
| D4 | ¿Y los ingresos? | No se dividen: solo los gastos. |
| D5 | ¿Cómo se guarda? | **Un solo movimiento:** `payment_method = 'mixed'` y una columna nueva `cash_amount` con lo que salió de la gaveta. El resto (`amount − cash_amount`) es QR o banco. |

---

## 2. Diagnóstico (verificado en el código)

| Punto | Estado |
|---|---|
| `cash_movements.payment_method` | Texto de 20 caracteres (`cash`, `qr`, `card`), no `enum`: agregar `mixed` no obliga a reconstruir la tabla. |
| Arqueo (`calculateSessionStats`) | Tres sumas por método: total de gastos, gastos en efectivo (`cash` o vacío) y gastos con QR o tarjeta. Hay que cambiar las dos últimas. |
| Finanzas (`FinanceReportService`) | Suma el monto del gasto por categoría, sin mirar el método: **no cambia**. |
| Corrección (`updateMovement`) | Valida `in:cash,qr,card`, guarda en `edit_previous` un método y un monto, y escribe la auditoría con un método. Hay que extenderlo. |
| Caja cerrada (`recloseWithSameCount`, `utils/closedCorrection.js`) | El servidor recalcula con las sumas del arqueo (sirve tal cual). La vista previa supone un solo método: hay que extenderla. |
| Pantalla | **La misma expresión `=== 'cash' ? … : (=== 'qr' ? … : 'Tarjeta')` está repetida** en `Turno.vue`, `RetroactiveSales.vue` (insignia, «Antes» y los totales de respaldo), `CashSessionReport.vue` y `VoidCashMovementModal.vue`. Un gasto `mixed` saldría como «Tarjeta» en todos esos lugares. |
| Hoy | Registrar dos gastos («Bs 40 efectivo» + «Bs 60 QR») ya cuadra el arqueo. Esto es una mejora de claridad, no la corrección de un error en los datos. |

---

## 3. Diseño

### 3.1 Datos

Migración aditiva `2026_11_02_000003_add_cash_amount_to_cash_movements`: `cash_amount decimal(12,2) nullable`, después
de `payment_method`. Solo tiene valor si `payment_method = 'mixed'`.

### 3.2 Servidor

- **Registrar** (`recordMovement`):
  - `payment_method` admite `mixed`, solo si `type = expense` (si no: 422, «Solo un gasto se puede dividir»);
  - `cash_amount` es obligatorio si es `mixed`: mayor que 0 y menor que `amount` (si no: 422, «La parte en efectivo
    tiene que ser mayor que 0 y menor que el total»);
  - si no es `mixed`, `cash_amount` se ignora y queda `null`.
- **Arqueo** (`calculateSessionStats`), con `CASE` en SQL:
  - gastos en efectivo = `amount` de `cash`/vacío + `cash_amount` de `mixed`;
  - gastos con QR o tarjeta = `amount` de `qr`/`card` + `amount − cash_amount` de `mixed`;
  - total de gastos: sin cambio.
- **Corregir** (`updateMovement`):
  - acepta `mixed` y `cash_amount` con las mismas reglas;
  - `edit_previous` guarda también `cash_amount`;
  - la comparación de «sin cambios» incluye `cash_amount`;
  - la auditoría describe el método con el desglose: «método QR → Mixto (Bs 40,00 efectivo + Bs 60,00 QR)».
- **Caja cerrada:** `recloseWithSameCount` usa las sumas del arqueo, así que no cambia; el cierre se rehace con la
  parte en efectivo correcta.
- **Anular:** no cambia; un gasto mixto anulado deja de restar su parte en efectivo.
- **Listado y reporte:** `movements()` y `buildReportPayload` incluyen `cash_amount`.

### 3.3 Un solo lugar para mostrar el método

Nuevo `frontend/src/utils/cashMovementMethod.js` (funciones puras, con pruebas):

- `methodLabel(m)` → «Efectivo», «QR», «Tarjeta» o «Efectivo + QR»;
- `methodDetail(m)` → para `mixed`: «Bs 40.00 efectivo + Bs 60.00 QR»;
- `cashPart(m)` y `nonCashPart(m)`.

Reemplaza las expresiones repetidas en:

- `Turno.vue`: insignia y «Antes»;
- `RetroactiveSales.vue`: insignia, «Antes» y los totales de respaldo `cashExpenses` / `nonCashExpenses`;
- `CashSessionReport.vue`;
- `VoidCashMovementModal.vue`;
- `CashExpenseModal.vue`;
- `utils/closedCorrection.js`: su `cashEffectCents` usa `cashPart`.

La insignia de un gasto mixto dice «💵📱 Efectivo + QR» y, debajo del concepto, el desglose.

### 3.4 Modal «Registrar gasto» / «Corregir gasto»

- **Debajo del monto**, solo en gastos, el enlace **«Dividir pago (efectivo + QR)»**. Al activarlo:
  - los botones de método se reemplazan por la línea «Pagado con efectivo + QR o banco»;
  - el monto pasa a ser el **total del gasto**;
  - aparece **«De eso, salió de la gaveta: Bs __»**;
  - se muestra solo **«Por QR o banco: Bs X»** (total − efectivo), que no se escribe;
  - «Quitar división» vuelve a un solo método.
- **Validación en pantalla:** el efectivo tiene que ser mayor que 0 y menor que el total, con el aviso debajo del campo.
- **Gaveta:** «En la gaveta ahora / Quedaría» y el aviso «no alcanza» miran **solo la parte en efectivo**.
- **Corregir:** un gasto mixto se abre ya dividido. Pasar a un solo método o cambiar el desglose cuenta como cambio. La
  vista previa «La gaveta pasa de … a …» y la de caja cerrada (teórico y diferencia) usan `cashPart`.
- **Portátil:** el desglose agrega una sola fila. Hay que comprobar que «Registrar gasto» y «Guardar corrección» se
  ven a 1366 × 611 y 1093 × 489.

---

## 4. Fases

Rama `feat/gasto-pago-dividido`, que sale de `fix/corregir-gasto-caja-cerrada` (lo que está en producción). Cada
commit actualiza el CHANGELOG y, si corresponde, la BITÁCORA.

- **F0 · Preparación:** crear la rama, respaldar la base local y copiar este plan a `docs/PLAN_GASTO_PAGO_DIVIDIDO.md`.
- **F1 · Servidor:** migración, registro, arqueo, corrección, auditoría, listado y reporte, con sus pruebas.
- **F2 · Utilidad y lugares que muestran el método:** `cashMovementMethod.js` con sus pruebas, y su uso en todas las pantallas de 3.3.
- **F3 · Modal:** «Dividir pago» al registrar y al corregir.
- **F4 · Verificación y cierre:** la sección 5 completa, la BITÁCORA, y **preguntar si se publica en el VPS**.

---

## 5. Pruebas

**PHPUnit: nuevo `CashMovementSplitTest.php`.**

- **Registrar un gasto de 100 (40 efectivo + 60 QR):** la gaveta baja 40, los gastos con QR suben 60 y el total de
  gastos sube 100. Finanzas lo cuenta una vez, por 100.
- **Validaciones:**
  - efectivo 0, efectivo 100 o más que el total, o sin `cash_amount` → 422;
  - un ingreso mixto → 422;
  - `cash_amount` enviado sin `mixed` → se ignora.
- **Corregir:**
  - QR 100 → mixto 40/60: la gaveta baja 40;
  - mixto → efectivo 100: baja 60 más;
  - `edit_previous` y la auditoría llevan el desglose.
- **Caja cerrada:** corregir QR 100 → mixto 40/60 rehace el cierre con el mismo contado y la diferencia cambia en 40.
- **Anular un gasto mixto:** la gaveta sube 40.

**Unitarias:** `cashMovementMethod.test.mjs`, y casos `mixed` en `closedCorrection.test.mjs`.

**E2E: ampliar `corregir-gastos.spec.js`, o un `gasto-dividido.spec.js` nuevo.**

- Registrar dividido: el total, la parte en efectivo y el QR que se calcula solo.
- El aviso «no alcanza» mira solo el efectivo.
- La lista de Turno y la de Control diario muestran «Efectivo + QR» con el desglose.
- Corregir un gasto mixto.
- Todo a 1366 × 611 y 1093 × 489, en claro y oscuro.

**Verificación obligatoria (AGENTS.md).**

- `php artisan test`, `npm run test:unit`, la suite E2E, `vite build` y contraste AA.
- **Flujo contra una copia de la base:**
  1. abrir caja;
  2. registrar un gasto dividido 40 + 60;
  3. vender;
  4. cerrar caja (debe cuadrar);
  5. corregir el desglose en la caja cerrada y comprobar el reporte.

---

## 6. Al publicar

- La migración es aditiva. Se publica con `deploy/vps/publicar.sh`: primero el ensayo y luego `--confirmar`.
- **Pendiente previo:** `main` sigue atrás de producción. Conviene adelantarlo antes o después de publicar esto.

## 7. Fuera de alcance (anotado)

- **Pago mixto en Compras de Inventario** (D1).
- **Doble conteo en Finanzas:** una compra pagada con efectivo de la gaveta que se registra como gasto de caja y,
  además, como compra de Inventario se cuenta dos veces (hallazgo B4 de la auditoría de Finanzas). No lo resuelve
  este plan.
