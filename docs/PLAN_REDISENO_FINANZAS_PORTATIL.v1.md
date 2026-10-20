# Plan de Rediseño: Módulo de Finanzas para la Portátil de Caja Toshiba (1366 × 768)

> **Contexto:** Módulo de Finanzas ([views/Finance.vue](../frontend/src/views/Finance.vue), componentes en [components/Finance/](../frontend/src/components/Finance/) y [finance.css](../frontend/src/components/Finance/finance.css)).
> **Resolución objetivo:** Portátil de caja Toshiba de 15.6" con resolución nativa **1366 × 768 px** (viewport útil del navegador en Playwright: **1366 × 611 px**).
> **Área útil de contenido (con AppShell):** **`1191 × 567 px`** (175 px de menú lateral izquierdo y 44 px de barra superior).
> **Rama:** `feat/pos-caja-arranque`. **Estado (2026-10-20):** plan de rediseño detallado.

---

## 0. Resumen Ejecutivo

El módulo de Finanzas es la herramienta exclusiva del dueño para analizar el Estado de Resultados (P&L mensual), controlar compras y gastos, gestionar gastos fijos, conciliar las liquidaciones de PedidosYa y supervisar costos de recetas y punto de equilibrio.

Al auditar las 6 pestañas y los modales del módulo a la resolución real de la portátil Toshiba (**1366 × 611 px**), se identificaron problemas de usabilidad y diseño:
1. **Pérdida de navegación al hacer scroll:** Ni la cabecera del módulo (`.fin-head`) ni las 6 pestañas (`.fin-tabs`) son fijas (*sticky*). Al desplazarse por reportes largos (el Resumen tiene 1.603 px de altura total), el selector de mes y las pestañas desaparecen, obligando al usuario a volver arriba cada vez que desea cambiar de sección.
2. **Hero del Resumen sobredimensionado:** El bloque inicial de la pestaña Resumen (`FinanceMonthHero.vue`) mide más de 460 px de alto, absorbiendo casi todo el primer pantallazo útil (567 px) y ocultando por completo el Estado de Resultados y el Punto de Equilibrio.
3. **Desperdicio horizontal en pantallas panorámicas:** En las pestañas de *PedidosYa*, *Gastos fijos* y *Categorías*, se apilan verticalmente tarjetas estrechas de una sola fila, dejando grandes áreas vacías a los lados en 1.191 px de ancho y multiplicando el desplazamiento vertical.
4. **Modales de alta densidad en altura reducida:** Modales complejos como `PurchaseModal.vue` (compra de insumos multilínea) y `RecipeEditor.vue` (recetas y mermas) superan la altura del viewport cuando tienen más de 3 líneas de detalle.

---

## 1. Inventario Exhaustivo de Vistas, Pestañas, Botones y Modales

El módulo se compone de una vista raíz ([Finance.vue](../frontend/src/views/Finance.vue)), 6 pestañas funcionales y 8 ventanas modales:

