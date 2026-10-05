<script setup>
import { ref, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useTurnoStore } from '../stores/turno';
import { useAuthStore } from '../stores/auth';
import AdminSaleEditor from '../components/AdminSaleEditor.vue';
import SaleDetailDrawer from '../components/SaleDetailDrawer.vue';
import CashExpenseModal from '../components/CashExpenseModal.vue';
import CashCloseWizard from '../components/CashCloseWizard.vue';
import CashSessionReport from '../components/CashSessionReport.vue';

const router = useRouter();
const turnoStore = useTurnoStore();
const authStore = useAuthStore();

const isAdmin = computed(() => authStore.user?.role === 'admin' || authStore.user?.role === 'super_admin');

// Utilidad fecha local YYYY-MM-DD
function getLocalDateStr(date = new Date()) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

const todayStr = getLocalDateStr();
const selectedDate = ref(todayStr);

// Estado de sesión y datos del día seleccionado
const currentSession = ref(null);
const currentStats = ref(null);
const movements = ref([]);
const closedReport = ref(null);
const isLoadingDay = ref(false);

// Modales
const showExpenseModal = ref(false);
const showCloseWizard = ref(false);
const showReportModal = ref(false);
const sessionCount = ref(0);

// Apertura de caja rápida
const openingFund = ref(0);
const isOpeningSession = ref(false);
const openError = ref('');

// Tab inferior: 'sales' | 'expenses'
const activeTab = ref('sales');

// Determinar si la fecha seleccionada es hoy
const isToday = computed(() => selectedDate.value === todayStr);

// Estado de la sesión
const isSessionOpen = computed(() => currentSession.value && currentSession.value.status === 'open');
const isSessionClosed = computed(() => currentSession.value && currentSession.value.status === 'closed');

// Cálculos del Arqueo en tiempo real
const openingAmount = computed(() => {
  return Number(currentSession.value?.opening_amount || 0);
});

const cashSales = computed(() => {
  if (currentStats.value?.cash_sales !== undefined) {
    return Number(currentStats.value.cash_sales);
  }
  // Sin sesión: no mostrar datos del store global
  return 0;
});

const qrSales = computed(() => {
  if (currentStats.value?.qr_sales !== undefined) {
    return Number(currentStats.value.qr_sales);
  }
  return 0;
});

const cardSales = computed(() => {
  if (currentStats.value?.card_sales !== undefined) {
    return Number(currentStats.value.card_sales);
  }
  return 0;
});

const otherSales = computed(() => {
  return Number(currentStats.value?.other_sales || 0);
});

// Gastos en efectivo vs electrónicos
const cashExpenses = computed(() => {
  if (currentStats.value?.cash_expenses !== undefined) {
    return Number(currentStats.value.cash_expenses);
  }
  return movements.value
    .filter(m => m.type === 'expense' && (!m.payment_method || m.payment_method === 'cash'))
    .reduce((sum, m) => sum + Number(m.amount || 0), 0);
});

const nonCashExpenses = computed(() => {
  if (currentStats.value?.non_cash_expenses !== undefined) {
    return Number(currentStats.value.non_cash_expenses);
  }
  return movements.value
    .filter(m => m.type === 'expense' && (m.payment_method === 'qr' || m.payment_method === 'card'))
    .reduce((sum, m) => sum + Number(m.amount || 0), 0);
});

const totalExpenses = computed(() => {
  return cashExpenses.value + nonCashExpenses.value;
});

const extraIncome = computed(() => {
  return Number(currentStats.value?.extra_income || 0);
});

// Efectivo físico proyectado / esperado en gaveta
const expectedPhysicalCash = computed(() => {
  if (currentStats.value?.expected_cash !== undefined) {
    return Number(currentStats.value.expected_cash);
  }
  return Math.round((openingAmount.value + cashSales.value - cashExpenses.value + extraIncome.value) * 100) / 100;
});

// Total vendido global en el día
const dayTotalSales = computed(() => {
  // Usar stats de sesión si existen; si no, 0 (no usar el resumen global del store)
  return Number(currentStats.value?.total_sales ?? 0);
});

// Ventas del día cargadas desde el store (página actual)
const daySales = computed(() => turnoStore.sales || []);
// Total real de ventas del día (suma de todas las páginas)
const salesTotalCount = computed(() => turnoStore.pagination?.total ?? daySales.value.length);

/**
 * Carga completa de la información del día seleccionado.
 * IMPORTANTE: Se resetean los datos locales INMEDIATAMENTE al inicio
 * para evitar que el arqueo de la fecha anterior sea visible durante la carga.
 */
