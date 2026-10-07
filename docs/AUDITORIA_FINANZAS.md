# Auditoría y plan de corrección — módulo de Finanzas

> Rama auditada: `feat/modulo-finanzas` (commits `b81d960`, `4cdc459`, `4126824`, `d710a05`), 07/10/2026.
> Referencias: [PLAN_MODULO_FINANZAS.md](PLAN_MODULO_FINANZAS.md) (v3) y
> [PLAN_PRECIOS_TAMANOS_PEDIDOSYA.md](PLAN_PRECIOS_TAMANOS_PEDIDOSYA.md).

## 1. Resumen

| Aspecto | Resultado |
|---|---|
| Tests | 100 en verde (también con `APP_TIMEZONE=America/La_Paz`) |
| Build del frontend | Correcto |
| Importación de la semana real 14–20/09 | **Correcta**: 13 pedidos, 11 entregados, 2 cancelados, ventas brutas 748, comisión 187, ingreso estimado 533,06, total liquidado 523,56, diferencia 0 |
| Precio PedidosYa por tamaños (`b81d960`) | Correcto y consistente entre frontend y backend, con compatibilidad offline |
| Acceso | Solo `super_admin`; cajero y admin reciben 403 |
| Interfaz | **Rota**: doble menú lateral y doble encabezado, contenido cortado a la derecha |
| Cálculos | **2 errores**: meses desplazados 4 horas y gastos fijos sumados en todos los meses |
| Riesgo en producción | **Un commit borra 10 imágenes de productos** |
| Alcance del plan v3 | La importación **no crea ni vincula ventas** (núcleo de §6) |
| Contraste | 17 textos bajo WCAG AA (10 en tema claro, 7 en oscuro) |

**Veredicto: no fusionar** hasta resolver la fase 0 de este plan.

## 2. Hallazgos

### Críticos

**C1. El commit `d710a05` borra 10 imágenes de productos del repositorio.**
El commit de graphify incluyó la eliminación de `backend/storage/app/public/products/*.jpg` (por ejemplo
`3lXcHLttZC2B1rBNRzGVBLVNGJ1yYgIXDNhEfESy.jpg`, que usa "Arma tu Bowl" en la base de la Mac). Al fusionar en
`main`, el vigilante hace `git pull` en la laptop del local y **borra esos archivos en producción**: los productos
que los usan se quedan sin imagen.
*Corrección:* restaurarlos en la rama (`git checkout 0fb2313 -- <archivos>`) en un commit propio. Regla nueva en
`AGENTS.md`: nunca incluir cambios de `backend/storage/app/public/` en un commit; revisar `git status` antes de
cada `git add -A`.

**C2. Doble menú lateral y doble encabezado.**
`Finance.vue` envuelve todo en `<AppShell>`, pero `App.vue` ya lo hace para todas las rutas. Resultado (captura
del usuario y pruebas a 1440 px y 390 px): dos menús laterales, dos barras superiores, y el contenido se corta a la
derecha (selector de mes, cuarta tarjeta, pestañas). En celular se ven dos encabezados.
*Corrección:* quitar `<AppShell>` y su import de `Finance.vue` (línea 4 y 582/1640).

### Altos

**A1. Los meses se calculan desplazados 4 horas.**
`FinanceReportService::getMonthlyPnL` convierte el inicio y el fin del mes a UTC, pero las ventas y los egresos se
guardan en hora de La Paz (`APP_TIMEZONE=America/La_Paz`). Prueba: ventas el 01/09 01:00 (7), el 30/09 22:30
(100) y el 01/10 02:00 (50) → el reporte de septiembre da **150**; lo correcto es **107**. Los tests no lo
detectan porque corren en UTC.
*Corrección:* filtrar con `'Y-m-d 00:00:00'` y `'Y-m-d 23:59:59'` del mes en hora local, sin convertir. Fijar
`APP_TIMEZONE=America/La_Paz` en `phpunit.xml` y agregar un test de bordes de mes.

**A2. Los gastos fijos se suman en todos los meses.**
Todo gasto fijo activo entra en cualquier mes consultado, incluso antes de existir. Prueba: un alquiler de 1.000
aparece en enero 2020, septiembre 2026 y diciembre 2030. Además, cambiar el monto reescribe la historia, y si el
pago real también se registra (en caja o en Compras y Gastos) se cuenta dos veces.
*Corrección (plan §8):* "Generar gastos fijos del mes" crea `finance_expenses` con `recurring_expense_id` y
`period` (único por plantilla y mes). El dueño los confirma o ajusta, y el Estado de Resultados lee solo gastos
reales. Para bimestrales y anuales se genera el gasto en el mes que corresponde, sin dividir el monto.

