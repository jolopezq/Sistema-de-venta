# Plan: conteo de efectivo directo y ajustado a la portátil de caja (Cerrar caja)

> **Archivos:** [CashCloseWizard.vue](../frontend/src/components/CashCloseWizard.vue) (pasos 1 «Conteo» y 2 «Resultado»), [Turno.vue](../frontend/src/views/Turno.vue) (apertura de caja) y, en una fase opcional, [Pos.vue](../frontend/src/views/Pos.vue).
> **Origen:** análisis adjunto sobre la pantalla «Cuenta el efectivo de la gaveta», verificado contra el código y medido en navegador.
> **Reglas:** [AGENTS.md](../AGENTS.md). **Rama:** `feat/pos-caja-arranque`. **Estado (2026-10-20):** implementado (fases 0–6); pendiente prueba manual en la caja real.
> **Pedido del dueño:** ingresar los montos de forma más directa y que la pantalla se integre a la portátil de caja (Toshiba 1366 × 768, viewport útil 1366 × 611, teclado con numérico, sin pantalla táctil).

---

## 1. Qué se midió (portátil, 1366 × 611, tema claro)

Medición real con Playwright, cajero con turno abierto y efectivo esperado Bs 700:

| Paso | Alto del modal | Contenido del cuerpo | Alto visible | **Desborde vertical** |
|---|---|---|---|---|
| 1 · Conteo | 562 px | 637 px | 372 px | **265 px** |
| 2 · Resultado (faltante con nota) | 562 px | 685 px | 372 px | **313 px** |

Lo que el cajero ve hoy sin tocar nada: **6 de las 9 denominaciones** (faltan Bs 2, Bs 1 y Bs 0,50), **ni el total contado ni nada de lo que sigue**. En el paso 2 queda fuera de vista la nota del faltante y todo el reparto del fondo para mañana (el campo de monto del paso 2). La cabecera ocupa 97 px y el pie 93 px: solo 372 px de 611 son trabajo.

> El análisis adjunto estimaba un desborde de ~80 px y un ahorro de ~100 px con tarjetas más bajas. La realidad es **más del triple** (265 px) y la cuadrícula tiene 408 px (no 320) porque en 1366 px caben 3 columnas de 220 px. Reducir las tarjetas no alcanza: hay que **cambiar la forma de la pantalla**.

---

## 2. Análisis del documento adjunto

**Correcto y se adopta:** el paso 1 es el de «Conteo»; no hay navegación con Enter (no existe ningún `@keydown`); los números nativos con flechas estorban (no hay `appearance: textfield`); el progreso se guarda en `localStorage`; la barra de total hay que dejarla siempre visible; el cierre convive con el cuadre de 409 del backend.

**Se corrige o se amplía:**
1. **Magnitud del desborde** (ver §1) y que el **paso 2 también desborda** (313 px); el adjunto afirma que «caben holgadamente» sin haberlo medido.
2. **Error de datos no detectado:** el campo es `type="number"` con `v-model.number`: acepta **negativos (−3), decimales (2,5) y notación `e`**. Un −3 en Bs 200 produce un total contado **negativo** y un desglose inválido que viaja al servidor. El servidor solo valida `bill_breakdown` como `nullable|array`: no revisa sus valores ni que sumen `actual_closing`.
3. **Los botones − / + no sirven con teclado y solo estorban:** son 18 controles más (y entran en el orden de Tab). Sin pantalla táctil, el cajero con un fajo de 35 billetes escribe «35».
4. **Pedido de acceso desde el POS y desde el menú de usuario** (Fase 3 del adjunto): útil, pero **no es «ingresar montos más directo»**; se deja como fase **opcional** con aprobación aparte (§7).
5. `useManualTotal` / `manualTotalOverride` están en el script pero **ninguna pantalla los activa** (código muerto): no se toca, se anota.

---

## 3. Propuesta de diseño

El sistema visual ya existe y se conserva (Ohana: açaí `--acai-*`, pasión `--passion-*`, Baloo 2 para cifras, Inter para texto, el color real de cada billete como franja). La decisión de diseño de esta pantalla es **una sola**: pasar de 9 tarjetas a un **libro de conteo en dos columnas**, hecho para teclear, con el **total vivo en el pie**.

