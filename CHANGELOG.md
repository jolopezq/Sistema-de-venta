# Changelog

Cambios relevantes del sistema. Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Cada cambio funcional se agrega a "Sin publicar" en el mismo commit; al fusionar en `main`, la sección
se renombra con la fecha de la actualización de producción.

## [Sin publicar]

### Cambiado
- **Remover selector manual de PedidosYa en el Punto de Venta (POS)** ([plan](docs/PLAN_REMOVER_CANAL_PEDIDOSYA_POS.md)): simplificación de la pantalla de caja eliminando el selector de canal `[ Local ]` / `[ PedidosYa ]`, el aviso y los modales de ajuste de precios en `Pos.vue`. Dado que los pedidos de la plataforma se gestionan e imprimen directamente en el dispositivo POS oficial de PedidosYa y se concilian por documentos en Finanzas, la columna del ticket recupera ~70px verticales, garantizando que el total y el botón de cobro se mantengan a la vista en la portátil de caja (1366 × 611).
- **Robustez en importación de lotes y delimitadores CSV de PedidosYa**: detección automática de delimitador (coma `,`, punto y coma `;`, tabulador `\t`), eliminación de marcas BOM residuales en cabeceras de columnas, y fallback transparente a la planilla Excel semanal (`ordersXls`) en caso de que el archivo CSV esté ausente o no pueda parsearse. Respuestas controladas con código HTTP 422 en lugar de 500 ante excepciones de archivo.

### Agregado
- **Asistencia en el local y sueldos** ([plan](docs/PLAN_ASISTENCIA_Y_SUELDOS.md)): reloj de asistencia en la portátil de caja, validado en el local, con rostro, y planilla de sueldos. Cada trabajador ve solo sus horas de entrada y salida; todo lo demás es de los administradores.
  - **Fase 1 (Servidor):** migración aditiva `2026_11_03_000001_create_attendance_tables` (`employee_profiles`, `face_enrollments`, `attendance_networks`, `attendance_devices`, `attendance_marks`).
    - **En el local:** una marca vale si llega desde un **equipo autorizado** (llave en `X-Attendance-Device`; el servidor guarda solo su huella SHA-256) y desde una **red del local** (IPv4 exacta o rango; IPv6 por prefijo /64). Equipo bueno con red nueva: la marca queda «pendiente: red nueva» y no se pierde; al aprobarla con «Es la red del local» la red queda registrada y las demás marcas de esa red se validan solas.
    - **Reloj** (`/api/attendance/kiosk/*`, sin sesión del trabajador): lista de quienes marcan (nombre, iniciales y si toca entrada o salida, nunca horas ni montos), PIN de 4 a 6 dígitos con bloqueo de 15 min tras 5 fallos por persona y equipo, foto obligatoria (JPEG o PNG, en `storage/app/private/asistencia/AAAA/MM`), doble toque en 2 min ignorado, marcas sin conexión con la hora del equipo (`offline_review`, idempotentes por `local_uuid`) y «Mis marcas» (día, entrada y salida de los últimos 7 días).
    - **Rostro:** el navegador manda el descriptor y el servidor lo compara con el registrado (misma escala que la librería Human; umbral `ATTENDANCE_FACE_THRESHOLD`, 0,55). Debajo del umbral, sin parpadeo o sin descriptor, la marca queda «Rostro a revisar»; al trabajador nunca se le devuelve el puntaje.
    - **Administradores** (permiso por persona nuevo *Asistencia y sueldos*, Ver o Editar; los administradores lo tienen por defecto y el dueño se lo puede quitar): hoy, bandeja «A revisar» (marcas pendientes y entradas sin salida), todas las marcas, fotos, aprobar, rechazar con motivo, agregar o corregir una marca con motivo (la original queda «corregida»), equipos, redes y el ajuste «abrir la caja exige marcar la entrada».
    - **Perfil laboral** (Usuarios, solo Super Admin): modo de pago (por hora, sueldo mensual o por turno), horario por día, tolerancia y horas extra, PIN (guardado con hash), consentimiento y hasta 5 capturas del rostro. Al quitar a una persona se borra su rostro y deja de aparecer en el reloj; sus marcas quedan.
    - **Rol nuevo «Personal» (`staff`):** solo marca asistencia; no inicia sesión ni tiene acceso a ninguna pantalla.
    - `GET /api/attendance/me/today`: la persona con sesión ve solo su entrada y su salida de hoy.
    - Tarea diaria `attendance:purge-photos` (03:15): borra las fotos de las marcas de más de 90 días; la marca queda.
    - 23 pruebas nuevas en `AttendanceKioskTest` y `AttendanceAdminTest`.
  - **Fase 2 (Reloj y pantallas):**
    - **Reloj de asistencia** (`/asistencia/reloj`, pantalla completa, sin sesión): elegir persona, PIN en teclado táctil o físico, cámara con foto, confirmación y «Mis marcas». El trabajador ve solo su nombre, si le toca entrada o salida, la hora marcada y sus marcas; nunca horas totales, montos, porcentajes ni el motivo de una revisión. Se abre desde el login y desde la barra superior, solo en un equipo autorizado.
    - **Sin conexión:** la marca se guarda con su foto en IndexedDB (Dexie v6, tabla `attendanceQueue`) y se envía sola al volver la conexión; el reloj muestra cuántas faltan enviar.
    - **Asistencia** (menú Sistema, con la marca de marcas por revisar): Hoy, A revisar (fotos de la marca y del rostro registrado, red, rostro; «Es la red del local», aprobar, rechazar con motivo, poner la salida olvidada), Todas las marcas (filtros y «Corregir») y Equipos y redes (autorizar este equipo, redes, quitar equipos y el ajuste de la caja).
    - **Usuarios:** rol «Personal (solo marca asistencia)» (el correo y la contraseña se generan solos) y botón «Trabajo y asistencia» por persona: si marca, modo de pago, recargos, tolerancia, horario por día y PIN, con aviso si queda por debajo del salario mínimo.
    - Permiso por persona *Asistencia y sueldos* en la ficha de permisos.
    - `npm run test:unit`: 6 pruebas nuevas (`attendance.test.mjs`).
  - **Fase 3 (Rostro):**
    - Librería **Human 3.3.6** (MIT) copiada en `frontend/public/vendor/human/` con solo los modelos que usa el reloj (detección, puntos del rostro, descriptor, cara real y vida; ~13 MB). No se precarga en todos los equipos: el service worker la guarda la primera vez que se abre el reloj (`CacheFirst`, caché `ohana-human`) y después funciona sin internet. Con WebGL; si el equipo no lo tiene, en CPU.
    - **Al marcar:** una sola cara, parpadeo y descriptor; con eso se toma la foto sola. Si en 20 s no se logra (luz, lentes, cámara), se toma a mano y la marca queda para revisar. El servidor compara el descriptor con el rostro registrado.
    - **Registrar el rostro** (Usuarios → Trabajo y asistencia): consentimiento firmado y de 3 a 5 fotos (de frente y un poco hacia los lados), con aviso si hay más de una cara o no se ve bien. «Borrar rostro» lo quita.
- **Cómo se cobró y dinero que entró en el mes (Finanzas):**
  - **Estado de Resultados:** «Ventas del local» se abre en Efectivo, QR o banco y Tarjeta (y otros medios si hay), con el % de cada uno sobre las ventas del local. Se suma cada pago, así un cobro mixto se reparte entre sus medios. Una venta sin pago registrado o cobrada de más ya no se imputa a efectivo: queda en **«Sin cuadrar»**, con las fechas para corregirla en Control diario, y el desglose suma exactamente las ventas del local. PedidosYa sigue con sus ventas netas (antes de comisiones), no con lo liquidado.
  - **Resumen:** tarjeta nueva **«Dinero que entró en el mes»**, por fecha de cobro: efectivo, QR o banco y tarjeta del local, y lo que PedidosYa **depositó en el mes según la fecha de pago** (aunque la semana sea del mes anterior), con el total que llegó a la cuenta. Avisa las semanas que se depositan el mes siguiente, las que no tienen fecha de pago, los días sin semana importada y las ventas que no cuadran. En «PedidosYa en el mes», «Te depositaron» pasa a «Te pagan por esas ventas»: es lo liquidado por las ventas del mes, no lo depositado en el mes.
- **Importar carpeta de PedidosYa** ([plan](docs/PLAN_IMPORTAR_CARPETA_PEDIDOSYA.md)): importación directa del paquete mensual o semanal de PedidosYa (carpeta o ZIP con estados de cuenta en PDF, planillas Excel y facturas fiscales) eliminando la carga manual de montos y cruzando automáticamente los pedidos con el CSV de artículos.
  - **Fase 1 (Lectores del backend):** lectores `StatementReader` (parsea PDFs de estado de cuenta extrayendo las 11 líneas del resumen, período y fecha de pago bancario, con verificación de balance matemático), `InvoiceReader` (parsea la factura fiscal con número, fecha de emisión, código de autorización y total) y `OrderListReader` (lee las hojas «Lista de Pedidos» y «Cargos por reclamos» del Excel sin librerías externas). Probado con suite unitaria y contra archivos reales de octubre.
  - **Fase 2 (Servidor y base de datos):** migración aditiva `2026_11_02_000004_add_archive_fields_to_pedidosya_settlements` (`statement_number`, `orders_source`, `invoice_number`, `invoice_date`, `invoice_authorization`, `invoice_total`, `claims` y `files`). Endpoints `POST /api/finance/pedidosya-settlements/read-week` (previsualización con detección de estados D3 y frenos de seguridad D6), modo `folder` en `POST /api/finance/pedidosya-settlements` (con guardado seguro en `storage/app/pedidosya/{statement_number}/`), descarga protegida en `GET /api/finance/pedidosya-settlements/{id}/files/{type}` y resumen mensual `GET /api/finance/pedidosya-settlements/invoices-summary` para crédito fiscal. Suite de pruebas en `PedidosYaSettlementArchiveTest` con 10 pruebas y 38 aserciones (suite global: 444 pruebas en verde).
  - **Fase 3 (Modal de importación por carpeta):** componente `PedidosYaFolderImportModal.vue` y utilidad `pedidosYaArchive.js` (`classifyArchiveFiles`). Permite arrastrar o seleccionar la carpeta `payoutArchive_*`, agrupando automáticamente estados de cuenta, planillas Excel y facturas fiscales junto al CSV de pedidos (`orderDetails.csv`). Previsualización secuencial con control de seguridad D6, detección de estados D3, panel de equivalencias de catálogo y ejecución en lote con barra de progreso. Pruebas unitarias en `pedidosYaArchive.test.mjs` (53 pruebas en verde).
  - **Fase 4 (Detalle y crédito fiscal):** en `PedidosYaSettlements.vue`, pestaña nueva «Facturas del mes (crédito fiscal)» con resumen contable (cantidad de facturas, base imponible e IVA 13%) y tabla con enlace directo para descargar las facturas fiscales del mes. En el modal de detalle de semana, visualización de N.º de estado de cuenta, datos de factura fiscal, desglose de cargos por reclamos y botones para descargar los archivos originales asociados (PDF del estado, Excel de órdenes y PDF de factura) mediante `apiDownload` con autenticación. Suite E2E en `importar-carpeta-pedidosya.spec.js` y `vite build` limpio en 1.5s.
- **Gasto de caja dividido entre efectivo y QR** ([plan](docs/PLAN_GASTO_PAGO_DIVIDIDO.md)): un gasto se puede pagar con una parte de efectivo de la gaveta y el resto por QR o banco, y queda como un solo gasto.
  - Se guarda con `payment_method = 'mixed'` y la columna nueva `cash_amount` (migración aditiva `2026_11_02_000003`). La parte en efectivo tiene que ser mayor que 0 y menor que el total; solo los gastos se dividen.
  - El arqueo resta de la gaveta solo la parte en efectivo; el resto cuenta como «gastos con QR». Finanzas lo cuenta una vez, por el total.
  - Corregir, anular y la corrección en una caja cerrada funcionan con gastos divididos; el «Antes» y la auditoría guardan el desglose.
  - **Modal «Registrar gasto» / «Corregir gasto»:** debajo del monto, el enlace «Dividir pago (efectivo + QR)». Al activarlo, el monto pasa a ser el total, aparece «De eso, salió de la gaveta» y el QR se calcula solo; «Quitar división» vuelve a un solo método. «En la gaveta ahora / Quedaría», el aviso «no alcanza» y la vista previa de una caja cerrada miran solo la parte en efectivo. Un gasto dividido se abre ya dividido al corregirlo, y cambiar solo el desglose cuenta como cambio. Un ingreso no se divide.
  - **Pantallas:** el método de un gasto se muestra desde una sola función (`utils/cashMovementMethod.js`). Antes cada pantalla repetía la condición y cualquier método distinto de efectivo y QR salía como «Tarjeta». Turno, Control diario, el reporte de caja y el modal de anular muestran «💵📱 Efectivo + QR» con el desglose, y el «Antes» de una corrección incluye el desglose.
