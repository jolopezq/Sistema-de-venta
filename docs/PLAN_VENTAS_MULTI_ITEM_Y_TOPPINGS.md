# Plan v2: Comanda multi-ítem, opciones y toppings en Control Diario — diseñado para la portátil de caja

> **Módulos:** [AdminSaleEditor.vue](../frontend/src/components/AdminSaleEditor.vue), [RetroactiveSales.vue](../frontend/src/views/RetroactiveSales.vue), [ModifierModal.vue](../frontend/src/components/ModifierModal.vue), [Turno.vue](../frontend/src/views/Turno.vue) (monta el mismo editor), [Pos.vue](../frontend/src/views/Pos.vue).
> **Reglas:** [AGENTS.md](../AGENTS.md) y [.agents/rules/buenas-practicas.md](../.agents/rules/buenas-practicas.md).
> **Versión anterior:** [PLAN_VENTAS_MULTI_ITEM_Y_TOPPINGS_v1.md](PLAN_VENTAS_MULTI_ITEM_Y_TOPPINGS_v1.md).
> **Rama:** `feat/control-diario-flujo-opciones-toppings` (cambios sin commit).
> **Estado:** implementado y probado (2026-10-23): fases 0 a 4; queda la Fase 5 (backend, contraste, publicación) y la prueba en la Toshiba real. Las mediciones de la sección 4 se tomaron con Playwright a 1366×611 y 1093×489; la sección 4.5 resume el impacto en el sistema de diseño y la 8, lo que cambió al implementar.

---

## 0. Qué cambia respecto a la v1

| # | Hallazgo | Efecto en el plan |
| :-- | :-- | :-- |
| 1 | La v1 no dice cómo debe verse en la portátil de caja. Con las medidas reales, **el catálogo de productos queda a 493 px de una pantalla útil de 570 px** (se ve solo su borde) y el botón *Registrar* a 2 pantallas de distancia. | Nueva sección 4 con presupuesto vertical, cambios de diseño y criterios medibles a 100 % y 125 %. |
| 2 | `ModifierModal` **ya cabe en la portátil** (verificado): botón de confirmar visible, cuerpo con scroll interno. | Se mantiene la decisión de reutilizarlo; no hay que rediseñarlo. |
| 3 | El modal deja confirmar **«Toppings» a Bs 0.00 sin elegir nada** (el grupo tiene mínimo 0). El criterio de aceptación 2 de la v1 no se cumplía con solo abrir el modal. | Fase 1: regla compartida en `ModifierModal` (también protege Ventas/POS). |
| 4 | En `main` ya está el comando `catalog:sync-toppings`, su prueba y una entrada de CHANGELOG, que la v1 declara descartados. | Fase 0: retirarlos para que nadie ejecute `--apply` y duplique el catálogo. |
| 5 | La pseudo-implementación 5.3 confía en el precio del modal, ignora `priceMode`/motivo de ajuste al editar y no resuelve colisiones de firma al editar. | Sección 3: especificación corregida. |
| 6 | La tabla de entornos omite el **VPS (Vultr)**, que es otro entorno de producción con su propia base. | Sección 2: verificación pendiente en el VPS. |
| 7 | Diagrama v1: «Capuchino simple» sin opciones. En la base, Capuchino (id 32) sí tiene grupo de tamaño. | Ejemplo corregido; la regla es por datos, no por nombre. |

---

## 1. Contexto operativo (sin cambios)

1. **Venta en mostrador (`Pos.vue`)**: tocar un producto con opciones abre `ModifierModal` (tamaño, frutas, toppings, nota, mesa/llevar) y entra al ticket con precio real.
2. **Registro diferido (Control Diario, `AdminSaleEditor.vue`)**: talonario manual, cortes de energía, corrección de turnos. Una comanda trae varios ítems (bowl con opciones, toppings, bebida).

Problema: en Control Diario las opciones se arman con un acordeón de casillas de 500 px de alto y un producto como «Toppings» (base Bs 0.00) entra vacío. Además, hoy el editor ocupa 2 a 5 pantallas en la portátil.

---

## 2. Estado verificado (2026-10-23)

