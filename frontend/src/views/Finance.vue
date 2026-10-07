<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { apiFetch } from '../services/api';
import AppShell from '../components/AppShell.vue';

// Pestañas disponibles
const activeTab = ref('summary'); // 'summary' | 'expenses' | 'recurring' | 'categories'

// Estado del período para el Estado de Resultados
const currentDate = new Date();
const selectedYear = ref(currentDate.getFullYear());
const selectedMonth = ref(currentDate.getMonth() + 1);

// Datos del P&L
const pnlLoading = ref(false);
const pnlError = ref('');
const pnlData = ref(null);

// Datos de Categorías
const categories = ref([]);
const categoriesLoading = ref(false);

// Datos de Compras y Gastos
const expenses = ref([]);
const expensesLoading = ref(false);
const expenseFilterCategory = ref('');

// Modal de Gasto Directo
const isExpenseModalOpen = ref(false);
const isEditingExpense = ref(false);
const editingExpenseId = ref(null);
const expenseForm = reactive({
  date: new Date().toISOString().split('T')[0],
  expense_category_id: '',
  supplier: '',
  amount: '',
  payment_method: 'bank_transfer',
  receipt_number: '',
  notes: '',
});
const expenseFormSaving = ref(false);
const expenseFormError = ref('');

// Datos de Gastos Fijos Recurrentes
const recurringExpenses = ref([]);
const recurringLoading = ref(false);

// Modal de Gasto Fijo
const isRecurringModalOpen = ref(false);
const isEditingRecurring = ref(false);
const editingRecurringId = ref(null);
const recurringForm = reactive({
  name: '',
  expense_category_id: '',
  amount: '',
  frequency: 'monthly',
  day_of_month: 1,
  notes: '',
});
const recurringFormSaving = ref(false);
const recurringFormError = ref('');

// Modal de Categoría
const isCategoryModalOpen = ref(false);
const categoryForm = reactive({
  name: '',
  section: 'operating',
  group_name: '',
  sort_order: 10,
});
const categoryFormSaving = ref(false);
const categoryFormError = ref('');

// Meses en español
const monthNames = [
  'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
];

const periodLabel = computed(() => {
  return `${monthNames[selectedMonth.value - 1]} ${selectedYear.value}`;
});

// Carga inicial
onMounted(async () => {
  await Promise.all([
    fetchPnL(),
    fetchCategories(),
    fetchExpenses(),
    fetchRecurring(),
  ]);
});

// Navegación de mes
function prevMonth() {
  if (selectedMonth.value === 1) {
    selectedMonth.value = 12;
    selectedYear.value--;
  } else {
    selectedMonth.value--;
  }
}

function nextMonth() {
  if (selectedMonth.value === 12) {
    selectedMonth.value = 1;
    selectedYear.value++;
  } else {
    selectedMonth.value++;
  }
}

watch([selectedYear, selectedMonth], () => {
  fetchPnL();
  fetchExpenses();
});

// 1. Fetch P&L
async function fetchPnL() {
  pnlLoading.value = true;
  pnlError.value = '';
  try {
    const data = await apiFetch(`/finance/pnl?year=${selectedYear.value}&month=${selectedMonth.value}`);
    pnlData.value = data;
  } catch (err) {
    pnlError.value = err?.message || 'Error al cargar el Estado de Resultados.';
  } finally {
    pnlLoading.value = false;
  }
}

// 2. Fetch Categorías
async function fetchCategories() {
  categoriesLoading.value = true;
  try {
    const data = await apiFetch('/expense-categories');
    categories.value = Array.isArray(data) ? data : [];
  } catch (err) {
    console.error('Error al cargar categorías:', err);
  } finally {
    categoriesLoading.value = false;
  }
}

// 3. Fetch Gastos
async function fetchExpenses() {
  expensesLoading.value = true;
  try {
    let url = `/finance/expenses?year=${selectedYear.value}&month=${selectedMonth.value}&per_page=100`;
    if (expenseFilterCategory.value) {
      url += `&category_id=${expenseFilterCategory.value}`;
    }
    const data = await apiFetch(url);
    expenses.value = data.data || [];
  } catch (err) {
    console.error('Error al cargar gastos:', err);
  } finally {
    expensesLoading.value = false;
  }
}

// 4. Fetch Gastos Fijos
async function fetchRecurring() {
  recurringLoading.value = true;
  try {
    const data = await apiFetch('/finance/recurring-expenses');
    recurringExpenses.value = Array.isArray(data) ? data : [];
  } catch (err) {
    console.error('Error al cargar gastos recurrentes:', err);
  } finally {
    recurringLoading.value = false;
  }
}

// Acciones Gastos Fuera de Caja
function openCreateExpenseModal() {
  isEditingExpense.value = false;
  editingExpenseId.value = null;
  expenseForm.date = `${selectedYear.value}-${String(selectedMonth.value).padStart(2, '0')}-01`;
  expenseForm.expense_category_id = categories.value[0]?.id || '';
  expenseForm.supplier = '';
  expenseForm.amount = '';
  expenseForm.payment_method = 'bank_transfer';
  expenseForm.receipt_number = '';
  expenseForm.notes = '';
  expenseFormError.value = '';
  isExpenseModalOpen.value = true;
}

function openEditExpenseModal(item) {
  isEditingExpense.value = true;
  editingExpenseId.value = item.id;
  expenseForm.date = item.date;
  expenseForm.expense_category_id = item.expense_category_id;
  expenseForm.supplier = item.supplier || '';
  expenseForm.amount = item.amount;
  expenseForm.payment_method = item.payment_method || 'bank_transfer';
  expenseForm.receipt_number = item.receipt_number || '';
  expenseForm.notes = item.notes || '';
  expenseFormError.value = '';
  isExpenseModalOpen.value = true;
}

