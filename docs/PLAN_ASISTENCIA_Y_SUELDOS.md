# Plan: asistencia en el local y cálculo de sueldos

> Versión 2, 2026-11-03. Es una propuesta para aprobar y todavía no hay código. Cambio de la v2: el dueño decidió
> que cada trabajador solo ve sus horas de entrada y salida (D12 y sección 5.8). Se revisó contra la rama
> `fix/importar-carpeta-pedidosya` (`6d647c1`), que es la que está publicada en el VPS.

## Resumen

- Cada trabajador marca su **entrada** y su **salida** en un **reloj de asistencia**. El reloj está en la portátil
  de caja y no depende de quién tenga la sesión abierta en el POS.
- Una marca vale solo si se hace **en el local**. El servidor lo comprueba con dos pruebas a la vez: que venga de la
  **red del local** (IP pública) y de un **equipo autorizado** (la portátil de caja).
- Para marcar, la persona pone su **PIN** y la **cámara** valida su rostro: tiene que haber una cara real, que parpadee
  y que coincida con la registrada. Además se guarda siempre una **foto** de respaldo.
- Con las marcas, el sistema arma la **planilla** de cada persona: por hora, sueldo mensual o por turno, con horas
  extra, atrasos, faltas y adelantos de caja. Al aprobarla, el sueldo pasa a Finanzas como gasto.
- **Cada trabajador solo ve sus propias horas de entrada y salida.** Las tarifas, las horas totales, los atrasos, las
  faltas, las horas extra, el sueldo, las fotos y la planilla son solo para los administradores (sección 5.8).
- Sirve igual para 1, 2 o más trabajadores. Con varios, todos marcan en la misma portátil, uno detrás de otro.

---

## 1. Lo que se pidió y por qué no se marca al iniciar sesión

**Lo que se pidió:** calcular el sueldo de cada persona que trabaja en caja, contando solo el tiempo en que estuvo en
el local. La entrada y la salida se marcan en el local, con reconocimiento de rostro. Hoy trabaja una persona y
después pueden ser dos o más.

**Marcar al iniciar sesión no sirve como reloj:**

| Problema | Detalle verificado |
|---|---|
| La sesión dura 7 días | `config/sanctum.php`: `expiration` 10080 minutos. La portátil queda con la sesión abierta y nadie vuelve a iniciar sesión al empezar el turno. |
| Iniciar sesión no es empezar a trabajar | Alguien puede entrar a mirar algo antes del turno, o desde su casa. |
| El segundo trabajador no inicia sesión | Si el cajero tiene el POS abierto, el ayudante o la persona de cocina no tiene dónde marcar. |
| Cerrar caja no es salir | Después de cerrar la caja se limpia y se ordena. Ese tiempo también es trabajo. |

Por eso la propuesta separa las dos cosas: el **reloj** registra el tiempo y el **login** sigue sirviendo para usar
el sistema. Los dos se conectan en la caja: al abrirla se pide que la persona haya marcado su entrada, y al cerrarla
se le ofrece marcar la salida (sección 5.5).

---

## 2. Diagnóstico (verificado en el código)

| Tema | Estado | Detalle |
|---|---|---|
| IP real de quien entra | **Existe** | En el VPS, Nginx toma la IP del encabezado `CF-Connecting-IP` y solo acepta ese dato del túnel (`set_real_ip_from 127.0.0.1`, en `deploy/vps/02-instalar-sistema.sh`). Como Nginx escucha solo en `127.0.0.1` y el firewall solo deja pasar SSH, nadie puede inventar ese encabezado desde internet. Laravel ya usa `$request->ip()` para limitar los intentos de login. |
| Registro de IPs | **Falta** | `audit_logs` no guarda la IP. No sabemos si la IP del local es fija o cambia. Los registros de acceso de Nginx en el VPS sí la tienen (ver F0). |
| Datos del trabajador | Parcial | `users` tiene `ci`, `phone`, `start_date` y `photo_url`. No guarda tarifa, horario, PIN ni datos del rostro. |
| Roles | Existe | `super_admin`, `admin`, `cashier` y `kitchen`. Una persona que no usa el sistema (un ayudante) igual necesita un registro para marcar. |
| Caja | Existe | Cada persona abre su caja (`CashRegisterSessionController::store`). No sabe nada de asistencia. |
| Sueldos en Finanzas | Parcial | Existe la categoría «Salarios y personal». El sueldo se carga como **gasto fijo** (`recurring_expenses`, p. ej. «Salario Juan») y el punto de equilibrio lo usa (`BreakEvenService::fixedCosts`). Los adelantos pagados de la gaveta son egresos de caja que Finanzas ya suma por categoría (`FinanceReportService`, L123 y L189). |
| Cámara | **Falta** | Ningún componente usa la cámara. El sitio funciona con HTTPS, que es un requisito del navegador para usarla. |
| Sin conexión | Existe | El POS guarda en el equipo (Dexie, `db/database.js` v5) y sincroniza después. El reloj puede aprovechar el mismo mecanismo. |
| Caché de la app | Existe | `vite-plugin-pwa` guarda en caché solo `js, css, html, ico, png, svg, woff2`, con un tope por archivo de 2 MiB. Los modelos de rostro tienen otra extensión y pesan más, así que hay que configurar su caché aparte (sección 5.4). |
| Dependencias | Restricción | AGENTS.md pide no agregar dependencias npm, porque los scripts de la laptop Windows nunca ejecutan `npm install`. El VPS sí ejecuta `npm ci` (`04-actualizar.sh`, L63). Igual, los modelos de rostro tienen que servirse como archivos, así que lo más simple es copiar la librería y los modelos a `frontend/public/vendor/`, como se hizo con las fuentes. |

