@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul
title Ohana POS - Vigilante de Actualizaciones Automáticas
color 0B

cd /d "%~dp0"

echo ================================================================
echo     🌺 OHANA AÇAÍ POS - VIGILANTE DE ACTUALIZACIONES 🌺
echo ================================================================
echo.
echo Iniciando monitoreo de cambios en GitHub...
echo (Este proceso revisa el repositorio periódicamente y actualiza
echo  el sistema automáticamente cada vez que hagas un push).
echo.
echo Para cerrarlo, simplemente cierra esta ventana o presiona Ctrl+C.
echo ================================================================
echo.

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0vigilante-actualizaciones.ps1" %*

if %errorLevel% neq 0 (
    echo.
    echo [AVISO] El vigilante ha finalizado o fue interrumpido.
    pause
)
