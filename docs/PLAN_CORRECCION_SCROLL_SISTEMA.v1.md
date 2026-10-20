# Plan de Corrección: Bloqueo de Viewport y Eliminación de Franja Blanca al Escrolear en Todos los Módulos

**Proyecto:** Ohana POS  
**Dispositivo objetivo:** Portátil de caja Toshiba (1366 × 768 px nativos, viewport útil en Chrome con barra de Windows al 100%: 1366 × 611 px; al 125%: 1093 × 489 px).  
**Rama de trabajo:** `feat/pos-caja-arranque`  
**Fecha:** 20 de Octubre de 2026  
**Estado:** Propuesta de Plan Técnico

---

## 1. Diagnóstico del Error (¿Por qué se ve así al final de escrolear en Finanzas?)

### 1.1 Síntoma visual en la captura
Al escrolear hasta el final de la pestaña **Costos y margen** en el módulo de Finanzas:
1. El menú lateral izquierdo (`.oh-sidebar`) se corta abruptamente debajo del chip de usuario ("Super Admin Dueño"), perdiendo la parte superior ("Ohana POS", "Vender", "Caja", "Delivery", etc.).
2. Debajo del menú lateral queda un **vacío blanco masivo** de entre 200 y 350 px.
3. El área de contenido principal a la derecha termina en la última fila de la tabla ("Vaso de café grande + tapa") y exhibe exactamente la misma franja blanca vacía en la parte inferior.
4. La barra superior (`.oh-shell-topbar`) y las pestañas del módulo de Finanzas ("Resumen", "PedidosYa", "Costos y margen", etc.) desaparecen hacia arriba fuera de la pantalla.

### 1.2 Causa raíz técnica (Paso a paso)
El fallo no es un simple margen o padding incorrecto, sino un **problema de contención de layout y encadenamiento de scroll a nivel de ventana**:

```
[ Navegador / Ventana (window.scrollY) ]  <-- NO ESTÁ BLOQUEADO (overflow: visible)
  └── html (scrollHeight: 2.383 px)     <-- FUGA: se expande por hijos flex estáticos
       └── body (height: 100%)
            └── #app (min-height: 100vh)
                 └── .oh-shell (position: static, sin containment)
                      ├── .oh-sidebar (height: 100% de la ventana)
                      └── .oh-shell-main
                           └── .oh-shell-content (overflow: auto)
                                └── .fin-page
                                     └── .fin-body (height: 3.057 px)
```

1. **Falta de bloqueo estricto en la raíz (`style.css`):**
   - En `frontend/src/style.css` (líneas 171-172):
     ```css
     html, body { margin:0; padding:0; height:100%; min-height:100vh; }
     #app { height:100%; min-height:100vh; display:flex; flex-direction:column; }
     ```
   - Ni `html`, ni `body`, ni `#app` definen `overflow: hidden` ni `overscroll-behavior: none`. Al usar `min-height: 100vh`, el viewport del navegador no tiene un límite superior rígido.
2. **Escape del layout en `.oh-shell` (`AppShell.vue`):**
   - `.oh-shell` tiene `position: static` (valor por defecto) y carece de contención CSS (`contain: paint`).
   - Cuando un hijo flex descendiente contiene elementos largos (como las 3 tablas consecutivas de `FinanceCosts.vue` que suman 3.057 px de altura), el motor de renderizado de Chromium propaga esa altura intrínseca hacia arriba hasta `document.documentElement.scrollHeight`, alcanzando **2.383 px** (una fuga de **+1.772 px** por encima de los 611 px de la pantalla).
3. **Encadenamiento de desplazamiento (*Overscroll Chaining*):**
   - Al usar el ratón o el touchpad en la tabla, el contenedor `.oh-shell-content` se desplaza internamente hasta su tope (`scrollTop = 2.567 px`).
   - Al llegar al final, al no tener `overscroll-behavior: contain`, el evento de scroll **se encadena a la ventana global** (`window`).
