# Plan v2: Finanzas en la portátil de caja Toshiba (1366 × 768)

> **Módulo:** [Finance.vue](../frontend/src/views/Finance.vue) + [components/Finance/](../frontend/src/components/Finance/) + [finance.css](../frontend/src/components/Finance/finance.css). Ruta `/finanzas`, solo super admin.
> **Pantallas objetivo:** 1366 × 611 (Windows 100 %, viewport útil) y 1093 × 489 (125 %, "mantener para ambas"). El área de contenido descontando menú y barra superior es ≈ **1191 × 567** (≈ 914 × 445 al 125 %); verificar en la Fase 0.
> **Rama:** `feat/pos-caja-arranque`. **Estado (2026-10-20):** implementado (fases 0–5); pendiente contraste AA con copia de BD y prueba en la caja real. La v1 queda en `PLAN_REDISENO_FINANZAS_PORTATIL.v1.md`.

## 0. Qué cambia respecto a la v1 (hallazgos al contrastar el plan con el código)

| # | La v1 decía | El código real | Decisión v2 |
|---|---|---|---|
| 1 | Poner `position: sticky; top: 0` a `.fin-head` **y** a `.fin-tabs` | Son hermanos: ambos con `top: 0` se pisan. El que se desplaza es `.oh-shell-content` (`overflow:auto`), y `.fin-page` no tiene overflow, así que sticky sí funciona. | Envolver cabecera + pestañas en un solo `<div class="fin-nav">` sticky (cambio en `Finance.vue`) y exponer su alto en `--fin-nav-h` para los encabezados de tabla pegajosos. |
| 2 | Resumen "en dos columnas 60/40" | Ya existe: `.sum-grid` / `.sum-main` / `.sum-side` (flex, `flex: 999 1 560px` + `1 1 320px`). | No se rehace el layout: se ajustan solo gaps y se verifica que a 1191 px queden lado a lado. Ahorro de trabajo y de riesgo. |
| 3 | Modales: "fijar cabecera y pie, scroll solo en el cuerpo" | `.fin-modal` ya es flex con `.fin-modal-body{overflow-y:auto}`; solo falta compactar paddings y el tope `100vh - 32px`. `PurchaseModal.vue` y `RecipeEditor.vue` usan CSS propio (`<style scoped>`), no `.fin-modal`. | Fase 4 = auditar esos dos modales y comparar con el estándar existente, no reescribirlos. |
| 4 | Hero → franja de 88 px con 2 botones `+ Compra` / `+ Semana` | El hero muestra además la **lista de avisos** del Estado de Resultados con 5 acciones posibles (`add_purchase`, `classify_cash`, `generate_recurring`, `import_pedidosya`, `view_pedidosya`). Reducirlo a 2 botones escondería avisos que hacen el resultado "provisional". | La franja conserva título + frase + barra de cada Bs 100 y los avisos en una línea con **todas** sus acciones (chips); el detalle se despliega. No se pierde ninguna acción. |
| 5 | Costos: "barra visual de margen", toggles de 26 px, cuadrícula de tarjetas | Son funciones nuevas, no adaptación a la portátil. El problema real es de **altura** (solo 3 filas visibles), no de anchura (la tabla de 1.133 px ya cabe en 1.191). | Solo se compacta: padding de filas, encabezado de tabla pegajoso, barra de filtros en una línea. Sin componentes nuevos. |
| 6 | PedidosYa: dropzone embebida y mapeos siempre visibles | La pestaña ya cabe (418 px). La dropzone sin modal cambia el flujo de importación. | Fuera de alcance: solo se verifica que no desborde. Dos columnas solo si la Fase 0 muestra un problema. |
| 7 | Compactar en todas las pantallas | Finanzas también se usa en monitores grandes y celular (reglas `@media (max-width: 760px)` ya existentes). | Todo lo compacto va bajo `@media (max-width:1366px), (max-height:800px)` (misma regla que `AppShell.vue`) y se prueba que ≥ 1440 px y 390 px no cambian. |
| 8 | No consideraba el 125 % | A 489 px de alto una navegación de 76 px es el 16 %. | Con `max-height: 520px` la cabecera deja de ser pegajosa y solo se pega la fila de pestañas (~34 px). |
| 9 | "Resumen: ver P&L hasta Utilidad Operativa en el primer pantallazo" | Presupuesto real: 567 − 76 (nav) − 88 (hero) − 2×12 (gaps) ≈ 380 px para el Estado de Resultados. Si tiene ≥ 12 filas de ~30 px no entra entero. | Meta medible: **hero + ingresos, costo de ventas y utilidad bruta + el Punto de Equilibrio visibles sin scroll**; el resto con scroll corto. Se confirma con la medición de la Fase 0. |
| 10 | Pruebas E2E sin mencionar datos | Finanzas necesita ~11 endpoints (`/finance/pnl`, `/expense-categories`, `/finance/expenses`, `recurring-expenses`, `cash-movements`, `suppliers`, `costs`, `break-even`, `inventory-indicators`, `pedidosya-settlements`…) y un usuario super admin. La auditoría de contraste (`audit-contrast.cjs`) exige backend + login reales. | Fase 0 crea un **fixture de API** reutilizable (mock con `page.route`, como en `cierre-caja-portatil.spec.js`), con datos "abundantes" (≥ 12 compras, ≥ 8 plantillas, ≥ 10 productos) para medir el peor caso. El contraste se corre contra la copia de BD (procedimiento de AGENTS.md) o con el fixture. |