---

## 3. Opciones

### 3.1 ¿Cómo se prueba que la persona está en el local?

| Opción | Cómo funciona | A favor | En contra |
|---|---|---|---|
| **A. Red del local (IP)** | El servidor compara la IP de la marca con la lista de redes del local. | Es gratis y no se ve. Desde un navegador no se puede falsificar (sección 2). | Si el proveedor de internet cambia la IP, nadie puede marcar hasta que se registre la nueva. Si la conexión usa IP compartida (CGNAT), otros clientes del mismo proveedor salen con la misma IP. Con IPv6, la dirección cambia seguido, así que hay que comparar el prefijo /64. Sin internet no hay forma de comprobarlo. |
| **B. Equipo autorizado** | El dueño autoriza la portátil de caja una sola vez. El navegador guarda una llave y el servidor guarda su huella. | Sigue funcionando aunque cambie la IP. Solo se marca desde ese equipo. | La portátil se puede llevar a otro lado. Una persona con conocimientos técnicos podría copiar la llave. |
| **C. GPS del navegador** | Se pide la ubicación al marcar. | En un celular es bastante preciso. | En una portátil la ubicación sale del Wi-Fi y puede errar por decenas de metros o más. Se puede falsificar. Sirve solo como dato extra. |
| **D. Código QR que cambia** | La portátil muestra un QR que cambia cada 30 segundos y el trabajador lo escanea con su celular. | Prueba que la persona está frente a la pantalla. Sirve cuando cada uno marca con su celular. | Hace falta celular y es un paso más. Cobra sentido con 3 o más personas. |

**Recomendación: A + B juntas.** Una marca es válida cuando viene del equipo autorizado **y** de la red del local.
Si el equipo es el correcto pero la IP es nueva, la marca **no se pierde**: queda «Pendiente: red nueva». El dueño
toca «Es la red del local», la nueva IP queda registrada y las marcas pendientes pasan a válidas. Así un cambio de IP
no deja a nadie sin poder marcar, y si la portátil sale del local, la marca queda a revisar.

### 3.2 ¿Cómo se prueba que es la persona?

| Nivel | Qué hace | A favor | En contra |
|---|---|---|---|
| **N1. PIN + foto** | PIN de 4 a 6 dígitos. La cámara exige una sola cara en la imagen y guarda la foto. El dueño ve las fotos en el reporte. | Es simple y nunca rechaza por mala luz. La foto desanima a quien quiera marcar por otro. | El sistema no compara rostros: se revisa a ojo. |
| **N2. Rostro en el navegador** | Se registran 3 a 5 fotos de la persona. En cada marca se pide un parpadeo, se calcula un «descriptor» del rostro y se compara con el registrado. | No tiene costo por uso, funciona sin internet y la foto no sale del sistema. | El resultado se calcula en el equipo, así que una persona con conocimientos técnicos podría falsearlo. Por eso se guarda la foto. Con mala luz o mala cámara puede haber rechazos falsos, que se calibran en F3. |
| **N3. Rostro en la nube** | El servidor envía la foto a un servicio como Amazon Rekognition (`CompareFaces`, con verificación de vida opcional). | Es más preciso y se calcula en el servidor, así que no se puede falsear desde el equipo. | Hace falta una cuenta de AWS con sus llaves en el VPS y conexión a internet. Las fotos del rostro salen del país. Cuesta USD 0,001 por foto y USD 0,015 por verificación de vida. Con 2 personas × 2 marcas × 30 días son unas 120 marcas al mes: alrededor de USD 0,12, o USD 1,90 con verificación de vida. |

