# Plan: Rediseño Ergonómico de Gestión de Inventario para la Portátil de Caja

> **Módulos afectados:** [InventoryList.vue](../frontend/src/views/InventoryList.vue), [PurchaseModal.vue](../frontend/src/components/PurchaseModal.vue), [WasteModal.vue](../frontend/src/components/Inventory/WasteModal.vue), [CountModal.vue](../frontend/src/components/Inventory/CountModal.vue), [MovementHistoryModal.vue](../frontend/src/components/Inventory/MovementHistoryModal.vue), [WasteReportModal.vue](../frontend/src/components/Inventory/WasteReportModal.vue), [ReorderModal.vue](../frontend/src/components/Inventory/ReorderModal.vue).  
> **Reglas y lineamientos:** [AGENTS.md](../AGENTS.md) y [.agents/rules/buenas-practicas.md](../.agents/rules/buenas-practicas.md).  
> **Rama sugerida:** `feat/inventario-portatil-modales`.  
> **Estado:** Propuesta de diseño y plan técnico para aprobación del usuario.

---

## 1. Diagnóstico Visual y Técnico de las Capturas

A partir del análisis detallado de las 6 capturas compartidas por el usuario en la pantalla de **Gestión de Inventario** (`/inventory`):

```
┌────────────────────────────────────────────────────────────────────────┐
│ ERROR CRÍTICO CAPTURA 1: Modal Insumo desbordado por arriba           │
│                                                                        │
│   [ Borde superior de la pantalla del navegador ]                      │
│   ▲                                                                    │
│   │  (Invisible fuera de pantalla: Título "Nuevo Insumo",             │
│   │   botón [X], campo "Nombre" y campo "Unidad")                      │
│   ▼                                                                    │
│ ┌──────────────────────────────────────────────────┐                   │
│ │ Categoría: [ Sin Categoría  v ]  Tipo: [ Perec v]│ ◄ Se ve cortado   │
│ │ [ ] Insumo clave (entra en conteo semanal)       │                   │
│ │ Stock inicial: [ 0 ]   Costo Inicial: [ 0 ]      │                   │
│ │ DATOS DE COMPRA (OPCIONAL)...                    │                   │
│ │ REPOSICIÓN Y VENCIMIENTO...                      │                   │
│ │ Vence el: [ mm/dd/yyyy ]                         │                   │
│ │ [ Cancelar ] [ Guardar ]                         │                   │
│ └──────────────────────────────────────────────────┘                   │
└────────────────────────────────────────────────────────────────────────┘
```

### 1.1 Falla Crítica en Modal Insumo (`showIngredientModal` - Captura 1)
* **Síntoma:** Al hacer clic en `+ Nuevo Insumo` o en `Editar`, la ventana modal se dibuja **cortada por arriba**. El título, el botón de cierre y los primeros campos fundamentales (*Nombre* y *Unidad*) quedan fuera de la vista (`top < 0`). El usuario no puede saber qué está editando ni escribir el nombre del insumo.
* **Causa técnica (`InventoryList.vue` L. 980-992):**
  * `.modal-backdrop` tiene `display: flex; align-items: center; justify-content: center;`.
  * `.modal-content` tiene ancho fijo (480 px), pero **carece de `max-height` y de `overflow-y: auto`**.
  * El formulario contiene **más de 16 campos** distribuidos verticalmente en una sola columna (~850 px de alto).
  * En una pantalla de laptop con viewport útil de 611 px (o 489 px en escala 125%), `align-items: center` posiciona el centro de la caja en el centro del viewport, empujando la mitad superior ~150 px hacia arriba, por fuera del borde superior del navegador.

### 1.2 Disparidad Arquitectónica entre Modales
* **Modales modernos (Capturas 2, 3, 4 y 5):**
  * `PurchaseModal.vue` (Registrar compra), `WasteModal.vue` (Registrar merma) y `WasteReportModal.vue` (Mermas del mes) utilizan la arquitectura de `FinanceModal.vue` (`.fin-modal`):
    - Tienen cabecera fija con título, descripción y botón `✕`.
    - Tienen `max-height: calc(100vh - 40px)`.
    - Tienen cuerpo con scroll interno independiente (`overflow-y: auto`).
    - Tienen pie de acciones fijo en la parte inferior.
* **Modales antiguos inline (Capturas 1 y 6):**
  * `showIngredientModal` (Nuevo/Editar insumo), `showCategoryModal` (Categorías) y `showSuppliersModal` (Proveedores) son bloques HTML inline dentro de `InventoryList.vue` con un CSS rudimentario (`.modal-backdrop` y `.modal-content`) que no respeta los límites de la pantalla ni tiene botón de cierre en la cabecera.

