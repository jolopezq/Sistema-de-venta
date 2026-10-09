# Plan — Inventario confiable, mermas y punto de reorden

> Base: [AUDITORIA_INVENTARIO_MERMAS.md](AUDITORIA_INVENTARIO_MERMAS.md) (08/10/2026). Rama: `feat/inventario-reorden`,
> que sale de `feat/costos-punto-equilibrio`. **Estado: propuesta.** No se implementa nada hasta que el dueño
> apruebe y responda las decisiones de la §1.

**Objetivo.** Que el stock del sistema se parezca al del local, que cada merma tenga valor y motivo, y que el
sistema avise **qué comprar, cuánto y a quién** antes de quedarse sin insumo. Compras, recetas e inventario se
conectan con Finanzas sin cargar nada dos veces.

## 1. Decisiones del dueño

| # | Decisión | Opciones | Recomendación |
|---|---|---|---|
| D1 | Cómo descuentan stock las ventas | **A. Componentes con composición (sub-receta):** «Porción base açaí» = 180 g de pulpa, «Kit descartable chico» = 1 vaso + 1 tapa + 1 cuchara… Al vender se descuentan los insumos de la composición y el costo del componente sale del CPP de esos insumos. Un componente sin composición sigue como hoy (costo fijo, sin stock). **B. Insumos reales además de los componentes**, en la misma receta (como en la prueba de la auditoría): hay que cargar todo dos veces y los costos pueden no coincidir. **C. Sin descuento automático:** solo compras y conteo semanal; el consumo sale de stock inicial + compras − stock final | **A, empezando por los insumos clave:** pulpa, helado, granola, leche en polvo, leche condensada, vasos, tapas y cucharas por tamaño, y envase del Autoservicio. Mantiene la decisión de costear por porciones y no obliga a cargar todo de una vez. La **fruta fresca** se maneja con C (compra diaria y conteo), porque se compra a diario y su merma natural es alta |
| D2 | Dónde se registra una compra | Finanzas, Inventario, o **un solo formulario** que usan ambas pantallas | **Un solo formulario «Compra»** con líneas. Una línea de insumo sube el stock y actualiza el CPP; una línea sin insumo (gas, limpieza) es solo gasto. Siempre crea el gasto en Finanzas |
| D3 | Qué hace el POS cuando el stock teórico no alcanza | Bloquear la venta (hoy) o avisar sin bloquear | **Avisar sin bloquear.** El stock teórico puede estar mal (una compra sin cargar) y no se deben perder ventas. El dueño puede marcar a mano un producto como «agotado» |
| D4 | Quién registra mermas | Solo admin (hoy) o también el cajero | **También el cajero**, con motivo obligatorio (derrame, caída, vencido…), desde Caja. El dueño las ve en un resumen. Los ajustes y conteos siguen siendo solo del admin |
| D5 | Conteo físico | Semanal, mensual o ninguno | **Semanal de los insumos clave** (10 minutos al cierre del domingo) y **mensual completo** |
| D6 | De dónde sale el consumo para el punto de reorden | Movimientos reales de venta o consumo teórico por recetas | **Movimientos reales menos los reversos** (requiere D1). Mientras no haya 4 semanas de datos, el consumo que estime el dueño |
| D7 | Cómo llegan las alertas | Dentro del sistema, WhatsApp, correo | **Dentro del sistema:** marca en el menú, tarjeta en Finanzas y aviso al abrir caja para el admin. Además, la **lista de compras como texto** para copiar o enviar por WhatsApp (enlace `wa.me`, sin servicios externos) |

## 2. Punto de reorden: cómo se calcula

### 2.1 Datos por insumo (nuevos campos)