| Afirmación | Resultado |
| :-- | :-- |
| `main` = `origin/main` = `3fa5b0b` (Mac) | Verificado. |
| Producto «Toppings» (id 33, categoría 11, base Bs 0) con el grupo de opciones 4 | Verificado en la base local. El grupo 4 aparece también en Classic Bowl (id 5) y Arma tu Bowl (id 10). |
| «Tamaño Acai» tiene `min_selections = 0`, `max = 1`; tamaños **Mediano +7, Grande +14, Ohana +32** | Verificado. **No existe una opción «Junior»**: Junior es no elegir tamaño (Bs 28). |
| Frutas: mínimo 0 en base de datos, pero `ModifierModal` exige **al menos 1** por regla de código (`getMinRequired`) | Verificado: en Control Diario habrá paridad con el POS (un bowl sin fruta no se podrá registrar). Confirmar con el dueño que es lo deseado para talonarios. |
| Forma de los `modifiers` del modal (`option_id`, `option_name`, `group_name`, `price`, `quantity`) | Idéntica a la que arma `recalcItemPricesAndModifiers` y envía `handleSave`. Compatible. |
| Migraciones locales: 64 aplicadas, 2 pendientes de permisos | Verificado. Producción ya las aplicó. Correr `php artisan migrate` en la Mac antes de probar. |
| Laptop Windows de caja en `3fa5b0b` por el vigilante | **No verificable desde aquí.** |
| VPS: tiene el producto «Toppings» | **Pendiente.** Comprobar: `ssh root@64.176.3.152 "sudo -u ohana sqlite3 /var/www/ohana-pos/datos/database.sqlite \"select id,name,price from products where name='Toppings';\""`. Si falta, crearlo desde Menú (nombre «Toppings», precio 0, grupo de opciones «Toppings»). |

**Decisión de datos (se mantiene de la v1):** los toppings se venden con el producto «Toppings» + su grupo de opciones. No se crean 13 productos sueltos.

---

## 3. Especificación técnica corregida

### 3.1 Integración del modal en `AdminSaleEditor.vue`
- Importar `ModifierModal` y montarlo con `:channel="saleOrigin === 'pedidosya' ? 'pedidosya' : 'pos'"`.
- Estado: `showModifierModal`, `selectedProductForModal`, `editingItemIndex` (null = nuevo) y `modalInitialData`.
- `addProductToSale(product)`: si el producto tiene grupos activos **o** es por peso → abrir el modal. Si no, apilar directo (lógica actual de fusión por firma, ya implementada).

### 3.2 `handleConfirmModal` (correcciones a la v1)
1. **Una sola fuente de precio.** Tras armar el ítem con `selectedOptionIds` y `modifiers`, llamar a `recalcItemPricesAndModifiers(item)`; no asignar `unit_price = finalPrice`. Así el cambio de canal (PedidosYa) y la cantidad siguen recalculando bien. En desarrollo, comparar con `finalPrice` y avisar en consola si difieren.
2. **Por peso:** `priceMode = 'weight'`, `weightGrams` y `pricePerGram` del producto; nunca fusionar (la firma ya devuelve `null`).
3. **Para llevar:** el ítem queda `is_takeaway = formData.is_takeaway || isTakeaway`. El modal arranca en «En mesa» y no conoce la venta; si la venta es «Para llevar» o PedidosYa, manda la venta.
4. **Cantidad:** al crear, 1. Si la firma coincide con una fila existente, sumar 1 y destellar esa fila (`flashAdded`).
5. **Estado de la fila:** `isExpanded = false`; ya no hay casillas de opciones dentro de la fila (ver 3.4).

### 3.3 Re-edición (`openEditItemModal`)
- Precarga `modifiers`, nota y `is_takeaway` del ítem.
- Al confirmar, **conserva** `quantity`, `priceMode`, `unit_price` manual y `priceOverrideReason` si el ítem estaba en modo manual; en modo `auto` recalcula.
- **Colisión:** si tras editar la firma coincide con otra fila, fusionar cantidades y quitar la fila editada.
- Ventas **ya guardadas** (modo edición): opciones inactivas o borradas no aparecen en el modal; se conservan como modificadores históricos solo si el usuario no abre el editor de esa fila. Mostrar aviso si hay modificadores sin opción vigente.

