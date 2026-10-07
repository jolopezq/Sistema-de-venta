# Plan: módulo de Finanzas (Estado de Resultados + PedidosYa)

> Versión 3 (07/10/2026): incorpora cómo opera Ohana (compras semanales, régimen simplificado, gastos fijos),
> análisis de archivos de PedidosYa, protección de datos personales en fixtures de test (CSV anonimizado),
> integración sobre la rama `feat/precios-pedidosya` (ajustes de precio por línea) y tarifas de PedidosYa actualizadas
> (Mediano a +8, Arma tu Bowl a 32). Antes de empezar, lee `AGENTS.md` y `docs/BITACORA.md`.

## 1. Objetivo

Ver cada mes, dentro del POS, si Ohana **gana o pierde dinero** y por qué, y saber **cuánto deja realmente
PedidosYa** después de comisiones, publicidad, impuestos y reintegros. El sistema calcula lo que ya conoce
(ventas del local); el dueño carga lo que el sistema no puede saber (compras, gastos fijos, archivos semanales
de PedidosYa).

## 2. Cómo opera Ohana (datos del dueño)

| Tema | Situación | Consecuencia en el diseño |
|---|---|---|
| Compras | Semanales y variadas, más alguna mensual: helado, ingredientes, vasos, cucharas | Registro rápido de compras con categoría; costo de ventas = compras del mes (método A) |
| Impuestos | Régimen simplificado: monto fijo; no se emiten facturas | Impuesto como gasto fijo recurrente; sin cálculo de IVA sobre las ventas del local |
| Gastos fijos | Salarios, internet, teléfono, publicidad, servicios básicos | Plantillas de gastos fijos que se confirman cada mes |
| PedidosYa | Liquidación semanal con factura, estado de cuenta y detalle de pedidos | Importación manual semanal de esos archivos (§6) |

## 3. Análisis del material Swiss Burger (resumen)

El ejemplo cuadra (ventas 350.000 → utilidad neta 85.600, rentabilidad 24,46 %). Precisiones para Ohana:

1. **Gastos financieros = solo intereses**, no la cuota completa.
2. **Utilidad neta ≠ dinero en la caja** (depreciación, compras para varias semanas, pagos de PedidosYa que
   llegan días después).
3. **Costo de ventas = lo consumido.** Con compras semanales, el método "compras del mes" es una buena
   aproximación; las compras grandes de fin de mes distorsionan un poco.
4. **Las comisiones de plataformas son gasto.** En Ohana, PedidosYa cobra mucho más que la comisión (§6.3).
5. **Impuestos según el régimen**: aquí, monto fijo.

## 4. Archivos semanales de PedidosYa — análisis (semana 14/09 al 20/09/2026)

### 4.1 Qué contiene cada archivo

| Archivo | Formato | Contenido | Uso en el sistema |
|---|---|---|---|
| `semana.csv` (detalle de pedidos, se descarga del portal) | CSV UTF-8 con BOM, separado por comas, **decimales con coma** (`"60,00"`), fechas `AAAA-MM-DD HH:MM` (hora de Bolivia), 46 columnas | Un pedido por fila: estado, forma de pago, tipo de entrega, horarios, subtotal, comisión, impuesto, descuentos, ingreso estimado y artículos | **Se importa** (pedido por pedido) |
| `invoicesArchive_<id>_<fecha>.zip` → `account_statement_<id>_1.pdf` | PDF | **Estado de cuenta semanal**: ventas netas, servicios, publicidad, reintegros, impuestos, total liquidado y fecha de pago | Se cargan sus totales en un formulario (8 montos) y se adjunta el ZIP |
| → `invoice-<id>.xls` (en realidad es `.xlsx`) | Excel con hojas **"Lista de Pedidos"** y **"Reintegros"** | Monto bruto, descuentos, monto comisionable, % y monto de servicio por pedido; reintegros por pedidos cancelados | Referencia; lectura automática en fase posterior |
| → `invoice_<id>.pdf` | PDF | Factura de PedidosYa por sus servicios (subtotal, descuento comercial, total) | Archivo adjunto |

Los archivos contienen datos personales (nombre y NIT del titular): se guardan solo en el servidor local y solo
los ve el dueño.

### 4.2 La semana en números