**Recomendación: N1 + N2.** Siempre PIN y foto. Encima, la comparación en el navegador con la librería **Human**
(licencia MIT): detecta el rostro, calcula el descriptor y su demo *FaceID* comprueba que sea una cara real. La
librería anterior del mismo autor (`face-api`) está archivada desde 2024 y él mismo remite a Human. N3 queda como
mejora futura, si alguna vez hay sospecha de fraude.

**Regla importante:** si el rostro no coincide, la marca **no se rechaza**. Se guarda como «Rostro a revisar» con su
foto. Rechazarla le haría perder horas a alguien por la mala luz; guardarla deja la decisión al dueño, con la prueba
a la vista.

### 3.3 ¿Dónde y cuándo se marca?

| Opción | Cómo funciona | Veredicto |
|---|---|---|
| Al iniciar sesión | La entrada se marca sola al iniciar sesión desde la red del local. | **No** (sección 1). |
| Al abrir y cerrar la caja | La entrada se marca al abrir la caja y la salida al cerrarla. | **No como única regla**: solo cubre al cajero y deja fuera el tiempo antes de abrir y después de cerrar. Sirve como recordatorio. |
| **Reloj de asistencia en la portátil** | Un botón «Marcar asistencia» en la pantalla de login y en la barra superior. Se elige el nombre, se pone el PIN, se valida el rostro y se muestra «Entrada 14:02». No cambia quién tiene la sesión abierta en el POS. | **Sí, recomendado.** |
| Celular de cada trabajador | Cada uno marca desde su celular conectado al Wi-Fi del local (IP), con selfie, y opcionalmente con el QR. | Más adelante, si hay 3 o más personas o si la portátil queda ocupada en horas pico. |

### 3.4 Con dos o más trabajadores

| Escenario | Cómo se marca | Qué hay que tener en cuenta |
|---|---|---|
| **1 persona (hoy)** | En la portátil. Al abrir la caja, si no marcó la entrada, se le pide hacerlo. | Nada más. |
| **2 personas, 1 portátil** | Las dos en la misma portátil, una después de otra. El POS sigue con la sesión del cajero. | El segundo trabajador necesita su registro, aunque no use el sistema (D2). |
| **2 personas en turnos distintos** (mañana y tarde) | Cada una marca su entrada y su salida. Cada cajero abre y cierra su propia caja, como hoy. | El cambio de caja entre turnos es otro tema y no entra en este plan. |
| **3 o más personas, o mucha gente a la misma hora** | Se suman los celulares con Wi-Fi del local y el QR (3.1-D). | Es una fase futura. El diseño de datos ya lo permite, porque cada marca guarda su equipo y su red. |

### 3.5 ¿Cómo se calcula el sueldo?

Cada persona tiene **un** modo de pago:

| Modo | Fórmula | Para quién |
|---|---|---|
| **Por hora** | horas normales × tarifa + horas extra × tarifa × 2 | Medio tiempo o horario variable. |
| **Sueldo mensual** | sueldo − atrasos − faltas + horas extra. Valor de la hora = sueldo ÷ 240 (30 días × 8 h). | Tiempo completo con horario fijo. |
| **Por turno (jornal)** | turnos trabajados × monto por turno + horas extra | Turnos de duración fija. |

**Referencias legales en Bolivia** (confírmalas con tu contador; esto no es asesoría legal):
- Las horas extra se pagan con un **recargo del 100 %**, es decir el doble (art. 55 de la Ley General del Trabajo),
  con un máximo de 2 horas por día.
- La jornada diurna es de 8 horas por día o 48 por semana. El trabajo nocturno, en domingo y en feriado tiene
  recargos propios: quedan como porcentajes configurables.
- El salario mínimo nacional 2026 es **Bs 3.300**, que da Bs 13,75 por hora. El sistema avisa si una tarifa queda por
  debajo.

**Ejemplo por hora:** Bs 15 la hora, 22 turnos de 8 h = 176 h → Bs 2.640. Más 6 h extra × Bs 30 = Bs 180. Bruto
Bs 2.820. Menos un adelanto de Bs 300 sacado de la caja: **neto Bs 2.520**.

