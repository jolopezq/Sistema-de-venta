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
4. Automáticamente buscará el PC Windows en la red local y abrirá el sistema en tu navegador.

*(Nota: La IP de la laptop Windows en casa es `192.168.1.9`. Si tu router cambia esa IP en el futuro, actualiza el archivo `conectar-mac-casa.command`.)*

---

## Opción 2: Estás fuera de casa o en el negocio (Vía Cloudflare Tunnel) 🌍

Si te llevas el Mac a otro lugar, al negocio, o estás usando datos móviles, puedes acceder al sistema a través del túnel seguro de Cloudflare, que se ejecuta automáticamente en la laptop Windows.

**Pasos:**
1. Abre Google Chrome o Safari en tu Mac (o en tu celular).
2. Ingresa directamente a: **`https://www.pos.ohana.com`**
3. Inicia sesión con tu usuario y contraseña de Super Admin.
4. ✅ Acceso completo en tiempo real desde cualquier parte del mundo.

> ⚠️ El túnel solo funciona cuando la laptop Windows está **encendida**. El servicio `cloudflared` se inicia automáticamente con Windows — no necesitas hacer nada extra.

---

### Resumen Rápido

| Situación | Qué usar |
|---|---|
| En casa, mismo Wi-Fi que la Windows | Doble clic en `conectar-mac-casa.command` |
| En el negocio o fuera de casa | Abrir `https://www.pos.ohana.com` en el browser |

---

### Notas Técnicas
- **CORS y Sanctum**: El servidor Laravel permite peticiones desde la red `192.168.1.*` (LAN de casa) y desde `https://www.pos.ohana.com` (túnel Cloudflare).
- **Firewall Windows**: Puerto `8000` abierto (API + Frontend compilado).
- **Servicio Cloudflare**: Se ejecuta automáticamente al iniciar Windows (túnel `sistema-pos`).
- **Puerto de acceso**: El sistema completo corre en el puerto `8000`. El puerto `5173` es exclusivo para desarrollo local.
- **URL fija del túnel**: `https://www.pos.ohana.com`
