# Plan v2: Inventario en la portátil de caja, integrado al sistema de diseño

> **Módulos:** [InventoryList.vue](../frontend/src/views/InventoryList.vue) (pantalla, modales de insumo, categorías, proveedores, confirmación y aviso), [FinanceModal.vue](../frontend/src/components/Finance/FinanceModal.vue) (tamaños y, desde la Fase 5, teclado), [finance.css](../frontend/src/components/Finance/finance.css), [inventoryLabels.js](../frontend/src/utils/inventoryLabels.js).
> **Sin cambios de diseño (ya cumplen):** [PurchaseModal.vue](../frontend/src/components/PurchaseModal.vue) (se corrigieron sus valores por defecto, sección 12, y su campo inicial, sección 13), [WasteModal.vue](../frontend/src/components/Inventory/WasteModal.vue), [CountModal.vue](../frontend/src/components/Inventory/CountModal.vue), [ReorderModal.vue](../frontend/src/components/Inventory/ReorderModal.vue), [MovementHistoryModal.vue](../frontend/src/components/Inventory/MovementHistoryModal.vue), [WasteReportModal.vue](../frontend/src/components/Inventory/WasteReportModal.vue).
> **Reglas:** [AGENTS.md](../AGENTS.md) y [.agents/rules/buenas-practicas.md](../.agents/rules/buenas-practicas.md).
> **Versión anterior:** [PLAN_REDISENO_INVENTARIO_PORTATIL_v1.md](PLAN_REDISENO_INVENTARIO_PORTATIL_v1.md).
> **Rama:** `feat/inventario-portatil` (desde `main` `62c6f51`).
> **Estado:** decisiones tomadas (sección 7). **Fases 1 a 5 hechas y probadas el 2026-10-23** (secciones 8 a 13); queda publicar y que el dueño lo vea en la Toshiba real. Las cifras de las secciones 1 y 2 se midieron con Playwright sobre el código actual (25 insumos simulados, 6 categorías, 4 proveedores), a 1366×611, 1093×489, 1440×900 y 390×844.

---

## 0. Qué cambia respecto a la v1

| # | La v1 decía | Lo medido | Efecto en el plan |
| :-- | :-- | :-- | :-- |
| 1 | El modal de insumo se corta **por arriba**. | Se corta **arriba y abajo**: mide **1026 px** (1058 al editar) y **«Guardar» queda fuera de alcance en todas las pantallas**, incluso en escritorio 1440×900 (empieza en −63 px y Guardar está en 939 px) y en teléfono. El fondo no tiene scroll, así que no hay forma de llegar al botón. | Pasa a ser **falla bloqueante en todo el sistema**, no solo en la portátil. Fase 1 independiente y primera. |
| 2 | ~850 px y 16 campos; en 2 columnas bajaría a ~430 px. | 18 campos más 5 textos de ayuda. En 2 columnas de 760 px el cálculo da ≈ 640 px, que no entra en 570. | **3 columnas** en un modal de 920 px desde 1024 px de ancho (sección 4.3). |
| 3 | El modal de Proveedores no se mencionaba como roto. | Mide 637 px: se corta **13 px** a 100 % y **74 px** a 125 %. | Entra en la Fase 2 con la misma estructura. |
| 4 | La columna Acciones necesita 550 px y **desborda a lo ancho**. | No desborda: **se parte en dos líneas** (100 %) o **en columna** (125 %). Cada fila mide **85 px** (100 %) y **213 px** (125 %). En la primera pantalla se ven **2 filas** (100 %) y **ninguna** (125 %). | El objetivo pasa a ser filas visibles por pantalla (sección 5). |
| 5 | — | En teléfono la tabla se **recorta**: mide 741 px en un contenedor de 264 px con `overflow: hidden`, y las columnas de la derecha no se pueden ver. | `overflow-x: auto` con la primera columna fija. |
| 6 | Cabecera de 8 botones en 2–3 líneas. | 162 px de alto, 2 líneas en 1366 y 1093; la tabla empieza a 281 px de 570 útiles. En teléfono, 8 líneas (479 px). | Cabecera de una línea (sección 4.1). |
| 7 | — | **Los modales de confirmar y de aviso** de la pantalla también son inline (`.modal-backdrop`). | Se migran con el resto. |
| 8 | — | **Tokens inexistentes**: `--ink-400` (en `.text-muted` y en el ícono de búsqueda), `--danger-300`, `--danger-50` y `--danger-pale`. Por eso la línea «Unidad: kg · proveedor» sale **en negrita oscura** en lugar de gris. Colores fijos de Material en las insignias (`#e3f2fd`, `#f3e5f5`) y `#f59e0b` en «Atención», sin modo oscuro. | Sección 3: reemplazo por tokens de marca. |
| 9 | — | El tipo de insumo aparece crudo: `no_perecedero`, `material_empaque`. | Etiquetas legibles en `inventoryLabels.js`. |
| 10 | Ruta `/inventory`, menú de 220 px. | La ruta es `/inventario`. El menú mide **175 px** en la portátil y 216 en escritorio; el área de contenido es de **1191 px** (100 %) y **918 px** (125 %). | Cifras corregidas. |
| 11 | Se proponen clases nuevas (`modal-head`, `modal-body`…). | Ya existe `FinanceModal` y **los otros 5 modales de Inventario ya lo usan**: entran en pantalla en los 4 tamaños, con el pie visible. | Se reutiliza `FinanceModal` con el mismo patrón de contenedor; no se crean clases nuevas. |

