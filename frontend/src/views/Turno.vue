<script setup>
import { ref, onMounted, computed, nextTick } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useTurnoStore, readCashCloseProgress } from '../stores/turno';
import { useAuthStore } from '../stores/auth';
import CashCloseWizard from '../components/CashCloseWizard.vue';
import SaleDetailDrawer from '../components/SaleDetailDrawer.vue';
import AdminSaleEditor from '../components/AdminSaleEditor.vue';
import CashExpenseModal from '../components/CashExpenseModal.vue';
import WasteModal from '../components/Inventory/WasteModal.vue';
import ReorderNotice from '../components/Inventory/ReorderNotice.vue';
import SyncIssuesPanel from '../components/SyncIssuesPanel.vue';
import { useNetworkStore } from '../stores/network';
import { useReorderStore } from '../stores/reorder';
import StatusTag from '../components/StatusTag.vue';
import FinanceModal from '../components/Finance/FinanceModal.vue';
import '../components/Finance/finance.css';

const router = useRouter();
const route = useRoute();
const turnoStore = useTurnoStore();
const authStore = useAuthStore();

// Modal de Venta Retroactiva / Edición (Admin / Super Admin)
const isRetroactiveModalOpen = ref(false);
const editingSaleForAdmin = ref(null);
const isCashExpenseModalOpen = ref(false);
const isWasteModalOpen = ref(false);
const network = useNetworkStore();
const reorder = useReorderStore();
const isReorderNoticeOpen = ref(false);

function openRetroactiveSaleModal() {
  editingSaleForAdmin.value = null;
  isRetroactiveModalOpen.value = true;
}

function openEditSaleModal(sale) {
  editingSaleForAdmin.value = sale;
  isRetroactiveModalOpen.value = true;
}

const isAdmin = computed(() => authStore.user?.role === 'admin' || authStore.user?.role === 'super_admin');

/**
 * Aviso de reposición (F4): al administrador, una vez por turno, al abrir caja o al entrar a Caja con el turno ya
 * abierto. Se recuerda por turno; si no se pudo consultar (sin conexión) no se marca y se intenta de nuevo.
 */
async function checkReorderNotice() {
  const session = turnoStore.activeSession;
  if (!isAdmin.value || !session) return;
  const key = 'reorder_notice_session';
  try {
    if (localStorage.getItem(key) === String(session.id)) return;
  } catch (e) { /* sin almacenamiento: se avisa y listo */ }
  const summary = await reorder.fetchSummary({ force: true });
  if (!summary) return;
  try { localStorage.setItem(key, String(session.id)); } catch (e) { /* idem */ }
  if (summary.to_reorder || summary.expiring) isReorderNoticeOpen.value = true;
}

// Modal de Apertura de Caja
const openingAmountInput = ref(200);
const openingError = ref('');
const isOpening = ref(false);

// Búsqueda en tiempo real con debounce
const searchInput = ref('');
let searchTimer = null;

function handleSearchChange() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    turnoStore.filters.search = searchInput.value;
    turnoStore.fetchSales(1);
  }, 350);
}

// Filtro de rango personalizado
const customFrom = ref('');
const customTo = ref('');

function applyCustomDateRange() {
  if (customFrom.value && customTo.value) {
    turnoStore.filters.period = 'custom';
    turnoStore.filters.from = customFrom.value;
    turnoStore.filters.to = customTo.value;
    turnoStore.filters.date = null;
    turnoStore.fetchSales(1);
  }
}

onMounted(async () => {
  await turnoStore.fetchActiveSession();
  await turnoStore.fetchSales(1);

  // Desde el POS («Abrir caja»): se abre directo el formulario de apertura
  if (route.query.abrir === '1') {
    router.replace({ query: {} });
    if (!turnoStore.hasActiveSession) {
      initOpeningModal();
      return;
    }
  }

  // Si hay un arqueo en progreso de ESTA caja, se retoma; uno de otra caja se ignora
  if (turnoStore.hasActiveSession && readCashCloseProgress(turnoStore.activeSession.id)) {
    turnoStore.isWizardOpen = true;
  } else {
    checkReorderNotice();
  }
});

// Horas en formato de 24 h, como se leen en el local («13:45»)
function formatTime(dateStr) {
  if (!dateStr) return '--:--';
  return new Date(dateStr).toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit', hour12: false });
}

function formatDate(dateStr) {
  if (!dateStr) return '--';
  const d = new Date(dateStr);
  return d.toLocaleDateString('es-BO', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  });
}