async function loadDayData(dateStr) {
  isLoadingDay.value = true;
  openError.value = '';

  // ―― Reset inmediato del estado local ――
  // Evita que datos de la fecha anterior persistan mientras llegan los nuevos
  currentSession.value = null;
  currentStats.value = null;
  movements.value = [];
  closedReport.value = null;

  try {
    // 1. Cargar ventas filtradas por fecha
    turnoStore.filters.period = 'custom';
    turnoStore.filters.date = dateStr;
    turnoStore.filters.from = null;
    turnoStore.filters.to = null;
    turnoStore.filters.payment_method = null;
    turnoStore.filters.status = null;
    turnoStore.filters.search = '';
    turnoStore.filters.cashier_id = null;
    await turnoStore.fetchSales(1);

    // 2. Cargar sesión de caja correspondiente a esa fecha
    if (dateStr === todayStr) {
      // Para hoy: el endpoint /active solo devuelve sesiones abiertas HOY
      await turnoStore.fetchActiveSession();
      if (turnoStore.activeSession) {
        currentSession.value = turnoStore.activeSession;
        // Obtener stats con detalle (endpoint show) para tener datos completos
        const sessionDetail = await turnoStore.fetchSessionShow(turnoStore.activeSession.id);
        currentStats.value = sessionDetail?.stats || turnoStore.sessionStats;
      } else {
        // Puede haber una sesión cerrada hoy
        const pastTodaySession = await turnoStore.fetchSessionByDate(dateStr);
        currentSession.value = pastTodaySession;
        if (pastTodaySession?.id) {
          const detail = await turnoStore.fetchSessionShow(pastTodaySession.id);
          currentStats.value = detail?.stats || null;
        } else {
          currentStats.value = null;
        }
      }
    } else {
      // Fecha histórica: buscar sesión por fecha directamente
      const histSession = await turnoStore.fetchSessionByDate(dateStr);
      currentSession.value = histSession;
      if (histSession?.id) {
        const detail = await turnoStore.fetchSessionShow(histSession.id);
        currentStats.value = detail?.stats || null;
      } else {
        currentStats.value = null;
      }
    }

    // 3. Cargar movimientos de la sesión si existe
    if (currentSession.value?.id) {
      const movs = await turnoStore.fetchSessionMovements(currentSession.value.id);
      movements.value = movs;

      // Si está cerrada, cargar reporte detallado
      if (currentSession.value.status === 'closed') {
        const rep = await turnoStore.fetchSessionReport(currentSession.value.id);
        closedReport.value = rep;
      } else {
        closedReport.value = null;
      }
    } else {
      movements.value = [];
      closedReport.value = null;
    }

    // Preparar monto de apertura sugerido si no hay sesión
    openingFund.value = Number(turnoStore.suggestedForNext || 0);
  } catch (err) {
    console.error('Error al cargar datos del día:', err);
  } finally {
    isLoadingDay.value = false;
  }
}

/**
 * Abrir sesión de caja para la fecha seleccionada (hoy o histórica)
 */
async function handleOpenSession() {
  if (openingFund.value < 0) {
    openError.value = 'El fondo inicial no puede ser negativo.';
    return;
  }
  isOpeningSession.value = true;
  openError.value = '';
  try {
    // Pasar la fecha si no es hoy
    const dateArg = selectedDate.value !== todayStr ? selectedDate.value : null;
    const data = await turnoStore.openSession(openingFund.value, dateArg);
    currentSession.value = data.session;
    currentStats.value = data.stats;
    await loadDayData(selectedDate.value);
  } catch (err) {
    openError.value = err.message || 'Error al abrir la sesión de caja.';
  } finally {
    isOpeningSession.value = false;
  }
}

/**
 * Callback al registrar una venta manual
 */
async function handleSavedSale(result) {
  sessionCount.value++;
  // Si la venta guardada tiene una fecha específica, sincronizar selectedDate para verla de inmediato
  const rawDate = result?.sale?.created_at || result?.created_at;
  if (rawDate) {
    const saleDateStr = getLocalDateStr(new Date(rawDate));
    if (saleDateStr && saleDateStr !== selectedDate.value) {
      selectedDate.value = saleDateStr;
      return;
    }
  }
  await loadDayData(selectedDate.value);
}

// Estado para Edición de Venta
const editingSale = ref(null);
const showEditModal = ref(false);

function openEditSale(sale) {
  editingSale.value = sale;
  showEditModal.value = true;
}

async function handleSaleEdited(result) {
  showEditModal.value = false;
  editingSale.value = null;
  await handleSavedSale(result);
}

function handleEditFromDrawer(sale) {
  turnoStore.closeSaleDetail();
  openEditSale(sale);
}

async function handleSaleVoided() {
  turnoStore.closeSaleDetail();
  await loadDayData(selectedDate.value);
}

/**
 * Callback al registrar un gasto/ingreso
 */
async function handleExpenseSaved() {
  await loadDayData(selectedDate.value);
}

/**
 * Callback al cerrar caja con el wizard
 */
async function handleSessionClosed(data) {
  showCloseWizard.value = false;
  await loadDayData(selectedDate.value);
  showReportModal.value = true;
}

// Navegar de vuelta a Turno
function goToTurno() {
  router.push('/turno');
}

// Formatters
function formatTime(isoStr) {
  if (!isoStr) return '--:--';
  const d = new Date(isoStr);
  return d.toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit' });
}

function formatCurrency(val) {
  return Number(val || 0).toFixed(2);
}

function getPaymentBadge(sale) {
  const p = sale.payments?.[0];
  if (!p) return { label: 'Sin pago', class: 'badge-gray' };
  if (p.method === 'cash') return { label: '💵 Efectivo', class: 'badge-cash' };
  if (p.method === 'qr') return { label: '📱 QR', class: 'badge-qr' };
  if (p.method === 'card') return { label: '💳 Tarjeta', class: 'badge-card' };
  if (p.method === 'delivery_platform') return { label: '🛵 PedidosYa', class: 'badge-pedidosya' };
  return { label: p.method, class: 'badge-gray' };
}

function getOriginBadge(sale) {
  if (sale.source === 'pedidosya') return { label: '🛵 PedidosYa', class: 'origin-pedidosya' };
  if (sale.is_takeaway) return { label: '🛍️ Para Llevar', class: 'origin-takeaway' };
  return { label: '🏪 Mesa / Tienda', class: 'origin-store' };
}

// Observar cambio de fecha — immediate:true reemplaza al onMounted
watch(selectedDate, (newDate) => {
  if (newDate) {
    loadDayData(newDate);
  }
}, { immediate: true });
</script>