- **Corregir un gasto de una caja ya cerrada, directo en Control diario** ([plan, §3.8](docs/PLAN_CORREGIR_GASTOS_CAJA.md)): con el permiso *Corregir cajas cerradas*, «Corregir» y «Anular» aparecen también en cajas cerradas. Al guardar, el cierre se rehace con el mismo efectivo contado (teórico y diferencia nuevos; contado, retiro, fondo para mañana y fecha del cierre sin cambio), el cierre anterior queda en el historial («Caja rectificada») y la auditoría registra `cash_session_reclosed`. Los modales muestran el teórico y la diferencia antes → después. Sin el permiso, la fila lo indica y el servidor responde 403.
- **Corregir y anular gastos de caja** ([plan](docs/PLAN_CORREGIR_GASTOS_CAJA.md)):
  - **Fase 1 (Servidor):** migración aditiva `2026_11_02_000002_add_correction_fields_to_cash_movements` con `edited_by`, `edited_at`, `edit_reason`, `edit_previous`, `edit_count`, `voided_by` y `void_reason`. Endpoints `PUT /cash-sessions/{cashSession}/movements/{cashMovement}` y `POST .../void` con motivo obligatorio (10 a 255 caracteres). Auditoría legible `cash_movement_updated` y `cash_movement_voided`. Listado y reporte actualizados con anulados y correcciones.
  - **Fase 2 (Modal y store):** `CashExpenseModal.vue` adaptado para modo corrección (prop `movement`), cálculo de gaveta disponible sin falsos sobregiros, vista previa del cambio en la gaveta y motivo obligatorio. Modal nuevo `VoidCashMovementModal.vue` para confirmación de anulación con motivo. Acciones `updateCashMovement` y `voidCashMovement` en `turno.js`.
  - **Fase 3 (Turno):** sección «Gastos del turno» en `Turno.vue` con tabla de movimientos del turno, distintivo de método y tipo, líneas de auditoría para movimientos corregidos («Antes: método · Bs X — Motivo: …») y anulados («Motivo: …»), y botones «Corregir» y «Anular» conectados a los modales. Conexión reactiva con la gaveta y recarga al guardar.
  - **Fase 4 (Control diario y reporte):** columna de acciones en la pestaña «Gastos e Insumos» de `RetroactiveSales.vue` con «Corregir» y «Anular» en cajas abiertas, aviso «Reabre la caja para corregir» en cajas cerradas, líneas de auditoría para movimientos modificados y conexión a los modales. En `CashSessionReport.vue`, sección nueva «MOVIMIENTOS CORREGIDOS / ANULADOS» detallando hora, tipo, concepto, método/monto anterior y actual, usuario responsable y motivo de corrección o anulación.
  - **Fase 5 (Verificación y cierre):** 20 pruebas E2E en Playwright (`corregir-gastos.spec.js`) en verde a través de todos los viewports (Portátil de caja, Pixel 7, iPhone 14, iPhone SE), 416 pruebas de backend (PHPUnit) en verde y 44 pruebas unitarias de frontend en verde. Solución de importación de `watch` en Turno y flexibilidad de tipos para `sessionId`.
- **Descuento manual en caja y en Control diario** ([plan](docs/PLAN_DESCUENTOS_CAJA.md)):
  - **Permiso por persona *Descuentos en caja*** (`pos_discounts`). Se da en Usuarios → editar persona y es el primero que también puede recibir un cajero (en su ficha solo aparece este). Por defecto solo lo tiene el Super Admin, y la lista de usuarios muestra la insignia «Descuentos» a quien lo tiene. Dar o quitar el permiso queda en la auditoría.
  - **Topes:** cajero hasta el 20 % del subtotal; administrador y Super Admin hasta el 100 % (cortesía). Escritos solo en `DiscountPolicy`; `/login` y `/me` los envían al POS en `discount_policy`.
  - **Motivo obligatorio** de 10 a 255 caracteres, en la columna nueva `sales.discount_reason` (no en `notes`, que va a cocina).
  - **Ventas del POS:** el servidor nunca rechaza una venta ya cobrada. Guarda el descuento y deja `sale_discount_applied`; si está fuera de regla (sin permiso, sobre el tope, sin motivo o en PedidosYa), `sale_discount_violation`.
  - **Al cobrar:** el enlace «Aplicar descuento» del modal abre una fila con el monto (y su máximo), el motivo y «Quitar». El total, los billetes sugeridos, el vuelto y el pago dividido se recalculan sobre el total con descuento, y Cobrar se habilita recién con el motivo. No aparece en PedidosYa ni sin el permiso, y cada cobro empieza sin descuento. Cabe en la portátil de caja (1366 × 611 sin desplazar; al 125 %, monto, motivo, vuelto y Cobrar a la vista).
  - La pantalla de venta cobrada muestra subtotal, descuento, total y motivo.
  - **Cortesía:** al 100 % el modal oculta el pago y el vuelto y el botón dice «Registrar cortesía». Se registra con un pago en efectivo de Bs 0. Descuenta inventario, no suma puntos y no cambia el efectivo esperado.
  - **Control diario:** el editor de ventas tiene el mismo descuento que el cobro: monto con su máximo y %, y el motivo obligatorio debajo de los totales. Sin el permiso, el campo está deshabilitado y el «Total a cobrar» no puede bajar del subtotal. En el servidor, un descuento nuevo o con otro monto exige el permiso, respeta el tope y pide el motivo (aquí sí se rechaza); editar otros datos de una venta con descuento no exige el permiso. La cortesía se registra con un pago de Bs 0.
  - **Dónde se ve:** resumen de Turno («Descuentos»), revisión del cierre de caja («Descuentos del turno», informativo), reporte de caja (sección DESCUENTOS con hora, ticket, cajero, %, lo cobrado y motivo), Control diario (total del día y, en cada venta, el descuento con su motivo y «de Bs …») y el detalle de la venta (motivo). Las cortesías llevan la insignia «🎁 Cortesía» en vez del método de pago.
  - **Arqueo y reporte:** `total_discounts` suma solo descuentos positivos (un importe negativo era un recargo viejo); se agregan `discount_count` y `courtesy_count`, y el reporte de caja trae la lista `discounts` con cajero, % y motivo.

