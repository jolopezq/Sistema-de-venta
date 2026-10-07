/**
 * Precios por canal de venta.
 *
 * PedidosYa usa `delivery_price` del producto o de la opción (tamaño, topping) cuando está
 * definido y es mayor que 0; pero en opciones solo aplica si su grupo de opcionales tiene
 * `pedidosya_pricing` activo (tamaños). Los demás opcionales cobran el precio local.
 * Es la misma regla que aplica el backend (App\Services\SalePricingService::productPrice / optionPrice).
 */

export const CHANNEL_LOCAL = 'pos';
export const CHANNEL_PEDIDOSYA = 'pedidosya';

const hasDeliveryPrice = (entity) => Number(entity?.delivery_price) > 0;

/**
 * Indica si una opción pertenece a un grupo que tiene tarifa diferenciada en PedidosYa.
 * Si el catálogo en caché aún no incluye la propiedad (offline/legacy), mantiene compatibilidad
 * asumiendo verdadero si ya tenía delivery_price > 0.
 */
export function optionHasChannelPrice(option) {
  if (!option) return false;
  if (option.pedidosya_pricing !== undefined && option.pedidosya_pricing !== null) {
    return Boolean(option.pedidosya_pricing);
  }
  // Compatibilidad offline: si no viene el campo en caché, aplica regla previa
  return hasDeliveryPrice(option);
}

export function productPrice(product, channel = CHANNEL_LOCAL) {
  if (channel === CHANNEL_PEDIDOSYA && hasDeliveryPrice(product)) {
    return Number(product.delivery_price);
  }
  return Number(product?.price) || 0;
}

export function optionPrice(option, channel = CHANNEL_LOCAL) {
  if (channel === CHANNEL_PEDIDOSYA && optionHasChannelPrice(option) && hasDeliveryPrice(option)) {
    return Number(option.delivery_price);
  }
  return Number(option?.additional_price) || 0;
}

/**
 * El producto (o la opción) no tiene tarifa PedidosYa propia y se cobrará a precio local.
 * En opciones, solo se considera "faltante" si su grupo admite precio PedidosYa (tamaños).
 * Toppings y frutas cobran siempre el precio local y nunca se marcan como faltantes.
 */
export function missingDeliveryPrice(entity) {
  if (!entity) return false;
  // Si es una opción (tiene option_group_id o additional_price o pedidosya_pricing)
  const isOption = 'option_group_id' in entity || 'additional_price' in entity || 'pedidosya_pricing' in entity;
  if (isOption) {
    if (!optionHasChannelPrice(entity)) {
      return false; // Toppings/frutas cobran precio local por diseño
    }
    return !hasDeliveryPrice(entity);
  }
  return !hasDeliveryPrice(entity);
}

/** El producto se ofrece en PedidosYa (sin valor cuenta como disponible). */
export function isAvailableOnPedidosYa(product) {
  return product?.available_on_pedidosya !== false;
}