<template>
  <div class="control-diario-page">

    <!-- BARRA SUPERIOR: TÍTULO, SELECTOR DE FECHA Y NAVEGACIÓN -->
    <header class="cd-topbar">
      <div class="topbar-left">
        <div class="topbar-title-row">
          <h1 class="topbar-title">Centro de Control Diario y Arqueo</h1>
          <span class="badge-role">🔒 Modo Admin</span>
        </div>
        <p class="topbar-desc">Gestión integral de ventas, gastos, fondo inicial y arqueo de caja por fecha.</p>
      </div>

      <div class="topbar-right">
        <!-- Selector de fecha -->
        <div class="date-picker-box">
          <label for="cd-date-input" class="date-label">📅 Fecha de Control:</label>
          <input
            id="cd-date-input"
            v-model="selectedDate"
            type="date"
            class="date-input"
            :max="todayStr"
          />
          <button 
            v-if="!isToday"
            class="btn-today-shortcut"
            @click="selectedDate = todayStr"
            title="Volver al día de hoy"
          >
            Ir a Hoy
          </button>
        </div>

        <button class="btn btn-outline" @click="goToTurno">
          ← Volver a POS / Turno
        </button>
      </div>
    </header>

    <!-- BANNER: CAJA NO ABIERTA HOY (Permite abrir con fondo inicial) -->
    <div v-if="isToday && !currentSession && !isLoadingDay" class="cd-open-banner">
      <div class="open-banner-info">
        <div class="banner-icon">⚡</div>
        <div>
          <h3 class="banner-title">Caja del Día no iniciada</h3>
          <p class="banner-desc">
            Inicia la sesión de caja de hoy con el fondo para cambio y vuelto. Si quedó fondo de ayer, ya está pre-cargado.
          </p>
        </div>
      </div>

      <div class="open-banner-actions">
        <div class="input-fund-group">
          <label class="fund-label">Fondo inicial (Bs):</label>
          <input
            v-model.number="openingFund"
            type="number"
            min="0"
            step="1"
            class="fund-input"
            placeholder="0.00"
            @keyup.enter="handleOpenSession"
          />
        </div>
        <button
          class="btn btn-primary btn-open-session"
          :disabled="isOpeningSession"
          @click="handleOpenSession"
        >
          <span v-if="isOpeningSession">Iniciando caja…</span>
          <span v-else>🟢 Abrir Caja del Día →</span>
        </button>
      </div>
      <p v-if="openError" class="open-error-msg">⚠️ {{ openError }}</p>
    </div>

    <!-- BANNER: SESIÓN HISTÓRICA SIN CAJA (Permite abrir sesión histórica) -->
    <div v-else-if="!isToday && !currentSession && !isLoadingDay" class="cd-open-banner cd-open-banner--historical">
      <div class="open-banner-info">
        <div class="banner-icon">📅</div>
        <div>
          <h3 class="banner-title">Sin Caja Registrada para el {{ selectedDate }}</h3>
          <p class="banner-desc">
            No existe sesión de caja para este día histórico. Puedes abrir la caja de esta fecha con su fondo inicial para registrar gastos y realizar el arqueo/cierre de caja correspondiente.
          </p>
        </div>
      </div>

      <div class="open-banner-actions">
        <div class="input-fund-group">
          <label class="fund-label">Fondo inicial (Bs):</label>
          <input
            v-model.number="openingFund"
            type="number"
            min="0"
            step="1"
            class="fund-input"
            placeholder="0.00"
            @keyup.enter="handleOpenSession"
          />
        </div>
        <button
          class="btn btn-primary btn-open-session btn-open-historical"
          :disabled="isOpeningSession"
          @click="handleOpenSession"
        >
          <span v-if="isOpeningSession">Iniciando caja histórica…</span>
          <span v-else>🟣 Abrir Caja del {{ selectedDate }} →</span>
        </button>
      </div>
      <p v-if="openError" class="open-error-msg">⚠️ {{ openError }}</p>
    </div>

    <!-- SECCIÓN PRINCIPAL: 2 COLUMNAS (IZQUIERDA: EDITOR DE VENTAS / DERECHA: PANEL DE ARQUEO EN VIVO) -->
    <div class="cd-grid">

      <!-- COLUMNA IZQUIERDA: INGRESO DE COMANDAS / VENTAS MANUALES -->
      <section class="cd-col-editor">
        <div class="editor-wrapper">
          <div class="editor-header-bar">
            <div class="editor-bar-title">
              <span class="editor-icon">📝</span>
              <span>Registrar Venta o Comanda del Día</span>
            </div>
            <div class="editor-date-badge">
              Fecha asignada: <strong>{{ selectedDate }}</strong>
            </div>
          </div>

          <AdminSaleEditor
            :visible="true"
            :inline="true"
            :semiLote="true"
            :defaultDate="selectedDate"
            @saved="handleSavedSale"
            @close="goToTurno"
          />
        </div>
      </section>

      <!-- COLUMNA DERECHA: PANEL DE CONTROL Y ARQUEO EN TIEMPO REAL -->
      <aside class="cd-col-panel">
        
        <!-- CARD DE ESTADO Y ARQUEO -->
        <div class="arqueo-card" :class="{ 'session-closed': isSessionClosed }">
          
          <!-- Encabezado del Arqueo -->
          <div class="arqueo-header">
            <div>
              <span class="arqueo-label">ESTADO DEL DÍA</span>
              <h2 class="arqueo-title">Arqueo de Caja</h2>
            </div>
            
            <div class="session-status-badge">
              <span v-if="isSessionOpen" class="badge-status open">
                <span class="dot pulse"></span> Caja Abierta
              </span>
              <span v-else-if="isSessionClosed" class="badge-status closed">
                🔒 Cerrada (Arqueada)
              </span>
              <span v-else class="badge-status unrecorded">
                Sin Sesión Oficial
              </span>
            </div>
          </div>

          <!-- CIFRA REINA: EFECTIVO FÍSICO ESPERADO EN GAVETA -->
          <div class="drawer-cash-hero">
            <span class="drawer-cash-label">💵 Efectivo Físico en Gaveta</span>
            <div class="drawer-cash-amount">
              <span class="currency">Bs</span>
              <span class="val">{{ formatCurrency(expectedPhysicalCash) }}</span>
            </div>
            <span class="drawer-cash-sub">
              (Fondo inicial + Ventas efectivo - Gastos en efectivo)
            </span>
          </div>

          <!-- DESGLOSE DETALLADO -->
          <div class="arqueo-breakdown">
            <div class="breakdown-row">
              <span class="row-label">🪙 Fondo inicial de caja:</span>
              <strong class="row-val">Bs {{ formatCurrency(openingAmount) }}</strong>
            </div>

            <div class="breakdown-divider"></div>

            <div class="breakdown-row highlight-income">
              <span class="row-label">💵 Ventas en Efectivo:</span>
              <strong class="row-val income">+ Bs {{ formatCurrency(cashSales) }}</strong>
            </div>

            <div class="breakdown-row">
              <span class="row-label">📱 Ventas vía QR / Banco:</span>
              <span class="row-val">Bs {{ formatCurrency(qrSales) }}</span>
            </div>

            <div class="breakdown-row">
              <span class="row-label">💳 Ventas con Tarjeta:</span>
              <span class="row-val">Bs {{ formatCurrency(cardSales) }}</span>
            </div>

            <div class="breakdown-row" v-if="otherSales > 0">
              <span class="row-label">🛵 Ventas PedidosYa / Otro:</span>
              <span class="row-val">Bs {{ formatCurrency(otherSales) }}</span>
            </div>

            <div class="breakdown-divider"></div>

            <!-- GASTOS -->
            <div class="breakdown-row highlight-expense">
              <span class="row-label">💸 Gastos en Efectivo (Gaveta):</span>
              <strong class="row-val expense">- Bs {{ formatCurrency(cashExpenses) }}</strong>
            </div>

            <div class="breakdown-row" v-if="nonCashExpenses > 0">
              <span class="row-label">📲 Gastos QR / Tarjeta:</span>
              <span class="row-val text-muted">Bs {{ formatCurrency(nonCashExpenses) }}</span>
            </div>

            <div class="breakdown-row" v-if="extraIncome > 0">
              <span class="row-label">📥 Ingresos Extraordinarios:</span>
              <span class="row-val income">+ Bs {{ formatCurrency(extraIncome) }}</span>
            </div>

            <div class="breakdown-divider"></div>

            <div class="breakdown-row total-row">
              <span class="row-label">Total Ventas del Día:</span>
              <strong class="row-val total-sales">Bs {{ formatCurrency(dayTotalSales) }}</strong>
            </div>
          </div>

          <!-- RESUMEN SI LA SESIÓN FUE CERRADA -->
          <div v-if="isSessionClosed" class="closed-summary-box">
            <h4 class="closed-box-title">📋 Cierre Registrado</h4>
            <div class="closed-box-grid">
              <div>
                <span class="c-label">Contado:</span>
                <strong>Bs {{ formatCurrency(currentSession.actual_closing) }}</strong>
              </div>
              <div>
                <span class="c-label">Diferencia:</span>
                <strong :class="currentSession.difference >= 0 ? 'text-success' : 'text-danger'">
                  {{ currentSession.difference >= 0 ? '+' : '' }}Bs {{ formatCurrency(currentSession.difference) }}
                </strong>
              </div>
              <div>
                <span class="c-label">Sobre Retirado:</span>
                <strong>Bs {{ formatCurrency(currentSession.cash_withdrawn) }}</strong>
              </div>
              <div>
                <span class="c-label">Quedó para cambio:</span>
                <strong>Bs {{ formatCurrency(currentSession.cash_left_for_next) }}</strong>
              </div>
            </div>
            <p v-if="currentSession.diff_note" class="diff-note">
              <em>Nota: {{ currentSession.diff_note }}</em>
            </p>
            <button class="btn btn-secondary btn-full" @click="showReportModal = true">
              📄 Ver Ticket Completo de Cierre
            </button>
          </div>

          <!-- BOTONES DE ACCIÓN RÁPIDA (Solo si la sesión está abierta) -->
          <div v-if="isSessionOpen" class="arqueo-actions">
            <button
              class="btn btn-expense"
              @click="showExpenseModal = true"
            >
              💸 Registrar Gasto / Compra
            </button>

            <button
              class="btn btn-close-day"
              @click="showCloseWizard = true"
            >
              🔒 Realizar Arqueo y Cerrar Día
            </button>
          </div>

          <div v-else-if="!isSessionClosed && !isToday" class="historical-notice">
            <small>⚠️ Modo de consulta histórica. Las ventas registradas aquí se guardan con fecha {{ selectedDate }}.</small>
          </div>

        </div>

      </aside>

    </div>

    <!-- SECCIÓN INFERIOR: TABLA DE VENTAS Y GASTOS DEL DÍA -->
    <section class="cd-bottom-records">
      
      <!-- Navegación de Pestañas -->
      <div class="records-tabs">
        <button
          class="tab-btn"
          :class="{ active: activeTab === 'sales' }"
          @click="activeTab = 'sales'"
        >
          🧾 Ventas del Día ({{ salesTotalCount }})
        </button>
        <button
          class="tab-btn"
          :class="{ active: activeTab === 'expenses' }"
          @click="activeTab = 'expenses'"
        >
          💸 Gastos e Insumos ({{ movements.length }})
        </button>
      </div>

      <!-- TAB 1: LISTADO DE VENTAS -->
      <div v-if="activeTab === 'sales'" class="records-content">
        <div v-if="daySales.length === 0" class="empty-table-state">
          <span class="empty-table-icon">🛍️</span>
          <p>Aún no hay ventas registradas para el día {{ selectedDate }}.</p>
          <small>Usa el formulario superior para registrar la primera comanda.</small>
        </div>

        <div v-else class="table-responsive">
          <table class="cd-table">
            <thead>
              <tr>
                <th>Hora</th>
                <th>Origen</th>
                <th>Productos / Detalle</th>
                <th>Método de Pago</th>
                <th class="text-right">Total</th>
                <th class="text-center">Estado</th>
                <th class="text-right">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr 
                v-for="sale in daySales" 
                :key="sale.id" 
                :class="{ 'row-voided': sale.status === 'voided' }"
                class="clickable-row"
                @click="turnoStore.openSaleDetail(sale)"
              >
                <td class="col-time">{{ formatTime(sale.created_at) }}</td>
                <td>
                  <span class="origin-badge" :class="getOriginBadge(sale).class">
                    {{ getOriginBadge(sale).label }}
                  </span>
                </td>
                <td class="col-items">
                  <div class="items-summary-list">
                    <span
                      v-for="(item, idx) in sale.items"
                      :key="idx"
                      class="item-summary-pill"
                    >
                      {{ item.quantity }}x {{ item.product?.name || item.name || 'Producto' }}
                    </span>
                  </div>
                  <div v-if="sale.notes" class="sale-note-sub">
                    💬 {{ sale.notes }}
                  </div>
                </td>
                <td>
                  <span class="payment-badge" :class="getPaymentBadge(sale).class">
                    {{ getPaymentBadge(sale).label }}
                  </span>
                </td>
                <td class="col-total text-right">
                  <strong>Bs {{ formatCurrency(sale.total_amount) }}</strong>
                </td>
                <td class="text-center">
                  <span v-if="sale.status === 'completed'" class="status-pill status-completed">
                    Completada
                  </span>
                  <span v-else-if="sale.status === 'voided'" class="status-pill status-voided" :title="sale.void_reason">
                    Anulada
                  </span>
                  <span v-else class="status-pill status-pending">
                    {{ sale.status }}
                  </span>
                </td>
                <td class="col-actions text-right" @click.stop>
                  <div class="actions-cell">
                    <button 
                      type="button" 
                      class="btn-table-action btn-table-view"
                      @click="turnoStore.openSaleDetail(sale)"
                      title="Ver detalle del ticket"
                    >
                      👁️ Detalle
                    </button>
                    <button 
                      v-if="isAdmin && sale.status !== 'voided'"
                      type="button" 
                      class="btn-table-action btn-table-edit"
                      @click="openEditSale(sale)"
                      title="Editar comanda/venta (Admin / Super Admin)"
                    >
                      ✏️ Editar
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TAB 2: LISTADO DE GASTOS Y MOVIMIENTOS -->
      <div v-if="activeTab === 'expenses'" class="records-content">
        <div v-if="movements.length === 0" class="empty-table-state">
          <span class="empty-table-icon">🧾</span>
          <p>No se han registrado compras ni gastos para esta sesión de caja.</p>
          <small>Puedes registrar compras de insumos, frutas, bolsas o pagos con el botón superior.</small>
        </div>

        <div v-else class="table-responsive">
          <table class="cd-table">
            <thead>
              <tr>
                <th>Hora</th>
                <th>Tipo</th>
                <th>Método de Pago</th>
                <th>Descripción / Concepto</th>
                <th>Registrado Por</th>
                <th class="text-right">Monto</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in movements" :key="m.id">
                <td class="col-time">{{ formatTime(m.created_at) }}</td>
                <td>
                  <span :class="m.type === 'expense' ? 'pill-expense' : 'pill-income'">
                    {{ m.type === 'expense' ? '📤 Egreso' : '📥 Ingreso Extra' }}
                  </span>
                </td>
                <td>
                  <span v-if="m.payment_method === 'cash'" class="payment-badge badge-cash">
                    💵 Efectivo (Gaveta)
                  </span>
                  <span v-else-if="m.payment_method === 'qr'" class="payment-badge badge-qr">
                    📱 QR / Banco
                  </span>
                  <span v-else-if="m.payment_method === 'card'" class="payment-badge badge-card">
                    💳 Tarjeta
                  </span>
                  <span v-else class="payment-badge badge-gray">
                    {{ m.payment_method || 'Efectivo' }}
                  </span>
                </td>
                <td class="col-desc">{{ m.description }}</td>
                <td class="col-user">{{ m.user?.name || 'Usuario' }}</td>
                <td class="col-total text-right" :class="m.type === 'expense' ? 'text-expense' : 'text-income'">
                  <strong>{{ m.type === 'expense' ? '-' : '+' }} Bs {{ formatCurrency(m.amount) }}</strong>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </section>

    <!-- MODAL DE REGISTRO DE GASTO -->
    <CashExpenseModal
      :visible="showExpenseModal"
      :sessionId="currentSession?.id"
      @close="showExpenseModal = false"
      @saved="handleExpenseSaved"
    />

    <!-- WIZARD DE CIERRE DE CAJA Y ARQUEO -->
    <Teleport to="body">
      <div v-if="showCloseWizard" class="wizard-modal-overlay">
        <div class="wizard-modal-container">
          <CashCloseWizard
            :session="currentSession"
            :stats="currentStats"
            @close="showCloseWizard = false"
            @closed="handleSessionClosed"
          />
        </div>
      </div>
    </Teleport>

    <!-- MODAL DE VISUALIZACIÓN DE REPORTE DE CIERRE HISTÓRICO -->
    <Teleport to="body">
      <div v-if="showReportModal && closedReport" class="report-modal-overlay" @click.self="showReportModal = false">
        <div class="report-modal-card">
          <div class="report-modal-header">
            <h3>Reporte Oficial de Cierre</h3>
            <button class="btn-close-modal" @click="showReportModal = false">✕</button>
          </div>
          <div class="report-modal-body">
            <CashSessionReport :report="closedReport" />
          </div>
          <div class="report-modal-footer">
            <button class="btn btn-secondary" @click="showReportModal = false">Cerrar</button>
            <button class="btn btn-primary" @click="window.print()">🖨️ Imprimir Ticket</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- DRAWER DE DETALLE DE VENTA -->
    <SaleDetailDrawer
      @close="turnoStore.closeSaleDetail"
      @voided="handleSaleVoided"
      @edit="handleEditFromDrawer"
    />

    <!-- MODAL DE EDICIÓN DE VENTA (SUPER ADMIN) -->
    <AdminSaleEditor
      v-if="showEditModal"
      :visible="showEditModal"
      :sale="editingSale"
      :inline="false"
      @saved="handleSaleEdited"
      @close="showEditModal = false"
    />

  </div>
