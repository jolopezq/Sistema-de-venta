# Plan: importar la carpeta de PedidosYa (liquidaciones, pedidos, ventas y facturas)

> Versión 1, 2026-11-02. Revisado contra el código (producción en `182f1be`) y contra la carpeta real
> `payoutArchive_PY_BO_20261001_to_20261101` (15 archivos, 5 semanas).

## Resumen: la mejor opción

En **Finanzas → PedidosYa**, un botón **«Importar carpeta de PedidosYa»**. Eliges la carpeta que bajas del portal, con
el **CSV de pedidos del mismo período** adentro, y el sistema:

1. reconoce cada semana por su número de liquidación;
2. lee del PDF los montos, las fechas y la fecha de pago, sin que escribas nada;
3. lee del Excel la lista de pedidos y los cargos por reclamos, y de la factura su número, fecha y total;
4. cruza todo y muestra una tabla: semanas nuevas, ya importadas y con diferencias;
5. con **«Importar»**, guarda las semanas nuevas, **crea o vincula las ventas** de los pedidos y guarda los PDF y el
   Excel, que quedan para descargar desde Finanzas.

**Por qué esta opción:**

- **Usa la importación que ya existe** (conciliación, ventas, reparto entre meses), en vez de armar otra en paralelo.
  Solo se elimina lo manual: escribir los 8 montos y elegir archivos uno por uno.
- **El servidor vuelve a leer el PDF al guardar.** Los montos nunca los manda el navegador.
- **Se sube una semana por vez.** No choca con los límites de subida del servidor (20 archivos por envío), y si una
  semana falla, las demás siguen.
- **Se puede repetir sin riesgo:** volver a elegir la misma carpeta salta lo ya importado.

---

## 1. Qué hay en la carpeta (verificado)

Por cada semana, tres archivos con el mismo número de liquidación (por ejemplo, `7800712391`):

| Archivo | Qué trae |
|---|---|
| `account_statement_<N>_1.pdf` | Estado de cuenta: período, fecha de pago, las 11 líneas del resumen y el total liquidado. |
| `invoice-<N>.xls` (es un Excel moderno) | Hoja «Lista de Pedidos»: número, fecha (sin hora), monto, monto comisionable, comisión, descuentos y método de pago. Si hubo reclamos, una segunda hoja «Cargos por reclamos»: pedido, fecha, hora, motivo y cargo. |
| `invoice_<N>.pdf` | Factura fiscal de PedidosYa al local: número, fecha, código de autorización, total y base de crédito fiscal. |

**Lo que no trae:** los artículos de cada pedido ni la hora. Están en el **CSV de pedidos del portal**, que hoy ya se
usa y que es lo único que permite crear las ventas con sus productos. Hay que bajarlo y ponerlo en la misma carpeta:
uno de todo el período o uno por semana, las dos formas sirven.

### Las 5 semanas de la carpeta de octubre

| Semana | Pago | Ventas netas | Servicios | Cargos | Publicidad | Reintegros | IVA | **Liquidado** | Factura | Pedidos |
|---|---|---|---|---|---|---|---|---|---|---|
| 21–27/09 | 01/10 | 838,00 | 209,50 | 0 | 105,85 | 0 | 47,11 | **475,54** | 688161 del 28/09: 362,46 | 15 |
| 28/09–04/10 | 08/10 | 824,00 | 206,00 | 0 | 66,64 | 0 | 40,73 | **510,63** | 691934 del 05/10: 313,37 | 17 |
| 05–11/10 | 15/10 | 686,00 | 171,50 | 40,00 | 90,71 | 0 | 45,15 | **338,64** | 695176 del 12/10: 347,36 | 13 |
| 12–18/10 | 22/10 | 1.166,00 | 302,50 | 0 | 100,70 | 38,26 | 54,52 | **746,54** | 700228 del 20/10: 419,48 | 20 |
| 19–25/10 | 29/10 | 1.082,00 | 270,50 | 0 | 136,38 | 0 | 60,79 | **614,33** | 701967 del 26/10: 467,67 | 17 |

Las 5 cuadran con la fórmula que ya usa el sistema:
`ventas netas − envíos − servicios − cargos − publicidad + reintegros − IVA = liquidado`.

**Detalles que el importador tiene que respetar:**

- **Semana 05–11/10:** el cargo de Bs 40 es un reclamo («Orden incorrecta», pedido del 08/10 a las 21:21), detallado
  en la segunda hoja del Excel.
- **Semana 12–18/10:**
  - la comisión (302,50) es el 25 % de 1.210, no de 1.166: el pedido de Bs 44 con descuento de PedidosYa cuenta 0 en
    ventas netas pero paga comisión. El control se hace con la columna «Monto comisionable»;
  - la factura (419,48) descuenta ese reintegro y difiere en Bs 0,02 de la suma del estado de cuenta.