**Ejemplo mensual:** sueldo Bs 3.300, hora Bs 13,75. Atrasos por 45 min (fuera de la tolerancia) → −Bs 10,31. Una
falta → −Bs 110 (un día = 3.300 ÷ 30). 4 h extra × Bs 27,50 → +Bs 110. **Bruto Bs 3.289,69.**

**Fuera de este plan:** aportes a la Gestora/AFP, RC-IVA, aguinaldo, finiquito e indemnización. La planilla tiene
una línea de «Otros descuentos / bonos» con motivo, para cargarlos a mano.

---

## 4. Decisiones para el dueño

| # | Pregunta | Recomendación |
|---|---|---|
| D1 | ¿Cómo se prueba que está en el local? | **Equipo autorizado + red del local**. Con una IP nueva, la marca queda pendiente y no se rechaza (3.1). |
| D2 | ¿Quién marca? | **Todo el que trabaja en el local**, también la cocina. Un ayudante que no usa el sistema se crea como persona con el rol nuevo **«Personal»**, que no tiene acceso a ninguna pantalla y solo marca. |
| D3 | ¿Cómo se valida a la persona? | **PIN + foto + rostro en el navegador** (N1 + N2). Si el rostro no coincide, la marca queda «a revisar» y no se rechaza. |
| D4 | ¿Se bloquea abrir la caja sin haber marcado la entrada? | Las **2 primeras semanas solo se avisa**, con un botón para marcar. Después se bloquea. |
| D5 | ¿Qué pasa si alguien no marca la salida? | A las 03:00 el turno queda «Sin salida» y cuenta **0 horas** hasta que el dueño ponga la hora, con motivo. La alternativa es usar la hora de fin del horario, también marcada para revisar. |
| D6 | ¿Cada cuánto se paga? | Cada **mes**, con vista parcial por quincena. Dime si pagas por semana o por quincena. |
| D7 | ¿Se descuentan los atrasos? | Sí, solo pasada la tolerancia (**10 min**). Confírmalo con tu reglamento interno o tu contador. |
| D8 | ¿Se redondean las horas? | No: se cuentan los minutos exactos. |
| D9 | ¿Cuánto tiempo se guardan las fotos de las marcas? | **90 días**. Después se borra la foto y la marca queda. El rostro registrado se borra cuando la persona deja de trabajar. |
| D10 | ¿Las fotos van a los respaldos de R2? | **No**: son datos personales y no hacen falta para recuperar el sistema. Las marcas sí se respaldan, porque están en la base. |
| D11 | ¿Qué pasa si no hay internet? | Se puede marcar igual. Se guarda en la portátil con su foto y llega al servidor al volver la conexión, como **«Marcada sin conexión: a aprobar»**, porque el reloj del equipo se puede cambiar. |
| D12 | ¿Quién ve qué? | **Decidido por el dueño (03/11):** cada trabajador ve solo sus propias horas de entrada y salida. Todo lo demás es solo para los administradores: tarifas, horas totales, atrasos, faltas, horas extra, adelantos, sueldo, fotos, puntaje del rostro, la bandeja «A revisar» y la planilla. El detalle está en la sección 5.8. |

**Lo que hace falta saber antes de empezar:**
1. ¿La portátil de caja tiene cámara y funciona? (Toshiba, 1366 × 611)
2. ¿Qué proveedor de internet tiene el local y la IP es fija? F0 lo mide igual.
3. Modo de pago, tarifa u horario de la persona que trabaja hoy.

---

## 5. Diseño (con las recomendaciones de la sección 4)

### 5.1 Datos (migraciones aditivas)