4. **Desplazamiento de la ventana completa (`window.scrollY > 0`):**
   - La ventana del navegador se desplaza físicamente hacia abajo hasta 1.772 px.
   - Como `.oh-shell` tiene un alto ligado al viewport (`height: 100%`), al moverse la ventana hacia arriba, el shell completo sube.
   - El espacio que queda debajo de `.oh-shell` es el lienzo virgen en blanco del navegador (*canvas background*), provocando la **franja blanca gigante**.

---

## 2. Auditoría Exhaustiva en Todos los Módulos del Sistema

Se realizó una auditoría automatizada en tiempo real mediante Playwright emulando las dimensiones exactas de la portátil de caja Toshiba (1366 × 611 px). Se evaluó la fuga en el `scrollHeight` de la raíz, la posibilidad de escrolear la ventana y la presencia de la franja blanca por sobre-desplazamiento.

### Tabla de Resultados por Módulo

| Módulo / Vista | Ruta | `html.scrollHeight` | Viewport | Fuga detectada | ¿Sufre franja blanca / descontrol? | Estado |
|---|---|---|---|---|---|---|
| **POS (Vender)** | `/pos` | 611 px | 611 px | 0 px | No | ✅ Seguro |
| **Caja (Turno)** | `/turno` | **638 px** | 611 px | **+27 px** | **Sí (Jiggle / Franja 27 px)** | ⚠️ **Vulnerable** |
| **Delivery** | `/delivery` | 611 px | 611 px | 0 px | No | ✅ Seguro |
| **Menú** | `/menu` | 611 px | 611 px | 0 px | No | ✅ Seguro |
| **Inventario** | `/inventario` | 611 px | 611 px | 0 px | No (actual) / Vulnerable al crecer | ⚠️ Riesgo latente |
| **Control Diario** | `/admin/ventas-retroactivas` | 611 px | 611 px | 0 px | No (actual) / Vulnerable con muchas ventas | ⚠️ Riesgo latente |
| **Finanzas - Resumen** | `/finanzas?tab=summary` | 611 px | 611 px | 0 px | No | ✅ Seguro |
| **Finanzas - PedidosYa** | `/finanzas?tab=pedidosya` | 611 px | 611 px | 0 px | No | ✅ Seguro |
| **Finanzas - Compras** | `/finanzas?tab=expenses` | 611 px | 611 px | 0 px | No (actual) / Vulnerable con muchas compras | ⚠️ Riesgo latente |
| **Finanzas - Gastos Fijos**| `/finanzas?tab=recurring` | 611 px | 611 px | 0 px | No | ✅ Seguro |
| **Finanzas - Costos y Margen**| `/finanzas?tab=costs` | **2.383 px** | 611 px | **+1.772 px** | **Sí (Franja blanca crítica ~300-500 px)** | 🔴 **Crítico** |
| **Finanzas - Categorías** | `/finanzas?tab=categories`| 611 px | 611 px | 0 px | No | ✅ Seguro |
| **Usuarios** | `/users` | 611 px | 611 px | 0 px | No | ✅ Seguro |
| **Auditoría** | `/audit-logs` | 611 px | 611 px | 0 px | No (actual) / Vulnerable con muchos logs | ⚠️ Riesgo latente |

### Conclusiones de la Auditoría
1. **Fallo Crítico Activo:** Ocurre con severidad máxima en **Finanzas (Costos y margen)** con 1.772 px de fuga y desprendimiento total del shell.
2. **Fallo Menor Activo:** Ocurre en **Caja (Turno)** donde la tarjeta de historial y la tabla de ventas desbordan por 27 px, generando que la ventana "baile" verticalmente y deje una pequeña ranura blanca bajo el menú.
3. **Fallo Latente en Todo el Sistema:** Cualquier módulo que aumente el número de filas o registros (Inventario, Ventas Retroactivas, Auditoría, Compras) desbordará de la misma manera si la ventana no se blinda globalmente.

---