| Dato | Ejemplo | Para qué |
|---|---|---|
| Unidad de consumo | kg | La de las recetas (hoy `unit`) |
| Unidad y factor de compra | Bolsa = 1 kg; caja = 50 vasos | Convertir lo comprado en stock (H15) |
| Proveedor y días de entrega (L) | Distribuidora X, 2 días | Cuánto hay que aguantar hasta que llegue |
| Días de cobertura objetivo (T) | 7 días | Cuánto comprar cada vez |
| Lote mínimo | 1 bolsa; 1 caja | Redondear el pedido |
| Vida útil | 3 días (fruta), 180 (pulpa congelada) | No comprar más de lo que dura y avisar vencimientos |
| Política | Reorden / compra diaria / sin control | La fruta va a «compra diaria»; los descartables y la pulpa, a «reorden» |
| Punto de reorden manual (opcional) | 12 kg | Lo fija el dueño hasta que haya datos |

### 2.2 Fórmulas

- **Consumo diario (d):** promedio de los últimos 28 días con ventas, usando las ventas menos los reversos.
  Con 8 semanas o más de datos, por día de la semana (los fines de semana venden distinto).
- **Stock de seguridad (SS):** colchón para los días buenos o un pedido que se atrasa.
  - Con datos: **SS = z × desvío del consumo diario × √L**, con z = 1,65 (cubre el 95 % de los días).
  - Con pocos datos: **SS = días de seguridad × d**, por ejemplo 2 días.
- **Punto de reorden (PR) = d × L + SS.** Cuando el stock llega aquí, hay que pedir.
- **Cantidad sugerida = d × T + SS − stock actual − pedidos en camino.** Se redondea hacia arriba al lote del
  proveedor. En perecederos se limita a lo que se consume dentro de la vida útil.

### 2.3 Ejemplo con los datos de Ohana

Datos de la auditoría §5 (10 días con ventas). Días de entrega, lotes y stock actual son **supuestos** para
mostrar el cálculo.

| Insumo | Consumo diario (d) | Desvío | L | SS | **Punto de reorden** | Cantidad sugerida |
|---|---|---|---|---|---|---|
| Pulpa de açaí (180 g por chico, más según tamaño) | 2,96 kg | 2,59 | 2 días | 1,65 × 2,59 × √2 = 6,0 kg | 2,96 × 2 + 6,0 = **12 kg** | T = 7 días, stock 11 kg: 26,8 − 11 = 15,8 → **16 bolsas de 1 kg** |
| Granola (25 g por bowl) | 0,30 kg | 0,27 | 3 días | 0,8 kg | 0,9 + 0,8 = **1,7 kg** | T = 14 días, stock 1,5 kg: 5,0 − 1,5 = 3,5 → **4 bolsas de 1 kg** |
| Vaso chico (1 por bowl chico) | 6,5 u | 6,6 | 5 días | 24 u | 32,5 + 24 = **57 vasos** | T = 30 días, stock 50: 219 − 50 = 169 → **4 cajas de 50** |

Con la fórmula simple, el SS de la pulpa sería (máximo − promedio) × L = (8,78 − 2,96) × 2 = 11,6 kg y el punto
de reorden 17,6 kg. Ese día de 8,8 kg (36 bowls el 23/08) pesa mucho con tan pocos datos. Por eso, hasta juntar
4 semanas, conviene usar los **días de seguridad** que fije el dueño.

### 2.4 Arranque sin historial

Mientras no haya 28 días de ventas con descuento real, el consumo diario se calcula con lo que estime el dueño
(por ejemplo, «12 bowls por día, 25 los sábados») multiplicado por la receta. También puede fijar un punto de
reorden manual. El sistema indica qué insumos usan datos reales y cuáles una estimación.

### 2.5 Estados y alertas

| Estado | Condición | Dónde se ve |
|---|---|---|
| OK | Stock > punto de reorden | — |
| **Reordenar** | Stock ≤ punto de reorden | Lista de compras, marca en el menú «Inventario», tarjeta en Finanzas → Resumen |
| **Crítico** | Stock ≤ d × L (se acaba antes de que llegue el pedido) | Lo anterior + aviso al abrir caja (admin) |
| **Agotado / revisar** | Stock ≤ 0 | Lo anterior. Si el stock es negativo, probablemente falta cargar una compra o un conteo |
| **Vence pronto** | Vence dentro de 2 días o del 20 % de su vida útil | Inventario y aviso al abrir caja |
| **Sin datos** | Sin consumo ni punto de reorden manual | Inventario, para que el dueño lo complete |

