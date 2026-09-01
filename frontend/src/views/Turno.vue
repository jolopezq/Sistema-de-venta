<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useTurnoStore } from '../stores/turno';
import { useAuthStore } from '../stores/auth';
import CashCloseWizard from '../components/CashCloseWizard.vue';
import SaleDetailDrawer from '../components/SaleDetailDrawer.vue';
import CashSessionReport from '../components/CashSessionReport.vue';
import AdminSaleEditor from '../components/AdminSaleEditor.vue';

const router = useRouter();
const turnoStore = useTurnoStore();
const authStore = useAuthStore();

// Modal de Venta Retroactiva / Edición (Super Admin)
const isRetroactiveModalOpen = ref(false);
const editingSaleForAdmin = ref(null);

function openRetroactiveSaleModal() {
  editingSaleForAdmin.value = null;
  isRetroactiveModalOpen.value = true;
}

function openEditSaleModal(sale) {
  editingSaleForAdmin.value = sale;
  isRetroactiveModalOpen.value = true;
}

// Modal de Apertura de Caja
const openingAmountInput = ref(200);
const openingError = ref('');
const isOpening = ref(false);

// Modal de visualización de reporte histórico
const viewingPastReport = ref(null);

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

  // Si hay un arqueo en progreso guardado en localStorage, abrir wizard automáticamente
  if (localStorage.getItem('cashClose_inProgress') && turnoStore.hasActiveSession) {
    turnoStore.isWizardOpen = true;
  }
});

function formatTime(dateStr) {
  if (!dateStr) return '--:--';
  const d = new Date(dateStr);
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
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

function getPaymentBadge(method) {
  if (method === 'cash') return { label: 'Efectivo', class: 'badge-cash', icon: '💵' };
  if (method === 'qr') return { label: 'QR', class: 'badge-qr', icon: '📱' };
  if (method === 'card') return { label: 'Tarjeta', class: 'badge-card', icon: '💳' };
  return { label: method || 'Otro', class: 'badge-other', icon: '💰' };
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
  } catch (err) {
    openingError.value = err.message || 'Error al abrir caja.';
  } finally {
    isOpening.value = false;
  }
}

// Recargar datos
async function handleRefresh() {
  await turnoStore.fetchActiveSession();
  await turnoStore.fetchSales(turnoStore.pagination.currentPage);
}
</script>

