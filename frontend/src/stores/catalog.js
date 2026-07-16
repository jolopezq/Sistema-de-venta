import { defineStore } from 'pinia';
import { db } from '../db/database';
import { apiFetch } from '../services/api';

export const useCatalogStore = defineStore('catalog', {
  state: () => ({
    categories: [],
    products: [],
    customers: [],
    isLoading: false,
  }),
  actions: {
    async loadFromLocal() {
      this.categories = await db.categories.toArray();
      this.products = await db.products.toArray();
      this.customers = await db.customers.toArray();
    },
    async fetchAndCache() {
      this.isLoading = true;
      try {
        const data = await apiFetch('/catalog');
        
        await db.transaction('rw', db.categories, db.products, db.customers, async () => {
          await db.categories.clear();
          await db.products.clear();
          await db.customers.clear();
          
          await db.categories.bulkAdd(data.categories);
          await db.products.bulkAdd(data.products);
          await db.customers.bulkAdd(data.customers);
        });
        
        await this.loadFromLocal();
      } catch (e) {
        console.error('Error al obtener el catálogo desde el servidor (modo offline activado)', e);
        await this.loadFromLocal();
      } finally {
        this.isLoading = false;
      }
    }
  }
});
