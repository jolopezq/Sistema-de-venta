# Plan — Costos por receta, margen de contribución y punto de equilibrio

> Base: [ANALISIS_RECETAS_COSTOS_PUNTO_EQUILIBRIO.md](ANALISIS_RECETAS_COSTOS_PUNTO_EQUILIBRIO.md) (octubre 2026).
> Rama propuesta: `feat/costos-punto-equilibrio`, a partir de `feat/modulo-finanzas`.
> Decisiones del dueño (08/10/2026): las porciones **solo calculan costos** (no mueven stock); costos fijos =
> **gastos fijos mensualizados + otros gastos marcados como fijos**; el **café se costea con su propia receta**
> en unidades reales (vaso, tapa, azúcar, leche, 18 g de café por 1 o 2 tazas según la bebida, matcha, bombilla,
> siropes); el **Autoservicio se costea por peso** (helado) + envase + cucharilla; las cifras del documento
> entran como **valores iniciales editables**.
>
> **Estado (08/10/2026): implementado** en `feat/costos-punto-equilibrio` (fases 1 a 4). Probado en una copia de la
> base de la Mac: con las recetas base y gastos fijos de prueba (alquiler 2.500, sueldo 2.500, cuota bimestral 300),
> agosto 2026 da un margen de contribución de ≈ 68 % y un punto de equilibrio de ≈ Bs 7.600 al mes (Bs 247 por día,
> ≈ 7,4 productos). Los productos eliminados y vueltos a crear con el mismo nombre (Capuchino) se costean con la
> receta del vigente; los eliminados sin reemplazo (Frapu de cafe) quedan fuera y se avisan como «producto eliminado».

## 1. Revisión del análisis

**Lo que se mantiene.** El diagnóstico es correcto: la receta de prueba (Hawaiian Bowl con 3 insumos) no sirve,
todo bowl lleva por defecto granola, leche en polvo y leche condensada (lo confirman las descripciones de los 7
bowls y los pedidos con «Sin granola» / «Sin leche en polvo»), los descartables deben estar en el costo y el
**modelo por porciones (opción 2)** es el adecuado para una barra de despacho rápido.

**Lo que hay que ajustar para que funcione en el sistema:**

| # | En el documento | En el sistema | Ajuste |
|---|---|---|---|
| 1 | Unidad «porción» o «pack» | `ingredients.unit` solo admite `kg`, `litros`, `unidades`, `sacos` | Porciones en `unidades` (1 porción = 1 unidad); el helado del Autoservicio en `kg` |
| 2 | Tamaños con deltas en Bs (+2,50 / +5,00 / +11,00) | `option_recipes` guarda **cantidades** de insumo, no Bs | Mediano = +0,40 porción de base y vaso mediano en lugar del chico (1,80 + 0,70 = **2,50**); Grande = +0,80 base y vaso grande (3,60 + 1,40 = **5,00**); Ohana = +2,00 base y vaso Ohana (9,00 + 2,00 = **11,00**) |
| 3 | Las porciones descuentan stock | Cada venta descontaría porciones que nadie cuenta: existencias negativas y alertas falsas | Insumos marcados **«solo costeo»**: no descuentan stock (decisión del dueño) |
| 4 | Punto de equilibrio con un bowl estándar de Bs 28 | En agosto el bowl promedio se vendió a **Bs 35,43** (Mediano 21, Grande 28 y Ohana 9 de 102 bowls) | Margen **ponderado por lo que realmente se vendió** en el mes |
| 5 | Un solo margen | En PedidosYa la plataforma se queda ~30 % (semana 14–20/09) | Margen por canal: con los costos del documento, ~67 % en el local y ~39 % en PedidosYa |
| 6 | Costos fijos de ejemplo (Bs 6.000) | La base de la Mac no tiene gastos fijos cargados | El cálculo toma los gastos fijos reales; sin ellos, el resumen pide cargarlos |
| 7 | «Sin leche condensada» | Esa opción solo existe en PedidosYa (en el POS no) | Queda como nota en la venta importada; en el POS se costean «Sin granola» y «Sin leche en polvo» |
| 8 | Autoservicio por peso | Los gramos se envían desde el POS (`weight_grams`) pero **no se guardan** en `sale_items` | Guardar `weight_grams` en las ventas nuevas; en las anteriores se deducen del precio (precio ÷ precio por gramo) |

Con las cifras del documento y las ventas de agosto de la base de la Mac (102 bowls, 93,9 % de las ventas):
costo variable de los bowls **33 %**, margen por bowl **Bs 23,73** (el documento calcula 18,80). Con Bs 6.000 de
costos fijos, el punto de equilibrio sería **~253 bowls al mes (~8,4 por día)**, no 319 (11 por día).

