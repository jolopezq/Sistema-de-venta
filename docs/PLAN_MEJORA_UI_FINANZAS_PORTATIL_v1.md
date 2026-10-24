# Plan de Mejora Ergonómica de UI — Módulo de Finanzas (Portátil de Caja)

> **Módulo:** [Finance.vue](../frontend/src/views/Finance.vue) + componentes en [frontend/src/components/Finance/](../frontend/src/components/Finance/) + [finance.css](../frontend/src/components/Finance/finance.css).
> **Dispositivo Principal:** Portátil de caja Toshiba (1366 × 768 nativa).
> **Viewports Objetivo:**
> - **1366 × 611** (Windows 100 % escala, viewport útil del navegador). Contenido real descontando sidebar y barra: **≈ 1191 × 567 px**.
> - **1093 × 489** (Windows 125 % escala DPI habitual). Contenido real: **≈ 914 × 445 px**.
> - **1440 × 900** (Monitor de escritorio sin cambios indeseados).
> - **390 × 844** (Móvil / PWA).
> **Fecha:** 2026-10-23.
> **Estado:** Propuesta para revisión y aprobación antes de implementación.

---

## 1. Análisis y Diagnóstico de las Vistas (Basado en las Capturas)

A partir de las capturas de pantalla proporcionadas por el usuario, se identifican 8 pantallas y modales críticos con problemas ergonómicos y oportunidades de optimización en la portátil de caja:

| # | Vista / Modal | Componente | Diagnóstico en Portátil (1366×611 y 1093×489) |
|---|---|---|---|
| **1 y 2** | **Informe de Inventario del Mes** | [InventoryIndicatorsModal.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Finance/InventoryIndicatorsModal.vue) | Modal `wide` (760 px) que contiene 6 tablas apiladas verticalmente (Indicadores, Cobertura, Quiebres, Mermas, Conteos y Valor). En 489 px de alto útil, requiere un scroll interno excesivo (> 2.000 px). Las tablas secundarias carecen de navegación rápida por sección. |
| **3** | **Compras Sugeridas** | [ReorderModal.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Inventory/ReorderModal.vue) | Los 2 KPIs superiores ("Por reponer" y "Estimado") y las notas de advertencia consumen ~190 px de altura fija. En pantallas de 489 px útiles, solo queda visible 1 proveedor con 1 solo insumo, forzando desplazamiento constante para revisar los pedidos. Las acciones del proveedor se aprietan con el texto de entrega. |
| **4** | **Vista Principal Resumen** | [FinanceSummary.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Finance/FinanceSummary.vue) / [FinanceMonthHero.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Finance/FinanceMonthHero.vue) | A 125 % DPI (≈ 914 px ancho útil de contenido), la cuadrícula `.sum-grid` rompe sus 2 columnas y colapsa en una sola columna vertical infinita (~1.400 px), empujando el Punto de Equilibrio y la Reposición al fondo de la pantalla. El hero del mes y la barra de Bs 100 consumen hasta 165 px. |
| **5** | **Nueva / Editar Categoría** | [FinanceCategories.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Finance/FinanceCategories.vue) | El modal está balanceado pero en la vista base las 4 tablas (Costo de ventas, Gastos operativos, etc.) se aprietan en 2 columnas y los textos de ayuda en los selects son redundantes. |
| **6** | **Cargar Recetas Base** | [BaseRecipesWizard.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Finance/BaseRecipesWizard.vue) | Asistente de 3 etapas secuenciales presentado como un formulario largo con scroll continuo. En la portátil solo se ven las primeras 4 filas de componentes; el usuario no tiene visibilidad de las etapas 2 y 3 sin scrollear hasta el fondo. |
| **7** | **Agregar Gasto Fijo** | [FinanceRecurring.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Finance/FinanceRecurring.vue) | 4 filas de campos con espaciado amplio. A 125 % (489 px de alto), el modal supera la altura visible del viewport y genera scroll vertical forzado en un formulario de entrada rápida. La fila de 3 columnas (Frecuencia, Primer mes, Día) se comprime en exceso horizontalmente. |
| **8** | **Registrar Compra o Gasto** | [FinanceExpenses.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Finance/FinanceExpenses.vue) | Similar a Gasto Fijo: 4 filas de inputs más notas de advertencia ("Si el dinero salió de caja..."). En pantallas de 489 px, los botones Cancelar y Guardar quedan pegados al borde inferior o requieren scroll. |