const money = (n) => `Bs ${Number(n || 0).toFixed(2)}`;
const isToday = (dateStr) => dateStr && new Date(dateStr).toDateString() === new Date().toDateString();

// ── Cabecera del turno ──
const session = computed(() => turnoStore.activeSession);
const isOwnSession = computed(() => Boolean(session.value) && session.value.cashier_id === authStore.user?.id);
const sessionTitle = computed(() => {
  if (!turnoStore.hasActiveSession) return 'No hay turno abierto';
  if (isOwnSession.value) return 'Tu turno';
  return `Turno de ${session.value.cashier?.name || 'otro usuario'}`;
});
const openedText = computed(() => {
  if (!session.value) return '';
  const when = isToday(session.value.opened_at) ? 'de hoy' : `del ${formatDate(session.value.opened_at)}`;
  return `desde las ${formatTime(session.value.opened_at)} ${when}`;
});

// ── Gaveta: la cuenta que el cajero va a comprobar al contar ──
const stats = computed(() => turnoStore.sessionStats || {});
const drawer = computed(() => {
  const st = stats.value;
  return {
    opening: Number(session.value?.opening_amount || 0),
    cashSales: Number(st.cash_sales || 0),
    extraIncome: Number(st.cash_extra_income || 0),
    cashExpenses: Number(st.cash_expenses ?? st.total_expenses ?? 0),
    expected: turnoStore.currentCashInDrawer,
  };
});
const otherCharges = computed(() => {
  const st = stats.value;
  return {
    totalSales: Number(st.total_sales || 0),
    salesCount: Number(st.sales_count || 0),
    qr: Number(st.qr_sales || 0),
    card: Number(st.card_sales || 0),
    nonCashExpenses: Number(st.non_cash_expenses || 0),
    pyaCount: Number(st.pedidosya_count || 0),
    pyaTotal: Number(st.pedidosya_total || 0),
  };
});

function paymentLabel(sale) {
  const map = { cash: 'Efectivo', qr: 'QR', card: 'Tarjeta', delivery_platform: 'PedidosYa' };
  if (sale.payments && sale.payments.length > 1) {
    return `Mixto: ${sale.payments.map((p) => map[p.method] || p.method).join(' y ')}`;
  }
  const method = getSalePrimaryMethod(sale);
  return map[method] || method || 'Otro';
}

function getSalePrimaryMethod(sale) {
  if (!sale.payments || sale.payments.length === 0) return 'cash';
  if (sale.payments.length > 1) return 'mixed';
  return sale.payments[0].method;
}

// Apertura de Turno
async function handleOpenSession() {
  if (openingAmountInput.value < 0) {
    openingError.value = 'El fondo inicial no puede ser negativo.';
    return;
  }

  isOpening.value = true;
  openingError.value = '';

  try {
    await turnoStore.openSession(openingAmountInput.value);
    turnoStore.isOpeningModalOpen = false;
    checkReorderNotice();
  } catch (err) {
    openingError.value = err.message || 'Error al abrir caja.';
  } finally {
    isOpening.value = false;
  }
}

const openingInput = ref(null);
function initOpeningModal() {
  openingAmountInput.value = turnoStore.suggestedOpeningAmount > 0
    ? turnoStore.suggestedOpeningAmount
    : 200;
  openingError.value = '';
  turnoStore.isOpeningModalOpen = true;
  nextTick(() => { openingInput.value?.focus(); openingInput.value?.select(); });
}

const PERIODS = [
  { value: 'today', label: 'Hoy' },
  { value: 'yesterday', label: 'Ayer' },
  { value: 'week', label: 'Esta semana' },
  { value: 'month', label: 'Este mes' },
  { value: 'year', label: 'Este año' },
];
const METHOD_FILTERS = [
  { value: null, label: 'Todas' },
  { value: 'cash', label: 'Efectivo' },
  { value: 'qr', label: 'QR' },
  { value: 'card', label: 'Tarjeta' },
];

// Recargar datos
async function handleRefresh() {
  await turnoStore.fetchActiveSession();
  await turnoStore.fetchSales(turnoStore.pagination.currentPage);
}
</script>