```
[ Finanzas (/finanzas) ]
  ├── Cabecera: Selector de Mes (< Mes > + Mes actual)
  ├── Barra de 6 Pestañas con Contadores Dinámicos y Alertas
  │
  ├── 1. Resumen (FinanceSummary.vue)
  │     ├── Hero Mensual (FinanceMonthHero.vue) [Botón: Registrar compra, Cargar semana]
  │     ├── Estado de Resultados (FinanceStatementTable.vue) [Botones: fin-toggle acordeón]
  │     ├── Tarjeta PedidosYa (sum-pya) [Botón: Ver semanas / Cargar semana]
  │     ├── Punto de Equilibrio (FinanceBreakEven.vue) [Botón: Ver costos y recetas]
  │     ├── Reposición de Inventario (sum-stock) [Botón: Ver compras sugeridas]
  │     └── Indicadores de Inventario (FinanceInventoryIndicators.vue) [Botón: Conteo físico, Mermas]
  │
  ├── 2. PedidosYa (PedidosYaSettlements.vue)
  │     ├── Tarjeta Cabecera [Botón: + Cargar semana]
  │     ├── Tabla de Semanas [Botones: Ver informe, Eliminar semana]
  │     ├── Mapeo de Nombres (PedidosYaMappings.vue) [Botón: Ver equivalencias]
  │     └── Modal Carga Semana (PedidosYaImportModal.vue) [Botones: Cancelar, Guardar semana]
  │
  ├── 3. Compras y gastos (FinanceExpenses.vue)
  │     ├── Compras fuera de caja [Botones: Registrar gasto, + Registrar compra]
  │     ├── Filtro Secciones [Todas, Costo ventas, Operativos, Financieros, Impuestos]
  │     ├── Filtro Categoría [Dropdown select]
  │     ├── Tabla Compras/Gastos [Botones: Editar, Eliminar por fila]
  │     ├── Egresos de Caja [Filtros: Todos, Sin clasificar] + [Dropdown asignar categoría]
  │     └── Modales: PurchaseModal.vue y FinanceModal.vue
  │
  ├── 4. Gastos fijos (FinanceRecurring.vue)
  │     ├── Gastos fijos del mes [Botón: Generar gastos fijos de Mes]
  │     ├── Tabla mensual [Botones por fila: Generar este gasto, Descartar]
  │     ├── Plantillas de gastos fijos [Botón: + Agregar gasto fijo]
  │     ├── Tabla plantillas [Botones por fila: Editar, Eliminar]
  │     └── Modal: FinanceModal.vue (Plantilla gasto fijo)
  │
  ├── 5. Costos y margen (FinanceCosts.vue)
  │     ├── Cabecera [Botón: Cargar recetas base]
  │     ├── Filtro componentes [Todos, Bases de costeo, Insumos con stock]
  │     ├── Tabla Productos y Margen [Botón: Receta (abre RecipeEditor.vue)]
  │     ├── Tabla Componentes de Costeo [Botones: Modo auto/manual, Composición (CompositionEditor.vue)]
  │     └── Modales: RecipeEditor.vue, CompositionEditor.vue, BaseRecipesWizard.vue
  │
  └── 6. Categorías (FinanceCategories.vue)
        ├── 4 Secciones: Costo ventas, Operativos, Financieros, Impuestos
        ├── Por sección [Botón: + Agregar categoría]
        ├── Por fila [Selector tipo costo (Fijo/Variable), Botón: Desactivar/Activar, Editar]
        └── Modal: FinanceModal.vue (Crear/Editar categoría)
```

---

## 2. Diagnóstico Dimensional en la Portátil de Caja Toshiba

* **Resolución nativa:** `1366 × 768 px`
* **Viewport útil de navegador (Playwright):** `1366 × 611 px`
* **Menú lateral izquierdo (`.oh-sidebar` en modo compacto):** `175 px`
* **Barra superior (`.oh-shell-topbar`):** `44 px`
* **Lienzo disponible para Finanzas (`.oh-shell-content`):** **`1191 px de ancho × 567 px de alto útil`**.

### Matriz de Mediciones Actuales por Pestaña

| Pestaña | Altura Total (`scrollHeight`) | Altura Disponible | ¿Cabe en 1 pantalla? | Elementos Críticos Ocultos Inicialmente |
|---|---|---|---|---|
| **1. Resumen** | `1.603 px` | `567 px` | ❌ No (3 pantallas) | P&L completo, Punto de equilibrio, Reposición e Indicadores de inventario. |
| **2. PedidosYa** | `418 px` | `567 px` | ✅ Sí | Cabe holgadamente, pero las tarjetas están apiladas con espacios vacíos a los lados. |
| **3. Compras y gastos** | `620 px` (vacía)<br>`> 1.200 px` (con datos) | `567 px` | ❌ No | La tabla de egresos de caja queda por debajo del fold. |
| **4. Gastos fijos** | `512 px` | `567 px` | ✅ Sí | Cabe en estado inicial; con >5 plantillas genera scroll vertical. |
| **5. Costos y margen** | `1.480 px` | `567 px` | ❌ No (2.5 pantallas) | Solo se ven los primeros 3 productos de la tabla; requiere scroll continuo. |
| **6. Categorías** | `1.320 px` | `567 px` | ❌ No (2.3 pantallas) | Solo se ve la sección "Costo de ventas"; Operativos, Financieros e Impuestos quedan abajo. |

---

## 3. Principios de Rediseño y Técnicas de Interfaz para Laptop de Caja

1. **Sticky Navigation Shell (Navegación Persistente):**
   - El selector de período y las pestañas deben permanecer fijos en la parte superior del módulo (`position: sticky; top: 0; z-index: 100`).
   - El usuario debe poder cambiar de mes o saltar de *Resumen* a *Compras* en cualquier punto del scroll sin tener que desplazarse hacia arriba.