| Tabla | Para qué | Campos principales |
|---|---|---|
| `employee_profiles` | Datos laborales de la persona (1 a 1 con `users`) | `user_id`, `attendance_enabled`, `pin_hash`, `pay_mode` (`hourly` / `monthly` / `per_shift`), `hourly_rate`, `monthly_salary`, `shift_rate`, `schedule` (JSON por día: inicio y fin), `late_tolerance_min`, `overtime_after_hours` (8), `overtime_multiplier` (2.0), `sunday_multiplier`, `holiday_multiplier`, `face_consent_at`, `face_consent_by` |
| `face_enrollments` | Rostro registrado | `user_id`, `descriptor` (vector de números, JSON), `model_version`, `photo_path`, `created_by`, `revoked_at` |
| `attendance_networks` | Redes del local | `label`, `cidr` (IPv4 exacta o rango; IPv6 por /64), `added_by`, `confirmed_from_mark_id`, `revoked_at` |
| `attendance_devices` | Equipos autorizados | `name` («Portátil de caja»), `token_hash`, `authorized_by`, `last_seen_at`, `last_ip`, `revoked_at` |
| `attendance_marks` | Cada entrada y salida | `user_id`, `type` (`in` / `out`), `marked_at` (**hora del servidor**), `device_time`, `received_at`, `ip`, `device_id`, `network_ok`, `device_ok`, `face_score`, `liveness_ok`, `photo_path`, `status` (`valid` / `pending_network` / `face_review` / `offline_review` / `rejected`), `source` (`kiosk` / `offline` / `manual`), `reason`, `corrected_from_id`, `created_by` |
| `payroll_periods` | Planilla aprobada | `period_start`, `period_end`, `status` (`draft` / `approved`), `approved_by`, `approved_at`, `finance_expense_id` |
| `payroll_lines` | Una línea por persona | `payroll_period_id`, `user_id`, horas normales y extra, minutos de atraso, faltas, bruto, adelantos, otros (con motivo), neto, `snapshot` (las tarifas que se usaron) |
| `cash_movements.employee_id` | Adelanto de sueldo pagado de la gaveta | Columna nula nueva. Se pide solo si la categoría es «Salarios y personal». |

Las marcas nunca se borran ni se editan en su lugar. Una corrección crea una marca nueva con `source = manual`,
`reason` y `corrected_from_id`, y queda en auditoría.

### 5.2 La regla «en el local» (servidor)

En `LocalPresenceService::evaluate($request)`:

1. `ip = $request->ip()`. Es la IP real que Nginx toma del túnel (sección 2).
2. `network_ok`: la IP está en `attendance_networks`. En IPv4 se compara exacta o por rango; en IPv6, por el prefijo
   /64.
3. `device_ok`: el encabezado `X-Attendance-Device` trae una llave cuya huella está en `attendance_devices` y no fue
   revocada.
4. Resultado:
   - las dos bien → `valid`;
   - equipo bien e IP nueva → `pending_network`;
   - IP bien y equipo desconocido → **se rechaza** con «Este equipo no está autorizado para marcar» y queda en
     auditoría;
   - las dos mal → se rechaza.

**Registrar la portátil (una vez):** el dueño inicia sesión en la portátil, en el local, y en
Usuarios → Asistencia toca «Autorizar este equipo». Ese paso genera la llave y propone registrar también la IP actual
como red del local.

### 5.3 Reloj de asistencia (pantalla)

- **Dónde está:** un botón «🕒 Marcar asistencia» en la pantalla de login y en la barra superior del POS. Aparece solo
  en un equipo autorizado.
- **Cómo funciona:**
  1. se muestran tarjetas con la foto y el nombre de quienes marcan asistencia;
  2. la persona pone su PIN;
  3. se abre la cámara: «Mira a la cámara y parpadea»;
  4. se muestra el resultado: «✅ Entrada 14:02» o «✅ Salida 22:15 · 8 h 13 min».
- Si la persona tiene una entrada abierta, la marca siguiente es la salida; si no, es una entrada. Una segunda marca
  dentro de los 2 minutos se ignora.
- **Servidor:** `POST /api/attendance/clock`. No pide la sesión del trabajador: pide la llave del equipo, el
  `user_id` y el PIN. Después de 5 PIN mal, esa persona queda bloqueada 15 minutos en ese equipo.
  `GET /api/attendance/roster` devuelve solo el nombre, la foto y si a la persona le toca entrada o salida. Nunca
  devuelve horas, tarifas ni montos, y las tarjetas del reloj no muestran las horas de nadie.
- **Resultado del rostro:** al trabajador se le muestra solo «Rostro verificado» o «No se pudo confirmar tu rostro».
  El porcentaje lo ven solo los administradores.
- **Mis marcas:** después de marcar, la persona puede tocar «Ver mis marcas». Ve sus últimos días con la hora de
  entrada y la de salida, y nada más. La pantalla se cierra sola a los 20 segundos.
- Diseñada para la portátil de caja (1366 × 611 y 1093 × 489), en tema claro y oscuro, con contraste AA.

### 5.4 Rostro

- **Librería:** Human (MIT). Su archivo principal y los modelos que se usan (detección, descriptor y anti-suplantación
  o vida) se copian a `frontend/public/vendor/human/`, sin agregar una dependencia npm. Se agrega una regla de caché `CacheFirst`
  para `vendor/human/**`, porque `globPatterns` no los incluye y pasan el tope de 2 MiB. Así funcionan sin internet.