<template>
  <div class="turno-container">
    <!-- ================= 1. EL TURNO ================= -->
    <header class="tn-head">
      <div class="tn-head-text">
        <h1>{{ sessionTitle }}</h1>
        <p v-if="turnoStore.hasActiveSession" class="tn-head-sub">
          <StatusTag tone="ok" label="Abierto" />
          <span>{{ openedText }}</span>
        </p>
        <p v-else class="tn-head-sub">
          Abre la caja con el fondo para dar cambio. Hasta entonces el POS no cobra.
        </p>
      </div>

      <div class="tn-actions">
        <button class="tn-icon-btn" type="button" title="Actualizar los montos" aria-label="Actualizar los montos" @click="handleRefresh">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36" /><path d="M21 4v5h-5" /></svg>
        </button>

        <!-- Merma: la anota quien la ve (se cayó, venció, error de preparación…); necesita conexión -->
        <button
          class="tn-btn tn-btn-ghost"
          type="button"
          :disabled="!network.isOnline"
          :title="network.isOnline ? 'Anotar lo que se cayó, venció o se usó sin venderse' : 'Necesitas conexión para anotar una merma'"
          @click="isWasteModalOpen = true"
        >
          Anotar merma
        </button>

        <button
          v-if="!turnoStore.hasActiveSession"
          class="tn-btn tn-btn-primary"
          type="button"
          @click="initOpeningModal"
        >
          Abrir caja
        </button>

        <template v-else>
          <button class="tn-btn tn-btn-ghost" type="button" @click="isCashExpenseModalOpen = true">
            Registrar gasto o ingreso
          </button>
          <button class="tn-btn tn-btn-primary" type="button" @click="turnoStore.isWizardOpen = true">
            Cerrar caja
          </button>
        </template>
      </div>
    </header>

    <!-- ================= 2. LA GAVETA ================= -->
    <section v-if="turnoStore.hasActiveSession" class="tn-drawer" aria-labelledby="tn-drawer-title">
      <h2 id="tn-drawer-title">En la gaveta debería haber</h2>
      <div class="tn-eq">
        <span class="tn-op tn-op-first" aria-hidden="true"></span>
        <div class="tn-term">
          <span class="tn-term-label">Fondo</span>
          <span class="tn-term-value">{{ money(drawer.opening) }}</span>
        </div>
        <span class="tn-op" aria-hidden="true">+</span><span class="sr-only">más</span>
        <div class="tn-term">
          <span class="tn-term-label">Ventas en efectivo</span>
          <span class="tn-term-value">{{ money(drawer.cashSales) }}</span>
        </div>
        <template v-if="drawer.extraIncome > 0">
          <span class="tn-op" aria-hidden="true">+</span><span class="sr-only">más</span>
          <div class="tn-term">
            <span class="tn-term-label">Ingresos</span>
            <span class="tn-term-value">{{ money(drawer.extraIncome) }}</span>
          </div>
        </template>
        <span class="tn-op" aria-hidden="true">−</span><span class="sr-only">menos</span>
        <div class="tn-term">
          <span class="tn-term-label">Gastos en efectivo</span>
          <span class="tn-term-value">{{ money(drawer.cashExpenses) }}</span>
        </div>
        <span class="tn-op tn-op-eq" aria-hidden="true">=</span><span class="sr-only">igual a</span>
        <div class="tn-term tn-result">
          <span class="tn-term-label">En la gaveta</span>
          <strong class="tn-term-value">{{ money(drawer.expected) }}</strong>
        </div>
      </div>
      <p class="tn-drawer-more">
        Ventas del turno: <strong>{{ money(otherCharges.totalSales) }}</strong> en
        {{ otherCharges.salesCount === 1 ? '1 ticket' : `${otherCharges.salesCount} tickets` }}.
        Fuera de la gaveta: QR <strong>{{ money(otherCharges.qr) }}</strong> y tarjeta <strong>{{ money(otherCharges.card) }}</strong><template v-if="otherCharges.nonCashExpenses > 0">; gastos pagados con QR o tarjeta {{ money(otherCharges.nonCashExpenses) }}</template>.
        PedidosYa: {{ otherCharges.pyaCount === 1 ? '1 pedido' : `${otherCharges.pyaCount} pedidos` }} por {{ money(otherCharges.pyaTotal) }}, los cobra la plataforma.
      </p>
    </section>

    <!-- Ventas de este dispositivo que no llegaron al servidor: su cobro todavía no cuenta en la caja -->
    <SyncIssuesPanel class="turno-sync-issues" />

    <!-- ================= 3. HISTORIAL DE VENTAS ================= -->
    <section class="history-card" aria-labelledby="tn-history-title">
      <div class="history-header">
        <div class="header-left">
          <h2 id="tn-history-title">Historial de ventas</h2>
          <p>Ventas del local por período y forma de pago. Toca una venta para ver su detalle.</p>
        </div>
        <button
          v-if="isAdmin"
          class="tn-btn tn-btn-ghost tn-btn-sm"
          type="button"
          title="Registrar una venta de un día pasado que no se cargó en el POS (solo administradores)"
          @click="openRetroactiveSaleModal"
        >
          Registrar venta pasada
        </button>
      </div>

      <div class="filter-controls-row">
        <div class="tn-seg" role="group" aria-label="Período">
          <button
            v-for="p in PERIODS"
            :key="p.value"
            type="button"
            class="tn-seg-btn"
            :class="{ active: turnoStore.filters.period === p.value }"
            :aria-pressed="turnoStore.filters.period === p.value"
            @click="turnoStore.setPeriod(p.value)"
          >{{ p.label }}</button>
          <button
            type="button"
            class="tn-seg-btn"
            :class="{ active: turnoStore.filters.period === 'custom' }"
            :aria-pressed="turnoStore.filters.period === 'custom'"
            @click="turnoStore.filters.period = 'custom'"
          >Elegir fechas</button>
        </div>

        <label class="search-box">
          <span class="sr-only">Buscar ventas</span>
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
          <input
            v-model="searchInput"
            type="search"
            class="search-input"
            placeholder="Buscar por número, cliente o producto"
            @input="handleSearchChange"
          />
          <button v-if="searchInput" class="clear-search" type="button" aria-label="Borrar la búsqueda" @click="searchInput = ''; handleSearchChange()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
          </button>
        </label>
      </div>

      <div v-if="turnoStore.filters.period === 'custom'" class="custom-range-bar">
        <label class="date-input-group">
          Desde
          <input v-model="customFrom" type="date" class="date-picker" />
        </label>
        <label class="date-input-group">
          Hasta
          <input v-model="customTo" type="date" class="date-picker" />
        </label>
        <button class="tn-btn tn-btn-primary tn-btn-sm" type="button" :disabled="!customFrom || !customTo" @click="applyCustomDateRange">
          Ver estas fechas
        </button>
      </div>

      <div class="method-chips" role="group" aria-label="Forma de pago">
        <button
          v-for="m in METHOD_FILTERS"
          :key="String(m.value)"
          type="button"
          class="chip"
          :class="{ active: turnoStore.filters.payment_method === m.value }"
          :aria-pressed="turnoStore.filters.payment_method === m.value"
          @click="turnoStore.setPaymentMethod(m.value)"
        >{{ m.label }}</button>
      </div>

      <!-- Resumen del filtro actual -->
      <dl class="summary-strip">
        <div class="strip-item strip-main">
          <dt>Total en ventas</dt>
          <dd>{{ money(turnoStore.summary.total_sales) }}</dd>
        </div>
        <div class="strip-item">
          <dt>Efectivo</dt>
          <dd>{{ money(turnoStore.summary.cash_total) }}</dd>
        </div>
        <div class="strip-item">
          <dt>QR</dt>
          <dd>{{ money(turnoStore.summary.qr_total) }}</dd>
        </div>
        <div class="strip-item">
          <dt>Tarjeta</dt>
          <dd>{{ money(turnoStore.summary.card_total) }}</dd>
        </div>
        <div class="strip-item">
          <dt>Tickets</dt>
          <dd>{{ turnoStore.summary.sales_count }}</dd>
        </div>
        <div v-if="turnoStore.summary.voided_count > 0" class="strip-item">
          <dt>Anuladas</dt>
          <dd>{{ turnoStore.summary.voided_count }}</dd>
        </div>
      </dl>

      <div class="table-responsive">
        <table class="sales-table">
          <thead>
            <tr>
              <th scope="col">Ticket</th>
              <th scope="col">Fecha y hora</th>
              <th scope="col">Cliente</th>
              <th scope="col">Productos</th>
              <th scope="col">Forma de pago</th>
              <th scope="col" class="num">Total</th>
              <th scope="col">Estado</th>
              <th scope="col"><span class="sr-only">Acciones</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="turnoStore.salesLoading">
              <td colspan="8" class="table-message">Cargando ventas…</td>
            </tr>

            <tr v-else-if="turnoStore.sales.length === 0">
              <td colspan="8" class="table-message">No hay ventas con estos filtros.</td>
            </tr>

            <template v-else>
            <tr
              v-for="sale in turnoStore.sales"
              :key="sale.id"
              class="clickable-row"
              :class="{ 'row-voided': sale.status === 'voided' }"
              @click="turnoStore.openSaleDetail(sale)"
            >
              <td>
                <div class="ticket-cell-wrap">
                  <span class="ticket-number">#{{ sale.order_number || (sale.id ? sale.id.substring(0, 8).toUpperCase() : '---') }}</span>
                  <span v-if="sale.source === 'manual_retroactive'" class="source-tag" title="Venta registrada a mano en una fecha pasada">Manual</span>
                  <span v-if="sale.edited_by" class="source-tag" title="Venta corregida por un administrador">Editada</span>
                </div>
              </td>
              <td>
                <div class="time-cell">
                  <strong>{{ formatTime(sale.created_at) }}</strong>
                  <small>{{ formatDate(sale.created_at) }}</small>
                </div>
              </td>
              <td>
                <div class="customer-cell">
                  <span v-if="sale.customer?.name">{{ sale.customer.name }}</span>
                  <span v-else class="muted">Sin cliente</span>
                  <small v-if="sale.customer?.ci_or_phone">{{ sale.customer.ci_or_phone }}</small>
                </div>
              </td>
              <td>
                <div class="items-summary-cell">
                  <span v-if="sale.items && sale.items.length > 0">
                    {{ Number(sale.items[0].quantity) }}× {{ sale.items[0].name || sale.items[0].product?.name }}
                    <small v-if="sale.items.length > 1" class="more-items-tag">y {{ sale.items.length - 1 }} más</small>
                  </span>
                  <span v-else class="muted">Sin detalle</span>
                </div>
              </td>
              <td><span class="payment-text">{{ paymentLabel(sale) }}</span></td>
              <td class="num"><strong class="price-cell">{{ money(sale.total_amount) }}</strong></td>
              <td>
                <StatusTag
                  :tone="sale.status === 'completed' ? 'ok' : 'err'"
                  :label="sale.status === 'completed' ? 'Completada' : 'Anulada'"
                />
              </td>
              <td class="actions-td" @click.stop>
                <div class="actions-cell">
                  <button
                    v-if="isAdmin && sale.status !== 'voided'"
                    class="tn-icon-btn sm"
                    type="button"
                    :aria-label="`Corregir la venta ${sale.order_number || ''}`"
                    title="Corregir productos o montos (administradores)"
                    @click="openEditSaleModal(sale)"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" /></svg>
                  </button>
                  <button class="tn-btn tn-btn-ghost tn-btn-xs" type="button" @click="turnoStore.openSaleDetail(sale)">
                    Ver
                  </button>
                </div>
              </td>
            </tr>
            </template>
          </tbody>
        </table>
      </div>

      <div v-if="turnoStore.pagination.lastPage > 1" class="pagination-bar">
        <div class="page-info">
          Página {{ turnoStore.pagination.currentPage }} de {{ turnoStore.pagination.lastPage }}, {{ turnoStore.pagination.total }} ventas
        </div>
        <div class="page-buttons">
          <button
            class="tn-btn tn-btn-ghost tn-btn-sm"
            type="button"
            :disabled="turnoStore.pagination.currentPage <= 1"
            @click="turnoStore.fetchSales(turnoStore.pagination.currentPage - 1)"
          >
            Anterior
          </button>
          <button
            class="tn-btn tn-btn-ghost tn-btn-sm"
            type="button"
            :disabled="turnoStore.pagination.currentPage >= turnoStore.pagination.lastPage"
            @click="turnoStore.fetchSales(turnoStore.pagination.currentPage + 1)"
          >
            Siguiente
          </button>
        </div>
      </div>
    </section>

    <!-- ================= APERTURA DE CAJA ================= -->
    <div v-if="turnoStore.isOpeningModalOpen" class="fin-page tn-modal-host">
      <FinanceModal
        title="Abrir caja"
        subtitle="Cuenta el efectivo con el que empiezas: es el fondo para dar cambio."
        :busy="isOpening"
        @close="turnoStore.isOpeningModalOpen = false"
      >
        <div v-if="openingError" class="fin-note err" role="alert">{{ openingError }}</div>
        <div class="fin-field">
          <label for="tn-opening">Fondo inicial</label>
          <span class="tn-money-input">
            <span aria-hidden="true">Bs</span>
            <input
              id="tn-opening"
              ref="openingInput"
              v-model.number="openingAmountInput"
              type="number"
              min="0"
              step="10"
              inputmode="decimal"
              @keydown.enter.prevent="handleOpenSession"
            />
          </span>
        </div>
        <div class="fin-chips" role="group" aria-label="Montos frecuentes">
          <button
            v-for="amount in [100, 150, 200, 300]"
            :key="amount"
            type="button"
            class="fin-chip tn-chip"
            :class="{ active: Number(openingAmountInput) === amount }"
            @click="openingAmountInput = amount"
          >Bs {{ amount }}</button>
        </div>
        <p v-if="turnoStore.suggestedOpeningAmount > 0" class="fin-hint">
          En el último cierre se dejaron {{ money(turnoStore.suggestedOpeningAmount) }} para este turno.
        </p>
        <template #footer>
          <button class="fin-btn fin-btn-ghost fin-btn-lg" type="button" :disabled="isOpening" @click="turnoStore.isOpeningModalOpen = false">Cancelar</button>
          <button
            class="fin-btn fin-btn-primary fin-btn-lg"
            type="button"
            :disabled="!(openingAmountInput >= 0) || openingAmountInput === '' || isOpening"
            @click="handleOpenSession"
          >
            {{ isOpening ? 'Abriendo…' : `Abrir caja con ${money(openingAmountInput)}` }}
          </button>
        </template>
      </FinanceModal>
    </div>

    <!-- ================= SUBCOMPONENTES ================= -->
    <CashCloseWizard
      v-if="turnoStore.isWizardOpen"
      @close="turnoStore.isWizardOpen = false"
      @closed="handleRefresh"
    />

    <SaleDetailDrawer
      @close="turnoStore.closeSaleDetail"
      @voided="handleRefresh"
      @edit="(s) => { turnoStore.closeSaleDetail(); openEditSaleModal(s); }"
    />

    <!-- Solo administradores corrigen o registran ventas pasadas (para un cajero no se monta ni pide usuarios) -->
    <AdminSaleEditor
      v-if="isAdmin"
      :visible="isRetroactiveModalOpen"
      :sale="editingSaleForAdmin"
      @close="isRetroactiveModalOpen = false"
      @saved="handleRefresh"
    />

    <WasteModal v-if="isWasteModalOpen" @close="isWasteModalOpen = false" />
    <ReorderNotice v-if="isReorderNoticeOpen" @close="isReorderNoticeOpen = false" />

    <CashExpenseModal
      :visible="isCashExpenseModalOpen"
      @close="isCashExpenseModalOpen = false"
      @saved="handleRefresh"
    />
  </div>