## 1. Diagnóstico a confirmar (Fase 0, sin tocar código de producción)

Medir con Playwright en 1366×611 y 1093×489 (claro/oscuro): altura total del contenido de cada pestaña (`scrollHeight` del `.fin-page`), alto del hero, alto de cabecera y pestañas, filas visibles de la tabla de costos, alto de cada modal abierto con datos abundantes y overflow horizontal. Las cifras de la v1 (1.603 / 418 / 620 / 512 / 1.480 / 1.320 px, hero 460 px) **no están verificadas**; se reemplazan por esta tabla y se guarda en el plan como línea base.

## 2. Diseño

### 2.1 Navegación pegajosa (`Finance.vue` + `finance.css`)
- `<div class="fin-nav">` envuelve `<header class="fin-head">` y `<nav class="fin-tabs">`; `position: sticky; top: 0; z-index: 40` (por debajo de modales z-200 y toast).
- Compacto: `.fin-head` padding `8px 20px 6px`; `h1` 20 px; el subtítulo `<p>` se oculta (es descriptivo, no informa); botones del selector de mes 30 px; pestañas 34 px de alto, fuente 13.5 px. Meta: **≤ 80 px** en total. Alternativa si la Fase 0 muestra que sobra ancho: título + mes en la misma fila de las pestañas (≈ 44 px).
- `--fin-nav-h` definido en `.fin-nav` y usado por los `thead` pegajosos.
- ≤ 520 px de alto: solo `.fin-tabs` es pegajosa; la cabecera se desplaza.
- `.fin-body`: padding `14px 20px 24px` (antes `22px 28px 64px`) y gap 12 px.
- Accesibilidad: foco visible en pestañas; el contenido enfocado por teclado no queda tapado (`scroll-padding-top: var(--fin-nav-h)` en el contenedor).

### 2.2 Resumen
- **Hero** (`FinanceMonthHero.vue`): de tarjeta (padding 34/40, título 44 px) a franja con título 24 px, frase 13 px (1 línea con elipsis + `title`), barra de cada Bs 100 a la derecha y avisos en una fila de chips con sus acciones. Altura meta ≤ 96 px con avisos y ≤ 72 px sin ellos. Estados "futuro / sin ventas / provisional / cerrado" conservan su texto.
- **Layout:** se mantiene `.sum-grid`; solo se reducen gaps (22 → 14 px) y `FinanceStatementTable` pasa a filas de ~28 px (`padding 6px 12px`).
- **Indicadores de inventario:** quedan al pie, sin cambio (ya es un bloque propio).