async function saveExpense() {
  if (!expenseForm.amount || parseFloat(expenseForm.amount) <= 0) {
    expenseFormError.value = 'El monto debe ser mayor a 0.';
    return;
  }
  if (!expenseForm.expense_category_id) {
    expenseFormError.value = 'Selecciona una categoría contable.';
    return;
  }

  expenseFormSaving.value = true;
  expenseFormError.value = '';
  try {
    if (isEditingExpense.value) {
      await apiFetch(`/finance/expenses/${editingExpenseId.value}`, {
        method: 'PUT',
        body: JSON.stringify(expenseForm),
      });
    } else {
      await apiFetch('/finance/expenses', {
        method: 'POST',
        body: JSON.stringify(expenseForm),
      });
    }
    isExpenseModalOpen.value = false;
    await Promise.all([fetchExpenses(), fetchPnL()]);
  } catch (err) {
    expenseFormError.value = err?.message || 'Error al guardar el gasto.';
  } finally {
    expenseFormSaving.value = false;
  }
}

async function deleteExpense(id) {
  if (!confirm('¿Estás seguro de eliminar este registro de compra/gasto?')) return;
  try {
    await apiFetch(`/finance/expenses/${id}`, { method: 'DELETE' });
    await Promise.all([fetchExpenses(), fetchPnL()]);
  } catch (err) {
    alert(err?.message || 'Error al eliminar');
  }
}

// Acciones Gastos Fijos
function openCreateRecurringModal() {
  isEditingRecurring.value = false;
  editingRecurringId.value = null;
  recurringForm.name = '';
  recurringForm.expense_category_id = categories.value.find(c => c.section === 'operating')?.id || categories.value[0]?.id || '';
  recurringForm.amount = '';
  recurringForm.frequency = 'monthly';
  recurringForm.day_of_month = 1;
  recurringForm.notes = '';
  recurringFormError.value = '';
  isRecurringModalOpen.value = true;
}

function openEditRecurringModal(item) {
  isEditingRecurring.value = true;
  editingRecurringId.value = item.id;
  recurringForm.name = item.name;
  recurringForm.expense_category_id = item.expense_category_id;
  recurringForm.amount = item.amount;
  recurringForm.frequency = item.frequency;
  recurringForm.day_of_month = item.day_of_month;
  recurringForm.notes = item.notes || '';
  recurringFormError.value = '';
  isRecurringModalOpen.value = true;
}

async function saveRecurring() {
  if (!recurringForm.name.trim()) {
    recurringFormError.value = 'El nombre del concepto es obligatorio.';
    return;
  }
  if (!recurringForm.amount || parseFloat(recurringForm.amount) <= 0) {
    recurringFormError.value = 'El monto debe ser mayor a 0.';
    return;
  }

  recurringFormSaving.value = true;
  recurringFormError.value = '';
  try {
    if (isEditingRecurring.value) {
      await apiFetch(`/finance/recurring-expenses/${editingRecurringId.value}`, {
        method: 'PUT',
        body: JSON.stringify(recurringForm),
      });
    } else {
      await apiFetch('/finance/recurring-expenses', {
        method: 'POST',
        body: JSON.stringify(recurringForm),
      });
    }
    isRecurringModalOpen.value = false;
    await Promise.all([fetchRecurring(), fetchPnL()]);
  } catch (err) {
    recurringFormError.value = err?.message || 'Error al guardar gasto fijo.';
  } finally {
    recurringFormSaving.value = false;
  }
}

async function toggleRecurringActive(item) {
  try {
    await apiFetch(`/finance/recurring-expenses/${item.id}/toggle-active`, { method: 'PATCH' });
    await Promise.all([fetchRecurring(), fetchPnL()]);
  } catch (err) {
    alert(err?.message || 'Error al cambiar estado.');
  }
}

async function deleteRecurring(id) {
  if (!confirm('¿Deseas eliminar este gasto fijo de la lista?')) return;
  try {
    await apiFetch(`/finance/recurring-expenses/${id}`, { method: 'DELETE' });
    await Promise.all([fetchRecurring(), fetchPnL()]);
  } catch (err) {
    alert(err?.message || 'Error al eliminar');
  }
}

// Acciones Categorías
function openCreateCategoryModal() {
  categoryForm.name = '';
  categoryForm.section = 'operating';
  categoryForm.group_name = '';
  categoryForm.sort_order = 10;
  categoryFormError.value = '';
  isCategoryModalOpen.value = true;
}

async function saveCategory() {
  if (!categoryForm.name.trim()) {
    categoryFormError.value = 'El nombre de la categoría es obligatorio.';
    return;
  }

  categoryFormSaving.value = true;
  categoryFormError.value = '';
  try {
    await apiFetch('/finance/categories', {
      method: 'POST',
      body: JSON.stringify(categoryForm),
    });
    isCategoryModalOpen.value = false;
    await fetchCategories();
  } catch (err) {
    categoryFormError.value = err?.message || 'Error al crear categoría.';
  } finally {
    categoryFormSaving.value = false;
  }
}

async function toggleCategoryActive(cat) {
  try {
    await apiFetch(`/finance/categories/${cat.id}/toggle-active`, { method: 'PATCH' });
    await fetchCategories();
  } catch (err) {
    alert(err?.message || 'Error al modificar estado');
  }
}

