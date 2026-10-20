# Plan: rediseño ergonómico y ajuste de la pantalla de Cobro para la portátil de caja Toshiba

> **Contexto:** Modal de cobro del POS ([CheckoutModal.vue](../frontend/src/components/CheckoutModal.vue)),
> reglas de trabajo ([AGENTS.md](../AGENTS.md)) y configuración de pantalla de la portátil de caja
> (Playwright: `1366 × 611`).
> **Rama:** `feat/pos-caja-arranque`. **Estado (2026-10-19):** plan de implementación tras análisis de incidencias en producción.

---

## 0. Resumen

En la portátil de caja Toshiba (1366 × 768), la vista de cobro actual ([CheckoutModal.vue](../frontend/src/components/CheckoutModal.vue)) presenta fallos visuales y funcionales críticos:
1. **Desborde horizontal masivo en pago dividido:** El selector de método (`Efectivo`, `QR`) ocupa el 100% del ancho debido a un conflicto de especificidad CSS; el campo de monto (`Bs [...]`) y el botón de eliminar línea (`[×]`) quedan ocultos fuera de la pantalla por más de 150 px hacia la derecha, provocando una barra de desplazamiento horizontal antiestética.
2. **Desborde vertical y corte inferior:** En el viewport real útil del navegador (**1366 × 611 px**), el contenido vertical del modal mide entre **598 px y 670 px**, superando el tope de `579 px` (`calc(100vh - 32px)`). Esto obliga al cajero a desplazarse verticalmente y oculta la fila de nota y los botones de confirmación ("Cancelar" y "Cobrar").
3. **Desperdicio de espacio en pantalla panorámica:** El modal está restringido a `max-width: 520px` (diseño de teléfono móvil), desperdiciando 846 px de ancho horizontal y provocando que los botones de billetes salten a una segunda fila innecesaria.

**Objetivo:** Adaptar el modal de cobro para que entre completo **sin scroll horizontal ni vertical (Zero-Scroll Guarantee)** en **1366 × 611 px**, resolviendo el bug de pago dividido y garantizando un despacho rápido y seguro para el cajero.

---

## 1. Requisitos de Pantalla de la Portátil de Caja

* **Hardware:** Portátil Toshiba de 15.6" con resolución nativa de **1366 × 768 px** (relación 16:9, ~100 ppp).
* **Entorno de ejecución:** Windows escala 100%, navegador web al 100% de zoom.
* **Espacio vertical disponible:**
  - Resolución nativa total: `768 px`
  - Barra de tareas de Windows: `-40 px`
  - Cabecera del navegador Chrome (pestañas + URL + marcadores): `-117 px`
  - **Viewport útil de la aplicación:** **`1366 × 611 px`** (proyecto *Portátil de caja* en Playwright).
  - Espacio de superposición (`.co-overlay` con padding de 16 px arriba y abajo): `611 - 32 =` **`579 px` de altura máxima utilizable**.

### Criterios de Calidad Obligatorios
1. **0 px de scroll horizontal** en `.co-card` y `.co-body`.
2. **0 px de scroll vertical** en 1366 × 611 px tanto en pago simple como en pago dividido (con o sin vuelto).
3. **Visibilidad permanente:** El botón principal `Cobrar Bs XX.XX`, el desglose de métodos y el vuelto deben ser inmediatamente visibles sin ninguna interacción previa con el trackpad.
4. **Cumplimiento de accesibilidad:** Contraste WCAG 2.1 AA en temas claro y oscuro (`scripts/audit-contrast.cjs`).

---

## 2. Diagnóstico Técnico de Fallos en [CheckoutModal.vue](../frontend/src/components/CheckoutModal.vue)

### A. Conflicto de Especificidad CSS (Bug de Pago Dividido)
* **Ubicación:**
  - Línea 597: `.co-split-method { width: 120px; flex-shrink: 0; }` (especificidad `0-1-0`).
  - Línea 600: `.co-input { width: 100%; box-sizing: border-box; ... }` (especificidad `0-1-0`).
* **Mecanismo del fallo:** En la plantilla (línea 297), el elemento tiene `<select class="co-input co-split-method">`. Al tener ambas reglas la misma especificidad de clase (`0-1-0`), la regla declarada posteriormente en el archivo (`.co-input`) prevalece en la cascada de CSS.
* **Efecto visual:**
  - El selector `<select>` toma el **100% de la columna disponible (378 px)**.
  - El campo de monto `.co-money-input.sm` (131 px) se posiciona a partir del pixel `929 px` (fuera del margen derecho de la columna de 923 px).
  - El botón de eliminar `.co-icon-btn` (34 px) se empuja hasta los `1.100 px` (desborda la tarjeta por 157 px).
  - `.co-body` no cuenta con `overflow-x: hidden`, por lo que el navegador genera una barra horizontal gruesa sobre la fila de la nota.

### B. Deficit de Altura y Colapso Vertical
* La altura máxima de la tarjeta está limitada por:
  ```css
  .co-card {
    max-height: calc(100vh - 32px); /* 611px - 32px = 579px */
  }
  ```
