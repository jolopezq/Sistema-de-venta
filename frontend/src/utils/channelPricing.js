/**
 * Precios por canal de venta.
 *
 * PedidosYa usa `delivery_price` del producto o de la opción (tamaño, topping) cuando está
 * definido y es mayor que 0; si no, el precio local. Es la misma regla que aplica el backend
 * (App\Services\SalePricingService::productPrice / optionPrice).
 */

export const CHANNEL_LOCAL = 'pos';
export const CHANNEL_PEDIDOSYA = 'pedidosya';

const hasDeliveryPrice = (entity) => Number(entity?.delivery_price) > 0;

export function productPrice(product, channel = CHANNEL_LOCAL) {
  if (channel === CHANNEL_PEDIDOSYA && hasDeliveryPrice(product)) {
    return Number(product.delivery_price);
  }
  return Number(product?.price) || 0;
}

export function optionPrice(option, channel = CHANNEL_LOCAL) {
  if (channel === CHANNEL_PEDIDOSYA && hasDeliveryPrice(option)) {
    return Number(option.delivery_price);
  }
  return Number(option?.additional_price) || 0;
}

/** El producto (o la opción) no tiene tarifa PedidosYa propia y se cobrará a precio local. */
export function missingDeliveryPrice(entity) {
  return !hasDeliveryPrice(entity);
}
