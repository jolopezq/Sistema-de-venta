import { defineStore } from 'pinia';
import { db } from '../db/database';
import { apiFetch } from '../services/api';

export const useCatalogStore = defineStore('catalog', {
  state: () => ({
    categories: [],
    products: [],
    customers: [],
    ingredients: [],
    isLoading: false,
    errorMessage: null,
  }),
  actions: {
    async loadFromLocal() {
      this.categories = await db.categories.toArray();
      this.products = await db.products.toArray();
      this.customers = await db.customers.toArray();
      this.ingredients = await db.ingredients.toArray();
    },
    async fetchAndCache() {
      this.isLoading = true;
      this.errorMessage = null;
      try {
        const data = await apiFetch('/catalog');
        
        await db.transaction('rw', db.categories, db.products, db.customers, db.ingredients, async () => {
          await db.categories.clear();
          await db.products.clear();
          await db.customers.clear();
          await db.ingredients.clear();
          
          await db.categories.bulkAdd(data.categories || []);
          await db.products.bulkAdd(data.products || []);
          await db.customers.bulkAdd(data.customers || []);
          await db.ingredients.bulkAdd(data.ingredients || []);
        });
        
        await this.loadFromLocal();
        this.errorMessage = null;
      } catch (error) {
        console.error('Error fetching catalog:', error);
        // Si no es un 401 (que ya redirige al login), mostramos mensaje de error
        if (error.status !== 401) {
          this.errorMessage = error.message || 'Error al obtener catálogo';
        }
        // Si falla, intentamos cargar de local
        await this.loadFromLocal();
      } finally {
        this.isLoading = false;
      }
    }
  }
});