- **Registrar el rostro:** lo hace el dueño con la persona, en el local, desde Usuarios → editar persona → «Registrar
  rostro». Primero se marca el consentimiento (fecha y quién), después se toman 3 a 5 capturas mirando de frente y un
  poco hacia los lados, y se guardan los descriptores y una foto de referencia.
- **Al marcar:**
  1. tiene que haber exactamente una cara en la imagen;
  2. la persona parpadea;
  3. se calcula el descriptor y se compara con el registrado;
  4. según la similitud, la marca queda: «alta» → válida; «media» o «baja» → `face_review` (se guarda igual).
- Los umbrales se calibran en F3 con la cámara y la luz reales del local.
- **Foto:** JPEG de 640 px, unos 60 KB, en `storage/app/private/asistencia/AAAA/MM/`. **Nunca va en
  `storage/app/public/`**: ver la regla de AGENTS.md. Se descarga solo por un endpoint protegido, como los archivos de
  PedidosYa.
- **Límite:** el puntaje lo calcula el navegador. El servidor guarda lo que recibe y la foto es la prueba que se
  puede revisar. Si algún día hace falta que el servidor lo calcule, se pasa a N3.

### 5.5 Turnos, olvidos, correcciones y caja

- **Horario** por persona y día (opcional). Con horario, el sistema calcula los atrasos (pasada la tolerancia) y las
  faltas (días con horario y sin marca). Sin horario, solo cuenta las horas.
- **Turno que pasa la medianoche:** las horas cuentan para el día de la entrada, en hora de La Paz.
- **Salida olvidada (D5):** una tarea del scheduler a las 03:00 marca el turno «Sin salida».
- **Correcciones:** el dueño, o quien tenga *Asistencia y sueldos* → Editar, agrega o corrige una marca con un motivo
  de 10 a 255 caracteres, igual que al reabrir una caja. La marca original se conserva y queda en auditoría.
- **Bandeja «A revisar»:** reúne las marcas `pending_network`, `face_review` y `offline_review` y los turnos sin
  salida, con foto, hora y red. Cada una se aprueba o se rechaza con un toque. La planilla no se puede aprobar
  mientras queden pendientes del período.
- **Caja:**
  - al abrirla, si quien la abre no tiene una entrada hoy, aparece el aviso «Primero marca tu entrada» con un botón
    (D4);
  - al terminar el cierre, en el último paso de `CashCloseWizard`, aparece el botón «Marcar mi salida», que abre el
    reloj con la persona ya elegida (igual pide PIN y rostro).

### 5.6 Planilla

- **Pantalla:** Finanzas → «Sueldos». Se elige el período y se ve una fila por persona con días, horas normales,
  horas extra, atrasos, faltas, bruto, adelantos, otros y neto. Cada fila abre el detalle día por día con sus marcas.
- **Adelantos:** los egresos de caja con la categoría «Salarios y personal» y una persona elegida (`employee_id`) se
  restan solos.
- **Aprobar la planilla:**
  - se guardan las tarifas usadas (`snapshot`) y se crea un gasto en Finanzas, categoría «Salarios y personal», por
    **bruto − adelantos**. Los adelantos ya están en Finanzas como egresos de caja, y así no se cuentan dos veces.
  - si la persona tiene un gasto fijo «Salario …», la planilla aprobada **ocupa el lugar** del gasto de ese mes
    (`recurring_expense_id` y el mismo período). El aviso de «gasto fijo pendiente» no aparece y el punto de
    equilibrio sigue usando la plantilla como estimado.
  - el mecanismo exacto se confirma en F5 contra `RecurringExpenseService`.
- **Recibo** por persona para imprimir o descargar en PDF: período, horas y montos.

### 5.7 Privacidad

- **Consentimiento por escrito** del trabajador antes de registrar su rostro, y queda anotado en el sistema. Una hoja
  simple debe explicar para qué se usa, quién lo ve y cuánto tiempo se guarda. No soy abogado: consulta si hay alguna
  norma que aplique a tu caso.
- Las fotos de las marcas se borran a los 90 días (D9) con una tarea del scheduler.
- El rostro registrado se borra cuando la persona sale de Usuarios.
- Las fotos, el puntaje del rostro y todo lo demás que lista la sección 5.8 los ven solo los administradores.

