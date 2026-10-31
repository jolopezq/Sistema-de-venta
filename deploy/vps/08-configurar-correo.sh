#!/usr/bin/env bash
# Ohana POS · Activar el envío de correos con Resend en el VPS (códigos para recuperar la contraseña).
# Pide la clave de Resend en la pantalla (no se ve al pegar ni queda en el registro), la guarda solo
# en el .env del servidor, recarga la configuración y envía un correo de prueba. Se puede volver a
# ejecutar: para cambiar la clave o solo para probar (Enter sin pegar nada mantiene la clave guardada).
#
# Antes, en resend.com: dominio avisos.ohana-pos.com verificado y una API key con «Sending access»
# (docs/PLAN_RECUPERAR_CONTRASENA.md, sección 4.1).
#
# Uso (en la Terminal de la Mac, dentro de la carpeta del proyecto). Es interactivo, por eso se copia primero:
#   scp deploy/vps/08-configurar-correo.sh root@64.176.3.152:/root/
#   ssh -t root@64.176.3.152 'bash /root/08-configurar-correo.sh' 2>&1 | tee deploy/vps/logs/08-configurar-correo.log

set -euo pipefail
export LC_ALL=C.UTF-8 LANG=C.UTF-8

BACK="${OHANA_BACKEND:-/var/www/ohana-pos/app/backend}"
ENV_FILE="$BACK/.env"
REMITENTE=no-responder@avisos.ohana-pos.com
NOMBRE='Ohana Açaí'
PRUEBA=jolopez.freelancer@gmail.com

paso() { printf '\n==> %s\n' "$*"; }
artisan() { (cd "$BACK" && sudo -u ohana -H --preserve-env=LC_ALL,LANG php artisan "$@"); }
# poner NOMBRE VALOR: cambia la línea NOMBRE=… del .env, o la agrega al final, sin tocar dueño ni permisos.
# El valor viaja por el entorno de awk, no por la línea de comandos (no aparece en `ps`).
poner() {
  local tmp
  tmp="$(mktemp)"
  K="$1" V="$2" awk '
    index($0, ENVIRON["K"] "=") == 1 { if (!listo) print ENVIRON["K"] "=" ENVIRON["V"]; listo = 1; next }
    { print }
    END { if (!listo) print ENVIRON["K"] "=" ENVIRON["V"] }' "$ENV_FILE" > "$tmp"
  cat "$tmp" > "$ENV_FILE"
  rm -f "$tmp"
}

[ "$(id -u)" -eq 0 ] || { echo "ERROR: hay que ejecutarlo como root"; exit 1; }
[ -f "$ENV_FILE" ] || { echo "ERROR: no existe $ENV_FILE"; exit 1; }
{ : < /dev/tty; } 2>/dev/null || { echo "ERROR: no hay pantalla para escribir la clave: ejecútalo con ssh -t"; exit 1; }
umask 077

paso "Clave de Resend"
TIENE=no
if grep -qE '^RESEND_API_KEY=re_' "$ENV_FILE"; then
  TIENE=si
  echo "Ya hay una clave guardada. Enter sin pegar nada la mantiene."
fi
CLAVE=""
read -rs -p "API key de Resend, empieza con re_ (no se ve al pegar): " CLAVE < /dev/tty
echo
CLAVE="${CLAVE//[[:space:]]/}"
if [ -z "$CLAVE" ]; then
  [ "$TIENE" = si ] || { echo "ERROR: no se pegó ninguna clave. No se cambió nada."; exit 1; }
  echo "Se mantiene la clave guardada."
elif [[ ! "$CLAVE" =~ ^re_[A-Za-z0-9_-]{8,}$ ]]; then
  echo "ERROR: eso no parece una clave de Resend (empieza con re_). No se cambió nada."
  exit 1
else
  echo "Clave recibida (${#CLAVE} caracteres)."
fi

paso "Respaldo del .env"
RESPALDO="$ENV_FILE.antes-de-correo-$(date +%Y%m%d-%H%M%S)"
cp -p "$ENV_FILE" "$RESPALDO"
chmod 600 "$RESPALDO"
echo "$RESPALDO"

paso "Correo en el .env"
poner MAIL_MAILER resend-api
poner MAIL_FROM_ADDRESS "\"$REMITENTE\""
poner MAIL_FROM_NAME "\"$NOMBRE\""
if [ -n "$CLAVE" ]; then
  poner RESEND_API_KEY "$CLAVE"
fi
CLAVE=""
grep -E '^(MAIL_MAILER|MAIL_FROM_ADDRESS|MAIL_FROM_NAME)=' "$ENV_FILE"
echo "RESEND_API_KEY: puesta"
ls -l "$ENV_FILE"

paso "Recargar la configuración"
artisan config:cache
systemctl reload php8.4-fpm

paso "Correo de prueba"
DESTINO=""
read -r -p "¿A qué correo envío la prueba? [Enter = $PRUEBA]: " DESTINO < /dev/tty
DESTINO="${DESTINO:-$PRUEBA}"
if artisan ohana:probar-correo "$DESTINO"; then
  echo
  echo "CORREO ACTIVO. Revisa $DESTINO (y Spam): debe llegar «Prueba de correo de Ohana POS»."
  echo "Después pide el código en https://pos.ohana-pos.com/recuperar-contrasena"
else
  echo
  echo "EL CORREO NO SALIÓ. Según el mensaje de arriba:"
  echo "  · «domain is not verified»: el dominio avisos.ohana-pos.com aún no está verificado en resend.com → Domains."
  echo "  · «API key is invalid» o «restricted»: la clave está mal copiada o es de otro dominio; vuelve a ejecutar este script."
  echo "La configuración queda puesta: apenas Resend verifique el dominio, los códigos empiezan a salir solos."
  echo "Para volver a como estaba: cat '$RESPALDO' > '$ENV_FILE' && cd $BACK && sudo -u ohana php artisan config:cache && systemctl reload php8.4-fpm"
  exit 1
fi