## 2. Componentes y recetas iniciales (editables)

**Componentes de costo** (insumos «solo costeo»):

| Componente | Unidad | Costo inicial |
|---|---|---:|
| Porción base açaí (bowl chico) | unidades | 4,50 |
| Mix de frutas (2–3 frutas) | unidades | 2,00 |
| Pack toppings base (granola + leche en polvo + condensada) | unidades | 1,50 |
| Kit descartable chico (vaso + tapa + cuchara) | unidades | 1,20 |
| Kit descartable mediano / grande / Ohana | unidades | 1,90 / 2,60 / 3,20 |
| Topping extra (porción) | unidades | 1,00 |
| Helado por peso (Autoservicio) | kg | 25,00 |
| Envase Autoservicio + cucharilla | unidades | 1,20 |

**Recetas:**

| Producto u opción | Receta | Costo inicial |
|---|---|---:|
| Classic, Hawaian, Aloha, Marley, Akahai, Shaka, Patriota | 1 base + 1 mix + 1 toppings + 1 kit chico | 9,20 |
| Arma tu Bowl | 1 base + 1 toppings + 1 kit chico; **cada fruta elegida** +⅓ mix (0,67) | 9,20 con 3 frutas (igual que un bowl chico) |
| Tamaño Mediano / Grande / Ohana | ver ajuste 2 de la §1 | +2,50 / +5,00 / +11,00 |
| Sin granola, Sin leche en polvo | −0,33 pack toppings | −0,50 |
| Extra granola, Extra leche en polvo, Leche condensada, Leche en polvo | +0,33 pack toppings | +0,50 |
| Otros toppings extra (ositos, chips, chispas, coco, moritas, chubi…) | +1 topping extra | +1,00 |
| Autoservicio | kg vendidos × helado por peso + 1 envase y cucharilla | 350 g ≈ 9,95 |

### Café y bebidas

A diferencia de los bowls, el café se costea con **insumos en su unidad real**: gramos de café, mililitros de
leche, vasos y tapas. El sistema guarda kg y litros; en la pantalla se escriben gramos y mililitros, y una línea
puede indicar cuántas tazas rinde (18 g que rinden 2 tazas = 9 g por taza).

| Componente | Unidad (se escribe en) | Uso |
|---|---|---|
| Café en grano | kg (g) | 18 g por dosis; 1 taza o 2 tazas según la bebida |
| Leche | litros (ml) | Según la bebida y el tamaño |
| Azúcar | unidades (sobres) o kg (g) | Por taza |
| Vaso de café + tapa | unidades | Uno por tamaño si los vasos cambian |
| Bombilla | unidades | Bebidas frías |
| Matcha | kg (g) | Bebidas con matcha |
| Siropes | litros (ml) | Por bebida, o como opción «+ sirope» si se cobra aparte |

Ejemplo — **Capuchino** (hoy el único café activo en la base de la Mac, Bs 16, con «Grande +2»): 18 g de café que
rinden 2 tazas, 200 ml de leche, 1 sobre de azúcar, 1 vaso con tapa; la opción **Grande** suma leche (y café si
corresponde) y cambia el vaso. Los costos de estos insumos no están en el documento: el asistente los crea en
**cero, marcados «sin costo»**, para que los completes; hasta entonces el margen del café aparece como incompleto.
Las bebidas nuevas (matcha, frappés) se costean con el mismo editor cuando se creen en el Menú.

## 3. Cálculos

- **Costo de un producto** = Σ (cantidad de su receta × costo del componente) + Σ ajustes de sus opciones. Costo
  del componente = costo promedio (CPP) si hay compras registradas; si no, el último costo cargado.
- **Costo variable del mes** = costo por receta de todo lo vendido (local, retroactivo y PedidosYa) + costos de
  PedidosYa (comisión, publicidad, IVA, cargos, envíos − reintegros) + gastos del mes en categorías **variables**.
  Las compras de insumos no se suman (ya están representadas por las recetas): se muestran al lado como
  **costo real**, y la diferencia señala merma, desperdicio o porciones más grandes.
- **Costos fijos del mes** = gastos fijos activos **mensualizados** (bimestral ÷ 2, trimestral ÷ 3, anual ÷ 12) +
  gastos del mes en categorías **fijas** que no salen de la lista de gastos fijos (directos y egresos de caja).
- **Margen de contribución** % = (ventas − costos variables) ÷ ventas, total y por canal (local / PedidosYa).
- **Punto de equilibrio** = costos fijos ÷ margen %; **meta diaria** = punto de equilibrio ÷ días del mes;
  **bowls por día** = meta diaria ÷ precio promedio del bowl en el mes.
