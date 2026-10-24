# Plan v2: Finanzas en la portátil de caja — segunda vuelta (medida con datos reales)

> **Módulo:** [Finance.vue](../frontend/src/views/Finance.vue), [components/Finance/](../frontend/src/components/Finance/), [finance.css](../frontend/src/components/Finance/finance.css) y [ReorderModal.vue](../frontend/src/components/Inventory/ReorderModal.vue).
> **Continúa:** [PLAN_REDISENO_FINANZAS_PORTATIL.md](PLAN_REDISENO_FINANZAS_PORTATIL.md) (implementado el 2026-10-20). Este plan no rehace lo que ese ya resolvió.
> **Dispositivo:** portátil de caja Toshiba (1366 × 768).
> - **1366 × 611:** Windows al 100 %.
> - **1093 × 489:** Windows al 125 %, con escala 1,25.
> - **1440 × 900 y 390 × 844:** deben quedar igual.
>
> **Versión anterior:** [PLAN_MEJORA_UI_FINANZAS_PORTATIL_v1.md](PLAN_MEJORA_UI_FINANZAS_PORTATIL_v1.md).
> **Estado (2026-10-23, 22:10):** **Fase 1 hecha y probada** (sección 7). Fases 2 a 6 pendientes, cada una con su punto de partida en la sección 7. Nada en commit ni publicado.
> **Rama:** crear `feat/finanzas-portatil-2` **desde `feat/inventario-portatil`**. Esa rama ya cambió [FinanceModal.vue](../frontend/src/components/Finance/FinanceModal.vue) (tamaños y teclado), `finance.css` y [PurchaseModal.vue](../frontend/src/components/PurchaseModal.vue). Partir de `main` haría chocar los mismos archivos.

---

## 0. Qué cambia respecto a la v1

La v1 se escribió a partir de capturas, sin medir. Esta versión midió cada pantalla y cada ventana en los dos tamaños, en claro y oscuro (sección 1). Hay tres resultados:

- **Se mantienen** tres problemas reales: Compras sugeridas, el Informe de inventario y Recetas base.
- **Se descartan** tres que ya no existen: los formularios no se salen de la pantalla y el Resumen no se cae a una columna.
- **Se agregan** cuatro que la v1 no vio: la cabecera del Resumen al 125 %, Costos y margen al 125 %, categorías preseleccionadas que ensucian el Estado de resultados, y el teclado.