<template>
  <div class="turno-container">
    <!-- ================= 1. CABECERA Y ESTADO DE SESIÓN ================= -->
    <div class="session-top-card">
      <div class="session-info-left">
        <div class="status-indicator">
          <span class="pulse-dot" :class="turnoStore.hasActiveSession ? 'online' : 'offline'"></span>
          <div>
            <div class="status-title">
              {{ turnoStore.hasActiveSession ? 'Turno Abierto' : 'Sin Turno Activo' }}
            </div>
            <div class="status-subtitle" v-if="turnoStore.hasActiveSession">
              Iniciado a las <strong>{{ formatTime(turnoStore.activeSession.opened_at) }}</strong> ({{ formatDate(turnoStore.activeSession.opened_at) }}) · Cajero: <strong>{{ turnoStore.activeSession.cashier?.name || authStore.user?.name }}</strong>
            </div>
            <div class="status-subtitle" v-else>
              Abre un turno con fondo inicial para habilitar el cobro y control de arqueo.
            </div>
          </div>
        </div>
      </div>

      <div class="session-actions-right">
        <button class="btn-icon-refresh" @click="handleRefresh" title="Actualizar datos">
          🔄
        </button>

        <button 
          v-if="!turnoStore.hasActiveSession"
          class="btn btn-primary"
          @click="turnoStore.isOpeningModalOpen = true"
        >
          ➕ Abrir Caja / Turno
        </button>

        <button 
          v-else
          class="btn btn-danger-soft"
          @click="turnoStore.isWizardOpen = true"
        >
          🔒 Cerrar Caja / Arqueo
        </button>
      </div>
    </div>

    <!-- ================= 2. TARJETAS DE MÉTRICAS DEL TURNO ACTIVO ================= -->
    <div v-if="turnoStore.hasActiveSession" class="metrics-grid">
      <div class="metric-card highlight">
        <div class="metric-icon">💵</div>
        <div class="metric-content">
          <div class="metric-label">Efectivo en Gaveta</div>
          <div class="metric-value">Bs {{ turnoStore.currentCashInDrawer.toFixed(2) }}</div>
          <div class="metric-hint">Fondo: Bs {{ Number(turnoStore.activeSession.opening_amount).toFixed(2) }} + Ventas: Bs {{ Number(turnoStore.sessionStats?.cash_sales || 0).toFixed(2) }}</div>
        </div>
      </div>

      <div class="metric-card">
        <div class="metric-icon">📈</div>
        <div class="metric-content">
          <div class="metric-label">Total Ventas (Turno)</div>
          <div class="metric-value">Bs {{ Number(turnoStore.sessionStats?.total_sales || 0).toFixed(2) }}</div>
          <div class="metric-hint">{{ turnoStore.sessionStats?.sales_count || 0 }} tickets completados</div>
        </div>
      </div>

      <div class="metric-card">
        <div class="metric-icon">📱</div>
        <div class="metric-content">
          <div class="metric-label">Ventas QR / Transf.</div>
          <div class="metric-value">Bs {{ Number(turnoStore.sessionStats?.qr_sales || 0).toFixed(2) }}</div>
          <div class="metric-hint">Pago digital directo</div>
        </div>
      </div>

      <div class="metric-card">
        <div class="metric-icon">💳</div>
        <div class="metric-content">
          <div class="metric-label">Ventas con Tarjeta</div>
          <div class="metric-value">Bs {{ Number(turnoStore.sessionStats?.card_sales || 0).toFixed(2) }}</div>
          <div class="metric-hint">Cobro con POS / Datáfono</div>
        </div>
      </div>
    </div>

    <!-- ================= 3. PANEL DE REGISTRO E HISTORIAL DE VENTAS ================= -->
    <div class="history-card">
      <!-- HEADER CON TÍTULO Y FILTROS RÁPIDOS -->
      <div class="history-header">
        <div class="header-left">
          <div class="header-title-row">
            <h2>Registro e Historial de Ventas</h2>
            <button 
              v-if="authStore.user?.role === 'super_admin'" 
              class="btn-retroactive" 
              @click="openRetroactiveSaleModal"
              title="Registrar venta manual en fecha pasada (Solo Super Admin)"
            >
              🔒 ＋ Venta Retroactiva
            </button>
          </div>
          <p>Consulta, filtra y audita las ventas por fecha, día, mes o método de pago.</p>
        </div>

        <!-- PERIOD TABS -->
        <div class="period-pills">
          <button 
            class="pill" 
            :class="{ active: turnoStore.filters.period === 'today' }"
            @click="turnoStore.setPeriod('today')"
          >
            Hoy
          </button>
          <button 
            class="pill" 
            :class="{ active: turnoStore.filters.period === 'yesterday' }"
            @click="turnoStore.setPeriod('yesterday')"
          >
            Ayer
          </button>
          <button 
            class="pill" 
            :class="{ active: turnoStore.filters.period === 'week' }"
            @click="turnoStore.setPeriod('week')"
          >
            Esta Semana
          </button>
          <button 
            class="pill" 
            :class="{ active: turnoStore.filters.period === 'month' }"
            @click="turnoStore.setPeriod('month')"
          >
            Este Mes
          </button>
          <button 
            class="pill" 
            :class="{ active: turnoStore.filters.period === 'year' }"
            @click="turnoStore.setPeriod('year')"
          >
            Este Año
          </button>
          <button 
            class="pill" 
            :class="{ active: turnoStore.filters.period === 'custom' }"
            @click="turnoStore.filters.period = 'custom'"
          >
            📅 Personalizado
          </button>
        </div>
      </div>

      <!-- DATE RANGE CUSTOM PICKER (SI ELIGE PERSONALIZADO) -->
      <div v-if="turnoStore.filters.period === 'custom'" class="custom-range-bar">
        <div class="date-input-group">
          <label>Desde:</label>
          <input type="date" v-model="customFrom" class="date-picker" />
        </div>
        <div class="date-input-group">
          <label>Hasta:</label>
          <input type="date" v-model="customTo" class="date-picker" />
        </div>
        <button class="btn btn-sm btn-primary" @click="applyCustomDateRange">
          Filtrar Rango
        </button>
      </div>

      <!-- FILTERS BAR: MÉTODOS DE PAGO Y BUSCADOR -->
      <div class="filter-controls-row">
        <!-- TABS METODOS DE PAGO -->
        <div class="method-chips">
          <button 
            class="chip" 
            :class="{ active: turnoStore.filters.payment_method === null }"
            @click="turnoStore.setPaymentMethod(null)"
          >
            Todos los métodos
          </button>
          <button 
            class="chip" 
            :class="{ active: turnoStore.filters.payment_method === 'cash' }"
            @click="turnoStore.setPaymentMethod('cash')"
          >
            💵 Efectivo
          </button>
          <button 
            class="chip" 
            :class="{ active: turnoStore.filters.payment_method === 'qr' }"
            @click="turnoStore.setPaymentMethod('qr')"
          >
            📱 QR
          </button>
          <button 
            class="chip" 
            :class="{ active: turnoStore.filters.payment_method === 'card' }"
            @click="turnoStore.setPaymentMethod('card')"
          >
            💳 Tarjeta
          </button>
        </div>

        <!-- SEARCH INPUT -->
        <div class="search-box">
          <span class="search-icon">🔍</span>
          <input 
            type="text"
            v-model="searchInput"
            @input="handleSearchChange"
            placeholder="Buscar por ID, cliente, producto..."
            class="search-input"
          />
          <button v-if="searchInput" class="clear-search" @click="searchInput = ''; handleSearchChange()">✕</button>
        </div>
      </div>

      <!-- RESUMEN FINANCIERO DEL FILTRO ACTUAL -->
      <div class="summary-strip">
        <div class="strip-item">
          <span class="strip-label">Total en ventas</span>
          <strong class="strip-value primary">Bs {{ turnoStore.summary.total_sales.toFixed(2) }}</strong>
        </div>
        <div class="strip-divider"></div>
        <div class="strip-item">
          <span class="strip-label">Efectivo</span>
          <strong class="strip-value">Bs {{ turnoStore.summary.cash_total.toFixed(2) }}</strong>
        </div>
        <div class="strip-item">
          <span class="strip-label">QR / Transf.</span>
          <strong class="strip-value">Bs {{ turnoStore.summary.qr_total.toFixed(2) }}</strong>
        </div>
        <div class="strip-item">
          <span class="strip-label">Tarjeta</span>
          <strong class="strip-value">Bs {{ turnoStore.summary.card_total.toFixed(2) }}</strong>
        </div>
        <div class="strip-divider"></div>
        <div class="strip-item">
          <span class="strip-label">Tickets</span>
          <strong class="strip-value">{{ turnoStore.summary.sales_count }}</strong>
        </div>
        <div class="strip-item" v-if="turnoStore.summary.voided_count > 0">
          <span class="strip-label text-danger">Anulados</span>
          <strong class="strip-value text-danger">{{ turnoStore.summary.voided_count }}</strong>
        </div>
      </div>

      <!-- TABLA DE VENTAS -->
      <div class="table-responsive">
        <table class="sales-table">
          <thead>
            <tr>
              <th>Ticket #</th>
              <th>Fecha y Hora</th>
              <th>Cliente</th>
              <th>Productos</th>
              <th>Método</th>
              <th>Total</th>
              <th>Estado</th>
              <th style="text-align: right;">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="turnoStore.salesLoading">
              <td colspan="8" class="text-center py-6">
                <div class="loading-spinner">Cargando ventas...</div>
              </td>
            </tr>

            <tr v-else-if="turnoStore.sales.length === 0">
              <td colspan="8" class="text-center py-8 text-muted">
                No se encontraron ventas para los filtros seleccionados.
              </td>
            </tr>

            <tr 
              v-for="sale in turnoStore.sales" 
              :key="sale.id"
              :class="{ 'row-voided': sale.status === 'voided' }"
              @click="turnoStore.openSaleDetail(sale)"
              class="clickable-row"
            >
              <!-- TICKET ID -->
              <td>
                <div class="ticket-cell-wrap">
                  <span class="ticket-badge">#{{ sale.order_number || (sale.id ? sale.id.substring(0, 8).toUpperCase() : '---') }}</span>
                  <span v-if="sale.source === 'manual_retroactive'" class="badge-source-manual" title="Venta manual / retroactiva">Manual</span>
                  <span v-if="sale.edited_by" class="badge-source-edited" title="Venta editada por Super Admin">Editado</span>
                </div>
              </td>

              <!-- FECHA Y HORA -->
              <td>
                <div class="time-cell">
                  <strong>{{ formatTime(sale.created_at) }}</strong>
                  <small>{{ formatDate(sale.created_at) }}</small>
                </div>
              </td>

              <!-- CLIENTE -->
              <td>
                <div class="customer-cell">
                  <span>{{ sale.customer?.name || 'Cliente Ocasional' }}</span>
                  <small v-if="sale.customer?.ci_or_phone">{{ sale.customer.ci_or_phone }}</small>
                </div>
              </td>

              <!-- PRODUCTOS -->
              <td>
                <div class="items-summary-cell">
                  <span v-if="sale.items && sale.items.length > 0">
                    {{ Number(sale.items[0].quantity) }}x {{ sale.items[0].name || sale.items[0].product?.name }}
                    <small v-if="sale.items.length > 1" class="more-items-tag">+{{ sale.items.length - 1 }} más</small>
                  </span>
                  <span v-else class="text-muted">Sin detalle</span>
                </div>
              </td>

              <!-- MÉTODO DE PAGO -->
              <td>
                <div v-if="sale.payments && sale.payments.length > 1" class="multi-payment-tag">
                  Mixto ({{ sale.payments.length }})
                </div>
                <div v-else>
                  <span 
                    class="payment-pill"
                    :class="getPaymentBadge(getSalePrimaryMethod(sale)).class"
                  >
                    {{ getPaymentBadge(getSalePrimaryMethod(sale)).icon }}
                    {{ getPaymentBadge(getSalePrimaryMethod(sale)).label }}
                  </span>
                </div>
              </td>

              <!-- TOTAL -->
              <td>
                <strong class="price-cell">Bs {{ Number(sale.total_amount).toFixed(2) }}</strong>
              </td>

              <!-- ESTADO -->
              <td>
                <span 
                  class="status-pill"
                  :class="sale.status === 'completed' ? 'completed' : 'voided'"
                >
                  {{ sale.status === 'completed' ? 'Completada' : 'Anulada' }}
                </span>
              </td>

              <!-- ACCIONES -->
              <td style="text-align: right;" @click.stop>
                <div class="actions-cell">
                  <button 
                    v-if="authStore.user?.role === 'super_admin' && sale.status !== 'voided'"
                    class="btn-icon-edit"
                    @click="openEditSaleModal(sale)"
                    title="Editar venta y corregir items/montos (Solo Super Admin)"
                  >
                    ✏️
                  </button>
                  <button 
                    class="btn-icon-view" 
                    @click="turnoStore.openSaleDetail(sale)"
                    title="Ver detalle del ticket"
                  >
                    👁️ Detalle
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- PAGINACIÓN -->
      <div v-if="turnoStore.pagination.lastPage > 1" class="pagination-bar">
        <div class="page-info">
          Mostrando página <strong>{{ turnoStore.pagination.currentPage }}</strong> de <strong>{{ turnoStore.pagination.lastPage }}</strong> ({{ turnoStore.pagination.total }} ventas totales)
        </div>
        <div class="page-buttons">
          <button 
            class="btn btn-sm btn-ghost" 
            :disabled="turnoStore.pagination.currentPage <= 1"
            @click="turnoStore.fetchSales(turnoStore.pagination.currentPage - 1)"
          >
            ← Anterior
          </button>
          <button 
            class="btn btn-sm btn-ghost" 
            :disabled="turnoStore.pagination.currentPage >= turnoStore.pagination.lastPage"
            @click="turnoStore.fetchSales(turnoStore.pagination.currentPage + 1)"
          >
            Siguiente →
          </button>
        </div>
      </div>
    </div>

    <!-- ================= MODAL: ABRIR CAJA / TURNO ================= -->
    <div v-if="turnoStore.isOpeningModalOpen" class="modal-overlay" @click.self="turnoStore.isOpeningModalOpen = false">
      <div class="modal-card">
        <div class="modal-header">
          <div>
            <div class="badge-new">Apertura de Turno</div>
            <h3>Abrir Caja Registradora</h3>
          </div>
          <button class="close-btn" @click="turnoStore.isOpeningModalOpen = false">✕</button>
        </div>

        <div class="modal-body">
          <p class="modal-desc">
            Ingresa el monto de <strong>fondo inicial</strong> con el que comienzas el turno para el cambio y vuelto.
          </p>

          <div v-if="openingError" class="error-banner">
            ⚠️ {{ openingError }}
          </div>

          <div class="field">
            <label><strong>Fondo de caja inicial (Bs):</strong></label>
            <input 
              type="number" 
              min="0"
              step="10"
              v-model.number="openingAmountInput" 
              class="opening-input"
              autofocus
            />
          </div>

          <!-- CHIPS DE VALORES RÁPIDOS -->
          <div class="quick-amounts">
            <span>Sugerencias rápidas:</span>
            <div class="chips-row">
              <button type="button" class="amount-chip" @click="openingAmountInput = 100">Bs 100</button>
              <button type="button" class="amount-chip" @click="openingAmountInput = 150">Bs 150</button>
              <button type="button" class="amount-chip" @click="openingAmountInput = 200">Bs 200</button>
              <button type="button" class="amount-chip" @click="openingAmountInput = 300">Bs 300</button>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-ghost" @click="turnoStore.isOpeningModalOpen = false">Cancelar</button>
          <button 
            class="btn btn-primary" 
            :disabled="openingAmountInput < 0 || isOpening" 
            @click="handleOpenSession"
          >
            <span v-if="isOpening">Abriendo caja...</span>
            <span v-else>Confirmar Apertura 🟢</span>
          </button>
        </div>
      </div>
    </div>

    <!-- ================= SUBCOMPONENTES / DRAWERS ================= -->
    <!-- WIZARD DE ARQUEO Y CIERRE -->
    <CashCloseWizard 
      v-if="turnoStore.isWizardOpen"
      @close="turnoStore.isWizardOpen = false"
      @closed="handleRefresh"
    />

    <!-- DRAWER DE DETALLE DE VENTA -->
    <SaleDetailDrawer 
      @close="turnoStore.closeSaleDetail"
      @voided="handleRefresh"
    />

    <!-- MODAL DE VENTA RETROACTIVA Y EDICIÓN (SUPER ADMIN) -->
    <AdminSaleEditor 
      :visible="isRetroactiveModalOpen"
      :sale="editingSaleForAdmin"
      @close="isRetroactiveModalOpen = false"
      @saved="handleRefresh"
    />
  </div>