</template>

<style scoped>
/*
  Caja (dirección visual del 09/10, la misma de Finanzas): el turno en una frase, la gaveta como una cuenta
  que se lee de izquierda a derecha y el historial de ventas debajo. Estados con icono y texto; maracuyá
  solo para la acción principal (abrir o cerrar la caja).
*/
.turno-container {
  padding: 24px 28px 40px;
  max-width: 1440px;
  margin: 0 auto;
  width: 100%;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  gap: 18px;
  background: var(--cream-100);
  min-height: 100%;
  color: var(--ink-900);
}

/* ── Botones de la pantalla ── */
.tn-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 42px;
  padding: 0 16px;
  border-radius: 11px;
  border: 1px solid transparent;
  font-family: inherit;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.tn-btn:disabled { opacity: .55; cursor: not-allowed; }
.tn-btn:focus-visible,
.tn-icon-btn:focus-visible,
.tn-seg-btn:focus-visible,
.chip:focus-visible,
.clear-search:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; }
.tn-btn-primary { background: var(--passion-500); color: var(--on-accent); font-weight: 700; }
.tn-btn-primary:hover:not(:disabled) { filter: brightness(1.06); }
.tn-btn-ghost { background: var(--surface); color: var(--ink-900); border-color: var(--border); }
.tn-btn-ghost:hover:not(:disabled) { background: var(--acai-50); color: var(--acai-text); border-color: transparent; }
.tn-btn-sm { min-height: 36px; padding: 0 13px; font-size: 13px; border-radius: 9px; }
.tn-btn-xs { min-height: 32px; padding: 0 12px; font-size: 12.5px; border-radius: 8px; }
.tn-icon-btn {
  width: 42px;
  height: 42px;
  flex-shrink: 0;
  border: 1px solid var(--border);
  border-radius: 11px;
  background: var(--surface);
  color: var(--ink-700);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.tn-icon-btn:hover { background: var(--acai-50); color: var(--acai-text); border-color: transparent; }
.tn-icon-btn svg { width: 18px; height: 18px; }
.tn-icon-btn.sm { width: 32px; height: 32px; border-radius: 8px; }
.tn-icon-btn.sm svg { width: 15px; height: 15px; }

/* ── 1. El turno ── */
.tn-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}
.tn-head h1 {
  margin: 0;
  font-size: 28px;
  line-height: 1.15;
  font-weight: 700;
  color: var(--ink-900);
}
.tn-head-sub {
  margin: 6px 0 0;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 14.5px;
  color: var(--ink-700);
}
.tn-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

