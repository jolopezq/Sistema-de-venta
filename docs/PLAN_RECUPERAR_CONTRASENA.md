# Recuperar la contraseña con un código por correo

> **Pedido del dueño (2026-10-30):** poder recuperar la contraseña desde el login con un código enviado al correo, como segundo factor.
> **Alcance decidido:** solo recuperar la contraseña. El inicio de sesión normal no cambia.
> **Envío:** Resend, desde el dominio propio `ohana-pos.com`, por su API HTTPS.
> **Estado:** implementado y probado el 2026-10-30, sin commit. Falta que el dueño configure Resend (sección 4) y correr `php artisan test` en la Mac (sección 5).

---

## 1. Cómo funciona para quien olvidó la contraseña

1. En el login, debajo de la contraseña, pulsa **«¿Olvidaste tu contraseña?»**. El correo que ya había escrito pasa a la siguiente pantalla.
2. **Paso 1 de 3:** confirma el correo y pulsa **Enviar código**.
3. **Paso 2 de 3:** le llega un correo «Tu código para cambiar la contraseña de Ohana POS» con 6 dígitos.
   - El código vence en 15 minutos y sirve una sola vez.
   - Puede pegarlo aunque venga con espacios o texto: el campo deja solo los dígitos.
   - «Reenviar código» se habilita a los 60 segundos.
4. **Paso 3 de 3:** escribe dos veces la contraseña nueva (mínimo 6 caracteres, con el botón para verla) y pulsa **Guardar contraseña**.
5. Vuelve al login con «Listo: tu contraseña se cambió» y su correo ya escrito. Recibe además el correo «Tu contraseña de Ohana POS se cambió».

---

## 2. Seguridad (decisiones)

| Riesgo | Decisión |
| :-- | :-- |
| Averiguar qué correos tienen cuenta | «Enviar código» responde lo mismo exista o no el correo. Un código equivocado da el mismo error que un correo inexistente. |
| Adivinar el código (1 en un millón) | Máximo 5 intentos por código; después se anula. Además, 10 verificaciones por minuto por IP y 15 por correo cada 15 minutos. |
| Llenar la bandeja de alguien | Como mucho un correo por minuto por usuario y 3 pedidos por correo e IP cada 15 minutos (10 por hora por IP). Si se supera, el mensaje es «Demasiados intentos. Espera unos minutos y vuelve a intentar.» |
| Robo de la base de datos | Del código y del permiso de cambio solo se guarda una huella HMAC-SHA256 con `APP_KEY`, nunca el valor. |
| Código interceptado después de usarse | El código se canjea por un permiso de un solo uso, que vence en 10 minutos. Al cambiar la contraseña se anulan todos los códigos pendientes. |
| Alguien con la sesión abierta (o un robo de sesión) | Al cambiar la contraseña se cierran **todas** las sesiones del usuario (tokens de Sanctum). |
| Cambio sin que el dueño de la cuenta se entere | Correo de aviso «Tu contraseña se cambió». Auditoría: `password_reset_requested`, `password_reset_completed`, `password_reset_code_locked` y `password_reset_mail_failed`. |
| El código visible en la pantalla bloqueada del celular | El código no va en el asunto del correo. |
| Usuarios eliminados | No reciben código: la búsqueda excluye cuentas borradas. |

**Contraseña mínima:** 6 caracteres, igual que cuando el administrador la restablece (`UserController::resetPassword`), porque hay cajeros con PIN.

---

## 3. Qué se cambió

### Backend