</template>

<style scoped>
.turno-container {
  padding: 24px;
  max-width: 1440px;
  margin: 0 auto;
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 20px;
  background: var(--cream-50);
  min-height: 100%;
}

/* ================= 1. CABECERA DE SESIÓN ================= */
.session-top-card {
  background: var(--surface);
  border: 1.5px solid var(--border);
  border-radius: 16px;
  padding: 18px 24px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  box-shadow: var(--shadow-card);
}

.status-indicator {
  display: flex;
  align-items: center;
  gap: 14px;
}

.pulse-dot {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  display: inline-block;
  flex-shrink: 0;
}

.pulse-dot.online {
  background: var(--lime-500);
  box-shadow: 0 0 0 4px rgba(95, 191, 113, 0.3);
  animation: pulseGreen 2s infinite;
}

.pulse-dot.offline {
  background: var(--danger-500);
  box-shadow: 0 0 0 4px rgba(229, 72, 77, 0.2);
}

@keyframes pulseGreen {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(95, 191, 113, 0.7); }
  70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(95, 191, 113, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(95, 191, 113, 0); }
}

.status-title {
  font-family: 'Baloo 2', sans-serif;
  font-size: 19px;
  font-weight: 800;
  color: var(--ink-900);
}

.status-subtitle {
  font-size: 13px;
  color: var(--ink-600);
  margin-top: 2px;
}

