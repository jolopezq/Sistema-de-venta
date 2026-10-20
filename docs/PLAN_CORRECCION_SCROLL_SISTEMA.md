# Corrección del scroll del sistema: franja blanca y menú que se despega

> **Rama:** `feat/pos-caja-arranque`. **Estado (2026-10-20):** implementado y verificado en la nube (E2E `layout-scroll.spec.js`, 8 pruebas, 3 tamaños × 15 pantallas). Pendiente: probar en la caja real.
> **Versión anterior:** `PLAN_CORRECCION_SCROLL_SISTEMA.v1.md` (propuesta de otro agente). Este documento corrige su diagnóstico y explica qué se aplicó y qué no.

## 1. Síntoma

Al llegar al final de una página larga (caso reportado: Finanzas → Costos y margen) la rueda seguía bajando: el menú lateral se iba hacia arriba, desaparecían la barra superior y las pestañas, y abajo quedaba una franja blanca.

## 2. Causa verificada (no es "altura de flexbox")

1. Las etiquetas para lectores de pantalla (`.sr-only`, en los encabezados «Acciones» / «Quitar» / «Pedir» de las tablas) llevan `position: absolute`.
2. Ningún contenedor entre ellas y la ventana estaba posicionado: ni `.oh-shell-content` (el que se desplaza), ni `.fin-table-wrap`, ni `.table-responsive`. Su bloque contenedor era **la ventana**.
3. Un elemento así se ubica en su lugar "natural" (por ejemplo a 862 px de la parte de arriba, dentro de la tabla de componentes) pero cuenta para el alto **del documento**, no del contenedor que se desplaza. Medido con datos simulados: `html.scrollHeight` = **863 px** con una pantalla de 611 px (con datos reales el otro agente midió 2.383 px).
4. Al terminar el scroll interno, la rueda pasaba a la ventana (sin `overscroll-behavior`) y la desplazaba **252 px**: menú y barra superior subían y debajo quedaba el fondo blanco del navegador.

Detección en todas las pantallas (1366×611 y 390×844, prueba automática): **solo** escapaban `.sr-only` de Caja (historial de ventas), Finanzas (PedidosYa, Compras, Gastos fijos, Costos, Categorías) y las tablas de los modales de receta, composición y reposición. Los demás `position: absolute` del proyecto (33) ya tenían un contenedor posicionado. La fuga de +27 px de Caja que vio el otro agente es el mismo `.sr-only` «Acciones» del historial.

## 3. Otros errores de la misma familia encontrados

| Error | Dónde | Efecto | Corrección |
|---|---|---|---|
| La pantalla se encoge al alto visible y su contenido queda **fuera** de ella | Finanzas (`.fin-page`: 570 px con 1.102 px de contenido; 803 con 4.489 en celular), Caja (`.turno-container`) e Inventario (`.inventory-layout`) con listas largas | Fondo y margen inferior cortados; en Finanzas la navegación pegada **se perdía después de ~490 px de scroll** | `.fin-page { flex: 1 0 auto }`; `flex-shrink: 0` en Caja e Inventario |
| `min-height: 100vh` dentro del contenido | Auditoría (`.oh-main`) y «Sin acceso» | 41 px de scroll vacío (el alto de la barra superior) | Se quita; `flex: 1` ya llena |
| `min-height: 100vh` en `.oh-shell` | Menú lateral | En celular 100vh es más alto que lo visible: el pie quedaba bajo la barra del navegador | `min-height: 0` (el alto sale de `height: 100%`) |
| `scrollIntoView` con navegación pegada | «Clasificar egresos», «Revisar gastos fijos» | Con fuga, movía también la ventana; con la navegación pegada de Finanzas, la tarjeta quedaba tapada | Se corrige con la contención; `scroll-margin-top: 92px` en las tarjetas de Finanzas (portátil) |

## 4. Qué se aplicó (defensa en capas)

1. **Causa:** `.oh-shell-content { position: relative }`: todo `position: absolute` del contenido queda contenido y se desplaza con él. También `position: relative` en `.fin-table-wrap`, `.table-responsive` (Caja y Control diario) y `.fin-modal-body`, para que sus `.sr-only` no ensanchen la página en celular.
2. **Sin encadenar el scroll:** `overscroll-behavior: contain` en `.oh-shell-content` y `.oh-sidebar` (además evita el «tirar para recargar» del celular a mitad de una venta).
3. **Ventana fija solo con el menú:** AppShell agrega `html.oh-shell-active` al montarse y lo quita al desmontarse; `style.css` pone `overflow: hidden; overscroll-behavior: none` en `html` y `body` solo con esa clase. El login y las pantallas sin menú siguen desplazándose normal. Al imprimir (`@media print`) la raíz vuelve a `overflow: visible` para no recortar comprobantes.

## 5. Qué NO se aplicó de la propuesta v1 y por qué

| Propuesta v1 | Motivo para no aplicarla |
|---|---|
| `html, body { overflow: hidden }` **global** | Bloquearía el scroll del login y de «Sin acceso» en pantallas bajas. Se aplica solo con el menú montado (clase en `html`). |
| `.oh-shell { position: fixed; contain: paint }` | `contain: paint` convierte al shell en contenedor de los `position: fixed`: los modales (Cobro, Cierre de caja, Finanzas) quedarían recortados por el shell y cambiaría su capa. No hace falta: la causa se corrige con `position: relative` en el contenido. |
| `.fin-page { min-height: 0 }` | Empeora el problema: la página se encoge más. Lo correcto es que no se encoja (`flex: 1 0 auto`). |
| Fijar cabecera y pestañas de Finanzas | Ya estaba hecho (PLAN_REDISENO_FINANZAS_PORTATIL.md); faltaba que no se perdiera al bajar (fila anterior). |
| `.fin-body` padding 64 → 24 en la base | Ya compactado en la portátil; en monitores grandes se mantiene el diseño. |

## 6. Pruebas

`frontend/e2e/layout-scroll.spec.js` (API simulada con `e2e/fixtures/finanzas-api.js`):
1. En 15 pantallas × 3 tamaños (1366×611, 1093×489, 390×844): documento ≤ ventana (alto y ancho), ningún `position: absolute` fuera del contenido, ninguna pantalla encogida por debajo de su contenido, y tras 20 giros de rueda la ventana sigue en 0 y el menú arriba.
2. Costos y margen al final del scroll: sin franja blanca y con pestañas a la vista.
3. Con un modal abierto (receta) la ventana no crece.
4. «Clasificar egresos»: la ventana no se mueve y la tarjeta no queda bajo las pestañas.
5. Al imprimir, la raíz no recorta.
6. En el login la ventana no queda bloqueada.

Comprobado que la prueba 2 **falla sin la corrección** (la ventana baja 252 px) y pasa con ella. Suite completa: 85 de 88 pruebas; las otras 3 no tienen que ver con este cambio: 2 de `responsive-mobile.spec.js` necesitan un backend real, y la de Cobro es un corte intermitente del servidor de desarrollo (pasa al repetirla). `vite build` correcto.

## 7. Regla para el futuro

Todo contenedor con `overflow: auto/scroll/hidden` que tenga dentro elementos `position: absolute` (incluido `.sr-only`) debe ser `position: relative`, o los absolutos se ubican respecto de la ventana. La prueba `layout-scroll.spec.js` lo detecta en las pantallas con menú.
