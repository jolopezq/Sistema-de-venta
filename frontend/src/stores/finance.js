import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { apiFetch } from '../services/api';

/**
 * Estado compartido del módulo de Finanzas: período, datos de cada pestaña, avisos y diálogos.
 * Cada pestaña lee de aquí; tras guardar algo se recargan solo los datos afectados.
 */

export const MONTHS = [
  'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
];

export const SECTIONS = {
  cost_of_sales: 'Costo de ventas',
  operating: 'Gastos operativos',
  financial: 'Gastos financieros',
  taxes: 'Impuestos',
};

export const PAYMENT_METHODS = {
  bank_transfer: 'Transferencia',
  qr: 'QR',
  card: 'Tarjeta',
  cash: 'Efectivo (fuera de caja)',
};

export const FREQUENCIES = {
  monthly: 'Mensual',
  bimonthly: 'Bimestral',
  quarterly: 'Trimestral',
  annual: 'Anual',
};

/** Fecha local AAAA-MM-DD (toISOString usa UTC y después de las 20:00 en La Paz da el día siguiente). */
export function localDate(d = new Date()) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export function formatBs(value, { sign = false } = {}) {
  const n = Number(value) || 0;
  const text = Math.abs(n).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  if (n < 0) return `−Bs ${text}`;
  return `${sign && n > 0 ? '+' : ''}Bs ${text}`;
}

export function formatPercent(value) {
  const n = Number(value) || 0;
  return `${n.toLocaleString('es-BO', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %`;
}

/** Día del calendario "AAAA-MM-DD" → "15/09/2026" sin pasar por Date (evita el corrimiento de zona). */
export function formatDay(value, { year = true } = {}) {
  if (!value) return '—';
  const [y, m, d] = String(value).slice(0, 10).split('-');
  return year ? `${d}/${m}/${y}` : `${d}/${m}`;
}

/** Clave para comparar nombres escritos a mano (sin mayúsculas, acentos ni espacios repetidos). */
export function nameKey(value) {
  return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
}

/** Mensaje legible de un error de apiFetch (los 422 traen el detalle en validationErrors). */
export function errorText(err, fallback = 'No se pudo completar la operación.') {
  if (err?.validationErrors) {
    const messages = Object.values(err.validationErrors).flat();
    if (messages.length) return messages.join(' ');
  }
  return err?.message || fallback;
}