### 3.1 Plan de diseño (revisado contra el encargo)
- **Color:** sin paleta nueva. Fondo `--surface`; fila enfocada con `--acai-50` y aro `--acai-500`; total en barra `--acai-900` con cifra blanca; acción `--passion-500`; las 9 franjas de color de billete actuales (`#1B5E20`, `#B71C1C`, `#E65100`, `#F57F17`, `#006064`, `#4E342E`, `#546E7A`, `#546E7A`, `#78909C`).
- **Tipografía:** Baloo 2 700 para la denominación y para las cifras (con `font-variant-numeric: tabular-nums` para que los montos se alineen al punto); Inter 500 para ayudas. Sin mayúsculas de etiqueta, sin numeración decorativa (los pasos del encabezado sí son una secuencia real).
- **Maqueta del paso 1** (1366 × 611, alineado a la izquierda, cifras a la derecha):
```
┌ Cerrar caja · Turno de Ana, abierto 08:12     ✓Revisión ②Conteo ③Resultado ④Comprobante  ✕ ┐
│ Cuenta el efectivo de la gaveta        Escribe cuántas piezas hay y pulsa Enter.  ✓ 01:49  │
│ Billetes                                          Monedas                                  │
│ ▌Bs 200   [  0 ]            Bs 0,00               ▌Bs 5     [  0 ]            Bs 0,00      │
│ ▌Bs 100   [  0 ]            Bs 0,00               ▌Bs 2     [  0 ]            Bs 0,00      │
│ ▌Bs 50    [  0 ]            Bs 0,00               ▌Bs 1     [  0 ]            Bs 0,00      │
│ ▌Bs 20    [ 35 ]          Bs 700,00               ▌Bs 0,50  [  0 ]            Bs 0,00      │
│ ▌Bs 10    [  0 ]            Bs 0,00                                                         │
├──────────────────────────────────────────────────────────────────────────────────────────┤
│ [Cancelar]        Total contado  Bs 700,00 · 35 piezas              [Ver el resultado]    │
└──────────────────────────────────────────────────────────────────────────────────────────┘
```
  Billetes y monedas son grupos reales del dinero (no decoración): Bs 5 es moneda y Bs 10 billete, como hoy. La cuarta fila de la columna derecha queda libre; no se rellena.
- **Principios:** (1) el cursor siempre está en una casilla; (2) lo que ya contaste se ve «encendido» y lo demás queda en gris; (3) el total no se esconde nunca; (4) nada se mueve ni se anima salvo el número del total al cambiar.
- **Revisión frente a lo genérico:** la versión inicial (9 tarjetas con insignia, +/− y subtotal) era el «kit de tarjetas iguales». Se reemplazó por filas de libro contable que **comparten una línea de base de cifras**, que es lo que de verdad ayuda a contar y a verificar con la vista.

### 3.2 Estados de fila
| Estado | Aspecto |
|---|---|
| Vacía (0) | Casilla con «0» tenue, subtotal gris |
| Con piezas | Casilla en tinta, subtotal en tinta y negrita |
| Enfocada | Fondo `--acai-50`, borde y aro `--acai-500` (siempre visible; no solo color: también el grosor del borde) |
| Valor inválido | No existe: la casilla **no admite** signos, comas ni letras |

---

## 4. Ingreso directo con teclado (especificación)

**Casilla:** `type="text"` con `inputmode="numeric"` y `autocomplete="off"`; se filtra todo lo que no sea dígito mientras se escribe, máximo 4 cifras, sin ceros a la izquierda. Vacía se guarda como 0 (se muestra «0» tenue como placeholder). Los − / + se quitan del flujo principal (versión pequeña, `tabindex="-1"`, visible solo al pasar el mouse o con foco en la fila) para que Tab/Enter recorran solo casillas.