### 1.3 Saturación de Cabecera y Desborde de la Tabla (Captura 6)
* **Cabecera saturada:** La franja superior tiene **8 botones grandes** (`📋 Compras sugeridas`, `🚚 Proveedores`, `📂 Categorías`, `📊 Mermas del mes`, `⚖️ Conteo`, `🗑️ Registrar merma`, `🛒 Registrar compra`, `+ Nuevo Insumo`). En pantallas de 1366 px o en Windows al 125%, se quiebran en 2 o 3 líneas empujando el contenido hacia abajo.
* **Tabla con 6 botones con texto por fila:** Cada fila tiene 6 botones (`Ingreso`, `Merma`, `Conteo`, `Historial`, `Editar`, `Eliminar`). Esto exige más de 550 px solo para la columna `ACCIONES`, desbordando la tabla horizontalmente cuando la barra lateral está presente.

---

## 2. Presupuesto de Pantalla en la Portátil de Caja Toshiba

Las pantallas objetivo del sistema (verificadas con el resto de módulos) son:

| Perfil | Viewport Útil | Alto Útil de Contenido (`.oh-shell-content`) | Ancho Útil (restando menú lateral de 220 px) |
| :--- | :--- | :--- | :--- |
| **Toshiba, Windows 100%** | **1366 × 611 px** | **≈ 570 px** | **≈ 1146 px** |
| **Toshiba, Windows 125%** | **≈ 1093 × 489 px** | **≈ 448 px** | **≈ 873 px** |
| **Escritorio Estándar** | 1440 × 900 px | ≈ 847 px | ≈ 1220 px |
| **Móvil / Tablet** | 390 × 844 px | ≈ 803 px | 390 px |

---

## 3. Arquitectura y Solución Propuesta

### 3.1 Estandarización de Modales con `FinanceModal` o Componente Modal Unificado
Sustituir los 3 modales inline de `InventoryList.vue` (`showIngredientModal`, `showCategoryModal`, `showSuppliersModal`) por la estructura probada de `FinanceModal.vue`:
* **Overlay:** `position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; display: flex; align-items: center; justify-content: center; padding: 16px;`.
* **Caja modal:** `max-height: calc(100vh - 32px); display: flex; flex-direction: column; overflow: hidden; border-radius: 16px;`.
* **Cabecera fija (`modal-head`):** Título legible (`Nuevo Insumo`), descripción corta y botón `✕` accesible con teclado (`Escape`).
* **Cuerpo con scroll (`modal-body`):** `overflow-y: auto; padding: 20px;`.
* **Pie fijo (`modal-foot`):** Botones `Cancelar` y `Guardar` siempre a la vista sin necesidad de desplazarse hasta el fondo.

---

### 3.2 Rediseño Ergonómico del Modal "+ Nuevo Insumo" en 2 Columnas

En lugar de apilar 16 campos en una torre vertical de 850 px, se organiza el modal en **dos columnas en laptop/desktop** (`min-width: 768px`) con un ancho de `max-width: 760px` (`wide`):

```
┌────────────────────────────────────────────────────────────────────────┐
│ Nuevo Insumo                                                        ✕  │
│ Configura los datos del insumo, costeo y reglas de reposición.         │
├───────────────────────────────────┬────────────────────────────────────┤
│ 📦 1. Identificación y Stock     │ 🚚 2. Compra y Reposición          │
│                                   │                                    │
│ Nombre: [ Pulpa de Açaí         ] │ Proveedor: [ Distribuidora Açaí v] │
│ Unidad: [ kg                  v ] │ Se compra en: [ caja             ] │
│ Categoría: [ Frutas y Pulpa   v ] │ kg por caja: [ 10                ] │
│ Tipo: [ Perecedero            v ] │ Pedido mínimo (kg): [ 20         ] │
│ [✓] Insumo clave (conteo semanal) │                                    │
│                                   │ Cómo se repone: [ Punto reorden v] │
│ Stock inicial: [ 50.00          ] │ Consumo diario est.: [ 5.0       ] │
│ Costo unitario (Bs): [ 12.00    ] │ Punto de reorden manual: [ 15    ] │
│                                   │ Días cobertura: [ 7 ] Días seg: [2]│
│                                   │ Vence el: [ dd/mm/aaaa           ] │
├───────────────────────────────────┴────────────────────────────────────┤
│                                              [ Cancelar ] [ Guardar ]  │
└────────────────────────────────────────────────────────────────────────┘
```

* **Beneficio inmediato:**
  - La altura total del modal baja de **850 px a ~430 px**.
  - **Entra completo sin scroll** en 1366 × 611 (570 px útiles).
  - En Windows al 125% (448 px útiles), solo requiere un scroll de apenas 20-30 px, y el botón de guardar y la cabecera permanecen fijos.

---

### 3.3 Rediseño del Modal de Categorías y Proveedores
* **Gestión de Categorías (`showCategoryModal`):**
  - Ancho contenido (640 px).
  - Columna izquierda: Lista de categorías con scroll interno (`max-height: 240px`).
  - Columna derecha: Formulario de creación/edición compacto.
  - Cabecera fija con `✕` y pie con botón `Cerrar`.
* **Proveedores (`showSuppliersModal`):**
  - Mismo formato `wide` (760 px).
  - Tabla de proveedores a la izquierda con scroll interno.
  - Formulario de alta rápida a la derecha.

---

### 3.4 Reorganización de la Cabecera de Inventario
Reducir la sobrecarga cognitiva y el desborde vertical de los 8 botones:

```
┌────────────────────────────────────────────────────────────────────────┐
│ Gestión de Inventario                                                  │
│ Control de insumos, abastecimiento y mermas.                           │
│                                                                        │
│ [ 📋 Compras sugeridas (2) ]  [ ⚖️ Conteo ]  [ 🗑️ Merma ]              │
│ [ ⚙️ Catálogo v ]  │  [ 🛒 Registrar compra ]  [ + Nuevo Insumo ]       │
└────────────────────────────────────────────────────────────────────────┘
```

1. **Botones de acción primaria:**
   - `+ Nuevo Insumo` (Destacado en naranja `--passion-500`).
   - `🛒 Registrar compra` (Botón outline prioritario).
2. **Operaciones del turno:**
   - `📋 Compras sugeridas` (con píldora de cantidad si hay faltantes).
   - `⚖️ Conteo físico`.
   - `🗑️ Merma`.
3. **Mantenimiento y reportes:**
   - Agrupar `Proveedores`, `Categorías` y `Mermas del mes` en un selector desplegable o grupo compacto `⚙️ Más opciones`.

---

### 3.5 Optimización de la Tabla de Insumos y Celda de Acciones
* **Contenedor:** Aplicar `overflow-x: auto;` en `.table-container` para que, si la tabla supera el ancho de la portátil, se desplace limpiamente sin romper el menú ni los bordes.
* **Celda de Acciones compacta:**
  - Cambiar los 6 botones labeled por botones de acción compactos con ícono y tooltip (`icon-action-btn`) de 32 × 32 px:
    - 🛒 `Compra` (verde)
    - ⚠️ `Merma` (ámbar)
    - ⚖️ `Conteo` (azul)
    - 🕒 `Historial` (gris)
    - ✏️ `Editar` (açaí)
    - 🗑️ `Eliminar` (rojo)
  - En la portátil (≤ 1366 px), las acciones se alinean en una sola fila compacta de ~210 px en vez de 550 px.
  - Cada botón conserva su `aria-label` y `title` para accesibilidad.

---

## 4. Fases de Implementación

### Fase 1: Corrección Crítica del Modal de Insumo (`showIngredientModal`)
1. Reemplazar el contenedor `.modal-backdrop` y `.modal-content` por la arquitectura `FinanceModal` o clases equivalentes (`modal-head`, `modal-body`, `modal-foot`).
2. Implementar el layout de 2 columnas (`.ingredient-form-grid`) para pantallas `≥ 768px`.
3. Añadir botón `✕` superior y escucha de tecla `Escape` para cerrar.
4. Fijar `max-height: calc(100vh - 40px)` y `overflow-y: auto` en el cuerpo.

### Fase 2: Estandarización de Modales de Categorías y Proveedores
1. Migrar `showCategoryModal` a la estructura con cabecera y pie fijos.
2. Migrar `showSuppliersModal` a la estructura con cabecera y pie fijos.

### Fase 3: Ergonomía de Cabecera y Tabla de Insumos
1. Reorganizar los 8 botones de cabecera en grupos lógicos para que entren en una sola línea en 1366 px y máximo 2 líneas al 125%.
2. Compactar los botones de la columna `Acciones` en la tabla (iconos accesibles de 32 × 32 px con tooltip).
3. Asegurar `overflow-x: auto` en `.table-container`.

### Fase 4: Pruebas y Verificación
1. **Verificación visual a 1366 × 611 (100%):**
   - Abrir `+ Nuevo Insumo`: el título, botón `✕`, campos y botones inferiores son visibles sin desbordar hacia arriba.
   - Abrir `Categorías` y `Proveedores`: contenidos centrados y usables.
2. **Verificación visual a 1093 × 489 (125%):**
   - El modal de insumo entra cómodamente con scroll interno suave; la cabecera y los botones de acción nunca se salen de la pantalla.
3. **Pruebas automatizadas y build:**
   - `cd backend && php artisan test`
   - `cd frontend && npx vite build`
   - `node scripts/audit-contrast.cjs /tmp/contrast dark` y `light` (0 textos bajo WCAG AA).

---

## 5. Criterios de Aceptación Medibles

| ID | Criterio | Verificación |
| :--- | :--- | :--- |
| **INV-1** | El modal de Nuevo/Editar Insumo **nunca se corta por arriba** (`top >= 16px` en cualquier resolución). | Inspección visual y prueba en 1366 × 611 y 1093 × 489. |
| **INV-2** | El título, el botón de cierre `✕` y los botones `Cancelar` / `Guardar` están siempre accesibles. | Tecla `Escape` y clic en `✕` cierran el modal. |
| **INV-3** | En pantallas de portátil (1366 px y 1093 px), el formulario de insumo se visualiza en 2 columnas lógicas. | Altura del modal ≤ 480 px. |
| **INV-4** | La tabla de insumos no se desborda ni empuja la ventana; las acciones caben en una franja compacta. | Sin scroll horizontal en la ventana entera. |
| **INV-5** | Todos los botones de acción tienen tamaño táctil/clic mínimo de 32 × 32 px con `aria-label`. | WCAG AA superado. |
