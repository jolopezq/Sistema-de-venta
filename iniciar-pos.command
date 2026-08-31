#!/bin/bash

# ==============================================================================
# Ohana Açaí POS - Script de Inicio Automático (1 Clic)
# ==============================================================================

# Obtener directorio del script
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$DIR"

# Colores para terminal
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${CYAN}====================================================${NC}"
echo -e "${GREEN}     🌺 INICIANDO OHANA AÇAÍ POS - SISTEMA DE VENTAS 🌺${NC}"
echo -e "${CYAN}====================================================${NC}"

# 1. Limpiar procesos anteriores si quedaron abiertos
echo -e "\n${YELLOW}[1/4] Liberando puertos 8000 y 5173...${NC}"
lsof -ti:8000 | xargs kill -9 2>/dev/null
lsof -ti:5173 | xargs kill -9 2>/dev/null
pkill -f "cloudflared tunnel" 2>/dev/null
pkill -f "ngrok http" 2>/dev/null
sleep 1

# 2. Iniciar Backend (Laravel)
echo -e "${YELLOW}[2/4] Iniciando Backend (Laravel API :8000)...${NC}"
cd "$DIR/backend"
export PHP_CLI_SERVER_WORKERS=8
php artisan serve --host=0.0.0.0 --port=8000 --no-reload > "$DIR/.backend.log" 2>&1 &
BACKEND_PID=$!

# 3. Iniciar Frontend (Vue + Vite)
echo -e "${YELLOW}[3/4] Iniciando Frontend (Vue PWA :5173)...${NC}"
cd "$DIR/frontend"
npm run dev > "$DIR/.frontend.log" 2>&1 &
FRONTEND_PID=$!

# Función de limpieza al salir (Ctrl + C o cerrar ventana)
cleanup() {
    echo -e "\n${RED}Deteniendo todos los servicios de Ohana POS...${NC}"
    kill $BACKEND_PID 2>/dev/null
    kill $FRONTEND_PID 2>/dev/null
    pkill -f "cloudflared tunnel" 2>/dev/null
    pkill -f "ngrok http" 2>/dev/null
    echo -e "${GREEN}✓ Servicios detenidos con éxito.${NC}"
    exit 0
}
trap cleanup SIGINT SIGTERM EXIT

# Esperar 2 segundos a que inicien los servidores
sleep 2

# 4. Iniciar Túnel Público
echo -e "${YELLOW}[4/4] Levantando enlace seguro para compartir...${NC}"

# Verificar si hay configuración de dominio estático en .tunnel_config
CONFIG_FILE="$DIR/.tunnel_config"
STATIC_DOMAIN=""
if [ -f "$CONFIG_FILE" ]; then
    source "$CONFIG_FILE"
fi

echo -e "\n${GREEN}====================================================${NC}"
echo -e "${GREEN}  ✓ ¡SISTEMA POS LISTO Y OPERATIVO!${NC}"
echo -e "${GREEN}====================================================${NC}"
echo -e "${CYAN}• Acceso Local en tu Mac:${NC}     http://localhost:5173"

# Mostrar IP local en la red Wi-Fi
LOCAL_IP=$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || echo "192.168.1.x")
echo -e "${CYAN}• Acceso Wi-Fi (Tablet/Móvil):${NC} http://${LOCAL_IP}:5173"

if [ -n "$NGROK_DOMAIN" ]; then
    echo -e "${CYAN}• URL Pública Fija (Ngrok):${NC}    https://${NGROK_DOMAIN}"
    echo -e "${YELLOW}(Para detener el sistema presiona Ctrl + C en esta ventana)${NC}\n"
    ngrok http 5173 --domain="$NGROK_DOMAIN"
else
    echo -e "${YELLOW}• Iniciando túnel público de Cloudflare...${NC}"
    echo -e "${YELLOW}(Para detener el sistema presiona Ctrl + C en esta ventana)${NC}\n"
    cloudflared tunnel --url http://localhost:5173
fi
