import { defineStore } from 'pinia';
import { apiFetch } from '../services/api';
import { db } from '../db/database';

export const useTurnoStore = defineStore('turno', {
  state: () => ({
    activeSession: null,
    sessionStats: null,
    sales: [],
    pagination: {
      currentPage: 1,
      lastPage: 1,
      total: 0,
      perPage: 15,
    },
    summary: {
      total_sales: 0,
      total_discounts: 0,
      sales_count: 0,
      voided_count: 0,
      cash_total: 0,
      qr_total: 0,
      card_total: 0,
    },
    filters: {
      period: 'today',
      date: new Date().toISOString().split('T')[0],
      from: null,
      to: null,
      payment_method: null,
      status: null,
      search: '',
      cashier_id: null,
      page: 1,
    },
    selectedSale: null,
    isDrawerOpen: false,
    isWizardOpen: false,
    isOpeningModalOpen: false,
    isReportModalOpen: false,
    closingReport: null,
    loading: false,
    salesLoading: false,
    error: null,
  }),

  getters: {
    hasActiveSession: (state) => !!state.activeSession && state.activeSession.status === 'open',
    currentCashInDrawer: (state) => {
      if (!state.activeSession) return 0;
      const opening = Number(state.activeSession.opening_amount || 0);
      const cashSales = Number(state.sessionStats?.cash_sales || 0);
      return opening + cashSales;
    },
  },

  actions: {
    /**
     * Consulta la sesión de caja activa actual.
     */
    async fetchActiveSession() {
      this.loading = true;
      this.error = null;
      try {
        const data = await apiFetch('/cash-sessions/active');
        this.activeSession = data.session;
        this.sessionStats = data.stats;
      } catch (err) {
        console.error('Error al obtener sesión activa:', err);
        this.error = err.message;
      } finally {
        this.loading = false;
      }
    },

    /**
     * Abre un nuevo turno de caja.
     */
    async openSession(openingAmount) {
      this.loading = true;
      this.error = null;
      try {
        const data = await apiFetch('/cash-sessions', {
          method: 'POST',
          body: JSON.stringify({ opening_amount: Number(openingAmount) }),
        });
        this.activeSession = data.session;
        this.sessionStats = data.stats;
        this.isOpeningModalOpen = false;
        // Refrescar ventas del turno
        await this.fetchSales(1);
        return data;
      } catch (err) {
        this.error = err.message;
        throw err;
      } finally {
        this.loading = false;
      }
    },

    /**
     * Cierra el turno de caja con el arqueo realizado.
     */
    async closeSession(closeData) {
      if (!this.activeSession) return;
      this.loading = true;
      this.error = null;
      try {
        const data = await apiFetch(`/cash-sessions/${this.activeSession.id}/close`, {
          method: 'POST',
          body: JSON.stringify(closeData),
        });

        this.activeSession = data.session;
        this.closingReport = data.report;
        // Limpiar progreso guardado de arqueo en localStorage
        localStorage.removeItem('cashClose_inProgress');
        return data;
      } catch (err) {
        this.error = err.message;
        throw err;
      } finally {
        this.loading = false;
      }
    },

    /**
     * Consulta el listado de ventas aplicando los filtros seleccionados.
     */
    async fetchSales(page = 1) {
      this.salesLoading = true;
      this.filters.page = page;

      try {
        // Construir parámetros de consulta
        const params = new URLSearchParams();
        params.append('page', page);
        params.append('per_page', this.pagination.perPage);

        if (this.filters.period === 'today' || this.filters.period === 'yesterday') {
          if (this.filters.date) params.append('date', this.filters.date);
        } else if (this.filters.from || this.filters.to) {
          if (this.filters.from) params.append('from', this.filters.from);
          if (this.filters.to) params.append('to', this.filters.to);
        }

        if (this.filters.payment_method) {
          params.append('payment_method', this.filters.payment_method);
        }

        if (this.filters.status) {
          params.append('status', this.filters.status);
        }

        if (this.filters.search) {
          params.append('search', this.filters.search);
        }

        if (this.filters.cashier_id) {
          params.append('cashier_id', this.filters.cashier_id);
        }

        const data = await apiFetch(`/sales?${params.toString()}`);

        this.sales = data.sales.data || [];
        this.pagination = {
          currentPage: data.sales.current_page,
          lastPage: data.sales.last_page,
          total: data.sales.total,
          perPage: data.sales.per_page,
        };
        this.summary = data.summary || {
          total_sales: 0,
          total_discounts: 0,
          sales_count: 0,
          voided_count: 0,
          cash_total: 0,
          qr_total: 0,
          card_total: 0,
        };
      } catch (err) {
        console.warn('Fallo petición API a /sales, intentando fallback IndexedDB:', err);
        await this.fetchOfflineSales();
      } finally {
        this.salesLoading = false;
      }
    },

    /**
     * Fallback offline para mostrar las ventas locales si se corta el internet.
     */
    async fetchOfflineSales() {
      try {
        const localSales = await db.sales.toArray();
        this.sales = localSales.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
        
        let total = 0;
        let cash = 0;
        let qr = 0;
        let card = 0;

        localSales.forEach(s => {
          if (s.status !== 'voided') {
            total += Number(s.total_amount || 0);
            (s.payments || []).forEach(p => {
              if (p.method === 'cash') cash += Number(p.amount || 0);
              else if (p.method === 'qr') qr += Number(p.amount || 0);
              else if (p.method === 'card') card += Number(p.amount || 0);
            });
          }
        });

        this.summary = {
          total_sales: total,
          total_discounts: 0,
          sales_count: localSales.filter(s => s.status !== 'voided').length,
          voided_count: localSales.filter(s => s.status === 'voided').length,
          cash_total: cash,
          qr_total: qr,
          card_total: card,
        };

        this.pagination = {
          currentPage: 1,
          lastPage: 1,
          total: localSales.length,
          perPage: localSales.length,
        };
      } catch (dbErr) {
        console.error('Error al consultar IndexedDB:', dbErr);
      }
    },

    /**
     * Cambia el periodo rápido (Hoy, Ayer, Esta semana, Este mes, Este año, Personalizado)
     */
    setPeriod(periodKey) {
      this.filters.period = periodKey;
      const now = new Date();
      const format = (d) => d.toISOString().split('T')[0];

      if (periodKey === 'today') {
        this.filters.date = format(now);
        this.filters.from = null;
        this.filters.to = null;
      } else if (periodKey === 'yesterday') {
        const y = new Date(now);
        y.setDate(y.getDate() - 1);
        this.filters.date = format(y);
        this.filters.from = null;
        this.filters.to = null;
      } else if (periodKey === 'week') {
        const day = now.getDay();
        const diff = now.getDate() - day + (day === 0 ? -6 : 1); // Lunes
        const monday = new Date(now.setDate(diff));
        this.filters.date = null;
        this.filters.from = format(monday);
        this.filters.to = format(new Date());
      } else if (periodKey === 'month') {
        const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
        this.filters.date = null;
        this.filters.from = format(firstDay);
        this.filters.to = format(new Date());
      } else if (periodKey === 'year') {
        const firstDayOfYear = new Date(now.getFullYear(), 0, 1);
        this.filters.date = null;
        this.filters.from = format(firstDayOfYear);
        this.filters.to = format(new Date());
      }
      this.fetchSales(1);
    },

    /**
     * Cambia el filtro por método de pago.
     */
    setPaymentMethod(method) {
      this.filters.payment_method = this.filters.payment_method === method ? null : method;
      this.fetchSales(1);
    },

    /**
     * Anula una venta con un motivo explicativo.
     */
    async voidSale(saleId, voidReason) {
      this.loading = true;
      try {
        const data = await apiFetch(`/sales/${saleId}/void`, {
          method: 'POST',
          body: JSON.stringify({ void_reason: voidReason }),
        });

        // Actualizar en listado local
        const idx = this.sales.findIndex(s => s.id === saleId);
        if (idx !== -1) {
          this.sales[idx].status = 'voided';
          this.sales[idx].void_reason = voidReason;
        }

        if (this.selectedSale && this.selectedSale.id === saleId) {
          this.selectedSale.status = 'voided';
          this.selectedSale.void_reason = voidReason;
        }

        // Actualizar sesión activa y listado
        await this.fetchActiveSession();
        await this.fetchSales(this.pagination.currentPage);

        return data;
      } catch (err) {
        throw err;
      } finally {
        this.loading = false;
      }
    },

    /**
     * Abre el drawer de detalle de una venta.
     */
    openSaleDetail(sale) {
      this.selectedSale = sale;
      this.isDrawerOpen = true;
    },

    /**
     * Cierra el drawer de detalle.
     */
    closeSaleDetail() {
      this.isDrawerOpen = false;
      this.selectedSale = null;
    },
  },
});