/* ── 2. La gaveta: la cuenta que se comprueba al contar ── */
.tn-drawer {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 18px 22px 16px;
}
.tn-drawer h2 {
  margin: 0 0 12px;
  font-size: 19px;
  font-weight: 700;
  color: var(--ink-900);
}
.tn-eq {
  display: flex;
  align-items: flex-end;
  flex-wrap: wrap;
  gap: 10px 14px;
}
.tn-term { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.tn-term-label { font-size: 13px; color: var(--ink-500); }
.tn-term-value {
  font-family: var(--font-heading);
  font-size: 22px;
  font-weight: 700;
  line-height: 1.1;
  color: var(--ink-900);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.tn-op {
  font-family: var(--font-heading);
  font-size: 24px;
  line-height: 1.1;
  color: var(--ink-500);
  padding-bottom: 1px;
}
.tn-op-first { display: none; }
.tn-result {
  margin-left: 2px;
  padding: 8px 16px 10px;
  border-radius: 12px;
  background: var(--acai-50);
}
.tn-result .tn-term-label { color: var(--acai-text); font-weight: 600; }
.tn-result .tn-term-value { font-size: 34px; line-height: 1; }
.tn-drawer-more {
  margin: 14px -22px 0;
  padding: 12px 22px 0;
  border-top: 1px solid var(--border);
  font-size: 13.5px;
  line-height: 1.55;
  color: var(--ink-700);
}
.tn-drawer-more strong { color: var(--ink-900); font-weight: 600; font-variant-numeric: tabular-nums; }

/* ── 3. Historial de ventas ── */
.history-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 20px 22px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.history-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 12px;
}
.header-left h2 { margin: 0; font-size: 21px; font-weight: 700; color: var(--ink-900); }
.header-left p { margin: 2px 0 0; font-size: 13.5px; color: var(--ink-500); }

.filter-controls-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

/* Período: control segmentado */
.tn-seg {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 3px;
  padding: 3px;
  border-radius: 11px;
  background: var(--cream-200);
}
.tn-seg-btn {
  min-height: 34px;
  padding: 0 12px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--ink-700);
  font-family: inherit;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
}
.tn-seg-btn:hover { color: var(--ink-900); }
.tn-seg-btn.active { background: var(--surface); color: var(--ink-900); font-weight: 700; box-shadow: 0 1px 2px rgba(32, 17, 47, 0.12); }

