# Plan: precios PedidosYa en el POS

> Continuación de [PLAN_PEDIDOSYA.md](PLAN_PEDIDOSYA.md). Antes de empezar, lee `AGENTS.md` y
> `docs/BITACORA.md` y sigue sus reglas (rama, plan aprobado, respaldo, tests, CHANGELOG y bitácora).

## 1. Problema

En modo PedidosYa el POS muestra y cobra **Bs 28**, igual que en el local, y el cajero no puede
cambiarlo. El pedido debe quedar registrado con el precio de PedidosYa, que es distinto.

## 2. Diagnóstico: el doble precio ya existe

La idea de "cada producto tiene dos precios y PedidosYa usa el segundo" **ya está implementada** en la
rama `feat/canal-pedidosya`:

| Pieza | Dónde |
|---|---|
| Segundo precio del producto | `products.delivery_price`. Se edita en Menú → producto → "🛵 Precio PedidosYa" (con botones +20 / +25 / +30 %). |
| Segundo precio de tamaños y toppings | `options.delivery_price`. Se edita en Menú → grupo de opciones. |
| Regla de precio por canal | `frontend/src/utils/channelPricing.js` y `SalePricingService::productPrice / optionPrice`: en PedidosYa usa `delivery_price` si es > 0; si no, el precio local. |
| El POS aplica la regla | `ProductCard.vue`, `ModifierModal.vue`, `Pos.vue`, `CheckoutModal.vue`. |

**Por qué se ve Bs 28:** ningún producto tiene `delivery_price` cargado, así que todos caen al precio local.

Estado actual de los datos (base local, 07/10/2026):

| Elemento | Local | PedidosYa |
|---|---|---|
| 8 bowls (Classic, Hawaian, Arma tu Bowl, Aloha, Marley, Akahai, Shaka, Patriota) | 28 | — (usa 28) |
| Capuchino | 16 | — (usa 16) |
| Autoservicio (por peso) | 0,12 / g | no existe precio por gramo PedidosYa |
| Tamaño Acai: Mediano / Grande / Ohana | +7 / +14 / +32 | +7 / +14 / **+36** |
| Toppings con precio PedidosYa (10) | +4 | +4 |
| Toppings sin precio PedidosYa: Ositos de gomita, Chips de Chocolate, Hojuelas de maíz, Chispas de chocolate | +4 | — (usa +4) |
| Tamaño Café: Grande | +2 | 0 → se trata como vacío (usa +2) |

## 3. Casos analizados

| # | Caso | Hoy | Propuesta |
|---|---|---|---|
| 1 | Producto de precio fijo con precio PedidosYa | Funciona si se carga el precio | Cargar precios (fase 0) |
| 2 | Producto sin precio PedidosYa | Cobra el local; el aviso aparece recién al agregarlo al carrito | Etiqueta "precio local" en la tarjeta, antes de agregarlo |
| 3 | Tamaños y toppings | Funciona con `options.delivery_price` | Completar los 4 toppings vacíos |
| 4 | Producto que no se vende en PedidosYa (Autoservicio por peso) | Aparece y cobra precio local | Casilla "Disponible en PedidosYa" (fase 1) |
| 5 | Pedido con promo de PedidosYa o precio desactualizado | El cajero no puede corregir; solo el admin en Control Diario | Ajuste por línea con motivo (fase 2) |
| 6 | PedidosYa sube su comisión y hay que actualizar todo | Producto por producto | Tarifario con "aplicar +%" (fase 3) |
| 7 | Cambio de precio con ventas pasadas | Cada venta guarda su precio | Sin cambios |
| 8 | POS sin internet | El catálogo en caché ya trae `delivery_price` | Incluir los campos nuevos en el catálogo |
| 9 | Opción gratis en PedidosYa pero pagada en el local | No se puede: 0 significa "vacío" | Limitación conocida; no cambiar la regla (ver §7) |

## 4. Opciones evaluadas

| Opción | Ventajas | Desventajas | Veredicto |
|---|---|---|---|
| **A. Dos precios fijos por producto y opción** | Exacto, rápido de cobrar, auditable; ya construido | Hay que mantener dos precios | **Base de la solución** |
| B. Porcentaje global (local × 1,25) | Cero mantenimiento | No coincide con el menú real de PedidosYa; redondeos | Solo como ayuda para llenar el tarifario |
| C. Precio manual en cada venta | Flexible | Lento, propenso a errores, sin control | Solo como excepción, con motivo |
| D. Tabla de listas de precios | Escala a varias plataformas | Excesivo para un solo canal | Solo si llega otra plataforma |