.session-actions-right {
  display: flex;
  align-items: center;
  gap: 10px;
}

.btn-icon-refresh {
  background: var(--surface-alt);
  border: 1.5px solid var(--border);
  width: 40px;
  height: 40px;
  border-radius: 10px;
  font-size: 16px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
}

.btn-icon-refresh:hover {
  background: var(--cream-200);
  transform: rotate(180deg);
}

.btn-danger-soft {
  background: var(--danger-100);
  color: var(--danger-700);
  border: 1.5px solid var(--danger-300);
  padding: 10px 18px;
  border-radius: 12px;
  font-weight: 700;
  font-size: 14px;
  cursor: pointer;
  transition: all 0.15s;
}

.btn-danger-soft:hover {
  background: var(--danger-500);
  color: white;
}

/* ================= 2. GRID DE MÉTRICAS ================= */
.metrics-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 16px;
}

.metric-card {
  background: var(--surface);
  border: 1.5px solid var(--border);
  border-radius: 16px;
  padding: 16px 18px;
  display: flex;
  align-items: flex-start;
  gap: 14px;
  box-shadow: var(--shadow-card);
}

.metric-card.highlight {
  border-color: var(--passion-400);
  background: var(--cream-100);
}

.metric-icon {
  font-size: 28px;
  background: var(--surface-alt);
  width: 48px;
  height: 48px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.metric-label {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  color: var(--ink-500);
  letter-spacing: 0.04em;
}

.metric-value {
  font-family: 'Baloo 2', sans-serif;
  font-size: 24px;
  font-weight: 800;
  color: var(--ink-900);
  margin: 2px 0;
}

.metric-hint {
  font-size: 11.5px;
  color: var(--ink-500);
}

/* ================= 3. HISTORIAL Y FILTROS ================= */
.history-card {
  background: var(--surface);
  border: 1.5px solid var(--border);
  border-radius: 18px;
  padding: 24px;
  box-shadow: var(--shadow-card);
}

.history-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 18px;
}

