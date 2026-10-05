# Changelog

Cambios relevantes del sistema. Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Cada cambio funcional se agrega a "Sin publicar" en el mismo commit; al fusionar en `main`, la sección
se renombra con la fecha de la actualización de producción.

## [Sin publicar] — rama `mejoras/analisis-integral`

### Agregado
- Movimientos de caja (egresos e ingresos extra) con método de pago; distribución del efectivo al cierre
  y desglose de billetes; Centro de Control Diario para ventas retroactivas. (`7644554`)
- Cada venta queda vinculada a su sesión de caja (`sales.cash_register_session_id`, con backfill que
  reproduce el cálculo anterior). (`35ba606`)
- Recálculo de importes en el servidor (`SalePricingService`) y auditoría de diferencias con el catálogo. (`7005e0e`)
- Rol cocina disponible en el formulario de usuarios. (`2ecb266`)
- Fuentes Inter, Baloo 2 y JetBrains Mono alojadas localmente y precacheadas (funcionan sin internet). (`624702e`)
- `frontend/scripts/audit-contrast.cjs`: auditoría de contraste WCAG por pantalla. (`2802a36`)
- `AGENTS.md`, `CLAUDE.md`, `docs/BITACORA.md` y este archivo para la gestión de cambios.

### Cambiado
- El arqueo de caja suma las ventas por sesión en lugar de por rango de horas (sin doble conteo). (`35ba606`)
- El sobrepago en efectivo se registra como cambio, no como ingreso. (`7005e0e`)
- Botones naranjas y verdes con texto oscuro; textos naranjas y grises más contrastados. (`b7b6aa4`)
- Usuarios y Auditoría usan la tipografía del sistema; el tema respeta la preferencia del sistema operativo. (`2802a36`)

### Corregido
- La sincronización descartaba notas, alérgenos, estado de preparación, número de ticket y cantidad de
  modificadores. (`852ae61`)
- Las ventas del POS se guardaban en UTC (+4 h) en lugar de la hora de La Paz. (`35ba606`)
- Al recargar la página, los administradores perdían su rol y su menú. (`2ecb266`)
- Las reglas de modo oscuro de los componentes nunca se aplicaban (`:global(html.dark)` en estilos scoped);
  pantallas de cocina, cobro y Control Diario con texto ilegible. (`2802a36`)

### Seguridad
- Permisos por rol aplicados en el backend; cocina sin acceso a ventas ni caja. (`938c31b`)
- Solo el dueño de una caja o un admin pueden operarla; abrir caja con fecha pasada es solo para admin. (`938c31b`)
- Límite de intentos de login, caducidad de tokens (7 días) y bloqueo de imágenes SVG. (`938c31b`)
- Restauración de backups validada, con copia previa y reemplazo atómico. (`938c31b`)