2. **First-Screen Information Hierarchy (Información Clave al Primer Vistazo):**
   - En la pestaña Resumen, el resultado mensual, el P&L y el Punto de Equilibrio deben verse juntos en el primer pantallazo (1.191 × 567 px).
   - Rediseño del hero a formato "franja panorámica ejecutiva" de ~85 px de altura, liberando 370 px verticales para el Estado de Resultados.
3. **Distribución Panorámica en Dos Columnas (Two-Column Master Layout):**
   - Con 1.191 px de ancho, Finanzas no debe apilar tarjetas verticalmente en una sola columna angosta.
   - Distribución:
     - **Columna principal (60% ~ 700 px):** Estado de Resultados o Tablas maestras.
     - **Columna lateral (40% ~ 440 px):** Punto de equilibrio, PedidosYa, Reposición o Paneles de filtro rápido.
4. **Ergonomía de Modales en 579 px de Alto Máximo:**
   - Reducir paddings verticales de modales de 16 px a 10 px.
   - Fijar encabezado y pie de modal; hacer que solo el área de datos/filas tenga scroll (`overflow-y: auto`), manteniendo siempre a la vista los botones "Cancelar" y "Guardar".

---

## 4. Detalle del Rediseño por Pestaña

### 4.1. Cabecera y Navegación Sticky Global ([finance.css](../frontend/src/components/Finance/finance.css))
* **Cabecera compacta:**
  - Reducir el padding de `.fin-head` de `20px 28px 14px` a **`10px 20px 8px`**.
  - Reducir el tamaño de `h1` de 28 px a **20 px** (Baloo 2).
  - Selector de mes `.fin-period` compacto con botones de 30 px.
* **Barra de pestañas integrada:**
  - Altura reducida de pestañas de 45 px a **34 px**.
  - Fijar `.fin-head` y `.fin-tabs` con `position: sticky; top: 0; background: var(--surface); z-index: 50`.
  - Altura total de navegación fija: **~76 px** (dejando **491 px netos** para el contenido sin interrupciones).

---

### 4.2. Pestaña 1: Resumen ([FinanceSummary.vue](../frontend/src/components/Finance/FinanceSummary.vue))
* **Hero Panorámico Compacto (`FinanceMonthHero.vue`):**
  - Rediseñar de una tarjeta vertical de 460 px a una franja horizontal de **88 px**:
    - *Izquierda:* Título `Octubre: Bs 107 de utilidad` en 22 px bold + frase resumen en 12.5 px.
    - *Centro:* Barra visual proporcional de Bs 100 de 160 px de ancho.
    - *Derecha:* Si hay alertas pendientes, mostrar botones compactos de 32 px (`+ Compra`, `+ Semana`).
  - **Ahorro vertical:** **`370 px recuperados`**.
* **Layout 2 Columnas:**
  - **Columna Izquierda (680 px):**
    - `FinanceStatementTable.vue`: Tabla de P&L con padding de filas compacto (`7px 14px` en vez de `11px 20px`), visible en el primer pantallazo hasta la Utilidad Operativa.
    - `sum-pya`: Tarjeta de PedidosYa debajo del P&L con métricas en 3 columnas.
  - **Columna Derecha (450 px):**
    - `FinanceBreakEven.vue`: Indicador visual de meta de ventas y día de equilibrio.
    - `sum-stock`: Alerta de insumos por reponer con sus 3 insumos críticos.
* **Indicadores de Inventario:**
  - Ubicados al pie en formato tabla compacta desplegable con acordeón.

---

### 4.3. Pestaña 2: PedidosYa ([PedidosYaSettlements.vue](../frontend/src/components/Finance/PedidosYaSettlements.vue))
* **Integración en 2 Columnas Panorámicas:**
  - **Columna Izquierda (720 px):** Tabla de liquidaciones semanales del mes, con importes claros (Ventas brutas, Retención, Neto liquidado, Estado de conciliación y botones de informe).
  - **Columna Derecha (410 px):**
    - Panel de importación rápida: botón destacado `+ Cargar semana (.xlsx o .csv)` y dropzone visible directamente sin abrir modal si no hay semanas.
    - Panel de mapeo de nombres (`PedidosYaMappings.vue`) directamente visible sin requerir navegación adicional.