### 3.4 Qué pasa con el acordeón actual
- Se **eliminan** de la fila las casillas de opciones (frutas, toppings, tamaño): ahora van por el modal.
- Se **conservan**, dentro de un botón «⚙ Precio y nota» (plegado por defecto): modo de precio (catálogo / peso / manual), precio manual, motivo de ajuste (PedidosYa) y nota. Son necesarios para talonarios con precios escritos a mano.

### 3.5 Regla compartida «extras obligatorios» en `ModifierModal` (Fase 1)
Hoy `isValid` solo mira el mínimo por grupo. Agregar: si el **precio base del canal es 0** y no hay ningún modificador elegido, el botón se bloquea con el texto «Elige al menos una opción». Efecto:
- Control Diario: «Toppings» nunca entra a Bs 0.00.
- Ventas (POS): mismo comportamiento (hoy se puede cobrar «Toppings» vacío a Bs 0.00).
- No afecta a productos con precio base > 0.

---

## 4. Diseño para la portátil de caja (NUEVO)

### 4.1 Pantallas objetivo
| Perfil | Viewport útil | Alto del área desplazable (`.oh-shell-content`) |
| :-- | :-- | :-- |
| Toshiba, Windows 100 % | 1366 × 611 | **570 px** |
| Toshiba, Windows 125 % | ≈ 1093 × 489 | **448 px** |
| No regresión | 1440 × 900 y 390 × 844 | 847 y 803 px |

A 1366 el editor comparte fila con el panel de Arqueo (editor 769 px de ancho). A 1093 el panel baja debajo del editor y el editor ocupa 870 px.

### 4.2 Medición del estado actual (caja abierta, editor vacío, scroll 0)
| Bloque | 1366 × 611 (de 570 px) | 1093 × 489 (de 448 px) |
| :-- | :-- | :-- |
| Cabecera de la pantalla (título, fecha) | 24 – 123 | ≈ 12 – 167 |
| Cabecera del editor + aviso de auditoría | 148 – 245 | ≈ 192 – 289 |
| Datos de la venta (fecha, usuario, origen, cliente, notas) | 263 – 407 (**144 px**) | ≈ 307 – 451 |
| Encabezado de ítems + catálogo | 448 / **493**, hasta ≈ 749 con catálogo real | ≈ 492 / **537** |
| Botón *Registrar* | 1145 (≈ 2.0 pantallas); con 5 ítems ≈ 1500 (**2.6**) | ≈ 1189 (**2.7**); con 5 ítems ≈ 1540 |
| Alto total de la página | 1554 (2.7 pantallas); con 5 ítems 2070 (3.6) | 2257 (5.0); con 5 ítems 2639 (5.9) |

Conclusiones:
1. Los datos de la venta ocupan 144 px que el cajero casi nunca cambia, **antes** de lo que sí usa (catálogo e ítems).
2. El catálogo arranca al 87 % (100 %) y al 120 % (125 %) del área visible. En 125 % solo se ve porque el `autofocus` del buscador desplaza la página; en 100 % ni eso.
3. Al apilar, la fila recién agregada queda fuera de la vista (el catálogo mide hasta 256 px y las filas van debajo). La línea de resumen dentro del catálogo ya ayuda, pero no basta.
4. *Registrar* no es alcanzable sin desplazarse varias pantallas.
5. Control: `ModifierModal` con Classic Bowl (8 frutas, 16 toppings) mide 562 px de alto a 1366 × 611 y 450 px a 1093 × 489; el cuerpo desplaza por dentro (577 px de contenido en 340) y el botón de confirmar queda dentro de la pantalla en ambas medidas. **No requiere cambios de tamaño.**

### 4.3 Presupuesto vertical objetivo
Barra de acción fija: **52 px**. Espacio útil: 570 − 52 = **518 px** (100 %) y 448 − 52 = **396 px** (125 %).
Tras posicionar la vista en «Productos», debe caber a la vez:

| Bloque | 100 % | 125 % |
| :-- | :-- | :-- |
| Fila de categorías + buscador (una sola línea) | 40 | 40 |
| Catálogo (2 filas de tarjetas de 44 px, scroll interno) | 108 | 100 |
| Filas de comanda (≈ 60 px c/u) | **≥ 4** filas (≈ 240) | **≥ 3** filas (≈ 180) |
| Total aproximado | 388 de 518 | 320 de 396 |

### 4.4 Cambios de diseño (solo en modo compacto)
Aplican con las mismas reglas de la portátil usadas en el resto del sistema: `@media (min-width:761px) and (max-width:1366px), (min-width:761px) and (max-height:800px)` y `(min-width:761px) and (max-height:520px)` para 125 %. Escritorio de 1440 y teléfono quedan como hoy.

- **L1. Datos de la venta plegables.** Fila resumen de 40 px («21/10 23:24 · Mismo Super Admin · En mesa · Cliente ocasional · Notas»); al tocarla se despliegan los campos actuales. En venta nueva, plegado. Si falta un dato obligatorio al guardar, se despliega solo.
- **L2. Cabecera + auditoría en una línea** (≈ 40 px en vez de 97): el aviso pasa a una insignia «🔒 Auditoría: se pedirá motivo».
- **L3. Categorías como pestañas, no lista desplegable.** Reutilizar `CategoryFilter` (igual que Ventas) en la misma fila que el buscador. «Toppings» queda a **un toque**, que es el caso de uso pedido. Quitar el `autofocus` del buscador (provoca saltos de scroll impredecibles).
- **L4. Catálogo compacto:** 4 columnas desde 700 px de ancho, 5 desde 800 px; tarjeta de 44 px, nombre en una línea con elipsis; altura máxima del área con scroll: 108 px (100 %) y 100 px (125 %).
- **L5. Fila de comanda compacta:** nombre + resumen en máximo 2 líneas (`line-clamp`), stepper de cantidad, subtotal, ✏ editar y 🗑 quitar; botones de al menos 32 × 32 px (44 px en teléfono). Sin selector de producto por fila (cambiar producto = quitar y agregar).
- **L6. Barra de acción fija (modo en línea):** `position: sticky; bottom: 0` dentro de la columna del editor, 52 px, `z-index: 30` (por debajo de modales = 200 y de la navegación de Finanzas = 40). Contiene: «🧾 N ítems · Bs X», un chip temporal «＋ Chubi ✓» con lo último agregado, y el botón **Registrar** (reutiliza `handleSave`, que ya hace `scrollToError`). Reemplaza al pie estático solo en modo en línea; el modal de edición conserva su pie. Añadir `padding-bottom` al contenido igual a la altura de la barra.
- **L7. Posicionamiento al abrir y al agregar.** Al montar el editor en una venta nueva y al abrir el catálogo, `scrollIntoView({ block: 'start' })` sobre el encabezado de «Productos» (con `scroll-margin-top`). Tras confirmar el modal o agregar un ítem **no** se mueve la vista; el destello de la fila y el chip de la barra dan la confirmación.
- **L8 (opcional, velocidad). Motivos rápidos.** Tres botones junto al campo de motivo («Talonario manual», «Corte de energía», «Corrección de turno»), porque el motivo de 5 caracteres es obligatorio en cada venta.

### 4.5 Impacto en el sistema de diseño

**Diagnóstico.** Control diario hablaba otro idioma visual que Ventas:

| Aspecto | Antes en `AdminSaleEditor.vue` | Sistema (Ventas, `style.css`) |
| :-- | :-- | :-- |
| Color de acción | `var(--primary, #8b5cf6)`: **`--primary` no existe** en el sistema, siempre caía al violeta de Tailwind (13 usos). Blanco sobre `#8b5cf6` ≈ 4,2:1 (bajo AA) y sin modo oscuro. | Açaí `--acai-700` para selección, passion `--passion-500` con `--on-accent` para cerrar (Cobrar). |
| Paleta | 163 usos del «puente Tailwind» (`--tw-*`) contra 30 tokens de marca. | Tokens `--ink-*`, `--border`, `--surface`, `--cream-*`. |
| Tipografía | Inter en todo; importes sin Baloo. | Baloo 2 para nombres de producto e importes; Inter para el resto. |
| Ítems | Tarjetas con sombra y acordeón de casillas. | Ticket: filas con línea punteada y píldoras de opciones (`CartItem.vue`). |
| Categorías | Lista desplegable. | Pestañas `CategoryFilter` (`.cat-tabs`). |

