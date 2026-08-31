@echo off
title Instalar Ohana KDS en Dispositivo Android
color 0A
cd /d "%~dp0"
echo ================================================================
echo       🌺 OHANA AÇAÍ - INSTALADOR DE APP KDS ANDROID 🌺
echo ================================================================
echo.
set "ADB=C:\platform-tools\adb.exe"
set "APK=C:\App Comandas\app\build\outputs\apk\debug\app-debug.apk"

if not exist "%ADB%" (
    echo [ERROR] No se encontro adb en C:\platform-tools\adb.exe
    pause
    exit /b 1
)

if not exist "%APK%" (
    echo [ERROR] No se encontro el APK en:
    echo %APK%
    pause
    exit /b 1
)

echo [1/3] Comprobando dispositivo Android conectado...
"%ADB%" devices -l
echo.

echo [2/3] Instalando aplicacion Ohana KDS...
"%ADB%" install -r "%APK%"
if %errorlevel% neq 0 (
    echo [ERROR] Fallo la instalacion. Verifica que el dispositivo este desbloqueado y con depuracion USB activa.
    pause
    exit /b 1
)

echo.
echo [3/3] Abriendo Ohana KDS en la pantalla del dispositivo...
"%ADB%" shell monkey -p com.ohana.kds -c android.intent.category.LAUNCHER 1 >nul 2>&1

echo.
echo ================================================================
echo    INSTALACION COMPLETADA EXITOSAMENTE!
echo ================================================================
echo.
pause