---

### 4.4. Pestaña 3: Compras y Gastos ([FinanceExpenses.vue](../frontend/src/components/Finance/FinanceExpenses.vue))
* **Barra de Herramientas Compacta:**
  - Unificar en una sola línea horizontal:
    - Botones de acción: `+ Registrar compra de insumos` (primario, 34 px) y `Registrar gasto` (secundario, 34 px).
    - Chips de filtro de sección (`Todas`, `Costo ventas`, `Operativos`, etc.).
    - Buscador / filtro de categoría.
* **Tabla de Compras/Gastos Eficiente:**
  - Reducir padding de celdas a `8px 12px`.
  - Fecha en formato compacto (`18 oct`), proveedor e insumo destacados en negrita, importe alineado a la derecha en tipografía tabular.
* **Egresos de Caja Integrados:**
  - Mostrar la sección de egresos de caja del turno justo debajo con etiquetas de advertencia "Sin clasificar" claramente visibles y selector de categoría en línea de un clic.

---

### 4.5. Pestaña 4: Gastos Fijos ([FinanceRecurring.vue](../frontend/src/components/Finance/FinanceRecurring.vue))
* **Estructura en Doble Panel:**
  - **Panel Superior / Izquierdo:** Gastos fijos del mes en curso con su botón `Generar gastos del mes` destacado. Si ya se generaron, muestra el estado `Generado` con tick verde.
  - **Panel Inferior / Derecho:** Tabla de plantillas recurrentes (alquiler, salarios, internet, régimen simplificado) con botón `+ Agregar plantilla`.

---

### 4.6. Pestaña 5: Costos y Margen ([FinanceCosts.vue](../frontend/src/components/Finance/FinanceCosts.vue))
* **Optimización de la Tabla de Costeo:**
  - En lugar de dejar que la tabla mida 1.133 px con columnas dispersas:
    - Fijar anchos proporcionales: Producto (280 px), Costo (90 px), Precio local (90 px), Margen local (140 px con barra visual), Precio PYa (90 px), Margen PYa (140 px), Botón receta (80 px).
    - Los márgenes en verde (`margin-good`), amarillo (`margin-fair`) o rojo (`margin-low`) con la ganancia en bolivianos en segunda línea.
* **Sub-tabla de Componentes de Costeo:**
  - Disponer los componentes (bases de açaí, kits descartables) en una cuadrícula compacta de 2 columnas de tarjetas con selector de modo automático/manual en un toggle de 26 px.

---

### 4.7. Pestaña 6: Categorías ([FinanceCategories.vue](../frontend/src/components/Finance/FinanceCategories.vue))
* **Matriz 2 × 2 de Secciones (En lugar de 4 listas verticales infinitas):**
  - Con 1.191 px de ancho disponible, organizar las 4 secciones contables en una cuadrícula de 2 columnas × 2 filas:
    - *Fila 1:* `Costo de ventas` (izq, ~560 px) y `Gastos operativos` (der, ~560 px).
    - *Fila 2:* `Gastos financieros` (izq, ~560 px) e `Impuestos` (der, ~560 px).
  - **Resultado:** Las 4 secciones contables quedan visibles simultáneamente en pantalla sin scroll vertical.

---

## 5. Ergonomía de Ventanas Modales de Finanzas

Para evitar que los formularios de compra y recetas desborden en el viewport de 611 px:

1. **Tokens de Modal (`.fin-modal`):**
   ```css
   .fin-modal {
     max-height: calc(100vh - 24px); /* 587 px en la Toshiba */
   }
   .fin-modal-head {
     padding: 12px 18px 10px;
   }
   .fin-modal-foot {
     padding: 10px 18px 12px;
   }
   ```
2. **`PurchaseModal.vue` (Compra de insumos):**
   - Disponer los campos generales (Fecha, Proveedor, Categoría, Comprobante) en 2 columnas compactas de 2 campos cada una (altura: ~70 px).
   - El área de líneas de compra (`.purchase-lines`) se define con `max-height: 240px; overflow-y: auto;`.
   - El total general y los botones de guardar quedan siempre fijados en el pie del modal sin importar cuántas líneas se agreguen.
