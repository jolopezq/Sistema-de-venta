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
    addItem(product, quantity = 1) {
      const existing = this.items.find(i => i.product_id === product.id);
      if (existing) {
        existing.quantity += quantity;
        existing.subtotal = existing.quantity * existing.unit_price;
      } else {
        this.items.push({
          product_id: product.id,
          name: product.name,
          quantity: quantity,
          unit_price: product.price,
          subtotal: quantity * product.price,
        });
      }
    },
    removeItem(productId) {
      this.items = this.items.filter(i => i.product_id !== productId);
    },
    clearCart() {
      this.items = [];
      this.customerId = null;
    },
    async checkout(paymentMethod = 'cash') {
      if (this.items.length === 0) return;
      
      const sale = {
        id: crypto.randomUUID(), // Generación de UUID v4 en cliente
        customer_id: this.customerId,
        subtotal: this.subtotal,
        discount_amount: 0,
        total_amount: this.total,
        status: 'completed',
        source: 'pos',
        sync_status: 'pending',
        created_at: new Date().toISOString(),
        items: this.items.map(i => ({
          product_id: i.product_id,
          quantity: i.quantity,
          unit_price: i.unit_price,
          subtotal: i.subtotal,
        })),
        payments: [
          { method: paymentMethod, amount: this.total }
        ]
      };

      // Guarda la venta en IndexedDB
      await db.sales.add(sale);
      
      this.clearCart();
      
      // Intenta sincronizar inmediatamente
      const { useNetworkStore } = await import('./network.js');
      const networkStore = useNetworkStore();
      networkStore.triggerSync();
    }
  }
});