---

## 1. Medición del estado actual

### 1.1 Pantalla

| Medida | 1366 × 611 | 1093 × 489 | 1440 × 900 | 390 × 844 |
| :-- | :-- | :-- | :-- | :-- |
| Área de contenido (ancho × alto) | 1191 × 570 | 918 × 448 | 1224 × 847 | 326 × 803 |
| Alto de la cabecera / líneas de botones | 162 / 2 | 164 / 2 | 162 / 2 | 479 / 8 |
| Inicio de la tabla | 281 | 283 | 281 | 598 |
| Alto de fila | 85 | 213 | 85 | 213 |
| Filas visibles en la primera pantalla | **2** | **0** | 6 | 0 |
| Ancho de la celda Acciones | 292 | 165 | 307 | 112 |
| Tabla recortada (ancho real / visible) | no | no | no | **741 / 264** |

### 1.2 Modales

| Modal | 1366 × 611 | 1093 × 489 | 1440 × 900 | 390 × 844 |
| :-- | :-- | :-- | :-- | :-- |
| Insumo nuevo (inline) | **−207 a 819**, Guardar inalcanzable | **−268 a 758** | **−63 a 963** | **−126 a 970** |
| Editar insumo (inline) | −223 a 835 | −284 a 774 | −79 a 979 | −149 a 993 |
| Categorías (inline) | 73 a 538 | 12 a 477 | ok | ok |
| Proveedores (inline) | **−13** a 624 | **−74** a 563 | ok | ok |
| Compra, Merma, Conteo, Sugeridas, Mermas del mes (`FinanceModal`) | dentro, pie visible | dentro, pie visible | dentro | dentro (hoja inferior) |

---

## 2. Causas

1. **Modales inline sin límite de alto.** `.modal-backdrop` centra con flex y `.modal-content` no tiene `max-height` ni scroll. Todo lo que excede la pantalla queda fuera, arriba y abajo, y el fondo fijo no se desplaza.
2. **Una sola columna de 480 px** para 18 campos con textos de ayuda.
3. **Acciones con texto en una celda `display: flex` puesta en línea sobre el `<td>`.** Seis botones con texto no caben en 165–292 px y se parten. Además, `display: flex` en una celda de tabla rompe su comportamiento de celda.
4. **Cabecera con 8 acciones del mismo peso**, más el título y una descripción, en una fila que se parte.
5. **`.table-container { overflow: hidden }`**: recorta en vez de desplazar.
6. **Tokens fuera del sistema** (sección 3.1).

---

## 3. Impacto en el sistema de diseño

### 3.1 Diagnóstico

| Aspecto | Inventario hoy | Sistema (Ventas, Finanzas, Control diario) |
| :-- | :-- | :-- |
| Modales | 5 inline con CSS propio, sin ✕ ni Escape. | `FinanceModal`: cabecera con ✕, Escape, cuerpo con scroll y pie fijo. Lo usan los 5 modales hijos de Inventario y Finanzas. |
| Íconos | Emojis en la cabecera (📋 🚚 📂 📊 ⚖️ 🗑️ 🛒). | Íconos de trazo SVG (menú lateral, herramientas de la comanda de Control diario, y las propias acciones de la tabla de Inventario). |
| Acciones por fila | Botones con texto de 11 px. | Herramientas de 32 × 32 con `aria-label` (fila de comanda de Control diario). |
| Texto secundario | `--ink-400` (no existe): sale en el color del texto principal. | `--ink-500`. |
| Insignias | Azul y lila de Material, fijos. | `--teal-100/--teal-700`, `--acai-50/--acai-text` (con modo oscuro). |
| Estados de reposición | `rp-chip` con tokens de marca (bien), más fila entera teñida de rojo y un ⚠️. | Píldoras de estado. El teñido de fila tapa el chip «Crítico» (mismo fondo). |
| Títulos de sección del formulario | Mayúsculas con espaciado. | Finanzas y Control diario usan títulos en minúscula inicial. |

### 3.2 Decisiones
1. **Un solo modal en todo Inventario:** `FinanceModal`, con el patrón de contenedor que ya usan los modales hijos (`<div class="fin-page inv-modal-host">` + `import finance.css`). Se agrega a `FinanceModal` un tamaño `xl` (920 px) junto al `wide` actual (760 px): un cambio de una prop que sirve a cualquier formulario grande.
2. **Acciones primarias en passion, el resto sobrio:** «+ Nuevo insumo» es el único botón naranja (`--passion-500` con `--on-accent`), igual que «Cobrar» y «Registrar venta». «Registrar compra», «Merma» y «Conteo» van con borde; «Compras sugeridas» lleva su contador en passion.
3. **El elemento propio de Inventario: el nivel de stock.** Debajo del número de stock, una barra de 4 px que va de 0 al doble del punto de reorden, con una marca en el punto de reorden, coloreada por estado (`--lime-500` ok, `--gold-500` reordenar, `--danger-500` crítico o agotado). Reemplaza al ⚠️ y al teñido de toda la fila (que pasa a una franja de 3 px a la izquierda en el color del estado). Es la única pieza nueva con carácter. Responde a la pregunta de la pantalla («¿cuánto queda antes de pedir?») y usa datos que ya llegan (`current_stock`, `reorder_point`, `status`).
4. **Íconos de trazo en lugar de emojis**, del mismo juego que ya está en las acciones de la tabla.
5. **Textos:** «Inventario» como título (en la portátil se oculta la descripción, igual que en Control diario). Las acciones dicen qué hacen: «Registrar compra», «Registrar merma», «Contar stock», «Nuevo insumo». Los tipos se muestran «Perecedero», «No perecedero», «Empaque», «Otros». El modal de borrado dice qué se borra («¿Eliminar Granola? Se quita del catálogo; su historial se conserva.») y el botón dice «Eliminar», no «Sí, Continuar».
6. **Tokens:** `--ink-400` → `--ink-500`; `--danger-300/-50/-pale` → `--danger-100` y `--danger-text`; insignias de categoría `--teal-100/--teal-700`, de tipo `--acai-50/--acai-text`; título de aviso `--gold-text`. Se borra `.icon-action-btn` (CSS sin uso).
7. **Movimiento:** ninguno nuevo. El nivel de stock no se anima.