.search-box {
  position: relative;
  display: flex;
  align-items: center;
  flex: 1 1 260px;
  max-width: 360px;
}
.search-icon {
  position: absolute;
  left: 11px;
  width: 16px;
  height: 16px;
  color: var(--ink-500);
  pointer-events: none;
}
.search-input {
  width: 100%;
  min-height: 38px;
  padding: 0 36px;
  border-radius: 10px;
  border: 1px solid var(--border);
  font-family: inherit;
  font-size: 13.5px;
  background: var(--surface);
  color: var(--ink-900);
}
.search-input::-webkit-search-cancel-button { display: none; }
.search-input:focus { outline: none; border-color: var(--acai-500); box-shadow: 0 0 0 3px var(--acai-50); }
.clear-search {
  position: absolute;
  right: 5px;
  width: 28px;
  height: 28px;
  border: none;
  border-radius: 7px;
  background: none;
  color: var(--ink-500);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.clear-search:hover { background: var(--cream-200); color: var(--ink-900); }
.clear-search svg { width: 14px; height: 14px; }

.custom-range-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  padding: 10px 14px;
  border-radius: 12px;
  background: var(--cream-100);
  border: 1px solid var(--border);
}
.date-input-group { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: var(--ink-700); }
.date-picker {
  min-height: 34px;
  padding: 0 10px;
  border-radius: 8px;
  border: 1px solid var(--border);
  font-family: inherit;
  font-size: 13px;
  background: var(--surface);
  color: var(--ink-900);
}