| Archivo | Qué hace |
| :-- | :-- |
| `database/migrations/2026_10_30_000001_create_password_reset_codes_table.php` | Tabla `password_reset_codes`: usuario, huella del código, intentos, vencimiento, huella del permiso, verificado/usado e IP. Las actualizaciones del VPS y de la laptop corren `migrate` solas. |
| `app/Services/PasswordResetService.php` | La lógica: pedir, verificar y cambiar. |
| `app/Http/Controllers/PasswordResetController.php` + `routes/api.php` | Rutas públicas con límite de intentos:<br>• `POST /api/password/code` (`email`)<br>• `POST /api/password/verify` (`email`, `code`) → `reset_token`<br>• `POST /api/password/reset` (`email`, `reset_token`, `password`, `password_confirmation`) |
| `app/Providers/AppServiceProvider.php` | Límites `password-code` y `password-verify`, y el registro del envío `resend-api`. |
| `app/Mail/Transport/ResendApiTransport.php` + `config/mail.php` (mailer `resend-api`) | Envío por `POST https://api.resend.com/emails` con el cliente HTTP de Laravel. **No agrega paquetes**, porque la laptop no corre `composer install` al actualizarse. **No usa puertos SMTP**, porque el VPS puede tenerlos bloqueados. Envía la versión HTML y la de texto y una `Idempotency-Key`. Si Resend rechaza el correo, el error queda claro en el log, por ejemplo «Resend rechazó el correo (403): …». |
| `app/Mail/PasswordResetCodeMail.php`, `PasswordChangedMail.php` + `resources/views/mail/*.blade.php` | Los dos correos, en HTML y texto, con la marca. |
| `app/Console/Commands/ProbarCorreo.php` | `php artisan ohana:probar-correo tu@correo.com`: comprueba la configuración. |
| `.env.example` | `RESEND_API_KEY` y la explicación. |
| `tests/Feature/PasswordResetTest.php`, `ResendApiTransportTest.php` | 15 pruebas: flujo completo, sesiones cerradas, misma respuesta, intentos, vencimientos, reenvío, límites, fallo del correo y formato del envío a Resend. |

### Frontend

| Archivo | Qué hace |
| :-- | :-- |
| `src/views/RecoverPassword.vue` (ruta `/recuperar-contrasena`, pública) | Las 3 pantallas, con el mismo diseño del login (claro y oscuro):<br>• Avisos junto a cada campo y foco en el campo de cada paso.<br>• `autocomplete="one-time-code"`, para que el celular ofrezca el código.<br>• Al 125 % se oculta el logo para que el botón quede a la vista. |
| `src/views/Login.vue` | • Enlace «¿Olvidaste tu contraseña?».<br>• Aviso verde al volver.<br>• Correo ya escrito.<br>• **Las cuentas de ejemplo (`admin@example.com` / `password`) ya no aparecen escritas en producción**; solo en desarrollo. |
| `src/router/index.js` | La ruta nueva. Con la sesión abierta lleva a la pantalla de inicio. |
| `e2e/recuperar-contrasena.spec.js` | 12 pruebas con la API simulada, incluidas 1366 × 611, 1093 × 489 y 390 × 844. |

---

## 4. Configurar Resend (lo hace el dueño, una sola vez)

> **La clave de Resend no se pega en el chat ni se sube al repositorio.** Va solo en el `.env` de cada máquina.

### 4.1 Cuenta y dominio