**Decisiones aplicadas.**
1. **Un solo arreglo de fondo:** en la raíz del editor `--primary` → `--acai-700`, `--primary-hover` → `--acai-800`, `--primary-200` → `--acai-300`, `--primary-50` → `--acai-50`, `--primary-700/900` → `--acai-text`. Así los 13 violetas quedan en marca y con modo oscuro, también en el editor en ventana de Turno.
2. **La comanda es un ticket:** filas con línea punteada, nombre e importe en Baloo 2, opciones como las píldoras de Ventas (tamaño en tono açaí, extras con «+4.00»), cantidad con botones de 32 px y herramientas con íconos de trazo (editar, precio y nota, quitar) con `aria-label`.
3. **Un único elemento fuerte:** la barra fija en `--acai-900` (el mismo tono de la píldora «Total» del configurador) con el total en Baloo 2 y **Registrar venta** en passion, igual que «Cobrar». El resto queda sobrio.
4. **Mismos componentes que Ventas:** `CategoryFilter` y `ModifierModal`; no se crean variantes.
5. **Movimiento:** solo el destello de la fila agregada (responde a una acción); se respeta `prefers-reduced-motion`.
6. **Textos:** en minúscula inicial y con verbos claros («Registrar venta», «Limpiar», «Cambiar», «Mostrar catálogo»). El estado vacío dice qué hacer. En el catálogo, un producto sin precio propio dice «Elige opciones» en lugar de «Bs 0.00».
7. **Modo oscuro:** todo lo nuevo usa tokens. De paso, los campos «Descuento» y «Total a cobrar» (fondo blanco con texto claro) pasan a `--surface`/`--ink-900`.

**Deuda que queda (fuera de alcance):** el resto del editor (pagos, avisos) y `RetroactiveSales.vue` siguen con el puente Tailwind y con `--color-surface-2`/`--color-border`, tokens que tampoco existen. Migrarlos es un trabajo aparte de bajo riesgo visual.

### 4.6 Criterios medibles (se convierten en pruebas E2E)
En **1366×611** y **1093×489**, con el editor de Control Diario:

| ID | Criterio |
| :-- | :-- |
| P1 | Con el editor recién abierto, sin desplazar manualmente: la primera fila de tarjetas del catálogo está completa dentro del área visible, por encima de la barra fija. |
| P2 | Con 10 ítems apilados y **mientras el editor está en pantalla** (inicio, mitad y final), el botón *Registrar* queda dentro de la pantalla y `elementFromPoint` en su centro devuelve el botón (nada lo tapa). A 125 % el panel de Arqueo queda debajo del editor; al bajar hasta él la barra se va con el editor, que es lo esperado. |
| P3 | Se puede desplazar hasta que la última fila de comanda quede por encima de la barra fija (sin quedar tapada). |
| P4 | Catálogo y filas visibles a la vez: ≥ 4 filas (100 %), ≥ 3 filas (125 %) con 4 ítems apilados. |
| P5 | «Toppings» a un toque desde las pestañas de categoría; sin recargar ni abrir listas. |
| P6 | Sin desborde horizontal; la ventana no se desplaza (se mantiene `layout-scroll.spec.js`). |
| P7 | `ModifierModal` (Classic Bowl con 8 frutas y 16 toppings): el botón de confirmar queda dentro de la pantalla. |
| P8 | Botones de cantidad, editar y quitar ≥ 32 × 32 px en la portátil. |
| P9 | No regresión a 1440×900 y 390×844: sin desborde y *Registrar* alcanzable. |

---

## 5. Fases

