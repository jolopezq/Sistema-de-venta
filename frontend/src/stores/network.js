import { defineStore } from 'pinia';

export const useNetworkStore = defineStore('network', {
  state: () => ({
    isOnline: navigator.onLine,
    isSyncing: false,
  }),
  actions: {
    init() {
      window.addEventListener('online', () => {
        this.isOnline = true;
        this.triggerSync();
      });
      window.addEventListener('offline', () => {
        this.isOnline = false;
      });
    },
    async triggerSync() {
      if (!this.isOnline || this.isSyncing) return;
      this.isSyncing = true;
      try {
        const { syncPendingSales } = await import('../services/syncService.js');
        await syncPendingSales();
      } catch (e) {
        console.error("Error sincronizando", e);
      } finally {
        this.isSyncing = false;
      }
    }
  }
});