| # | La v1 decía | Lo medido (1366 × 611 / 1093 × 489) | Decisión v2 |
| :-- | :-- | :-- | :-- |
| 1 | El Resumen se cae a **una columna** al 125 % | Sigue en **dos columnas** en ambos tamaños: Estado de resultados y Punto de equilibrio empiezan a la misma altura. | No se toca `.sum-grid`. |
| 2 | La cabecera del mes mide «hasta 165 px» | Mide **191 px** al 100 % y **281 px** al 125 %. A 125 % los avisos se apilan debajo de la frase y el Estado de resultados empieza a 22 px del borde inferior. Es el **63 %** del alto útil. | **Fase 2:** compactar la cabecera solo al 125 %. Es el problema más visible de Finanzas. |
| 3 | «Registrar compra o gasto» mide 510 px y no entra al 125 % | Mide **440 px** y el cuerpo **no se desplaza** en ninguno de los dos tamaños. Guardar está al alcance. | No se reacomoda en 3 filas ni se esconde el aviso de efectivo, que es el que evita contar dos veces un egreso. Sí se corrigen sus errores (Fase 1). |
| 4 | «Agregar gasto fijo» supera la pantalla al 125 % | Mide **403 px**, sin desplazamiento. | Solo se corrigen sus errores (Fase 1). |
| 5 | Controles de 32–34 px y etiquetas de 12 px | Campos de 38 px, botones de 36 px y botones chicos de 28 px, iguales en Ventas, Inventario y Control diario. Todos los formularios ya entran. | **Se mantienen.** Achicarlos rompe la coherencia con el resto del sistema y empeora el uso con touchpad sin ganar nada. |
| 6 | Compras sugeridas: KPIs de ~190 px, 1 proveedor visible | Confirmado. KPIs 96 px, aviso 38 px y cabecera de proveedor 41 px. Se ven **3 insumos** al 100 % y **1** al 125 %. Además la unidad de «Pedir» cae debajo del campo (35 px más abajo) **incluso al 100 %**. | **Fase 3**, con la unidad en la misma línea. El campo queda en 84 px y no en 64 px, porque con decimales se recortaría. |
| 7 | Informe de inventario: más de 2.000 px | El cuerpo mide **1.318 px**: 850 px de desplazamiento al 100 % y 972 px al 125 %. Las tablas siguen siendo tablas (no pasan a tarjetas antes de 760 px). | **Fase 5:** un índice «Ir a» en lugar de pestañas. Es un informe que se lee de corrido y se imprime, y las pestañas esconderían la mitad. Se descarta la parte de «no pasar a tarjetas». |
| 8 | Recetas base en 3 pasos | Es la ventana más larga del sistema: **3.344 px** de cuerpo. | Se mantiene como **Fase 6, opcional**: se usa una vez, al configurar los costos. |
| 9 | — | **Costos y margen** al 125 %: la receta de cada producto ocupa 4–5 líneas (filas de 120 px) y se ven **2 productos**. Al 100 % se ven 6. | **Fase 4 (nueva).** |
| 10 | — | **Categoría preseleccionada:**<br>• «Registrar gasto» abre con **«Helado y açaí»**.<br>• «Agregar gasto fijo» abre con **«Salarios y personal»**.<br>Un gasto de gas guardado sin mirar cae en costo de ventas y altera el margen. Es el mismo error que se corrigió en «Registrar compra». | **Fase 1 (nueva).** |
| 11 | — | El botón dice **«Registrar gasto»** y la ventana **«Registrar compra o gasto»**, junto a otro botón «Registrar compra» que hace otra cosa (líneas e inventario). | **Fase 1:** la ventana se llama «Registrar gasto». |
| 12 | — | **Teclado:** desde `feat/inventario-portatil`, `FinanceModal` lleva el foco a la ventana y no deja que Tab salga. Pero los formularios de Finanzas no marcan su primer campo, así que hay que pulsar Tab hasta llegar a él. | **Fase 1:** marcar con `data-autofocus` el primer campo de cada formulario. |
| 13 | Contraste: correr `audit-contrast.cjs` | Medido con el backend real y una copia de la base: **0 textos bajo AA** en las 6 pestañas y las 7 ventanas, en claro y oscuro. Era el pendiente del plan del 20/10. | Cerrado. Se vuelve a medir al final de cada fase. |

---

## 1. Línea base medida (2026-10-23)

**Cómo se midió:**
- **Backend:** el backend real (Laravel 13 con PHP 8.4 en WebAssembly), sobre una **copia** de la base de la Mac.
- **Datos de prueba**, cargados en la copia por la API: 12 gastos de octubre, 8 gastos fijos, 23 insumos y 2 compras.
- **Navegador:** Chromium a 1366 × 611 con escala 1 y a 1093 × 489 con escala 1,25, en claro y oscuro.

En cada caso se midió el alto del contenido, la posición de las secciones, el tamaño de cada ventana, si su cuerpo se desplaza y si los botones del pie están al alcance. El contraste se auditó recorriendo todo el contenido, no solo el primer pantallazo.

### Pestañas

Alto útil: 570 px al 100 % y 448 px al 125 %.

| Pestaña | Contenido 100 % | Contenido 125 % | Observación |
| :-- | :-- | :-- | :-- |
| Resumen | 1.448 px | 1.586 px | Cabecera del mes de **191 px** al 100 % y **281 px** al 125 %. El Estado de resultados empieza en y = 336 al 100 % y en y = 426 al 125 %, de 489. |
| PedidosYa | cabe | cabe | — |
| Compras y gastos | 954 px | 1.002 px | Bien: 4 gastos a la vista al 125 %. |
| Gastos fijos | 960 px | 990 px | Bien: 5 plantillas completas a la vista al 125 %. |
| Costos y margen | 3.257 px | 3.781 px | Se ven **6 productos** al 100 % y **2** al 125 %. Filas de 120 px por la receta en 4–5 líneas. |
| Categorías | 641 px | 884 px | Bien. |