---

## 4. Diseño propuesto

Reglas de la portátil, las mismas del resto del sistema: `@media (min-width:761px) and (max-width:1366px), (min-width:761px) and (max-height:800px)` y `(min-width:761px) and (max-height:520px)` para 125 %. Escritorio y teléfono se ajustan donde hoy fallan (modal de insumo y tabla recortada).

### 4.1 Cabecera y filtros (de 162 + 76 px a ≈ 96 px)

```
1366 × 611 (contenido 1191 px)
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│ Inventario        [Compras sugeridas ⑥] [Contar stock] [Registrar merma] [Registrar compra] │
│                                                            [+ Nuevo insumo] [Más ▾]        │  ← una sola línea en 1191 px
├──────────────────────────────────────────────────────────────────────────────────────────┤
│ [ Buscar insumo…                ]  [ Todas las categorías ▾ ]                              │
└──────────────────────────────────────────────────────────────────────────────────────────┘
«Más ▾»: Proveedores · Categorías · Mermas del mes
```
- Título y acciones en una fila; descripción oculta en la portátil.
- **«Más»** agrupa lo que no es del día a día (Proveedores, Categorías, Mermas del mes). Es un menú pequeño propio (botón con `aria-expanded`, lista de 3 opciones, se cierra con Escape y al tocar fuera), sin dependencias nuevas.
- A 125 % (918 px) las etiquetas se acortan a «Sugeridas ⑥», «Contar», «Merma», «Compra» y «Nuevo». Si aun así no entran, «Contar» y «Merma» pasan a «Más».
- Teléfono: título arriba; «+ Nuevo insumo» y «Más» a la derecha; el resto entra en «Más».

### 4.2 Tabla (de 85/213 px por fila a ≈ 56 px)

```
┌───────────────────────┬────────────────┬──────────────┬────────────────────────┬──────────┬──────────────┐
│ Insumo                │ Categoría      │ Stock        │ Reposición             │ Costo    │              │
├───────────────────────┼────────────────┼──────────────┼────────────────────────┼──────────┼──────────────┤
▌Granola                │ Lácteos        │ 3,70 kg      │ Reordenar              │ Bs 9,00  │ ⊕ △ ⚖ ◷ ✎ 🗑 │
▌kg · Frutería Don Pepe │ No perecedero  │ ▬▬▬▬▬|──     │ reorden en 6,5 · 3,2 d │ prom 9,40│              │
└───────────────────────┴────────────────┴──────────────┴────────────────────────┴──────────┴──────────────┘
▌ franja de estado (3 px)        ▬▬|── nivel de stock con marca en el punto de reorden
```
- **Acciones:** seis herramientas de 32 × 32 px con íconos de trazo (Registrar compra, Merma, Contar, Historial, Editar y Eliminar), con `aria-label` y `title`. Ocupan ≈ 202 px en una línea. Van dentro de un `<div>`; la celda vuelve a ser celda.
- **Costo y CPP en una columna:** el costo unitario arriba y «prom. 9,40» debajo en `--ink-500`. Se gana una columna de ancho.
- **Stock** con su unidad y el nivel debajo.
- **Reposición:** la píldora de estado más una línea de nota (sin partirse en tres).
- **Contenedor:** `overflow-x: auto`. En teléfono la primera columna queda fija (`position: sticky; left: 0`) para no perder qué insumo se mira.
- **Encabezados de la tabla:** se mantienen en mayúsculas pequeñas, como en las tablas de Finanzas (consistencia antes que preferencia).

### 4.3 Modal de insumo (de 1026 px, roto, a ≈ 510 px)

