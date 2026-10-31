import { defineStore } from 'pinia';
import { db } from '../db/database';
import { stockUsage } from '../utils/stockRecipe';
import { makeLine, addLine, replaceLine, increaseLine, decreaseLine } from '../utils/cartLines';

/**
 * Suma (sign = 1) o resta (sign = -1) al stock local lo que consume una línea de venta, en memoria y en
 * IndexedDB. Se redondea a 4 decimales como el servidor.
 */
async function adjustLocalStock(catalog, usage, sign) {
  for (const [ingredientId, quantity] of usage) {
    if (!quantity) continue;
    const ing = catalog.ingredients.find(i => i.id === ingredientId);
    if (ing && typeof ing.current_stock === 'number') {
      ing.current_stock = Math.round((ing.current_stock + sign * quantity) * 10000) / 10000;
      await db.ingredients.put(JSON.parse(JSON.stringify(ing)));
    }
  }
}

function generateUUID() {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

async function generateOrderNumber(dateObj = new Date()) {
  const day = String(dateObj.getDate()).padStart(2, '0');
  const month = String(dateObj.getMonth() + 1).padStart(2, '0');
  const year = String(dateObj.getFullYear()).slice(-2); // Formato 2 dígitos (ej: 26 para 2026)
  const datePrefix = `${day}${month}${year}`; // DDMMAA ej: 270826

  let seq = 1;
  try {
    const todaySales = await db.sales
      .filter(s => {
        if (!s.created_at) return false;
        const d = new Date(s.created_at);
        return d.getDate() === dateObj.getDate() &&
               d.getMonth() === dateObj.getMonth() &&
               d.getFullYear() === dateObj.getFullYear();
      })
      .toArray();

    if (todaySales.length > 0) {
      const maxSeq = todaySales.reduce((max, s) => {
        const sSeq = s.daily_sequence || 0;
        return sSeq > max ? sSeq : max;
      }, 0);
      seq = maxSeq + 1;
    }
  } catch (err) {
    console.warn('Error calculando secuencia diaria:', err);
  }

  const orderNumber = `${datePrefix}-${String(seq).padStart(4, '0')}`;
  return { orderNumber, dailySequence: seq };
}

export const useCartStore = defineStore('cart', {
  state: () => ({
    items: [],
    customerId: null,
    // Canal de la venta en curso: 'pos' (mostrador) o 'pedidosya' (precios de plataforma)
    channel: 'pos',
  }),
  getters: {
    subtotal: (state) => state.items.reduce((acc, item) => acc + item.subtotal, 0),
    total: (state) => state.items.reduce((acc, item) => acc + item.subtotal, 0),
    orderDestinationSummary: (state) => {
      if (!state.items || state.items.length === 0) return 'dine_in';
      const takeawayCount = state.items.filter(i => Boolean(i.is_takeaway)).length;
      if (takeawayCount === state.items.length) return 'takeaway';
      if (takeawayCount === 0) return 'dine_in';
      return 'mixed';
    },
    takeawayItemsCount: (state) => state.items.filter(i => Boolean(i.is_takeaway)).length,
    dineInItemsCount: (state) => state.items.filter(i => !Boolean(i.is_takeaway)).length,
  },
  actions: {
    toggleItemTakeaway(cartKey) {
      const item = this.items.find(i => (i.cart_key || i.cartKey) === cartKey);
      if (item) {
        item.is_takeaway = !item.is_takeaway;
      }
    },
    setAllTakeaway(isTakeaway) {
      const val = Boolean(isTakeaway);
      this.items.forEach(item => {
        item.is_takeaway = val;
      });
    },
    /**
     * Agrega un producto al ticket. Si ya hay una línea con la misma configuración, suma la cantidad;
     * un producto por peso entra siempre como línea nueva (reglas en utils/cartLines.js).
     */
    addItem(product, quantity = 1, itemNote = '', allergenFlags = []) {
      addLine(this.items, makeLine(product, quantity, itemNote, allergenFlags));
    },

    /**
     * Actualiza una línea del ticket en su lugar, con su cantidad. Si la nueva configuración coincide con
     * otra línea, se juntan las cantidades; un producto por peso no se junta nunca.
     *
     * @param {string} oldCartKey      - cart_key de la línea editada
     * @param {Object} updatedProduct  - { id, name, price, modifiers, base_price, is_takeaway, is_weight_based, weight_grams, price_per_gram }
     * @param {string} itemNote        - Nota de cocina actualizada
     * @param {Array}  allergenFlags   - Alérgenos actualizados
     */
    updateItem(oldCartKey, updatedProduct, itemNote = '', allergenFlags = []) {
      // Editar la configuración recalcula el precio desde el tarifario: el ajuste se descarta
      replaceLine(this.items, oldCartKey, makeLine(updatedProduct, 1, itemNote, allergenFlags));
    },

    /** Una unidad más de la línea. En un producto por peso no hace nada (cada bowl se pesa aparte). */
    increaseItem(cartKey) {
      return increaseLine(this.items, cartKey);
    },

    /** Una unidad menos; con una sola unidad, o en un producto por peso, quita la línea. */
    decreaseItem(cartKey) {
      return decreaseLine(this.items, cartKey);
    },

    /**
     * Ajusta el precio unitario de una línea con un motivo. Solo en pedidos PedidosYa
     * (promo de la plataforma, precio desactualizado); en el local el precio no se toca.
     * Si el precio nuevo es igual al del tarifario, se quita el ajuste.
     *
     * @returns {boolean} true si se aplicó.
     */
    overrideItemPrice(cartKey, unitPrice, reason) {
      if (this.channel !== 'pedidosya') return false;
      const item = this.items.find(i => i.cart_key === cartKey);
      if (!item || item.is_weight_based) return false;

      const price = Math.round(Number(unitPrice) * 100) / 100;
      const cleanReason = String(reason || '').trim();
      if (!Number.isFinite(price) || price < 0) return false;

      const tariff = item.catalog_unit_price ?? item.unit_price;
      if (Math.abs(price - tariff) < 0.005) {
        this.clearItemPriceOverride(cartKey);
        return true;
      }
      if (!cleanReason) return false;

      item.catalog_unit_price = tariff;
      item.unit_price = price;
      item.price_override_reason = cleanReason.slice(0, 255);
      item.subtotal = item.quantity * item.unit_price;
      return true;
    },

    /** Vuelve la línea al precio del tarifario. */
    clearItemPriceOverride(cartKey) {
      const item = this.items.find(i => i.cart_key === cartKey);
      if (!item || item.catalog_unit_price == null) return;
      item.unit_price = item.catalog_unit_price;
      item.catalog_unit_price = null;
      item.price_override_reason = null;
      item.subtotal = item.quantity * item.unit_price;
    },

    /**
     * Cambia el canal de venta. Solo con el carrito vacío, para no mezclar precios
     * de mostrador y de PedidosYa en un mismo pedido (la vista pide confirmación y vacía antes).
     */
    setChannel(channel) {
      if (this.items.length > 0) return false;
      this.channel = channel === 'pedidosya' ? 'pedidosya' : 'pos';
      if (this.channel === 'pedidosya') this.customerId = null;
      return true;
    },

    removeItem(cartKey) {
      this.items = this.items.filter(i => i.cart_key !== cartKey);
    },
    clearCart() {
      this.items = [];
      this.customerId = null;
    },
    async checkout(checkoutData) {
      if (this.items.length === 0) return null;
      
      // checkoutData contiene: { payments: [], customerId: null, discountAmount: 0, saleNote: '' }
      const payments = checkoutData.payments || [];
      const customerId = checkoutData.customerId || this.customerId;
      const discountAmount = checkoutData.discountAmount || 0;
      const saleNote = checkoutData.saleNote || '';
      
      const now = new Date();
      const { orderNumber, dailySequence } = await generateOrderNumber(now);
      
      // Soporte inteligente para destination: 'dine_in' | 'takeaway' | 'mixed'
      let isOrderTakeaway = false;
      let resolveItemTakeaway = (item) => Boolean(item.is_takeaway);

      if (checkoutData.destination === 'takeaway') {
        isOrderTakeaway = true;
        resolveItemTakeaway = () => true;
      } else if (checkoutData.destination === 'dine_in') {
        isOrderTakeaway = false;
        resolveItemTakeaway = () => false;
      } else if (checkoutData.destination === 'mixed') {
        const allTakeaway = this.items.length > 0 && this.items.every(i => Boolean(i.is_takeaway));
        isOrderTakeaway = allTakeaway;
        resolveItemTakeaway = (item) => Boolean(item.is_takeaway);
      } else {
        isOrderTakeaway = checkoutData.isTakeaway ?? this.items.some(i => i.is_takeaway);
        resolveItemTakeaway = (item) => isOrderTakeaway ? true : Boolean(item.is_takeaway);
      }

      const sale = {
        id: generateUUID(),
        order_number: orderNumber,
        daily_sequence: dailySequence,
        customer_id: customerId,
        subtotal: this.subtotal,
        discount_amount: discountAmount,
        total_amount: this.total - discountAmount,
        status: 'completed',
        preparation_status: 'received',
        source: this.channel,
        is_takeaway: isOrderTakeaway,
        notes: saleNote,
        sync_status: 'pending',
        created_at: now.toISOString(),
        items: this.items.map(i => ({
          product_id: i.product_id,
          name: i.name,
          quantity: i.quantity,
          unit_price: i.unit_price,
          subtotal: i.subtotal,
          modifiers: i.modifiers || [],
          base_price: i.base_price || i.unit_price,
          item_note: i.item_note || null,
          allergen_flags: i.allergen_flags || [],
          is_takeaway: resolveItemTakeaway(i),
          weight_grams: i.is_weight_based ? i.weight_grams : null,
          price_override_reason: this.channel === 'pedidosya' ? (i.price_override_reason || null) : null,
          // Solo para el recibo y para restaurar el ticket; el servidor no lo usa
          catalog_unit_price: i.price_override_reason ? i.catalog_unit_price : null
        })),
        payments: payments
      };

      // Guarda la venta en IndexedDB (quitando los Proxies reactivos de Vue)
      const pureSale = JSON.parse(JSON.stringify(sale));
      await db.sales.add(pureSale);

      // Notificar al network store
      const { useNetworkStore } = await import('./network.js');
      const networkStore = useNetworkStore();
      await networkStore.updatePendingCount();
      
      // Reduce el stock localmente en memoria y en IndexedDB
      const { useCatalogStore } = await import('./catalog.js');
      const catalog = useCatalogStore();
      
      try {
        // Las filas de stock vienen ya expandidas en el catálogo (componentes → insumos reales)
        await db.transaction('rw', db.ingredients, async () => {
          for (const item of this.items) {
            const product = catalog.products.find(p => p.id === item.product_id);
            await adjustLocalStock(catalog, stockUsage(product, item), -1);
          }
        });
      } catch (err) {
        console.error("Error actualizando stock local:", err);
      }

      // Limpia el carrito inmediatamente al procesar la venta exitosamente.
      // El canal vuelve a mostrador para que la siguiente venta no salga con precios PedidosYa.
      this.clearCart();
      this.channel = 'pos';

      return sale; // Devolvemos la venta para mostrar el recibo
    }
  }
});
