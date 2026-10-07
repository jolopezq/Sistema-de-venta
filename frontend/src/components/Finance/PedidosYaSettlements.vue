<script setup>
import { computed, ref, watch, onMounted } from 'vue';
import { apiFetch } from '../../services/api';
import { useFinanceStore, formatBs, formatDay, formatPercent, errorText } from '../../stores/finance';
import FinanceModal from './FinanceModal.vue';
import PedidosYaImportModal from './PedidosYaImportModal.vue';
import PedidosYaSalesPanel from './PedidosYaSalesPanel.vue';
import PedidosYaMappings from './PedidosYaMappings.vue';

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

// ---------- Informe de la semana (plan §6.6) ----------
const detail = ref(null); // { settlement, report }
const detailLoading = ref(false);
const statusFilter = ref('');

async function loadReport(st) {
  detailLoading.value = true;
  try {
    const report = await apiFetch(`/finance/pedidosya-settlements/${st.id}/report`);
    detail.value = { settlement: report.settlement, report };
  } catch (err) {
    finance.notify(errorText(err), 'error');
    detail.value = null;
  } finally {
    detailLoading.value = false;
  }
}

function openDetail(st) {
  statusFilter.value = '';
  detail.value = { settlement: st, report: null };
  loadReport(st);
}

const report = computed(() => detail.value?.report || null);
const filteredOrders = computed(() => (report.value?.orders || [])
  .filter((o) => !statusFilter.value || o.status === statusFilter.value));

const PAYMENT_LABEL = (m) => m;

function hour(value) {
  if (!value) return '';
  return String(value).slice(11, 16);
}

function printReport() {
  const r = report.value;
  if (!r) return;
  const st = r.settlement;
  const row = (label, value) => `<tr><td>${label}</td><td style="text-align:right">${value}</td></tr>`;
  const html = `<!doctype html><html lang="es"><head><meta charset="utf-8"><title>PedidosYa ${formatDay(st.start_date)} – ${formatDay(st.end_date)}</title>
<style>body{font-family:system-ui,sans-serif;color:#111;margin:24px;font-size:13px}h1{font-size:18px;margin:0 0 4px}h2{font-size:14px;margin:18px 0 6px}
table{border-collapse:collapse;width:100%}td{padding:4px 0;border-bottom:1px solid #ddd}.muted{color:#555}</style></head><body>
<h1>Informe PedidosYa · semana ${formatDay(st.start_date)} – ${formatDay(st.end_date)}</h1>
<div class="muted">Pago ${formatDay(st.payout_date)} · Ohana Açaí</div>
<h2>Ventas</h2><table>
${row(`Pedidos entregados / cancelados`, `${r.sales.delivered_count} / ${r.sales.cancelled_count}`)}
${row('Ventas brutas', formatBs(r.sales.gross_sales))}${row('Ticket promedio', formatBs(r.sales.average_ticket))}
${r.sales.by_payment.map((p) => row(`Pago: ${p.method} (${p.count})`, formatBs(p.amount))).join('')}
${row('Más vendidos', r.sales.top_items.map((i) => `${i.quantity} ${i.name}`).join(', '))}</table>
<h2>Gastos PedidosYa</h2><table>
${row(`Comisión (${formatPercent(r.costs.commission_percent)})`, formatBs(r.costs.commission))}
${row(`Publicidad (${formatPercent(r.costs.advertising_percent)})`, formatBs(r.costs.advertising))}
${row('IVA en comisiones', formatBs(r.costs.taxes))}${row('Cargos operativos', formatBs(r.costs.operating_charges))}
${row('Envíos a cargo del local', formatBs(r.costs.delivery_fees))}${row(`Total (${formatPercent(r.costs.total_percent)})`, formatBs(r.costs.total))}</table>
<h2>Descuentos y reintegros</h2><table>
${row('Descuentos del local', formatBs(r.discounts.vendor))}${row('Descuentos PedidosYa', formatBs(r.discounts.pedidosya))}
${row(`Reintegros (cancelados: ${r.refunds.cancelled_count} por ${formatBs(r.refunds.cancelled_amount)})`, formatBs(r.refunds.total))}</table>
<h2>Resultado</h2><table>
${row('Ventas netas', formatBs(r.result.net_sales))}${row('Total liquidado', formatBs(r.result.total_payout))}
${row(`Se queda PedidosYa (${formatPercent(r.result.retained_percent)})`, formatBs(r.result.retained_amount))}
${r.result.product_cost !== null ? row(`Costo de productos estimado (${formatPercent(r.result.food_cost_percent)} del mes)`, formatBs(r.result.product_cost)) + row('Ganancia estimada', formatBs(r.result.estimated_profit)) : ''}
</table>
<h2>Control</h2><table>
${r.control.reconciliation.checks.map((c) => row(c.label, c.ok ? 'Cuadra' : `Diferencia ${formatBs(c.difference, { sign: true })}`)).join('')}
${row('Ventas creadas / vinculadas / sin venta', `${r.control.sales_created} / ${r.control.sales_linked} / ${r.control.without_sale}`)}
${r.control.price_differences.map((d) => row(`Pedido ${d.order_number} con otro precio`, `${formatBs(d.total)} (Tarifario ${formatBs(d.catalog_total)})`)).join('')}
</table></body></html>`;
  const w = window.open('', '_blank');
  if (!w) { finance.notify('El navegador bloqueó la ventana del informe.', 'error'); return; }
  w.document.write(html);
  w.document.close();
  w.focus();
  w.print();
}

