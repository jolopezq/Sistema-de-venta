# Plan: ajuste de la pantalla de Cobro para la portátil de caja (v2)

> **Archivo a modificar:** [CheckoutModal.vue](../frontend/src/components/CheckoutModal.vue) (se abre desde [Pos.vue](../frontend/src/views/Pos.vue)).
> **Reglas:** [AGENTS.md](../AGENTS.md). **Rama:** `feat/pos-caja-arranque`.
> **Estado (2026-10-19):** plan v2, pendiente de aprobación. La v1 queda en `PLAN_PANTALLA_COBRO_PORTATIL.v1.md`.

---

## 0. Qué cambió respecto a la v1

| # | Corrección | Motivo |
|---|---|---|
| 1 | Se retira la afirmación de que "Cancelar" y "Cobrar" quedan ocultos | `.co-foot` es hermano de `.co-body` dentro de una tarjeta flex: **el pie nunca se desplaza**, solo el cuerpo. Lo que se pierde de vista al desbordar es la **nota** y parte del vuelto, no los botones. |
| 2 | Se quita `!important` del arreglo del selector | Basta con subir la especificidad (`.co-split-line .co-split-method`) o reordenar. `!important` dificulta mantener el CSS. |
| 3 | El overlay también pasa de 16 px a 12 px de padding | La v1 subía la tarjeta a `100vh - 24px` pero dejaba `padding: 16px` en `.co-overlay`: la tarjeta no cabría en el overlay (587 > 579) y quedaría descentrada. |
| 4 | Breakpoint móvil: **520 px**, no 640 px | Es el valor real del código (`@media (max-width: 520px)`). Cambiarlo a 640 alteraría tablets sin necesidad. |
| 5 | "Dividir el pago" pasa a la **misma línea** que el selector de método | Ahorra ~26 px reales y es más simple que bajar la altura de cada botón. Los cálculos de la v1 (62 px) no cuadraban con botones de 34 px + enlace debajo (≈ 67 px). |
| 6 | Se calcula el **peor caso real: 3 pagos** | El código permite hasta 3 (`payments.length < 3`); la v1 solo evaluaba 2. |
| 7 | Cálculo de ancho del pago dividido corregido | La v1 mezclaba 110 px (input) con 80 px (ancho real del `<input>` interno): línea = 120 + 131 + 30 + 12 = 293 px, no 272. |
| 8 | Altura del bloque "Resultado" coherente con su relleno | Con `padding: 10px` y texto 14 px/1.4, el mínimo real es ≈ 40 px, no 38. Se baja el padding a 8 px. |
| 9 | Aserciones de Playwright con tolerancia de 1 px | `scrollHeight === clientHeight` falla por subpíxeles. |
| 10 | Se explica **cómo** el test llega al modal | La v1 no decía cómo abrir el cobro (login mock, productos, caja abierta). |
| 11 | Comando de contraste corregido | `audit-contrast.cjs` recibe `<carpeta-salida> [dark\|light]`; `pos` se tomaba como nombre de carpeta, y el script no abre el modal. |
| 12 | Se añade plan B para escala de Windows 125 % | A 125 % el viewport útil baja a ≈ 1093 × 489. |
| 13 | Los números de la v1 son **mediciones declaradas, no verificadas** | Se agrega un paso 0: test que reproduce el fallo (rojo) antes de tocar CSS. |

---

## 1. Resumen del problema

En la portátil de caja (1366 × 768, viewport útil **1366 × 611** con Chrome, barra de marcadores y barra de tareas) el modal de cobro tiene tres defectos:

1. **Pago dividido desborda horizontalmente.** `.co-split-method` (`width: 120px`) y `.co-input` (`width: 100%`) tienen la misma especificidad (0-1-0) y `.co-input` está declarada después, así que el `<select>` ocupa toda la columna. Como `.co-split-method` tiene `flex-shrink: 0`, el monto y la `×` se salen de la tarjeta y aparece scroll horizontal.
2. **Desborde vertical.** La tarjeta mide `calc(100vh - 32px)` = 579 px y el contenido pide ≈ 611 px (pago simple con vuelto) y ≈ 651 px (dividido). El cuerpo entra en scroll; el pie **sí** queda visible, pero la nota y parte del vuelto quedan abajo.
3. **Ancho desaprovechado.** `max-width: 520px` (pensado en teléfono) manda "Recibe" a dos líneas (input + billetes) y cuesta ~50 px de alto.