- **El paquete de «octubre» empieza el 21/09.** Trae la última semana de septiembre, pagada el 01/10. Finanzas ya
  reparte por la fecha de los pedidos las semanas que cruzan de mes.
- **La fecha de la factura no es la de la semana** (la del 21–27/09 es del 28/09). Para el crédito fiscal cuenta la
  fecha de la factura.

---

## 2. Decisiones del plan

| # | Tema | Decisión |
|---|---|---|
| D1 | Cómo se elige | Selector de **carpeta** del navegador (Chrome, Edge, Safari y Firefox lo tienen). Se ignoran los archivos que no son de PedidosYa y se listan. |
| D2 | El CSV de pedidos | Va en la misma carpeta: uno del período o varios. **Sin CSV** se importan igual los montos, los pedidos (del Excel, sin hora), los reclamos y las facturas, pero **no se crean ventas**. Si después agregas el CSV y eliges la carpeta otra vez, esas semanas se completan. |
| D3 | Semanas ya cargadas | **Mismo número de liquidación:** se salta. **Mismas fechas cargadas a mano, mismos montos:** solo se agregan el número y los archivos; no se tocan pedidos ni ventas. **Mismas fechas, montos distintos:** se muestra la diferencia y tú decides si reemplazar o saltar. **Se cruza con otra semana de fechas distintas:** se bloquea, como hoy. |
| D4 | Fuente de verdad | El servidor lee el PDF al guardar; el navegador solo manda archivos. |
| D5 | Lector de PDF | Librería `smalot/pdfparser` (licencia LGPL-3.0, sin modificarla). **Probada con tus 5 estados de cuenta y 5 facturas: leyó todos los montos, fechas y números.** El VPS ya ejecuta `composer install` en cada actualización. |
| D6 | Frenos de seguridad | **Bloquean la semana:** las líneas del PDF no suman su total; aparece con monto distinto de 0 una línea que el sistema no guarda (ajustes, saldo pendiente, crédito PedidosYa, ventas fuera de la app cobradas); el Excel no coincide con el PDF en ventas netas o comisión; los reclamos no coinciden con los cargos operativos. **Solo avisan:** la factura difiere más de Bs 0,05; pedidos del CSV fuera de la semana. Una semana bloqueada se puede cargar a mano con la ventana de hoy. |
| D7 | Archivos | Se guardan en `storage/app/pedidosya/<número>/` (privado, no público) y se descargan desde el detalle de la semana. Se agregan al respaldo diario del VPS, que hoy solo copia la base y las fotos. |
| D8 | Facturas | Se guardan por semana (número, fecha, código de autorización, total) y Finanzas muestra la lista del mes **por fecha de factura**, para el crédito fiscal. |
| D9 | Permiso | El mismo de hoy: *Finanzas, Editar*. |
| D10 | Datos sensibles | La carpeta de ejemplo sale del proyecto (`.gitignore`: `docs/payoutArchive_*`). Las pruebas usan texto anonimizado; la prueba con tus archivos reales solo corre en tu Mac si la carpeta existe. |

---

## 3. Diseño

### 3.1 Datos (migración aditiva)

Columnas nuevas en `pedidosya_settlements`, todas nulas:

- `statement_number` (única): número de liquidación;
- `orders_source`: `csv` o `xls`, de dónde salieron los pedidos;
- `invoice_number`, `invoice_date`, `invoice_authorization`, `invoice_total`;
- `claims` (json): cargos por reclamos, pedido por pedido;
- `files` (json): rutas del estado de cuenta, del Excel y de la factura.

`zip_path` queda para las semanas cargadas a mano.

### 3.2 Lectores (`backend/app/Services/PedidosYa/`)

- **`StatementReader`:** PDF → número, período, fecha de pago, las 11 líneas y el total. Verifica que las líneas sumen
  el total y que las no guardadas sean 0.
- **`OrderListReader`:** Excel → pedidos y reclamos. Lee el XML del Excel con lo que ya tiene PHP (zip y xml), sin
  librerías nuevas.
- **`InvoiceReader`:** PDF de la factura → número, fecha, código de autorización y total.
- Cada lector separa «leer el archivo» de «interpretar el texto», para probar la interpretación con texto
  anonimizado.

### 3.3 Servidor

- **`POST /pedidosya-settlements/read-week` (sin guardar):**
  - recibe el estado de cuenta (obligatorio), el Excel, la factura y los CSV;
  - devuelve los montos, la factura, los reclamos, los controles, el estado frente a lo ya cargado (D3), los pedidos
    del CSV que caen en la semana y el resumen de ventas (crear, vincular, sin equivalencia), con el `SalesLinker`
    de hoy.
- **`POST /pedidosya-settlements` (el de hoy), con modo «carpeta»:**
  - recibe los mismos archivos, vuelve a leerlos, guarda los montos del PDF, toma del CSV **solo los pedidos de esa
    semana** (hoy toma todos y solo avisa), guarda los archivos y el número de liquidación;
  - sin CSV, toma los pedidos del Excel y no crea ventas;
  - el modo manual de hoy no cambia.
