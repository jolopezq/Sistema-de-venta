@echo off
chcp 65001 >nul
title Ohana Acai POS - Configuracion de Firewall
color 0B

echo ================================================================
echo    🌺 OHANA AÇAÍ POS - CONFIGURACIÓN DE RED Y FIREWALL 🌺
echo ================================================================
echo.
echo Este script permitirá que las Tablets y celulares Android con la
echo App de Comandas se conecten al POS a través de la red WiFi local.
echo.

:: Verificar si se está ejecutando como Administrador
net session >nul 2>&1
if %errorLevel% neq 0 (
    color 0C
    echo [ERROR] Se requieren permisos de Administrador.
    echo.
    echo Por favor, haz clic derecho sobre este archivo y selecciona:
    echo "Ejecutar como administrador"
    echo.
    pause
    exit /b 1
)

echo [1/2] Creando reglas de Firewall para puertos 8000 (API) y 5173 (Frontend)...
netsh advfirewall firewall delete rule name="Ohana POS Server (8000)" >nul 2>&1
netsh advfirewall firewall add rule name="Ohana POS Server (8000)" dir=in action=allow protocol=TCP localport=8000 profile=private,domain >nul

netsh advfirewall firewall delete rule name="Ohana POS Frontend (5173)" >nul 2>&1
netsh advfirewall firewall add rule name="Ohana POS Frontend (5173)" dir=in action=allow protocol=TCP localport=5173 profile=private,domain >nul

if %errorLevel% equ 0 (
    color 0A
    echo       ✓ Reglas de Firewall creadas con éxito.
) else (
    color 0C
    echo       [ADVERTENCIA] No se pudieron crear automáticamente las reglas.
)

echo.
echo [2/2] Obteniendo dirección IP local de esta computadora...
echo ----------------------------------------------------------------
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do (
    echo   Dirección IP detectada: %%a
)
echo ----------------------------------------------------------------
echo.
echo ¡Configuración completada!
echo Ahora las tablets podrán conectarse usando tu IP local en el puerto 8000.
echo.
pause
