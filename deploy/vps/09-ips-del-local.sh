#!/usr/bin/env bash
# Ohana POS · ¿Con qué IP entra el local? (docs/PLAN_ASISTENCIA_Y_SUELDOS.md, F0)
# Lee los registros de acceso de Nginx del VPS (los de hoy y los rotados que queden, normalmente 14 días) y resume,
# por día, qué IPs usaron el POS (`/api/sales/sync`, `/api/login`, `/api/me`, `/api/catalog`). Solo lee: no cambia
# nada en el servidor. Con la salida se sabe si la IP del local es fija, si usa IPv6 y si cambia de noche.
#
# Uso (en la Terminal de la Mac, dentro de la carpeta del proyecto):
#   ssh root@64.176.3.152 'bash -s' < deploy/vps/09-ips-del-local.sh 2>&1 | tee deploy/vps/logs/09-ips-del-local.log
#
# Cómo leerlo: el local es la IP que aparece todos los días con muchas ventas sincronizadas. Si el prefijo cambia de un
# día a otro, la IP es dinámica (el reloj de asistencia lo resuelve con «Es la red del local»). Una IPv6 se compara por
# su prefijo /64 (las cuatro primeras partes), que es lo que se muestra en la última columna.

set -euo pipefail
export LC_ALL=C.UTF-8 LANG=C.UTF-8

LOGS="${OHANA_NGINX_LOGS:-/var/log/nginx}"
[ -d "$LOGS" ] || { echo "ERROR: no existe $LOGS"; exit 1; }

leer() {
  for f in "$LOGS"/access.log*; do
    [ -e "$f" ] || continue
    case "$f" in
      *.gz) zcat -- "$f" ;;
      *) cat -- "$f" ;;
    esac
  done
}

echo "==> Registros leídos:"
ls -1 "$LOGS"/access.log* 2>/dev/null | sed 's#^#    #'
echo
echo "==> Uso del POS por día e IP (día · IP · pedidos · ventas sincronizadas · red para el reloj)"
leer | awk '
  BEGIN { split("Jan Feb Mar Apr May Jun Jul Aug Sep Oct Nov Dec", m, " "); for (i = 1; i <= 12; i++) mes[m[i]] = sprintf("%02d", i) }
  $7 ~ /^\/api\/(sales\/sync|login|me|catalog)/ {
    split(substr($4, 2), t, ":"); split(t[1], f, "/")
    clave = f[3] "-" mes[f[2]] "-" f[1] " " $1
    total[clave]++
    if ($7 ~ /^\/api\/sales\/sync/) sync[clave]++
  }
  END {
    for (c in total) {
      split(c, p, " ")
      red = p[2]
      if (index(red, ":") > 0) { split(red, g, ":"); red = g[1] ":" g[2] ":" g[3] ":" g[4] "::/64" } else { red = red "/32" }
      printf "%s  %-40s  %7d  %7d  %s\n", p[1], p[2], total[c], sync[c] + 0, red
    }
  }' | sort
echo
echo "==> IPs distintas en todo el período (las que más usan el POS primero)"
leer | awk '$7 ~ /^\/api\/sales\/sync/ { n[$1]++ } END { for (ip in n) printf "%7d  %s\n", n[ip], ip }' | sort -rn | head -15