| Concepto | Bs | % de ventas brutas |
|---|---:|---:|
| Pedidos entregados (11) — ventas brutas a precio PedidosYa | 748,00 | 100 % |
| Pago en la app / en efectivo al repartidor | 342,00 / 406,00 | |
| (−) Descuento PedidosYa a usuarios no contado como venta neta (pedido 2290720503) | −38,00 | 5,1 % |
| **Ventas netas** (estado de cuenta) | **710,00** | |
| (−) Servicio PedidosYa (comisión 25 %, también en el pedido "Retirado") | −187,00 | 25,0 % |
| (−) Publicidad dentro de la app ("Campaña gold vip") | −112,65 | 15,1 % |
| (−) IVA en comisiones | −39,83 | 5,3 % |
| (+) Reintegro del descuento PedidosYa | +33,04 | |
| (+) Reintegro por 2 pedidos cancelados por usuarios (50 % de 240) | +120,00 | |
| **Total liquidado** (pago al banco el 24/09) | **523,56** | **70,0 %** |

Comprobación: 710 − 187 − 112,65 + 153,04 − 39,83 = 523,56 ✔.

### 4.3 Hallazgos

1. **El CSV solo no alcanza para la ganancia.** Su "Ingreso estimado" suma 533,06, pero no incluye la
   publicidad (112,65), los reintegros (153,04) ni el IVA completo. La diferencia con lo liquidado es −9,50.
   **Fuente de verdad del dinero: el estado de cuenta.** El CSV aporta el detalle por pedido.
2. **La columna "Impuesto" del CSV** (27,94) es 13/87 de la comisión (IVA incluido en la comisión). El estado
   de cuenta cobra 39,83 porque también grava la publicidad. Como Ohana no emite facturas, ese IVA es un costo
   más de PedidosYa (confirmar con el contador).
3. **La publicidad pesa**: la campaña costó el 15 % de las ventas de la semana.
4. **Dos pedidos cancelados después del retiro** (30 y 210 Bs): el local preparó el pedido y recibió solo el
   50 % como reintegro.
5. **El POS casi no registra PedidosYa**: en la base de la Mac hay 1 venta PedidosYa esa semana (Bs 38) frente
   a 11 pedidos entregados. Para Finanzas, las ventas PedidosYa saldrán de la importación, no del POS.
6. **Precios reales de PedidosYa** deducidos de los pedidos (verificar en el portal):

| Artículo PedidosYa | Precio deducido | En el sistema hoy |
|---|---:|---|
| Classic Bowl, Patriota Bowl | 30 | 30 ✔ |
| Arma Tu Ohana Acai (= "Arma tu Bowl") | 32 | vacío → cobra 28 ✘ |
| Tamaño Mediano | +8 | +7 ✘ |
| Tamaño Ohana + Chispas de corazones | +40 | +36 + 4 ✔ |
| Tamaño Grande, Syrup de chocolate, Extra de leche condensada | no se puede separar (pedido de 56) | "Syrup" y "Extra de leche condensada" no existen en el POS |

   Los nombres difieren entre PedidosYa y el POS ("Patriota Bowl" / "Patriota"): hace falta una tabla de
   equivalencias (ya existe `platform_catalog_mappings`, sin uso).

## 5. Lo que el sistema tiene y lo que falta

| Línea | Hoy | Falta |
|---|---|---|
| Ventas del local | `sales` completadas, canal `pos` | Agrupar por mes |
| Ventas PedidosYa | Casi no se registran en el POS | Importación semanal (§6) |
| Costo de ventas | Insumos con costo, recetas incompletas | Registro de compras (método A) |
| Gastos | Egresos de caja sin categoría | Categoría en egresos; gastos fuera de caja; gastos fijos |
| Impuestos | — | Gasto fijo (régimen simplificado) |

## 6. Importación semanal de PedidosYa

### 6.1 Flujo para el dueño (cada semana, ~2 minutos)

1. En Finanzas → **PedidosYa** → "Cargar semana".
2. Subir `semana.csv` (detalle de pedidos).
3. Escribir los 8 montos del estado de cuenta PDF: ventas netas, envíos a cargo del local, servicios
   PedidosYa, cargos operativos, publicidad, reintegros, impuestos y total liquidado (+ fecha de pago).
4. Opcional: adjuntar el ZIP de la semana.
5. El sistema muestra la conciliación (§6.3) y avisa diferencias; el dueño confirma.

### 6.2 Lectura del CSV (reglas)

- Columnas **por nombre**, no por posición (si PedidosYa agrega columnas no se rompe). Obligatorias: Nro de
  pedido, Estado del pedido, Fecha del pedido, Forma de pago, Tipo de entrega, Total parcial, Comisión,
  Impuesto, Descuento financiado por usted, Descuento financiado por PedidosYa, Ingreso estimado,
  Artículos del pedido. Si falta una, se rechaza el archivo indicando cuál.
