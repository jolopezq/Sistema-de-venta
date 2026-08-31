import { defineStore } from 'pinia';
import { apiFetch } from '../services/api';
import { db } from '../db/database';

export const useOrderQueueStore = defineStore('orderQueue', {
  state: () => ({
    orders: [],
    counts: {
      received: 0,
      preparing: 0,
      ready: 0,
      delivered: 0,
    },
    filterSource: 'all', // 'all', 'pos', 'pedidosya'
    loading: false,
    pollTimer: null,
  }),

  getters: {
    filteredOrders: (state) => {
      if (state.filterSource === 'all') return state.orders;
      return state.orders.filter(o => o.source === state.filterSource);
    },
    ordersByStatus: (state) => (status) => {
      return state.filteredOrders.filter(o => (o.preparation_status || 'received') === status);
    },
  },

  actions: {
    /**
     * Consulta los pedidos activos del backend con fallback a IndexedDB si está offline.
     */
    async fetchOrders() {
      this.loading = true;
      try {
        const queryParams = new URLSearchParams();
        if (this.filterSource !== 'all') {
          queryParams.append('source', this.filterSource);
        }

        const res = await apiFetch(`/order-queue?${queryParams.toString()}`);
        let serverOrders = res.orders || [];

        // Integrar de inmediato ventas locales pendientes de IndexedDB que aún no se reflejen en backend
        try {
          const pendingSales = await db.sales.where('sync_status').equals('pending').toArray();
          if (pendingSales && pendingSales.length > 0) {
            const serverIds = new Set(serverOrders.map(o => String(o.id)));
            const localOrders = pendingSales
              .filter(s => !serverIds.has(String(s.id)))
              .map(s => {
                const takeawayCount = s.items?.filter(i => Boolean(i.is_takeaway)).length || 0;
                const totalCount = s.items?.length || 0;
                const isAllTakeaway = Boolean(s.is_takeaway) || (totalCount > 0 && takeawayCount === totalCount);
                const isMixed = !isAllTakeaway && takeawayCount > 0;

                let sourceLabel = 'Local / Mesa';
                if (s.source === 'pedidosya') {
                  sourceLabel = 'PedidosYa Delivery';
                } else if (isAllTakeaway) {
                  sourceLabel = 'Para Llevar';
                } else if (isMixed) {
                  sourceLabel = 'Mixto (Mesa/Llevar)';
                }

                let customerName = s.customer?.name;
                if (!customerName) {
                  if (s.source === 'pedidosya') {
                    customerName = 'Cliente PedidosYa';
                  } else if (isAllTakeaway) {
                    customerName = 'Cliente (Para Llevar)';
                  } else if (isMixed) {
                    customerName = 'Cliente (Mesa y Llevar)';
                  } else {
                    customerName = 'Cliente en Local';
                  }
                }

                return {
                  ...s,
                  is_takeaway: isAllTakeaway,
                  is_mixed: isMixed,
                  preparation_status: s.preparation_status || 'received',
                  source: s.source || 'pos',
                  source_label: sourceLabel,
                  customer_name: customerName,
                  cashier_name: s.cashier?.name || 'Cajero',
                };
              });

            if (this.filterSource === 'all') {
              serverOrders = [...localOrders, ...serverOrders];
            } else {
              serverOrders = [
                ...localOrders.filter(o => o.source === this.filterSource),
                ...serverOrders
              ];
            }
          }
        } catch (localErr) {
          console.warn('No se pudieron combinar ventas locales pendientes:', localErr);
        }

        this.orders = serverOrders;
        this.counts = {
          received: this.orders.filter(o => (o.preparation_status || 'received') === 'received').length,
          preparing: this.orders.filter(o => o.preparation_status === 'preparing').length,
          ready: this.orders.filter(o => o.preparation_status === 'ready').length,
          delivered: this.orders.filter(o => o.preparation_status === 'delivered').length,
        };
      } catch (err) {
        console.warn('Fallo al consultar /order-queue, usando fallback IndexedDB:', err);
        await this.fetchOfflineOrders();
      } finally {
        this.loading = false;
      }
    },

    /**
     * Fallback offline para mostrar pedidos guardados en IndexedDB.
     */
    async fetchOfflineOrders() {
      try {
        const localSales = await db.sales.toArray();
        this.orders = localSales.map(s => ({
          ...s,
          preparation_status: s.preparation_status || 'received',
        }));

        this.counts = {
          received: this.orders.filter(o => o.preparation_status === 'received').length,
          preparing: this.orders.filter(o => o.preparation_status === 'preparing').length,
          ready: this.orders.filter(o => o.preparation_status === 'ready').length,
          delivered: this.orders.filter(o => o.preparation_status === 'delivered').length,
        };
      } catch (e) {
        console.error('Error cargando pedidos desde IndexedDB:', e);
      }
    },

    /**
     * Avanza el estado de preparación de un pedido.
     */
    async updateOrderStatus(orderId, nextStatus) {
      // Actualización optimista en el estado local
      const idx = this.orders.findIndex(o => o.id === orderId);
      if (idx !== -1) {
        this.orders[idx].preparation_status = nextStatus;
      }

      // Actualizar en IndexedDB
      try {
        await db.sales.update(orderId, { preparation_status: nextStatus });
      } catch (e) {
        console.warn('No se pudo actualizar IndexedDB:', e);
      }

      // Actualizar en el Backend
      try {
        const res = await apiFetch(`/order-queue/${orderId}/status`, {
          method: 'PATCH',
          body: JSON.stringify({ status: nextStatus }),
        });

        if (res.order && idx !== -1) {
          this.orders[idx] = res.order;
        }
      } catch (err) {
        console.error('Error sincronizando cambio de estado con el servidor:', err);
      }
    },

    /**
     * Inicia polling periódico cada N milisegundos.
     */
    startPolling(intervalMs = 7000) {
      this.stopPolling();
      this.fetchOrders();
      this.pollTimer = setInterval(() => {
        this.fetchOrders();
      }, intervalMs);
    },

    /**
     * Detiene el polling al salir de la pantalla.
     */
    stopPolling() {
      if (this.pollTimer) {
        clearInterval(this.pollTimer);
        this.pollTimer = null;
      }
    },
  },
});
