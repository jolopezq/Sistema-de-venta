#!/bin/bash
# ==============================================================================
# Ohana POS — Conexión desde Mac
# ==============================================================================
# Haz doble clic para conectarte al servidor Windows de Ohana POS.
# Intenta primero por red local (ultrarrápido), y si no hay conexión,
# ofrece abrir el acceso remoto por internet.

# Configuración
SERVER_IP="192.168.1.9"
SERVER_PORT="8000"
REMOTE_URL="https://www.pos.ohana.com"

# Colores para terminal
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${CYAN}====================================================${NC}"
echo -e "${GREEN}  🌺 OHANA AÇAÍ POS — Conectando desde Mac 🌺${NC}"
echo -e "${CYAN}====================================================${NC}"
echo -e "${YELLOW}Buscando servidor en red local ($SERVER_IP)...${NC}"

# Intentar conexión por red local (LAN)
if ping -c 1 -W 2 "$SERVER_IP" &>/dev/null; then
    echo -e "${GREEN}✓ Servidor encontrado en la red local${NC}"
    echo -e "${CYAN}Abriendo Ohana POS en tu navegador...${NC}"
    open "http://${SERVER_IP}:${SERVER_PORT}"
    echo -e "\n${YELLOW}Puedes cerrar esta ventana.${NC}"
    sleep 3
else
    echo -e "\n${RED}✗ No hay conexión local con $SERVER_IP${NC}"
    echo -e "${YELLOW}Puede que estés fuera de casa o que la Windows esté apagada.${NC}\n"
    echo -e "Opciones:"
    echo -e "  ${CYAN}[1]${NC} Abrir por internet: ${GREEN}$REMOTE_URL${NC}"
    echo -e "  ${CYAN}[2]${NC} Salir"
    echo ""
    read -rp "Selecciona una opción [1/2]: " OPT
    if [[ "$OPT" == "1" ]]; then
        echo -e "${CYAN}Abriendo acceso remoto...${NC}"
        open "$REMOTE_URL"
        sleep 2
    else
        echo -e "${YELLOW}Hasta pronto.${NC}"
    fi
fi