### Ventanas

| Ventana | Tamaño 100 % | Desplaz. 100 % | Tamaño 125 % | Desplaz. 125 % | Foco al abrir |
| :-- | :-- | :-- | :-- | :-- | :-- |
| Registrar gasto | 520 × 440 | 0 | 520 × 440 | 0 | la ventana (sin campo marcado) |
| Registrar compra (líneas) | 760 × 587 | 3 | 760 × 465 | 125 | «Insumo» ✔ |
| Agregar gasto fijo | 520 × 403 | 0 | 520 × 403 | 0 | la ventana |
| Nueva categoría | 520 × 320 | 0 | 520 × 320 | 0 | la ventana |
| Informe de inventario | 760 × 587 | 850 | 760 × 465 | 972 | la ventana ✔ (es de lectura) |
| Cargar recetas base | 760 × 587 | 2.876 | 760 × 465 | 2.998 | la ventana |
| Compras sugeridas (Inventario) | 760 × 587 | 734 | 760 × 465 | 856 | la ventana ✔ |

En todas, los botones del pie están al alcance y no hay scroll horizontal. Contraste: 0 textos bajo AA.

---

## 2. Reglas para integrarse al sistema de diseño

1. **Sin componentes nuevos.**
   - Todo se hace con `FinanceModal` (tamaños `''`, `wide` y `xl`; teclado incluido), `.fin-card`, `.fin-chip`, `.fin-badge`, `.fin-note` y `.fin-btn`.
   - Si hace falta un índice o una franja de datos, se arma con `.fin-chip` y `.fin-kpis`.
2. **Tokens existentes.**
   - Colores: `--acai-*`, `--passion-*`, `--ink-900/700/500/300`, `--border`, `--surface`, `--danger-*`, `--gold-*`, `--teal-*`. No existe `--ink-400`.
   - Tipografía: Baloo 2 para títulos e importes, Inter para el texto.
   - Sin colores fijos: todo debe verse bien en `html.dark`.
3. **Alturas de control:** campos de 38 px, botones de 36 px y botones chicos de 28 px. No se achican.
4. **Lo compacto va solo bajo la regla de la portátil.**
   - `@media (min-width:761px) and (max-width:1366px), (min-width:761px) and (max-height:800px)`.
   - Lo específico del 125 % va en `(min-width:761px) and (max-height:520px)`.
   - Monitor y teléfono no cambian.
5. **Encabezados pegajosos:** un ancestro con `overflow: hidden` anula `position: sticky`; se usa `overflow: clip`. Esto también destraba el `thead` pegajoso que el plan del 20/10 había descartado en Costos.
6. **Números en `es-BO`** (`formatBs`, `formatQty`): «Bs 1.364,10», «0,5 kg».
7. **Valores por defecto honestos:** un campo que decide a dónde va la plata (categoría) empieza vacío, con «Elige una categoría…». Puede sugerir, por el proveedor, pero no elegir en silencio.
8. **Teclado:**
   - Cada formulario marca su primer campo con `data-autofocus`.
   - Las ventanas de lectura (informes, listas) no marcan nada.
   - Enter en el último campo guarda solo si el formulario ya lo hace hoy; no se agregan atajos nuevos.

---

## 3. Fases

### Fase 1 — Errores de comportamiento (chica, se puede publicar sola)

Archivos: [FinanceExpenses.vue](../frontend/src/components/Finance/FinanceExpenses.vue), [FinanceRecurring.vue](../frontend/src/components/Finance/FinanceRecurring.vue), [FinanceCategories.vue](../frontend/src/components/Finance/FinanceCategories.vue) y [CategorySelect.vue](../frontend/src/components/Finance/CategorySelect.vue) si hace falta el marcador vacío.