Las alertas se calculan al momento: no se guardan ni se envían varias veces. El aviso al abrir caja aparece una
vez por turno. La **lista de compras** agrupa por proveedor y deja ajustar cantidades. Se puede copiar, imprimir,
enviar por WhatsApp o convertir en una Compra (F1) cuando llega la mercadería.

## 3. Fases

Cada fase se entrega sola, en la rama, con pruebas, CHANGELOG y BITÁCORA. Verificación común a todas:

- `php artisan test` en verde;
- `npx vite build`;
- contraste 0 a 1440 y 390 px, en claro y oscuro;
- flujo de venta en una copia (abrir caja → vender local, PedidosYa y por peso → cocina → anular → cerrar
  caja);
- el recorrido de stock de la auditoría (§6) con todos los pasos en ✅.

### F0 · Correcciones de base (urgente) — 1 a 1,5 días

- **CPP correcto (H5, H6):** el alta y la edición del costo inicial fijan el CPP. Con stock ≤ 0, una compra deja
  el CPP igual a su costo.
  - Migración de datos: insumos con stock > 0 y CPP 0 toman `unit_cost` como CPP. **Cambia datos: pedir
    confirmación.** En la Mac son pulpa 12, granola 5 y banana 0,5.
- **Productos por peso por gramos (H7):** el descuento usa `weight_grams` ÷ 1000 en el servidor y en el POS sin
  conexión, igual que el costeo.
- **El stock solo cambia por movimientos (H10):**
  - el alta crea un movimiento «stock inicial»;
  - la edición no toca el stock ni el CPP;
  - el «Ajuste» pasa a ser «Conteo»: se escribe lo contado y el sistema calcula la diferencia (completo en F3).
- **Validaciones (H11, H15):** merma siempre negativa en el servidor; lista cerrada de unidades (kg, litros,
  unidades); «sacos» pasa a ser unidad de compra en F1.
- **Descuento atómico (H9):** `UPDATE … SET current_stock = current_stock − ?` y SQLite en modo `IMMEDIATE` con
  `busy_timeout` y WAL. Son las Fases 5a/5b de la bitácora; revisar `respaldo-diario.bat` por los archivos
  `-wal`/`-shm`.
- **POS (H8, D3):** avisa en lugar de desactivar. El dueño puede marcar a mano un producto como agotado.
- **Pruebas nuevas:** CPP (inicial, negativo), peso, merma positiva rechazada, edición sin tocar stock, venta con
  stock insuficiente.
- **Riesgo:** el cambio a WAL afecta los respaldos; probarlo con `respaldo-diario.bat` antes de producción.

### F1 · Compras ligadas a insumos y proveedores — 2 a 3 días

- **Modelo:**
  - tabla `suppliers` (nombre, teléfono, días de entrega, días de pedido, notas);
  - `finance_expense_items`: gasto, insumo opcional, cantidad, unidad de compra, factor, costo unitario, total;
  - en `ingredients`: `purchase_unit`, `purchase_factor`, `supplier_id`, `lot_size`.
  - Las compras ya cargadas siguen como gastos sin líneas (hoy hay 0 en la Mac).
- **Servicio `PurchaseService`:** en una transacción, crea el gasto (categoría de costo de ventas), los
  movimientos `restock` ligados a la línea y el CPP. Editar o eliminar la compra revierte los movimientos, como
  hoy al anular una venta.
- **Pantallas:**
  - «Compra» con proveedor, fecha, comprobante y líneas. Se escribe el total de la línea y el sistema calcula el
    costo unitario.
  - Finanzas → «Registrar compra» e Inventario → «Ingreso» abren este mismo formulario.
  - Proveedores, dentro de Inventario.