**Objetivo:** que el cobro entre completo, **sin scroll horizontal y sin scroll vertical en el cuerpo**, en 1366 × 611, tanto en pago simple como dividido, sin perder legibilidad ni tamaño cómodo de clic con touchpad.

---

## 2. Requisitos de pantalla

- Portátil Toshiba 15.6", 1366 × 768, Windows al 100 %, Chrome al 100 %.
- Viewports de referencia: **1366 × 611** (peor caso: Chrome con favoritos), 641, 697 (app instalada), 768 (F11).
- Alto útil de la tarjeta en el peor caso: `611 − 24 = 587 px` (con overlay de 12 px).

### Criterios de aceptación
1. 0 px de scroll horizontal en `.co-card` y `.co-body`.
2. En 1366 × 611: `.co-body.scrollHeight − clientHeight ≤ 1` en pago simple (efectivo con vuelto), QR, tarjeta, dividido de 2 y de 3 pagos, y PedidosYa.
3. Pie `Cancelar` / `Cobrar` siempre dentro del viewport (`boundingBox().y + height ≤ viewport`).
4. Escala 125 % (≈ 1093 × 489): puede haber scroll **vertical solo en el cuerpo**; el pie no se mueve y no hay scroll horizontal.
5. Móvil 390 × 844 y tablet 768 × 1024: sin cambios de comportamiento (bottom-sheet bajo 520 px).
6. Contraste WCAG AA en claro y oscuro.

---

## 3. Diagnóstico (código actual)

- `.co-split-method { width:120px; flex-shrink:0 }` (≈ línea 597) queda anulada por `.co-input { width:100% }` (≈ línea 600), declarada después. Efecto: select 378 px, monto desde x≈929, `×` hasta ≈1100 px → desborde de ~157 px.
- `.co-body { overflow-y:auto }` sin `overflow-x` → barra horizontal sobre la nota.
- Alturas actuales (pago simple con vuelto): cabecera 50 + total 76,5 + cómo paga 72 + recibe 92 + vuelto 62 + se sirve 42 + nota 40 + 5 gaps × 14 + padding cuerpo 28 + pie 79 = **611,5 px** (déficit 32,5). Dividido de 2: +40 → **651,5 px** (déficit 72,5). Suma verificada.
- Dividido de 3: +80 sobre el simple ≈ **691 px**.

---

## 4. Diseño propuesto

### 4.1 Dimensiones

| Elemento | Hoy | Propuesto |
|---|---|---|
| `.co-overlay` padding | 16 px | **12 px** |
| `.co-card` | `max-width 520`, `max-height 100vh−32` | **`max-width 620`**, `max-height 100vh−24` |
| `.co-head` | `16px 20px 0`, h2 20 px (50 px) | `10px 18px 0`, h2 18 px (**≈ 40 px**) |
| `.co-body` | padding `12px 20px 16px`, gap 14 | padding **`8px 18px 10px`**, gap **9**, `overflow-x: hidden` |
| `.co-total` | padding `14px 16px`, monto 34 px (76,5) | padding **`8px 14px`**, monto **30 px** (**≈ 58**) |
| `.co-row` | label 92 px | label **90 px** |
| Cómo paga (simple) | seg 38 px + enlace debajo (72) | seg botones **34 px**, `Dividir el pago` **en la misma línea** (**≈ 40**) |
| Línea de pago dividido | select 100 % ❌ | select **120 px fijo**, monto `flex:1; min-width:0`, `×` 30 px; alto 34 px |
| Recibe | 2 líneas (92) | **1 línea** (input 38 px + billetes 34 px) (**≈ 38**) |
| `.co-result` | min 48 / vuelto 62, padding 10 | min **38** / vuelto **52**, padding **8px 12px**, monto 26 px |
| Se sirve | 34 px (42) | botones 30 px (**≈ 36**) |
| Nota | 40 px | input 34 px (**≈ 34**) |
| `.co-foot` | `12px 20px 16px`, botón 50 (79) | `10px 18px 12px`, botón **44** (**≈ 67**) |