### Fase 0 — Higiene de publicación
1. Retirar `backend/app/Console/Commands/SyncToppingProducts.php`, `backend/tests/Feature/Catalog/SyncToppingProductsCommandTest.php` y la entrada «Toppings sueltos» del CHANGELOG (queda la de la comanda multi-ítem).
2. Quitar la sección 6 de la v1 (decisiones del comando) y la prueba 3 de `control-diario-apilado.spec.js` («Topping suelto» como producto), que se reescribe en la Fase 4.
3. Verificar el producto «Toppings» en el VPS (sección 2).

### Fase 1 — Regla de extras obligatorios en `ModifierModal` (3.5)
Prueba E2E en `/pos`: «Toppings» no se confirma vacío; con 1 topping suma Bs 4.00. Prueba de no regresión: Classic Bowl sin cambios.

### Fase 2 — Integración del modal en `AdminSaleEditor.vue` (3.1–3.4)
Incluye canal, edición con colisiones y plegado del acordeón de opciones.

### Fase 3 — Diseño de portátil (4.4, L1–L7; L8 si se aprueba)
Orden sugerido: L6 (barra fija) → L1/L2 → L3/L4 → L5 → L7.

### Fase 4 — Pruebas
1. **E2E** `frontend/e2e/control-diario-apilado.spec.js`, ampliado a los 4 perfiles de 4.1 con los criterios P1–P9 y los casos funcionales:
   - A: Classic Bowl + Mediano + Frutilla → Bs 35.00; editar a Grande → Bs 42.00; Ohana → Bs 60.00.
   - B: «Toppings» con Chips + Chubi → Bs 8.00; `＋` → cantidad 2, Bs 16.00; sin elegir nada, no se agrega.
   - C: comanda mixta, motivo, efectivo → *Registrar*; verificar `sale_item_options` y el efectivo en el panel de Arqueo (con base y backend reales: prueba manual o de feature en PHPUnit).
   - D: origen PedidosYa: los precios del modal usan el canal PedidosYa.
2. **Backend:** `cd backend && php artisan test` (si no se cambia backend, igualmente confirmar que sigue en verde).
3. **Visual en la portátil real:** abrir Control Diario a 100 % y a 125 % de zoom de Chrome; apilar bowl + toppings + bebida; registrar sin desplazar más de una vez.

### Fase 5 — Calidad y publicación
`npx vite build`, `scripts/audit-contrast.cjs` (barra fija y chips nuevos), `graphify update .`, `docs/BITACORA.md`, `CHANGELOG.md` en «Sin publicar». Publicación: `main` → laptop (vigilante) y VPS (paquete). No hay migraciones.

---

## 6. Criterios de aceptación

1. **Paridad con el POS:** bowl o toppings se configuran con el mismo modal en ambas pantallas.
2. **Cero ítems a Bs 0.00 accidentales** en Control Diario **y** en Ventas (regla 3.5).
3. **Precios fieles:** Junior Bs 28, Mediano Bs 35, Grande Bs 42, Ohana Bs 60; PedidosYa con su tarifa.
4. **Comanda multi-ítem ágil:** el catálogo no se cierra; las filas son compactas; mismo ítem suma cantidad.
5. **Portátil de caja:** se cumplen P1–P9 a 1366×611 y 1093×489.
6. **Trazabilidad e inventario:** las ventas descuentan insumos por las recetas de cada opción.
7. **Sin trabajo muerto en producción:** el comando `catalog:sync-toppings` ya no existe en `main`.

## 7. Riesgos y decisiones abiertas

| Tema | Detalle |
| :-- | :-- |
| Fruta obligatoria | El modal exige ≥ 1 fruta en bowls. Si un talonario no la anota, el administrador no podrá registrar ese bowl. Decidir si Control Diario relaja esa regla (parámetro del modal) o se mantiene la paridad. |
| Barra fija en 125 % | Quita 52 px de 448. Si molesta, bajar a 44 px o mostrar solo total + *Registrar* y llevar el chip al catálogo. |
| Ventas guardadas con opciones ya inactivas | Ver 3.3; se avisa y no se pierden modificadores. |
| Archivo grande | `AdminSaleEditor.vue` tiene ≈ 2650 líneas; extraer la fila de comanda a un componente (`SaleItemRow.vue`) al hacer L5 reduce el riesgo de regresión. |
| Cuenta real de la portátil | Las mediciones son de Chromium en la nube con la fuente del sistema; confirmar en la Toshiba (fuentes y barra del navegador pueden restar píxeles). |