/* Forma de pago */
.method-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.chip {
  min-height: 32px;
  padding: 0 13px;
  border-radius: 999px;
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--ink-700);
  font-family: inherit;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
}
.chip:hover { border-color: var(--acai-300); }
.chip.active { background: var(--acai-50); color: var(--acai-text); border-color: var(--acai-300); font-weight: 700; }

/* Resumen del filtro */
.summary-strip {
  margin: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 10px 28px;
  padding: 12px 16px;
  border-radius: 12px;
  background: var(--cream-100);
}
.strip-item dt { font-size: 12.5px; color: var(--ink-500); }
.strip-item dd {
  margin: 2px 0 0;
  font-size: 16px;
  font-weight: 700;
  color: var(--ink-900);
  font-variant-numeric: tabular-nums;
}
.strip-main dd { font-family: var(--font-heading); font-size: 20px; line-height: 1.1; }

/* Tabla de ventas */
.table-responsive { overflow-x: auto; }
.sales-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13.5px;
}
.sales-table th {
  padding: 10px 12px;
  border-bottom: 1px solid var(--border);
  text-align: left;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--ink-500);
  white-space: nowrap;
}
.sales-table td {
  padding: 10px 12px;
  border-bottom: 1px solid var(--border);
  color: var(--ink-900);
  vertical-align: middle;
}
.sales-table .num { text-align: right; }
.table-message { padding: 32px 12px !important; text-align: center; color: var(--ink-500) !important; }
.clickable-row { cursor: pointer; }
.clickable-row:hover { background: var(--surface-hover); }
.clickable-row.row-voided td { color: var(--ink-500); }
.clickable-row.row-voided .price-cell { text-decoration: line-through; }