.header-left h2 {
  margin: 0;
  font-size: 20px;
  color: var(--ink-900);
}

.header-left p {
  margin: 4px 0 0;
  font-size: 13px;
  color: var(--ink-500);
}

/* Period Pills */
.period-pills {
  display: flex;
  background: var(--surface-alt);
  padding: 4px;
  border-radius: 12px;
  border: 1px solid var(--border);
  gap: 4px;
  flex-wrap: wrap;
}

.pill {
  background: transparent;
  border: none;
  padding: 6px 12px;
  border-radius: 8px;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--ink-600);
  cursor: pointer;
  transition: all 0.15s;
}

.pill:hover {
  color: var(--ink-900);
}

.pill.active {
  background: var(--passion-500);
  color: white;
  box-shadow: 0 2px 6px rgba(229, 72, 77, 0.3);
}

/* Custom Date Range */
.custom-range-bar {
  display: flex;
  align-items: center;
  gap: 14px;
  background: var(--cream-100);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 10px 16px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.date-input-group {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
}

.date-picker {
  padding: 6px 10px;
  border-radius: 8px;
  border: 1.5px solid var(--border);
  font-family: inherit;
  font-size: 13px;
  background: var(--surface);
}

/* Filter Controls Row */
.filter-controls-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 14px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.method-chips {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.chip {
  background: var(--surface);
  border: 1.5px solid var(--border);
  padding: 6px 12px;
  border-radius: 20px;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--ink-700);
  cursor: pointer;
  transition: all 0.15s;
}

.chip.active {
  background: var(--acai-700);
  color: white;
  border-color: var(--acai-700);
}

.search-box {
  position: relative;
  min-width: 280px;
  flex: 1;
  max-width: 360px;
}

.search-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 14px;
  color: var(--ink-400);
}