- **Progreso**: ventas a la fecha ÷ punto de equilibrio, ritmo diario, proyección a fin de mes y día estimado en
  que se alcanza.
- **Cobertura**: % de las ventas con receta completa (con todos sus componentes costeados). Lo que no tiene
  receta, o tiene componentes sin costo, se informa aparte; el margen se calcula sobre lo que sí está completo y el
  resumen avisa.

## 4. Cambios en el sistema

| Área | Cambio |
|---|---|
| `ingredients` | Columna `tracks_stock` (por defecto sí). Los componentes de costo van con «no»: `InventoryService` no los descuenta e Inventario no los muestra en alertas de stock |
| `sale_items` | Columna `weight_grams` (nula). `insertSaleItems` la guarda en ventas del POS y del Control Diario. Toca el flujo de venta: se verifica el flujo completo |
| `expense_categories` | Columna `cost_behavior` (`fixed` / `variable`). Por defecto: costo de ventas = variable; operativos, financieros e impuestos = fijo. Editable en Finanzas → Categorías |
| Servicios | `RecipeCostService` (costo por producto y del mes, cobertura, por canal) y `BreakEvenService` (fijos, margen, punto de equilibrio, progreso) |
| API (solo dueño) | `GET /finance/costs` (componentes, recetas y margen por producto), `PUT` de componentes, recetas y ajustes de opciones, `POST /finance/costs/base-recipes` (asistente con vista previa), `GET /finance/break-even?year&month` |
| Finanzas → **Costos y margen** (pestaña nueva) | Componentes con su costo editable; tabla de productos (bowls y cafés) con costo por tamaño y margen local / PedidosYa; edición de la receta de cada producto y de los ajustes de opciones, con cantidades en g / ml y «rinde N tazas»; asistente «Cargar recetas base» (bowls, Autoservicio y Capuchino) |
| Finanzas → Resumen | Tarjeta **Punto de equilibrio**: barra de progreso del mes, meta diaria en Bs y en bowls, margen local / PedidosYa, costos fijos; avisos si faltan recetas o gastos fijos |
| Finanzas → Categorías | Marca fijo / variable por categoría |

**Asistente de recetas base**: crea los componentes (si no existen con ese nombre) y las recetas de la §2 en una
vista previa editable; solo toca productos sin receta (o los que marques para reemplazar); se puede repetir sin
duplicar. Se aplicará primero en una copia de la base; en la base real lo ejecutas tú desde la pantalla.

## 5. Fases

| Fase | Contenido | Verificación |
|---|---|---|
| 1. Modelo y costo | Migraciones (`tracks_stock`, `weight_grams`, `cost_behavior`), `RecipeCostService`, inventario sin descontar componentes, gramos guardados | Tests: bowl chico 9,20, Mediano 11,70, Grande 14,20, Ohana 20,20, «Sin granola» −0,50, Arma tu Bowl con 3 frutas 9,20, Autoservicio 350 g 9,95, Capuchino con 18 g para 2 tazas (9 g por taza) y su Grande; un componente sin costo deja la receta incompleta; componentes sin movimiento de stock; `weight_grams` guardado; flujo de venta completo |
| 2. Costos y margen | Pestaña nueva y asistente | Tests de API y del asistente (idempotente, no pisa recetas); capturas 1440/390 en ambos temas; contraste 0 |
| 3. Punto de equilibrio | `BreakEvenService`, tarjeta del Resumen, fijo / variable en Categorías | Tests: anual 1.200 → 100 por mes; gasto generado no se cuenta dos veces; comisión PedidosYa como variable; fórmulas con cifras conocidas |
| 4. Cierre | Bitácora, CHANGELOG, grafo; entrega en la rama de la Mac | `php artisan test`, `npx vite build`, auditoría de contraste |

## 6. Pendiente del dueño

- Confirmar o corregir los costos iniciales de la §2 (sobre todo el costo por kg del helado del Autoservicio).
- Completar los costos y cantidades del café: precio del kg de café, del litro de leche, del sobre de azúcar, de
  los vasos y tapas por tamaño, bombillas, matcha y siropes; y cuántas tazas rinde la dosis de 18 g en cada bebida.
- Cargar los gastos fijos reales en Finanzas → Gastos fijos (sin ellos no hay punto de equilibrio).
- En la Mac: `php artisan migrate` con respaldo previo (migración `2026_10_09_000001`, además de las `2026_10_08_*`
  de Finanzas) y luego Finanzas → Costos y margen → «Cargar recetas base» en la base real.
- Revisar en Categorías qué gastos son fijos o variables (por defecto: costo de ventas variable, el resto fijo;
  «Empaques delivery» quedó variable por estar en costo de ventas).