1. Crea una cuenta en [resend.com](https://resend.com).
2. En **Domains → Add domain**, escribe **`avisos.ohana-pos.com`**. Resend recomienda un subdominio para enviar; así el correo de `ohana-pos.com` no se toca. Para la región, elige **South America (São Paulo)** si aparece; si no, la que venga por defecto.
3. Pulsa **Sign in to Cloudflare** y autoriza. Resend agrega solo los registros DNS en Cloudflare.
4. Si prefieres hacerlo a mano, crea en Cloudflare → DNS los registros que muestra Resend:
   - **MX** `send.avisos`, con el servidor que indica Resend y prioridad 10.
   - **TXT** `send.avisos`, con `v=spf1 include:amazonses.com ~all`.
   - **TXT** `resend._domainkey.avisos`, con la clave DKIM que muestra Resend, en **DNS only** (nube gris).
5. Pulsa **Verify DNS Records** y espera a que diga *Verified*. Suelen ser minutos.
6. En **API Keys → Create API key**, elige el permiso **Sending access**, limitado al dominio `avisos.ohana-pos.com`. Copia la clave (empieza con `re_`): **se muestra una sola vez**.

### 4.2 VPS (`pos.ohana-pos.com`)

Primero publica el código (sección 6). Luego, desde la Terminal de la Mac:

```bash
ssh root@64.176.3.152
nano /var/www/ohana-pos/app/backend/.env
```

En el `.env`, cambia o agrega estas líneas. Deja `MAIL_MAILER=log` fuera: debe quedar `resend-api`.

```
MAIL_MAILER=resend-api
MAIL_FROM_ADDRESS="no-responder@avisos.ohana-pos.com"
MAIL_FROM_NAME="Ohana Açaí"
RESEND_API_KEY=pega_aqui_la_clave
```

Guarda con Ctrl+O, Enter y Ctrl+X. Luego:

```bash
cd /var/www/ohana-pos/app/backend
sudo -u ohana php artisan config:cache
sudo -u ohana php artisan ohana:probar-correo tu_correo@gmail.com
```

Debe decir «Enviado» y llegarte «Prueba de correo de Ohana POS» (revisa Spam la primera vez). Si dice «Resend rechazó el correo (403)…», el dominio todavía no está verificado o la clave es de otro dominio.

### 4.3 Laptop de caja (Windows)

Necesita internet para enviar el correo. Sin conexión, la recuperación no funciona: el administrador puede restablecer la contraseña desde **Usuarios**.

1. Abre `backend\.env` de la carpeta del sistema con el Bloc de notas y cambia las mismas 4 líneas. Puedes crear otra clave en Resend para la laptop y así revocarla por separado.
2. Ejecuta `actualizar-pos.bat`, que limpia la caché de configuración. También puedes correr `C:\Ohana-POS-Windows\php\php.exe artisan config:clear` en `backend`.
3. Prueba con `php artisan ohana:probar-correo tu_correo@gmail.com`, usando el `php.exe` de la ruta anterior.

### 4.4 Correos reales en las cuentas

El código va al correo de la cuenta. Las cuentas de ejemplo (`cashier@example.com`, `admin@example.com`…) no reciben nada: cambia sus correos en **Usuarios**, o quítalas si no se usan. El super admin del VPS ya usa `jolopez.freelancer@gmail.com`.

---

## 5. Pruebas hechas (2026-10-30)

- **Backend real** (PHP 8.4, copia de la base de la Mac, `MAIL_MAILER=log` para leer el código del correo): **22 de 22 comprobaciones**.
  - Misma respuesta para correos existentes e inexistentes.
  - Solo se guarda la huella del código.
  - No se reenvía antes de 60 s.
  - Mismo error para código equivocado y correo inexistente.
  - El código y el permiso no sirven dos veces.
  - Validaciones de la contraseña.
  - Las sesiones abiertas quedan cerradas (401).
  - La contraseña vieja ya no entra y la nueva sí.
  - Hay auditoría.
  - Bloqueo tras 5 intentos.
  - Límite de pedidos (429 con mensaje en español).
- **Envío a Resend** (con `Http::fake`): 11 de 11. Destino, clave `Bearer`, `Idempotency-Key`, remitente «Ohana Açaí <…>» sin comillas, el código en HTML y texto pero no en el asunto, y errores claros (403 o falta de clave).
- **Navegador contra el backend real:** 51 de 51 comprobaciones, de punta a punta, en tres perfiles:
  - 1366 × 611, claro;
  - 1093 × 489 con escala 1,25, oscuro;
  - 390 × 844, claro.

  En cada perfil se revisó: foco en cada paso, aviso del código equivocado, código pegado con espacios, confirmación distinta, mostrar contraseña, vuelta al login con el aviso, entrar con la contraseña nueva, botón a la vista y contraste AA.
- **E2E con API simulada:** 12 nuevas. La suite completa da 185 en verde, más las 3 de teléfono que necesitan el backend. `npx vite build` sin errores.
- **Pendiente:** correr las pruebas de PHPUnit en la Mac. En la nube no se pudieron correr, porque PHP en WebAssembly se cae con `RefreshDatabase`; la misma lógica sí se probó contra el backend real (arriba).

  ```bash
  cd backend && php artisan test --filter='PasswordResetTest|ResendApiTransportTest' && php artisan test
  ```

---

## 6. Publicar

Solo código y una migración, sin paquetes nuevos.

1. Commit en una rama propia. La rama `feat/inventario-portatil` tiene cambios de otra sesión sin commit: no mezclarlos. Los archivos de esta función no se cruzan con esos, salvo `CHANGELOG.md` y `docs/BITACORA.md`.
2. **VPS:** paquete y `deploy/vps/04-actualizar.sh`, que corre `migrate`. Luego, la sección 4.2.
3. **Laptop:** `git push origin main` cuando el dueño lo decida (el vigilante actualiza y migra). Luego, la sección 4.3.
4. Hasta configurar Resend, la recuperación responde normal pero el correo no sale: con `MAIL_MAILER=log` el código queda solo en `storage/logs/laravel.log`.
