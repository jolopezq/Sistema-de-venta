import { defineStore } from 'pinia';
import { db } from '../db/database';

export const useNetworkStore = defineStore('network', {
  state: () => ({
    isOnline: navigator.onLine,
    isSyncing: false,
    pendingSyncCount: 0,
  }),
  actions: {
    async init() {
      await this.updatePendingCount();

      window.addEventListener('online', () => {
        this.isOnline = true;
        this.triggerSync();
      });

      window.addEventListener('offline', () => {
        this.isOnline = false;
      });

      // Si al iniciar estamos online y hay pendientes, sincronizar de inmediato
      if (this.isOnline) {
        this.triggerSync();
      }

      // Reintento automático periódico cada 30 segundos si hay ventas pendientes
      setInterval(() => {
        if (this.pendingSyncCount > 0 && this.isOnline && !this.isSyncing) {
          this.triggerSync();
        }
      }, 30000);
    },

    async updatePendingCount() {
      try {
        this.pendingSyncCount = await db.sales.where('sync_status').equals('pending').count();
      } catch (e) {
        console.warn('Error consultando ventas pendientes:', e);
      }
    },

    async triggerSync() {
      if (!this.isOnline || this.isSyncing) return;
      this.isSyncing = true;
      try {
        const { syncPendingSales } = await import('../services/syncService.js');
        await syncPendingSales();
        await this.updatePendingCount();
      } catch (e) {
        console.error("Error sincronizando ventas con el servidor:", e);
      } finally {
        this.isSyncing = false;
        await this.updatePendingCount();
      }
    }
  }
});