1. **«Registrar gasto»:**
   - La categoría empieza vacía, con «Elige una categoría…».
   - La sugerencia por proveedor (`supplierHint`) sigue funcionando: si el proveedor ya tiene una categoría habitual, se elige y se avisa.
   - Si falta, el aviso dice «Elige una categoría.».
2. **«Agregar gasto fijo»:** igual, la categoría empieza vacía. «Día de pago» queda en 1, que es un valor que no cambia la plata.
3. **Título:** «Registrar gasto» y «Editar gasto», igual que su botón. El subtítulo («Pagos hechos fuera de la caja del turno…») se queda.
4. **Foco con `data-autofocus`:**
   - Registrar gasto: «Monto».
   - Gasto fijo: «Concepto».
   - Categoría: «Nombre».
   - Recetas base: no se marca nada, porque es una revisión.
5. **Pruebas:**
   - Las dos ventanas abren sin categoría y guardar sin elegirla muestra el aviso.
   - Con un proveedor conocido se sugiere su categoría.
   - Cada formulario abre con el foco en su campo.
   - Tab no sale de la ventana.

### Fase 2 — Cabecera del Resumen al 125 %

Archivo: [FinanceMonthHero.vue](../frontend/src/components/Finance/FinanceMonthHero.vue).

- **Al 100 % no cambia:** 191 px, con los avisos a la derecha de la frase.
- **Al 125 % (`max-height: 520px`):**
  - Cada aviso pasa a **una línea**: ícono, texto corto con `text-overflow: ellipsis` y el texto completo en `title`, más su botón chico de 28 px. Ejemplos: «7 gastos fijos sin generar · Bs 6.239,00» y «3 semanas de PedidosYa sin cargar».
  - El detalle largo (qué semanas, cuánto falta descontar) se despliega con «ver detalle». No se pierde ninguna de las acciones que hoy tiene cada aviso.
  - La barra «cada Bs 100» y su leyenda se quedan, porque son la lectura del mes.
- **Meta:**
  - Cabecera de **≤ 180 px** a 1093 × 489.
  - El Estado de resultados empieza **antes de y = 330** y muestra al menos Ventas, Costo de ventas y Utilidad bruta sin desplazar.
  - Lo mismo para el Punto de equilibrio.

### Fase 3 — Compras sugeridas

Archivo: [ReorderModal.vue](../frontend/src/components/Inventory/ReorderModal.vue). Se abre desde Inventario y desde el Resumen.

- **Franja de KPIs:**
  - Las dos tarjetas (96 px) pasan a una franja `.fin-kpis` de una línea, de ≤ 44 px: «10 por reponer · 1 agotado» y «Estimado Bs 1.364,10 · 10 insumos».
  - El aviso de estimaciones (38 px) entra a la franja como una `.fin-badge` con su texto en `title` y se despliega al pulsarla.
- **Cabecera de proveedor en una línea:** nombre, «llega en 2 días · lunes y jueves» y, a la derecha, Copiar, WhatsApp y **Registrar compra** (botones chicos). A 1093 px el teléfono pasa a `title`.
- **«Pedir» en una línea:** `.rm-qty { flex-wrap: nowrap }`, con la unidad `white-space: nowrap`. El campo sigue en 84 px.
- **Pie:** sin cambios. Recalcular, Imprimir, Copiar todo y Cerrar ya están al alcance.
- **Meta:**
  - **≥ 4 insumos a la vista** al 125 % (hoy 1) y **≥ 6** al 100 % (hoy 3).
  - La unidad queda a la misma altura que el campo.

### Fase 4 — Costos y margen al 125 %

Archivo: [FinanceCosts.vue](../frontend/src/components/Finance/FinanceCosts.vue).

