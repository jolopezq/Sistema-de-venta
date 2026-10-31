/**
 * Líneas del ticket del POS: cómo se arma una línea y cuándo dos se juntan.
 *
 * - Cada línea tiene un `cart_key` propio que no cambia mientras está en el ticket. Editarla o cambiarle el
 *   destino no la convierte en otra.
 * - Al agregar o editar, una línea se junta con otra solo si su configuración ACTUAL es idéntica: producto,
 *   opciones, nota, alergias y destino. (Antes se comparaba una clave armada al agregarla, que quedaba vieja
 *   al cambiar el destino.)
 * - Un producto por peso (Autoservicio) nunca se junta ni cambia de cantidad. Cada bowl se pesa aparte y es su
 *   propia línea, con sus gramos y su precio. Antes, un segundo bowl igual se sumaba al primero: cantidad 2 al
 *   precio del primero, y los gramos del segundo se perdían.
 *
 * Sin dependencias del navegador ni de la base local, para probarlo con `node --test` (cartLines.test.mjs).
 */

let fallbackSeq = 0;

/** Id único de una línea del ticket. */
export function newLineKey() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  fallbackSeq += 1;
  return `linea-${Date.now().toString(36)}-${fallbackSeq.toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
}

/**
 * Arma una línea del ticket.
 * @param {Object} product - { id, name, price, modifiers, base_price, is_takeaway, is_weight_based, weight_grams, price_per_gram }
 */
export function makeLine(product, quantity = 1, itemNote = '', allergenFlags = [], cartKey = newLineKey()) {
  const isWeight = Boolean(product.is_weight_based);
  const qty = isWeight ? 1 : quantity;
  return {
    cart_key: cartKey,
    product_id: product.id,
    name: product.name,
    quantity: qty,
    unit_price: product.price,
    subtotal: qty * product.price,
    modifiers: product.modifiers || [],
    base_price: product.base_price || product.price,
    item_note: itemNote,
    allergen_flags: allergenFlags,
    is_takeaway: product.is_takeaway || false,
    is_weight_based: isWeight,
    weight_grams: product.weight_grams || null,
    price_per_gram: product.price_per_gram || null,
    // Ajuste de precio de la línea (solo PedidosYa): precio del tarifario y motivo
    catalog_unit_price: null,
    price_override_reason: null,
  };
}

/** Configuración de la línea para compararla con otra; null si no se junta nunca (producto por peso). */
export function lineSignature(line) {
  if (!line || line.is_weight_based) return null;
  const modifiers = (line.modifiers || []).map((m) => `${m.option_id}:${m.quantity || 1}`).sort().join(',');
  const allergens = [...(line.allergen_flags || [])].sort().join(',');
  return JSON.stringify([line.product_id, modifiers, line.item_note || '', allergens, Boolean(line.is_takeaway)]);
}

/** Índice de otra línea con la misma configuración, o -1. */
export function findTwin(items, line, exceptIndex = -1) {
  const signature = lineSignature(line);
  if (signature === null) return -1;
  return items.findIndex((other, i) => i !== exceptIndex && lineSignature(other) === signature);
}

function setQuantity(line, quantity) {
  line.quantity = quantity;
  line.subtotal = quantity * line.unit_price;
}

/** Agrega la línea al ticket, o suma su cantidad a la gemela. Devuelve la línea que quedó en el ticket. */
export function addLine(items, line) {
  const twin = findTwin(items, line);
  if (twin === -1) {
    items.push(line);
    return items[items.length - 1];
  }
  setQuantity(items[twin], items[twin].quantity + line.quantity);
  return items[twin];
}

/**
 * Reemplaza la línea `cartKey` por `line` en el mismo lugar, con el mismo `cart_key` y la misma cantidad.
 * Si la línea editada quedó igual a otra, se juntan. Un producto por peso queda siempre en su lugar.
 * @returns {boolean} false si la línea ya no está en el ticket.
 */
export function replaceLine(items, cartKey, line) {
  const idx = items.findIndex((i) => i.cart_key === cartKey);
  if (idx === -1) return false;

  line.cart_key = items[idx].cart_key;
  if (!line.is_weight_based) setQuantity(line, items[idx].quantity);

  const twin = findTwin(items, line, idx);
  if (twin !== -1) {
    setQuantity(items[twin], items[twin].quantity + line.quantity);
    items.splice(idx, 1);
  } else {
    items.splice(idx, 1, line);
  }
  return true;
}

/** Una unidad más. Un producto por peso no sube: el otro bowl se pesa y se agrega desde el menú. */
export function increaseLine(items, cartKey) {
  const line = items.find((i) => i.cart_key === cartKey);
  if (!line || line.is_weight_based) return false;
  setQuantity(line, line.quantity + 1);
  return true;
}

/** Una unidad menos; con una sola unidad, o en un producto por peso, quita la línea del ticket. */
export function decreaseLine(items, cartKey) {
  const idx = items.findIndex((i) => i.cart_key === cartKey);
  if (idx === -1) return false;
  const line = items[idx];
  if (!line.is_weight_based && line.quantity > 1) {
    setQuantity(line, line.quantity - 1);
  } else {
    items.splice(idx, 1);
  }
  return true;
}