* En pago simple con vuelto:
  - Cabecera (`.co-head`): `50 px`
  - Total a cobrar (`.co-total`): `76.5 px`
  - Cómo paga (`.co-row`): `72 px`
  - Recibe (`.co-row` en 2 líneas): `92 px`
  - Vuelto grande (`.co-result.change`): `62 px`
  - Se sirve (`.co-row`): `42 px`
  - Nota (`.co-row`): `40 px`
  - Gaps de `.co-body` (5 gaps × 14 px): `70 px`
  - Paddings verticales de `.co-body`: `28 px`
  - Pie de acciones (`.co-foot`): `79 px`
  - **Altura total requerida:** **`611.5 px`** > **`579 px`** disponibles (**Déficit: -32.5 px**).
* En pago dividido (2 métodos):
  - La sección de cómo paga crece de 72 px a `112 px` (+40 px).
  - **Altura total requerida:** **`651.5 px`** > **`579 px`** disponibles (**Déficit: -72.5 px**).
* **Efecto visual:** El cuerpo entra en scroll forzado; los botones "Cancelar" y "Cobrar" quedan presionados contra el borde inferior o semivelados, y la nota queda cortada.

---

## 3. Matriz Dimensional Detallada: Dimensiones Actuales vs Propuestas

Mediciones reales obtenidas mediante inspección DOM automatizada con Playwright en viewport `1366 × 611 px`:

| Elemento / Bloque | Propiedad | Dimensión Actual | Dimensión Propuesta | Justificación del Cambio |
|---|---|---|---|---|
| **Contenedor Modal (`.co-card`)** | `max-width` | `520 px` | **`620 px`** | Aprovecha el ancho panorámico de la laptop (1366 px). |
| | `max-height` | `calc(100vh - 32px)` (579 px) | `calc(100vh - 24px)` (587 px) | Mayor aprovechamiento vertical con margen seguro de 12 px. |
| **Cabecera (`.co-head`)** | `padding` | `16px 20px 0` | **`12px 18px 0`** | Compacta 4 px verticales. |
| | Título `h2` | `font-size: 20px` | `font-size: 18px` | Proporcional y legible. |
| | Altura total bloque | `50 px` | **`42 px`** | Ahorro de 8 px. |
| **Cuerpo (`.co-body`)** | `padding` | `12px 20px 16px` | **`8px 18px 10px`** | Compacta 10 px verticales. |
| | `gap` | `14 px` | **`9 px`** | 5 gaps × 5 px de ahorro = **25 px ganados**. |
| | `overflow` | `overflow-y: auto` | **`overflow-y: auto; overflow-x: hidden`** | Elimina barras horizontales parásitas. |
| **Franja Total (`.co-total`)** | `padding` | `14px 16px` | **`8px 14px`** | Ahorro vertical directo de 12 px. |
| | Monto (`.co-total-amount`) | `34 px` (Baloo 2) | `30 px` (Baloo 2) | Muy llamativo sin engullir altura. |
| | Altura total bloque | `76.5 px` | **`58 px`** | Ahorro de 18.5 px. |
| **Etiqueta de Fila (`.co-row-label`)** | `width` | `92 px` | `90 px` | Proporcional a los textos. |
| **Columna de Controles (`.co-row-control`)**| `width` disponible | `378 px` (480 - 92 - 10) | **`484 px`** (584 - 90 - 10) | **+106 px de ancho libre**. |
| **Fila "Cómo paga" (Simple)** | Altura bloque | `72 px` | **`62 px`** | Botones de 34 px de alto + link compacto. |
| **Fila "Cómo paga" (Dividido)** | Selector método | `width: 100%` (378 px) ❌ | **`flex: 0 0 120px` (120 px)** ✅ | Corrige el bug de sobreescritura CSS. |
| | Input de monto | `131.3 px` (desbordado) | **`110 px`** | Cabe dentro de la tarjeta holgadamente. |
| | Botón eliminar `[×]` | `34 px` (desbordado) | **`30 px`** | Dentro del flujo visible. |
| | Ancho total línea dividida | `555 px` (desborde de 177 px) | **`272 px`** (en espacio de 484 px) | **212 px de margen libre sin desborde**. |
| | Altura bloque dividido | `112 px` | **`96 px`** | Reducción de gaps internos a 4 px. |
| **Fila "Recibe" (Efectivo)** | Disposición | 2 líneas envueltas (wrap) | **1 sola línea horizontal** | Con 484 px entran input (130 px) + 4 botones (280 px). |
| | Input dinero | `min-height: 46px` | `min-height: 38px` | Letra 18 px en Baloo 2. |
| | Botones de billetes | `min-height: 38px` | `min-height: 34px` | Altura estándar de clic rápido. |
| | Altura total bloque | `92 px` ❌ | **`42 px`** ✅ | **Ahorro masivo de 50 px verticales**. |
| **Franja Resultado / Vuelto** | Altura (pago justo / tarjeta / qr)| `48 px` | **`38 px`** | Compactación de paddings. |
| | Altura (con vuelto activo) | `62 px` | **`52 px`** | Monto vuelto en Baloo 2 `26px`. |
| **Fila "Se sirve"** | Altura botones | `34 px` | **`30 px`** | Formato segmentado compacto. |
| | Altura total bloque | `42 px` | **`36 px`** | Ahorro de 6 px. |
| **Fila "Nota"** | Altura input | `40 px` | **`34 px`** | Padding `6px 10px`. |
| | Altura total bloque | `40 px` | **`34 px`** | Ahorro de 6 px. |
| **Pie de Botones (`.co-foot`)** | `padding` | `12px 20px 16px` | **`10px 18px 12px`** | Compacta 6 px verticales. |
| | Altura botones | `50 px` | **`44 px`** | Ergonómico para mouse/touchpad. |
| | Altura total bloque | `79 px` | **`66 px`** | Ahorro de 13 px. |