// Helpers visuales
function formatBs(val) {
  const n = parseFloat(val) || 0;
  return 'Bs ' + n.toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function sectionLabel(sec) {
  switch (sec) {
    case 'cost_of_sales': return 'Costo de Ventas (COGS)';
    case 'operating':     return 'Gasto Operativo (OPEX)';
    case 'financial':     return 'Gasto Financiero';
    case 'taxes':         return 'Impuestos';
    default:              return sec;
  }
}

function methodBadge(m) {
  switch (m) {
    case 'cash':          return '💵 Efectivo';
    case 'qr':            return '📱 QR';
    case 'card':          return '💳 Tarjeta';
    case 'bank_transfer': return '🏦 Transferencia';
    default:              return m;
  }
}
</script>

<template>
  <AppShell>
    <div class="fin-page">
      <!-- HEADER CON SELECTOR DE PERÍODO -->
      <header class="fin-header">
        <div class="fin-header-title">
          <div class="fin-badge">Módulo de Finanzas</div>
          <h1>Estado de Resultados y Rentabilidad</h1>
          <p class="fin-subtitle">
            Análisis financiero mensual de Ohana POS · Control de utilidad real y gastos operativos
          </p>
        </div>

        <!-- Selector de Mes y Año -->
        <div class="fin-period-picker">
          <button class="fin-btn-nav" @click="prevMonth" title="Mes anterior">◀</button>
          <div class="fin-period-display">
            <span class="fin-period-month">{{ periodLabel }}</span>
            <span class="fin-period-dates" v-if="pnlData?.period">
              {{ pnlData.period.start_date }} al {{ pnlData.period.end_date }}
            </span>
          </div>
          <button class="fin-btn-nav" @click="nextMonth" title="Mes siguiente">▶</button>
        </div>
      </header>

      <!-- NAVEGACIÓN POR PESTAÑAS -->
      <nav class="fin-tabs">
        <button
          class="fin-tab-btn"
          :class="{ active: activeTab === 'summary' }"
          @click="activeTab = 'summary'"
        >
          📊 Estado de Resultados
        </button>
        <button
          class="fin-tab-btn"
          :class="{ active: activeTab === 'expenses' }"
          @click="activeTab = 'expenses'"
        >
          🛒 Compras y Gastos ({{ expenses.length }})
        </button>
        <button
          class="fin-tab-btn"
          :class="{ active: activeTab === 'recurring' }"
          @click="activeTab = 'recurring'"
        >
          🔁 Gastos Fijos ({{ recurringExpenses.length }})
        </button>
        <button
          class="fin-tab-btn"
          :class="{ active: activeTab === 'categories' }"
          @click="activeTab = 'categories'"
        >
          🏷️ Plan de Categorías
        </button>
      </nav>

      <!-- CONTENIDO DE PESTAÑAS -->

      <!-- TAB 1: ESTADO DE RESULTADOS (P&L) -->
      <div v-if="activeTab === 'summary'" class="fin-tab-content">
        <!-- Indicador de carga -->
        <div v-if="pnlLoading" class="fin-loading">
          <div class="fin-spinner"></div>
          <span>Calculando métricas del período...</span>
        </div>

        <div v-else-if="pnlError" class="fin-alert fin-alert-error">
          ⚠️ {{ pnlError }}
        </div>

        <div v-else-if="pnlData" class="fin-summary-grid">
          <!-- TARJETAS DE KPIS PRINCIPALES -->
          <div class="fin-kpi-row">
            <!-- Ingresos -->
            <div class="fin-card kpi-card revenue">
              <div class="kpi-header">
                <span class="kpi-title">Ventas Totales</span>
                <span class="kpi-icon">💰</span>
              </div>
              <div class="kpi-value">{{ formatBs(pnlData.revenue.total) }}</div>
              <div class="kpi-details">
                <span>{{ pnlData.revenue.local_sales_count }} tickets en local</span>
                <span>Ticket prom: {{ formatBs(pnlData.revenue.average_ticket) }}</span>
              </div>
            </div>

            <!-- Costo de Ventas -->
            <div class="fin-card kpi-card cogs">
              <div class="kpi-header">
                <span class="kpi-title">Costo de Ventas (COGS)</span>
                <span class="kpi-icon">📦</span>
              </div>
              <div class="kpi-value">{{ formatBs(pnlData.cost_of_sales.total) }}</div>
              <div class="kpi-details">
                <span class="kpi-badge warning">{{ pnlData.cost_of_sales.percentage }}% de ingresos</span>
                <span>Compras de insumos</span>
              </div>
            </div>

            <!-- Utilidad Bruta -->
            <div class="fin-card kpi-card gross">
              <div class="kpi-header">
                <span class="kpi-title">Utilidad Bruta</span>
                <span class="kpi-icon">📈</span>
              </div>
              <div class="kpi-value" :class="{ negative: pnlData.gross_profit.amount < 0 }">
                {{ formatBs(pnlData.gross_profit.amount) }}
              </div>
              <div class="kpi-details">
                <span class="kpi-badge success">Margen {{ pnlData.gross_profit.percentage }}%</span>
                <span>Tras compras de insumo</span>
              </div>
            </div>

            <!-- Gastos Operativos -->
            <div class="fin-card kpi-card opex">
              <div class="kpi-header">
                <span class="kpi-title">Gastos Operativos (OPEX)</span>
                <span class="kpi-icon">🏢</span>
              </div>
              <div class="kpi-value">{{ formatBs(pnlData.operating_expenses.total) }}</div>
              <div class="kpi-details">
                <span class="kpi-badge warning">{{ pnlData.operating_expenses.percentage }}% de ingresos</span>
                <span>Sueldos, servicios, alquiler</span>
              </div>
            </div>

            <!-- Utilidad Neta -->
            <div class="fin-card kpi-card net" :class="{ 'net-positive': pnlData.net_profit.amount >= 0, 'net-negative': pnlData.net_profit.amount < 0 }">
              <div class="kpi-header">
                <span class="kpi-title">Utilidad Neta</span>
                <span class="kpi-icon">🎯</span>
              </div>
              <div class="kpi-value">
                {{ formatBs(pnlData.net_profit.amount) }}
              </div>
              <div class="kpi-details">
                <span class="kpi-badge" :class="pnlData.net_profit.amount >= 0 ? 'success' : 'danger'">
                  Margen neto {{ pnlData.net_profit.percentage }}%
                </span>
                <span>Resultado final del mes</span>
              </div>
            </div>
          </div>

          <!-- NOTA DIDÁCTICA: UTILIDAD VS CAJA -->
          <div class="fin-card fin-note-card">
            <div class="note-icon">💡</div>
            <div class="note-body">
              <strong>Regla contable clave: La Utilidad Neta no es igual al dinero en la gaveta</strong>
              <p>
                La utilidad mide si el negocio generó ganancias económicas en el mes. El saldo de efectivo
                puede variar debido a compras de stock para semanas futuras, pagos pendientes o egresos
                no operacionales.
              </p>
            </div>
          </div>

          <!-- TABLA COMPLETA DEL ESTADO DE RESULTADOS -->
          <div class="fin-card fin-pnl-table-card">
            <h2 class="fin-section-title">Estructura del Estado de Resultados ({{ periodLabel }})</h2>
            
            <table class="fin-table pnl-table">
              <thead>
                <tr>
                  <th>Concepto Contable</th>
                  <th class="text-right">Monto (Bs)</th>
                  <th class="text-right">% Ventas</th>
                </tr>
              </thead>
              <tbody>
                <!-- Ingresos -->
                <tr class="row-header">
                  <td>(+) Ingresos Operativos (Ventas del Local)</td>
                  <td class="text-right text-bold">{{ formatBs(pnlData.revenue.total) }}</td>
                  <td class="text-right text-bold">100.0%</td>
                </tr>

                <!-- Costo de Ventas -->
                <tr class="row-header row-sub">
                  <td>(−) Costo de Ventas (Compras del mes)</td>
                  <td class="text-right text-bold text-danger">{{ formatBs(pnlData.cost_of_sales.total) }}</td>
                  <td class="text-right text-danger">{{ pnlData.cost_of_sales.percentage }}%</td>
                </tr>
                <tr v-for="cat in pnlData.cost_of_sales.by_category" :key="cat.name" class="row-child">
                  <td class="pl-4">↳ {{ cat.name }} ({{ cat.group }})</td>
                  <td class="text-right">{{ formatBs(cat.amount) }}</td>
                  <td class="text-right text-muted">
                    {{ pnlData.revenue.total > 0 ? ((cat.amount / pnlData.revenue.total) * 100).toFixed(1) : '0.0' }}%
                  </td>
                </tr>

                <!-- Utilidad Bruta -->
                <tr class="row-total">
                  <td>(=) UTILIDAD BRUTA</td>
                  <td class="text-right text-bold" :class="pnlData.gross_profit.amount >= 0 ? 'text-success' : 'text-danger'">
                    {{ formatBs(pnlData.gross_profit.amount) }}
                  </td>
                  <td class="text-right text-bold">{{ pnlData.gross_profit.percentage }}%</td>
                </tr>

                <!-- Gastos Operativos -->
                <tr class="row-header row-sub">
                  <td>(−) Gastos Operativos (Fijos + Variables)</td>
                  <td class="text-right text-bold text-danger">{{ formatBs(pnlData.operating_expenses.total) }}</td>
                  <td class="text-right text-danger">{{ pnlData.operating_expenses.percentage }}%</td>
                </tr>
                <tr v-for="cat in pnlData.operating_expenses.by_category" :key="cat.name" class="row-child">
                  <td class="pl-4">↳ {{ cat.name }} ({{ cat.group }})</td>
                  <td class="text-right">{{ formatBs(cat.amount) }}</td>
                  <td class="text-right text-muted">
                    {{ pnlData.revenue.total > 0 ? ((cat.amount / pnlData.revenue.total) * 100).toFixed(1) : '0.0' }}%
                  </td>
                </tr>

                <!-- Utilidad Operativa -->
                <tr class="row-total">
                  <td>(=) UTILIDAD OPERATIVA (EBITDA)</td>
                  <td class="text-right text-bold" :class="pnlData.operating_profit.amount >= 0 ? 'text-success' : 'text-danger'">
                    {{ formatBs(pnlData.operating_profit.amount) }}
                  </td>
                  <td class="text-right text-bold">{{ pnlData.operating_profit.percentage }}%</td>
                </tr>

                <!-- Gastos Financieros -->
                <tr v-if="pnlData.financial_expenses.total > 0" class="row-child">
                  <td>(−) Gastos Financieros e Intereses</td>
                  <td class="text-right text-danger">{{ formatBs(pnlData.financial_expenses.total) }}</td>
                  <td class="text-right text-muted">
                    {{ pnlData.revenue.total > 0 ? ((pnlData.financial_expenses.total / pnlData.revenue.total) * 100).toFixed(1) : '0.0' }}%
                  </td>
                </tr>

                <!-- Impuestos -->
                <tr v-if="pnlData.taxes.total > 0" class="row-child">
                  <td>(−) Impuestos (Régimen Simplificado)</td>
                  <td class="text-right text-danger">{{ formatBs(pnlData.taxes.total) }}</td>
                  <td class="text-right text-muted">
                    {{ pnlData.revenue.total > 0 ? ((pnlData.taxes.total / pnlData.revenue.total) * 100).toFixed(1) : '0.0' }}%
                  </td>
                </tr>

                <!-- Utilidad Neta Final -->
                <tr class="row-grand-total">
                  <td>(=) UTILIDAD NETA</td>
                  <td class="text-right text-bold" :class="pnlData.net_profit.amount >= 0 ? 'text-success' : 'text-danger'">
                    {{ formatBs(pnlData.net_profit.amount) }}
                  </td>
                  <td class="text-right text-bold">{{ pnlData.net_profit.percentage }}%</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- TAB 2: COMPRAS Y GASTOS (REGISTRO FUERA DE CAJA) -->
      <div v-else-if="activeTab === 'expenses'" class="fin-tab-content">
        <div class="fin-card">
          <div class="fin-card-header">
            <div>
              <h2 class="fin-section-title">Compras y Gastos Pagados Fuera de Caja</h2>
              <p class="fin-subtitle">
                Registra compras pagadas vía transferencia bancaria, QR personal o cheques directos.
                (Los egresos desde la gaveta física ya se registraron en el turno de caja).
              </p>
            </div>
            <button class="fin-btn-primary" @click="openCreateExpenseModal">
              ➕ Registrar Compra / Gasto
            </button>
          </div>

          <!-- Filtro de categoría -->
          <div class="fin-filter-row">
            <div class="fin-field">
              <label>Filtrar por Categoría:</label>
              <select v-model="expenseFilterCategory" @change="fetchExpenses" class="fin-input">
                <option value="">-- Todas las categorías --</option>
                <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                  {{ cat.name }} ({{ cat.group_name || cat.section }})
                </option>
              </select>
            </div>
          </div>

          <!-- Tabla de Gastos -->
          <div v-if="expensesLoading" class="fin-loading">
            <div class="fin-spinner"></div>
            <span>Cargando registros...</span>
          </div>

          <div v-else-if="expenses.length === 0" class="fin-empty-state">
            <span class="empty-icon">📭</span>
            <p>No hay compras ni gastos registrados para este mes.</p>
            <button class="fin-btn-secondary" @click="openCreateExpenseModal">
              Registrar el primer gasto
            </button>
          </div>

          <table v-else class="fin-table">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Categoría</th>
                <th>Proveedor / Detalle</th>
                <th>Método</th>
                <th>Comprobante</th>
                <th class="text-right">Monto</th>
                <th class="text-center">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in expenses" :key="item.id">
                <td>{{ item.date }}</td>
                <td>
                  <span class="fin-category-badge" :class="item.category?.section">
                    {{ item.category?.name }}
                  </span>
                </td>
                <td>
                  <div class="text-bold">{{ item.supplier || '—' }}</div>
                  <div class="text-muted text-xs" v-if="item.notes">{{ item.notes }}</div>
                </td>
                <td>{{ methodBadge(item.payment_method) }}</td>
                <td>{{ item.receipt_number || '—' }}</td>
                <td class="text-right text-bold">{{ formatBs(item.amount) }}</td>
                <td class="text-center">
                  <button class="fin-action-btn" @click="openEditExpenseModal(item)" title="Editar">✏️</button>
                  <button class="fin-action-btn text-danger" @click="deleteExpense(item.id)" title="Eliminar">🗑️</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TAB 3: GASTOS FIJOS RECURRENTES -->
      <div v-else-if="activeTab === 'recurring'" class="fin-tab-content">
        <div class="fin-card">
          <div class="fin-card-header">
            <div>
              <h2 class="fin-section-title">Gastos Fijos Recurrentes (Plantillas)</h2>
              <p class="fin-subtitle">
                Conceptos fijos periódicos (Alquiler, Salarios, Internet, Cuota de Impuestos).
                Se incorporan automáticamente en el Estado de Resultados de cada mes.
              </p>
            </div>
            <button class="fin-btn-primary" @click="openCreateRecurringModal">
              ➕ Nuevo Gasto Fijo
            </button>
          </div>

          <div v-if="recurringLoading" class="fin-loading">
            <div class="fin-spinner"></div>
            <span>Cargando gastos fijos...</span>
          </div>

          <div v-else-if="recurringExpenses.length === 0" class="fin-empty-state">
            <span class="empty-icon">🔁</span>
            <p>No tienes gastos fijos configurados todavía.</p>
            <button class="fin-btn-secondary" @click="openCreateRecurringModal">
              Configurar primer gasto fijo
            </button>
          </div>

          <table v-else class="fin-table">
            <thead>
              <tr>
                <th>Concepto</th>
                <th>Categoría</th>
                <th>Frecuencia</th>
                <th>Día Estimado</th>
                <th class="text-right">Monto Estimado</th>
                <th class="text-center">Estado</th>
                <th class="text-center">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="rec in recurringExpenses" :key="rec.id">
                <td>
                  <div class="text-bold">{{ rec.name }}</div>
                  <div class="text-muted text-xs" v-if="rec.notes">{{ rec.notes }}</div>
                </td>
                <td>
                  <span class="fin-category-badge" :class="rec.category?.section">
                    {{ rec.category?.name }}
                  </span>
                </td>
                <td>
                  <span class="frequency-tag">{{ rec.frequency }}</span>
                </td>
                <td>Día {{ rec.day_of_month }}</td>
                <td class="text-right text-bold">{{ formatBs(rec.amount) }}</td>
                <td class="text-center">
                  <button
                    class="status-pill"
                    :class="rec.is_active ? 'active' : 'inactive'"
                    @click="toggleRecurringActive(rec)"
                  >
                    {{ rec.is_active ? 'Activo' : 'Pausado' }}
                  </button>
                </td>
                <td class="text-center">
                  <button class="fin-action-btn" @click="openEditRecurringModal(rec)" title="Editar">✏️</button>
                  <button class="fin-action-btn text-danger" @click="deleteRecurring(rec.id)" title="Eliminar">🗑️</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TAB 4: PLAN DE CATEGORÍAS -->
      <div v-else-if="activeTab === 'categories'" class="fin-tab-content">
        <div class="fin-card">
          <div class="fin-card-header">
            <div>
              <h2 class="fin-section-title">Plan de Cuentas y Categorías de Egresos</h2>
              <p class="fin-subtitle">
                Clasificación contable para calcular automáticamente el Costo de Ventas y Gastos Operativos.
              </p>
            </div>
            <button class="fin-btn-primary" @click="openCreateCategoryModal">
              ➕ Nueva Categoría
            </button>
          </div>

          <table class="fin-table">
            <thead>
              <tr>
                <th>Nombre</th>
                <th>Sección en P&L</th>
                <th>Grupo</th>
                <th>Orden</th>
                <th class="text-center">Estado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="cat in categories" :key="cat.id">
                <td class="text-bold">{{ cat.name }}</td>
                <td>
                  <span class="fin-category-badge" :class="cat.section">
                    {{ sectionLabel(cat.section) }}
                  </span>
                </td>
                <td>{{ cat.group_name || 'General' }}</td>
                <td>{{ cat.sort_order }}</td>
                <td class="text-center">
                  <button
                    class="status-pill"
                    :class="cat.is_active ? 'active' : 'inactive'"
                    @click="toggleCategoryActive(cat)"
                  >
                    {{ cat.is_active ? 'Activa' : 'Inactiva' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- MODALES -->

      <!-- MODAL COMPRA / GASTO DIRECTO -->
      <Teleport to="body">
        <div v-if="isExpenseModalOpen" class="fin-modal-backdrop" @click.self="isExpenseModalOpen = false">
          <div class="fin-modal-panel">
            <div class="fin-modal-header">
              <h3>{{ isEditingExpense ? 'Editar Registro de Gasto' : 'Registrar Compra o Gasto Fuera de Caja' }}</h3>
              <button class="fin-modal-close" @click="isExpenseModalOpen = false">✕</button>
            </div>

            <div class="fin-modal-body">
              <div v-if="expenseFormError" class="fin-alert fin-alert-error">
                ⚠️ {{ expenseFormError }}
              </div>

              <div class="fin-form-grid">
                <div class="fin-field">
                  <label>Fecha <span class="req">*</span></label>
                  <input type="date" v-model="expenseForm.date" class="fin-input" />
                </div>

                <div class="fin-field">
                  <label>Categoría Contable <span class="req">*</span></label>
                  <select v-model="expenseForm.expense_category_id" class="fin-input">
                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                      {{ cat.name }} · ({{ sectionLabel(cat.section) }})
                    </option>
                  </select>
                </div>

                <div class="fin-field">
                  <label>Proveedor o Beneficiario</label>
                  <input type="text" v-model="expenseForm.supplier" placeholder="Ej: Distribuidora Açaí" class="fin-input" />
                </div>

                <div class="fin-field">
                  <label>Monto (Bs) <span class="req">*</span></label>
                  <input type="number" step="0.01" min="0.01" v-model="expenseForm.amount" placeholder="0.00" class="fin-input" />
                </div>

                <div class="fin-field">
                  <label>Método de Pago <span class="req">*</span></label>
                  <select v-model="expenseForm.payment_method" class="fin-input">
                    <option value="bank_transfer">🏦 Transferencia Bancaria</option>
                    <option value="qr">📱 Pago QR</option>
                    <option value="card">💳 Tarjeta de Débito/Crédito</option>
                    <option value="cash">💵 Efectivo (Personal/Fuera de Caja)</option>
                  </select>
                </div>

                <div class="fin-field">
                  <label>Número de Recibo / Factura</label>
                  <input type="text" v-model="expenseForm.receipt_number" placeholder="Ej: Fac 1042 o Recibo #5" class="fin-input" />
                </div>

                <div class="fin-field full-width">
                  <label>Notas / Observaciones</label>
                  <textarea v-model="expenseForm.notes" rows="2" placeholder="Detalle adicional..." class="fin-input"></textarea>
                </div>
              </div>
            </div>

            <div class="fin-modal-footer">
              <button class="fin-btn-secondary" @click="isExpenseModalOpen = false" :disabled="expenseFormSaving">
                Cancelar
              </button>
              <button class="fin-btn-primary" @click="saveExpense" :disabled="expenseFormSaving">
                {{ expenseFormSaving ? 'Guardando...' : (isEditingExpense ? 'Actualizar' : 'Guardar Gasto') }}
              </button>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- MODAL GASTO FIJO RECURRENTE -->
      <Teleport to="body">
        <div v-if="isRecurringModalOpen" class="fin-modal-backdrop" @click.self="isRecurringModalOpen = false">
          <div class="fin-modal-panel">
            <div class="fin-modal-header">
              <h3>{{ isEditingRecurring ? 'Editar Gasto Fijo' : 'Nuevo Gasto Fijo Recurrente' }}</h3>
              <button class="fin-modal-close" @click="isRecurringModalOpen = false">✕</button>
            </div>

            <div class="fin-modal-body">
              <div v-if="recurringFormError" class="fin-alert fin-alert-error">
                ⚠️ {{ recurringFormError }}
              </div>

              <div class="fin-form-grid">
                <div class="fin-field full-width">
                  <label>Concepto del Gasto <span class="req">*</span></label>
                  <input type="text" v-model="recurringForm.name" placeholder="Ej: Alquiler del Local, Salario Encargado" class="fin-input" />
                </div>

                <div class="fin-field">
                  <label>Categoría Contable <span class="req">*</span></label>
                  <select v-model="recurringForm.expense_category_id" class="fin-input">
                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                      {{ cat.name }} · ({{ sectionLabel(cat.section) }})
                    </option>
                  </select>
                </div>

                <div class="fin-field">
                  <label>Monto Estimado (Bs) <span class="req">*</span></label>
                  <input type="number" step="0.01" min="0.01" v-model="recurringForm.amount" placeholder="0.00" class="fin-input" />
                </div>

                <div class="fin-field">
                  <label>Frecuencia de Pago <span class="req">*</span></label>
                  <select v-model="recurringForm.frequency" class="fin-input">
                    <option value="monthly">Mensual</option>
                    <option value="bimonthly">Bimestral (cada 2 meses)</option>
                    <option value="quarterly">Trimestral (cada 3 meses)</option>
                    <option value="annual">Anual</option>
                  </select>
                </div>

                <div class="fin-field">
                  <label>Día Habitual de Pago (1 al 31)</label>
                  <input type="number" min="1" max="31" v-model="recurringForm.day_of_month" class="fin-input" />
                </div>

                <div class="fin-field full-width">
                  <label>Notas / Condiciones</label>
                  <textarea v-model="recurringForm.notes" rows="2" placeholder="Observaciones..." class="fin-input"></textarea>
                </div>
              </div>
            </div>

            <div class="fin-modal-footer">
              <button class="fin-btn-secondary" @click="isRecurringModalOpen = false" :disabled="recurringFormSaving">
                Cancelar
              </button>
              <button class="fin-btn-primary" @click="saveRecurring" :disabled="recurringFormSaving">
                {{ recurringFormSaving ? 'Guardando...' : (isEditingRecurring ? 'Actualizar' : 'Guardar Gasto Fijo') }}
              </button>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- MODAL NUEVA CATEGORÍA -->
      <Teleport to="body">
        <div v-if="isCategoryModalOpen" class="fin-modal-backdrop" @click.self="isCategoryModalOpen = false">
          <div class="fin-modal-panel">
            <div class="fin-modal-header">
              <h3>Nueva Categoría de Gastos</h3>
              <button class="fin-modal-close" @click="isCategoryModalOpen = false">✕</button>
            </div>

            <div class="fin-modal-body">
              <div v-if="categoryFormError" class="fin-alert fin-alert-error">
                ⚠️ {{ categoryFormError }}
              </div>

              <div class="fin-form-grid">
                <div class="fin-field full-width">
                  <label>Nombre de la Categoría <span class="req">*</span></label>
                  <input type="text" v-model="categoryForm.name" placeholder="Ej: Empaques biodegradables" class="fin-input" />
                </div>

                <div class="fin-field">
                  <label>Sección en el Estado de Resultados <span class="req">*</span></label>
                  <select v-model="categoryForm.section" class="fin-input">
                    <option value="cost_of_sales">Costo de Ventas (COGS - Alimentos, insumos)</option>
                    <option value="operating">Gasto Operativo (OPEX - Sueldos, servicios)</option>
                    <option value="financial">Gasto Financiero (Intereses, bancos)</option>
                    <option value="taxes">Impuestos (Régimen simplificado)</option>
                  </select>
                </div>

                <div class="fin-field">
                  <label>Grupo Contable</label>
                  <input type="text" v-model="categoryForm.group_name" placeholder="Ej: Insumos directos, Personal..." class="fin-input" />
                </div>
              </div>
            </div>

            <div class="fin-modal-footer">
              <button class="fin-btn-secondary" @click="isCategoryModalOpen = false" :disabled="categoryFormSaving">
                Cancelar
              </button>
              <button class="fin-btn-primary" @click="saveCategory" :disabled="categoryFormSaving">
                {{ categoryFormSaving ? 'Guardando...' : 'Crear Categoría' }}
              </button>
            </div>
          </div>
        </div>
      </Teleport>
    </div>
  </AppShell>
</template>

<style scoped>
.fin-page {
  padding: 1.5rem 2rem;
  max-width: 1400px;
  margin: 0 auto;
}

/* Header */
.fin-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
  gap: 1.5rem;
  flex-wrap: wrap;
}

.fin-badge {
  display: inline-block;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 0.2rem 0.6rem;
  border-radius: 9999px;
  background: rgba(124, 58, 237, 0.15);
  color: #7c3aed;
  margin-bottom: 0.35rem;
}

html.dark .fin-badge {
  background: rgba(167, 139, 250, 0.2);
  color: #c4b5fd;
}

.fin-header h1 {
  font-size: 1.65rem;
  font-weight: 800;
  color: var(--text-primary);
  margin: 0 0 0.25rem 0;
}

.fin-subtitle {
  font-size: 0.88rem;
  color: var(--text-muted);
  margin: 0;
}

/* Picker de Período */
.fin-period-picker {
  display: flex;
  align-items: center;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 0.4rem 0.6rem;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
}

.fin-btn-nav {
  background: transparent;
  border: none;
  font-size: 1rem;
  padding: 0.4rem 0.8rem;
  border-radius: 8px;
  cursor: pointer;
  color: var(--text-primary);
  transition: background 0.15s;
}

.fin-btn-nav:hover {
  background: var(--surface-card);
}

.fin-period-display {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 0 1rem;
  min-width: 180px;
}

.fin-period-month {
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--text-primary);
}

.fin-period-dates {
  font-size: 0.72rem;
  color: var(--text-muted);
}

/* Pestañas */
.fin-tabs {
  display: flex;
  gap: 0.5rem;
  border-bottom: 2px solid var(--border);
  margin-bottom: 1.5rem;
  overflow-x: auto;
}

.fin-tab-btn {
  background: transparent;
  border: none;
  border-bottom: 3px solid transparent;
  padding: 0.75rem 1.25rem;
  font-size: 0.92rem;
  font-weight: 600;
  color: var(--text-muted);
  cursor: pointer;
  transition: all 0.2s;
  white-space: nowrap;
}

.fin-tab-btn:hover {
  color: var(--text-primary);
}

.fin-tab-btn.active {
  color: #7c3aed;
  border-bottom-color: #7c3aed;
  font-weight: 700;
}

html.dark .fin-tab-btn.active {
  color: #c4b5fd;
  border-bottom-color: #c4b5fd;
}

/* Tarjetas */
.fin-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 1.5rem;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
  margin-bottom: 1.5rem;
}

