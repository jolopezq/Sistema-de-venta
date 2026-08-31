import { defineStore } from 'pinia';
import { db } from '../db/database';

/**
 * Genera el cart_key único para un ítem dado su configuración.
 * Centralizado para que addItem y updateItem usen la misma lógica.
 */
function buildCartKey(productId, modifiers = [], itemNote = '', allergenFlags = [], isTakeaway = false) {
  const modifiersHash = modifiers && modifiers.length > 0
    ? JSON.stringify(modifiers.map(m => `${m.option_id}:${m.quantity || 1}`).sort())
    : '';
  const notesHash = itemNote ? encodeURIComponent(itemNote) : '';
  const allergensHash = allergenFlags.length > 0 ? [...allergenFlags].sort().join(',') : '';
  const takeawayHash = isTakeaway ? '-takeaway' : '';
  return `${productId}-${modifiersHash}-${notesHash}-${allergensHash}${takeawayHash}`;
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
    addItem(product, quantity = 1, itemNote = '', allergenFlags = []) {
      const cartKey = buildCartKey(
        product.id,
        product.modifiers || [],
        itemNote,
        allergenFlags,
        product.is_takeaway || false
      );

      const existing = this.items.find(i => i.cart_key === cartKey);
      if (existing) {
        existing.quantity += quantity;
        existing.subtotal = existing.quantity * existing.unit_price;
      } else {
        this.items.push({
          cart_key: cartKey,
          product_id: product.id,
          name: product.name,
          quantity: quantity,
          unit_price: product.price,
          subtotal: quantity * product.price,
          modifiers: product.modifiers || [],
          base_price: product.base_price || product.price,
          item_note: itemNote,
          allergen_flags: allergenFlags,
          is_takeaway: product.is_takeaway || false
        });
      }
    },

    /**
     * Actualiza un ítem ya existente en el carrito manteniendo su posición y cantidad.
     * Si la nueva configuración coincide con otro ítem existente, fusiona las cantidades.
     *
     * @param {string} oldCartKey      - cart_key del ítem a reemplazar
     * @param {Object} updatedProduct  - { id, name, price, modifiers, base_price, is_takeaway }
     * @param {string} itemNote        - Nota de cocina actualizada
     * @param {Array}  allergenFlags   - Alérgenos actualizados
     */
    updateItem(oldCartKey, updatedProduct, itemNote = '', allergenFlags = []) {
      const idx = this.items.findIndex(i => i.cart_key === oldCartKey);
      if (idx === -1) return; // Ítem no encontrado, noop seguro

      const oldItem = this.items[idx];
      const newCartKey = buildCartKey(
        updatedProduct.id,
        updatedProduct.modifiers || [],
        itemNote,
        allergenFlags,
        updatedProduct.is_takeaway || false
      );

      // Si la nueva key ya existe (misma config que otro ítem), fusionamos cantidades
      const duplicateIdx = this.items.findIndex((i, j) => i.cart_key === newCartKey && j !== idx);
      if (duplicateIdx !== -1) {
        this.items[duplicateIdx].quantity += oldItem.quantity;
        this.items[duplicateIdx].subtotal = this.items[duplicateIdx].quantity * this.items[duplicateIdx].unit_price;
        this.items.splice(idx, 1);
        return;
      }

      // Reemplaza in-place preservando cantidad y posición
      this.items.splice(idx, 1, {
        cart_key: newCartKey,
        product_id: updatedProduct.id,
        name: updatedProduct.name,
        quantity: oldItem.quantity,
        unit_price: updatedProduct.price,
        subtotal: oldItem.quantity * updatedProduct.price,
        modifiers: updatedProduct.modifiers || [],
        base_price: updatedProduct.base_price || updatedProduct.price,
        item_note: itemNote,
        allergen_flags: allergenFlags,
        is_takeaway: updatedProduct.is_takeaway || false
      });
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
        source: 'pos',
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
          is_takeaway: resolveItemTakeaway(i)
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
        await db.transaction('rw', db.ingredients, async () => {
          for (const item of this.items) {
            const product = catalog.products.find(p => p.id === item.product_id);
            if (product && product.recipes) {
              for (const recipe of product.recipes) {
                 const qtyToDeduct = recipe.quantity_required * item.quantity;
                 if (qtyToDeduct > 0) {
                   const ing = catalog.ingredients.find(i => i.id === recipe.ingredient_id);
                   if (ing) {
                     ing.current_stock -= qtyToDeduct;
                     await db.ingredients.put(JSON.parse(JSON.stringify(ing)));
                   }
                 }
              }
            }
            
            if (item.modifiers && product && product.option_groups) {
              for (const modifier of item.modifiers) {
                let opt = null;
                for (const og of product.option_groups) {
                  opt = og.options.find(o => o.id === modifier.option_id);
                  if (opt) break;
                }
                if (opt && opt.recipes) {
                  for (const recipe of opt.recipes) {
                    const qtyToDeduct = recipe.quantity_required * (modifier.quantity || 1) * item.quantity;
                    if (qtyToDeduct !== 0) { // Modificadores pueden devolver stock (ej: Sin algo)
                      const ing = catalog.ingredients.find(i => i.id === recipe.ingredient_id);
                      if (ing) {
                        ing.current_stock -= qtyToDeduct;
                        await db.ingredients.put(JSON.parse(JSON.stringify(ing)));
                      }
                    }
                  }
                }
              }
            }
          }
        });
      } catch (err) {
        console.error("Error actualizando stock local:", err);
      }

      // Limpia el carrito inmediatamente al procesar la venta exitosamente
      this.clearCart();

      return sale; // Devolvemos la venta para mostrar el recibo
    },

    /**
     * Cancela una venta recién generada (antes de que el cajero confirme en la comanda)
     * y restaura el estado: elimina de IndexedDB y revierte el stock local.
     * Los items YA están en cart.items porque clearCart() aún no fue llamado.
     */
    async cancelAndRestoreSale(sale) {
      if (!sale) return;

      // 1. Eliminar la venta de IndexedDB
      if (sale.id) {
        try {
          await db.sales.delete(sale.id);
        } catch (e) {
          console.warn("No se pudo eliminar la venta de IndexedDB:", e);
        }
      }

      // 2. Revertir el stock local descontado durante checkout()
      try {
        const { useCatalogStore } = await import('./catalog.js');
        const catalog = useCatalogStore();

        if (catalog && catalog.ingredients && sale.items) {
          await db.transaction('rw', db.ingredients, async () => {
            for (const item of sale.items) {
              const product = (catalog.products || []).find(p => p.id === item.product_id);
              if (product && product.recipes) {
                for (const recipe of product.recipes) {
                  const qty = (recipe.quantity_required || 0) * (item.quantity || 1);
                  if (qty > 0) {
                    const ing = catalog.ingredients.find(i => i.id === recipe.ingredient_id);
                    if (ing && typeof ing.current_stock === 'number') {
                      ing.current_stock += qty;
                      await db.ingredients.put(JSON.parse(JSON.stringify(ing)));
                    }
                  }
                }
              }
              if (item.modifiers && product && product.option_groups) {
                for (const modifier of item.modifiers) {
                  let opt = null;
                  for (const og of product.option_groups || []) {
                    opt = (og.options || []).find(o => o.id === modifier.option_id);
                    if (opt) break;
                  }
                  if (opt && opt.recipes) {
                    for (const recipe of opt.recipes) {
                      const qty = (recipe.quantity_required || 0) * (modifier.quantity || 1) * (item.quantity || 1);
                      if (qty !== 0) {
                        const ing = catalog.ingredients.find(i => i.id === recipe.ingredient_id);
                        if (ing && typeof ing.current_stock === 'number') {
                          ing.current_stock += qty;
                          await db.ingredients.put(JSON.parse(JSON.stringify(ing)));
                        }
                      }
                    }
                  }
                }
              }
            }
          });
        }
      } catch (err) {
        console.error("Error restaurando stock local:", err);
      }

      // 3. Actualizar contador de pendientes
      try {
        const { useNetworkStore } = await import('./network.js');
        await useNetworkStore().updatePendingCount();
      } catch (err) {
        console.warn("Error actualizando networkStore:", err);
      }

      // 4. Garantizar SIEMPRE la reconstrucción de los ítems en el carrito
      if (sale && Array.isArray(sale.items) && sale.items.length > 0) {
        this.items = sale.items.map(i => ({
          cart_key: buildCartKey(
            i.product_id,
            i.modifiers || [],
            i.item_note || '',
            i.allergen_flags || [],
            i.is_takeaway || false
          ),
          product_id: i.product_id,
          name: i.name,
          quantity: i.quantity || 1,
          unit_price: Number(i.unit_price) || 0,
          subtotal: Number(i.subtotal) || (Number(i.unit_price) * (i.quantity || 1)),
          modifiers: i.modifiers || [],
          base_price: Number(i.base_price) || Number(i.unit_price) || 0,
          item_note: i.item_note || '',
          allergen_flags: i.allergen_flags || [],
          is_takeaway: i.is_takeaway || false
        }));
        this.customerId = sale.customer_id || null;
      }
    }
  }
});