- Números con coma decimal (`"1.234,50"` → 1234.50); vacío = 0. Quitar el BOM.
- Fechas en hora de La Paz.
- **Idempotente**: el `Nro de pedido` es único; volver a subir la misma semana actualiza, no duplica.
- Se guarda la fila original completa (JSON) para no perder columnas no usadas.
- Lectura con PHP nativo (`fgetcsv`): **sin dependencias nuevas** (producción no ejecuta `npm install`).

### 6.3 Conciliación automática

- Ventas brutas (CSV, entregados) − descuentos PedidosYa no comisionados = **ventas netas** del estado de
  cuenta.
- Suma de comisiones del CSV = **servicios PedidosYa**.
- Ventas netas − servicios − cargos − publicidad + reintegros − impuestos = **total liquidado**.
- Cada diferencia mayor a Bs 1 se muestra con su monto.

### 6.4 Datos (backend)

| Tabla | Contenido |
|---|---|
| `pedidosya_settlements` | Semana (desde/hasta), los 8 montos del estado de cuenta, fecha de pago, ZIP adjunto, quién cargó, diferencias de conciliación |
| `pedidosya_orders` | Una fila por pedido: número (único), estado, fechas, forma de pago, tipo de entrega, subtotal, comisión, impuesto, descuentos (local / PedidosYa), ingreso estimado, artículos (texto), `settlement_id`, `sale_id` (venta del POS vinculada, si existe), fila original (JSON) |

### 6.5 Vínculo con el POS (fase 3)

Emparejar cada pedido importado con su venta del POS por número de pedido anotado o, si no, por fecha (±30
min) y monto. Reporte: pedidos PedidosYa que no se registraron en el POS y diferencias de precio por artículo
(ayuda a corregir el Tarifario).

## 7. Cálculos

### 7.1 Estado de Resultados del mes

1. **Ventas** = ventas del local (POS, canal `pos` y retroactivas) + **ventas netas PedidosYa** de las semanas
   importadas. Las ventas PedidosYa del POS **no se suman** para no contarlas dos veces.
2. **Costo de ventas** = compras del mes en categorías de costo (helado/açaí, ingredientes, descartables).
3. **Gastos operativos** = salarios, servicios básicos, internet y teléfono, publicidad propia, **costos
   PedidosYa** (servicios + publicidad + IVA − reintegros de descuentos), mantenimiento, otros + egresos de caja
   clasificados.
4. **Impuestos** = cuota fija del régimen simplificado (gasto fijo).
5. Utilidad bruta, operativa, neta y rentabilidad %, con los mismos pasos que el material.

Las semanas de PedidosYa se asignan al mes por su fecha de fin (la semana 14–20/09 va a septiembre). Las que
cruzan dos meses se prorratean por fecha de cada pedido (el CSV lo permite).

### 7.2 Ganancia de PedidosYa (por semana y por mes)

| Línea | Semana 14–20/09 |
|---|---:|
| Ventas brutas | 748,00 |
| Total liquidado | 523,56 |
| **Se queda PedidosYa** | 224,44 (30,0 %) |
| (−) Costo de los productos (food cost % del mes × ventas brutas) | según compras del mes |
| (−) Empaques de delivery (categoría propia) | según compras |
| **= Ganancia PedidosYa** | |

Indicadores: % que se queda PedidosYa, desglosado en comisión, publicidad, IVA y reintegros; ticket promedio
(68 Bs); pedidos cancelados y su costo.

## 8. Información que carga el dueño

- **Una vez:** gastos fijos (salarios, internet, teléfono, servicios básicos, publicidad propia, cuota del
  régimen simplificado) con monto y frecuencia; categorías de compra.
- **Cada semana:** compras (fecha, proveedor, categoría, monto, nota) y la semana de PedidosYa.
- **Cada mes:** confirmar los gastos fijos propuestos y registrar gastos variables pagados fuera de la caja.
- **Al sacar dinero de la caja:** elegir la categoría del egreso.

**Regla para no contar dos veces:** lo que se pagó desde la caja ya es un egreso de caja; en Finanzas solo se
registra lo pagado por fuera.

## 9. Diseño general

### 9.1 Tablas

| Tabla | Para qué |
|---|---|
| `expense_categories` | Plan de cuentas: sección (`cost_of_sales`, `operating`, `financial`, `taxes`) y grupo. Precargadas: Helado y açaí, Ingredientes y frutas, Descartables (vasos, cucharas), Empaques delivery, Salarios, Servicios básicos, Internet y teléfono, Publicidad propia, Mantenimiento, Régimen simplificado, Otros |
| `finance_expenses` | Compras y gastos pagados fuera de la caja |
| `recurring_expenses` | Gastos fijos con frecuencia (mensual o cada N meses) |
| `pedidosya_settlements`, `pedidosya_orders` | §6.4 |
| `finance_settings` | Método de costo, cuota de impuestos |
| `fixed_assets` (fase 4) | Equipos y depreciación |
| `finance_periods` (fase 5) | Cierre de mes congelado |