### 5.8 Qué ve cada uno (decisión del dueño, 03/11)

| Dato | Trabajador (cajero, cocina o Personal) | Administradores |
|---|---|---|
| Sus horas de entrada y salida | Sí, solo las suyas | Sí, las de todos |
| Las horas de entrada y salida de los demás | No | Sí |
| Horas totales, horas extra, atrasos y faltas | No | Sí |
| Tarifa, sueldo, adelantos, planilla y recibos | No | Sí |
| Fotos de las marcas y puntaje del rostro | No. Solo ve «Rostro verificado» o «No se pudo confirmar». | Sí |
| Estado de una marca (pendiente, rechazada o corregida) | Solo el aviso al marcar: «quedó guardada, un administrador la revisa» | Sí |
| Bandeja «A revisar», redes y equipos | No | Sí |

- **El servidor aplica la regla, no solo la pantalla.** Las respuestas para un trabajador (`/api/attendance/roster`,
  `/api/attendance/clock` y `/api/attendance/my-marks`) solo llevan el día, el tipo y la hora de sus propias marcas.
  Los montos, las horas totales, las fotos y los puntajes salen solo de endpoints con el permiso
  *Asistencia y sueldos*.
- **Quiénes son los administradores:**
  - el Super Admin, siempre;
  - los administradores, que tienen el permiso por defecto. Si alguno no debe ver los sueldos, se le quita en
    Usuarios.
- **El perfil laboral** (tarifa, PIN y rostro) sigue en Usuarios, que es solo del Super Admin.
- **El recibo de sueldo** lo imprime o lo descarga un administrador y se lo entrega a la persona. El sistema no se
  lo muestra al trabajador.

---

## 6. Fases (un commit por fase, en la rama `feat/asistencia-sueldos`)

### F0 · Preparación y medición (no cambia nada para el trabajador)
- Crear la rama, copiar este plan a `docs/PLAN_ASISTENCIA_Y_SUELDOS.md` y respaldar la base.
- Crear el script `deploy/vps/09-ips-del-local.sh`. Lee los registros de acceso de Nginx del VPS y resume por día qué
  IPs usaron `/api/sales/sync` y `/api/login`. El dueño lo ejecuta desde la Mac y la salida queda en `deploy/vps/logs/`.
  Con eso se sabe en minutos si la IP del local es fija, si usa IPv6 y si cambia de noche.
- Revisar que la cámara de la portátil funcione con el navegador del POS.

### F1 · Servidor — `feat(asistencia): marcas de entrada y salida validadas en el local`
- Migraciones de 5.1, salvo la planilla. `LocalPresenceService`, endpoints de reloj, roster, redes, equipos y
  correcciones, y `GET /api/attendance/my-marks`, que devuelve solo las horas de entrada y salida de quien pone su PIN.
  Permiso por persona `attendance` en `PermissionService`: techo administrador `edit` y cajero `none`; por defecto,
  administrador `edit` (5.8).
  Rol `staff` («Personal») con techo `none` en todo.

### F2 · Reloj de asistencia — `feat(asistencia): reloj en la portátil de caja con PIN y foto`
- Pantalla 5.3 con N1 (PIN + foto + una sola cara), autorizar el equipo, registrar la red y la bandeja «A revisar».
- Marca sin conexión en Dexie (v6) y su sincronización.
- **Con esta fase ya se puede empezar a marcar.**

### F3 · Rostro — `feat(asistencia): reconocimiento facial con parpadeo`
- Human copiada en `public/vendor/`, su regla de caché, registro del rostro con consentimiento y comparación al
  marcar. Calibración de los umbrales en el local, con la persona real.

### F4 · Turnos y caja — `feat(asistencia): horarios, salidas olvidadas, correcciones y aviso al abrir caja`
- Horario, tolerancia, tarea de las 03:00, aviso o bloqueo al abrir la caja y «Marcar mi salida» al cerrarla.

### F5 · Planilla — `feat(sueldos): planilla por período y gasto en Finanzas`
- Cálculo de los tres modos, adelantos, otros, aprobación, gasto en Finanzas sin doble conteo y recibo.

### F6 · Verificación y cierre
- Pruebas de la sección 7, `graphify update .` en la Mac, CHANGELOG y BITÁCORA. Después, la pregunta de publicar en el
  VPS.

---

## 7. Pruebas

