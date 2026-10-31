<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Código para cambiar tu contraseña</title></head>
<body style="margin:0;padding:24px 12px;background:#f6f1ea;font-family:Arial,Helvetica,sans-serif;color:#2a1838;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:16px;">
    <tr><td style="padding:28px 28px 8px;">
      <p style="margin:0 0 4px;font-size:13px;font-weight:bold;color:#7d4a8f;letter-spacing:.04em;text-transform:uppercase;">Ohana POS</p>
      <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#2a1838;">Código para cambiar tu contraseña</h1>
      <p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Hola {{ $user->name }}, escribe este código en la pantalla de recuperación:</p>
      <p style="margin:0 0 16px;padding:14px 0;text-align:center;font-size:34px;font-weight:bold;letter-spacing:8px;background:#f6f1ea;border-radius:12px;color:#2a1838;">{{ $code }}</p>
      <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">Vence en {{ $minutes }} minutos y sirve una sola vez.</p>
      <p style="margin:0 0 24px;font-size:13px;line-height:1.5;color:#5c4a68;">Si no lo pediste, ignora este correo: tu contraseña no cambia. Nadie de Ohana te pedirá este código.</p>
    </td></tr>
  </table>
</body>
</html>
