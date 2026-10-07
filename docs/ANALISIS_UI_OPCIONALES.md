# Análisis de Interfaz: Módulo de Gestión de Opcionales y Modal de Edición

## 1. Identificación y Ubicación en el Código

| Aspecto | Detalle |
| :--- | :--- |
| **Ruta en el navegador** | `/menu` (Pestaña activa: `Opcionales`) |
| **Vista contenedora principal** | [`frontend/src/views/Menu.vue`](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/views/Menu.vue) |
| **Componente del panel central y modal** | [`frontend/src/components/Menu/OptionGroupDetail.vue`](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Menu/OptionGroupDetail.vue) |
| **Componente de asignación vinculado** | [`frontend/src/components/Menu/AssignProductsModal.vue`](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Menu/AssignProductsModal.vue) |
| **Endpoints backend asociados** | `PUT /api/options/{id}` (actualizar), `DELETE /api/options/{id}` (eliminar), `POST /api/options` (crear) |

---

## 2. Jerarquía y Distribución Espacial de Elementos

La pantalla se estructura en tres capas jerárquicas:
1. **Navegación superior y pestañas globales**
2. **Layout de dos columnas (Sidebar lateral + Panel de detalle)**
3. **Capa modal emergente (Overlay / Diálogo de edición)**

```
+---------------------------------------------------------------------------------------------------------+
| [Productos]  [OPCIONALES (activo)]                                      [⚡ Rápido] [☀️ Modo] [🟢 Online] |
+-----------------------+---------------------------------------------------------------------------------+
| SIDEBAR IZQUIERDO     | PANEL CENTRAL (OptionGroupDetail.vue)                                           |
|                       |                                                                                 |
| [+ Crear grupo opc.]  |  [Tamaño Acai ✏️]                                              [+ Crear Opcional] |
|                       |  🔗 Classic Bowl, Hawaian Bowl...                           [Vincular productos] |
| > [Tamaño Acai    (3)]|  💡 Arrastra y suelta las opciones con el icono ⠿ para cambiar el orden...       |
|   [Toppings      (15)]|                                                                                 |
|   [Frutas        (11)]|  ⠿ Mediano                         +-----------------------------+ [ON] +7 BOB |
|   [Tamaño Cafe    (1)]|                                    | MODAL: Editar opcional   ✕  |             |
|                       |  ⠿ Grande                          | Nombre de la opción *       | [ON] +14 BOB|
|                       |                                    | [ Mediano                 ] |             |
|                       |  ⠿ Ohana                           | Precio adicional local      | [ON] +32 BOB|
|                       |                                    | [ 7                       ] |             |
|                       |                                    | Precio adicional delivery   |             |
|                       |                                    | [ 7                       ] |             |
|                       |                                    | Disponible             [ON] |             |
|                       |                                    |                             |             |
|                       |                                    | 🗑️ Eliminar [Cancelar] [Guardar]           |
|                       |                                    +-----------------------------+             |
+-----------------------+---------------------------------------------------------------------------------+
```

---

## 3. Desglose Detallado por Componente

### 3.1. Barra Superior Global (`Menu.vue`)
- **Pestaña "Productos"**: Inactiva.
- **Pestaña "Opcionales"**: Activa, con tipografía en negrita y barra inferior indicadora en color primario naranja (`var(--primary)`).
- **Controles de esquina superior derecha**:
  - Botón de sincronización / recarga rápida (icono de rayo).
  - Selector de tema claro/oscuro (icono de sol).
  - Indicador de conectividad (círculo verde de red activa).

### 3.2. Sidebar Izquierdo (`Menu.vue` líneas 530–555)
- **Acción superior**: Botón tipo cápsula rectangular con borde punteado/destacado `+ Crear grupo de opcionales` (`.sidebar-add-action`).
- **Lista de grupos (`.section-list`)**:
  - **Tarjeta seleccionada (`.option-group-sidebar-card.active`)**:
    - Grupo: **"Tamaño Acai"**.
    - Subtítulo: "3 Opcionales".
    - Badge numérico y flecha de navegación a la derecha: `3 >`.
    - Borde perimetral resaltado en naranja cálido.
  - **Tarjetas secundarias**:
    - "Toppings" (15 Opcionales, badge 15).
    - "Frutas" (11 Opcionales, badge 11).
    - "Tamaño Cafe" (1 Opcionales, badge 1).

### 3.3. Panel Central de Detalle (`OptionGroupDetail.vue` líneas 250–345)
- **Cabecera de Grupo (Top Section)**:
  - Título `<h2>`: **"Tamaño Acai"**.
  - Icono de edición (lápiz): Abre el formulario para editar nombre y reglas globales del grupo (mínimo/máximo de selecciones).
  - Botón superior derecho: `+ Crear Opcional` (`.btn-add-option-pya`), con acento visual en botón redondeado.
- **Barra de Vinculación (Middle Section)**:
  - Izquierda: Icono de enlace y texto con los productos asignados (*"Classic Bowl, Hawaian Bowl, Arma tu Bowl, Akahai, Aloha, Marley, Patriota, Shaka."*).
  - Derecha: Botón interactivo `"Vincular productos"`, que despliega [`AssignProductsModal.vue`](file:///Users/jolopez/Sistema%20de%20Ventas/frontend/src/components/Menu/AssignProductsModal.vue).
- **Lista de Opciones y Reordenamiento (Options Section)**:
  - Mensaje guía: *"💡 Arrastra y suelta las opciones con el icono ⠿ para cambiar el orden de visualización"*.
  - Filas de opciones (`.option-row-card`):
    - **Manija drag & drop**: Icono `⠿` de 6 puntos a la izquierda para reordenar por arrastre.
    - **Nombre de la opción**: Texto editable al hacer clic (**Mediano**, **Grande**, **Ohana**).
    - **Control toggle de activación**: Interruptor estilo switch iOS en verde (`.toggle-switch`).
    - **Precio adicional**: Leyenda inferior al switch (`+7,00 BOB`, `+14,00 BOB`, `+32,00 BOB`).

### 3.4. Modal Superpuesto en Primer Plano (`OptionGroupDetail.vue` líneas 358–406)
- **Fondo (Backdrop)**: Capa translúcida oscurecida (`.modal-backdrop`) que bloquea la interacción con el fondo.
- **Tarjeta Modal (`.modal-content-sm`)**:
  - **Cabecera**:
    - Título `<h3>`: `"Editar opcional"`.
    - Botón de cierre: Icono de cruz `✕` (`.btn-close-sm`).
  - **Cuerpo del formulario (`.modal-body-sm`)**:
    - Campo 1: **"Nombre de la opción *"** con foco activo (borde naranja), valor `"Mediano"`.
    - Campo 2: **"Precio adicional en local (BOB)"**, valor numérico `"7"`.
    - Campo 3: **"Precio adicional en delivery (BOB)"**, valor numérico `"7"` (canal PedidosYa).
    - Campo 4: **"Disponible"**, interruptor toggle activo en verde.
  - **Pie de acciones (`.modal-footer-sm`)**:
    - **Extremo izquierdo**: Botón `"🗑️ Eliminar"` en tipografía roja (`.btn-delete-opt-sub`), visible solo al editar una opción existente.
    - **Extremo derecho**:
      - Botón secundario: `"Cancelar"` (`.btn-cancel`) en tono neutro grisáceo.
      - Botón principal: `"Guardar"` (`.btn-save`) con fondo de color acento naranja (`var(--primary)`).
