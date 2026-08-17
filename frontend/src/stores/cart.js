import { defineStore } from 'pinia';
import { db } from '../db/database';

export const useCartStore = defineStore('cart', {
  state: () => ({
    items: [],
    customerId: null,
  }),
  getters: {
    subtotal: (state) => state.items.reduce((acc, item) => acc + item.subtotal, 0),
    total: (state) => state.items.reduce((acc, item) => acc + item.subtotal, 0),
  },
  actions: {
    addItem(product, quantity = 1, itemNote = '', allergenFlags = []) {
      // Create a unique key based on product ID, selected modifiers, notes and allergens
      const modifiersHash = product.modifiers && product.modifiers.length > 0 
        ? JSON.stringify(product.modifiers.map(m => m.option_id).sort()) 
        : '';
      const notesHash = itemNote ? encodeURIComponent(itemNote) : '';
      const allergensHash = allergenFlags.length > 0 ? allergenFlags.sort().join(',') : '';
      const takeawayHash = product.is_takeaway ? '-takeaway' : '';
      const cartKey = `${product.id}-${modifiersHash}-${notesHash}-${allergensHash}${takeawayHash}`;

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
      
      const sale = {
        id: (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'pos-' + Date.now() + '-' + Math.floor(Math.random()*1000), // Generación de UUID v4 en cliente o fallback
        customer_id: customerId,
        subtotal: this.subtotal,
        discount_amount: discountAmount,
        total_amount: this.total - discountAmount,
        status: 'completed',
        source: 'pos',
        notes: saleNote,
        sync_status: 'pending',
        created_at: new Date().toISOString(),
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
          is_takeaway: i.is_takeaway || false
        })),
        payments: payments
      };

      // Guarda la venta en IndexedDB (quitando los Proxies reactivos de Vue)
      const pureSale = JSON.parse(JSON.stringify(sale));
      await db.sales.add(pureSale);
      
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
                    const qtyToDeduct = recipe.quantity_required * item.quantity;
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

      this.clearCart();
      
      // Intenta sincronizar inmediatamente
      const { useNetworkStore } = await import('./network.js');
      const networkStore = useNetworkStore();
      networkStore.triggerSync();

      return sale; // Devolvemos la venta para mostrar el recibo
    }
  }
});