.fin-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.25rem;
  flex-wrap: wrap;
  gap: 1rem;
}

.fin-section-title {
  font-size: 1.2rem;
  font-weight: 700;
  color: var(--text-primary);
  margin: 0 0 0.25rem 0;
}

/* KPI Cards */
.fin-kpi-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1.25rem;
  margin-bottom: 1.5rem;
}

.kpi-card {
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

.kpi-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.5rem;
}

.kpi-title {
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.kpi-icon {
  font-size: 1.2rem;
}

.kpi-value {
  font-size: 1.65rem;
  font-weight: 800;
  color: var(--text-primary);
  margin: 0.25rem 0 0.5rem 0;
}

.kpi-value.negative {
  color: #ef4444;
}

.kpi-details {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.75rem;
  color: var(--text-muted);
}

.kpi-badge {
  padding: 0.15rem 0.5rem;
  border-radius: 6px;
  font-weight: 700;
  font-size: 0.72rem;
}

.kpi-badge.success {
  background: rgba(16, 185, 129, 0.15);
  color: #059669;
}

.kpi-badge.warning {
  background: rgba(245, 158, 11, 0.15);
  color: #d97706;
}

.kpi-badge.danger {
  background: rgba(239, 68, 68, 0.15);
  color: #dc2626;
}

.net-positive {
  border-color: rgba(16, 185, 129, 0.4);
  background: linear-gradient(135deg, var(--surface) 0%, rgba(16, 185, 129, 0.05) 100%);
}

.net-negative {
  border-color: rgba(239, 68, 68, 0.4);
  background: linear-gradient(135deg, var(--surface) 0%, rgba(239, 68, 68, 0.05) 100%);
}

/* Nota didáctica */
.fin-note-card {
  display: flex;
  gap: 1rem;
  align-items: flex-start;
  background: rgba(59, 130, 246, 0.08);
  border: 1px solid rgba(59, 130, 246, 0.25);
  padding: 1rem 1.25rem;
}

.note-icon {
  font-size: 1.5rem;
  line-height: 1;
}

.note-body strong {
  display: block;
  font-size: 0.9rem;
  color: var(--text-primary);
  margin-bottom: 0.25rem;
}

.note-body p {
  font-size: 0.82rem;
  color: var(--text-muted);
  margin: 0;
  line-height: 1.4;
}

/* Tablas */
.fin-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.88rem;
}