- En la portátil, la receta bajo el nombre del producto queda en **una línea** (`-webkit-line-clamp: 1`) con el texto completo en `title`. El detalle completo ya existe en «Receta» y en el desplegable ▸ de variantes.
- `thead` pegajoso, cambiando a `overflow: clip` el contenedor que hoy lo impide (`.fin-card` / `.fin-table-wrap`).
- **Meta:**
  - **≥ 5 productos a la vista** al 125 % (hoy 2) y **≥ 8** al 100 % (hoy 6).
  - Encabezado visible al desplazar.

### Fase 5 — Informe de inventario: índice «Ir a»

Archivo: [InventoryIndicatorsModal.vue](../frontend/src/components/Finance/InventoryIndicatorsModal.vue).

- Arriba del cuerpo, una fila pegajosa de `.fin-chip`: Indicadores · Cobertura · Quiebres · Mermas · Conteos · Valor.
- Cada chip hace `scrollIntoView` a su sección y se marca el chip de la sección visible (`IntersectionObserver`).
- El informe sigue completo y «Imprimir» no cambia.
- **Meta:** cualquier sección queda a un clic. Sin scroll horizontal. Imprimir sigue generando el mismo informe.

### Fase 6 (opcional) — Recetas base en tres pasos

Archivo: [BaseRecipesWizard.vue](../frontend/src/components/Finance/BaseRecipesWizard.vue).

- Pasos «1. Costos de componentes · 2. Recetas de productos · 3. Ajustes de opciones», con «Anterior / Siguiente».
- En el paso 3, la acción actual (`applyLabel`).
- El pie muestra el paso: «Paso 2 de 3».
- No cambia lo que se guarda.
- Solo si el dueño lo va a usar de nuevo: hoy se usa una vez, al configurar.

### Fuera de alcance (decidido)

- Reacomodar «Registrar gasto» y «Agregar gasto fijo» en otras filas: ya entran.
- Esconder el aviso de efectivo: evita doble conteo.
- Achicar campos y botones.
- Cambiar `.sum-grid`.
- Pestañas en el Informe de inventario.
- Rediseñar «Registrar compra», que ya se ajustó en Inventario (secciones 12 y 13 de [PLAN_REDISENO_INVENTARIO_PORTATIL.md](PLAN_REDISENO_INVENTARIO_PORTATIL.md)). Sus valores por defecto y su foco deben conservarse.

---

## 4. Criterios de aceptación (Playwright, `e2e/finanzas-portatil.spec.js`)

Se prueban a 1366 × 611 y a 1093 × 489 (escala 1,25), con el fixture de API de la suite actual y, al cerrar cada fase, con el backend real sobre una copia de la base.

| ID | Criterio |
| :-- | :-- |
| F1 | «Registrar gasto» y «Agregar gasto fijo» abren **sin categoría**. Guardar sin elegirla muestra «Elige una categoría.». Con un proveedor conocido se sugiere la suya. |
| F2 | El título de la ventana coincide con su botón («Registrar gasto»). |
| F3 | Al abrir, el foco está en el campo marcado (Monto, Concepto o Nombre). 25 Tab no sacan el foco de la ventana. Al cerrar, el foco vuelve al botón. |
| F4 | Resumen a 1093 × 489: cabecera ≤ 180 px. El Estado de resultados empieza antes de y = 330, con Ventas, Costo de ventas y Utilidad bruta a la vista. Al 100 % no cambia (≤ 191 px). |
| F5 | Compras sugeridas: ≥ 4 insumos a la vista al 125 % y ≥ 6 al 100 %. Unidad y campo de «Pedir» a la misma altura. |
| F6 | Costos y margen: ≥ 5 productos a la vista al 125 % y ≥ 8 al 100 %. `thead` visible tras desplazar. |
| F7 | Informe de inventario: cada chip lleva a su sección. Imprimir sigue funcionando. |
| F8 | Sin scroll horizontal en las 6 pestañas y en las ventanas, en los 4 tamaños. 1440 × 900 y 390 × 844 sin cambios. |
| F9 | Contraste: 0 textos bajo AA en claro y oscuro (pestañas y ventanas, recorriendo todo el contenido). |
| F10 | No regresión: `inventario-portatil.spec.js`, `finanzas-portatil.spec.js` y el resto de la suite en verde. `npx vite build` sin errores. |