- **PHPUnit:**
  - `LocalPresenceService`: IPv4 exacta y por rango, IPv6 del mismo /64 y de otro, equipo revocado, IP nueva con
    equipo bueno → `pending_network`.
  - Reloj: PIN mal y bloqueo, entrada y salida alternadas, doble toque dentro de 2 minutos, hora del servidor (no la
    del equipo).
  - Marcas sin conexión → `offline_review`; correcciones auditadas que conservan la original.
  - Rol `staff` sin acceso a ninguna ruta.
  - Lo que ve un trabajador:
    - `roster`, `clock` y `my-marks` no devuelven horas totales, tarifas, montos, fotos, puntajes ni marcas de otras
      personas;
    - un cajero recibe 403 en la bandeja, la planilla, los recibos y las fotos.
  - Planilla: los tres modos; horas extra a partir de las 8 h; tolerancia; turno que pasa la medianoche en
    `America/La_Paz` con fechas que llegan en UTC; turno sin salida; borde del período a las 23:59; adelantos
    restados; gasto en Finanzas sin doble conteo; planilla bloqueada con pendientes; aviso por debajo del salario
    mínimo.
- **Unitarias del frontend:** decidir si toca entrada o salida, clasificar por umbral de similitud, cola sin conexión.
- **E2E (Playwright):**
  - reloj a 1366 × 611 y 1093 × 489, en claro y oscuro;
  - cámara simulada con `--use-fake-device-for-media-stream` y un video de prueba (una cara, dos caras, ninguna);
  - aviso al abrir la caja y «Marcar mi salida» al cerrarla;
  - planilla y aprobación.
- **Contraste AA** de todas las pantallas nuevas.
- **Flujo contra una copia de la base:**
  1. autorizar el equipo;
  2. registrar el rostro;
  3. marcar la entrada;
  4. abrir caja → vender → cerrar caja;
  5. marcar la salida;
  6. simular una IP nueva (queda pendiente y se aprueba);
  7. salida olvidada y su corrección;
  8. adelanto de caja;
  9. aprobar la planilla y verla en Finanzas.

---

## 8. Riesgos y límites

| Riesgo | Cómo se cubre |
|---|---|
| La IP del local cambia | La marca queda `pending_network` y se aprueba con un toque. F0 mide qué tan seguido pasa. |
| IP compartida (CGNAT) | Igual hace falta el equipo autorizado, el PIN y el rostro. |
| Se llevan la portátil | El equipo está bien pero la red no: la marca queda pendiente. |
| Mala luz o mala cámara | Nunca se rechaza: queda «a revisar», con la foto. |
| Sin internet | La marca se guarda sin conexión y queda a aprobar. |
| Alguien falsea el resultado del rostro | Lo cubre la foto guardada. Si hace falta, se pasa a N3. |
| Una persona marca por otra | PIN + rostro + foto. La bandeja muestra la foto al lado de la registrada. |
| VPS chico (1 GB) | El reconocimiento se hace en el navegador y el servidor solo guarda la foto (unos 7 MB al mes con 2 personas). |
| Cumplimiento laboral | La planilla calcula horas y montos. Los aportes, el aguinaldo y el finiquito quedan fuera (3.5). |

---

## 9. Al publicar

- Las migraciones corren con `04-actualizar.sh`.
- **El dueño, en el local:**
  1. autorizar la portátil y registrar la red;
  2. crear o completar el perfil laboral de cada persona (modo de pago, tarifa, horario y PIN);
  3. firmar el consentimiento y registrar el rostro;
  4. dar *Asistencia y sueldos* a quien corresponda (nadie lo tiene por defecto).
- Las 2 primeras semanas, abrir la caja solo avisa (D4). Después se revisa la bandeja y se activa el bloqueo.

---

**Fuentes:** recargo de horas extra y divisor 240 ([Deel](https://deel.com/es/blog/calcular-pago-horas-extra-bolivia/));
jornada y tope de 2 h extra ([Jibble](https://www.jibble.io/es/legislacion-laboral/bolivia/horas-extras));
salario mínimo 2026 ([SWI swissinfo / EFE](https://www.swissinfo.ch/spa/el-presidente-rodrigo-paz-sube-un-20-%25-el-salario-m%C3%ADnimo-en-bolivia/90657848));
precios de Rekognition ([AWS](https://aws.amazon.com/rekognition/pricing/)); librería Human
([GitHub](https://github.com/vladmandic/human)) y `face-api` archivada ([ecosyste.ms](https://awesome.ecosyste.ms/api/v1/projects/github.com%2Fvladmandic%2Fface-api)).