### 2.3 Categorías (`FinanceCategories.vue`)
- Rejilla 2 × 2 (`grid-template-columns: repeat(2, minmax(0,1fr))`) para las 4 secciones bajo la media query compacta; 1 columna ≤ 760 px (se conserva la tabla "stack" actual). Cada tarjeta: ~550 px de ancho, sin scroll horizontal (la columna "tipo de costo" y las acciones no deben romper la fila: se verifica con nombres largos).
- Meta honesta: las 4 secciones visibles **con las categorías por defecto**; con muchas categorías hay scroll dentro de la página, no dentro de cada tarjeta.

### 2.4 Compras y gastos (`FinanceExpenses.vue`)
- Barra de herramientas en una línea: acciones (primario "Registrar compra", secundario "Registrar gasto"), chips de sección y filtro de categoría; `flex-wrap` como respaldo al 125 %.
- Filas de la tabla: padding `7px 10px`. Fecha corta ("18 oct") **solo si** no se pierde el año al cruzar de año (tooltip con la fecha completa).
- Egresos de caja: se mantiene su sección y su selector de categoría; solo se compacta. "Sin clasificar" lleva icono + texto (no solo color).

### 2.5 Gastos fijos (`FinanceRecurring.vue`) y PedidosYa
- Solo compactar paddings y comprobar que con ≥ 8 plantillas el scroll sea el de la página. Sin reestructurar (v1 §4.3 y §4.5 pasan a "verificar").

### 2.6 Costos y margen (`FinanceCosts.vue`)
- Filas de ~34 px (hoy ≈ 50), `thead` pegajoso con `top: var(--fin-nav-h)`, filtros en una línea, columnas con `table-layout: fixed` y anchos por porcentaje (no px fijos, para que sirva igual al 125 %). Meta: **≥ 8 productos visibles** a 1366×611.

### 2.7 Modales (Fase 4: auditar y ajustar)
- `.fin-modal`: `max-height: calc(100vh - 24px)`; cabecera `12px 18px 10px`, pie `10px 18px 12px`, cuerpo gap 10 px.
- `PurchaseModal.vue`: campos generales en 2 columnas, `.purchase-lines` con `max-height` en función del viewport (`calc(100vh - 300px)`, mínimo 120 px) en vez de 240 px fijos; total y botones siempre visibles.
- `RecipeEditor.vue`: lista de ingredientes con scroll interno; costo total y "Guardar" siempre visibles.
- `PedidosYaImportModal`, `CompositionEditor`, `BaseRecipesWizard`, `InventoryIndicatorsModal`, `FinanceModal`: se miden y solo se tocan si desbordan.

## 3. Fases

0. **Línea base y fixture** (1 h): `e2e/finanzas-portatil.spec.js` con mock de API y super admin; test de medición que imprime la tabla del §1 (se borra el log al terminar, se conservan los asserts). Actualizar §1 del plan con los números reales.
1. **Navegación y tokens** (`Finance.vue`, `finance.css`): `.fin-nav` pegajosa, compactación bajo media query, 125 %.
2. **Resumen y hero** (`FinanceMonthHero.vue`, `FinanceSummary.vue`, `FinanceStatementTable.vue`).
3. **Pestañas**: Categorías 2×2, Compras y gastos, Costos y margen; verificar Gastos fijos y PedidosYa.
4. **Modales**: auditoría con datos abundantes y ajustes.
5. **Verificación**: E2E completa, contraste, capturas, documentación.

## 4. Pruebas (criterios de aceptación)

