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
- Arqueo con PedidosYa aparte: `pedidosya_count` y `pedidosya_total` en las estadísticas de la caja.
- POS: selector de canal **Local / PedidosYa**. En PedidosYa se aplican los precios de plataforma de
  producto, tamaño y toppings, se avisa qué ítems no tienen precio PedidosYa, el cobro es un único pago de
  plataforma (sin efectivo, cambio, cliente ni puntos), se puede anotar el Nº de pedido de la tablet y,
  tras la venta, el POS vuelve a Local. Cambiar de canal con productos en el ticket pide vaciarlo.
- Reporte de cierre, asistente de cierre, Turno y Control Diario muestran "Ventas del local" y un bloque
  PedidosYa aparte (informativo, no se rinde en caja).
- Productos con casilla **"Disponible en PedidosYa"** (`products.available_on_pedidosya`). En modo PedidosYa
  el POS solo muestra los disponibles; la migración deja fuera los productos por peso (Autoservicio). Una
  venta PedidosYa de un producto no disponible (POS con catálogo viejo) se acepta y queda en auditoría.
- En modo PedidosYa, las tarjetas sin precio de plataforma muestran la etiqueta "precio local" antes de
  agregarlas al ticket.

### Cambiado
- El arqueo de caja suma las ventas por sesión en lugar de por rango de horas (sin doble conteo). (`35ba606`)
- El sobrepago en efectivo se registra como cambio, no como ingreso. (`7005e0e`)
- Los pedidos PedidosYa (`source = 'pedidosya'`) ya no suman a las ventas del local ni al efectivo esperado,
  aunque se hayan registrado con otro método de pago; se guardan siempre con un único pago
  `delivery_platform` por el total (POS y Control Diario) y el ajuste queda en auditoría.
- La verificación de precios contra el catálogo usa el precio PedidosYa (`delivery_price`, o el local si
  está vacío) en los pedidos de ese canal; el precio guardado de cada topping también es el del canal.
- Botones naranjas y verdes con texto oscuro; textos naranjas y grises más contrastados. (`b7b6aa4`)
- Usuarios y Auditoría usan la tipografía del sistema; el tema respeta la preferencia del sistema operativo. (`2802a36`)

### Corregido
- La sincronización descartaba notas, alérgenos, estado de preparación, número de ticket y cantidad de
  modificadores. (`852ae61`)
- Las ventas del POS se guardaban en UTC (+4 h) en lugar de la hora de La Paz. (`35ba606`)
- Al recargar la página, los administradores perdían su rol y su menú. (`2ecb266`)
- El chip PedidosYa de Delivery y cocina no alcanzaba el contraste AA (4,46); el método `delivery_platform`
  se muestra como "PedidosYa" en el recibo y en el historial de Turno.
- Una caja abierta con fecha pasada empezaba a las 08:00 y dejaba fuera del arqueo las ventas de madrugada
  de ese día; ahora empieza a las 00:00.
- Las reglas de modo oscuro de los componentes nunca se aplicaban (`:global(html.dark)` en estilos scoped);
  pantallas de cocina, cobro y Control Diario con texto ilegible. (`2802a36`)

### Seguridad
- Permisos por rol aplicados en el backend; cocina sin acceso a ventas ni caja. (`938c31b`)
- Solo el dueño de una caja o un admin pueden operarla; abrir caja con fecha pasada es solo para admin. (`938c31b`)
- Límite de intentos de login, caducidad de tokens (7 días) y bloqueo de imágenes SVG. (`938c31b`)
- Restauración de backups validada, con copia previa y reemplazo atómico. (`938c31b`)