---

## 8. Implementación (2026-10-23) y diferencias con lo planeado

| Ítem | Qué se hizo | Diferencia con el plan |
| :-- | :-- | :-- |
| Fase 0 | Retirados `SyncToppingProducts.php` y su prueba. | La entrada del CHANGELOG ya estaba publicada (2026-10-21): no se reescribe; se registra «Retirado» en «Sin publicar». |
| 3.5 | `ModifierModal`: producto con precio base 0 y sin extra con precio → botón bloqueado, «Elige al menos una opción». | Se exige un extra **con precio**: «Sin granola» (Bs 0) solo no alcanza. |
| 3.1–3.3 | Configurador integrado, precio recalculado por el editor, edición con fusión de filas iguales, precio manual y motivo conservados. | — |
| 3.4 | Filas sin casillas de opciones; «Precio y nota» plegado. Al editar una venta guardada, las filas llegan cerradas salvo las de precio manual. | Se quitó el selector de producto por fila (cambiar producto = quitar y agregar). |
| L1 | Datos plegados en una línea. | **El origen (En mesa / Para llevar / PedidosYa) queda siempre visible** al lado del resumen: cambia los precios y no debe esconderse. |
| L2 | Aviso de auditoría en una línea corta (en modo ventana se mantiene el texto completo). | — |
| L3–L5 | Pestañas `CategoryFilter` (solo categorías con productos), búsqueda en todo el menú, catálogo de 2 filas, fila de comanda con columnas fijas. | Sin `autofocus` en el buscador. |
| L6 | Barra fija con total, último agregado, Limpiar y Registrar venta. El mensaje de venta registrada aparece en la barra. | En `RetroactiveSales.vue`, `.editor-wrapper` pasa de `overflow: hidden` a `overflow: clip`: `hidden` creaba un contenedor de scroll y anulaba la barra fija. |
| L7 | **No se usa desplazamiento automático.** | En su lugar, en la portátil la cabecera de la pantalla queda en una línea (sin descripción ni insignia «Modo Admin») y a 125 % se oculta el título del editor, que repetía la fecha. Así el catálogo entra sin mover la vista y la cabecera con la fecha sigue a la vista. |
| L8 | Motivos rápidos «Talonario manual», «Corte de energía» y «Corrección de turno». | En modo semi-lote **el motivo se conserva** entre ventas del mismo talonario. |

**Medidas después del cambio (editor vacío, caja abierta):**

| Bloque | 1366 × 611 (570 útiles) | 1093 × 489 (448 útiles) |
| :-- | :-- | :-- |
| Cabecera de la pantalla | 24 – 85 | 24 – 85 |
| Catálogo (cuadrícula) | **360 – 468** (antes desde 493 + filtros) | **316 – 416** (antes ≈ 580) |
| Barra fija | 512 – 570 | 390 – 448 |

**Pruebas:** `frontend/e2e/control-diario-apilado.spec.js`, 26 casos: funcionales A–E (bowl, edición, fusión, toppings, pestañas, registrar, PedidosYa, datos plegados), P1–P8 a 1366×611 y 1093×489, P9 a 1440×900 y 390×844, regla de toppings en Ventas (POS) y el editor en ventana de Turno. No regresión: `layout-scroll`, `checkout-portatil`, `cierre-caja-portatil`, `finanzas-portatil` y `permisos-portatil` en verde (110 casos), compilación correcta y unitarias 16/16.

**Pendiente:**
- `php artisan test` en la Mac (se borró una prueba de backend junto con el comando).
- `scripts/audit-contrast.cjs` con la base real.
- Prueba en la Toshiba a 100 % y 125 %.
- Confirmar la regla de fruta obligatoria (sección 7). Por ahora se mantiene igual que en Ventas.