---

## 2. Principios y Metas Ergonómicas de Diseño

1. **Presupuesto Vertical Estricto (Below-the-fold cero en modales simples):**
   - En modales de entrada de datos (Gastos, Gastos Fijos, Categorías), **el 100 % de los campos, encabezado y botones Guardar/Cancelar deben ser visibles simultáneamente sin scroll** en 1366×611 y en 1093×489.
2. **Alta Densidad Informativa Accesible (High-Density Compact UI):**
   - Inputs y botones de 32 a 34 px de alto (cumpliendo accesibilidad táctil mínima de 32 px en portátil).
   - Labels compactos de 12 px (`font-weight: 600`), márgenes verticales reducidos de 14 px a 8–10 px en formularios.
3. **Contención y Navegación Segmentada en Modales Extensos:**
   - Para modales con múltiples conjuntos de datos (`InventoryIndicatorsModal` y `BaseRecipesWizard`), sustituir scrolls interminables por navegación segmentada o pasos (tabs internos), permitiendo saltar directamente a la sección deseada.
4. **Resumen en 2 Columnas Estables (Sin Colapso Abrupto a 125 %):**
   - Ajustar el breakpoint de flexbox en `.sum-grid` para que a 914 px de contenido (125 % DPI) se conserve la relación analítica: Estado de Resultados a la izquierda y Punto de Equilibrio + Reposición a la derecha.

---

## 3. Plan de Mejora por Componente

### Fase 1: Formularios Modales Rápidos (Finanzas)
- **Archivos:** [FinanceExpenses.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Finance/FinanceExpenses.vue), [FinanceRecurring.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Finance/FinanceRecurring.vue), [FinanceCategories.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Finance/FinanceCategories.vue), [finance.css](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Finance/finance.css).
- **Acciones:**
  1. En `.fin-page .fin-modal`:
     - En pantallas con alto `≤ 620px` (y regla específica para `≤ 520px` al 125 %):
       - Header padding: `10px 16px 8px`.
       - Body padding: `10px 16px`, gap: `8px`.
       - Footer padding: `8px 16px 10px`.
  2. **Modal "Registrar compra o gasto" (`FinanceExpenses.vue`):**
     - Compactar campos en 3 filas lógicas balanceadas en lugar de 4:
       - Fila 1: Fecha (35 %) + Monto Bs (30 %) + Forma de pago (35 %).
       - Fila 2: Proveedor (50 %) + Categoría (50 %).
       - Fila 3: Recibo/Factura (40 %) + Nota (60 %).
     - La nota informativa de efectivo se muestra como un tooltip o hint inline compacto (`font-size: 11.5px`), ahorrando 35 px verticales.
     - **Resultado:** Altura total del modal pasa de 510 px a **385 px** (100 % visible en 489 px sin scroll).
  3. **Modal "Agregar gasto fijo" (`FinanceRecurring.vue`):**
     - Fila 1: Concepto (60 %) + Monto Bs (40 %).
     - Fila 2: Categoría (50 %) + Forma de pago (50 %).
     - Fila 3: Frecuencia (35 %) + Primer mes (35 %) + Día de pago (30 %).
     - Fila 4: Nota opcional en una sola línea compacta.
     - **Resultado:** Altura total reducida a **390 px**.
  4. **Modal "Nueva categoría" (`FinanceCategories.vue`):**
     - Textos de opciones del select simplificados:
       - `Fijo: no depende de ventas (alquiler, sueldos, servicios)`
       - `Variable: sube con las ventas (insumos, comisiones)`
     - Formulario 100 % visible sin tocar bordes.

