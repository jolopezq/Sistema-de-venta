#!/bin/bash
# ==============================================================================
# Ohana POS — Conexión directa desde Mac (Red de Casa)
# ==============================================================================
# Coloca este archivo en tu Mac y haz doble clic cuando estés en casa
# para acceder al sistema POS de manera rápida y sin túneles.

# Configuración
SERVER_IP="192.168.1.9"
FRONTEND_PORT="5173"

# Colores para terminal
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${CYAN}====================================================${NC}"
echo -e "${GREEN}  🌺 OHANA AÇAÍ POS — Acceso desde Mac (Casa) 🌺${NC}"
echo -e "${CYAN}====================================================${NC}"
echo -e "${YELLOW}Buscando servidor en $SERVER_IP...${NC}"

# Ping al servidor para verificar si está activo
if ping -c 1 -W 2 "$SERVER_IP" &>/dev/null; then
    echo -e "${GREEN}✓ Servidor encontrado en la red de casa${NC}"
    echo -e "${CYAN}Abriendo Punto de Venta en tu navegador por defecto...${NC}"
    
    # Abre en el navegador (macOS)
    open "http://${SERVER_IP}:${FRONTEND_PORT}"
    
    echo -e "\n${YELLOW}Puedes cerrar esta ventana.${NC}"
    sleep 3
else
    echo -e "\n${RED}✗ No se pudo conectar a $SERVER_IP${NC}"
    echo -e "\n${YELLOW}Verifica lo siguiente:${NC}"
    echo "  1. ¿Estás conectado al Wi-Fi de tu casa?"
    echo "  2. ¿El PC Windows está encendido y tiene el POS abierto?"
    echo "  3. ¿La IP del PC Windows sigue siendo $SERVER_IP?"
    echo -e "\nPresiona Enter para salir..."
    read -r
fi