.fin-table th {
  background: var(--surface-card);
  color: var(--text-muted);
  font-weight: 700;
  text-align: left;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--border);
  font-size: 0.78rem;
  text-transform: uppercase;
}

.fin-table td {
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--border);
  color: var(--text-primary);
}

.pnl-table .row-header td {
  background: var(--surface-card);
  font-weight: 700;
}

.pnl-table .row-child td {
  background: transparent;
  font-size: 0.84rem;
}

.pnl-table .row-total td {
  background: rgba(124, 58, 237, 0.06);
  font-weight: 800;
  border-top: 2px solid var(--border);
  border-bottom: 2px solid var(--border);
}

.pnl-table .row-grand-total td {
  background: rgba(16, 185, 129, 0.1);
  font-weight: 900;
  font-size: 1rem;
  border-top: 2px solid #10b981;
  border-bottom: 2px solid #10b981;
}

.pl-4 { padding-left: 2rem !important; }
.text-right { text-align: right; }
.text-center { text-align: center; }
.text-bold { font-weight: 700; }
.text-success { color: #10b981 !important; }
.text-danger { color: #ef4444 !important; }
.text-muted { color: var(--text-muted); }
.text-xs { font-size: 0.75rem; }

/* Badges */
.fin-category-badge {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 6px;
  font-size: 0.75rem;
  font-weight: 600;
  background: var(--surface-card);
  border: 1px solid var(--border);
}

.fin-category-badge.cost_of_sales {
  background: rgba(245, 158, 11, 0.1);
  color: #b45309;
  border-color: rgba(245, 158, 11, 0.2);
}

.fin-category-badge.operating {
  background: rgba(59, 130, 246, 0.1);
  color: #1d4ed8;
  border-color: rgba(59, 130, 246, 0.2);
}

.fin-category-badge.taxes {
  background: rgba(239, 68, 68, 0.1);
  color: #b91c1c;
  border-color: rgba(239, 68, 68, 0.2);
}

.frequency-tag {
  background: var(--surface-card);
  padding: 0.2rem 0.5rem;
  border-radius: 4px;
  font-size: 0.75rem;
  text-transform: capitalize;
}

.status-pill {
  border: none;
  padding: 0.25rem 0.65rem;
  border-radius: 9999px;
  font-size: 0.72rem;
  font-weight: 700;
  cursor: pointer;
}

.status-pill.active {
  background: rgba(16, 185, 129, 0.15);
  color: #059669;
}

.status-pill.inactive {
  background: rgba(156, 163, 175, 0.2);
  color: #6b7280;
}

/* Botones */
.fin-btn-primary {
  background: #7c3aed;
  color: #fff;
  border: none;
  padding: 0.6rem 1.2rem;
  border-radius: 10px;
  font-weight: 600;
  font-size: 0.88rem;
  cursor: pointer;
  transition: background 0.15s;
}

.fin-btn-primary:hover:not(:disabled) {
  background: #6d28d9;
}

.fin-btn-secondary {
  background: var(--surface-card);
  color: var(--text-primary);
  border: 1px solid var(--border);
  padding: 0.6rem 1.2rem;
  border-radius: 10px;
  font-weight: 600;
  font-size: 0.88rem;
  cursor: pointer;
}

.fin-btn-secondary:hover:not(:disabled) {
  background: var(--surface);
}

.fin-action-btn {
  background: transparent;
  border: none;
  cursor: pointer;
  font-size: 1rem;
  padding: 0.3rem;
  margin: 0 0.2rem;
}

/* Modales */
.fin-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.65);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  padding: 1rem;
}