| Tecla | Acción |
|---|---|
| Al entrar al paso | Foco automático en Bs 200 y número seleccionado |
| Al enfocar una casilla | Selecciona todo: lo que escribes reemplaza |
| `Enter`, `↓` o `Tab` | Siguiente casilla (izquierda arriba→abajo, luego columna derecha) |
| `Shift+Enter`, `↑` o `Shift+Tab` | Casilla anterior |
| `Enter` en la última (Bs 0,50) | Pasa el foco al botón «Ver el resultado» (un segundo Enter lo activa; así un Enter perdido no cierra el paso) |
| `Ctrl+Enter` en cualquier casilla | Ir al resultado |
| `Esc` | No cierra el asistente (evita perder el contexto); se cierra con «Cancelar» o ✕ |

**Total vivo:** el pie muestra «Total contado Bs X · N piezas» y se actualiza con cada tecla; los lectores de pantalla lo anuncian con `aria-live="polite"`.

**Borrador guardado (`localStorage`):** al cargarlo se **sanea** (enteros ≥ 0, ≤ 9999) para no heredar valores inválidos de versiones anteriores. Sin cambios en el formato que se envía al servidor (`bill_breakdown`, `actual_closing`).

**Paso 2 (monto del fondo para mañana):** foco automático y selección al entrar; atajos junto al campo: «Dejar el fondo de apertura (Bs 200)», «Dejar todo lo contado» y «No dejar fondo»; Enter lleva el foco a «Cerrar caja» (sin cerrar). Si falta dinero, el foco va primero al motivo.

**Apertura de caja (`Turno.vue`):** el monto inicial ya tiene atajos de monto; se añade selección al enfocar y Enter para abrir, con la misma casilla numérica sin signos.

---

## 5. Integración con la portátil de caja

Presupuesto de alto en 1366 × 611 (disponible 587 con modal a `calc(100vh − 24px)`):

| Bloque | Hoy | Propuesto |
|---|---|---|
| Cabecera (título + subtítulo + pasos en una línea) | 97 | **≈ 64** |
| Introducción (título + instrucción + «Revisado a las…» en una fila) | 69 | **≈ 36** |
| Relleno del cuerpo | 48 | **≈ 22** |
| Cuadrícula de conteo (2 columnas × 5 filas de 40 px + rótulos) | 408 | **≈ 244** |
| Total contado | 74 (en el cuerpo) | **0** (pasa al pie) |
| Pie (total + Cancelar + Ver el resultado) | 93 | **≈ 64** |
| **Paso 1, total** | 637 + 97 + 93 | **≈ 430** de 587 → **holgura ≈ 157 px** |

- **Paso 2:** hoy 313 px de desborde. Se compacta: tarjeta izquierda (arqueo) con los cobros QR/tarjeta en **una línea desplegable**; derecha con la **diferencia en una franja horizontal** (no en caja grande), motivo del faltante y campo del fondo con sus atajos. Objetivo: **sin scroll en 1366 × 611 con faltante y nota visible** (peor caso). Si en la implementación no cabe, el cuerpo hace scroll pero **el primer campo editable y el botón principal quedan siempre visibles**.
- **Detección de la portátil:** la app ya marca `html.screen-compact` en pantallas ≤ 1366 (`utils/hardwareDetector.js`). El layout de dos columnas y las alturas anteriores son el diseño base (también válido en pantallas grandes); `html.screen-compact` aplica la densidad máxima y `@media (max-height: 520px)` cubre Windows al 125 % (≈ 1093 × 489: paso 1 ≈ 430 px cabe en 465 disponibles).
- **Responsive:** < 640 px el libro pasa a **una columna**, el pie apila total y botón (el total y «Ver el resultado» siguen siempre a la vista; el cuerpo hace scroll).
- **Contraste:** los números van en tinta sobre `--surface` (franja de color solo como identificación, no como único portador de información); revisar claro y oscuro con `scripts/audit-contrast.cjs`.

---

## 6. Pruebas

