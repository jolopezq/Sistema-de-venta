@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul
title Ohana POS - Respaldo de Base de Datos
color 0E

cd /d "%~dp0"

echo ================================================================
echo        🌺 OHANA AÇAÍ POS - COPIA DE SEGURIDAD 🌺
echo ================================================================
echo.

if not exist "%~dp0backups" mkdir "%~dp0backups"

set "DB_FILE=%~dp0backend\database\database.sqlite"

if not exist "%DB_FILE%" (
    echo [ERROR] No se encontró el archivo de base de datos en:
    echo %DB_FILE%
    echo.
    pause
    exit /b 1
)

:: Obtener fecha y hora para el nombre del archivo
for /f "usebackq tokens=*" %%I in (`"%%SystemRoot%%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -Command Get-Date -Format yyyy-MM-dd_HHmmss`) do set "STAMP=%%I"

set "BACKUP_FILE=%~dp0backups\database_%STAMP%.sqlite"

echo Creando copia de seguridad:
echo Destino: %BACKUP_FILE%
echo.

copy /y "%DB_FILE%" "%BACKUP_FILE%" >nul

if %errorLevel% equ 0 (
    color 0A
    echo   ✓ ¡RESPALDO CREADO CON ÉXITO!
    echo.
    echo   Ubicación: %BACKUP_FILE%
) else (
    color 0C
    echo   [ERROR] No se pudo crear el archivo de respaldo.
)

echo.
echo ================================================================
ping 127.0.0.1 -n 3 >nul