3. **`RecipeEditor.vue` (Editor de recetas):**
   - Las líneas de ingredientes se muestran en una tabla compacta con scroll interno, garantizando que el costo total de la receta y el botón "Guardar receta" estén siempre visibles.

---

## 6. Plan de Implementación por Fases

### Fase 1: Arquitectura Base y Navegación Sticky en CSS
* Modificar [finance.css](../frontend/src/components/Finance/finance.css):
  - Implementar `position: sticky; top: 0` para `.fin-head` y `.fin-tabs`.
  - Reducir paddings de `.fin-head`, `.fin-tabs` y `.fin-body`.
  - Crear la clase `.fin-grid-2col` (layout de 2 columnas 60/40 para 1.191 px).
  - Ajustar tokens de modales (`.fin-modal`, `.fin-modal.wide`) para tope de 587 px.

### Fase 2: Rediseño de Resumen y Hero Mensual
* Modificar [FinanceMonthHero.vue](../frontend/src/components/Finance/FinanceMonthHero.vue):
  - Compactar diseño a franja horizontal de 88 px.
  - Integrar botones de alertas en formato compacto.
* Modificar [FinanceSummary.vue](../frontend/src/components/Finance/FinanceSummary.vue):
  - Aplicar el layout de 2 columnas balanceadas (P&L a la izquierda, Punto de Equilibrio y Reposición a la derecha).

### Fase 3: Optimización Panorámica de Pestañas
* Modificar [FinanceCategories.vue](../frontend/src/components/Finance/FinanceCategories.vue) para aplicar la matriz 2 × 2 de secciones contables.
* Modificar [PedidosYaSettlements.vue](../frontend/src/components/Finance/PedidosYaSettlements.vue) para integrar importación y equivalencias en dos columnas.
* Modificar [FinanceExpenses.vue](../frontend/src/components/Finance/FinanceExpenses.vue) para unificar la barra de herramientas y filtros en una sola línea.
* Modificar [FinanceCosts.vue](../frontend/src/components/Finance/FinanceCosts.vue) con anchos de columna fijos y badges de margen compactos.

### Fase 4: Ajuste de Modales de Alta Densidad
* Optimizar [PurchaseModal.vue](../frontend/src/components/PurchaseModal.vue) y [RecipeEditor.vue](../frontend/src/components/Finance/RecipeEditor.vue) con scroll interno exclusivo para las líneas de insumos y pie de confirmación fijo.

### Fase 5: Batería de Pruebas y Verificación
* Crear prueba E2E en Playwright (`frontend/e2e/finanzas-portatil.spec.js`) con viewport `1366 × 611`:
  - Verificar ausencia total de scroll horizontal en las 6 pestañas (`scrollWidth === clientWidth`).
  - Verificar que la cabecera y las pestañas se mantengan fijas al scrollear.
  - Verificar apertura de modales (compra, gasto, receta, importación) y visibilidad de sus botones de guardado sin scroll.
* Ejecutar auditoría de contraste WCAG AA:
  ```bash
  cd frontend
  node scripts/audit-contrast.cjs contrast-finanzas dark
  node scripts/audit-contrast.cjs contrast-finanzas light
  ```
* Sincronizar grafo con `graphify update .` y actualizar bitácora y changelog.

---

## 7. Registro de Archivos

* **Archivos a modificar:**
  - `frontend/src/components/Finance/finance.css` (estilos globales, sticky, responsive y modales).
  - `frontend/src/views/Finance.vue` (cabecera compacta y estructura).
  - `frontend/src/components/Finance/FinanceMonthHero.vue` (hero compacto panorámico).
  - `frontend/src/components/Finance/FinanceSummary.vue` (layout 2 columnas).
  - `frontend/src/components/Finance/FinanceCategories.vue` (matriz 2x2).
  - `frontend/src/components/Finance/FinanceCosts.vue` (anchos fijos de tabla).
  - `frontend/src/components/Finance/FinanceExpenses.vue` (barra de filtros y herramientas).
  - `frontend/src/components/PurchaseModal.vue` (scroll interno de líneas).
  - `frontend/src/components/Finance/RecipeEditor.vue` (scroll interno de receta).
* **Archivos a crear:**
  - `frontend/e2e/finanzas-portatil.spec.js` (suite de no-regresión para laptop 1366 × 611).
* **Documentación:**
  - `CHANGELOG.md` ("Sin publicar").
  - `docs/BITACORA.md`.