.fin-modal-panel {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 20px;
  width: 100%;
  max-width: 580px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
  overflow: hidden;
  animation: modalPop 0.2s ease-out;
}

@keyframes modalPop {
  from { transform: scale(0.95); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}

.fin-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid var(--border);
}

.fin-modal-header h3 {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 800;
  color: var(--text-primary);
}

.fin-modal-close {
  background: transparent;
  border: none;
  font-size: 1.2rem;
  color: var(--text-muted);
  cursor: pointer;
}

.fin-modal-body {
  padding: 1.5rem;
  max-height: 75vh;
  overflow-y: auto;
}

.fin-modal-footer {
  padding: 1rem 1.5rem;
  border-top: 1px solid var(--border);
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  background: var(--surface-card);
}

/* Formularios */
.fin-form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}

.fin-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.fin-field.full-width {
  grid-column: span 2;
}

.fin-field label {
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--text-muted);
}

.fin-field .req {
  color: #ef4444;
}

.fin-input {
  background: var(--surface-card);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 0.6rem 0.8rem;
  font-size: 0.88rem;
  color: var(--text-primary);
  width: 100%;
}

.fin-input:focus {
  outline: none;
  border-color: #7c3aed;
}

/* Alertas y Estados */
.fin-alert {
  padding: 0.75rem 1rem;
  border-radius: 8px;
  font-size: 0.85rem;
  margin-bottom: 1rem;
}

.fin-alert-error {
  background: rgba(239, 68, 68, 0.15);
  border: 1px solid rgba(239, 68, 68, 0.3);
  color: #dc2626;
}

.fin-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 3rem 0;
  color: var(--text-muted);
  font-size: 0.9rem;
}

.fin-spinner {
  width: 22px;
  height: 22px;
  border: 3px solid var(--border);
  border-top-color: #7c3aed;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.fin-empty-state {
  text-align: center;
  padding: 3rem 1rem;
  color: var(--text-muted);
}

.empty-icon {
  font-size: 2.5rem;
  display: block;
  margin-bottom: 0.5rem;
}

.fin-filter-row {
  margin-bottom: 1rem;
  max-width: 320px;
}
</style>