## 3. Arquitectura de la Solución (Blindaje en 3 Capas)

Para erradicar este problema definitivamente en toda la aplicación, la solución debe aplicarse de forma piramidal:

```
[ Capa 1: style.css ] --------> Bloquea la ventana (html, body, #app) al 100dvh con overflow: hidden
[ Capa 2: AppShell.vue ] -----> Aísla el Shell con position: fixed, inset: 0, contain: paint y min-height: 0
[ Capa 3: finance.css/Turno ] -> Corrige flex-basis, min-height: 0, padding y sticky nav
```

### Capa 1: Blindaje Global del Viewport (`frontend/src/style.css`)
En una aplicación tipo POS de pantalla única, la ventana del navegador (`window.scrollY`) **nunca debe escrolear bajo ninguna circunstancia**.
```css
html, body {
  margin: 0;
  padding: 0;
  height: 100%;
  height: 100dvh;
  max-height: 100dvh;
  overflow: hidden;
  overscroll-behavior: none;
}

#app {
  height: 100%;
  height: 100dvh;
  max-height: 100dvh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
```
- `overflow: hidden` en `html, body, #app`: Impide que el scroll de la ventana se active jamás.
- `overscroll-behavior: none`: Detiene el rebote elástico y el encadenamiento de gestos táctiles y trackpads.
- `100dvh`: Soporta dinámicamente barras de navegación o herramientas en pantallas táctiles o móviles.

### Capa 2: Aislamiento y Contención del Shell (`frontend/src/components/AppShell.vue`)
El contenedor principal del sistema debe crear un contexto de contención estricto para que ningún hijo pueda alterar las dimensiones exteriores:
```css
.oh-shell {
  position: fixed;
  inset: 0;
  width: 100vw;
  height: 100vh;
  height: 100dvh;
  overflow: hidden;
  contain: paint;
  display: flex;
  background: var(--cream);
  color: var(--ink);
}

.oh-sidebar {
  /* Mantener estilos existentes */
  overscroll-behavior: contain;
}

.oh-shell-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 0; /* CRUCIAL para evitar que flexbox propague el alto de hijos */
  overflow: hidden;
}

.oh-shell-content {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  display: flex;
  flex-direction: column;
  min-height: 0; /* CRUCIAL: permite que el scroll ocurra aquí adentro */
  overscroll-behavior: contain; /* Evita que el scroll se propague */
}
```

### Capa 3: Ajustes Específicos en Módulos (`finance.css` y `Turno.vue`)

#### A. Finanzas (`frontend/src/components/Finance/finance.css`)
1. **Quitar expansión forzada de `.fin-page`:**
   ```css
   .fin-page {
     flex: 1;
     display: flex;
     flex-direction: column;
     min-width: 0;
     min-height: 0; /* Cambiar min-height: 100% por min-height: 0 */
   }
   ```
2. **Encabezado y Pestañas Pegajosas (`.fin-nav`):**
   Para que el usuario no pierda de vista en qué pestaña está ni los botones al bajar por tablas largas:
   ```css
   .fin-page .fin-nav {
     position: sticky;
     top: 0;
     z-index: 15;
     background: var(--surface);
     box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
   }
   ```
3. **Compactar padding inferior en pantalla portátil:**
   El padding actual `.fin-body { padding: 22px 28px 64px; }` añade 64 px innecesarios al final. Se reduce a:
   ```css
   .fin-page .fin-body {
     padding: 16px 20px 24px;
     display: flex;
     flex-direction: column;
     gap: 16px;
   }
   ```

#### B. Caja / Turno (`frontend/src/views/Turno.vue`)
En la vista de Caja, el contenedor de historial de ventas causa un desborde de 27 px:
1. Asegurar `min-height: 0` en el contenedor de pestañas y tabla de ventas.
2. Comprobar que `.table-responsive` tenga `max-height` o scroll propio cuando la lista de ventas del turno supera las 10 transacciones.

---

## 4. Evidencia Experimental de la Solución