- **Pruebas:**
  - una compra = gasto + stock + CPP;
  - editar o eliminar revierte;
  - un gasto sin insumo no mueve el stock;
  - el Estado de Resultados no cuenta dos veces.
- **Riesgo:** compras viejas sin líneas. Quedan como gasto, y el stock inicial se pone en orden con el primer
  conteo (F3).

### F2 · Recetas que mueven stock (componentes con composición) — 2 a 3 días

- **Modelo:** tabla `ingredient_compositions` (componente de costeo → insumo real, cantidad).
- **Ventas:** `InventoryService` expande el componente en sus insumos al vender, sin pasar de un nivel.
- **Costos:** `RecipeCostService` costea un componente con composición por el CPP de sus insumos. Sin
  composición, conserva su costo manual.
- **Pantalla:** en Costos y margen, «Composición» de cada componente, con g/ml, y una vista previa del costo que
  resulta.
- **POS:** el catálogo envía la receta ya expandida y la disponibilidad cuenta los tamaños (H20, H21). El POS
  sin conexión usa la misma receta.
- **Pruebas:**
  - un bowl Mediano descuenta 252 g de pulpa;
  - el costo del componente cambia cuando cambia el CPP;
  - un componente sin composición no mueve stock;
  - anular revierte la composición.
- **Riesgo:** cambiar una composición no recalcula el stock de las ventas pasadas. Es intencional; queda
  explicado en la pantalla.

### F3 · Mermas y conteo físico — 2 a 3 días

- **Modelo:**
  - `inventory_movements.unit_cost` (CPP del momento) y `total_cost`;
  - más motivos de merma: vencido, dañado, derrame, error de preparación, porción de prueba o cortesía,
    consumo del personal;
  - `inventory_counts` + `inventory_count_lines` (esperado, contado, diferencia, costo).
- **Pantallas:**
  - «Registrar merma» en Caja para el cajero (D4) y en Inventario.
  - «Conteo»: lista de insumos clave; se escribe lo contado y se ve la diferencia en unidades y Bs. Al
    confirmar se generan los ajustes ligados al conteo.
  - Historial de movimientos por insumo, paginado (H18).
- **Reporte de merma del mes:**
  - registrada (por motivo y por insumo, en Bs);
  - oculta = consumo real (inicial + compras − final contado) − consumo teórico de las ventas.
- **Finanzas:** la tarjeta del punto de equilibrio desglosa la diferencia entre el costo real y el de recetas en
  «merma registrada» y «merma oculta».
- **Pruebas:** costo guardado en la merma, conteo que genera ajustes, cajero puede mermar pero no ajustar.

### F4 · Punto de reorden y alertas — 2 a 3 días

- **Campos:** los de la §2.1 en `ingredients`, más el consumo estimado del dueño.
- **`ReorderService`:**
  - consumo diario con las ventas menos los reversos (H19), por día de la semana cuando hay datos;
  - stock de seguridad, punto de reorden, cantidad sugerida y estado;
  - `GET /inventory/reorder`.
- **Pantallas:**
  - Inventario → «Compras sugeridas», agrupadas por proveedor, para copiar, imprimir, enviar por WhatsApp o
    convertir en Compra;
  - marca con el número de insumos por reponer en el menú «Inventario» (admin);
  - tarjeta en Finanzas → Resumen;
  - aviso al abrir caja (admin), una vez por turno;
  - vencimientos: campo en la pantalla del insumo y aviso.
- **Pruebas:** fórmulas con cifras conocidas (las del ejemplo §2.3), arranque sin historial, perecederos
  limitados por vida útil, estados.

### F5 · Indicadores — 1 a 2 días

- Valor del inventario (stock × CPP), días de cobertura, quiebres, % de merma, costo teórico frente al real y
  exactitud del conteo (§5).
- En Finanzas → Resumen, más un reporte mensual imprimible.

**Total estimado: 10 a 15 días de desarrollo**, en este orden: F0 → F1 → F2 → F3 → F4 → F5.