</template>

<style scoped>
.control-diario-page {
  padding: 1.5rem;
  max-width: 1400px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  font-family: inherit;
}

/* TOPBAR */
.cd-topbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
  background: var(--color-surface-2, var(--tw-bg-fff));
  padding: 1.25rem 1.75rem;
  border-radius: 14px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.04);
  border: 1px solid var(--color-border, var(--tw-bg-e2e8f0));
}

.topbar-title-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.topbar-title {
  margin: 0;
  font-size: 1.45rem;
  font-weight: 700;
  color: var(--color-text-primary, var(--tw-fg-0f172a));
}

.badge-role {
  background: var(--tw-bg-fef2f2);
  color: var(--tw-fg-dc2626);
  font-size: 0.75rem;
  font-weight: 700;
  padding: 3px 8px;
  border-radius: 6px;
  border: 1px solid var(--tw-bg-fecaca);
}

.topbar-desc {
  margin: 0.25rem 0 0 0;
  font-size: 0.85rem;
  color: var(--color-text-secondary, var(--tw-fg-64748b));
}

.topbar-right {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.date-picker-box {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  background: var(--color-surface-3, var(--tw-bg-f8fafc));
  padding: 0.4rem 0.75rem;
  border-radius: 10px;
  border: 1.5px solid var(--color-border, var(--tw-bg-cbd5e1));
}

.date-label {
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--tw-fg-334155);
}

