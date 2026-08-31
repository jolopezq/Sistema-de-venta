@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul
title Ohana POS - Actualizador de Sistema
color 0B

cd /d "%~dp0"

echo ================================================================
echo        🌺 OHANA AÇAÍ POS - APLICAR ACTUALIZACIÓN 🌺
echo ================================================================
echo.

:: 1. Respaldo preventivo obligatorio de la base de datos
echo [1/5] Creando respaldo de seguridad preventivo...
call "%~dp0respaldo-diario.bat"

:: 2. Cerrar instancias previas
echo.
echo [2/5] Deteniendo servicios en ejecución...
for /f "tokens=5" %%p in ('netstat -aon ^| findstr /r ":8000.*LISTENING"') do (
    taskkill /f /pid %%p >nul 2>&1
)
for /f "tokens=5" %%p in ('netstat -aon ^| findstr /r ":5173.*LISTENING"') do (
    taskkill /f /pid %%p >nul 2>&1
)

:: 3. Descargar cambios desde GitHub
echo.
echo [3/5] Descargando últimos cambios desde GitHub...
git pull origin main

:: 4. Migraciones y mantenimiento backend
echo.
echo [4/5] Aplicando migraciones de base de datos y limpiando caché...
set "PHP_BIN=php"
set "PHP_INI="
where php >nul 2>&1
if %errorLevel% neq 0 (
    if exist "C:\Ohana-POS-Windows\php\php.exe" (
        set "PHP_BIN=C:\Ohana-POS-Windows\php\php.exe"
        set "PHP_INI=-c C:\Ohana-POS-Windows\php\php.ini"
    )
) else (
    if exist "C:\Ohana-POS-Windows\php\php.ini" (
        set "PHP_INI=-c C:\Ohana-POS-Windows\php\php.ini"
    )
)

cd /d "%~dp0backend"
"%PHP_BIN%" %PHP_INI% artisan migrate --force
"%PHP_BIN%" %PHP_INI% artisan optimize:clear
if not exist "%~dp0backend\public\storage" (
    "%PHP_BIN%" %PHP_INI% artisan storage:link >nul 2>&1
)
cd /d "%~dp0"

:: 5. Reconstruir Frontend si es necesario
echo.
echo [5/5] Compilando interfaz web actualizada...
cd /d "%~dp0frontend"
call npm.cmd run build
xcopy /e /y /i "%~dp0frontend\dist\*" "%~dp0backend\public\" >nul 2>&1
cd /d "%~dp0"

echo.
echo ================================================================
echo   ✓ ¡ACTUALIZACIÓN APLICADA CON ÉXITO!
echo ================================================================
echo Presiona cualquier tecla para reiniciar el POS...
pause >nul
start "" "%~dp0iniciar-pos.bat"
exit