- F0 conviene hacerlo antes de usar recetas con insumos reales.
- F1 y F2 son la base de todo lo demás.
- El punto de reorden (F4) recién es útil con 2 a 4 semanas de ventas que descuenten stock real.

## 4. Otras mejoras de la versión actual

| Prioridad | Mejora | Por qué | Esfuerzo |
|---|---|---|---|
| Urgente | Una venta inválida no debe frenar la cola del POS: validar por venta y guardar las que fallen en una cuarentena con pantalla de admin (Fase 5d) | Hoy una venta con error bloquea todas las siguientes (bitácora; H2 era un caso) | 1 día |
| Urgente | Revertir exactamente los puntos de fidelización al anular o editar (Fase 5c) | `incrementPoints` ignora valores negativos: los puntos nunca se descuentan | 0,5 día |
| Urgente | Verificar `APP_DEBUG=false` en producción y no devolver mensajes internos | En la copia, un error devuelve la traza completa | 0,5 día |
| Urgente | Fusionar las ramas pendientes y migrar la Mac con respaldo (`2026_10_08_*`, `2026_10_09_000001`) | La base de la Mac está sin las migraciones de Finanzas | 0,5 día |
| Importante | Índice único del número de orden solo para ventas nuevas (Fase 5e) | Hay números repetidos en datos históricos | 0,5 día |
| Importante | Matriz de permisos (Usuarios → Roles): conectarla al backend o quitarla | Hoy no se aplica y confunde | 1 día |
| Importante | Pantalla de Inventario con el estilo de Finanzas (filtros por estado, historial, ventanas propias) | `InventoryList.vue` es la pantalla más vieja y la que más se va a usar | 1-2 días (con F3/F4) |
| Importante | Completar el Tarifario PedidosYa (Mediano +8, Arma tu Bowl 32) | Pedidos con otro precio que el del sistema | Dueño |
| Deseable | Dividir `AdminSaleEditor.vue` (≈2.600 líneas) y `RetroactiveSales.vue` | Difíciles de mantener | 1-2 días |
| Deseable | Quitar código muerto (`ProcessOfflineSaleJob`, `HelloWorld.vue`), actualizar el README (dice MySQL) y resolver el conflicto de dependencias de npm | Orden y menos confusión | 0,5 día |

## 5. Indicadores para medir el resultado

| Indicador | Cómo se calcula | Meta inicial (ajustable) |
|---|---|---|
| % de merma por insumo | Merma registrada en Bs ÷ consumo en Bs del mes | Pulpa < 3 %, fruta < 10 % |
| Merma oculta | (Inicial + compras − final contado) − consumo teórico | Que baje mes a mes |
| Diferencia de costo | Costo real de compras − costo por recetas, en % de ventas | ≤ 5 puntos |
| Días de cobertura | Stock ÷ consumo diario | Pulpa 5-10 días; descartables ≥ 15 |
| Quiebres | Días en que un insumo clave llegó a 0 o un producto se marcó agotado | 0 por mes |
| Exactitud del conteo | Insumos con diferencia ≤ 2 % ÷ insumos contados | ≥ 90 % |
| Valor del inventario | Σ stock × CPP | Seguimiento |

## 6. Preguntas para el dueño (bloquean el plan)

1. ¿Todas las ventas pasan por el sistema? En la base de la Mac hay ventas en solo 10 días entre el 17/08 y el
   04/10. ¿La laptop de producción tiene más?
2. ¿Aceptas las decisiones D1 a D7 como están recomendadas? ¿Cuál cambiarías?
3. Insumos clave, proveedor, días de entrega y unidad de compra de cada uno (por ejemplo, pulpa en bolsas de
   cuántos kg y quién la trae).
4. Gramos reales por tamaño: ¿180 g de pulpa en el chico? ¿Cuánto en Mediano, Grande y Ohana? ¿Qué vaso, tapa y
   cuchara usa cada tamaño?
5. ¿Qué día y a qué hora puede hacerse el conteo semanal y quién lo haría?
6. ¿Confirmas la corrección de datos de F0 (CPP inicial de los 3 insumos = su costo cargado)?
