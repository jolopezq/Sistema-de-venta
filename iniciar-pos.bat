@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul
title Ohana Acai POS - Sistema de Ventas y Comandas
color 0A

cd /d "%~dp0"

echo ================================================================
echo      🌺 OHANA AÇAÍ POS - SISTEMA DE PUNTO DE VENTA 🌺
echo ================================================================
echo.

:: 1. Liberar puertos 8000 y 5173 si quedaron tomados
echo [1/4] Verificando disponibilidad de puertos (8000 y 5173)...
for /f "tokens=5" %%p in ('netstat -aon ^| findstr /r ":8000.*LISTENING"') do (
    taskkill /f /pid %%p >nul 2>&1
)
for /f "tokens=5" %%p in ('netstat -aon ^| findstr /r ":5173.*LISTENING"') do (
    taskkill /f /pid %%p >nul 2>&1
)

:: 2. Detectar IP Local para Comandas y Tablets
echo [2/4] Detectando IP en la red local...

set "LOCAL_IP="
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do (
    if not defined LOCAL_IP set "LOCAL_IP=%%a"
)
set "LOCAL_IP=%LOCAL_IP: =%"

:: Leer configuracion de red de casa si existe
set "HOME_PREFIX="
if exist "%~dp0home-network.conf" (
    for /f "tokens=1,2 delims==" %%A in ('type "%~dp0home-network.conf" ^| findstr /v "^#"') do (
        if "%%A"=="HOME_NETWORK_PREFIX" set "HOME_PREFIX=%%B"
    )
)

echo ----------------------------------------------------------------
echo   Dirección en red local: %LOCAL_IP%

:: Verificar si estamos en casa
set "EN_CASA=0"
if defined HOME_PREFIX (
    echo %LOCAL_IP% | findstr /b /c:"%HOME_PREFIX%" >nul
    if not errorlevel 1 (
        set "EN_CASA=1"
        color 0B
        echo.
        echo   [INFO] Estás en tu red de casa ^(Acceso Mac habilitado^)
        echo   Usa el archivo "conectar-mac-casa.command" en tu Mac.
        echo   URL Directa Mac: http://%LOCAL_IP%:5173
    )
)

if "%EN_CASA%"=="0" (
    echo.
    echo   Para conectar la Tablet de Comandas, ingresa en la App:
    echo   http://%LOCAL_IP%:8000/api
)
echo ----------------------------------------------------------------
echo.

:: 3. Localizar PHP
echo [3/4] Comprobando entorno de ejecución PHP...
set "PHP_BIN=php"
set "PHP_INI="
where php >nul 2>&1
if %errorLevel% neq 0 (
    if exist "C:\Ohana-POS-Windows\php\php.exe" (
        set "PHP_BIN=C:\Ohana-POS-Windows\php\php.exe"
        set "PHP_INI=-c C:\Ohana-POS-Windows\php\php.ini"
    ) else (
        echo [ERROR] No se encontró el ejecutable de PHP.
        pause
        exit /b 1
    )
) else (
    if exist "C:\Ohana-POS-Windows\php\php.ini" (
        set "PHP_INI=-c C:\Ohana-POS-Windows\php\php.ini"
    )
)

:: 4. Verificar enlace de almacenamiento para imágenes de productos
if not exist "%~dp0backend\public\storage" (
    echo [INFO] Configurando enlace de imágenes de productos...
    cd /d "%~dp0backend"
    "%PHP_BIN%" %PHP_INI% artisan storage:link >nul 2>&1
    cd /d "%~dp0"
)

:: 5. Iniciar Servidor Backend Laravel
echo [4/4] Iniciando Servidor Ohana POS (Puerto 8000)...
set PHP_CLI_SERVER_WORKERS=8
cd /d "%~dp0backend"
start "Ohana POS Service" /min "%PHP_BIN%" %PHP_INI% artisan serve --host=0.0.0.0 --port=8000 --no-reload
cd /d "%~dp0"

ping 127.0.0.1 -n 3 >nul

