# 🍏 Guía de Conexión desde el Mac al Servidor Windows (Ohana POS)

Este documento explica cómo acceder al sistema Ohana POS desde tu Mac. El sistema central (Base de datos y Servidor) se está ejecutando permanentemente en el **PC con Windows**.

Desde tu Mac tienes dos formas de conectarte:

---

## Opción 1: Estás en tu casa (Conexión Directa por LAN) 🏠

Esta es la mejor opción cuando el Mac y la laptop Windows están conectados al **mismo Wi-Fi de tu casa**. Es ultra rápida, no depende de internet (funciona offline) y no tiene latencia.

**Pasos:**
1. Asegúrate de que el PC Windows está encendido y tiene ejecutando el script `iniciar-pos.bat`.
2. En este repositorio (desde el Mac), busca el archivo llamado **`conectar-mac-casa.command`**.
3. Haz doble clic sobre él.
4. Automáticamente buscará el PC Windows en la red local y abrirá la pantalla del POS en tu navegador.

*(Nota: La IP actual de la laptop Windows en casa está guardada como `192.168.1.9`. Si tu router cambia esa IP en el futuro, solo debes actualizar el archivo `home-network.conf` en el PC Windows y el script `conectar-mac-casa.command`)*

---

## Opción 2: Estás fuera de casa (Vía Cloudflare Tunnel) 🌍

Si te llevas el Mac a otro lugar, o estás usando los datos móviles en la calle, puedes seguir accediendo al sistema completo a través del túnel seguro de Cloudflare, que se ejecuta permanentemente en el servidor Windows como un servicio.

**Pasos:**
1. Pídele al administrador del sistema (o fíjate en el link de Cloudflare que se genera) la URL pública vigente.
2. Ingresa esa URL en el navegador de tu Mac (Google Chrome o Safari).
3. Podrás hacer comandas, ver reportes e inventarios en tiempo real desde cualquier parte del mundo.

---

### Notas Técnicas (Cambios recientes en el servidor)
- **CORS y Sanctum**: El servidor Laravel ahora permite peticiones desde la red `192.168.1.*` (IPs de casa). Las sesiones y logins funcionarán perfectamente por red local.
- **Firewall**: El Windows ahora tiene abierto el puerto `5173` (Frontend) y `8000` (Backend API).
- **Servicio en Segundo Plano**: Cloudflare ahora se ejecuta como servicio nativo en Windows, iniciando automáticamente con el sistema.