`FinanceModal size="xl"` (920 px). Tres columnas desde 1024 px de ancho; dos entre 761 y 1023; una en teléfono.

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│ Nuevo insumo                                                                       ✕ │
│ Lo que se usa en las recetas: cómo se compra y cuándo reponerlo.                     │
├────────────────────────────┬────────────────────────────┬────────────────────────────┤
│ Insumo                     │ Compra                     │ Reposición                 │
│ Nombre [               ]   │ Proveedor [          ▾]    │ Cómo se repone [       ▾]  │
│ Unidad [kg ▾] Tipo [Per ▾] │ Se compra en [caja]        │ Consumo diario (kg) [   ]  │
│ Categoría [           ▾]   │ kg por caja [10]           │ Punto de reorden (kg) [0]  │
│ ☐ Insumo clave             │ Pedido mínimo (kg) [  ]    │  con 0 lo calcula el sist. │
│ Stock inicial [0] Costo [0]│ Vence el [dd/mm/aaaa]      │ Cobertura [7] Seguridad [2]│
│                            │  avisa 2 días antes        │ Vida útil (días) [   ]     │
├────────────────────────────┴────────────────────────────┴────────────────────────────┤
│                                                       [Cancelar]  [Guardar insumo]   │
└──────────────────────────────────────────────────────────────────────────────────────┘
```
- **Columnas por tarea:** qué es, cómo se compra, cuándo reponer. «Vence el» va con la compra, porque es del lote que está en el estante.
- **Pares en la misma fila:** Unidad y Tipo, Stock y Costo, Cobertura y Seguridad. Las ayudas se acortan y quedan en una línea bajo su campo.
- **Errores de validación:** junto al campo, y el cuerpo se desplaza al primero.
- **Editar:** el stock queda deshabilitado y explica que cambia con Ingreso, Merma o Conteo.
- **Estimación:** cuerpo ≈ 390 px + cabecera 56 + pie 56 ≈ 510 px. **Entra sin scroll interno a 1366 × 611** (546 px disponibles con 12 px de margen). A 125 % el cuerpo desplaza ≈ 80 px con cabecera y pie fijos. Se verifica en la Fase 1 (criterio INV-2).

### 4.4 Categorías, proveedores, confirmación y aviso
- **Categorías:** `FinanceModal wide`; lista con scroll interno a la izquierda y formulario a la derecha (ya es de dos columnas).
- **Proveedores:** `FinanceModal wide`; tabla con scroll interno arriba y formulario de alta debajo en dos columnas (hoy se corta 13 y 74 px).
- **Confirmar y aviso:** `FinanceModal` angosto con el mismo aspecto que `FinanceConfirm` de Finanzas (`fin-btn-danger` para borrar). No se usa el store de Finanzas: el componente es local.

### 4.5 Lo que no cambia
- Los 5 modales hijos (compra, merma, conteo, sugeridas, historial, mermas del mes) ya cumplen; no se tocan.
- La lógica de inventario, las llamadas a la API y el backend no cambian. Es un cambio solo de pantalla.

---

## 5. Criterios medibles (se convierten en `e2e/inventario-portatil.spec.js`)

| ID | Criterio | 1366 × 611 | 1093 × 489 | 1440 × 900 | 390 × 844 |
| :-- | :-- | :-- | :-- | :-- | :-- |
| INV-1 | Modal de insumo (nuevo y editar): título, ✕ y **Guardar** dentro de la pantalla, y `elementFromPoint` sobre Guardar devuelve el botón. | ✔ | ✔ | ✔ | ✔ |
| INV-2 | El cuerpo del modal de insumo no necesita scroll. | ✔ | scroll ≤ 120 px | ✔ | libre |
| INV-3 | Escape y ✕ cierran todos los modales de la pantalla; con un error de validación, Guardar sigue visible. | ✔ | ✔ | ✔ | ✔ |
| INV-4 | Categorías y Proveedores: el borde superior ≥ 0 y el pie visible. | ✔ | ✔ | ✔ | ✔ |
| INV-5 | La cabecera ocupa una sola línea de acciones y la tabla empieza a ≤ 150 px del borde del área de contenido. | ✔ | ≤ 160 px | ✔ | — |
| INV-6 | Filas visibles en la primera pantalla (25 insumos). | ≥ 6 | ≥ 3 | ≥ 10 | ≥ 2 |
| INV-7 | Alto de fila ≤ 64 px y acciones en una línea. | ✔ | ✔ | ✔ | — |
| INV-8 | Sin desborde de la ventana; la tabla se desplaza dentro de su contenedor y la primera columna queda visible. | ✔ | ✔ | ✔ | ✔ |
| INV-9 | Herramientas de fila ≥ 32 × 32 px (≥ 44 en teléfono), cada una con `aria-label`. | ✔ | ✔ | ✔ | ✔ |
| INV-10 | Sin tokens inexistentes en `InventoryList.vue` (`--ink-400`, `--danger-300`, `--danger-50`, `--danger-pale`) ni colores fijos fuera de tokens. Contraste AA en claro y oscuro. | ✔ | ✔ | ✔ | ✔ |
| INV-11 | No regresión: `layout-scroll.spec.js` en verde (incluye `/inventario`). | ✔ | ✔ | ✔ | ✔ |

---

## 6. Fases

### Fase 1 — Modal de insumo (bloqueante, se puede publicar sola)
1. `FinanceModal`: prop `size` (`'md'` por defecto, `'wide'` 760, `'xl'` 920), conservando `wide` por compatibilidad.
2. Modal de insumo con `FinanceModal size="xl"` dentro de `<div class="fin-page inv-modal-host">`, con tres columnas, ayudas cortas y «Guardar insumo» en el pie.
3. Pruebas INV-1, INV-2 e INV-3 (parcial).

### Fase 2 — Categorías, Proveedores, confirmación y aviso
Los cuatro modales restantes a `FinanceModal` (INV-3, INV-4). Se borran `.modal-backdrop` y `.modal-content`.

### Fase 3 — Cabecera
Una línea, menú «Más», íconos de trazo, título y descripción para la portátil (INV-5).

### Fase 4 — Tabla
Herramientas de 32 px, Costo + CPP en una columna, nivel de stock, franja de estado, etiquetas de tipo, `overflow-x: auto` con la primera columna fija (INV-6 a INV-9).

### Fase 5 — Tokens y pruebas
Reemplazo de tokens (INV-10), `e2e/inventario-portatil.spec.js` con INV-1 a INV-11 en los cuatro tamaños, `layout-scroll.spec.js`, `npx vite build`, `scripts/audit-contrast.cjs` en claro y oscuro. Actualizar `CHANGELOG.md` («Sin publicar») y `docs/BITACORA.md`.

**Publicación:** solo frontend, sin migraciones. VPS por paquete (`deploy/vps/04-actualizar.sh`) y laptop por el vigilante al hacer `git push origin main`.

---

## 7. Decisiones (tomadas el 2026-10-23)

Criterios: lo frecuente a la vista y lo administrativo en un menú; el estado no se comunica solo con color; un único componente de modal.

| Tema | Decisión | Alternativa descartada |
| :-- | :-- | :-- |
| Menú «Más» | **Sí:** Proveedores, Categorías y Mermas del mes adentro (tareas de administración, no del turno). | Dejarlos a la vista en una segunda fila (la cabecera sigue en 2 líneas). |
| Nivel de stock y franja de estado | **Sí:** reemplazan al ⚠️ y al teñido de fila; el estado se sigue leyendo en el texto del chip. | Mantener el teñido (más llamativo, pero tapa el chip «Crítico»). |
| Tamaño `xl` en `FinanceModal` | **Sí:** prop `size` (`''`, `'wide'`, `'xl'`), compatible con `wide`. | Estilo local del modal de insumo. |
| Deuda de diseño | **Queda como deuda:** `FinanceModal` sigue dependiendo de `.fin-page`; moverlo a un `AppModal` global es un trabajo aparte. | Hacerlo ahora (toca Finanzas y los 5 modales hijos). |

---

## 8. Fase 1 implementada (2026-10-23)

**Cambios**
- `FinanceModal.vue`: prop `size` (`''` 520 px, `'wide'` 760 px, `'xl'` 920 px). `finance.css`: `.fin-modal.xl` y su versión de hoja inferior en teléfono.
- `InventoryList.vue`: el modal de insumo pasa a `FinanceModal size="xl"` dentro de `<div class="fin-page inv-modal-host">` (`display: contents`), con los campos del sistema (`fin-field`, `fin-input`, `fin-hint`, `fin-btn`).
  - Tres columnas: Insumo, Compra y Reposición. Dos columnas entre 761 y 1023 px, una en teléfono. Unidad y Tipo en 40/60 para que «No perecedero» se lea completo.
  - Título «Nuevo insumo» o «Editar Granola». El botón dice **«Guardar insumo»** y el formulario se envía también con Enter.
  - Foco en Nombre al abrir. Errores del servidor junto a su campo (`aria-invalid`, `aria-describedby`), y el foco va al primero.
  - Mientras guarda: botón «Guardando…» deshabilitado, sin doble envío, y no se cierra con Escape ni con ✕.
  - Stock bloqueado al editar, con la explicación asociada al campo. Etiquetas de tipo legibles en el selector.
  - Los avisos viejos (`.modal-backdrop`) suben a la capa 210 para verse sobre el formulario hasta la Fase 2.

**Medidas**

| Pantalla | Modal (arriba – abajo, alto) | Scroll interno | Antes |
| :-- | :-- | :-- | :-- |
| 1366 × 611 | 27 – 584, 558 px | **0** | −207 – 819, Guardar inalcanzable |
| 1093 × 489 | 12 – 477, 465 px | 83 px (cabecera y pie fijos) | −268 – 758 |
| 1440 × 900 | 156 – 744, 589 px | 0 | −63 – 963 |
| 390 × 844 | hoja inferior 68 – 844 | sí, con pie fijo | −126 – 970 |

**Pruebas:** `frontend/e2e/inventario-portatil.spec.js` con 15 casos:
- INV-1 e INV-2 en los cuatro tamaños, nuevo y editar.
- Tres columnas y foco inicial.
- INV-3 (Escape y ✕).
- Crear con Enter y verificar los datos enviados.
- Error del servidor a 125 % (campo marcado, foco y Guardar visible).
- Sin doble envío.
- Editar con PUT y stock bloqueado.
- «No perecedero» legible.

No regresión: `layout-scroll`, `finanzas-portatil`, `cierre-caja-portatil`, `control-diario-apilado` y `checkout-portatil` en verde. Una prueba distinta de Cobro excede el tiempo en algunas corridas largas y pasa al repetir (ya ocurría antes; Cobro no usa `FinanceModal`). Compilación correcta.

---

## 9. Fase 2 implementada (2026-10-23)

**Cambios en `InventoryList.vue`**
- **Categorías y Proveedores** con `FinanceModal size="wide"`: lista a la izquierda (scroll propio, herramientas de 32 px para editar y eliminar con `aria-label`, la fila en edición resaltada) y formulario a la derecha con campos del sistema.
  - **Acción principal en el pie** («Crear categoría», «Crear proveedor», «Guardar cambios»), junto a «Cancelar edición» y «Cerrar». Al medir, el botón dentro del formulario quedaba bajo el pliegue a 125 % y en teléfono; en el pie siempre está a la vista.
  - Categorías tiene su propio estado de errores (`categoryErrors`): antes compartía `formErrors` con el insumo y los errores aparecían en el otro formulario.
  - Sin doble envío (`savingCategory`, `savingSupplier`).
- **Confirmación y aviso** con `FinanceModal`, al final de la plantilla para quedar encima de cualquier otro modal (Escape cierra solo el de arriba).
  - La confirmación nombra lo que se borra («¿Eliminar «Granola» del catálogo de insumos?») y el botón dice la acción («Eliminar insumo»).
  - El foco queda en «Cancelar». La ventana **espera a que termine** el borrado («Eliminando…») y no se cierra mientras tanto.
  - Los avisos tienen título según el caso («No se pudo eliminar», «No se pudo guardar», «Compra registrada con un aviso») y el foco en «Entendido».
- **Se borraron** `.modal-backdrop`, `.modal-content` y el CSS que solo usaban los modales viejos. También `.icon-action-btn`, que no se usaba, con sus colores fijos.

**Medidas:** Categorías y Proveedores quedan dentro de la pantalla, con el pie visible, en 1366 × 611, 1093 × 489, 1440 × 900 y 390 × 844 (INV-4).

**Pruebas:** `e2e/inventario-portatil.spec.js` suma 9 casos (24 en total):
- INV-4 en los cuatro tamaños.
- Crear y editar categoría, y que sus errores no se mezclen con los del insumo.
- Crear proveedor.
- Borrado de insumo con foco en Cancelar y espera.
- Fallo de borrado desde Categorías: el aviso queda encima y Escape cierra solo el de arriba.

No regresión: `layout-scroll` (8) y `finanzas-portatil` (27, comparte `FinanceModal`) en verde. Compilación correcta.

---

## 10. Fase 3 implementada (2026-10-23)

Hecha en otra sesión de trabajo sobre la misma rama; verificada aquí (pruebas y capturas).
- **Cabecera en una línea:** «Inventario» y las acciones del turno (Compras sugeridas con su contador, Contar stock, Registrar merma, Registrar compra), el menú **«Más»** y **«Nuevo insumo»**, el único botón naranja.
- **Íconos de trazo** en lugar de emojis. A ≤ 1250 px las etiquetas se acortan («Sugeridas», «Contar», «Merma», «Compra»).
- **Menú «Más»** (Proveedores, Categorías, Mermas del mes), con el patrón de botón de menú:
  - `aria-haspopup` y `aria-expanded`;
  - al abrir, el foco va a la primera opción;
  - flechas, Inicio y Fin recorren las opciones;
  - Escape cierra y devuelve el foco al botón; un clic fuera también cierra.
- **Teléfono:** título, «Más» y «Nuevo» arriba; las acciones del turno en una franja que se desliza, con botones de 44 px.
- **Pruebas (6):**
  - INV-5 a 1366 y a 1093: acciones en una línea y la tabla a ≤ 150 y ≤ 160 px, sin emojis;
  - teclado y clic fuera del menú;
  - «Mermas del mes» abre el reporte;
  - teléfono.

## 11. Fase 4 implementada (2026-10-23)

**Cambios**
- **Columnas:** Insumo | Categoría | Stock | Reposición | Costo | Acciones. Costo y CPP comparten celda («Bs 9.00» y «prom. 9.40» debajo).
- **Insumo:** el nombre va en un `<th scope="row">`; debajo, «kg · proveedor» en `--ink-500` (antes salía en negrita oscura por `--ink-400`).
- **Categoría:** píldora `--teal-100/--teal-700` y el tipo legible debajo («No perecedero»; `TYPE_LABEL` en `inventoryLabels.js`).
- **Stock:** cantidad con unidad y una **barra de nivel** de 0 al doble del punto de reorden, con la marca en el punto. Es verde, dorada o roja según el estado y no tiene barra si el insumo no tiene datos o es de compra diaria.
- **Sin ⚠️ ni fila teñida:** las filas que piden atención llevan una **franja de 3 px** a la izquierda (dorada al reordenar, roja si está crítico o agotado). El estado se sigue leyendo en la píldora.
- **Reposición:** píldora y nota en una sola línea (con «…» si no entra; el texto completo queda en el `title`).
- **Acciones:** seis herramientas de 32 × 32 px (44 en teléfono) con íconos de trazo y `aria-label` con el nombre del insumo («Registrar merma de Granola»). La celda ya no es `display: flex`.
- **≤ 1200 px (125 %):** la columna Categoría se oculta y pasa a la línea del insumo («Lácteos · kg · Frutería Don Pepe»). Insumo y Reposición tienen topes de 220 y 190 px.
- **Contenedor `overflow-x: auto`:** en teléfono la tabla se desliza y el nombre queda fijo (`position: sticky`).
- **CSS sin uso eliminado:** `.badge`, `.cat-badge` y `.type-badge` (colores de Material), `.btn-action-labeled` (colores fijos), `.low-stock`, `.text-danger`, `.fw-bold` y `.actions`. `--ink-400` pasó a `--ink-500`.

**Medidas (25 insumos)**

| Pantalla | Filas a la vista | Alto de fila | Antes |
| :-- | :-- | :-- | :-- |
| 1366 × 611 | **7** | ≈ 59 px | 2 filas de 85 px |
| 1093 × 489 | **5** | ≈ 55 px | 0 filas de 213 px |
| 1440 × 900 | ≥ 10 | ≈ 59 px | 6 |
| 390 × 844 | 8 (desliza a lo ancho, nombre fijo) | ≈ 65 px | tabla recortada |

**Pruebas (9 nuevas, 39 en total):**
- INV-6, INV-7 e INV-9 a 1366, 1093 y 1440: filas visibles, alto de fila, herramientas en una línea, sin desborde.
- INV-8 en teléfono: desliza y el nombre queda fijo.
- Etiquetas y costo.
- Nivel y franja sin teñir la fila.
- Categoría dentro de Insumo a 125 %.
- Las herramientas abren lo suyo.
- INV-10: sin tokens inexistentes ni colores fijos.

No regresión: `layout-scroll`, `finanzas-portatil`, `control-diario-apilado`, `cierre-caja-portatil`, `permisos-portatil` y `checkout-portatil` en verde (dos pruebas de Cobro intermitentes pasan al reintentar; ya ocurría antes).

**Fase 5 pendiente:** `scripts/audit-contrast.cjs` en claro y oscuro con la base real, y revisión en la Toshiba a 100 % y 125 %.

## 12. Registrar compra: valores por defecto (2026-10-23)

Revisando el modal en la portátil apareció un problema que no era de tamaño: la línea empezaba en **«Otro gasto»** y la categoría en
**la primera de la lista** («Helado y açaí»). Una compra de pulpa guardada sin mirar entraba como gasto suelto (sin subir stock ni costo) y
en una categoría al azar.

**Cambios en `PurchaseModal.vue`** (lo usan Inventario y Finanzas):
- La línea nueva empieza en **«Elige un insumo…»** (opción deshabilitada, valor `null`). Los insumos van en el grupo «Insumos» y «Otro gasto
  (gas, limpieza, bolsas…)» en «Otros gastos».
- La categoría empieza en **«Elige una categoría…»**.
- Al guardar sin elegir, el aviso dice qué falta: «Línea 1: elige un insumo, u «Otro gasto» si no es del inventario.» o «Elige una categoría.»
  «Otro gasto» sin concepto pide «escribe el concepto del gasto».
- «Se compra en» y el cálculo por unidad solo aparecen cuando la línea tiene un insumo.
- Desde la herramienta de una fila la línea sigue llegando con ese insumo; la categoría también se elige.

**Pruebas (4 nuevas, 43 en total):** marcadores y avisos al abrir desde la cabecera; insumo muestra «Se compra en» y «Otro gasto» muestra
«Concepto»; el envío lleva la categoría elegida y `ingredient_id: null` solo en la línea de otro gasto; desde la fila llega el insumo y la
categoría vacía. `finanzas-portatil` en verde (27).

**Coordinación:** [PLAN_MEJORA_UI_FINANZAS_PORTATIL.md](PLAN_MEJORA_UI_FINANZAS_PORTATIL.md) (otra sesión) propone rediseñar este modal
como «Registrar compra o gasto»; este cambio solo toca los valores por defecto y la validación, y debe conservarse en ese rediseño.

## 13. Fase 5: simulación de la Toshiba con datos reales (2026-10-23)

**Cómo se probó.** El dueño no tenía la Toshiba a mano, así que se simuló contra el **backend real** (Laravel 13 con PHP 8.4 en
WebAssembly, en la nube) y una **copia de la base de la Mac** (3 insumos con stock, 3 proveedores, 14 categorías de gasto), y después
con un **inventario completo** cargado por la API real en esa copia (23 insumos, 5 categorías, 6 proveedores, nombres largos, agotados,
por reordenar y de compra diaria). La base de la Mac no se tocó.

| Perfil | Ventana | Escala | CPU |
| :-- | :-- | :-- | :-- |
| Toshiba al 100 % | 1366 × 611 | 1 | 4× más lenta |
| Toshiba al 125 % | 1093 × 489 | 1,25 | 4× más lenta |

En cada perfil, en claro y en oscuro, se abrieron los 16 estados de la pantalla (lista, menú «Más», nuevo insumo y sus errores,
editar, Categorías, Proveedores, Registrar compra vacía, con aviso, con insumo y con dos líneas, compra desde la fila, merma, conteo,
compras sugeridas, historial, mermas del mes y confirmación de borrado). En cada uno se midió: ventana dentro de la pantalla, botones
del pie al alcance, scroll horizontal, herramientas en una línea, textos recortados sin título, foco y contraste AA.

**Resultado de la base de la Mac y del inventario completo**
- **Tamaño:** todo entra en los dos perfiles. Sin scroll horizontal, sin botones tapados, cabecera en una línea.
- **Filas a la vista:** 6 al 100 % y 5 al 125 %, con 23 insumos.
- **Contraste:** 0 textos bajo AA en los 16 estados × 2 tamaños × 2 temas, auditando la ventana abierta y no lo que queda atrás.
- **Tiempos:** abrir «Nuevo insumo» toma 0,2 s y «Registrar compra» entre 0,4 y 1 s con la CPU 4× más lenta (incluye pedir las opciones al servidor).
- **Compra de punta a punta:** Frutilla 2 kg por Bs 36 más «Hielo en bolsa» Bs 15. El stock pasó de 2,5 a 4,5 kg y el costo quedó en Bs 18/kg. Con teclado, Kiwi pasó de 0,8 a 1,8 kg.

**Hallazgos y correcciones**

| # | Hallazgo | Corrección |
| :-- | :-- | :-- |
| 1 | **El foco no entraba a las ventanas.** Registrar compra, merma, conteo, compras sugeridas e historial lo dejaban en el botón de atrás. Categorías, Proveedores y Mermas del mes lo perdían, porque el ítem del menú desaparece. Con Tab se recorría la página tapada, y en la caja se trabaja con teclado. | `FinanceModal`, que comparte Finanzas:<br>• Al abrir, el foco va al campo marcado con `data-autofocus` (aunque aparezca después de cargar) o, si no hay, a la ventana.<br>• Tab y Mayús+Tab no salen de la ventana.<br>• Al cerrar, el foco vuelve al botón que la abrió.<br>• En pantallas táctiles no se enfoca un campo, para no abrir el teclado en pantalla.<br>`runMore` devuelve el foco a «Más» antes de abrir. |
| 2 | No había campo inicial en cada ventana. | Campo inicial de cada ventana:<br>• Registrar compra: «Insumo».<br>• Compra desde la fila: «Cantidad».<br>• Compra desde Compras sugeridas: «Pagado».<br>• Merma: «Buscar», o «Cantidad perdida» si se abre desde la fila.<br>• Conteo: el primer «Contado».<br>• Categorías y Proveedores: «Nombre».<br>Las ventanas de revisión (Compras sugeridas, Historial, Mermas) enfocan la ventana y no un campo, para no cambiar cantidades por accidente. |
| 3 | Costo con punto («Bs 9.40») junto a cantidades con coma («0,3 kg») en la misma fila. | Formato `es-BO` como en Finanzas y en las ventanas de Inventario: «Bs 9,40». |
| 4 | Un nombre largo ocupaba 3 líneas (fila de 87 px al 125 %). | Máximo 2 líneas (`line-clamp`) con el nombre completo en `title`. La fila queda en 69 px. |
| 5 | Errores «Failed to fetch» en consola. | No es un error: se abortan pedidos al navegar mientras el POS carga (no aparecen con navegación normal). Los 403 son fotos de productos que no se copiaron a la nube. |

**Teclado (18 comprobaciones al 125 %, todas en verde).** Se hizo una compra completa sin mouse:
1. Enter en «Registrar compra».
2. Se escribe «Kiwi» en el insumo.
3. Tab pasa por Cantidad, «Se compra en» y «Pagado».
4. Se elige la categoría y se guarda.
5. El foco vuelve al botón.

Además, Tab no sale de la ventana en 30 pulsaciones, Escape devuelve el foco a la herramienta de la fila y Enter en el conteo pasa al siguiente insumo.

**Pruebas:** 6 E2E nuevas en `inventario-portatil.spec.js`, 49 en total:
- foco en «Insumo» y vuelta al botón;
- foco en «Cantidad» desde la fila;
- Tab atrapado en la ventana;
- Categorías desde «Más»;
- Compras sugeridas sin foco en cantidades;
- nombre largo en dos líneas.

La suite completa está en verde (153 + 2 de teléfono que requieren backend). La de Cobro intermitente de siempre pasa al reintentar. `npx vite build` sin errores.

**Queda para la Toshiba real (no se puede simular):** el dibujo de fuentes de Windows (ClearType), el touchpad y el zoom propio de Chrome si
alguien lo cambió. Basta abrir Inventario, «Registrar compra» y «Nuevo insumo» al 100 % y al 125 %.

## 14. Estado para retomar (2026-10-23, 22:10)

- **Fases 1 a 5: ✅ hechas y probadas.** La Fase 3 la hizo otra sesión y aquí se verificó. Las secciones 8 a 13 detallan cada una.
- **Sin commit** en `feat/inventario-portatil`. Nada en GitHub ni en el VPS.
- **Commit sugerido de Inventario:**
  - `views/InventoryList.vue`, `utils/inventoryLabels.js`, `components/PurchaseModal.vue`;
  - `components/Inventory/WasteModal.vue`, `components/Inventory/CountModal.vue`;
  - `components/Finance/FinanceModal.vue`, `components/Finance/finance.css`;
  - `e2e/inventario-portatil.spec.js`, este plan y su `_v1`.

  En la misma rama también está la Fase 1 de Finanzas v2. El orden de los commits está en la sección 7 de [PLAN_MEJORA_UI_FINANZAS_PORTATIL.md](PLAN_MEJORA_UI_FINANZAS_PORTATIL.md).
- **Falta:**
  1. Los commits.
  2. Publicar: `git push origin main` cuando el dueño lo decida, y el VPS por paquete.
  3. Abrir en la Toshiba real Inventario, «Registrar compra» y «Nuevo insumo» al 100 % y al 125 %, para ver letras de Windows, touchpad y zoom.
- **Para repetir las pruebas:**
  - Con la API simulada: `npx playwright test e2e/inventario-portatil.spec.js` (49 pruebas).
  - Con el backend real: [frontend/scripts/toshiba/](../frontend/scripts/toshiba/README.md) (`simular-inventario.cjs`, `teclado-inventario.cjs`).