.search-input {
  width: 100%;
  padding: 9px 34px 9px 34px;
  border-radius: 10px;
  border: 1.5px solid var(--border);
  font-size: 13.5px;
  background: var(--surface-alt);
  color: var(--ink-900);
}

.search-input:focus {
  outline: none;
  border-color: var(--passion-500);
}

.clear-search {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  color: var(--ink-400);
  cursor: pointer;
}

/* Summary Strip */
.summary-strip {
  background: var(--surface-alt);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 12px 18px;
  display: flex;
  align-items: center;
  gap: 20px;
  margin-bottom: 18px;
  overflow-x: auto;
}

.strip-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
  white-space: nowrap;
}

.strip-label {
  font-size: 11px;
  text-transform: uppercase;
  font-weight: 700;
  color: var(--ink-500);
}

.strip-value {
  font-family: 'Baloo 2', sans-serif;
  font-size: 16px;
  color: var(--ink-900);
}

.strip-value.primary {
  color: var(--passion-600);
  font-size: 18px;
  font-weight: 800;
}

.strip-divider {
  width: 1px;
  height: 28px;
  background: var(--border);
}

/* Sales Table */
.table-responsive {
  overflow-x: auto;
}

.sales-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 13.5px;
}

.sales-table th {
  background: var(--cream-100);
  color: var(--ink-700);
  font-weight: 700;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 12px 14px;
  border-bottom: 2px solid var(--border);
  text-align: left;
}

