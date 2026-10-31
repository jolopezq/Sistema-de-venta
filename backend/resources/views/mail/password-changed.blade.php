<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tu contraseña se cambió</title></head>
<body style="margin:0;padding:24px 12px;background:#f6f1ea;font-family:Arial,Helvetica,sans-serif;color:#2a1838;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:16px;">
    <tr><td style="padding:28px;">
      <p style="margin:0 0 4px;font-size:13px;font-weight:bold;color:#7d4a8f;letter-spacing:.04em;text-transform:uppercase;">Ohana POS</p>
      <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#2a1838;">Tu contraseña se cambió</h1>
      <p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Hola {{ $user->name }}, la contraseña de tu cuenta se cambió el {{ $when }} con un código enviado a este correo. Se cerraron las sesiones que tenías abiertas.</p>
      <p style="margin:0;font-size:14px;line-height:1.5;color:#5c4a68;">Si no fuiste tú, avisa al dueño de inmediato y cambia también la contraseña de este correo.</p>
    </td></tr>
  </table>
</body>
</html>