Cambio en tabla existente: `cash_movements.expense_category_id` (nullable).

### 9.2 Pantallas

Nueva vista **Finanzas** (`/finanzas`, solo dueño) con pestañas: **Resumen** (Estado de Resultados del mes,
indicadores, gráfico de 12 meses), **Compras y gastos**, **PedidosYa** (semanas cargadas, conciliación,
ganancia), **Gastos fijos**, **Configuración**. En Turno, selector de categoría al registrar un egreso.

## 10. Análisis de nodos e impacto (grafo `graphify-out/`, 07/10)

| Nodo existente | Conexiones | Cambio | Riesgo |
|---|---:|---|---|
| `Sale` (nodo central) | 45 | Solo lectura; índice `(status, created_at)` | Bajo |
| `apiFetch()` (nodo central) | 49 | Sin cambios | Bajo |
| `CashMovement` | 12 | Columna `expense_category_id` opcional; el arqueo no cambia | Bajo |
| `CashRegisterSessionController` | — | `recordMovement` acepta la categoría | Bajo |
| `PlatformCatalogMapping` | — (sin uso hoy) | Se usa para equivalencias PedidosYa ↔ POS (fase 3) | Bajo |
| `InventoryService` / `InventoryMovement` | 7 / 15 | **No se tocan** con el método A. Solo si más adelante se adopta el costo por recetas | — |
| `routes/api.php`, `router/index.js`, `AppShell.vue` | — | Rutas y menú nuevos | Bajo (conflictos con ramas pendientes) |

Nodos nuevos sin dependencias entrantes: servicios de reporte e importación, modelos nuevos y `Finance.vue`.

Impactos transversales: solo tablas y columnas nuevas (la precarga de categorías inserta datos: confirmar antes
de producción); datos sensibles solo para `super_admin` y auditados; ventas editadas o retroactivas cambian
meses pasados hasta tener cierre de mes; ventas antiguas guardadas en UTC pueden caer en el mes equivocado.

## 11. Fases

| Fase | Contenido |
|---|---|
| 1 | Categorías, compras y gastos, gastos fijos, categoría en egresos de caja, Estado de Resultados del mes (solo local) |
| 2 | Importación semanal PedidosYa (CSV + 8 montos + ZIP), conciliación, PedidosYa en el Estado de Resultados y ganancia por canal |
| 3 | Vínculo pedidos ↔ ventas POS, equivalencias de nombres, diferencias de precio |
| 4 | Gráficos de 12 meses, exportar CSV/PDF, punto de equilibrio, equipos y depreciación |
| 5 | Cierre de mes congelado; lectura automática del Excel de la liquidación |

## 12. Tests

1. Importar `semana.csv` de ejemplo (fixture anonimizado): 13 pedidos, 11 entregados, ventas brutas 748,
   comisión 187, ingreso estimado 533,06.
2. Con los 8 montos de la semana: conciliación sin diferencias y total liquidado 523,56.
3. Reimportar el mismo CSV no duplica pedidos.
4. CSV sin una columna obligatoria → error que la nombra; números con coma y miles con punto se leen bien.
5. Estado de Resultados de un mes con ventas del local, PedidosYa importado, compras, gastos fijos y egresos de
   caja: cada línea coincide con un cálculo a mano; las ventas PedidosYa del POS no se suman dos veces.
6. Generar gastos fijos dos veces el mismo mes no los duplica.
7. Un cajero o admin recibe 403 en Finanzas.

## 13. Decisiones (con propuesta)

| Decisión | Propuesta |
|---|---|
| Quién ve Finanzas | Solo `super_admin` (dueño) |
| Costo de ventas | Método A: compras registradas del mes |
| Impuestos | Gasto fijo del régimen simplificado (monto y frecuencia los indica el dueño) |
| Ventas PedidosYa | Desde la importación semanal, no desde el POS |
| Estado de cuenta | Montos tecleados del PDF (sin librerías nuevas); lectura automática del Excel más adelante |
| Orden | Activar `feat/precios-pedidosya`, hacer los tamaños (corrigiendo Mediano a +8 y Arma tu Bowl a 32 si se confirman) y luego Finanzas en una rama nueva |

## 14. Fuera de alcance

Balance general, flujo de caja proyectado, facturación y conciliación bancaria.