Durante el diagnóstico en el entorno local (Task-329 y scripts de verificación Playwright):
- **Antes del arreglo:**
  - `htmlScrollHeight`: 2.383 px (Fuga de +1.772 px).
  - Al escrolear al final de la tabla de costos: `window.scrollY = 1.772 px`.
  - Visual: Franja blanca gigante (~300 px) debajo de la barra lateral y de la tabla.
- **Con el arreglo propuesto aplicado:**
  - `htmlScrollHeight`: **611 px** (Exactamente idéntico al viewport, **0 px de fuga**).
  - `window.scrollY`: **0 px** (Permanente, inamovible).
  - `shellContentScrollTop`: **2.567 px** (La tabla se escrolea limpiamente hasta la última fila).
  - Visual: Menú lateral 100% pegado al fondo, barra superior visible, fondo uniforme `--cream-50` (o modo oscuro), **cero franja blanca**.
  - Evidencia gráfica guardada en: `finanzas_costs_at_bottom_fixed.png`.

---

## 5. Plan de Implementación Paso a Paso

### Fase 1: Blindaje Global del Shell y Resets
1. Editar `frontend/src/style.css`:
   - Configurar `overflow: hidden`, `overscroll-behavior: none` y `height: 100dvh` en `html, body` y `#app`.
2. Editar `frontend/src/components/AppShell.vue`:
   - Aplicar `position: fixed; inset: 0; contain: paint;` a `.oh-shell`.
   - Agregar `min-height: 0;` a `.oh-shell-main` y `.oh-shell-content`.
   - Agregar `overscroll-behavior: contain;` a `.oh-sidebar` y `.oh-shell-content`.

### Fase 2: Ajuste de Módulos (Finanzas y Turno)
3. Editar `frontend/src/components/Finance/finance.css`:
   - Establecer `min-height: 0;` en `.fin-page`.
   - Ajustar `.fin-page .fin-nav` con `position: sticky; top: 0; z-index: 15;`.
   - Compactar padding de `.fin-body` a `16px 20px 24px`.
4. Revisar `frontend/src/views/Turno.vue`:
   - Verificar y corregir la altura de `.history-card` y `.table-responsive` para que `Turno` no exceda 611 px.

### Fase 3: Pruebas y Verificación Automatizada
5. Ejecutar la suite de prueba de módulos con Playwright en resoluciones de portátil:
   - 1366 × 611 px (Windows 100%).
   - 1093 × 489 px (Windows 125%).
   - Verificar que en los 14 módulos `html.scrollHeight === window.innerHeight` y `window.scrollY === 0`.
6. Validar compatibilidad con modo oscuro (`html.dark`) y modo liviano (`perf-lite`).
7. Ejecutar `npm run build` en `frontend/` para asegurar que no hay errores de compilación Vite.

### Fase 4: Documentación y Registro
8. Actualizar `CHANGELOG.md` en la sección "Sin publicar".
9. Actualizar `docs/BITACORA.md`.
10. Ejecutar `graphify update .` conforme a las reglas del repositorio.

---

## 6. Checklist de Validación

- [ ] `window.scrollY` es estrictamente `0` en todos los módulos y al forzar scroll con teclado (`End`, `PageDown`) o rueda de ratón.
- [ ] La barra lateral morada (`.oh-sidebar`) siempre llega hasta el borde inferior de la pantalla.
- [ ] No existe ninguna franja blanca en el fondo de ninguna pantalla del sistema.
- [ ] El scroll de tablas largas (Finanzas, Ventas retroactivas, Inventario, Turno) se ejecuta con fluidez dentro del contenedor principal.
- [ ] Las pestañas de Finanzas permanecen fijas arriba (`position: sticky`) al navegar por tablas extensas.
- [ ] Los modales (Cobro, Cierre de Caja, Composición, Recetas) continúan centrados y con scroll independiente.
- [ ] `npm run build` pasa limpiamente sin errores de sintaxis.
