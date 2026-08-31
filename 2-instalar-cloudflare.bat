@echo off
title Instalacion de Cloudflare Tunnel
color 0B

echo ================================================================
echo       Instalando Servicio de Cloudflare Tunnel
echo ================================================================
echo.

rem Verificar si se esta ejecutando como Administrador
net session >nul 2>&1
if %errorLevel% neq 0 (
    color 0C
    echo [ERROR] Se requieren permisos de Administrador para instalar el servicio.
    echo.
    echo Por favor, cierra esta ventana, haz clic derecho sobre este archivo
    echo y selecciona: "Ejecutar como administrador"
    echo.
    pause
    exit /b 1
)

echo [1/3] Descargando la ultima version del instalador...
powershell -Command "Invoke-WebRequest -Uri 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.msi' -OutFile 'cloudflared-windows-amd64.msi'"

echo [2/3] Instalando Cloudflared silenciosamente (Por favor espera)...
start /wait msiexec /i "%~dp0cloudflared-windows-amd64.msi" /quiet /qn /norestart

rem Verificar donde se instalo
set "CLOUDFLARED_BIN=cloudflared.exe"
if exist "C:\Program Files (x86)\cloudflared\cloudflared.exe" (
    set "CLOUDFLARED_BIN=C:\Program Files (x86)\cloudflared\cloudflared.exe"
) else if exist "C:\Program Files\cloudflared\cloudflared.exe" (
    set "CLOUDFLARED_BIN=C:\Program Files\cloudflared\cloudflared.exe"
)

echo.
echo [3/3] Configurando e instalando el servicio...
echo Ruta detectada: %CLOUDFLARED_BIN%

rem Limpiar instalacion anterior si existe
"%CLOUDFLARED_BIN%" service uninstall >nul 2>&1

rem Instalar nuevo servicio
"%CLOUDFLARED_BIN%" service install eyJhIjoiODRkZGFiZDMzMGUyODMxODViNjNmYzZmNTA5NjY4YTQiLCJ0IjoiMGIxZTJkMGUtOGNiOS00NjlmLWJlZTktNWYyN2I2MGYyOGQ1IiwicyI6IlkyWmlNamRqTVRjdFpqSTRNQzAwT1RGbExUazRNV0V0TVRjd09UbG1abVZqTVdFNCJ9

if %errorLevel% equ 0 (
    color 0A
    echo.
    echo ================================================================
    echo OK - EL SERVICIO DE CLOUDFLARE SE HA INSTALADO CORRECTAMENTE!
    echo ================================================================
) else (
    color 0C
    echo.
    echo [ERROR] Ocurrio un error al instalar el servicio de Cloudflare.
    echo Codigo de error: %errorLevel%
)

echo.
pause