---

## 4. Balance Vertical Resultante (Garantía de Cero Scroll)

### Escenario A: Cobro en Efectivo con Vuelto (Mayor uso diario)
* Cabecera: `42 px`
* Paddings cuerpo: `18 px`
* Franja Total: `58 px`
* Gap 1: `9 px`
* Cómo paga (simple): `62 px`
* Gap 2: `9 px`
* Recibe (1 sola línea): `42 px`
* Gap 3: `9 px`
* Vuelto grande: `52 px`
* Gap 4: `9 px`
* Se sirve: `36 px`
* Gap 5: `9 px`
* Nota: `34 px`
* Pie de botones: `66 px`
* **Altura total del modal:** **`455 px`**
* **Espacio disponible en pantalla:** **`587 px`**
* **Margen de seguridad libre:** **`+132 px` (HOLGURA TOTAL, CERO SCROLL)**.

### Escenario B: Cobro Dividido con 2 Métodos (Peor caso de altura)
* Cómo paga crece de 62 px a 96 px (+34 px).
* **Altura total del modal:** `455 + 34 =` **`489 px`**
* **Espacio disponible en pantalla:** **`587 px`**
* **Margen de seguridad libre:** **`+98 px` (CERO SCROLL GARANTIZADO)**.

---

## 5. Plan de Implementación por Fases

### Fase 1: Corrección de Estilos y Bugs en [CheckoutModal.vue](../frontend/src/components/CheckoutModal.vue)
1. **Solucionar la especificidad de los métodos divididos:**
   ```css
   .co-split-method.co-input {
     width: 120px !important;
     flex: 0 0 120px;
   }
   .co-split-line .co-money-input.sm {
     flex: 1;
     min-width: 0;
   }
   ```
2. **Eliminar desbordes horizontales parásitos:**
   - Asignar `overflow-x: hidden;` en `.co-body`.
   - Asegurar `min-width: 0;` en contenedores flex (`.co-split-line`, `.co-row-control`).
3. **Aplicar la nueva escala dimensional para escritorio/laptop:**
   - Incrementar `max-width` de `.co-card` a `620px`.
   - Configurar `.co-received` como `flex-wrap: nowrap;` para mantener input de dinero y billetes en una sola línea horizontal.
   - Ajustar los tokens de padding, gap y tipografía según la matriz de la Sección 3.
4. **Respetar comportamiento en teléfonos móviles:**
   - Mantener las reglas en `@media (max-width: 640px)` para que en pantallas de teléfono el modal conserve el comportamiento vertical tipo bottom-sheet.

### Fase 2: Verificación Automatizada con Playwright
1. **Crear test E2E específico:** `frontend/e2e/checkout-portatil.spec.js` bajo el proyecto `Portátil de caja` (`1366 × 611`).
2. **Aserciones automáticas requeridas:**
   ```js
   expect(body.scrollWidth).toBe(body.clientWidth); // 0 scroll horizontal
   expect(card.scrollHeight).toBeLessThanOrEqual(587); // 0 scroll vertical de tarjeta
   expect(body.scrollHeight).toBe(body.clientHeight); // 0 scroll vertical interno
   await expect(page.locator('.co-btn-primary')).toBeVisible(); // Botón Cobrar visible sin scroll
   ```
3. Comprobar tanto el flujo simple como el flujo con pago dividido y vuelto.

### Fase 3: Auditoría Visual y de Contraste
1. Ejecutar el script oficial del repositorio:
   ```bash
   node scripts/audit-contrast.cjs pos dark
   node scripts/audit-contrast.cjs pos light
   ```
   (Objetivo: 0 textos bajo WCAG AA).
2. Captura de pantalla de verificación en tema oscuro y tema claro para confirmar alineación visual y nitidez de Baloo 2.

---

## 6. Registro de Cambios Previstos
* **Archivos a modificar:**
  - `frontend/src/components/CheckoutModal.vue`: corrección de estilos CSS, layout horizontal de billetes y ajustes de densidad.
  - `frontend/e2e/checkout-portatil.spec.js`: nueva suite de pruebas E2E para certificar la no-regresión en la portátil.
  - `CHANGELOG.md`: registro del fix en la sección "Sin publicar".
  - `docs/BITACORA.md`: actualización de estado con la resolución de la incidencia.
* **Sincronización:** Ejecutar `graphify update .` tras finalizar los cambios para reflejar el impacto en el grafo.