### Corregido
- **Importación por carpeta de PedidosYa: toda la información se carga** ([guía](docs/GUIA_IMPORTACION_CARPETAS_PEDIDOSYA.md)):
  - **Una semana borrada se puede volver a importar:** borrarla libera su número de liquidación (antes, la reimportación fallaba con un error de la base); las semanas borradas antes de esta corrección también lo liberan. Las ventas que ya había creado se vinculan, no se duplican.
  - **Ningún archivo pasa en silencio:** se avisa si falta o no se lee la factura, el Excel o el CSV, y se muestran todos los avisos de cada semana (no solo el primero), también en el resultado. Antes, si el CSV fallaba, la semana se guardaba sin ventas y sin aviso.
  - **Pedidos completos:** si el CSV no trae una semana o le faltan pedidos, se completan con los del Excel (sin artículos) y se dice cuántos y de qué fechas. Se pueden poner varios CSV en la carpeta (uno por mes), para las semanas que cruzan de mes. El origen de los pedidos (`csv`, `xls` o `mixed`) refleja lo que realmente se guardó.
  - **Completar después:** una semana ya importada a la que le faltan pedidos, el detalle de sus pedidos o sus ventas aparece como «Completar» y se completa sin duplicar nada. Incluye las semanas que guardó la primera versión (sin pedidos, o con «Pedido PedidosYa #…» como artículo).
  - **Una semana que ya estaba importada se informa como «Saltada»**, no como «Importada correctamente». Una semana cargada a mano con los mismos montos recibe también su fecha de pago si le faltaba.
  - **Equivalencias:** una opción que no está en el menú se puede anotar en la venta sin descontar inventario, como en «Crear o vincular ventas»; el aviso explica que tras guardar puede aparecer una segunda tanda (las opciones de esos productos).
  - **Controles:** la factura descuenta el descuento de PedidosYa a clientes, no el reintegro por pedidos cancelados (la semana del 14 al 20/09 daba un aviso falso). El Excel se lee por la referencia de cada celda (una celda vacía omitida ya no corre las columnas). El número de liquidación solo admite dígitos (también es el nombre de la carpeta de archivos). Si una semana falla, no deja archivos sueltos.
  - **Pruebas sin la carpeta real:** `PedidosYaFolderImportTest` (12 pruebas, texto anonimizado y Excel armado en la prueba) y `importar-carpeta-avisos.spec.js`; la prueba 3 de `importar-carpeta-pedidosya.spec.js` ya no lee `/Users/jolopez/...`. Se quitó un NIT real de una prueba.
  - **Contraste:** las etiquetas PDF, XLS y CSV de la vista previa en tema oscuro (2,5:1).
- **Contraste AA en las listas de gastos:** en Turno, las pastillas «Egreso» (4,1:1) e «Ingreso» quedaban bajo el mínimo en tema claro; en Control diario (tema oscuro), la insignia «📱 QR / Banco» (1,8:1) y el botón «✏️ Corregir» (1,9:1). Ahora todas pasan 4,5:1.
- **Visualización de métodos de pago en Control diario y Turno:** cuando una comanda se cobraba con pagos mixtos/divididos (ej. QR + Efectivo), la tabla de Control diario mostraba únicamente la pastilla del primer método (`payments[0]`), ignorando los demás pagos y ocultando el efectivo o QR restante. Ahora se muestran las pastillas de cada método con su importe (`📱 QR Bs 40.00` `💵 Efectivo Bs 5.00`), y en Turno se desglosa el texto del pago mixto con sus importes.

## [2026-10-31]

### Agregado
- `deploy/vps/08-configurar-correo.sh`: activa el envío por Resend en el VPS. Pide la clave sin mostrarla, ajusta el `.env`, corre `config:cache`, recarga PHP y envía un correo de prueba.
- **Caja cerrada que cambió después del cierre:** si después se edita, anula o sincroniza tarde una venta, el ticket de cierre y Control diario lo avisan.
  - Muestran el cierre guardado tal cual se firmó y, aparte, el efectivo teórico y la diferencia con las cifras de hoy.
  - El ticket lista los cambios auditados: fecha, quién y qué ticket.
  - Antes, el ticket sumaba las ventas de hoy pero mostraba el teórico del cierre, y las filas no cuadraban.
- **Permisos por persona** ([plan](docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md), Fase 1): en Usuarios → editar persona, el Super Admin da a un administrador *Finanzas* (Ver o Editar), *Ventas de días pasados* (Control diario) y *Corregir cajas cerradas*.
  - Es una excepción encima del rol que nunca supera su techo: un cajero o la cocina no pueden recibirlos.
  - La lista de usuarios muestra con insignias quién tiene permisos distintos a los de su rol.
  - Todo cambio queda en la auditoría (`user_permissions_changed`).
  - Los administradores siguen con *Ventas de días pasados* por defecto; *Finanzas* y *Corregir cajas* empiezan sin acceso.
- **Super Admin protegido:** nombrar o quitar un Super Admin pide la contraseña de quien lo hace y avisa que tendrá todo.
  - Nadie puede cambiar su propio rol ni eliminar su cuenta, y no se puede quitar ni eliminar al último Super Admin.
  - Al cambiar el rol de una persona se cierran sus sesiones y queda `role_changed` en la auditoría.
- Control diario ofrece como vendedores a cajeros y administradores (`GET /users/sellers`); antes un admin no veía la lista y la venta quedaba a su nombre.
- **Finanzas para administradores** (Fase 2): un administrador con *Finanzas: Ver* consulta resultados, gastos, gastos fijos, costos y PedidosYa con el aviso «Solo lectura» y sin botones de registro. Con *Editar*, trabaja como el dueño.
  - En la API, las consultas de `finance/*` piden `finance,read` y los registros `finance,edit`.
  - Usuarios, auditoría y respaldos siguen siendo solo del Super Admin.
- **Cargar días pasados en Control diario** (Fase 3): para el histórico de antes del sistema y para un día en que el sistema falle.
  - **Venta histórica:** una venta anterior al inicio del sistema no descuenta stock ni suma puntos, y no necesita caja. Por defecto, el inicio es la primera venta del POS; el Super Admin puede cambiarlo en Control diario. Anularla o editarla tampoco quita puntos que nunca dio.
  - **Stock con conteos posteriores:** una venta no descuenta los insumos que se contaron o cargaron después de su fecha, porque ese conteo ya la reflejó. El editor avisa cuántos.
  - **N.º de comanda en papel:** se guarda con la venta, avisa si ya está cargada ese día y sugiere el siguiente número.
  - **Caja de un día pasado:** se abre a nombre del cajero que vendió. Sus egresos quedan con la fecha de ese día (`occurred_at`), y Finanzas los cuenta en su mes, no en el de carga.
  - **Ventas sin sincronizar:** Control diario avisa si el dispositivo tiene ventas de ese día pendientes, para no cargarlas dos veces.
  - **Vendedor:** el editor dice «Vendida por», con «Yo (quien registra)» por defecto, y conserva el método de pago entre comandas.
- **Corregir cajas cerradas** (Fase 4): con el permiso *Corregir cajas cerradas*, en Control diario se **reabre** una caja con un motivo, se registra lo que faltó (egresos con la fecha de ese día, tickets) y se **vuelve a cerrar** con la misma pantalla de arqueo.
  - El conteo parte del cierre anterior y el nuevo cierre pide el motivo de la corrección.
  - El cierre anterior queda en el historial (`cash_session_closings`) y el ticket muestra «Caja rectificada» con los motivos. El cierre conserva la fecha y hora original.
  - El arqueo nunca se recalcula solo. El POS no ve una caja reabierta de otro día y esta no recibe ventas nuevas.
  - Si la corrección cambió el «fondo para mañana», el ticket avisa con qué fondo abrió la caja siguiente.
  - Auditoría: `cash_session_reopened` y `cash_session_reclosed`, con los valores antes y después.

### Corregido
- **Arma tu Bowl (Frutas opcionales):** el modal de opciones exigía seleccionar al menos 1 fruta de forma forzada, impidiendo vender solo helado sin frutas ni toppings. Ahora `ModifierModal` respeta el `min_selections` real configurado en cada grupo de opciones (el grupo «Frutas» tiene mínimo 0), permitiendo agregar y cobrar «Arma tu Bowl» sin frutas ni toppings.
- **Cajas abiertas con fecha pasada (Control diario):** al cerrarlas quedaban con la fecha del cierre real, días o semanas después. Hasta ese día absorbían cualquier venta cargada o editada para esos días.
  - Ahora una venta solo entra a una caja abierta el mismo día, como ya pasaba con las cajas abiertas.
  - En el VPS había dos cajas así: #9 (1 al 21 de octubre) y #10 (2 al 24 de octubre). Todavía no tenían ventas de otros días, así que no hubo que reparar datos.
- **Autoservicio (producto por peso) en el ticket del POS:**
  - Un segundo bowl con el mismo destino y los mismos toppings se sumaba al primero: quedaba cantidad 2 al precio del primero y se perdían los gramos del segundo. Con 151 g y 200 g se cobraban Bs 72 en vez de Bs 84, y el inventario descontaba 302 g en vez de 351 g. Ahora cada bowl es su propia línea.
  - El «+» de un bowl duplicaba el precio y seguía mostrando los mismos gramos. Ahora no hay «+»: **Quitar** saca ese bowl y **Editar** corrige su peso sin tocar los demás.
  - El peso ya no viene escrito: el campo empieza vacío en cada bowl, recibe el foco y sin gramos no se puede agregar. Antes traía 350 g, o el peso del bowl anterior.
  - En producción no había ventas afectadas (consulta del 2026-10-31: ningún producto por peso con cantidad mayor que 1).
- **Destino de las líneas del ticket:** al cambiar una línea a «Para llevar», el mismo producto agregado después «En mesa» se sumaba a ella y salía para llevar. Ahora cada línea tiene un id fijo y solo se juntan líneas con la configuración actual idéntica (`src/utils/cartLines.js`, con pruebas en `cartLines.test.mjs`).

## [2026-10-30]

### Agregado
- **Recuperar la contraseña con un código por correo** ([plan](docs/PLAN_RECUPERAR_CONTRASENA.md)): «¿Olvidaste tu contraseña?» en el login
  lleva a 3 pasos (correo → código de 6 dígitos → contraseña nueva). El código vence en 15 minutos, admite 5 intentos y sirve una vez; la
  respuesta no revela si el correo existe; hay límite de pedidos e intentos; al cambiarla se cierran todas las sesiones del usuario, llega un
  aviso por correo y queda en Auditoría. Envío por la API HTTPS de Resend (`MAIL_MAILER=resend-api`, sin paquetes nuevos) y
  `php artisan ohana:probar-correo` para comprobar la configuración. Migración nueva `password_reset_codes`.

### Corregido
- **Inventario: el modal de insumo no se podía guardar** ([plan](docs/PLAN_REDISENO_INVENTARIO_PORTATIL.md)): medía 1026 px y quedaba cortado
  arriba y abajo en todas las pantallas (incluso 1440 × 900); «Guardar» era inalcanzable. Ahora usa `FinanceModal` en tres columnas
  (Insumo, Compra, Reposición) y entra completo en la portátil (558 px a 1366 × 611); a 125 % solo desplaza el cuerpo, con cabecera y pie fijos.
  Foco en Nombre, Enter guarda, errores junto a su campo y sin doble envío.
- **Inventario: Proveedores se cortaba** 13 px a 100 % y 74 px a 125 %.
- **Inventario: los errores de Categorías aparecían en el formulario de insumo** (compartían el mismo estado).
- **Inventario: la confirmación de borrado se cerraba antes de terminar** y no decía qué se borraba.
- **Inventario: texto secundario y colores fuera del sistema**: `--ink-400` (no existe) dejaba en negrita oscura la línea «unidad · proveedor»;
  las insignias usaban colores fijos sin modo oscuro.
- **Registrar compra (Inventario y Finanzas): valores por defecto engañosos**: la línea empezaba en «Otro gasto» y la categoría en la
  primera de la lista, así que una compra guardada sin mirar no subía stock ni costo y caía en una categoría al azar. Ahora la línea empieza
  en «Elige un insumo…» (insumos y otros gastos en grupos separados) y la categoría en «Elige una categoría…»; al guardar sin elegir, el
  aviso dice qué falta. «Se compra en» y el cálculo por unidad solo aparecen con un insumo elegido. Desde la fila de un insumo la línea
  sigue llegando con ese insumo.
- **Ventanas de Inventario y Finanzas con teclado** ([plan, sección 13](docs/PLAN_REDISENO_INVENTARIO_PORTATIL.md)): al abrir, el foco quedaba
  en el botón de atrás (o se perdía si se abría desde «Más») y Tab recorría la página tapada. Ahora entra a la ventana, al campo con el que
  se empieza («Insumo» en Registrar compra, «Cantidad» desde la fila, el primer «Contado» en el conteo, «Nombre» en Categorías y Proveedores);
  Tab no sale de ella y al cerrar el foco vuelve al botón que la abrió. En pantallas táctiles no se enfoca un campo (no abre el teclado).
- **Inventario: costo con punto junto a cantidades con coma** en la misma fila: ahora «Bs 9,40», como en Finanzas.
- **Inventario: un nombre largo ocupaba tres líneas**: máximo dos, con el nombre completo al pasar el puntero.
- **Finanzas: «Registrar gasto» y «Agregar gasto fijo» elegían la categoría solos** ([plan v2, Fase 1](docs/PLAN_MEJORA_UI_FINANZAS_PORTATIL.md)):
  empezaban en «Helado y açaí» y «Salarios y personal», así que un gasto guardado sin mirar (gas, alquiler…) caía en otra sección del Estado de
  resultados. Ahora empiezan en «Elige una categoría…»; el proveedor conocido la sugiere (también si su lista llega después de escribirlo).
- **Finanzas: avisos del formulario fuera de la vista al 125 %**: ahora van junto a cada campo, el foco salta al primero con error y se borran
  al corregirlo. La ventana se llama «Registrar gasto», como su botón (decía «compra o gasto», junto a otro botón «Registrar compra»).
- **Finanzas: cabecera del Resumen al 125 %** ([plan v2, Fase 2](docs/PLAN_MEJORA_UI_FINANZAS_PORTATIL.md)): en pantallas con `max-height: 520px`
  la cabecera pasaba a 3 pisos verticales apilados midiendo hasta 281 px y empujando el Estado de resultados fuera de la vista
  (a y = 426-500 px de 489 px). Ahora mantiene un layout horizontal de 2 columnas con avisos en versión compacta (el detalle
  completo en `title`), reduciendo la altura a 116 px y permitiendo que el Estado de resultados y el Punto de equilibrio inicien en y = 261 px
  con 5 filas de cifras operativas visibles en el primer pantallazo sin scroll.
- **Compras sugeridas en la portátil de caja** ([plan v1 Fase 2 / v2 Fase 3](docs/PLAN_MEJORA_UI_FINANZAS_PORTATIL_v1.md)): las 2 tarjetas KPI
  (96 px) y las notas de advertencia consumían hasta 170 px verticales dejando visible solo 1 insumo al 125 %; además, la unidad de medida
  hacía wrap cayendo 35 px debajo del campo numérico. Se reemplazaron por una franja horizontal compacta `.rm-kpis` (38 px) con badges de alerta
  integrados con tooltip nativo, cabecera de proveedor en una línea horizontal (~32 px) y cantidades sin quiebre de línea (`.rm-qty { flex-wrap: nowrap }`).
  Emojis ⚠ ℹ sustituidos por iconos SVG de trazo; instrucciones visibles sin necesidad de hover; texto del grupo sin proveedor aclarado.
- **Informe de inventario en la portátil de caja** ([plan v1 Fase 3](docs/PLAN_MEJORA_UI_FINANZAS_PORTATIL_v1.md)): el modal apilaba 6 tablas
  analíticas en más de 1.300 px verticales, obligando a un desplazamiento masivo en pantallas de 489 px de alto útil. Ahora cuenta con una barra
  superior de pestañas/chips (`1. Indicadores del mes`, `2. Cobertura y Quiebres`, `3. Mermas y Conteos`, `4. Valorización`, `Ver todo`), reduciendo
  la altura visible por sección a < 320 px sin scroll forzado y sustituyendo `.fin-table.stack` por formato tabular estructurado `.iim-table` sin
  apilar filas como tarjetas. La impresión mantiene el reporte íntegro con todas las secciones.
- **Cargar recetas base en la portátil de caja** ([plan v1 Fase 4](docs/PLAN_MEJORA_UI_FINANZAS_PORTATIL_v1.md)): el asistente apilaba más de
  3.300 px de formulario continuo con tres etapas mezcladas. Ahora se organiza en un stepper de 3 pasos (`1. Costo de componentes`,
  `2. Recetas de productos`, `3. Ajustes de opciones`) navegables con botones `← Anterior`, `Siguiente →` o clic directo en los chips superiores.
  Incluye herramientas de selección rápida (`Todos`, `Solo sin receta/ajuste`, `Ninguno`) y tabla compacta de componentes; cada paso se
  muestra de forma aislada sin mezclar las tres secciones. El botón «Aplicar» solo aparece en el paso 3, tras revisar costos, productos y opciones.
- **Resumen de Finanzas en dos columnas estables al 125 % DPI** ([plan v1 Fase 5](docs/PLAN_MEJORA_UI_FINANZAS_PORTATIL_v1.md)): en pantallas
  con escala DPI o anchos reducidos, la cuadrícula `.sum-grid` podía quebrar sus columnas y apilar el Punto de Equilibrio y la Reposición al
  fondo a más de 1.400 px de scroll. Con flex proporcional estabilizado (`.sum-main: 1 1 480px`, `.sum-side: 0 0 280px` y versión ajustada a
  `max-height: 520px`), el Estado de Resultados a la izquierda y el Punto de Equilibrio a la derecha se conservan siempre lado a lado sin colapsar,
  con el botón «Ver compras sugeridas» visible de inmediato.
- **Correcciones de la auditoría de accesibilidad (portátil):**
  - Contraste de los números del stepper en Cargar recetas base: el paso activo pasa a texto blanco sobre morado (antes 2,83 por `--on-accent`, que es oscuro) y los demás a `--ink-900`.
  - Pestañas del informe de inventario y del stepper de recetas: se eliminó `role="tablist"/"tab"` que exigía navegación con flechas no implementada; ahora son botones simples con `aria-current`.
  - Cabecera del Resumen al 125 %: todas las fuentes subidas de 10–11 px a ≥ 12 px, botones de acción de 24 px a ≥ 28 px de alto.
  - Descripciones de las tarjetas de Finanzas: visible de nuevo al 125 % (la regla `display: none` afectaba todas las pestañas, no solo el encabezado).
- **Finanzas con teclado**: «Registrar gasto» empieza en Monto, «Gasto fijo» en Concepto, «Categoría» y «+ Nueva categoría…» en Nombre.

### Cambiado
- **Login en producción**: ya no aparece escrita la cuenta de ejemplo (`admin@example.com` / `password`); solo en desarrollo.
- **Inventario: Categorías, Proveedores, confirmación y aviso con `FinanceModal`**: lista con herramientas y formulario al lado, la acción
  principal en el pie, Escape y ✕ en todos; el borrado nombra el insumo, la categoría o el proveedor, y el foco queda en «Cancelar».
- **`FinanceModal`**: prop `size` (`''`, `'wide'`, `'xl'` de 920 px), compatible con `wide`.
- **Inventario: cabecera en una línea**: acciones del turno a la vista, Proveedores, Categorías y Mermas del mes en el menú «Más»
  (teclado completo), íconos de trazo en vez de emojis y «Nuevo insumo» como único botón naranja. La tabla empieza a ≤ 150 px (antes 281).
- **Inventario: tabla legible en la portátil**: filas de ≈ 56 px (antes 85 y 213 a 125 %), seis herramientas de 32 px en una línea con
  el nombre del insumo en su etiqueta, nivel de stock frente al punto de reorden y franja de estado en vez de teñir la fila, tipo legible
  («No perecedero»), costo y promedio en una celda. Se ven 7 filas a 100 % y 5 a 125 % (antes 2 y 0). En teléfono la tabla se desliza con
  el nombre fijo (antes se recortaba).

## [2026-10-23]

### Cambiado
- **Control diario: comanda con el configurador de Ventas** ([plan](docs/PLAN_VENTAS_MULTI_ITEM_Y_TOPPINGS.md)): los productos con opciones
  o por peso abren `ModifierModal` (el mismo de Ventas); cada fila se edita con el lápiz, y si al editar queda igual a otra se juntan.
  Las filas siguen el ticket de Ventas (píldoras de opciones, Baloo 2 en importes); «Precio y nota» queda plegado.
- **Control diario en la portátil de caja**: datos de la venta en una línea (el origen siempre visible), pestañas de categoría como en Ventas,
  catálogo de dos filas y barra fija con el total y **Registrar venta**. A 1366 × 611 y a 125 % el catálogo se ve sin desplazar.
  Motivos rápidos y, en modo semi-lote, el motivo se conserva entre ventas del mismo talonario.
- **Marca en el editor de ventas**: el token `--primary`, que no existía, se mapea a la paleta açaí (antes caía al violeta de Tailwind,
  sin modo oscuro y con contraste insuficiente). Afecta también al editor en ventana de Turno.

### Corregido
- **Ventas y Control diario: «Toppings» a Bs 0.00**: un producto sin precio propio ya no se puede agregar sin al menos un extra con precio.
- **Control diario en modo oscuro**: los campos «Descuento» y «Total a cobrar» tenían fondo blanco con texto claro.

### Retirado
- Comando `php artisan catalog:sync-toppings` y su prueba: los toppings se venden con el producto «Toppings» y su grupo de opciones.

## [2026-10-21]

### Agregado
- **Control diario: comanda con varios ítems**: el catálogo ya no se cierra al agregar un producto; se apilan ítems con un clic,
  el mismo ítem sin ajustes suma cantidad, las filas quedan compactas (se abren solo si falta elegir una opción obligatoria),
  hay resumen de comanda y total dentro del catálogo y el destello marca lo recién agregado. Afecta `AdminSaleEditor.vue`
  (Control diario y editar venta). Prueba: `e2e/control-diario-apilado.spec.js`.
- **Toppings sueltos**: comando `php artisan catalog:sync-toppings` (simulación; con `--apply` escribe) que crea la categoría
  «Toppings» y un producto por topping con su precio y su consumo de inventario, para venderlos sin elegir envase en Ventas y Control diario.

### Corregido
- **Control Diario / Arqueo: saldo de gaveta en modal de gastos (`CashExpenseModal`)**:
  - `CashExpenseModal` aceptaba `sessionId` pero ignoraba el saldo de dicha sesión, calculando siempre `currentCash` a través del turno activo global del store (`turnoStore.currentCashInDrawer`).
  - Si se visualizaba una sesión en Control Diario / Ventas Históricas con un saldo distinto al turno activo (por ejemplo, Bs 215.00 frente a Bs 295.00), el modal mostraba "En la gaveta ahora" y calculaba sobregiros (`isOverdrawn`) con los fondos de otra caja.
  - Se añade la propiedad `expectedCash` a `CashExpenseModal.vue` y `RetroactiveSales.vue` la abastece con `expectedPhysicalCash`, manteniendo la compatibilidad con `Turno.vue`.

## [2026-10-20] — versión portátil de caja (Cobro, Cierre, Finanzas, Permisos y Layout)

### Agregado
- **Scroll del sistema sin franja blanca (PLAN_CORRECCION_SCROLL_SISTEMA.md)**:
  - **Corrección:** al llegar al final de una página larga (Finanzas → Costos y margen; también Caja con historial largo) la ventana se desplazaba, el menú lateral se despegaba hacia arriba y quedaba una franja blanca. Causa: las etiquetas `.sr-only` de los encabezados de tabla se ubicaban respecto de la ventana. El contenido ahora las contiene (`position: relative`), no encadena la rueda a la ventana y, con el menú montado, la ventana queda fija (el login sigue desplazándose; al imprimir no se recorta).
  - **Corrección:** Finanzas, Caja e Inventario se encogían al alto de la pantalla con mucho contenido (fondo y margen cortados; en Finanzas la navegación pegada se perdía tras ~490 px). Auditoría y «Sin acceso» tenían 41 px de scroll vacío. El menú lateral en celular ya no queda bajo la barra del navegador.
  - «Clasificar egresos» / «Revisar gastos fijos» dejan la tarjeta visible debajo de las pestañas. Prueba `layout-scroll.spec.js` (15 pantallas × 3 tamaños).
- **Finanzas en la portátil de caja (PLAN_REDISENO_FINANZAS_PORTATIL.md)**:
  - **Corrección:** en pantallas de laptop la barra de pestañas se aplastaba a 1 px y desaparecía (el contenedor flex la encogía); ahora no se encoge.
  - Cabecera y pestañas pegadas arriba al desplazarse (solo las pestañas al 125 %); cabecera de ~48 px y pestañas de 32 px.
  - Resumen: el resultado del mes pasa de 471 a 165 px de alto (aviso de datos faltantes a la derecha, con todas sus acciones); la página baja de 1.435 a 912 px. Estado de resultados y Punto de equilibrio compactos y lado a lado.
  - Categorías en 2 columnas × 2 filas; Compras y gastos, Costos y margen y Gastos fijos con filas y barras más cortas (≥ 7 productos visibles en Costos); líneas de «Registrar compra» en dos columnas.
  - Todo bajo 1366 px de ancho o 800 px de alto; monitores grandes y celular sin cambios. Prueba E2E `finanzas-portatil.spec.js` (27) con API simulada (`e2e/fixtures/finanzas-api.js`).
- **Cerrar caja: conteo directo (PLAN_CIERRE_CAJA_CONTEO_DIRECTO.md)**:
  - Paso «Conteo» en dos columnas (billetes / monedas) con las 9 denominaciones, casillas numéricas saneadas (sin signos, decimales ni letras; máx. 4 cifras) y el total contado siempre visible en el pie. Entra sin scroll en 1366 × 611, 1280 × 720 y al 125 % (1093 × 489).
  - Teclado completo: foco inicial en Bs 200, Enter/↓ siguiente, Shift+Enter/↑ anterior, Ctrl+Enter avanza; − / + salen del orden de Tab. Se elimina la opción de escribir solo el total.
  - Paso «Resultado» compacto en 3 tarjetas: foco en el motivo si hay faltante, fondo para mañana con atajos («Como al abrir», «Todo», «Nada») y Enter no cierra por accidente. Monto de apertura sin signos ni exponente.
  - Indicador «Turno abierto» en el menú que lleva directo al cierre.
  - Servidor: si llega `bill_breakdown`, se exigen denominaciones válidas, cantidades enteras 0–9999 y que la suma coincida con el efectivo contado (422 con mensaje claro). Tests nuevos en `CashRegisterSessionTest`.
- **Cobro dividido: los montos se completan solos (PLAN_COBRO_DIVIDIDO_AUTOCOMPLETAR.md)**:
  - Al escribir el monto de un método, los demás se reacomodan para sumar siempre el total (con 2 y con 3 métodos; con 3, la línea menos reciente absorbe la diferencia y nunca se pisa lo último que escribió el cajero). Ya no aparece "faltan Bs X en efectivo" ni un vuelto falso cuando el cliente paga parte por QR o tarjeta.
  - El efectivo a cobrar es ahora `min(línea de efectivo, total − QR/tarjeta)`: lo usan "Justo", los billetes sugeridos, el vuelto y la validación. Las líneas no pueden pasar del total ni quedar negativas; montos en centavos enteros (sin errores de decimales).
  - Con pago dividido la etiqueta dice "Recibe / en efectivo". Sin cambios de alto: sigue entrando en 1366 × 611 y en escala 125 % (también con 3 métodos).
  - Lógica en `frontend/src/utils/splitPayments.js` (11 pruebas: `npm run test:unit`) y 9 pruebas E2E nuevas en `checkout-portatil.spec.js` (22 en total).
- **Pantalla de Cobro ajustada a la portátil de caja (PLAN_PANTALLA_COBRO_PORTATIL.md)**:
  - Corrige el desborde horizontal del pago dividido (el selector de método ocupaba toda la columna por conflicto de especificidad CSS con `.co-input`).
  - Modal más ancho (620 px) y compacto: "Recibe" en una línea, "Dividir el pago" junto al selector de método, filas y pie más bajos. Entra completo, sin scroll, en 1366 × 611 (efectivo con vuelto, QR, tarjeta, dividido de 2 y 3 pagos, PedidosYa, claro y oscuro).
  - Plan B para ventanas bajas (p. ej. Windows al 125 %): se compacta más y, si aun así no cabe, solo se desplaza el cuerpo; el pie con "Cobrar" no se mueve.
  - Nueva suite `frontend/e2e/checkout-portatil.spec.js` (13 pruebas). Sin cambios de lógica de cobro.
  - Pantalla Roles y permisos: se reemplazaron variables CSS inexistentes por las del tema, filas más bajas (cabe Cajero/Cocina en 1366 × 611), frase resumen completa y etiqueta "Gestionar" en Pedidos y comandas.
- **Nueva pantalla de Roles y Permisos para la portátil de caja (PLAN_PANTALLA_PERMISOS.md)**:
  - Rediseño ergonómico y compacto de **Usuarios → Roles y permisos**, adaptado a la pantalla de 15,6" (1366 × 768 / 1366 × 611 de viewport útil):
    - **Un rol a la vez**: Cocina, Cajero o Administrador; Super Admin fijo informativo.
    - **Frase resumen dinámica**: frase en tipografía Baloo 2 que resume las facultades del rol según el borrador activo.
    - **Filas de acción directa**: 10 módulos operativos (se omiten `users` y `audit` exclusivos del Super Admin) con altura compacta de 38 px y selector segmentado con solo los niveles permitidos (`ALLOWED_LEVELS`) sin exceder el techo.
    - **Vista previa en vivo**: réplica visual del menú lateral compacto que verá el rol, indicando "Abre aquí", "Solo ver", pantallas no visibles y tarjeta "Acceso no disponible".
    - **Sin scroll**: cero scroll horizontal en cualquier resolución (1366, 1024, 390 px) y cero scroll vertical en 1366 × 611 para el Administrador con cambios pendientes y aviso visible.
    - **Guardado diferencial**: el botón "Guardar cambios" en el encabezado envía únicamente las filas modificadas (`changedRows`).
  - **Backend**:
    - `PermissionService`: constante `ALLOWED_LEVELS`, método `levelsFor(string $role, string $module)`, y normalización automática de niveles no válidos en `level()`.
    - `RolePermissionRequest`: validación estricta de estructura array y validación de niveles contra `levelsFor()` (error 422 ante niveles no soportados).
    - `RolePermissionController@index`: entrega el mapa `levels` de niveles permitidos por rol y módulo.
    - `CashRegisterSessionController@ensureCanAccess`: granularidad `read` para `show`, `report` y `movements`, y `edit` para `close` y `recordMovement` (permite auditar turnos ajenos sin cerrarlos).
    - Migración `2026_10_16_000001_normalize_role_permission_levels.php`: normaliza niveles incompatibles en la base de datos de forma idempotente.
  - **Pruebas y accesibilidad**:
    - Suite de pruebas E2E `frontend/e2e/permisos-portatil.spec.js` con el proyecto "Portátil de caja" (6 tests automáticos en verde: scrolls, interactividad, diffs, restablecimiento a defaults, error 500 y vista previa).
    - Auditoría de contraste WCAG 2.1 AA (`scripts/audit-contrast.cjs`): 0 textos bajo el estándar en tema claro y oscuro (`09b_roles_permisos issues: 0`).
    - 344 tests de PHPUnit en verde con 2.134 aserciones.
- **Módulo de Usuarios, Roles y Permisos: Modelo de techos por rol y matriz dinámica (Opción C v2)**:
  - Implementación integral del modelo: la matriz de la base de datos y la interfaz en Usuarios otorgan y recortan permisos reales dentro de los techos inamovibles fijados en código (`PermissionService::CEILINGS`).
  - **Topes de seguridad inamovibles**:
    - `super_admin`: edición total incondicional en todos los módulos (no editable desde la interfaz ni en requests).
    - Techos declarados por rol: `cashier` no puede superar `none` en finanzas, usuarios, auditoría, catálogo, inventario ni anulaciones; `kitchen` restringido con techo `edit` en operaciones de delivery/KDS y `none` en todo lo demás.
    - Falla cerrado: rutas o llamadas con módulos o niveles desconocidos lanzan `InvalidArgumentException`.
  - **Backend**:
    - `PermissionService`: registrado como `scoped`, con cálculo de `effectiveFor(User)` en una sola consulta por rol y caché de ciclo de vida.
    - Separación por verbo HTTP en `api.php`: lectura (`read`) y escritura (`edit`) desacopladas en Ventas POS (`pos`), Caja y Mermas (`register_self`), y Cola de Pedidos/KDS (`delivery_ops`); `GET /customers` protegido con `perm:crm_read,read`.
    - `CashRegisterSessionController`: conectado al permiso `register_others` (lectura para consultar otras cajas, edición para operar sobre ellas).
    - `RolePermissionRequest`: valida contra techos y rechaza pares `{role, module}` repetidos con 422.
    - `RolePermissionController`: guardado atómico en lote mediante `DB::transaction`; `index()` expone `ceilings`, `defaults`, `modules` y `restrictions`.
    - `AuthController`: respuestas de `/login` y `/me` exponen `effective_permissions`.
    - `CheckModulePermission`: retorna `reason: 'permission_denied'` en el JSON de error 403.
    - Migración `2026_10_15_000001_update_role_permissions_to_match_reality.php`: sincroniza la base de datos mediante `DB::table('role_permissions')->upsert()` con valores literales.
    - Suite de pruebas: `PermissionServiceTest` con 18 tests automatizados cubriendo techos, fallos cerrados, unicidad de rutas, migraciones, atomismo y separaciones de verbos (340 tests en verde).
  - **Frontend**:
    - Vista `SinAcceso.vue`: pantalla de bloqueo con opción de cerrar sesión para usuarios sin pantallas permitidas.
    - Router `router/index.js`: guard `beforeEach` con función `landingFor()` que previene bucles infinitos de redirección.
    - Store `auth.js`: consume `effective_permissions` del backend sin duplicar lógica de fallback; refresco automático al recuperar el foco de ventana o ante respuestas 403 `permission_denied`.
    - Menú `AppShell.vue`: visibilidad de Control Diario alineada a `pos,edit`; Delivery sin excepción cableada para Cocina.
    - Pantalla `AdminUsers.vue`: celdas dinámicas según los techos del rol, nuevas etiquetas de módulo y confirmación preventiva si un rol queda sin pantallas operativas.
- **Finanzas: Corrección integral de costos, margen y visualización**:
  - Corrección de distorsión en costos por receta: los componentes con composición parcial de stock (`Mix de frutas`, `Pack toppings base`, etc.) forzaban el costo financiero del producto al valor de los pocos insumos inventariados (bajando el costo del Classic Bowl de Bs 9,20 a Bs 4,11 e inflando el margen al 85,3%).
  - Nueva columna `ingredients.use_manual_cost` (migración `2026_10_14_033606_add_use_manual_cost_to_ingredients_table`): permite alternar entre modo automático (suma de CPP de insumos) y modo manual (costo fijo establecido para costeo financiero, mientras sigue descontando los insumos al vender en el POS).
  - Backend `CompositionService`, `FinanceCostController`: cálculo de `compositionCost()`, soporte de `use_manual_cost` en actualización de componentes y composición, y exposición de ganancia monetaria neta (`profit_local`, `profit_pya`) junto con el porcentaje de margen.
  - Frontend `FinanceCosts.vue`:
    - Visualización de la ganancia neta en moneda nacional (`Ganancia Bs 18,80`, etc.) debajo del margen porcentual tanto para productos como para variantes.
    - Clases semánticas de color para márgenes (`margin-good` >= 60%, `margin-fair` 45-59%, `margin-low` < 45%).
    - Filtro segmentado en la tabla de componentes: "Todos", "Bases y packs" e "Insumos directos".
    - Botón/badge interactivo para alternar entre "Modo: Fijo manual" y "Modo: Automático" directamente en la tabla.
    - Edición directa de costo para componentes en modo manual.
  - Modal `CompositionEditor.vue`: selector con tarjetas interactivas para elegir el modo de costeo financiero (Automático por suma vs Costo fijo manual con campo para ingresar el valor).
  - Corrección del fondo de la aplicación y altura del shell (`style.css`, `AppShell.vue`, `finance.css`): eliminación del fondo `#20112F` que creaba un vacío morado debajo de los componentes en pantallas altas o con zoom, asegurando extensión fluida a 100vh tanto en tema claro como oscuro (0 textos bajo WCAG AA en tema oscuro).
- Movimientos de caja (egresos e ingresos extra) con método de pago; distribución del efectivo al cierre
  y desglose de billetes; Centro de Control Diario para ventas retroactivas. (`7644554`)
- Cada venta queda vinculada a su sesión de caja (`sales.cash_register_session_id`, con backfill que
  reproduce el cálculo anterior). (`35ba606`)
- Recálculo de importes en el servidor (`SalePricingService`) y auditoría de diferencias con el catálogo. (`7005e0e`)
- Rol cocina disponible en el formulario de usuarios. (`2ecb266`)
- Fuentes Inter, Baloo 2 y JetBrains Mono alojadas localmente y precacheadas (funcionan sin internet). (`624702e`)
- `frontend/scripts/audit-contrast.cjs`: auditoría de contraste WCAG por pantalla. (`2802a36`)
- `AGENTS.md`, `CLAUDE.md`, `docs/BITACORA.md` y este archivo para la gestión de cambios.
- Arqueo con PedidosYa aparte: `pedidosya_count` y `pedidosya_total` en las estadísticas de la caja.
- POS: selector de canal **Local / PedidosYa**. En PedidosYa se aplican los precios de plataforma de
  producto, tamaño y toppings, se avisa qué ítems no tienen precio PedidosYa, el cobro es un único pago de
  plataforma (sin efectivo, cambio, cliente ni puntos), se puede anotar el Nº de pedido de la tablet y,
  tras la venta, el POS vuelve a Local. Cambiar de canal con productos en el ticket pide vaciarlo.
- Reporte de cierre, asistente de cierre, Turno y Control Diario muestran "Ventas del local" y un bloque
  PedidosYa aparte (informativo, no se rinde en caja).
- Productos con casilla **"Disponible en PedidosYa"** (`products.available_on_pedidosya`). En modo PedidosYa
  el POS solo muestra los disponibles; la migración deja fuera los productos por peso (Autoservicio). Una
  venta PedidosYa de un producto no disponible (POS con catálogo viejo) se acepta y queda en auditoría.
- En modo PedidosYa, las tarjetas sin precio de plataforma muestran la etiqueta "precio local" antes de
  agregarlas al ticket.
- **Ajuste de precio por línea en pedidos PedidosYa**: en el ticket, "💲 Ajustar" permite cobrar otro precio
  (promo de la plataforma, precio desactualizado) con motivo obligatorio. La línea muestra el precio del
  tarifario tachado y el motivo; el recibo y el detalle de la venta lo indican. Se guarda en
  `sale_items.price_override_reason` y queda en auditoría como `pedidosya_price_override` (no como
  discrepancia). En ventas del local el cajero no puede ajustar precios y un motivo recibido se ignora y se
  audita. En Control Diario, el modo "✏️ Manual Libre" de un pedido PedidosYa acepta el mismo motivo.
- **Tarifario PedidosYa** (Menú → "🛵 Tarifario PedidosYa"): tabla con todos los productos, tamaños y toppings
  con su precio de mostrador y su precio PedidosYa editable, la disponibilidad de cada producto y un resumen
  de cuántos usan el precio local. "Aplicar +X %" (a los vacíos o a todos, redondeo a 0,50) llena una vista
  previa que se revisa antes de guardar. Se guarda en lote con `PUT /api/admin/pricing/pedidosya` (solo
  admin), que nunca toca el precio de mostrador y deja cada cambio en auditoría.
- **Módulo de Finanzas — Fase 1 (Estado de Resultados mensual y gestión contable)** (`docs/PLAN_MODULO_FINANZAS.md`):
  - Nuevo módulo `/finanzas` exclusivo para `super_admin` con protección en router, backend y menú lateral (`AppShell.vue`).
  - Plan de cuentas y categorías (`expense_categories`): precarga de 14 categorías estándar organizadas por sección (`cost_of_sales`, `operating`, `financial`, `taxes`) y grupos contables.
  - Compras y gastos pagados fuera de caja (`finance_expenses`): registro rápido con fecha, categoría, proveedor, monto, método de pago, comprobante y notas.
  - Gastos fijos recurrentes (`recurring_expenses`): plantillas de costos fijos (alquiler, salarios, internet, régimen simplificado) con frecuencia y día estimado, prorrateados en el cálculo mensual.
  - Clasificación de egresos en turnos de caja (`cash_movements.expense_category_id`): selector opcional de categoría contable en `CashExpenseModal.vue` para clasificar egresos en tiempo real.
  - Cálculo automatizado del Estado de Resultados (`FinanceReportService` / `FinanceReportController`):
    - Ventas del local (excluye PedidosYa para evitar duplicación).
    - Costo de Ventas (COGS - Método A: compras registradas del mes + egresos clasificados).
    - Utilidad Bruta y margen bruto (%).
    - Gastos Operativos (OPEX: compras directas + gastos fijos + egresos de caja clasificados y sin clasificar).
    - Utilidad Operativa (EBITDA) y margen operativo (%).
    - Gastos Financieros e Impuestos fijos del régimen simplificado.
    - Utilidad Neta y margen neto (%).
  - Pantalla `Finance.vue` con 4 pestañas interactivas, KPIs visuales, tabla jerárquica del P&L, selector de período mensual y modales de gestión.
  - Fixture anonimizado (`semana_pedidosya_anonimizada.csv`) para pruebas de PedidosYa sin datos personales ni NIT.
- **Módulo de Finanzas — Fase 2 (Liquidaciones semanales de PedidosYa, conciliación bancaria y análisis de canal)** (`docs/PLAN_MODULO_FINANZAS.md`):
  - Tablas `pedidosya_settlements` y `pedidosya_orders` creadas por migración con almacenamiento JSON de datos crudos.
  - Servicio `PedidosYaImportService`: lectura nativa de CSV semanal de PedidosYa, detección de BOM, formateo de importes con comas decimales, conversión de fechas a hora boliviana (`America/La_Paz`) e idempotencia por `order_number` y `settlement_id`.
  - Conciliación de 8 montos del estado de cuenta PDF (ventas netas, envío, comisiones de servicio, cargos operativos, publicidad, reintegros, impuestos y total pagado al banco).
  - Integración en `FinanceReportService`:
    - Incorporación de ventas netas de liquidaciones en ingresos totales (`revenue.pedidosya_net`).
    - Costos netos de plataforma (comisiones + publicidad + impuestos retenidos − reintegros) integrados a gastos operativos (OPEX).
    - Bloque de análisis de rentabilidad PedidosYa (`pedidosya_analysis`): ventas brutas en app, total depositado en cuenta bancaria, retención de plataforma (monto y %), y desglose de pedidos entregados vs cancelados.
  - Controlador `PedidosYaSettlementController` con endpoints protegidos para `super_admin`.
  - Frontend:
    - Pestaña "🛵 Liquidaciones PedidosYa" en `Finance.vue` con resumen mensual y tabla de semanas.
    - Banner de rentabilidad PedidosYa en el Estado de Resultados.
    - Modal de importación con subida de CSV y comprobante ZIP/PDF, y validador de conciliación en vivo (alerta visual según discrepancia).
    - Modal/drawer para visualizar todas las órdenes de la semana con filtros por estado (Todos / Entregados / Cancelados).
  - Suite de pruebas `PedidosYaSettlementTest` en verde.
- **Precio PedidosYa solo en tamaños** (`docs/PLAN_PRECIOS_TAMANOS_PEDIDOSYA.md`):
  - Nueva columna `option_groups.pedidosya_pricing` (migración activa por defecto para grupos "Tamaño...").
  - En PedidosYa, solo los grupos marcados aplican `delivery_price`; toppings y frutas cobran siempre el precio local en ambos canales.
  - `SalePricingService` y `utils/channelPricing.js` sincronizados con la nueva regla.
  - `PedidosYaPricingController` rechaza con 422 cualquier intento de asignar tarifa de delivery a opciones de grupos no marcados.
  - El POS no alerta de "Sin precio PedidosYa" para toppings ni frutas.
  - En Opcionales (Menú), casilla "🛵 Precio distinto en PedidosYa", campo exclusivo en el modal de edición de opcionales e indicadores visuales claros en la lista de opciones.
  - El Tarifario PedidosYa lista únicamente los tamaños de grupos marcados en su sección de opcionales.
- Finanzas (auditoría, fase 1): `GET /finance/recurring-expenses/pending` y `POST …/generate` (idempotente;
  un gasto generado y eliminado no se vuelve a proponer salvo que se pida); plantillas con `start_month` y
  forma de pago.
- Finanzas: avisos en el Estado de Resultados (`warnings`): mes sin compras, egresos de caja sin clasificar,
  gastos fijos sin generar, semanas PedidosYa sin cargar y semanas con diferencias de conciliación.
- Finanzas: `GET /finance/cash-movements` y `PATCH /finance/cash-movements/{id}/category` para clasificar
  desde Finanzas los egresos de caja que quedaron sin categoría.
- PedidosYa: `POST /finance/pedidosya-settlements/preview` y semana tomada del CSV (lunes a domingo) si no se
  indica; se avisan los pedidos fuera del rango y se rechazan los que ya pertenecen a otra semana.
- PedidosYa: conciliación completa por semana (`reconciliation`): total liquidado, comisión del CSV = servicios
  e (informativa) ventas brutas − descuentos PedidosYa = ventas netas; diferencias mayores a Bs 1.
- PedidosYa: una semana que cruza dos meses se prorratea por la fecha de cada pedido entregado; las ventas
  PedidosYa del POS de días sin semana importada se suman mientras tanto (con aviso).
- **PedidosYa (auditoría, fase 3): la importación semanal crea o vincula las ventas** (plan de Finanzas §6.2–6.4).
  Cada pedido entregado queda como venta PedidosYa con la fecha y hora del pedido, sus artículos y opciones, el
  monto de PedidosYa (si difiere del Tarifario la línea lleva el motivo «Precio PedidosYa (importado)» y queda en
  auditoría), un único pago de plataforma y estado entregado; descuenta inventario y no entra al arqueo. Una venta
  ingresada a mano se **vincula** (Nº de pedido en la nota, o misma hora ±30 min y mismo monto; el dueño puede
  elegir otra o crear una nueva). Los cancelados no crean venta. Reimportar no duplica.
- Equivalencias de nombres PedidosYa ↔ POS en `platform_catalog_mappings` (`/finance/pedidosya-mappings`): se
  proponen productos parecidos; una opción puede quedar «sin equivalencia» (se anota en la venta). La importación
  no se confirma mientras falten equivalencias, salvo que se elija importar solo los montos
  (`sales_mode=skip`); las ventas se pueden crear después (`GET/POST /finance/pedidosya-settlements/{id}/sales`).
- Informe de la semana (`GET …/{id}/report`, plan §6.6): ventas por forma de pago y más vendidos, gastos de
  PedidosYa con % sobre ventas, descuentos y reintegros, lo que se queda PedidosYa, costo de productos y ganancia
  estimada (con el % de costo de ventas del mes), conciliación, pedidos a otro precio que el Tarifario y pedidos
  sin venta. Se puede imprimir.
- `pedidosya_orders.sale_link` y `catalog_total` (migración `2026_10_08_000003`).
- Índices para los reportes por mes: `sales (status, created_at)`, `sales (source, created_at)` y
  `cash_movements (type, created_at)` (migración `2026_10_08_000004`).
- Finanzas: **registrar lo que no está en la lista**. Los desplegables de categoría (compras y gastos, gastos
  fijos y clasificación de egresos de caja) terminan en «+ Nueva categoría…»: se escribe el nombre, se elige la
  sección del Estado de Resultados (costo de ventas, operativos, financieros o impuestos) y queda creada y
  elegida. Avisa si ya existe una parecida para usarla en vez de duplicarla; el backend rechaza nombres repetidos
  aunque cambien mayúsculas, acentos o espacios (también contra categorías desactivadas). Solo el dueño crea
  categorías: en la caja, el cajero elige «Otro / no está en la lista», describe el egreso y el dueño lo clasifica.
- Compras y gastos: el proveedor sugiere los ya usados (`GET /finance/suppliers`), propone la categoría de su
  última compra y se guarda con la forma ya registrada («frutas del valle» → «Frutas del Valle»).
- Finanzas: **Costos y margen** (`docs/PLAN_COSTOS_PUNTO_EQUILIBRIO.md`). Nueva pestaña con el costo por receta de
  cada producto, su margen en el local y en PedidosYa (después del % que se queda la plataforma) y el costo de cada
  tamaño. El asistente «Cargar recetas base» crea los componentes de costeo del análisis (porción base, mix de
  frutas, pack de toppings, kits descartables por tamaño, helado por kg y envase del Autoservicio, café en grano,
  leche, azúcar, vasos de café, matcha, bombilla y siropes) con sus costos como valores iniciales editables, y las
  recetas de bowls, Arma tu Bowl, Autoservicio y café, más los ajustes por opción (tamaños, «Sin …», toppings extra y
  cada fruta de Arma tu Bowl). Editor de recetas con cantidades en g/ml y «rinde» (18 g de café para 2 tazas = 9 g por
  taza). Los componentes de costeo (`ingredients.tracks_stock = false`) no mueven el stock de Inventario.
- Finanzas: **Punto de equilibrio** en el Resumen: margen de contribución (por canal), costos fijos del mes (gastos
  fijos mensualizados + otros gastos en categorías fijas), ventas necesarias al mes y por día (y productos por día),
  avance del mes, ritmo, día en que se alcanza y proyección al cierre; un mes cerrado muestra su resultado.
  Sin recetas usa el costo de las compras del mes; sin ventas, el margen del mes anterior. `GET /finance/break-even`.
- Categorías con **tipo de costo fijo o variable** (`expense_categories.cost_behavior`; el costo de ventas queda
  variable). Se elige en Categorías y al crear una.
- Las ventas de productos por peso guardan los gramos pesados (`sale_items.weight_grams`) desde el POS y desde
  Control Diario; las ventas anteriores se costean con los gramos deducidos del precio.
- Migración `2026_10_09_000001_add_costing_fields` (`tracks_stock`, `weight_grams`, `cost_behavior`).

- Inventario (plan [PLAN_INVENTARIO_REORDEN.md](docs/PLAN_INVENTARIO_REORDEN.md), F0): **Conteo físico** en lugar de
  «Ajuste» (se escribe lo que hay y el servidor calcula la diferencia contra el stock del momento);
  movimiento `initial` al crear un insumo con stock; comando `php artisan inventory:fix-initial-cost`
  (muestra o, con `--confirm`, corrige el CPP en 0 de insumos con stock).
- POS: aviso **«stock bajo»** en productos y opciones cuando el stock teórico no alcanza; no bloquea la venta.
- Inventario (plan, F1): **compras ligadas a insumos y proveedores**. Una compra es un gasto de Finanzas con líneas
  (`finance_expense_items`): cada línea con insumo sube el stock, actualiza el costo promedio (CPP) y deja un
  movimiento `restock` con su costo; las líneas sin insumo (gas, limpieza) cuentan solo como gasto. Se escribe lo
  pagado por línea y el sistema calcula el costo por unidad; si se compra en bolsas o cajas se indica cuántos kg
  o unidades trae cada una. Al **editar o eliminar** una compra el stock y el CPP se rehacen (movimiento
  `purchase_reversal`); si algún insumo tiene ingresos antiguos sin costo, el stock se corrige y el CPP se deja
  como estaba con un aviso. El estado de resultados no cuenta la compra dos veces.
- Un mismo formulario de compra en Finanzas → «Registrar compra» (y editar desde la lista, con la etiqueta
  «Compra · N líneas») e Inventario → «Ingreso» / «Registrar compra». Lo pueden usar admin y dueño
  (`GET /api/purchases/options`, `/api/purchases`).
- **Proveedores** (Inventario → «Proveedores»): nombre, teléfono, días de entrega y de pedido, notas. Se crean
  solos al escribir un proveedor nuevo en una compra (sin duplicar por mayúsculas, tildes ni espacios);
  `/api/suppliers`. Cada insumo guarda proveedor habitual, unidad y factor de compra y pedido mínimo (Editar insumo).
- Migraciones `2026_10_10_000002_create_suppliers_and_purchase_items` y
  `2026_10_10_000003_add_purchase_fields_to_ingredients_and_movements` (tablas y columnas nuevas; no modifican datos).
- Inventario (plan, F2): **composición de los componentes de costeo** (Finanzas → Costos y margen → Componentes de costo →
  «Composición»). Una porción como «Porción base açaí» o «Kit descartable chico» dice qué insumos reales consume
  (180 g de pulpa; 1 vaso + 1 tapa), en g, ml o unidades. Al vender, el sistema descuenta esos insumos (un solo nivel) y
  anular una venta devuelve cada movimiento tal como se registró; cambiar una composición no recalcula las ventas
  pasadas. El costo del componente pasa a ser la suma de sus insumos a su costo promedio, así que cambia solo cuando
  cambia el CPP; un componente sin composición sigue como antes (costo cargado, sin stock). `PUT
  /api/finance/costs/components/{id}/composition` (solo dueño).
- El catálogo del POS envía la receta de stock ya expandida (`stock_recipe` en productos y opciones); el POS, también sin
  conexión, solo suma y resta (`frontend/src/utils/stockRecipe.js`). El aviso de «stock bajo» de una opción cuenta lo que
  pide el producto base más el ajuste, así un tamaño Ohana avisa aunque alcance para el chico.
- No se puede eliminar un insumo que forma parte de la composición de un componente.
- Migración `2026_10_11_000001_create_ingredient_compositions` (solo crea la tabla; no modifica datos).
- Inventario (plan, F3): **mermas con motivo y valor**. «Registrar merma» en Caja (cajero) y en Inventario (admin): insumo,
  cantidad en g/ml/unidades, motivo obligatorio (vencido, dañado, derrame, error de preparación, prueba o cortesía,
  consumo del personal) y nota. La merma resta stock y queda valorada al costo promedio del momento; no se bloquea por falta
  de stock (avisa). El cajero solo ve lo que anotó él y no ve costos. `GET/POST /api/inventory/waste`.
- **Conteo físico** (Inventario → «Conteo»): se escribe lo que hay y el sistema muestra la diferencia en unidades y Bs; se
  revisa y, al confirmar, deja el stock igual a lo contado con un ajuste ligado al conteo. Filtro «Claves» / «Todos»,
  «contado hace N días» y un historial de conteos con sus líneas. La diferencia se calcula contra el stock del momento de
  confirmar. El primer conteo de un insumo ordena el stock de partida y no cuenta como merma oculta. Tablas
  `inventory_counts` e `inventory_count_lines`; `GET/POST /api/inventory/counts` (solo admin).
- Insumo clave (`ingredients.is_key`, casilla en Editar insumo): los que entran en el conteo semanal.
- **Historial por insumo** (Inventario → «Historial»): todos los movimientos, del más reciente al más antiguo, con tipo,
  cantidad, valor, detalle y quién; filtro por tipo y «Cargar más». `GET /api/ingredients/{id}/movements` ahora está
  paginado y es solo para admin (antes devolvía todo y lo podía leer cualquier usuario).
- **Mermas del mes** (Inventario): merma anotada por motivo, insumo y persona, y merma oculta (lo que los conteos
  encontraron de menos sin que nadie lo anotara). `GET /api/inventory/waste/report`.
- Finanzas → Punto de equilibrio: el costo real (compras del mes) frente al de las recetas se reparte en merma anotada,
  merma oculta y compras que siguen en stock y otros (`cost_gap`).
- Migración `2026_10_12_000001_create_inventory_counts` (tablas nuevas y la columna `is_key`; no modifica datos).
- Inventario (plan, F4): **punto de reorden y compras sugeridas**. Para cada insumo con stock el sistema calcula, con las
  ventas reales (menos los reversos de anulaciones y ediciones), el consumo diario de los últimos 28 días con ventas, el
  stock de seguridad (`1,65 × desvío × √días de entrega`; con menos de 28 días, días de seguridad × consumo), el punto de
  reorden y la cantidad a pedir para cubrir los días objetivo, redondeada a unidades de compra (bolsa, caja) y al pedido
  mínimo del proveedor, y limitada por la vida útil en los perecederos. Con 8 semanas de historia proyecta por día de la
  semana. Estados: OK, Reordenar, Crítico, Agotado, Compra diaria, Sin datos y Sin control, más aviso de vencimiento.
  `GET /api/inventory/reorder` y `/api/inventory/reorder/summary` (solo admin); las alertas se calculan al consultar, no
  se guardan.
- Arranque sin historial: mientras no hay 28 días de ventas se usa el consumo diario estimado por el dueño (o, con 3 a 27
  días, el promedio de lo vendido) y el stock mínimo hace de punto de reorden manual (nunca baja el calculado). Cada insumo
  indica si usa datos reales o una estimación. Un insumo cuenta sus días desde que se usó por primera vez.
- Inventario → **«Compras sugeridas»**: lista agrupada por proveedor (días de entrega, días de pedido, teléfono) con
  cantidades ajustables, estimado en Bs, y para cada grupo «Copiar», «WhatsApp» (enlace `wa.me`, sin servicios externos) y
  «Registrar compra» (abre el formulario de compra con proveedor y líneas ya puestos; el importe lo escribe quien compra).
  «Imprimir» y «Copiar todo» para la lista completa. La compra diaria (fruta) va en su propio grupo.
- Inventario: columna **Reposición** con el estado de cada insumo, el punto de reorden y los días que alcanza el stock; el
  formulario del insumo suma «Reposición y vencimiento» (cómo se repone, consumo diario estimado, punto de reorden manual,
  días de cobertura, días de seguridad, vida útil y fecha de vencimiento).
- Marca con el número de insumos por reponer en el menú «Inventario» (admin), tarjeta «Reposición del inventario» en
  Finanzas → Resumen y aviso «Antes de abrir» (agotados, críticos y por vencer) al administrador, una vez por turno, al
  abrir caja o al entrar a Caja con el turno ya abierto.
- Migración `2026_10_13_000001_add_reorder_fields_to_ingredients` (cinco columnas opcionales: `reorder_policy`,
  `target_days`, `safety_days`, `shelf_life_days` y `daily_estimate`; no modifica datos).
- Seeder `ReorderDefaultsSeeder`: precarga de proveedores (Distribuidora Açaí, Granola Artesanal, Mercado Central) y parámetros de reorden y vida útil de los insumos clave, marcando la fruta como compra diaria.
- Inventario (plan, F5): **indicadores de inventario** en Finanzas → Resumen (solo dueño), `GET /api/finance/inventory-indicators`
  (`year`, `month`). Seis indicadores, cada uno con su estado frente a la meta (en meta, cerca, fuera de meta o sin medir),
  la meta, un detalle y el mes anterior para comparar:
  - **Valor del inventario**: stock × costo promedio, a hoy (informativo), con los insumos sin costo o en negativo avisados.
  - **Días de cobertura**: lo que dura el insumo clave que primero se acaba, a hoy (si no hay claves marcados usa todos los
    que tienen consumo); el estado sale del punto de reorden de F4.
  - **Quiebres de stock**: días de venta del mes en que un insumo clave llegó a cero o menos, reconstruidos con los
    movimientos; meta: ninguno. No incluye productos marcados «agotado» a mano (no se guarda su historial).
  - **Merma sobre el consumo**: merma anotada en Bs ÷ consumo en Bs de las ventas (menos los reversos), total y por insumo;
    meta 5 % (10 % en perecederos).
  - **Diferencia de costo**: costo real (compras) menos costo por recetas, en puntos de las ventas; meta ±5 puntos.
  - **Exactitud del conteo**: insumos contados con diferencia de hasta 2 % ÷ insumos contados (sin los primeros conteos);
    meta 90 % o más.
- Finanzas → Resumen → **«Informe del mes»**: los seis indicadores con su meta más el detalle (cobertura de los insumos
  clave, quiebres con sus fechas, merma por insumo y la merma oculta de los conteos, conteos con más diferencia y dónde está
  el valor del inventario), listo para **imprimir** (A4, sin depender de la pantalla). Las metas son iniciales y están
  fijas en el servidor (`InventoryIndicatorsService::TARGETS`).
- Caja ([análisis del POS](https://claude.ai/code/artifact/0a10e4d8-9e98-424e-8ffe-8cc39486eaef), prioridad 1):
  **turno obligatorio para cobrar**. `GET /api/cash-sessions/active` devuelve `sales_session_id`, la caja que recibiría una
  venta ahora (la propia o la compartida de un admin, con la misma regla de `CashSessionResolver`). Sin caja, el POS cambia
  «Cobrar venta» por un aviso y «Abrir turno», que lleva a Turno con el formulario de apertura abierto. Sin conexión usa la
  copia del día; si no hay dato de hoy deja vender y avisa, para que el local no se quede sin vender.
- Caja: **revisión previa al cierre** (paso «Revisión» del asistente). Envía las ventas del dispositivo, trae las cifras al
  día y no deja cerrar mientras haya ventas pendientes o rechazadas. Un admin puede cerrar igual escribiendo el motivo, que
  queda en la nota del cierre y en la auditoría (`cash_close_forced`).
- Caja: `POST /api/cash-sessions/{id}/close` acepta `expected_seen` (el esperado que vio el cajero: si el servidor calcula
  otro, responde 409 con las cifras nuevas y el asistente las muestra para confirmar otra vez), `unsynced_sales` y
  `force_reason` (cajero: 422; admin: motivo de al menos 5 caracteres).
- Ventas **rechazadas a la vista**: tras 3 rechazos seguidos una venta pasa a «rechazada» con su motivo, deja de reintentarse
  sola y aparece en Turno (panel con «Reintentar envío»), en el indicador de red («N rechazadas») y en la revisión del cierre.
  Los motivos de validación de la sincronización están en español.
- Finanzas → **Resumen rediseñado** (dirección visual del 09/10: evolución de la identidad de Ohana, aprobada sobre la
  maqueta «Finanzas: dirección y maqueta»). El resultado del mes se lee como una frase («Septiembre cerró con Bs 9.094 de
  utilidad», «Octubre va con…», en pérdida o sin ventas), con la barra de qué queda de cada Bs 100 vendidos (los costos en
  tonos neutros y lo que queda en açaí; con pérdida, una marca muestra hasta dónde llegaron las ventas). Pegado al resultado
  va lo que falta cargar para que sea definitivo, cada aviso con su botón. Debajo: Estado de resultados y PedidosYa a la
  izquierda, punto de equilibrio y reposición a la derecha, y los indicadores de inventario como tabla, del que está peor al
  que está mejor, con estados de icono y texto. Mientras carga otro mes, el resultado avisa «Actualizando…».
- Punto de equilibrio: `progress.reached_day`, el día en que las ventas acumuladas cubrieron los costos fijos (las ventas
  diarias del POS escaladas al total del Estado de Resultados). El bloque lo dice en una frase («Cubriste los costos fijos
  el 8 de octubre») con la barra de los días del mes; el detalle (márgenes por canal, costo por recetas frente al real y
  merma) queda en «Ver de dónde sale».
- Vender: el **buscador funciona** (antes no filtraba): busca por nombre o descripción en todo el menú, sin distinguir
  tildes ni mayúsculas, dice cuántos productos encontró y ofrece «Ver todo el menú» si no hay ninguno.
- Caja: la **gaveta como una cuenta** que se lee de izquierda a derecha (fondo + ventas en efectivo + ingresos − gastos en
  efectivo = lo que debería haber), con QR, tarjeta y PedidosYa en una línea aparte. En celular la cuenta se lee como lista.
- `StatusTag`: estado con icono y texto (completada, anulada, abierto) para Vender y Caja, igual que `FinanceStatus`.

### Cambiado
- Venta del dueño o de un admin **con el turno de un cajero abierto**: entra a ese turno (el local tiene una sola gaveta).
  `CashSessionResolver` agrega un tercer paso: si quien vende es admin o super_admin y no tiene caja propia ni hay una caja
  compartida de admin, la venta va al turno abierto en ese momento. Antes el POS bloqueaba al dueño con «No hay un turno
  abierto» y Caja le mostraba el turno del cajero sin forma de abrir otro. Un cajero sigue sin vender en el turno de otro
  cajero. 4 tests nuevos.
- Rediseño de **Vender y Caja** con la dirección visual de Finanzas (evolución de la identidad de Ohana), sin cambiar la
  disposición de la pantalla de venta:
  - **Cobro**: arriba el total a cobrar; billetes sugeridos según el monto (los tres más chicos que alcanzan) y «Justo»;
    el **vuelto** en grande apenas se marca lo recibido; aviso si lo recibido no alcanza; con QR o tarjeta, qué hacer antes
    de cobrar; «Dividir el pago» en vez de dos controles de método; Enter cobra y Escape cierra. El botón dice el monto
    («Cobrar Bs 44.00») y queda siempre a la vista en la laptop de 1366×768 (antes había que desplazarse para confirmar).
  - **Comprobante**: «Entrega de vuelto» en grande, comanda y pagos; «Nueva venta» queda enfocado.
  - **Ticket**: cuenta productos (no líneas), botón «Cobrar Bs X», «Editar» con texto, botones de cantidad más grandes,
    opciones en etiquetas neutras (el tamaño en morado) y aviso de error legible si la venta no se guarda.
  - **Caja**: el turno en una frase («Tu turno» o «Turno de Cajero Test», abierto desde las 13:45), acciones con nombre
    («Anotar merma», «Registrar gasto o ingreso», «Cerrar caja» en maracuyá), historial con período en control
    segmentado, horas en 24 h y estados con icono. «Registrar gasto o ingreso» y «Abrir caja» usan las ventanas de Finanzas
    (las mismas de «Anotar merma»); la de gastos ya no sale siempre en oscuro.
  - **Cierre de caja**: título «Cerrar caja» con el dueño del turno (antes mostraba al usuario conectado aunque fuera un
    admin cerrando el turno de un cajero), pasos numerados del 1 al 4, diferencia con veredicto («La caja cuadra», «Falta
    dinero») y billetes con su color como franja.
  - Menú lateral: **Vender** y **Caja** (antes «POS · Ventas» y «Caja / Turno»), con su propio icono: Vender, Caja y Menú
    mostraban el mismo ⚙️ porque los nombres de icono no coincidían. Secciones sin mayúsculas, opción activa marcada, el
    rol del cajero dice «Cajero» (antes «cashier») y el nombre usa dos líneas. Los botones de rendimiento y tema quedan solo
    en la barra superior.
  - Sin emojis como iconos en Vender, Caja, cobro, comprobante, opciones del producto, detalle de venta y cierre; sin
    rótulos en mayúsculas; cifras tabulares. Productos sin foto (o con la foto perdida) muestran su inicial en vez de un
    ícono roto.
- `scripts/audit-contrast.cjs` usa los nombres nuevos del menú y del cobro.
- Finanzas, todas las pestañas: título y encabezados de bloque más grandes, encabezados de tabla sin mayúsculas, cifras
  tabulares y botón principal en maracuyá como el resto del POS (antes açaí, que queda para «lo que te queda»). El Resumen
  ya no tiene las cuatro tarjetas de cifras ni la tarjeta «Para que el resultado sea real»: su contenido pasó al resultado
  del mes.
- Arqueo: el asistente de cierre, la tarjeta «Efectivo en gaveta» de Turno y el reporte impreso muestran solo efectivo
  (fondo + ventas + ingresos − egresos en efectivo = esperado) y los egresos con QR o tarjeta aparte. Antes se restaban los
  egresos totales y se omitían los ingresos extra, así que las filas no sumaban el esperado.
- El arqueo en progreso guardado en el navegador queda ligado a su caja: uno abandonado ya no aparece al cerrar otra caja.
- Inventario (F3): los movimientos de venta, merma y conteo guardan el costo promedio del momento (`unit_cost`,
  `total_cost`; vacío si el insumo no tiene costo). El reverso de una venta anulada o editada conserva el valor del
  movimiento original.
- Inventario (F3): el ajuste manual por diferencia ya no existe; `POST /api/inventory/movements` con `type=adjustment`
  exige `counted_stock` y deja un conteo de un solo insumo. La merma acepta los seis motivos nuevos.
- Inventario: el stock de un insumo ya no se edita desde el formulario (cambia con ingresos, mermas, conteos
  y ventas); el costo inicial fija el CPP; una unidad antigua se conserva al editar y las nuevas son kg,
  litros o unidades.
- El stock se descuenta con una sola sentencia SQL y SQLite espera hasta 5 s por el bloqueo de escritura
  (`DB_BUSY_TIMEOUT`, `DB_TRANSACTION_MODE=IMMEDIATE`; WAL opcional con `DB_JOURNAL_MODE=wal`).
- `inventory_movements.type`, `waste_category` y `reference_type` pasan de enum a texto (migración sin cambio de datos).
- En un producto por peso, las filas de la receta en kg se multiplican por los kilos vendidos y las demás (envase,
  cucharilla) van una vez por unidad, igual que el costeo; antes el descuento multiplicaba todas por los kilos.
- POS: el descuento y la restauración del stock local usan la misma regla que el servidor; se corrige que un ajuste de
  opción con receta dejara `NaN` en el stock local (leía `quantity_required` en vez de `quantity_delta`) y que anular un
  ticket pendiente de un producto por peso devolviera una unidad en vez de los kilos.
- Inventario: los ingresos de stock ya no se registran como movimiento suelto; `POST /api/inventory/movements`
  solo acepta `waste` y `adjustment` (conteo). Todo ingreso entra por una Compra, para que Inventario y Finanzas
  cuadren. Un gasto con líneas no se edita como gasto simple (se edita como compra); al eliminarlo desde
  Finanzas se devuelve el stock.
- El arqueo de caja suma las ventas por sesión en lugar de por rango de horas (sin doble conteo). (`35ba606`)
- El sobrepago en efectivo se registra como cambio, no como ingreso. (`7005e0e`)
- Los pedidos PedidosYa (`source = 'pedidosya'`) ya no suman a las ventas del local ni al efectivo esperado,
  aunque se hayan registrado con otro método de pago; se guardan siempre con un único pago
  `delivery_platform` por el total (POS y Control Diario) y el ajuste queda en auditoría.
- La verificación de precios contra el catálogo usa el precio PedidosYa (`delivery_price`, o el local si
  está vacío) en los pedidos de ese canal; el precio guardado de cada topping también es el del canal.
- Botones naranjas y verdes con texto oscuro; textos naranjas y grises más contrastados. (`b7b6aa4`)
- Usuarios y Auditoría usan la tipografía del sistema; el tema respeta la preferencia del sistema operativo. (`2802a36`)
- Finanzas (auditoría, fase 2): pantalla rediseñada. El **Estado de Resultados** es la vista principal (tabla
  línea · Bs · % de ventas, con desglose por categoría y por movimiento), 4 indicadores arriba, avisos con acceso
  directo («Clasificar», «Revisar y generar», «Cargar semana») y resumen del canal PedidosYa. Selector de mes
  compacto, pestañas con contadores que se cargan con la página y scroll horizontal en pantallas angostas;
  en celular las listas se muestran como tarjetas y los formularios como hoja inferior.
- Finanzas: `Finance.vue` (2.554 líneas) se divide en `components/Finance/` (`FinanceSummary`,
  `FinanceStatementTable`, `FinanceExpenses`, `FinanceRecurring`, `FinanceCategories`,
  `PedidosYaSettlements`, `PedidosYaImportModal`, `FinanceModal`, `FinanceConfirm`, `finance.css`) y el
  store `stores/finance.js`. Solo tokens de `style.css` (nuevo `--danger-text`), modo oscuro incluido; 0
  textos bajo WCAG AA en las 5 pestañas y sus ventanas, en ambos temas, a 1440 y 390 px.
- Finanzas: confirmaciones y avisos con ventanas del sistema (sin `alert`/`confirm`); la fecha de un gasto
  nuevo es la fecha local (hoy, o el último día si el mes ya pasó).
- Compras y gastos: totales del filtro completo por sección (`totals` en `GET /finance/expenses`), aviso al
  elegir pago en efectivo y sección de egresos de caja con su categoría editable.
- Gastos fijos: panel del mes con pendientes, generados y descartados («Generar», «Recuperar»); las
  plantillas indican primer mes y forma de pago.
- PedidosYa: la carga de una semana lee primero el CSV (semana sugerida, totales, solapes, pedidos fuera de
  rango) y muestra la conciliación mientras se escriben los montos; el detalle de la semana muestra el estado de
  cuenta, las comprobaciones y los pedidos paginados con su hora local.
- `scripts/audit-contrast.cjs` recorre también las pestañas de Finanzas.

### Corregido
- Cobro: se quitaron el buscador de clientes que no buscaba, la clienta de ejemplo («María Fernanda Rojas, VIP») escrita en
  el código y la fila fija «Descuento VIP − Bs 0.00» del ticket. La venta se sigue registrando sin cliente.
- Si una venta no se puede guardar, el cobro se cierra y el ticket queda intacto con un aviso para el cajero; antes quedaba
  la ventana en «Procesando…» y se mostraba el error técnico completo.
- Caja: un cajero ya no pide la lista de usuarios al entrar (403); el editor de ventas pasadas solo se monta para admins.
- Indicador de red: «1 venta por enviar» (antes «🔄 1 pendientes»).
- Sincronización: una venta que no pasaba la validación hacía fallar todo el lote (422) y frenaba la cola entera. Ahora se
  reenvía venta por venta y solo queda retenida la que falla. La cola de cocina sigue mostrando las ventas rechazadas.
- Asistente de cierre: los botones deshabilitados («Continuar», «Confirmar cierre») se veían activos, había colores sin
  definir (`--lime-800`, `--danger-700`) y el distintivo «Arqueo de turno» quedaba bajo AA. En celular, el encabezado del
  asistente y los botones de Turno ya no se cortan.
- Costo promedio (CPP) mal calculado con stock inicial o negativo (10 kg a Bs 24 sobre 50 kg a Bs 12 daba
  Bs 4 en lugar de Bs 14; con stock negativo daba 24 en lugar de 18).
- Los productos por peso (Autoservicio) descontaban 1 kg de insumo por venta en lugar de los gramos vendidos,
  en el servidor y en el POS sin conexión.
- Una merma con cantidad positiva o un ingreso negativo ya no se aceptan (inflaban o vaciaban el stock).
- Prueba de caja que fallaba entre las 00:00 y las 02:00 por depender de la hora actual.
- La sincronización descartaba notas, alérgenos, estado de preparación, número de ticket y cantidad de
  modificadores. (`852ae61`)
- Las ventas del POS se guardaban en UTC (+4 h) en lugar de la hora de La Paz. (`35ba606`)
- Al recargar la página, los administradores perdían su rol y su menú. (`2ecb266`)
- El chip PedidosYa de Delivery y cocina no alcanzaba el contraste AA (4,46); el método `delivery_platform`
  se muestra como "PedidosYa" en el recibo y en el historial de Turno.
- Una caja abierta con fecha pasada empezaba a las 08:00 y dejaba fuera del arqueo las ventas de madrugada
  de ese día; ahora empieza a las 00:00.
- Las reglas de modo oscuro de los componentes nunca se aplicaban (`:global(html.dark)` en estilos scoped);
  pantallas de cocina, cobro y Control Diario con texto ilegible. (`2802a36`)
- Finanzas: la pantalla se dibujaba dentro de otro menú lateral (doble menú y doble encabezado, contenido
  cortado a la derecha). Ahora usa el marco común de la app.
- Finanzas: el Estado de Resultados cortaba los meses en hora UTC aunque las ventas se guardan en hora de La
  Paz (las ventas de las 00:00–04:00 del día 1 caían en el mes anterior). Los tests corren ahora con
  `APP_TIMEZONE=America/La_Paz`.
- Finanzas: la pestaña de liquidaciones PedidosYa aparecía siempre vacía (la respuesta paginada no se leía) y no
  filtraba por mes.
- Se restauran 10 imágenes de productos que un commit de graphify había borrado del repositorio (al fusionar en
  `main` se habrían borrado en producción).
- Finanzas: los gastos fijos se sumaban en todos los meses (incluso antes de existir, y cambiar el monto
  reescribía la historia; bimestrales y anuales se dividían). Ahora cada mes se **generan** como gastos reales
  (`finance_expenses.recurring_expense_id` + `period`, únicos por plantilla y mes) desde `start_month`; el
  bimestral, trimestral o anual entra completo en su mes. El Estado de Resultados solo lee gastos reales.
- Finanzas: editar o eliminar una compra o gasto respondía "actualizado" sin cambiar nada (el parámetro de la
  ruta no coincidía con el del controlador); lo mismo al ver o eliminar una semana PedidosYa.
- Finanzas: los gastos del último día del mes no aparecían en la lista ni en el Estado de Resultados (las
  columnas `date` se guardan con hora `00:00:00`). Las fechas se devuelven como `AAAA-MM-DD`.
- PedidosYa: se podían cargar semanas que se solapan (y sumarse dos veces); un CSV con columnas faltantes
  respondía error 500. Ahora se rechazan con 422 y un mensaje claro.
- Finanzas: el detalle de pedidos de una semana PedidosYa no cargaba (la respuesta paginada no se leía) y la
  conciliación del formulario sumaba los envíos en vez de restarlos.
- PedidosYa (revisión de la fase 3): una venta del POS vinculada a un pedido de una semana importada pero
  registrada un día fuera de esa semana se sumaba dos veces en el Estado de Resultados; un pedido con artículos
  ilegibles o un producto por peso creaba una venta vacía o a precio 0 (ahora se informa para registrarlo a
  mano); al reimportar una semana sin un pedido, se avisa que su venta sigue registrada; el ZIP subido se borra
  si la importación falla y el ZIP reemplazado se elimina.
- Finanzas: al reabrir un formulario mientras se recargaban las listas tras guardar, quedaba en «Guardando…»
  (botones desactivados y sin cerrar con Escape).
- Finanzas: al cambiar de mes rápido, una respuesta atrasada del mes anterior podía quedar en pantalla (el Estado de
  Resultados de septiembre con agosto elegido); ahora cada bloque muestra solo su última petición.
- Finanzas: en las ventanas con tablas (asistente de recetas) la tabla se encogía hasta desaparecer.
- Control Diario: al editar una venta por peso, los gramos se precargaban con la cantidad (1 g) en lugar de los
  gramos vendidos.
- POS: tras «Cargar recetas base», todos los productos con receta aparecían sin stock y no se podían vender (el
  catálogo enviaba los componentes de costeo, con stock 0, como insumos). El catálogo del POS ahora solo envía los
  insumos que llevan stock.
- Inventario: los componentes de costeo aparecían en la lista de insumos como «stock bajo» y se podían eliminar
  (stock 0); al eliminar uno, toda venta de un producto que lo usaba fallaba al sincronizar (error 500 que
  frenaba la cola del POS). Ahora Inventario solo lista lo que lleva stock, no se puede eliminar un insumo que
  está en una receta vigente (aviso 422 con cuántas recetas lo usan) y una venta se guarda aunque su receta tenga
  un insumo ya eliminado.

### Seguridad
- Permisos por rol aplicados en el backend; cocina sin acceso a ventas ni caja. (`938c31b`)
- Solo el dueño de una caja o un admin pueden operarla; abrir caja con fecha pasada es solo para admin. (`938c31b`)
- Límite de intentos de login, caducidad de tokens (7 días) y bloqueo de imágenes SVG. (`938c31b`)
- Restauración de backups validada, con copia previa y reemplazo atómico. (`938c31b`)
