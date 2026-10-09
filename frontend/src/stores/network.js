import { defineStore } from 'pinia';
import { db } from '../db/database';

export const useNetworkStore = defineStore('network', {
  state: () => ({
    isOnline: navigator.onLine,
    isSyncing: false,
    pendingSyncCount: 0,
    // Ventas que el servidor rechazó varias veces: ya no se reintentan solas y bloquean el cierre de caja
    failedSyncCount: 0,
  }),
  getters: {
    /** Ventas de este dispositivo que todavía no están en ninguna caja. */
    unsyncedCount: (state) => state.pendingSyncCount + state.failedSyncCount,
  },
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
        this.failedSyncCount = await db.sales.where('sync_status').equals('failed').count();
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
    },

    /**
     * Sincroniza ahora y espera el resultado (si ya había una sincronización en curso, espera a que
     * termine y vuelve a intentar). La usa el cierre de caja antes de mirar las cifras.
     */
    async syncNow() {
      const deadline = Date.now() + 15000;
      while (this.isSyncing && Date.now() < deadline) {
        await new Promise((resolve) => setTimeout(resolve, 200));
      }
      await this.triggerSync();
      await this.updatePendingCount();
    },

    /** Vuelve a enviar las ventas rechazadas (todas o las indicadas). */
    async retryFailed(ids = null) {
      const { retryFailedSales } = await import('../services/syncService.js');
      await retryFailedSales(ids);
      await this.updatePendingCount();
      await this.syncNow();
    },
  }
});