.date-input {
  border: none;
  background: transparent;
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--tw-fg-0f172a);
  outline: none;
  cursor: pointer;
}

.btn-today-shortcut {
  background: #6366f1;
  color: white;
  border: none;
  border-radius: 6px;
  padding: 3px 8px;
  font-size: 0.75rem;
  font-weight: 600;
  cursor: pointer;
  transition: opacity 0.15s;
}

.btn-today-shortcut:hover {
  opacity: 0.9;
}

/* BANNERS */
.cd-open-banner {
  background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
  color: white;
  padding: 1.25rem 1.75rem;
  border-radius: 14px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 1.25rem;
  box-shadow: 0 4px 16px rgba(49, 46, 129, 0.2);
}

.open-banner-info {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.banner-icon {
  font-size: 2rem;
  background: rgba(255, 255, 255, 0.15);
  width: 48px;
  height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 12px;
}

.banner-title {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 700;
}

.banner-desc {
  margin: 0.2rem 0 0 0;
  font-size: 0.85rem;
  color: #c7d2fe;
}

.open-banner-actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.input-fund-group {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  background: rgba(255,255,255,0.1);
  padding: 0.4rem 0.75rem;
  border-radius: 8px;
  border: 1px solid rgba(255,255,255,0.2);
}

.fund-label {
  font-size: 0.82rem;
  font-weight: 600;
  color: #e0e7ff;
}

.fund-input {
  background: transparent;
  border: none;
  color: white;
  font-size: 1rem;
  font-weight: 700;
  width: 90px;
  outline: none;
}

.btn-open-session {
  background: #10b981;
  color: white;
  border: none;
  padding: 0.65rem 1.25rem;
  border-radius: 8px;
  font-size: 0.95rem;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.15s;
}

.btn-open-session:hover {
  background: #059669;
}

.open-error-msg {
  width: 100%;
  color: #fca5a5;
  font-size: 0.85rem;
  margin: 0.5rem 0 0 0;
}

.cd-empty-banner {
  background: var(--tw-bg-f8fafc);
  border: 1px solid var(--tw-bg-cbd5e1);
  padding: 1rem 1.25rem;
  border-radius: 12px;
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
  color: var(--tw-fg-334155);
  font-size: 0.88rem;
}

.cd-open-banner--historical {
  background: linear-gradient(135deg, #2e1065 0%, #4c1d95 100%);
  box-shadow: 0 4px 16px rgba(76, 29, 149, 0.25);
}

.cd-open-banner--historical .banner-desc {
  color: #ddd6fe;
}

.btn-open-historical {
  background: #8b5cf6;
}

.btn-open-historical:hover {
  background: #7c3aed;
}

/* GRID PRINCIPAL: EDITOR + ARQUEO */
.cd-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 350px;
  gap: 1.5rem;
  align-items: start;
}

@media (max-width: 1250px) {
  .cd-grid {
    grid-template-columns: 1fr;
  }
}

/* COLUMNA EDITOR */
.cd-col-editor {
  min-width: 0;
}

.editor-wrapper {
  background: var(--color-surface-2, var(--tw-bg-fff));
  border-radius: 14px;
  border: 1px solid var(--color-border, var(--tw-bg-e2e8f0));
  box-shadow: 0 2px 10px rgba(0,0,0,0.04);
  overflow: hidden;
}

.editor-header-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: var(--tw-bg-f8fafc);
  padding: 0.75rem 1.25rem;
  border-bottom: 1px solid var(--tw-bg-e2e8f0);
}

