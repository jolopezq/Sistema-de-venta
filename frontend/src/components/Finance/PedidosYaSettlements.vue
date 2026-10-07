<script setup>
import { computed, ref, watch, onMounted } from 'vue';
import { apiFetch } from '../../services/api';
import { useFinanceStore, formatBs, formatDay, formatPercent, errorText } from '../../stores/finance';
import FinanceModal from './FinanceModal.vue';
import PedidosYaImportModal from './PedidosYaImportModal.vue';

/** Pestaña PedidosYa: semanas importadas del mes, su conciliación y sus pedidos. */
const props = defineProps({ intent: { type: String, default: '' } });
const emit = defineEmits(['intent-done']);
const finance = useFinanceStore();

const pya = computed(() => finance.pnl?.pedidosya_analysis || null);
const importOpen = ref(false);

function retention(st) {
  const gross = Number(st.csv_gross_sales) || 0;
  const kept = gross - (Number(st.total_payout) || 0);
  return { amount: kept, percent: gross > 0 ? (kept / gross) * 100 : 0 };
}

function crossesMonth(st) {
  return String(st.start_date).slice(0, 7) !== String(st.end_date).slice(0, 7);
}

async function onImported(res) {
  importOpen.value = false;
  finance.notify(res.message, res.settlement?.reconciliation?.status === 'ok' ? 'ok' : 'error');
  if (res.warnings?.length) setTimeout(() => finance.notify(res.warnings.join(' '), 'error'), 2800);
  await Promise.all([finance.loadSettlements(), finance.loadPnl()]);
}

async function remove(st) {
  const ok = await finance.confirm({
    title: 'Eliminar semana',
    message: `Semana ${formatDay(st.start_date)} – ${formatDay(st.end_date)} (${st.orders_count} pedidos). `
      + 'Sus montos salen del Estado de Resultados; puedes volver a importarla después.',
    confirmLabel: 'Eliminar semana',
    danger: true,
  });
  if (!ok) return;
  try {
    await apiFetch(`/finance/pedidosya-settlements/${st.id}`, { method: 'DELETE' });
    finance.notify('Semana eliminada.');
    await Promise.all([finance.loadSettlements(), finance.loadPnl()]);
  } catch (err) {
    finance.notify(errorText(err), 'error');
  }
}

// ---------- Detalle de la semana ----------
const detail = ref(null);
const detailLoading = ref(false);
const statusFilter = ref('');
const page = ref(1);

async function loadDetail(st) {
  detailLoading.value = true;
  try {
    const params = new URLSearchParams({ page: page.value });
    if (statusFilter.value) params.set('status', statusFilter.value);
    detail.value = await apiFetch(`/finance/pedidosya-settlements/${st.id}?${params}`);
  } catch (err) {
    finance.notify(errorText(err), 'error');
    detail.value = null;
  } finally {
    detailLoading.value = false;
  }
}

function openDetail(st) {
  statusFilter.value = '';
  page.value = 1;
  detail.value = { settlement: st, orders: null };
  loadDetail(st);
}
function setFilter(status) {
  statusFilter.value = status;
  page.value = 1;
  loadDetail(detail.value.settlement);
}
function goPage(delta) {
  page.value += delta;
  loadDetail(detail.value.settlement);
}

const statement = computed(() => {
  const s = detail.value?.settlement;
  if (!s) return [];
  return [
    ['Ventas brutas (CSV, entregados)', s.csv_gross_sales],
    ['Ventas netas', s.net_sales],
    ['Envíos a cargo del local', -s.delivery_fees],
    ['Servicios PedidosYa (comisión)', -s.service_fees],
    ['Cargos operativos', -s.operating_charges],
    ['Publicidad en la app', -s.advertising],
    ['Reintegros', s.refunds],
    ['Impuestos (IVA)', -s.taxes],
  ];
});

function hour(value) {
  if (!value) return '';
  return String(value).slice(11, 16);
}

function handleIntent(intent) {
  if (intent === 'import') importOpen.value = true;
  if (intent) emit('intent-done');
}
onMounted(() => handleIntent(props.intent));
watch(() => props.intent, handleIntent);
</script>