export const useFinanceStore = defineStore('finance', () => {
  const now = new Date();
  const year = ref(now.getFullYear());
  const month = ref(now.getMonth() + 1);

  const periodLabel = computed(() => `${MONTHS[month.value - 1]} ${year.value}`);
  const periodQuery = computed(() => `year=${year.value}&month=${month.value}`);
  const isCurrentMonth = computed(() => year.value === now.getFullYear() && month.value === now.getMonth() + 1);

  // Datos
  const pnl = ref(null);
  const categories = ref([]);
  const expenses = ref([]);
  const expenseTotals = ref({ count: 0, amount: 0, by_section: {} });
  const expenseFilter = ref({ category_id: '', section: '' });
  const templates = ref([]);
  const recurringPeriod = ref({ items: [], pending_count: 0, pending_total: 0 });
  const cashMovements = ref({ data: [], count: 0, total: 0 });
  const settlements = ref([]);
  const suppliers = ref([]);

  // Estados de carga y error por bloque
  const loading = ref({ pnl: false, expenses: false, recurring: false, cash: false, settlements: false, categories: false });
  const errors = ref({});

  async function run(key, fn) {
    loading.value[key] = true;
    errors.value[key] = '';
    try {
      await fn();
    } catch (err) {
      errors.value[key] = errorText(err, 'No se pudieron cargar los datos.');
    } finally {
      loading.value[key] = false;
    }
  }

  const loadPnl = () => run('pnl', async () => {
    pnl.value = await apiFetch(`/finance/pnl?${periodQuery.value}`);
  });

  const loadCategories = () => run('categories', async () => {
    const data = await apiFetch('/expense-categories');
    categories.value = Array.isArray(data) ? data : [];
  });

  const loadExpenses = () => run('expenses', async () => {
    const params = new URLSearchParams({ year: year.value, month: month.value, per_page: 200 });
    if (expenseFilter.value.category_id) params.set('category_id', expenseFilter.value.category_id);
    if (expenseFilter.value.section) params.set('section', expenseFilter.value.section);
    const data = await apiFetch(`/finance/expenses?${params}`);
    expenses.value = data.data || [];
    expenseTotals.value = data.totals || { count: expenses.value.length, amount: 0, by_section: {} };
  });

  const loadRecurring = () => run('recurring', async () => {
    const [list, period] = await Promise.all([
      apiFetch('/finance/recurring-expenses'),
      apiFetch(`/finance/recurring-expenses/pending?${periodQuery.value}`),
    ]);
    templates.value = Array.isArray(list) ? list : [];
    recurringPeriod.value = period;
  });

  const loadCashMovements = () => run('cash', async () => {
    cashMovements.value = await apiFetch(`/finance/cash-movements?${periodQuery.value}`);
  });

  const loadSuppliers = () => run('suppliers', async () => {
    const data = await apiFetch('/finance/suppliers');
    suppliers.value = Array.isArray(data) ? data : [];
  });

  /** Crea una categoría que no estaba en la lista y recarga el listado. Devuelve la categoría creada. */
  async function createCategory({ name, section, group_name = null }) {
    const res = await apiFetch('/finance/categories', {
      method: 'POST',
      body: JSON.stringify({ name, section, group_name }),
    });
    await loadCategories();
    return res.category;
  }

  const loadSettlements = () => run('settlements', async () => {
    const data = await apiFetch(`/finance/pedidosya-settlements?${periodQuery.value}&per_page=20`);
    settlements.value = Array.isArray(data) ? data : (data?.data || []);
  });

  /** Datos que dependen del mes (los contadores de las pestañas se cargan con la página). */
  function loadPeriod() {
    return Promise.all([loadPnl(), loadExpenses(), loadRecurring(), loadCashMovements(), loadSettlements()]);
  }

  function loadAll() {
    return Promise.all([loadCategories(), loadPeriod()]);
  }

  function setPeriod(y, m) {
    year.value = y;
    month.value = m;
    return loadPeriod();
  }

  function shiftMonth(delta) {
    const d = new Date(year.value, month.value - 1 + delta, 1);
    return setPeriod(d.getFullYear(), d.getMonth() + 1);
  }

  // Contadores de las pestañas
  const counts = computed(() => ({
    pedidosya: settlements.value.length,
    expenses: expenseTotals.value.count + cashMovements.value.count,
    recurringPending: recurringPeriod.value.pending_count || 0,
    unclassified: cashMovements.value.data.filter((m) => !m.expense_category_id).length,
    categories: categories.value.filter((c) => c.is_active).length,
  }));

  const activeCategories = computed(() => categories.value.filter((c) => c.is_active));

  // Aviso breve (toast)
  const toast = ref({ show: false, message: '', kind: 'ok' });
  let toastTimer = null;
  function notify(message, kind = 'ok') {
    toast.value = { show: true, message, kind };
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.value.show = false; }, kind === 'error' ? 4500 : 2600);
  }

  // Diálogo de confirmación del sistema (reemplaza a window.confirm)
  const dialog = ref({ open: false, title: '', message: '', confirmLabel: 'Confirmar', danger: false, resolve: null });
  function confirm({ title, message, confirmLabel = 'Confirmar', danger = false }) {
    return new Promise((resolve) => {
      dialog.value = { open: true, title, message, confirmLabel, danger, resolve };
    });
  }
  function closeDialog(result) {
    const { resolve } = dialog.value;
    dialog.value = { ...dialog.value, open: false, resolve: null };
    if (resolve) resolve(result);
  }

  return {
    year, month, periodLabel, periodQuery, isCurrentMonth,
    pnl, categories, activeCategories, expenses, expenseTotals, expenseFilter, templates, recurringPeriod,
    cashMovements, settlements, suppliers, loading, errors, counts,
    loadPnl, loadCategories, loadSuppliers, createCategory, loadExpenses, loadRecurring, loadCashMovements, loadSettlements, loadPeriod, loadAll,
    setPeriod, shiftMonth,
    toast, notify, dialog, confirm, closeDialog,
  };
});