---

## 5. Orden y publicación

1. Fase 1 (errores): media jornada. Se puede publicar sola, junto con Inventario.
2. Fase 2 (Resumen).
3. Fase 3 (Compras sugeridas). Comparte pantalla con Inventario: probar también desde allí.
4. Fase 4 (Costos).
5. Fase 5 (Informe).
6. Fase 6 solo si el dueño lo pide.

**Publicación:** solo frontend, sin migraciones. VPS por paquete (`deploy/vps/04-actualizar.sh`) y laptop por el vigilante al hacer `git push origin main`, cuando el dueño lo decida.

**Documentación:** `CHANGELOG.md` («Sin publicar») y `docs/BITACORA.md` en cada fase, y la tabla de la sección 1 actualizada con el «después».

---

## 6. Riesgos

| Riesgo | Mitigación |
| :-- | :-- |
| Otra sesión edita los mismos archivos (`FinanceModal`, `finance.css`, `PurchaseModal`). | Partir de `feat/inventario-portatil` y comprobar el md5 antes de cada cambio. |
| Quitar la categoría por defecto hace más lento registrar gastos repetidos. | La sugerencia por proveedor la completa sola. Medir que «Registrar gasto» con un proveedor conocido sigue siendo de 3 campos. |
| La cabecera compacta del 125 % esconde un aviso importante. | Cada aviso sigue en su propia línea con su botón; solo se acorta el texto, que queda completo en `title` y en «ver detalle». |
| `overflow: clip` en `.fin-card` recorta sombras o menús que hoy se salen. | Aplicarlo solo al contenedor de la tabla de Costos y revisar los menús de esa tarjeta. |

---

## 7. Estado por fase (dónde quedó, para retomar)

### Estado del repositorio (2026-10-23, 22:10)

- **Rama:** todo está **sin commit** en `feat/inventario-portatil`, que sale de `main` `62c6f51`. No se creó `feat/finanzas-portatil-2`. Nada se subió a GitHub ni al VPS.
- **Commits sugeridos al retomar, en este orden:**
  1. **Inventario, Fases 1 a 5:**
     - `views/InventoryList.vue`, `utils/inventoryLabels.js`, `components/PurchaseModal.vue`;
     - `components/Inventory/WasteModal.vue`, `components/Inventory/CountModal.vue`;
     - `components/Finance/FinanceModal.vue`;
     - la parte de `finance.css` de tamaños y foco (`.fin-modal.xl`, `.fin-modal:focus`);
     - `e2e/inventario-portatil.spec.js`, `docs/PLAN_REDISENO_INVENTARIO_PORTATIL*.md`.
  2. **Finanzas v2, Fase 1:**
     - `components/Finance/FinanceExpenses.vue`, `FinanceRecurring.vue`, `FinanceCategories.vue`, `CategorySelect.vue`;
     - la parte de `finance.css` de errores de campo (`.fin-field-err` y `.fin-input[aria-invalid]`);
     - `e2e/finanzas-portatil.spec.js`, `docs/PLAN_MEJORA_UI_FINANZAS_PORTATIL*.md`.
  3. **Herramientas:** `frontend/scripts/toshiba/` (simulación de la Toshiba, ver su README).

  `CHANGELOG.md` y `docs/BITACORA.md` van con el commit que corresponda. `finance.css` mezcla las dos fases: se puede agregar entero en el commit 1 si no se quieren separar sus líneas.
- **Para publicar:** solo frontend, sin migraciones. Primero commit; luego `git push origin main` cuando el dueño lo decida (actualiza la laptop por el vigilante); el VPS va por paquete con `deploy/vps/04-actualizar.sh`.
- **Antes de tocar cualquier archivo:** comprobar su md5 o `git diff`, porque otra sesión trabajó en paralelo sobre Inventario y Finanzas.

### Fase 1 — Errores de comportamiento: ✅ HECHA (2026-10-23)

**Qué cambió**