.sales-table td {
  padding: 12px 14px;
  border-bottom: 1px solid var(--border);
  color: var(--ink-900);
  vertical-align: middle;
}

.clickable-row {
  cursor: pointer;
  transition: background 0.1s;
}

.clickable-row:hover {
  background: var(--surface-hover);
}

.clickable-row.row-voided {
  background: rgba(229, 72, 77, 0.04);
  opacity: 0.75;
}

.ticket-badge {
  font-family: 'JetBrains Mono', monospace;
  font-weight: 700;
  font-size: 12px;
  background: var(--surface-alt);
  padding: 3px 6px;
  border-radius: 6px;
  border: 1px solid var(--border);
}

.time-cell strong {
  display: block;
}

.time-cell small {
  font-size: 11px;
  color: var(--ink-500);
}

.customer-cell span {
  font-weight: 600;
  display: block;
}

.customer-cell small {
  font-size: 11px;
  color: var(--ink-500);
}

.items-summary-cell {
  max-width: 220px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.more-items-tag {
  background: var(--cream-200);
  padding: 1px 5px;
  border-radius: 4px;
  font-size: 10.5px;
  color: var(--ink-700);
  margin-left: 4px;
}

.payment-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
}

.badge-cash { background: var(--lime-100); color: var(--lime-800); }
.badge-qr { background: var(--passion-100); color: var(--passion-800); }
.badge-card { background: #E0F2FE; color: #0369A1; }
.badge-other { background: var(--cream-200); color: var(--ink-700); }

.multi-payment-tag {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--passion-600);
}

.price-cell {
  font-family: 'Baloo 2', sans-serif;
  font-size: 15px;
  font-weight: 800;
}

.status-pill {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
}

.status-pill.completed {
  background: var(--lime-100);
  color: var(--lime-800);
}

.status-pill.voided {
  background: var(--danger-100);
  color: var(--danger-700);
}

.btn-icon-view {
  background: var(--surface-alt);
  border: 1px solid var(--border);
  padding: 6px 10px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 700;
  color: var(--ink-700);
  cursor: pointer;
}