### 4.2 Ancho disponible
Tarjeta 620 → cuerpo 584 → control = 584 − 90 − 10 = **484 px**.
- Simple: 3 botones de método (~250 px) + enlace (~110 px) caben en una línea; si no, `flex-wrap`.
- Recibe: input ≈ 160 px + 4 botones (~62 px) + gaps ≈ 440 px < 484. Se deja `flex-wrap: wrap` como **red de seguridad** (la v1 imponía `nowrap`, lo que podría provocar desborde si un billete tiene tres cifras, p. ej. `Bs 200`).
- Dividido: 120 + monto (flex) + 30 + 12 de gaps; sobra ancho para el monto.

### 4.3 Balance vertical (viewport 611, disponible 587)

| Escenario | Cálculo | Total | Holgura |
|---|---|---|---|
| A. Efectivo con vuelto | 40+18+58+40+38+52+36+34+67 + 5 gaps×9 | **≈ 428** | +159 |
| B. Dividido 2 pagos + vuelto | A − 40 + (2×34 + 4 + 24) = A + 56 | **≈ 484** | +103 |
| C. Dividido 3 pagos + vuelto (peor) | A − 40 + (3×34 + 2×4 + 24) = A + 94 | **≈ 522** | +65 |
| Escala 125 % (disp. ≈ 465) | A cabe (428); C no (522) | — | **plan B** (§4.4) |

### 4.4 Plan B para escalas/ventanas más bajas
Añadir `@media (max-height: 520px) and (min-width: 521px)`: gap 6 px, total 50 px, botones de seguridad 30 px, pie 58 px. Con eso C baja a ≈ 480 px (estimado; se confirma en la Fase 2). Si aun así no cabe, solo el **cuerpo** hace scroll (el pie sigue fijo). Es un respaldo, no el diseño principal.

---

## 5. Implementación por fases

### Fase 0: reproducir el fallo (rojo)
Escribir primero `frontend/e2e/checkout-portatil.spec.js` y verificar que **falla** hoy en 1366 × 611 (scroll horizontal en dividido; scroll vertical en A, B y C). Si las mediciones de este plan no se reproducen, se corrige el plan antes de tocar CSS.

**Cómo llega el test al modal** (mismo patrón que `permisos-portatil.spec.js`): `page.route('**/api/**')` con mocks de `/me` (cajero con `effective_permissions`), `/categories`, `/products` (2–3 productos, uno con precio alto), caja abierta (`/cash-sessions/current`) y `/sales/sync`; `localStorage.auth_token`; ir a `/pos`, agregar un producto y pulsar `Cobrar`. Verificar los selectores reales de `Pos.vue` antes de escribirlos.

### Fase 1: CSS de `CheckoutModal.vue` (sin cambiar lógica)
1. **Bug del select:**
   ```css
   .co-split-line .co-split-method { flex: 0 0 120px; width: 120px; }
   .co-split-line .co-money-input.sm { flex: 1; min-width: 0; }
   .co-split-line .co-money-input.sm input { width: 100%; min-width: 0; }
   ```
2. `overflow-x: hidden` en `.co-body`; `min-width: 0` en `.co-split`, `.co-split-line`, `.co-row-control`.
3. Escala de §4.1; overlay a 12 px; tarjeta a 620 px.
4. "Cómo paga": `.co-row-control` en `flex-direction: row; flex-wrap: wrap; align-items: center` solo cuando **no** hay pago dividido; en dividido, columna con el enlace "Agregar otro método" debajo.
5. `.co-received` con `flex-wrap: wrap` (sin forzar `nowrap`).
6. `@media (max-width: 520px)`: **no tocar** (bottom-sheet). Verificar que los nuevos paddings no lo rompan; si es necesario, repetir los valores móviles dentro de ese bloque.
7. Plan B de §4.4.
8. Sin cambios en el `<script>`: cálculo de vuelto, `handleConfirm`, teclas (Enter en "Recibe", Esc cierra) y descuento del exceso en efectivo se mantienen.