| Archivo | Cambio |
| :-- | :-- |
| `FinanceExpenses.vue` | • La categoría empieza vacía («Elige una categoría…»).<br>• Título «Registrar gasto» / «Editar gasto».<br>• Foco inicial en «Monto» (`data-autofocus`).<br>• Avisos junto a cada campo (fecha, monto y categoría), con `aria-invalid` y `aria-describedby`; el foco salta al primer campo con error y el aviso se borra al corregirlo.<br>• **Hallazgo y arreglo:** la sugerencia por proveedor fallaba si se escribía el proveedor antes de que llegara la lista de proveedores (carrera). Un `watch` sobre `finance.suppliers` la reintenta. |
| `FinanceRecurring.vue` | • La categoría empieza vacía.<br>• Foco en «Concepto».<br>• Avisos junto al campo (concepto, monto y categoría).<br>• Antes no se validaba la categoría en el navegador. |
| `FinanceCategories.vue` | Foco en «Nombre». |
| `CategorySelect.vue` | • Prop `errorId`: marca el selector inválido y lo enlaza a su aviso.<br>• La ventana «+ Nueva categoría…» pasa de `autofocus` a `data-autofocus`, para que el foco entre de verdad al nombre y al cerrar vuelva al selector. |
| `finance.css` | Estilos compartidos `.fin-field-err` y `.fin-input[aria-invalid="true"]`, el mismo patrón que Inventario. |

**Por qué los avisos van junto al campo:** al 125 % el aviso al final del formulario quedaba debajo del pie y no se veía. Medido con el backend real.

**Pruebas**

- **E2E:** 9 nuevas en `e2e/finanzas-portatil.spec.js`, en el bloque «Finanzas v2 — Fase 1: formularios». Cubren:
  - título, categoría vacía, aviso a la vista y foco en el selector;
  - sugerencia por proveedor, también cuando la lista llega tarde (prueba de la carrera);
  - guardar vacío deja un aviso por campo;
  - editar conserva la categoría;
  - gasto fijo;
  - foco en Nombre;
  - Tab atrapado y foco devuelto al botón;
  - «+ Nueva categoría…» devuelve el foco al selector.
- **Suite completa:** 167 en verde. La de Cobro intermitente de siempre pasó al reintentar. `npx vite build` sin errores.
- **Backend real** (`frontend/scripts/toshiba/finanzas-fase1.cjs`): 32 de 32 comprobaciones al 125 %, en claro y oscuro.
  - Las ventanas no cambiaron de tamaño: 440 y 403 px, sin desplazamiento.
  - Contraste 0 bajo AA, también con el aviso rojo a la vista.
  - Se guardó un gasto con la categoría sugerida por el proveedor y un gasto fijo con su categoría.

**Pendiente de esta fase:** solo el commit (ver arriba) y verlo en la Toshiba real.

### Fase 2 — Cabecera del Resumen al 125 %: ✅ HECHA (2026-10-23)

**Qué cambió**

| Archivo | Cambio |
| :-- | :-- |
| `FinanceMonthHero.vue` | • Se reemplazó el layout de 3 filas apiladas (`grid-template-areas: "text" "bar" "trust"`) en `@media (max-height: 520px)` por un layout compacto de 2 columnas (`minmax(0, 1.15fr) minmax(0, 1fr)`).<br>• Avisos compactos: función `shortWarningMessage(w)` que muestra un mensaje condensado en la portátil de caja y mantiene el detalle completo en `title`.<br>• Altura de la cabecera a 1093 × 489: bajó de 281/256 px a **116 px** (meta ≤ 180 px).<br>• En pantallas grandes (1440 × 900) y móviles (390 × 844) se mantiene intacto (> 300 px). |
| `finance.css` | • En `@media (min-width: 761px) and (max-height: 520px)`, reducción de márgenes y padding de `.fin-card-head` (6px 12px) y celdas de tabla (5px 12px).<br>• Ocultación del subtítulo estático redundante en cabecera de tarjeta en portátiles.<br>• El Estado de resultados y el Punto de equilibrio inician en **y = 261 px** (meta < 330 px; antes en y = 426-500 px).<br>• Quedan completamente visibles en el primer pantallazo sin scroll: Ventas, Costo de ventas, Utilidad bruta, Gastos operativos y Utilidad operativa, junto al Punto de equilibrio a la derecha. |
| `e2e/finanzas-portatil.spec.js` | Test 3b que valida la cabecera ≤ 180 px y la tarjeta y filas visibles en 1093 × 489. 37/37 pruebas en verde. |