---

### Fase 2: Modal de Compras Sugeridas (`ReorderModal.vue`)
- **Archivo:** [frontend/src/components/Inventory/ReorderModal.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Inventory/ReorderModal.vue)
- **Acciones:**
  1. **Barra Resumen Horizontal de KPIs (Compact Strip):**
     - Reemplazar las 2 tarjetas verticales grandes de KPI (`.rm-kpis`) por una franja resumen compacta horizontal de 38 px de alto:
       - `Por reponer: 2 (2 agotados)` · `Estimado: Bs 544,00 (2 insumos)` · Badge informativo con advertencia plegable.
     - Ahorro de espacio vertical: **110 px recuperados** para la lista de compras.
  2. **Cabecera de Grupo de Proveedor Compacta:**
     - Alinear en una sola línea:
       - Izquierda: Nombre del proveedor en negrita + info de entrega (`Llega en 2 días · Lunes/Jueves`).
       - Derecha: Botones compactos (`Copiar`, botón icono `WhatsApp`, y botón primario `Registrar compra`).
  3. **Tabla de Insumos Optimizada:**
     - Filas con padding compacto (`6px 8px`).
     - Input numérico de cantidad a pedir ajustado a 64 px con botón sutil de restablecer al sugerido.
     - Indicador de stock actual (`Hay`) en formato monoespaciado legible.
  4. **Footer:**
     - Botones `Recalcular`, `Imprimir`, `Copiar todo`, `Cerrar` con padding ergonómico y espaciador flexible.

---

### Fase 3: Modal de Informe de Inventario (`InventoryIndicatorsModal.vue`)
- **Archivo:** [frontend/src/components/Finance/InventoryIndicatorsModal.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Finance/InventoryIndicatorsModal.vue)
- **Acciones:**
  1. **Navegación Rápida por Pestañas / Segmentos:**
     - Incorporar en la parte superior del cuerpo una barra de pestañas compacta (chips segmentados):
       - `[1. Indicadores del mes]` · `[2. Cobertura y Quiebres]` · `[3. Mermas y Conteos]` · `[4. Valorización]`
     - Permite al usuario revisar de inmediato el área de interés sin tener que realizar un scroll de 6 páginas.
  2. **Compactación Tabular:**
     - En pantallas `≤ 1366px`, evitar que `.fin-table.stack` rompa a vista de tarjetas en este modal analítico, manteniendo el formato tabular limpio con anchos porcentuales fijos.
  3. **Impresión Directa:**
     - Mantener el botón `Imprimir` optimizado que genera el reporte oficial en iframe sin alterar la vista en pantalla.

---

### Fase 4: Asistente "Cargar Recetas Base" (`BaseRecipesWizard.vue`)
- **Archivo:** [frontend/src/components/Finance/BaseRecipesWizard.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Finance/BaseRecipesWizard.vue)
- **Acciones:**
  1. **Estructura en Pasos (Wizard Stepper):**
     - En lugar de apilar 3 listas extensas, dividir en 3 pasos navegables:
       - **Paso 1:** Costo de componentes (porciones base, descartables, café).
       - **Paso 2:** Recetas de productos (bowls y opciones base).
       - **Paso 3:** Ajustes de opciones (frutas, extras, sin...).
     - Barra de progreso superior simple: `Paso 1 de 3` con botones `Anterior` y `Siguiente`, y en el paso 3 `Aplicar recetas`.
  2. **Beneficio en Portátil:**
     - Cada paso mide menos de 360 px de alto, permitiendo revisar y ajustar costos sin perder de vista los botones ni el contexto.

---