### Fase 2: pruebas Playwright (`checkout-portatil.spec.js`)
Casos, en 1366 × 611 salvo que se indique:
- Efectivo con vuelto (total y billete que generen vuelto).
- QR y Tarjeta (sin fila "Recibe").
- Dividido 2 y 3 pagos (cambiar método, escribir monto, quitar línea con `×`).
- Estados `short` y `cash-short` (texto largo del resultado en una línea).
- PedidosYa (si hay forma de activarlo en el mock).
- Destino "Mixto" visible (3 botones en "Se sirve").
- Tema oscuro (`html.dark`).
- Viewports adicionales: 1366 × 641 / 697 / 768, 1280 × 720, 1093 × 489 (125 %), 390 × 844.

Aserciones (con tolerancia):
```js
const m = await page.evaluate(() => {
  const b = document.querySelector('.co-body'), c = document.querySelector('.co-card');
  return { hx: b.scrollWidth - b.clientWidth, vy: b.scrollHeight - b.clientHeight,
           cx: c.scrollWidth - c.clientWidth, foot: document.querySelector('.co-foot').getBoundingClientRect().bottom };
});
expect(m.hx).toBeLessThanOrEqual(1);
expect(m.cx).toBeLessThanOrEqual(1);
expect(m.vy).toBeLessThanOrEqual(1);          // solo en 1366x611 y mayores
expect(m.foot).toBeLessThanOrEqual(viewportHeight);
await expect(page.locator('.co-btn-primary')).toBeVisible();
```
Además un test funcional corto: tras el rediseño, confirmar un cobro dividido envía el `payments` esperado a `/sales/sync` (red de seguridad contra regresiones de lógica).

### Fase 3: contraste y capturas
```bash
cd frontend
node scripts/audit-contrast.cjs contrast-pos dark
node scripts/audit-contrast.cjs contrast-pos light
```
(el script audita pantallas principales y **no abre el modal**: extenderlo o añadir al spec una captura del modal abierto en claro/oscuro y comprobar los textos con la misma función `AUDIT`). Revisar a ojo capturas en 1366 × 611, claro y oscuro.

### Fase 4: cierre
- `CHANGELOG.md` ("Sin publicar") y `docs/BITACORA.md` (estado y decisión).
- `graphify update .` (AGENTS.md); revisar `git status` antes de `git add -A` por los archivos de `graphify-out/`.
- Commit por tema, sin `backend/storage/app/public/`.

---

## 6. Riesgos y decisiones abiertas

| Riesgo | Mitigación |
|---|---|
| Las mediciones de la v1 no se reproducen | Fase 0 (test en rojo primero). |
| Escala de Windows ≠ 100 % en la caja | Plan B + el pie fijo; confirmar con el dueño qué escala usa la Toshiba. |
| Botones de 34 px menos cómodos | Es touchpad/ratón (sin táctil); 34 px supera el mínimo de 24 px de WCAG 2.2. El botón `Cobrar` queda en 44 px. |
| `Pos.vue` en móvil/tablet se ve afectado | Pruebas 390 × 844 y 768 × 1024 en Fase 2. |
| Estilos `scoped` y cascada | Los selectores nuevos viven en el mismo componente; verificar con el test, no solo a ojo. |

**Pregunta para el dueño:** ¿la Toshiba usa 100 % de escala de Windows? Si usa 125 %, el viewport real es ≈ 1093 × 489 y el Plan B pasa a ser el diseño base para esa máquina.

---

## 7. Archivos

- Modificar: `frontend/src/components/CheckoutModal.vue` (solo `<style>`).
- Crear: `frontend/e2e/checkout-portatil.spec.js`.
- Actualizar: `CHANGELOG.md`, `docs/BITACORA.md`, grafo (`graphify update .`).
