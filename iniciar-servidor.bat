@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul

cd /d "%~dp0"

:: Verificar si el servidor ya está corriendo en el puerto 8000
netstat -aon | findstr /r ":8000.*LISTENING" >nul 2>&1
if %errorLevel% equ 0 (
    exit /b 0
)

:: Localizar PHP
set "PHP_BIN=php"
set "PHP_INI="
where php >nul 2>&1
if %errorLevel% neq 0 (
    if exist "C:\Ohana-POS-Windows\php\php.exe" (
        set "PHP_BIN=C:\Ohana-POS-Windows\php\php.exe"
        set "PHP_INI=-c C:\Ohana-POS-Windows\php\php.ini"
    ) else (
        exit /b 1
    )
) else (
    if exist "C:\Ohana-POS-Windows\php\php.ini" (
        set "PHP_INI=-c C:\Ohana-POS-Windows\php\php.ini"
    )
)

:: Verificar enlace simbólico de imágenes
if not exist "%~dp0backend\public\storage" (
    cd /d "%~dp0backend"
    "%PHP_BIN%" %PHP_INI% artisan storage:link >nul 2>&1
    cd /d "%~dp0"
)

:: Iniciar Servidor Backend Laravel en segundo plano
set PHP_CLI_SERVER_WORKERS=8
cd /d "%~dp0backend"
"%PHP_BIN%" %PHP_INI% artisan serve --host=0.0.0.0 --port=8000 --no-reload