**Recomendación:** A como regla, C como excepción controlada y B como herramienta de carga.

## 5. Fases

### Fase 0 — Cargar los precios (sin código, se puede hacer hoy)
- En Menú, llenar "🛵 Precio PedidosYa" de los 9 productos y de los 4 toppings vacíos.
- Probar en el POS: en modo PedidosYa las tarjetas deben mostrar el precio nuevo.

### Fase 1 — Disponible en PedidosYa
- Migración: `products.available_on_pedidosya` (boolean, default `true`). La migración deja en `false`
  los productos por peso (`is_weight_based = 1`), que hoy es solo Autoservicio. **Confirmar con el usuario
  antes de fusionar**, porque cambia datos en producción.
- `ProductRequest`, `ProductResource` y el catálogo incluyen el campo.
- `ProductForm.vue`: casilla "Disponible en PedidosYa" junto al precio PedidosYa.
- `Pos.vue`: en modo PedidosYa solo muestra los productos disponibles.
- `ProductCard.vue`: en modo PedidosYa, etiqueta "precio local" en las tarjetas sin precio PedidosYa.
- Backend: si llega una venta PedidosYa con un producto no disponible (POS offline con caché vieja), se
  acepta y se registra la discrepancia en auditoría; nunca se rechaza una venta cobrada.

### Fase 2 — Ajuste de precio por línea (cajero, con motivo)
- Solo en modo PedidosYa. En el local el cajero sigue sin poder cambiar precios.
- En el carrito, cada línea tiene "Ajustar precio": nuevo precio unitario + motivo obligatorio
  (lista corta: "Promo PedidosYa", "Precio desactualizado", "Otro" + texto).
- La línea muestra el precio del tarifario tachado y el ajustado, y el ticket marca "precio ajustado".
- Migración: `sale_items.price_override_reason` (string nullable). Regla en `SyncSalesRequest` y
  `AdminSaleRequest` (si no, se descarta en `validated()`).
- `SaleSyncService`: una línea con motivo se registra en auditoría como `pedidosya_price_override`
  (precio del tarifario, precio cobrado, motivo, cajero), no como `sale_sync_discrepancy`. Si llega un
  motivo en una venta del local, se ignora y se audita.
- Control Diario (`AdminSaleEditor.vue`, modo "✏️ Manual Libre") guarda el mismo motivo.

### Fase 3 — Pantalla Tarifario PedidosYa (admin)
- Nueva pestaña en Menú: tabla con productos y opciones (tamaños, toppings): precio local,
  precio PedidosYa, disponible, y una marca en los que usan el precio local por estar vacíos.
- Edición directa de la columna PedidosYa.
- "Aplicar +X %" a todos o solo a los vacíos, con redondeo a 0,50 y **vista previa antes de guardar**.
- Endpoint admin `PUT /api/admin/pricing/pedidosya` que guarda en lote dentro de una transacción y deja
  un registro de auditoría por cambio.

## 6. Tests

1. Venta PedidosYa con producto y opción con `delivery_price` → importes correctos, sin discrepancia.
2. Producto no disponible en PedidosYa vendido por PedidosYa → se acepta y se audita.
3. Línea con ajuste y motivo → se guarda el motivo y se audita `pedidosya_price_override`.
4. Venta del local con motivo de ajuste → se ignora el motivo y se audita.
5. Tarifario: el guardado en lote actualiza solo `delivery_price` y nunca el precio local.
6. Los tests existentes siguen en verde (`php artisan test`) y `npx vite build` compila.

## 7. Fuera de alcance y limitaciones
- **Opción gratis en PedidosYa y pagada en el local:** no se puede con la regla actual (0 = vacío). Cambiarla a
  "vacío = usar local, 0 = gratis" convertiría en gratis opciones que hoy tienen 0 por defecto (por ejemplo,
  Tamaño Café Grande). Si se necesita, hacerlo con una migración que convierta esos 0 en vacío.
- Precio por gramo PedidosYa para productos por peso.
- Comisión de PedidosYa y conciliación de liquidaciones.

## 8. Forma de trabajo
- Rama nueva desde `feat/canal-pedidosya`, que todavía no está fusionada.
- Consultar `graphify-out/` antes de tocar código (regenerarlo si está desactualizado).
- Presentar el plan y esperar aprobación; respaldo de la base antes de migrar.
- Un commit por fase, con `CHANGELOG.md` y `docs/BITACORA.md` actualizados.