Nuevo `frontend/e2e/cierre-caja-portatil.spec.js` (proyecto «Portátil de caja»; mock de `/me`, `/cash-sessions/active` con `stats` y `/sales/sync`, como el de Cobro; **la línea base ya se midió con este mismo mock**):
1. **Cero scroll** (cuerpo ≤ 1 px de desborde, sin scroll horizontal) en paso 1 y paso 2 (faltante + nota) a 1366 × 611, 641, 697, 768, 1280 × 720 y 1093 × 489 (125 %); claro y oscuro.
2. Las **9 denominaciones, el total y los botones** visibles a la vez en 1366 × 611.
3. **Teclado:** foco inicial en Bs 200; escribir «3» + Enter → foco en Bs 100; Shift+Enter vuelve; Enter en Bs 0,50 enfoca «Ver el resultado»; Ctrl+Enter avanza; Tab recorre solo casillas.
4. **Entrada saneada:** «-3», «2,5», «e», «99999» no producen negativos, decimales ni > 9999; vacío = 0.
5. **Aritmética:** 3×200 + 5×50 + 2×0,5 = Bs 851,00 en el pie y en el paso 2; mismo total enviado en `actual_closing` y desglose.
6. **Borrador:** recargar con borrador conserva el conteo; un borrador con valores inválidos se sanea.
7. **Móvil 390 × 844:** una columna, sin scroll horizontal, total y botón visibles.
8. Auditoría de contraste claro/oscuro del modal.

Unitarias: el filtro de entrada y la suma van a `frontend/src/utils/cashCount.js` (funciones puras, p. ej. `sanitizeCount`, `countTotal`) con prueba `node --test`.

**Fase 0:** escribir los tests 1 y 2 y ver que **fallan hoy** (desborde 265 / 313 px).

---

## 7. Fases

| Fase | Trabajo | Aprobación |
|---|---|---|
| 0 | Tests rojos (desborde y 9 denominaciones visibles) | — |
| 1 | Libro de conteo en 2 columnas, total en el pie, cabecera/introducción compactas | este plan |
| 2 | Casilla numérica saneada, foco automático, Enter/↑/↓/Ctrl+Enter, `−`/`+` fuera del flujo, saneo del borrador | este plan |
| 3 | Paso 2 compacto + foco, atajos y Enter en el fondo para mañana; Enter en el monto de apertura (`Turno.vue`) | este plan |
| 4 | Pruebas completas, contraste, capturas 1366 × 611 claro/oscuro, `CHANGELOG.md`, `docs/BITACORA.md`, `graphify update .` | — |
| 5 *(opcional)* | **Cerrar caja desde Vender** (botón en el ticket que abre el mismo asistente) y punto de turno en el menú de usuario | **requiere tu OK** (toca `Pos.vue` y `AppShell.vue`) |
| 6 *(opcional)* | Endurecer el servidor: `bill_breakdown` solo enteros ≥ 0 con denominaciones válidas y suma = `actual_closing` (±0,01) | **requiere tu OK** (cambio de backend y pruebas PHPUnit) |

## 8. Riesgos y preguntas abiertas
| Tema | Mitigación / pregunta |
|---|---|
| El cajero ya conoce las tarjetas actuales | El cambio es de forma, no de datos ni de pasos; sigue siendo la misma secuencia Revisión → Conteo → Resultado → Comprobante. |
| Un Enter de más avanza el paso | Enter en la última casilla solo enfoca el botón; hace falta un segundo Enter. |
| ¿Conviene permitir **escribir solo el total** sin contar billetes (el código muerto `useManualTotal`)? | **Pregunta:** hoy no existe en pantalla; implicaría enviar el cierre sin desglose y perder la auditoría por billete. Recomiendo **no** hacerlo. |
| ¿Fases 5 y 6? | **Pregunta:** ¿las incluimos? La 5 acorta el camino diario; la 6 cierra el hueco de validación del servidor. |
| Escala de Windows de la Toshiba (100 % o 125 %) | El diseño cabe en ambos; confirmar cuál usa para fijar la prueba de referencia. |

## 9. Archivos
- Modificar: `frontend/src/components/CashCloseWizard.vue`, `frontend/src/views/Turno.vue` (solo teclado/foco en el monto de apertura).
- Crear: `frontend/src/utils/cashCount.js` (+ prueba), `frontend/e2e/cierre-caja-portatil.spec.js`.
- Opcionales (fases 5 y 6): `Pos.vue`, `AppShell.vue`, `CashRegisterSessionController.php` y su prueba.
- Actualizar: `CHANGELOG.md`, `docs/BITACORA.md`, grafo (`graphify update .`).