### Fase 3 — Compras sugeridas: ⏳ PENDIENTE (en curso)

- **Dónde empezar:** [ReorderModal.vue](../frontend/src/components/Inventory/ReorderModal.vue).
  - **KPIs:** `.rm-kpis`, de 96 px; está en la plantilla cerca de la línea 187.
  - **Aviso de estimaciones:** `.fin-modal-body > .fin-note`, de 38 px.
  - **Cabecera de proveedor:** `.rm-group-head`, de 41 px.
  - **Cantidad:** `.rm-qty`, con CSS cerca de la línea 284. Ahí `flex-wrap: wrap` hace caer la unidad 35 px.
- **Línea base:** 1 insumo a la vista al 125 % y 3 al 100 %, con 10 insumos por reponer en 4 proveedores.
- **Datos de prueba:** `datos-inventario.py`.
- **Cómo medir:** `simular-inventario.cjs` (estado `11-compras-sugeridas`). La prueba de Inventario «Compras sugeridas (revisión)» exige que el foco quede en la ventana, no en una cantidad.
- **Cuidado:** se abre desde Inventario y desde el Resumen («Ver compras sugeridas» lleva a `/inventario?compras=1`). «Registrar compra» de cada proveedor abre `PurchaseModal` con `prefill`, y el foco va a «Pagado»: no romperlo.

### Fase 4 — Costos y margen al 125 %: ⏳ PENDIENTE

- **Dónde empezar:** [FinanceCosts.vue](../frontend/src/components/Finance/FinanceCosts.vue).
  - La receta bajo el nombre del producto es el `fin-cell-sub` de cada fila de producto.
  - El botón «Receta» está cerca de la línea 185.
  - El desplegable ▸ es `.fin-toggle`.
- **Línea base:** 6 productos a la vista al 100 % y 2 al 125 %, con filas de 120 px.
- **Cómo medir:** `medir-finanzas.cjs` (línea «costos»). En la suite, el test 5 exige ≥ 7 productos en 1366 × 611: subirlo a ≥ 8 y agregar uno al 125 % (≥ 5).
- **`thead` pegajoso:** cambiar a `overflow: clip` el contenedor de esa tabla, no toda `.fin-card`.

### Fase 5 — Informe de inventario: ⏳ PENDIENTE

- **Dónde empezar:** [InventoryIndicatorsModal.vue](../frontend/src/components/Finance/InventoryIndicatorsModal.vue).
  - Las secciones salen de `reportSections()` en `utils/inventoryIndicators.js`, cada una con `key` y `title`.
  - Las tablas son `.iim-section`.
  - «Imprimir» arma su propio HTML: no depende de la pantalla.
- **Línea base:** el cuerpo mide 1.318 px; se desplaza 850 px al 100 % y 972 px al 125 %.
- **Se abre** con «Informe del mes» en el Resumen.

### Fase 6 (opcional) — Recetas base en tres pasos: ⏸ SOLO SI EL DUEÑO LO PIDE

- **Dónde empezar:** [BaseRecipesWizard.vue](../frontend/src/components/Finance/BaseRecipesWizard.vue), con tres bloques `fin-label` «1. / 2. / 3.».
- **Línea base:** el cuerpo mide 3.344 px.

### Herramientas para retomar

[frontend/scripts/toshiba/](../frontend/scripts/toshiba/README.md) tiene los scripts de simulación, la carga de datos de prueba y un servidor PHP 8.4 en WebAssembly para cuando no haya PHP nativo. **Siempre sobre una copia de la base**, con las credenciales en variables de entorno.