- **`GET /pedidosya-settlements/{id}/files/{tipo}`:** descarga del estado de cuenta, el Excel o la factura.
- **El listado de semanas** incluye los datos de la factura, para la lista mensual de facturas.

### 3.4 Pantalla

1. **«Importar carpeta de PedidosYa»**, junto al «Importar semana» de hoy. El navegador pide confirmar que se suben los
   archivos de la carpeta.
2. **«Lo que encontré»:** una fila por semana, con:
   - semana, fecha de pago y total liquidado;
   - archivos encontrados: PDF, Excel, factura y CSV;
   - controles (✓ o el aviso en palabras);
   - ventas: cuántas se crean, cuántas se vinculan, cuántas ya estaban;
   - estado: Nueva, Ya importada, Cargada a mano (igual o distinta), Se cruza.

   Debajo, los archivos ignorados.
3. **Artículos sin equivalencia en el menú:** un solo bloque para todas las semanas, con la pantalla de equivalencias
   de hoy. Una equivalencia sirve para todas.
4. **Ventas con dudas:** si dos ventas del POS coinciden en hora y monto con un pedido, se marca y se puede elegir;
   por defecto, la primera, como hoy.
5. **«Importar N semanas»:** de a una, con progreso y el resultado por semana. Si una falla, las demás siguen.
6. **Detalle de la semana:** número de liquidación, factura, reclamos y botones para descargar los archivos.
7. **Lista «Facturas de PedidosYa del mes»**, por fecha de factura.

Todo entra a 1366 × 611 y 1093 × 489, en claro y oscuro.

### 3.5 Lo que no cambia

- La ventana manual «Importar semana».
- La fórmula de conciliación.
- El reparto entre meses.
- El POS.

---

## 4. Fases

Rama `feat/importar-carpeta-pedidosya`. Sale de `feat/gasto-pago-dividido` si se publica antes, o de producción. Cada
commit actualiza el CHANGELOG y, si corresponde, la BITÁCORA.

- **F0 · Preparación:** rama, `.gitignore` para las carpetas de PedidosYa, este plan en `docs/` y respaldo de la base
  local.
- **F1 · Lectores:** `smalot/pdfparser` y los tres lectores, con pruebas sobre texto anonimizado y una prueba local con
  la carpeta real.
- **F2 · Servidor:** migración, `read-week`, modo «carpeta» del guardado, descargas, y pruebas de los casos de D3 y D6.
- **F3 · Pantalla de importación:** selector, tabla, equivalencias, importación con progreso y E2E en la portátil.
- **F4 · Detalle y facturas:** archivos y reclamos en el detalle de la semana y la lista de facturas del mes.
- **F5 · Verificación y cierre:** sección 5, BITÁCORA y **preguntar si se publica en el VPS**.

---

## 5. Pruebas

**PHPUnit**

- **Lectores, con texto anonimizado de las 5 semanas:**
  - montos, fechas y número;
  - la semana con reclamo y la semana con reintegro;
  - una línea no guardada con monto distinto de 0 → se rechaza;
  - un total que no suma → se rechaza.
- **Excel:** un archivo armado en la prueba, con las dos hojas.
- **Importación de una semana:**
  - nueva;
  - repetida → se salta;
  - cargada a mano igual → solo agrega el número;
  - cargada a mano distinta → no se toca sin confirmación;
  - cruzada → bloqueada;
  - sin CSV → sin ventas;
  - con un CSV de todo el período → cada semana toma solo sus pedidos.
- **Descarga de archivos:** solo con *Finanzas*.

**Unitarias:** la clasificación de archivos por nombre en el navegador.

**E2E:** la carpeta de 5 semanas con una ya importada y una con aviso, a 1366 × 611 y 1093 × 489, en claro y oscuro.

**Flujo contra una copia de la base, con tu carpeta real y un CSV del período:**

1. importar las 5 semanas;
2. comprobar los totales (2.685,68 liquidado en total) y el Estado de Resultados de octubre;
3. revisar las ventas creadas y vinculadas;
4. elegir la carpeta otra vez → todo se salta.

**Además:** contraste AA y `vite build`.

---

## 6. Al publicar

- La migración es aditiva.
- `composer install` instala la librería del PDF; lo hace solo `04-actualizar.sh`.
- Agregar `storage/app/pedidosya` al respaldo diario (`05-respaldos.sh`, que hoy no está en git).
- Si la laptop de Windows todavía se actualiza desde `main`, confirmar que ejecuta `composer install`. Si no, solo la
  importación por carpeta fallaría allí.

## 7. Fuera de alcance

- Subir el ZIP sin descomprimir. La Mac lo descomprime con doble clic.
- Bajar los archivos del portal automáticamente.
- Exportar el libro de compras IVA.
- El doble conteo de una compra registrada como gasto de caja y como compra de Inventario (B4 de la auditoría de
  Finanzas).
