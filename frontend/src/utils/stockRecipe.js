/**
 * Recetas de stock del POS (plan de inventario, F2).
 *
 * El servidor envía en el catálogo la receta ya expandida (`stock_recipe`): los componentes de costeo con
 * composición («Porción base açaí») llegan convertidos en los insumos reales que consumen, así el navegador solo
 * suma y resta y no repite la regla (H21). Un catálogo guardado antes de esta versión solo trae `recipes` y se
 * interpreta como antes.
 */

/** Filas de stock de un producto: [{ ingredient_id, quantity, per_kg }] (cantidad por unidad, o por kilo si per_kg). */
export function productStockRows(product) {
  if (Array.isArray(product?.stock_recipe)) return product.stock_recipe;
  return (product?.recipes || []).map((r) => ({
    ingredient_id: r.ingredient_id,
    quantity: Number(r.quantity_required) || 0,
    per_kg: true,
  }));
}

/** Filas de stock del ajuste de una opción: [{ ingredient_id, quantity }]; negativa si devuelve stock. */
export function optionStockRows(option) {
  if (Array.isArray(option?.stock_recipe)) return option.stock_recipe;
  return (option?.recipes || []).map((r) => ({
    ingredient_id: r.ingredient_id,
    quantity: Number(r.quantity_delta) || 0,
  }));
}

export function findOption(product, optionId) {
  for (const group of product?.option_groups || []) {
    const option = (group.options || []).find((o) => o.id === optionId);
    if (option) return option;
  }
  return null;
}

const round4 = (n) => Math.round(n * 10000) / 10000;

/**
 * Lo que consume una línea de venta: Map ingrediente → cantidad (negativa si devuelve stock).
 * Las mismas reglas que el servidor: por unidades vendidas; en un producto por peso, las filas «por kilo» se
 * multiplican por los gramos ÷ 1000 y las demás (envase, cucharilla) van una vez por unidad.
 */
export function stockUsage(product, item) {
  const usage = new Map();
  if (!product) return usage;
  const add = (id, quantity) => usage.set(id, round4((usage.get(id) || 0) + quantity));

  const units = Number(item.quantity) || 1;
  const grams = Number(item.weight_grams);
  const kilos = product.is_weight_based && grams > 0 ? (grams / 1000) * units : null;

  for (const row of productStockRows(product)) {
    add(row.ingredient_id, row.quantity * (kilos !== null && row.per_kg ? kilos : units));
  }
  for (const modifier of item.modifiers || []) {
    for (const row of optionStockRows(findOption(product, modifier.option_id))) {
      add(row.ingredient_id, row.quantity * (modifier.quantity || 1) * units);
    }
  }
  return usage;
}

/**
 * ¿Alcanza el stock para elegir esta opción? Se compara lo que pide el producto base más el ajuste de la opción
 * contra el stock, solo en los insumos donde la opción suma consumo (así un tamaño Ohana avisa aunque alcance
 * para el chico, H20). Solo avisa: el stock del sistema es teórico y no se debe perder una venta.
 */
export function optionLowStock(product, option, stockOf) {
  const base = new Map();
  for (const row of productStockRows(product)) {
    base.set(row.ingredient_id, (base.get(row.ingredient_id) || 0) + row.quantity);
  }
  for (const row of optionStockRows(option)) {
    if (row.quantity <= 0) continue;
    const stock = stockOf(row.ingredient_id);
    if (stock !== undefined && stock < (base.get(row.ingredient_id) || 0) + row.quantity) return true;
  }
  return false;
}
