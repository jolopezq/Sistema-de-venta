@echo off
chcp 65001 >nul
title Detener Ohana POS
color 0C

echo ================================================================
echo          🌺 OHANA AÇAÍ POS - DETENER SERVICIOS 🌺
echo ================================================================
echo.
echo Deteniendo procesos del servidor en los puertos 8000 y 5173...

for /f "tokens=5" %%p in ('netstat -aon ^| findstr /r ":8000.*LISTENING"') do (
    taskkill /f /pid %%p >nul 2>&1
)
for /f "tokens=5" %%p in ('netstat -aon ^| findstr /r ":5173.*LISTENING"') do (
    taskkill /f /pid %%p >nul 2>&1
)

echo.
echo   ✓ Todos los servicios han sido detenidos correctamente.
echo.
echo ================================================================
ping 127.0.0.1 -n 3 >nul
exit