// ---------- Crear o vincular las ventas de una semana ya cargada ----------
const salesSync = ref(null); // { settlement, analysis }
const salesLinks = ref({});
const syncing = ref(false);

async function openSalesSync() {
  const st = detail.value.settlement;
  salesLinks.value = {};
  salesSync.value = { settlement: st, analysis: null };
  await loadSalesAnalysis();
}

async function loadSalesAnalysis() {
  try {
    salesSync.value.analysis = await apiFetch(`/finance/pedidosya-settlements/${salesSync.value.settlement.id}/sales`);
  } catch (err) {
    finance.notify(errorText(err), 'error');
    salesSync.value = null;
  }
}

const syncBlocked = computed(() => (salesSync.value?.analysis?.summary?.unresolved_names || 0) > 0);

async function runSync() {
  syncing.value = true;
  try {
    const res = await apiFetch(`/finance/pedidosya-settlements/${salesSync.value.settlement.id}/sales`, {
      method: 'POST',
      body: JSON.stringify({ links: salesLinks.value }),
    });
    finance.notify(res.message, res.sales?.errors?.length ? 'error' : 'ok');
    const st = salesSync.value.settlement;
    salesSync.value = null;
    await loadReport(st);
  } catch (err) {
    finance.notify(errorText(err), 'error');
  } finally {
    syncing.value = false;
  }
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

  <PedidosYaMappings />

  <PedidosYaImportModal v-if="importOpen" @close="importOpen = false" @imported="onImported" />

  <!-- Informe de la semana -->
  <FinanceModal
    v-if="detail"
    :title="`Semana ${formatDay(detail.settlement.start_date, { year: false })} – ${formatDay(detail.settlement.end_date)}`"
    :subtitle="`Pago ${formatDay(detail.settlement.payout_date)} · ${detail.settlement.orders_count} pedidos`"
    wide
    @close="detail = null"
  >
    <div v-if="detailLoading && !report" class="fin-loading">Armando el informe…</div>
    <template v-else-if="report">
      <div class="fin-row">
        <span class="fin-badge" :class="report.control.reconciliation.status === 'ok' ? 'ok' : 'err'">
          {{ report.control.reconciliation.status === 'ok' ? 'Conciliada' : 'Con diferencias' }}
        </span>
        <span class="fin-badge" :class="report.control.without_sale ? 'warn' : 'ok'">
          {{ report.control.without_sale ? `${report.control.without_sale} pedidos sin venta en el POS` : 'Todos los pedidos tienen venta' }}
        </span>
        <span class="fin-spacer"></span>
        <button v-if="report.control.without_sale" class="fin-btn fin-btn-primary fin-btn-sm" type="button" @click="openSalesSync">Crear o vincular ventas</button>
        <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="printReport">Imprimir</button>
      </div>

      <div class="fin-grid-2" style="align-items: start">
        <div>
          <div class="fin-label">Ventas</div>
          <div class="fin-pairs">
            <div class="fin-pair"><span>Entregados / cancelados</span><strong class="fin-num">{{ report.sales.delivered_count }} / {{ report.sales.cancelled_count }}</strong></div>
            <div class="fin-pair"><span>Ventas brutas</span><strong class="fin-num">{{ formatBs(report.sales.gross_sales) }}</strong></div>
            <div class="fin-pair"><span>Ticket promedio</span><strong class="fin-num">{{ formatBs(report.sales.average_ticket) }}</strong></div>
            <div v-for="p in report.sales.by_payment" :key="p.method" class="fin-pair">
              <span>{{ PAYMENT_LABEL(p.method) }} ({{ p.count }})</span><strong class="fin-num">{{ formatBs(p.amount) }}</strong>
            </div>
          </div>
          <p v-if="report.sales.top_items.length" class="fin-hint" style="margin: 6px 0 0">
            Más vendidos: {{ report.sales.top_items.map((i) => `${i.quantity} ${i.name}`).join(' · ') }}
          </p>
        </div>
        <div>
          <div class="fin-label">Gastos PedidosYa</div>
          <div class="fin-pairs">
            <div class="fin-pair"><span>Comisión ({{ formatPercent(report.costs.commission_percent) }})</span><strong class="fin-num">{{ formatBs(report.costs.commission) }}</strong></div>
            <div class="fin-pair"><span>Publicidad ({{ formatPercent(report.costs.advertising_percent) }})</span><strong class="fin-num">{{ formatBs(report.costs.advertising) }}</strong></div>
            <div class="fin-pair"><span>IVA en comisiones</span><strong class="fin-num">{{ formatBs(report.costs.taxes) }}</strong></div>
            <div v-if="report.costs.operating_charges" class="fin-pair"><span>Cargos operativos</span><strong class="fin-num">{{ formatBs(report.costs.operating_charges) }}</strong></div>
            <div v-if="report.costs.delivery_fees" class="fin-pair"><span>Envíos a cargo del local</span><strong class="fin-num">{{ formatBs(report.costs.delivery_fees) }}</strong></div>
            <div class="fin-pair total"><span>Total ({{ formatPercent(report.costs.total_percent) }})</span><span class="fin-num">{{ formatBs(report.costs.total) }}</span></div>
          </div>
        </div>
        <div>
          <div class="fin-label">Descuentos y reintegros</div>
          <div class="fin-pairs">
            <div class="fin-pair"><span>Descuentos del local</span><strong class="fin-num">{{ formatBs(report.discounts.vendor) }}</strong></div>
            <div class="fin-pair"><span>Descuentos PedidosYa</span><strong class="fin-num">{{ formatBs(report.discounts.pedidosya) }}</strong></div>
            <div class="fin-pair"><span>Reintegros recibidos</span><strong class="fin-num">{{ formatBs(report.refunds.total, { sign: true }) }}</strong></div>
            <div class="fin-pair"><span>Pedidos cancelados ({{ report.refunds.cancelled_count }})</span><strong class="fin-num">{{ formatBs(report.refunds.cancelled_amount) }}</strong></div>
          </div>
        </div>
        <div>
          <div class="fin-label">Resultado</div>
          <div class="fin-pairs">
            <div class="fin-pair"><span>Ventas netas</span><strong class="fin-num">{{ formatBs(report.result.net_sales) }}</strong></div>
            <div class="fin-pair"><span>Total liquidado</span><strong class="fin-num">{{ formatBs(report.result.total_payout) }}</strong></div>
            <div class="fin-pair total"><span>Se queda PedidosYa ({{ formatPercent(report.result.retained_percent) }})</span><span class="fin-num">{{ formatBs(report.result.retained_amount) }}</span></div>
            <template v-if="report.result.product_cost !== null">
              <div class="fin-pair"><span>Costo de productos ({{ formatPercent(report.result.food_cost_percent) }} del mes)</span><strong class="fin-num">{{ formatBs(-report.result.product_cost) }}</strong></div>
              <div class="fin-pair total"><span>Ganancia estimada</span><span class="fin-num" :class="{ neg: report.result.estimated_profit < 0 }">{{ formatBs(report.result.estimated_profit) }}</span></div>
            </template>
            <p v-else class="fin-hint" style="margin: 6px 0 0">Registra las compras del mes para estimar el costo de los productos y la ganancia.</p>
          </div>
        </div>
      </div>

      <div class="fin-checks">
        <div v-for="c in report.control.reconciliation.checks" :key="c.key" class="fin-check">
          <span class="fin-check-label">
            {{ c.label }}
            <span class="fin-muted fin-small"> · esperado {{ formatBs(c.expected) }}</span>
          </span>
          <span v-if="c.ok" class="fin-badge ok">Cuadra</span>
          <span v-else class="fin-badge" :class="c.informative ? 'info' : 'err'">{{ c.informative ? 'Aviso' : 'Diferencia' }} {{ formatBs(c.difference, { sign: true }) }}</span>
        </div>
      </div>

      <div v-if="report.control.price_differences.length" class="fin-note">
        {{ report.control.price_differences.length }} pedido(s) cobrados a otro precio que el Tarifario:
        {{ report.control.price_differences.map((d) => `${d.order_number} (${formatBs(d.difference, { sign: true })})`).join(', ') }}.
        Revisa los precios en Menú → Tarifario PedidosYa.
      </div>

      <div class="fin-row">
        <div class="fin-chips" role="group" aria-label="Estado del pedido">
          <button class="fin-chip" :class="{ active: !statusFilter }" type="button" @click="statusFilter = ''">Todos ({{ report.orders.length }})</button>
          <button class="fin-chip" :class="{ active: statusFilter === 'Entregado' }" type="button" @click="statusFilter = 'Entregado'">Entregados</button>
          <button class="fin-chip" :class="{ active: statusFilter === 'Cancelado' }" type="button" @click="statusFilter = 'Cancelado'">Cancelados</button>
        </div>
      </div>

      <div class="fin-table-wrap">
        <table class="fin-table stack">
          <thead>
            <tr>
              <th>Pedido</th>
              <th>Artículos</th>
              <th class="right">Total</th>
              <th class="right">Comisión</th>
              <th>Venta</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="o in filteredOrders" :key="o.id">
              <td data-label="Pedido">
                <span class="fin-cell-main fin-num">{{ formatDay(o.order_date, { year: false }) }} {{ hour(o.order_date) }}</span>
                <div class="fin-cell-sub">
                  Nº {{ o.order_number }}
                  <span class="fin-badge" :class="o.status === 'Entregado' ? 'ok' : o.status === 'Cancelado' ? 'err' : ''">{{ o.status }}</span>
                </div>
              </td>
              <td data-label="Artículos" class="fin-small">
                {{ o.items_text || '—' }}
                <div class="fin-cell-sub">{{ o.payment_method }}</div>
              </td>
              <td data-label="Total" class="right fin-num">
                {{ formatBs(o.subtotal) }}
                <div v-if="o.catalog_total !== null && Number(o.catalog_total) !== Number(o.subtotal)" class="fin-cell-sub">Tarifario {{ formatBs(o.catalog_total) }}</div>
              </td>
              <td data-label="Comisión" class="right fin-num">{{ formatBs(o.commission) }}</td>
              <td data-label="Venta">
                <template v-if="o.sale">
                  #{{ o.sale.order_number }}
                  <div class="fin-cell-sub">{{ o.sale_link === 'created' ? 'creada al importar' : 'vinculada' }}</div>
                </template>
                <span v-else-if="o.status === 'Entregado'" class="fin-badge warn">Sin venta</span>
                <span v-else class="fin-muted">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </FinanceModal>

  <!-- Crear o vincular ventas de una semana ya cargada -->
  <FinanceModal
    v-if="salesSync"
    title="Ventas de la semana en el POS"
    :subtitle="`Semana ${formatDay(salesSync.settlement.start_date, { year: false })} – ${formatDay(salesSync.settlement.end_date)}`"
    wide
    :busy="syncing"
    @close="salesSync = null"
  >
    <div v-if="!salesSync.analysis" class="fin-loading">Revisando los pedidos…</div>
    <PedidosYaSalesPanel
      v-else
      v-model:links="salesLinks"
      :analysis="salesSync.analysis"
      @mappings-saved="loadSalesAnalysis"
    />
    <template #footer>
      <button class="fin-btn fin-btn-secondary" type="button" :disabled="syncing" @click="salesSync = null">Cancelar</button>
      <button class="fin-btn fin-btn-primary" type="button" :disabled="syncing || !salesSync.analysis || syncBlocked" @click="runSync">
        {{ syncing ? 'Registrando…' : 'Crear o vincular ventas' }}
      </button>
    </template>
  </FinanceModal>
</template>
