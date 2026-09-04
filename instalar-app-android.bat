@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul
title Ohana POS - Instalar App KDS / Comandas en Android
color 0A

cd /d "%~dp0"

echo ================================================================
echo       OHANA ACAI - INSTALADOR Y CONEXION DE APP COMANDAS
echo ================================================================
echo.

REM 1. Detectar IP Local de esta maquina
set "LOCAL_IP="
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do (
    if not defined LOCAL_IP set "LOCAL_IP=%%a"
)
set "LOCAL_IP=%LOCAL_IP: =%"

echo [1/3] Informacion de Conexion para la App / Tablet:
echo ----------------------------------------------------------------
echo   Direccion IP de este Servidor : %LOCAL_IP%
echo   URL de la API para la App     : http://%LOCAL_IP%:8000/api
echo   Descarga directa de la APK    : http://%LOCAL_IP%:8000/app-comandas.apk
echo ----------------------------------------------------------------
echo.

REM 2. Ubicar archivo APK
set "APK=%~dp0backend\public\app-comandas.apk"
if not exist "%APK%" set "APK=%~dp0app-comandas.apk"
if not exist "%APK%" set "APK=C:\App Comandas\app\build\outputs\apk\debug\app-debug.apk"

if not exist "%APK%" (
    color 0C
    echo [ERROR] No se encontro el archivo APK.
    echo Asegurate de que exista app-comandas.apk en la carpeta del sistema.
    pause
    exit /b 1
)

REM 3. Verificar ADB para instalacion por cable USB
set "ADB=C:\platform-tools\adb.exe"
if not exist "%ADB%" (
    where adb >nul 2>&1
    if %errorLevel% equ 0 set "ADB=adb"
)

if exist "%ADB%" (
    echo [2/3] Comprobando dispositivos Android conectados por cable USB...
    "%ADB%" devices
    echo.
    echo [3/3] Intentando instalar en dispositivo USB...
    "%ADB%" install -r "%APK%" >nul 2>&1
    if !errorLevel! equ 0 (
        echo   [OK] App instalada con exito en el dispositivo!
        "%ADB%" shell monkey -p com.ohana.kds -c android.intent.category.LAUNCHER 1 >nul 2>&1
        echo   [OK] App abierta en la pantalla del dispositivo.
    ) else (
        echo [INFO] No se detecto un dispositivo USB con depuracion activa.
        echo No te preocupes, puedes instalarla por Wi-Fi siguiendo los pasos abajo.
    )
) else (
    echo [INFO] ADB no encontrado. Instalacion directa por Wi-Fi disponible.
)

echo.
echo ================================================================
echo             COMO CONECTAR TU TABLET O CELULAR:
echo ================================================================
echo  OPCION 1 (Descarga por Wi-Fi en la Tablet/Celular):
echo    1. Conecta la tablet a la misma red Wi-Fi que esta PC.
echo    2. Abre Chrome en la tablet y entra a:
echo       http://%LOCAL_IP%:8000/app-comandas.apk
echo    3. Descarga e instala el archivo.
echo    4. Al abrir la app, en "Servidor / API URL" escribe:
echo       http://%LOCAL_IP%:8000/api
echo.
echo  OPCION 2 (Usar Pantalla Web de Comandas sin instalar nada):
echo    - Entra desde el navegador de la tablet a:
echo      http://%LOCAL_IP%:8000
echo ================================================================
echo.
pause