:: 6. Abrir POS en Navegador Web (Modo Aplicación de Escritorio)
echo Abriendo pantalla de Punto de Venta...
if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" (
    start "" "%ProgramFiles%\Google\Chrome\Application\chrome.exe" --app=http://localhost:8000
) else if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" (
    start "" "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" --app=http://localhost:8000
) else if exist "%LocalAppData%\Google\Chrome\Application\chrome.exe" (
    start "" "%LocalAppData%\Google\Chrome\Application\chrome.exe" --app=http://localhost:8000
) else if exist "%ProgramFiles%\BraveSoftware\Brave-Browser\Application\brave.exe" (
    start "" "%ProgramFiles%\BraveSoftware\Brave-Browser\Application\brave.exe" --app=http://localhost:8000
) else if exist "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" (
    start "" "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" --app=http://localhost:8000
) else if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" (
    start "" "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" --app=http://localhost:8000
) else (
    start http://localhost:8000
)

echo.
echo ================================================================
echo   ✓ ¡SISTEMA OHANA POS EN EJECUCIÓN!
echo ================================================================
echo   - Local POS:     http://localhost:8000
echo   - Base de Datos: SQLite (Activa)
echo.
echo   [IMPORTANTE] Mantén esta ventana minimizada mientras operas.
echo   Para cerrar el sistema limpiamente, presiona la tecla [X].
echo ================================================================
echo.

:loop
echo Opciones disponibles:
echo   [X] Cerrar POS y detener servidores
echo   [B] Realizar copia de seguridad (Respaldo)
echo   [A] Iniciar Vigilante de Actualizaciones (Auto-Update desde GitHub)
echo   [T] Iniciar Túnel Remoto Cloudflare (Para conectar por Internet)
if "%EN_CASA%"=="1" echo   [M] Conectar Mac en red de casa (Copiar enlace)
echo   [D] Iniciar servidor de desarrollo Vite (Puerto 5173)
echo   [K] Instalar / Abrir App KDS en Android conectado
set /p OPT="Selecciona una opción [X/B/A/T/M/D/K]: "
if /i "%OPT%"=="M" if "%EN_CASA%"=="1" (
    echo http://%LOCAL_IP%:5173 | clip
    echo.
    echo ✓ Enlace copiado al portapapeles: http://%LOCAL_IP%:5173
    echo.
    goto loop
)
if /i "%OPT%"=="X" goto salir
if /i "%OPT%"=="B" (
    call "%~dp0respaldo-diario.bat"
    echo.
    goto loop
)
if /i "%OPT%"=="A" (
    echo Iniciando Vigilante de Actualizaciones en ventana separada...
    start "Ohana POS Vigilante" "%~dp0vigilante-actualizaciones.bat"
    echo.
    goto loop
)
if /i "%OPT%"=="T" (
    echo Iniciando Túnel Seguro de Cloudflare en ventana separada...
    start "Ohana Tunnel" "%~dp0iniciar-tunel-remoto.bat"
    echo.
    goto loop
)
if /i "%OPT%"=="D" (
    echo Iniciando Vite Dev Server en ventana separada...
    cd /d "%~dp0frontend"
    start "Vite Dev Server" npm.cmd run dev
    cd /d "%~dp0"
    echo ✓ Vite iniciado en http://localhost:5173
    echo.
    goto loop
)
if /i "%OPT%"=="K" (
    call "%~dp0instalar-app-android.bat"
    echo.
    goto loop
)
goto loop

:salir
echo.
echo Deteniendo servicios de Ohana POS...
for /f "tokens=5" %%p in ('netstat -aon ^| findstr /r ":8000.*LISTENING"') do (
    taskkill /f /pid %%p >nul 2>&1
)
for /f "tokens=5" %%p in ('netstat -aon ^| findstr /r ":5173.*LISTENING"') do (
    taskkill /f /pid %%p >nul 2>&1
)
echo ✓ Sistema detenido con éxito. Hasta pronto.
ping 127.0.0.1 -n 3 >nul
exit
