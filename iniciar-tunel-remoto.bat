@echo off
chcp 65001 >nul
title Ohana POS - Enlace Seguro para Acceso Remoto
color 0E

echo ================================================================
echo      🌺 OHANA AÇAÍ POS - ACCESO REMOTO POR INTERNET 🌺
echo ================================================================
echo.
echo Iniciando túnel cifrado Cloudflare...
echo Este túnel permite acceder a este POS desde tu casa o desde tu Mac
echo sin importar en qué Wi-Fi o negocio se encuentre esta portátil.
echo.
echo Espera unos segundos mientras se genera tu enlace público seguro...
echo ================================================================
echo.

cloudflared tunnel --url http://localhost:8000