### Fase 5: Vista Principal de Finanzas (`FinanceSummary.vue` & `Finance.vue`)
- **Archivos:** [frontend/src/views/Finance.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/views/Finance.vue), [FinanceSummary.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Finance/FinanceSummary.vue), [FinanceMonthHero.vue](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Finance/FinanceMonthHero.vue).
- **Acciones:**
  1. **Estabilidad del Grid de 2 Columnas en 125 % DPI:**
     - Ajustar los anchos mínimos en `.sum-grid`:
       - `.sum-main`: `flex: 1 1 500px; min-width: 0;`
       - `.sum-side`: `flex: 0 0 280px; min-width: 260px;`
     - En 914 px de contenido (1093 px con escala 125 %), las columnas se mantienen lado a lado (590 px para Estado de Resultados / 280 px para Punto de Equilibrio y Reposición), evitando el colapso a 1 sola columna vertical.
  2. **Hero del Mes Compacto:**
     - Reducir el padding de `FinanceMonthHero` a `10px 16px`.
     - Barra de progreso de Bs 100 integrada a la derecha del texto en la misma línea cuando el ancho lo permite.
     - Avisos de egresos sin clasificar en una píldora inline de alerta rápida con botón de acción.
  3. **Punto de Equilibrio y Reposición:**
     - Reducir paddings internos y asegurar que el botón "Ver compras sugeridas" esté siempre a la vista en la primera pantalla.

---

## 4. Matriz de Criterios de Aceptación (Verificación)

| ID | Criterio | Verificación |
|---|---|---|
| **C1** | **Modal Gasto y Gasto Fijo:** en 1366×611 y 1093×489, el 100% de los campos y los botones Cancelar/Guardar están en pantalla sin scroll vertical. | Playwright: `await expect(footer).toBeInViewport()` sin scroll previo. |
| **C2** | **Compras Sugeridas:** la franja de KPIs ocupa ≤ 42 px de alto; al menos 2 proveedores e insumos son visibles de inmediato en el primer pantallazo del modal. | Medición de bounding box y captura visual. |
| **C3** | **Informe de Inventario:** navegación segmentada por pestañas operativas; al cambiar de pestaña el contenido cambia sin romper el contenedor. | Prueba funcional E2E. |
| **C4** | **Cargar Recetas Base:** asistente por pasos (1, 2 y 3); cada paso queda 100% contenido en 489 px de alto útil. | Playwright stepper test. |
| **C5** | **Vista Resumen en 125 %:** `.sum-grid` conserva 2 columnas lado a lado a 1093×489 (Estado de resultados a la izquierda, Punto de equilibrio a la derecha). | Assert de layout en Playwright. |
| **C6** | **Sin Desborde Horizontal:** cero scroll horizontal en toda la vista de Finanzas (`scrollWidth <= clientWidth + 1`). | Test automatizado en los 4 viewports. |
| **C7** | **Contraste Accesible (WCAG AA):** 0 textos ilegibles bajo WCAG AA en tema claro y oscuro fuera de degradados. | `node scripts/audit-contrast.cjs`. |
| **C8** | **No Regresión:** `npm run build` exitoso, tests unitarios en verde y pantallas móviles (390×844) y desktop (1440×900) mantienen su usabilidad. | Suite completa Playwright y Vite build. |

---

## 5. Plan de Ejecución y Pasos Inmediatos

1. **Crear rama de trabajo aislada:**
   ```bash
   git checkout -b feat/finanzas-portatil-ergonomia
   ```
2. **Implementar Fase 1:** Optimización de modales rápidos (`FinanceExpenses`, `FinanceRecurring`, `FinanceCategories`).
3. **Implementar Fase 2:** Modal de Compras Sugeridas (`ReorderModal`).
4. **Implementar Fase 3 & 4:** Modales extensos (`InventoryIndicatorsModal` y `BaseRecipesWizard`).
5. **Implementar Fase 5:** Vista Resumen (`FinanceSummary` y `FinanceMonthHero`).
6. **Verificación y Suite E2E:**
   - Ejecutar `e2e/finanzas-portatil.spec.js` en viewports 1366×611 y 1093×489.
   - Auditoría de contraste claro/oscuro.
   - `npm run build` y `php artisan test`.
7. **Documentación:** Actualizar `CHANGELOG.md` y `docs/BITACORA.md`.