Para cada pestaña y cada tamaño (1366×611, 1366×768, 1093×489, 1440×900 sin cambios, 390×844):
1. Sin scroll horizontal en la página ni en las tarjetas (`scrollWidth ≤ clientWidth + 1`).
2. Con scroll de 400 px la barra de pestañas sigue visible (`boundingClientRect.top ≈ 0`) y se puede cambiar de pestaña y de mes.
3. Resumen: hero ≤ 96 px; título, frase, Estado de Resultados (primeras filas) y Punto de Equilibrio dentro del primer pantallazo.
4. Categorías: las 4 secciones se ven lado a lado en 2 columnas a 1366; 1 columna a 390.
5. Costos: ≥ 8 filas visibles; `thead` pegado debajo de la navegación.
6. Cada modal (compra con 6 líneas, gasto, receta, importación PedidosYa, plantilla fija, categoría): cabecera y botones de guardar dentro del viewport, y el overlay por encima de la navegación pegajosa.
7. Tema oscuro y claro; contraste AA con `audit-contrast.cjs` (copia de BD) o el fixture; pestañas y chips con texto además de color.
8. No regresión: 1440×900 mantiene el diseño actual (el hero de 44 px sigue ahí) y 390×844 pasa las pruebas existentes de móvil.
9. Los tests existentes (`checkout-portatil`, `cierre-caja-portatil`, `permisos-portatil`) siguen verdes y `vite build` compila.

## 5. Riesgos

- **Sticky + `overflow-x: auto` de `.fin-tabs`:** el sticky va en el contenedor `.fin-nav`, no en el elemento con overflow.
- **Avisos del hero:** mostrar todos o ninguno; la regla del §0-4 evita esconder acciones críticas.
- **Datos reales más largos que el fixture** (nombres de insumos, 40 categorías): usar elipsis con `title` y probar con nombres de 60 caracteres.
- **Scoped CSS en hero/summary:** los cambios compactos viven en esos componentes con la misma media query; `finance.css` solo para lo compartido.
- **Rendimiento:** sin `backdrop-filter` ni sombras nuevas en la barra pegajosa (equipo lento, modo liviano).

## 6. Archivos

- Modificar: `views/Finance.vue`, `components/Finance/{finance.css, FinanceMonthHero, FinanceSummary, FinanceStatementTable, FinanceCategories, FinanceExpenses, FinanceCosts, FinanceRecurring (solo si la Fase 0 lo pide), RecipeEditor}.vue`, `components/PurchaseModal.vue`.
- Crear: `frontend/e2e/finanzas-portatil.spec.js` (+ fixture dentro del mismo archivo).
- Documentación: este plan, `CHANGELOG.md` ("Sin publicar"), `docs/BITACORA.md`; al terminar `graphify update .`.
- Sin cambios de backend ni de permisos.

## 7. Resultado de la implementación (línea base medida → final, 1366×611)

| Pestaña | Antes | Después |
|---|---|---|
| Resumen | 1.435 px (hero 471) | 912 px (hero 165) |
| PedidosYa | 649 | 570 |
| Compras y gastos | 1.703 | 1.408 |
| Gastos fijos | 1.211 | 990 |
| Costos y margen | 1.473 (≈ 5 filas visibles) | 1.102 (≥ 7 filas) |
| Categorías | 1.460 | 625 |

Desviaciones del plan: (1) **hallazgo extra:** las pestañas medían 1 px de alto (se aplastaban) y no se veían en la portátil; corregido con `flex-shrink: 0`. (2) El hero con avisos queda en ~165 px, no en 96 px (el aviso con sus acciones se conserva). (3) **No hay `thead` pegajoso** en Costos: `.fin-card` y `.fin-table-wrap` tienen `overflow` y lo impedirían; se descartó `--fin-nav-h`. (4) Los modales ya cumplían (cabecera/pie fijos, scroll en el cuerpo): solo se compactaron paddings y las líneas de `PurchaseModal`. (5) La auditoría de contraste con `audit-contrast.cjs` exige backend y login reales: queda pendiente contra la copia de BD.