**A3. La pestaña PedidosYa siempre aparece vacía.**
`GET /finance/pedidosya-settlements` devuelve un objeto paginado (`{data: [...]}`) y la vista espera un arreglo
(`Array.isArray(data) ? data : []`). Además, el backend ignora el filtro `month`. Prueba: con la semana importada,
la pestaña dice "No hay semanas de PedidosYa importadas" y el contador muestra (0).
*Corrección:* leer `data.data` y filtrar por semanas cuya fecha de fin cae en el mes.

**A4. La importación no crea ni vincula ventas (núcleo del plan v3, §6.2–6.4).**
No hay vista previa, no se resuelven equivalencias de nombres, no se crean ventas con `adminCreateSale` y
`pedidosya_orders.sale_id` queda siempre vacío. Los pedidos siguen requiriendo ingreso manual y el inventario de
PedidosYa no se descuenta. El informe semanal (§6.6) está incompleto (falta el detalle de artículos, las
diferencias de precio y los pedidos sin registrar).

### Medios

| Id | Hallazgo | Corrección |
|---|---|---|
| M1 | Se pueden cargar semanas que se solapan (14–20 y 15–21 se suman las dos). Las fechas se escriben a mano y no se comparan con las fechas del CSV | Tomar el rango del CSV, rechazar solapes y avisar si hay pedidos fuera del rango |
| M2 | Una semana que cruza dos meses se asigna entera al mes de su fecha de fin | Prorratear por la fecha de cada pedido (plan §7.1) |
| M3 | La conciliación solo valida la fórmula del total liquidado | Validar además: ventas brutas − descuento PedidosYa = ventas netas, y comisión del CSV = servicios |
| M4 | Sin compras registradas, el resumen muestra "Margen 100 %" sin aviso. Los egresos de caja sin clasificar (210) se suman sin pedir clasificarlos | Avisos en el resumen: "No hay compras este mes", "1 egreso sin clasificar → Clasificar", "Semanas PedidosYa sin cargar" |
| M5 | 17 textos bajo WCAG AA: insignias de margen, píldoras "Activa", insignias de sección (OPEX 2,21 en oscuro) y "Módulo de Finanzas". Colores fijos (`#7c3aed`, `#10b981`…) en vez de las variables de `style.css` | Usar los tokens del sistema y repetir la auditoría hasta 0 |
| M6 | La fecha por defecto del formulario de gasto usa `toISOString()` (UTC): después de las 20:00 propone el día siguiente | Fecha local |
| M7 | 8 `alert()` / `confirm()` nativos | Usar los modales del sistema |
| M8 | `Finance.vue` tiene 2.554 líneas en un solo archivo | Dividir en componentes (§4.3) |

### Bajos

| Id | Hallazgo |
|---|---|
| B1 | `OptionResource` carga el grupo de cada opción por separado (N+1 en `/api/catalog`) cuando la relación no viene cargada |
| B2 | Bloque duplicado en `FinanceReportService` (la consulta de ventas del local está dos veces) |
| B3 | La lista de gastos pide 100 registros y no tiene paginación ni totales del servidor |
| B4 | `finance_expenses` acepta pago `cash`: riesgo de duplicar un egreso de caja. Falta un aviso en el formulario |
| B5 | Se commitearon 63 archivos de `graphify-out/cache` con rutas absolutas de la Mac (decisión pendiente en la bitácora) |
| B6 | Faltan tests de: bordes de mes, gastos fijos por período, solape de semanas, reimportación, listas paginadas |
| B7 | Cambiar la sección de una categoría reclasifica meses pasados (queda auditado, pero la interfaz no lo advierte) |
| B8 | Falta un índice `(status, created_at)` en `sales` para los reportes |

### Lo que está bien

Parser del CSV por nombre de columna, con BOM y coma decimal; idempotencia por número de pedido; montos de
PedidosYa exactos contra el estado de cuenta; migraciones solo aditivas; modelos auditados; acceso restringido;
regla de tamaños con compatibilidad offline; `CHANGELOG.md` y bitácora actualizados.

## 3. Plan de corrección

### Fase 0 — Bloqueantes (antes de cualquier fusión)

1. Restaurar las 10 imágenes (C1) y agregar la regla a `AGENTS.md`.
2. Quitar el `<AppShell>` interno (C2).
3. Corregir los bordes de mes (A1) y fijar la zona horaria en los tests.
4. Corregir la lista de liquidaciones (A3).

*Aceptación:* un solo menú lateral, nada cortado a 1440 px ni a 390 px; septiembre = 107 en el test de bordes;
la semana importada aparece en su pestaña con su contador.

### Fase 1 — Cálculos correctos

1. Gastos fijos por período: generar, confirmar e idempotencia (A2).
2. Avisos de datos faltantes en el resumen (M4) y clasificación de egresos de caja desde Finanzas.
3. Semanas PedidosYa: rango tomado del CSV, sin solapes, prorrateo entre meses y conciliación completa (M1–M3).
4. Tests: bordes de mes, gastos fijos, solape, reimportación, conciliación con diferencias.