.ticket-cell-wrap { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; }
.ticket-number { font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
.source-tag {
  font-size: 11.5px;
  font-weight: 600;
  padding: 1px 7px;
  border-radius: 999px;
  background: var(--gold-100);
  color: var(--gold-text);
}
.time-cell strong { display: block; font-variant-numeric: tabular-nums; }
.time-cell small,
.customer-cell small { display: block; font-size: 12px; color: var(--ink-500); }
.customer-cell span { display: block; font-weight: 500; }
.muted { color: var(--ink-500); }
.items-summary-cell { max-width: 240px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.more-items-tag { margin-left: 4px; font-size: 12px; color: var(--ink-500); }
.payment-text { white-space: nowrap; }
.price-cell { font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
.actions-cell { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }

.pagination-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  padding-top: 12px;
  border-top: 1px solid var(--border);
}
.page-info { font-size: 13px; color: var(--ink-500); }
.page-buttons { display: flex; gap: 8px; }

/* ── Apertura de caja (ventana de Finanzas) ── */
.tn-modal-host { display: contents; }
.tn-money-input {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 56px;
  padding: 0 16px;
  border: 1.5px solid var(--border);
  border-radius: 12px;
  background: var(--surface);
  align-self: flex-start;
}
.tn-money-input:focus-within { border-color: var(--acai-500); box-shadow: 0 0 0 3px var(--acai-50); }
.tn-money-input > span { font-size: 16px; font-weight: 600; color: var(--ink-500); }
.tn-money-input input {
  width: 150px;
  border: none;
  outline: none;
  background: transparent;
  color: var(--ink-900);
  font-family: var(--font-heading);
  font-size: 28px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.tn-chip { min-height: 36px; padding: 0 14px; font-size: 13.5px; }

/* ── Pantallas angostas ── */
@media (max-width: 900px) {
  .turno-container { padding: 16px 14px 32px; gap: 14px; }
  .tn-head { align-items: flex-start; }
  .tn-head h1 { font-size: 24px; }
  .tn-actions { width: 100%; }
  .tn-actions .tn-btn-primary { flex: 1 1 auto; }
  .tn-drawer, .history-card { padding: 16px; }
  .tn-drawer-more { margin: 12px -16px 0; padding: 12px 16px 0; }
  /* La cuenta de la gaveta se lee como una lista: signo, concepto y monto en cada renglón */
  .tn-eq { display: grid; grid-template-columns: 18px minmax(0, 1fr); align-items: center; gap: 6px 8px; }
  .tn-op-first { display: block; }
  .tn-op { font-size: 20px; text-align: center; padding: 0; }
  .tn-term { flex-direction: row; justify-content: space-between; align-items: baseline; gap: 12px; }
  .tn-term-value { font-size: 18px; }
  .tn-result { margin-left: 0; padding: 10px 12px; }
  .tn-result .tn-term-label { white-space: nowrap; }
  .tn-result .tn-term-value { font-size: 28px; }
  .search-box { max-width: none; }
}
</style>