<template>
  <div class="fin-card">
    <div class="fin-card-head">
      <div>
        <h2>PedidosYa en {{ finance.periodLabel }}</h2>
        <p>Cada semana se carga con el CSV de pedidos y el estado de cuenta. Las que cruzan dos meses se reparten por la fecha de cada pedido.</p>
      </div>
      <button class="fin-btn fin-btn-primary" type="button" @click="importOpen = true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12" /></svg>
        Cargar semana
      </button>
    </div>
    <div v-if="pya && pya.settlements_count" class="fin-card-body">
      <div class="fin-kpis">
        <div class="fin-kpi" style="padding: 0">
          <span class="fin-kpi-label">Ventas brutas</span>
          <span class="fin-kpi-value fin-num">{{ formatBs(pya.gross_sales) }}</span>
          <span class="fin-kpi-sub">{{ pya.delivered_count }} entregados · {{ pya.cancelled_count }} cancelados</span>
        </div>
        <div class="fin-kpi" style="padding: 0">
          <span class="fin-kpi-label">Total liquidado</span>
          <span class="fin-kpi-value fin-num">{{ formatBs(pya.total_payout) }}</span>
          <span class="fin-kpi-sub">lo que llega al banco</span>
        </div>
        <div class="fin-kpi" style="padding: 0">
          <span class="fin-kpi-label">Se queda PedidosYa</span>
          <span class="fin-kpi-value fin-num">{{ formatPercent(pya.retention_percent) }}</span>
          <span class="fin-kpi-sub">{{ formatBs(pya.retention_amount) }} de las ventas brutas</span>
        </div>
        <div class="fin-kpi" style="padding: 0">
          <span class="fin-kpi-label">Ticket promedio</span>
          <span class="fin-kpi-value fin-num">{{ formatBs(pya.average_ticket) }}</span>
          <span class="fin-kpi-sub">por pedido entregado</span>
        </div>
      </div>
    </div>
  </div>

  <div class="fin-card">
    <div class="fin-card-head">
      <h3>Semanas del mes</h3>
    </div>
    <div v-if="finance.errors.settlements" class="fin-error">{{ finance.errors.settlements }}</div>
    <div v-else-if="finance.loading.settlements && !finance.settlements.length" class="fin-loading">Cargando semanas…</div>
    <div v-else-if="!finance.settlements.length" class="fin-empty">
      No hay semanas de PedidosYa cargadas en {{ finance.periodLabel }}.
      <div style="margin-top: 8px"><button class="fin-btn-link" type="button" @click="importOpen = true">Cargar la primera</button></div>
    </div>
    <div v-else class="fin-table-wrap">
      <table class="fin-table stack">
        <thead>
          <tr>
            <th>Semana</th>
            <th class="right">Pedidos</th>
            <th class="right">Ventas brutas</th>
            <th class="right">Liquidado</th>
            <th class="right">Se queda PedidosYa</th>
            <th>Conciliación</th>
            <th class="actions"><span class="sr-only">Acciones</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="st in finance.settlements" :key="st.id">
            <td data-label="Semana">
              <span class="fin-cell-main fin-num">{{ formatDay(st.start_date, { year: false }) }} – {{ formatDay(st.end_date) }}</span>
              <div class="fin-cell-sub">
                Pago {{ formatDay(st.payout_date) }}
                <template v-if="crossesMonth(st)"> · cruza meses (prorrateada)</template>
              </div>
            </td>
            <td data-label="Pedidos" class="right fin-num">{{ st.delivered_count }} / {{ st.orders_count }}</td>
            <td data-label="Ventas brutas" class="right fin-num">{{ formatBs(st.csv_gross_sales) }}</td>
            <td data-label="Liquidado" class="right fin-num fin-cell-main">{{ formatBs(st.total_payout) }}</td>
            <td data-label="Se queda" class="right fin-num">
              {{ formatBs(retention(st).amount) }}
              <div class="fin-cell-sub">{{ formatPercent(retention(st).percent) }}</div>
            </td>
            <td data-label="Conciliación">
              <span class="fin-badge" :class="st.reconciliation?.status === 'ok' ? 'ok' : 'err'">
                {{ st.reconciliation?.status === 'ok' ? 'Conciliada' : 'Con diferencias' }}
              </span>
            </td>
            <td class="actions">
              <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="openDetail(st)">Ver detalle</button>
              <button class="fin-icon-btn danger" type="button" :aria-label="`Eliminar semana ${formatDay(st.start_date)}`" @click="remove(st)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" /></svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <PedidosYaImportModal v-if="importOpen" @close="importOpen = false" @imported="onImported" />

  <!-- Detalle de la semana -->
  <FinanceModal
    v-if="detail"
    :title="`Semana ${formatDay(detail.settlement.start_date, { year: false })} – ${formatDay(detail.settlement.end_date)}`"
    :subtitle="`Pago ${formatDay(detail.settlement.payout_date)} · ${detail.settlement.orders_count} pedidos`"
    wide
    @close="detail = null"
  >
    <div class="fin-grid-2" style="align-items: start">
      <div class="fin-pairs">
        <div v-for="[label, value] in statement" :key="label" class="fin-pair">
          <span>{{ label }}</span><strong class="fin-num">{{ formatBs(value) }}</strong>
        </div>
        <div class="fin-pair total"><span>Total liquidado</span><span class="fin-num">{{ formatBs(detail.settlement.total_payout) }}</span></div>
      </div>
      <div class="fin-checks">
        <div v-for="c in detail.settlement.reconciliation?.checks || []" :key="c.key" class="fin-check">
          <span class="fin-check-label">
            {{ c.label }}
            <span class="fin-muted fin-small"> · esperado {{ formatBs(c.expected) }}</span>
          </span>
          <span v-if="c.ok" class="fin-badge ok">Cuadra</span>
          <span v-else class="fin-badge" :class="c.informative ? 'info' : 'err'">{{ c.informative ? 'Aviso' : 'Diferencia' }} {{ formatBs(c.difference, { sign: true }) }}</span>
        </div>
      </div>
    </div>

    <div class="fin-row">
      <div class="fin-chips" role="group" aria-label="Estado del pedido">
        <button class="fin-chip" :class="{ active: !statusFilter }" type="button" @click="setFilter('')">Todos</button>
        <button class="fin-chip" :class="{ active: statusFilter === 'Entregado' }" type="button" @click="setFilter('Entregado')">Entregados</button>
        <button class="fin-chip" :class="{ active: statusFilter === 'Cancelado' }" type="button" @click="setFilter('Cancelado')">Cancelados</button>
      </div>
    </div>

    <div v-if="detailLoading" class="fin-loading">Cargando pedidos…</div>
    <div v-else-if="!detail.orders?.data?.length" class="fin-empty">Sin pedidos con este filtro.</div>
    <div v-else class="fin-table-wrap">
      <table class="fin-table stack">
        <thead>
          <tr>
            <th>Pedido</th>
            <th>Artículos</th>
            <th>Pago</th>
            <th class="right">Total</th>
            <th class="right">Comisión</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="o in detail.orders.data" :key="o.id">
            <td data-label="Pedido">
              <span class="fin-cell-main fin-num">{{ formatDay(o.order_date, { year: false }) }} {{ hour(o.order_date) }}</span>
              <div class="fin-cell-sub">
                Nº {{ o.order_number }}
                <span class="fin-badge" :class="o.status === 'Entregado' ? 'ok' : o.status === 'Cancelado' ? 'err' : ''">{{ o.status }}</span>
              </div>
            </td>
            <td data-label="Artículos" class="fin-small">{{ o.items_text || '—' }}</td>
            <td data-label="Pago" class="fin-small">{{ o.payment_method }}</td>
            <td data-label="Total" class="right fin-num">{{ formatBs(o.subtotal) }}</td>
            <td data-label="Comisión" class="right fin-num">{{ formatBs(o.commission) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="detail.orders?.last_page > 1" class="fin-row">
      <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" :disabled="page <= 1" @click="goPage(-1)">Anterior</button>
      <span class="fin-small fin-muted">Página {{ page }} de {{ detail.orders.last_page }}</span>
      <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" :disabled="page >= detail.orders.last_page" @click="goPage(1)">Siguiente</button>
    </div>
  </FinanceModal>
</template>