### Fase 2 — Interfaz (rediseño)

1. **El Estado de Resultados es la pantalla principal**, como en el material de Swiss Burger: una tabla con
   línea, Bs y % de ventas (Ventas → Costo de ventas → Utilidad bruta → Gastos operativos → Utilidad operativa
   → Financieros → Impuestos → Utilidad neta), con el desglose por categoría desplegable. Las tarjetas
   KPI quedan como resumen arriba (4 como máximo, en una fila que se adapta al ancho).
2. Selector de mes compacto en el encabezado; pestañas con scroll horizontal en pantallas angostas;
   contadores que se cargan con la página.
3. Colores y tipografía del sistema (`--acai-*`, `--passion-*`, `--surface`, `--ink-*`), modo oscuro con las
   reglas `html.dark`, sin colores fijos. Contraste: 0 textos bajo AA en las 5 pestañas, tema claro y oscuro.
4. Modales del sistema en lugar de `alert` / `confirm`; fecha local en los formularios.
5. División de `Finance.vue`: `FinanceSummary.vue`, `FinanceStatementTable.vue`, `FinanceExpenses.vue`,
   `FinanceRecurring.vue`, `FinanceCategories.vue`, `PedidosYaSettlements.vue`, `PedidosYaImportModal.vue` y un
   store `finance.js` para el período y las cargas.

*Aceptación:* capturas a 1440 px y 390 px en ambos temas revisadas; auditoría de contraste en 0.

### Fase 3 — Importación completa (plan v3 §6)

Vista previa, equivalencias de nombres, creación y vínculo de ventas con `adminCreateSale`, cancelados con
reintegro e informe semanal completo. Tests de la sección 12 del plan v3 (3b–3e).

### Fase 4 — Optimización y limpieza

N+1 de `OptionResource` (B1); código duplicado (B2); paginación y totales del servidor (B3); aviso de pago en
efectivo (B4); índice en `sales` (B8); decisión sobre `graphify-out/cache` (B5).

## 4. Forma de trabajo

- Corregir sobre `feat/modulo-finanzas`, un commit por fase, con `CHANGELOG.md` y bitácora.
- Verificación por fase: `php artisan test` (con La Paz), `npx vite build`, capturas a 1440 px y 390 px en ambos
  temas y auditoría de contraste de `/finanzas`.
- Volver a pedir auditoría al terminar las fases 0 a 2.

## 5. Estado de la corrección (07–08/10/2026)

| Fase | Commit | Resultado |
|---|---|---|
| 0. Bloqueantes | `9a5f871` | Imágenes restauradas y regla en `AGENTS.md`; un solo marco; meses en hora de La Paz (test de bordes: septiembre = 107); lista de liquidaciones |
| 1. Cálculos | `f2319e1` | Gastos fijos generados por mes; avisos; clasificación de egresos; semanas con rango del CSV, sin solapes, prorrateo y conciliación completa |
| 2. Interfaz | `36ec4ad` | Estado de Resultados como vista principal; `Finance.vue` dividido en `components/Finance/` + `stores/finance.js`; contraste 0 (claro y oscuro, 1440 y 390 px) |
| 3. Importación | `a7def47` | La importación crea o vincula las ventas, equivalencias de nombres e informe semanal |
| 4. Optimización | ver registro | Índices de reportes, totales del servidor, grafo actualizado |

| Id | Estado |
|---|---|
| C1, C2, A1, A3 | Corregidos (fase 0) |
| A2, M1–M4 | Corregidos (fase 1) |
| M5–M8 | Corregidos (fase 2) |
| A4 | Corregido (fase 3) |
| B1 | Verificado sin cambios: con 8 productos × 3 grupos × 6 opciones, `/api/catalog` hace 9 consultas (el grupo se asigna desde `OptionGroupResource`) |
| B2, B6 | Corregidos (fases 0 y 1–3: 39 tests nuevos) |
| B3, B4, B7 | Corregidos (fase 2: totales del servidor, aviso de pago en efectivo, aviso al cambiar la sección) |
| B8 | Corregido (fase 4: índices `sales (status, created_at)`, `sales (source, created_at)`, `cash_movements (type, created_at)`) |
| B5 | **Pendiente de decisión del dueño** (los commits nuevos no tocan `graphify-out/cache`) |

Hallazgos nuevos durante la corrección (corregidos):

- Editar o eliminar una compra respondía 200 sin cambiar nada (el parámetro de la ruta no coincidía con el del
  controlador); lo mismo al ver o eliminar una semana PedidosYa.
- Los gastos del último día del mes quedaban fuera de listas y del Estado de Resultados (`date` se guarda con
  `00:00:00`), y las fechas se devolvían como `…T04:00:00Z`.
- El formulario de la semana sumaba los envíos en vez de restarlos y el detalle de pedidos no cargaba.
- Un CSV con columnas faltantes respondía error 500.