.btn-icon-view:hover {
  background: var(--passion-500);
  color: white;
  border-color: var(--passion-500);
}

/* Pagination */
.pagination-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1px solid var(--border);
}

.page-info {
  font-size: 13px;
  color: var(--ink-600);
}

.page-buttons {
  display: flex;
  gap: 8px;
}

/* Modal Apertura */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(26, 13, 33, 0.6);
  backdrop-filter: blur(2px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1200;
  padding: 16px;
}

.modal-card {
  background: var(--surface);
  border-radius: 18px;
  width: 100%;
  max-width: 460px;
  box-shadow: var(--shadow-pop);
  overflow: hidden;
}

.modal-header {
  padding: 20px 24px;
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.badge-new {
  display: inline-block;
  background: var(--lime-100);
  color: var(--lime-800);
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  padding: 2px 8px;
  border-radius: 999px;
  margin-bottom: 4px;
}

.modal-header h3 {
  margin: 0;
  font-size: 19px;
  color: var(--ink-900);
}

.modal-desc {
  margin: 0 0 16px;
  font-size: 13px;
  color: var(--ink-600);
  line-height: 1.4;
}

.modal-body {
  padding: 20px 24px;
}

.opening-input {
  width: 100%;
  padding: 12px 14px;
  border-radius: 12px;
  border: 2px solid var(--border);
  font-family: 'Baloo 2', sans-serif;
  font-size: 22px;
  font-weight: 800;
  color: var(--ink-900);
  background: var(--surface-alt);
  text-align: center;
  margin-top: 6px;
}

.opening-input:focus {
  outline: none;
  border-color: var(--passion-500);
}

.quick-amounts {
  margin-top: 14px;
}

.quick-amounts span {
  font-size: 11.5px;
  color: var(--ink-500);
  font-weight: 600;
}

.chips-row {
  display: flex;
  gap: 8px;
  margin-top: 6px;
}

.amount-chip {
  flex: 1;
  background: var(--cream-100);
  border: 1px solid var(--border);
  padding: 8px;
  border-radius: 8px;
  font-weight: 700;
  font-size: 13px;
  color: var(--ink-800);
  cursor: pointer;
  transition: all 0.15s;
}

.amount-chip:hover {
  background: var(--passion-500);
  color: white;
  border-color: var(--passion-500);
}

.modal-footer {
  padding: 16px 24px;
  border-top: 1px solid var(--border);
  background: var(--surface-alt);
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

@media (max-width: 900px) {
  .session-top-card {
    flex-direction: column;
    align-items: flex-start;
    gap: 14px;
  }
  .session-actions-right {
    width: 100%;
    justify-content: flex-end;
  }
  .history-header {
    flex-direction: column;
  }
  .search-box {
    max-width: 100%;
  }
}

.header-title-row {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}

.btn-retroactive {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #ffffff;
  border: none;
  padding: 6px 14px;
  border-radius: 9999px;
  font-size: 12.5px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(217, 119, 6, 0.3);
  transition: all 0.2s;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.btn-retroactive:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 10px rgba(217, 119, 6, 0.4);
  background: linear-gradient(135deg, #fbbf24, #d97706);
}

.ticket-cell-wrap {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 3px;
}

.badge-source-manual {
  font-size: 10px;
  font-weight: 800;
  background: #fef3c7;
  color: #b45309;
  padding: 1px 6px;
  border-radius: 4px;
  border: 1px solid #fde68a;
  text-transform: uppercase;
}

.badge-source-edited {
  font-size: 10px;
  font-weight: 800;
  background: #ede9fe;
  color: #6d28d9;
  padding: 1px 6px;
  border-radius: 4px;
  border: 1px solid #ddd6fe;
  text-transform: uppercase;
}

.actions-cell {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 6px;
}

.btn-icon-edit {
  background: #ede9fe;
  border: 1px solid #ddd6fe;
  color: #6d28d9;
  padding: 5px 8px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 12px;
  transition: all 0.15s;
}

.btn-icon-edit:hover {
  background: #ddd6fe;
}
</style>
