import { defineStore } from 'pinia';
import { apiFetch } from '../services/api';

/**
 * Resumen de reposición del inventario (plan de inventario, F4): lo comparten la marca del menú «Inventario», la
 * tarjeta de Finanzas y el aviso al abrir caja. Solo lo consulta el administrador. Las alertas se calculan en el
 * servidor al momento; aquí solo se guarda la última respuesta para no pedirla en cada pantalla.
 */
const MAX_AGE_MS = 60 * 1000;

export const useReorderStore = defineStore('reorder', {
  state: () => ({
    summary: null,
    loadedAt: 0,
    loading: false,
  }),
  getters: {
    /** Insumos por reponer: agotados, críticos o bajo su punto de reorden. */
    count: (state) => state.summary?.to_reorder ?? 0,
    /** Insumos con stock que vencen pronto o ya vencieron. */
    expiring: (state) => state.summary?.expiring ?? 0,
  },
  actions: {
    /** Pide el resumen. Sin conexión o sin permiso se queda con lo último que había, sin molestar. */
    async fetchSummary({ force = false } = {}) {
      if (this.loading) return this.summary;
      if (!force && this.summary && Date.now() - this.loadedAt < MAX_AGE_MS) return this.summary;
      this.loading = true;
      try {
        this.summary = await apiFetch('/inventory/reorder/summary');
        this.loadedAt = Date.now();
      } catch (e) {
        // La marca es un aviso: si no se puede consultar, simplemente no aparece
      } finally {
        this.loading = false;
      }
      return this.summary;
    },

    /** Inventario ya trae el informe completo: de ahí sale el mismo resumen sin otra consulta. */
    setFromReport(report) {
      const items = report.items || [];
      this.summary = {
        ...report.summary,
        urgent: items
          .filter((i) => i.status === 'out' || i.status === 'critical' || i.expiry?.level === 'expired')
          .slice(0, 8)
          .map((i) => ({ id: i.id, name: i.name, unit: i.unit, status: i.status, stock: i.stock, days_of_stock: i.days_of_stock })),
        expiring_items: items
          .filter((i) => i.expiry?.level)
          .slice(0, 8)
          .map((i) => ({ id: i.id, name: i.name, unit: i.unit, stock: i.stock, expiry: i.expiry })),
      };
      this.loadedAt = Date.now();
    },
  },
});