.editor-bar-title {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-weight: 700;
  font-size: 0.95rem;
  color: var(--tw-fg-1e293b);
}

.editor-date-badge {
  font-size: 0.8rem;
  color: var(--tw-fg-475569);
  background: var(--tw-bg-e2e8f0);
  padding: 2px 8px;
  border-radius: 6px;
}

/* COLUMNA ARQUEO PANEL */
.cd-col-panel {
  position: sticky;
  top: 1rem;
}

.arqueo-card {
  background: var(--color-surface-2, var(--tw-bg-fff));
  border: 1.5px solid var(--color-border, var(--tw-bg-e2e8f0));
  border-radius: 14px;
  padding: 1.5rem;
  box-shadow: 0 4px 16px rgba(0,0,0,0.05);
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.arqueo-card.session-closed {
  border-color: #94a3b8;
  background: #fcfcfd;
}

.arqueo-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.arqueo-label {
  font-size: 0.72rem;
  font-weight: 700;
  color: var(--tw-fg-64748b);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.arqueo-title {
  margin: 0.1rem 0 0 0;
  font-size: 1.25rem;
  font-weight: 800;
  color: var(--tw-fg-0f172a);
}

.session-status-badge .badge-status {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 0.78rem;
  font-weight: 700;
}

.badge-status.open {
  background: var(--tw-bg-ecfdf5);
  color: var(--tw-fg-059669);
  border: 1px solid var(--tw-bg-a7f3d0);
}

.badge-status.closed {
  background: var(--tw-bg-f1f5f9);
  color: var(--tw-fg-475569);
  border: 1px solid var(--tw-bg-cbd5e1);
}

.badge-status.unrecorded {
  background: var(--tw-bg-fffbeb);
  color: var(--tw-fg-b45309);
  border: 1px solid var(--tw-bg-fde68a);
}

.dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: currentColor;
}

.dot.pulse {
  animation: pulse 1.5s infinite;
}

@keyframes pulse {
  0% { transform: scale(0.95); opacity: 0.8; }
  50% { transform: scale(1.3); opacity: 1; }
  100% { transform: scale(0.95); opacity: 0.8; }
}

/* HERO: EFECTIVO EN GAVETA */
.drawer-cash-hero {
  background: linear-gradient(135deg, #064e3b 0%, #065f46 100%);
  color: white;
  padding: 1.25rem;
  border-radius: 12px;
  text-align: center;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  box-shadow: 0 4px 12px rgba(6, 78, 59, 0.25);
}

.drawer-cash-label {
  font-size: 0.82rem;
  font-weight: 600;
  color: #a7f3d0;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.drawer-cash-amount {
  display: flex;
  align-items: baseline;
  justify-content: center;
  gap: 4px;
}

.drawer-cash-amount .currency {
  font-size: 1.1rem;
  font-weight: 600;
  opacity: 0.9;
}

.drawer-cash-amount .val {
  font-size: 2.2rem;
  font-weight: 800;
  letter-spacing: -0.5px;
}

.drawer-cash-sub {
  font-size: 0.72rem;
  color: #6ee7b7;
}

/* DESGLOSE DEL ARQUEO */
.arqueo-breakdown {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.breakdown-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.86rem;
  color: var(--tw-fg-334155);
}

.breakdown-divider {
  height: 1px;
  background: var(--tw-bg-f1f5f9);
  margin: 0.25rem 0;
}

.breakdown-row.highlight-income {
  background: var(--tw-bg-f0fdf4);
  padding: 4px 8px;
  border-radius: 6px;
}

.breakdown-row.highlight-expense {
  background: var(--tw-bg-fef2f2);
  padding: 4px 8px;
  border-radius: 6px;
}

.row-val.income {
  color: var(--tw-fg-16a34a);
}

.row-val.expense {
  color: var(--tw-fg-dc2626);
}

.breakdown-row.total-row {
  font-size: 0.95rem;
  font-weight: 700;
  padding-top: 0.25rem;
}

.total-sales {
  color: var(--tw-fg-4f46e5);
  font-size: 1.05rem;
}

/* CAJA DE CIERRE REGISTRADO */
.closed-summary-box {
  background: var(--tw-bg-f8fafc);
  border: 1px solid var(--tw-bg-e2e8f0);
  border-radius: 10px;
  padding: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.closed-box-title {
  margin: 0;
  font-size: 0.88rem;
  font-weight: 700;
  color: var(--tw-fg-1e293b);
}

.closed-box-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
  font-size: 0.8rem;
}

.c-label {
  display: block;
  font-size: 0.7rem;
  color: var(--tw-fg-64748b);
}

.diff-note {
  margin: 0;
  font-size: 0.75rem;
  color: var(--tw-fg-64748b);
  background: var(--tw-bg-fff);
  padding: 4px 8px;
  border-radius: 4px;
  border: 1px dashed var(--tw-bg-cbd5e1);
}

/* BOTONES DE ACCIÓN */
.arqueo-actions {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.btn {
  font-family: inherit;
  font-weight: 600;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.15s;
  padding: 0.6rem 1rem;
  text-align: center;
}

.btn-expense {
  background: var(--tw-bg-fef2f2);
  color: var(--tw-fg-dc2626);
  border: 1.5px solid var(--tw-bg-fecaca);
  font-size: 0.88rem;
  padding: 0.75rem 1rem;
}

.btn-expense:hover {
  background: var(--tw-bg-fee2e2);
  border-color: #f87171;
}

.btn-close-day {
  background: #4f46e5;
  color: white;
  border: none;
  font-size: 0.95rem;
  font-weight: 700;
  padding: 0.8rem 1rem;
  box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
}

.btn-close-day:hover {
  background: #4338ca;
}

.btn-outline {
  background: transparent;
  border: 1px solid var(--tw-bg-cbd5e1);
  color: var(--tw-fg-475569);
}

.btn-outline:hover {
  background: var(--tw-bg-f8fafc);
  color: var(--tw-fg-0f172a);
}

.btn-secondary {
  background: var(--tw-bg-f1f5f9);
  color: var(--tw-fg-334155);
  border: 1px solid var(--tw-bg-cbd5e1);
}

.btn-secondary:hover {
  background: var(--tw-bg-e2e8f0);
}

.btn-full {
  width: 100%;
}

.historical-notice {
  text-align: center;
  color: var(--tw-fg-64748b);
}

/* SECCIÓN INFERIOR: REGISTROS */
.cd-bottom-records {
  background: var(--color-surface-2, var(--tw-bg-fff));
  border-radius: 14px;
  border: 1px solid var(--color-border, var(--tw-bg-e2e8f0));
  box-shadow: 0 2px 10px rgba(0,0,0,0.04);
  overflow: hidden;
}

.records-tabs {
  display: flex;
  background: var(--tw-bg-f8fafc);
  border-bottom: 1px solid var(--tw-bg-e2e8f0);
}

.tab-btn {
  padding: 0.85rem 1.5rem;
  border: none;
  background: transparent;
  font-size: 0.92rem;
  font-weight: 600;
  color: var(--tw-fg-64748b);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  transition: all 0.15s;
}

.tab-btn:hover {
  color: var(--tw-fg-0f172a);
}

.tab-btn.active {
  color: var(--tw-fg-4f46e5);
  border-bottom-color: #4f46e5;
  background: var(--tw-bg-fff);
}

.records-content {
  padding: 1.25rem;
}

.empty-table-state {
  text-align: center;
  padding: 3rem 1rem;
  color: var(--tw-fg-64748b);
}

.empty-table-icon {
  font-size: 2.5rem;
  display: block;
  margin-bottom: 0.5rem;
}

.table-responsive {
  overflow-x: auto;
}

.cd-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.88rem;
  text-align: left;
}

.cd-table th {
  background: var(--tw-bg-f8fafc);
  color: var(--tw-fg-475569);
  font-weight: 700;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  padding: 0.75rem 1rem;
  border-bottom: 1.5px solid var(--tw-bg-e2e8f0);
}

.cd-table td {
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--tw-bg-f1f5f9);
  color: var(--tw-fg-1e293b);
  vertical-align: middle;
}

.cd-table tr:hover td {
  background: var(--tw-bg-f8fafc);
}

.clickable-row {
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.clickable-row:hover td {
  background-color: var(--tw-bg-f1f5f9);
}

.col-actions {
  white-space: nowrap;
}

.actions-cell {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 6px;
}

.btn-table-action {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 0.75rem;
  font-weight: 700;
  padding: 4px 9px;
  border-radius: 6px;
  border: 1px solid transparent;
  cursor: pointer;
  transition: all 0.15s ease;
}

.btn-table-view {
  background: var(--tw-bg-f0fdf4);
  color: #166534;
  border-color: var(--tw-bg-bbf7d0);
}

.btn-table-view:hover {
  background: #dcfce7;
  border-color: #86efac;
}

.btn-table-edit {
  background: var(--tw-bg-fefce8);
  color: #854d0e;
  border-color: var(--tw-bg-fef08a);
}

.btn-table-edit:hover {
  background: var(--tw-bg-fef9c3);
  border-color: var(--tw-bg-fde047);
}

.row-voided td {
  opacity: 0.5;
  text-decoration: line-through;
}

.col-time {
  font-weight: 600;
  color: var(--tw-fg-64748b);
  white-space: nowrap;
}

.items-summary-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
}

.item-summary-pill {
  background: var(--tw-bg-f1f5f9);
  color: var(--tw-fg-334155);
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 0.78rem;
  font-weight: 500;
}

.sale-note-sub {
  font-size: 0.75rem;
  color: var(--tw-fg-64748b);
  margin-top: 3px;
}

/* BADGES */
.origin-badge {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 0.75rem;
  font-weight: 700;
}

.origin-pedidosya {
  background: var(--tw-bg-fef2f2);
  color: var(--tw-fg-dc2626);
  border: 1px solid var(--tw-bg-fecaca);
}

.origin-takeaway {
  background: var(--tw-bg-eff6ff);
  color: var(--tw-fg-2563eb);
  border: 1px solid var(--tw-bg-bfdbfe);
}

.origin-store {
  background: var(--tw-bg-f0fdf4);
  color: var(--tw-fg-16a34a);
  border: 1px solid var(--tw-bg-bbf7d0);
}

.payment-badge {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 0.75rem;
  font-weight: 600;
}

.badge-cash {
  background: var(--tw-bg-ecfdf5);
  color: var(--tw-fg-059669);
}

.badge-qr {
  background: #eef2ff;
  color: var(--tw-fg-4f46e5);
}

.badge-card {
  background: #faf5ff;
  color: #9333ea;
}

.badge-pedidosya {
  background: var(--tw-bg-fff1f2);
  color: #e11d48;
}

.badge-gray {
  background: var(--tw-bg-f1f5f9);
  color: var(--tw-fg-64748b);
}

.status-pill {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 0.72rem;
  font-weight: 700;
}

.status-completed {
  background: var(--tw-bg-ecfdf5);
  color: var(--tw-fg-059669);
}

.status-voided {
  background: var(--tw-bg-fef2f2);
  color: var(--tw-fg-dc2626);
}

.status-pending {
  background: var(--tw-bg-fffbeb);
  color: #d97706;
}

.pill-expense {
  background: var(--tw-bg-fef2f2);
  color: var(--tw-fg-dc2626);
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 0.75rem;
  font-weight: 700;
}

.pill-income {
  background: var(--tw-bg-f0fdf4);
  color: var(--tw-fg-16a34a);
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 0.75rem;
  font-weight: 700;
}

.text-expense { color: var(--tw-fg-dc2626); }
.text-income { color: var(--tw-fg-16a34a); }
.text-right { text-align: right; }
.text-center { text-align: center; }

/* MODALES OVERLAYS */
.wizard-modal-overlay, .report-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.65);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1200;
  padding: 1rem;
}

.wizard-modal-container {
  width: 100%;
  max-width: 680px;
}

.report-modal-card {
  background: var(--tw-bg-fff);
  border-radius: 16px;
  width: 100%;
  max-width: 480px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 20px 48px rgba(0,0,0,0.3);
}

.report-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid var(--tw-bg-e2e8f0);
}

.report-modal-header h3 {
  margin: 0;
  font-size: 1.1rem;
}

.btn-close-modal {
  border: none;
  background: transparent;
  font-size: 1.25rem;
  cursor: pointer;
  color: var(--tw-fg-64748b);
}

.report-modal-body {
  padding: 1.25rem;
  overflow-y: auto;
}

.report-modal-footer {
  padding: 1rem 1.5rem;
  border-top: 1px solid var(--tw-bg-e2e8f0);
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  background: var(--tw-bg-f8fafc);
}
</style>
