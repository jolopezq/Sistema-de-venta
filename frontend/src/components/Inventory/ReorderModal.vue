<script setup>
import { computed, onMounted, ref } from 'vue';
import { apiFetch } from '../../services/api';
import { useReorderStore } from '../../stores/reorder';
import { formatBs, errorText } from '../../stores/finance';
import { formatQty, REORDER_STATUS, REORDER_BASIS, shoppingListText, whatsappLink } from '../../utils/inventoryLabels';
import FinanceModal from '../Finance/FinanceModal.vue';
import '../Finance/finance.css';

/**
 * Compras sugeridas (plan de inventario, F4): lo que conviene pedir hoy según el consumo y el punto de reorden de
 * cada insumo, agrupado por proveedor. Las cantidades se pueden ajustar; la lista se copia, se imprime, se manda
 * por WhatsApp o se convierte en una Compra cuando llega la mercadería. Nada se guarda aquí.
 */
const emit = defineEmits(['close', 'purchase', 'loaded']);
const reorder = useReorderStore();

const loading = ref(true);
const error = ref('');
const report = ref(null);
const rows = ref([]);
const toast = ref('');
let toastTimer = null;

function packText(item) {
  const differs = (item.purchase_unit || '').toLowerCase() !== (item.unit || '').toLowerCase();
  return differs && item.purchase_factor !== 1 ? `de ${formatQty(item.purchase_factor)} ${item.unit}` : '';
}

function buildRows(items) {
  return items
    .filter((i) => ['out', 'critical', 'reorder'].includes(i.status) || (i.status === 'daily' && i.suggested?.units > 0))
    .map((i) => ({
      ...i,
      key: i.id,
      daily: i.status === 'daily',
      checked: i.status !== 'daily',
      units: i.suggested?.units ?? 0,
      unit_label: i.purchase_unit || i.unit,
      pack: packText(i),
    }));
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    report.value = await apiFetch('/inventory/reorder');
    rows.value = buildRows(report.value.items);
    reorder.setFromReport(report.value);
    emit('loaded', report.value);
  } catch (err) {
    error.value = errorText(err, 'No se pudo calcular la lista de compras. Revisa la conexión.');
  } finally {
    loading.value = false;
  }
}
onMounted(load);

function estimate(row) {
  return (Number(row.units) || 0) * (row.purchase_factor || 1) * (row.unit_cost || 0);
}

const groups = computed(() => {
  const map = new Map();
  for (const row of rows.value) {
    const key = row.daily ? 'daily' : (row.supplier?.id ?? 'none');
    if (!map.has(key)) {
      map.set(key, {
        key,
        daily: row.daily,
        supplier: row.daily ? null : row.supplier,
        title: row.daily ? 'Compra diaria' : (row.supplier?.name || 'Sin proveedor fijo'),
        rows: [],
      });
    }
    map.get(key).rows.push(row);
  }
  const list = [...map.values()];
  const rank = (g) => (g.daily ? 2 : g.supplier ? 0 : 1);
  return list.sort((a, b) => rank(a) - rank(b) || a.title.localeCompare(b.title, 'es'));
});

function chosen(group) {
  return group.rows.filter((r) => r.checked && Number(r.units) > 0);
}

function groupTotal(group) {
  return chosen(group).reduce((sum, r) => sum + estimate(r), 0);
}

const grandTotal = computed(() => groups.value.reduce((sum, g) => sum + groupTotal(g), 0));
const chosenCount = computed(() => groups.value.reduce((sum, g) => sum + chosen(g).length, 0));

const estimated = computed(() => rows.value.filter((r) => r.basis === 'estimate' || r.basis === 'partial' || r.basis === 'manual').length);
const noData = computed(() => report.value?.summary.no_data ?? 0);

function reset(row) {
  row.units = row.suggested?.units ?? 0;
}

function textFor(group) {
  return shoppingListText(group.daily ? 'compra diaria' : group.supplier?.name, chosen(group));
}

function allText() {
  return groups.value
    .filter((g) => chosen(g).length)
    .map((g) => `${g.daily ? 'Compra diaria' : g.title}\n${shoppingListText(null, chosen(g), { heading: false })}`)
    .join('\n\n');
}

function say(message) {
  toast.value = message;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { toast.value = ''; }, 3500);
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text);
  } catch (e) {
    // Sin permiso del portapapeles (conexión no segura): se copia con un campo temporal
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    document.execCommand('copy');
    document.body.removeChild(area);
  }
  say('Lista copiada.');
}

function whatsapp(group) {
  window.open(whatsappLink(group.supplier?.phone, textFor(group)), '_blank', 'noopener');
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** Imprime desde un marco oculto: no depende de ventanas emergentes ni de los estilos de la pantalla. */
function print() {
  const today = new Date().toLocaleDateString('es-BO', { day: '2-digit', month: 'long', year: 'numeric' });
  const body = groups.value.filter((g) => chosen(g).length).map((g) => {
    const lines = chosen(g).map((r) => `<tr><td>${escapeHtml(r.name)}</td><td class="n">${escapeHtml(formatQty(r.units))} ${escapeHtml(r.unit_label)} ${escapeHtml(r.pack)}</td><td class="n">${escapeHtml(formatBs(estimate(r)))}</td></tr>`).join('');
    const meta = g.supplier ? `<p class="m">${g.supplier.delivery_days} ${g.supplier.delivery_days === 1 ? 'día' : 'días'} de entrega${g.supplier.order_days ? ` · pedidos: ${escapeHtml(g.supplier.order_days)}` : ''}${g.supplier.phone ? ` · ${escapeHtml(g.supplier.phone)}` : ''}</p>` : '';
    return `<h2>${escapeHtml(g.title)}</h2>${meta}<table><tbody>${lines}</tbody><tfoot><tr><td colspan="2">Estimado</td><td class="n">${escapeHtml(formatBs(groupTotal(g)))}</td></tr></tfoot></table>`;
  }).join('');
  const html = `<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Compras sugeridas</title><style>
    body{font:13px/1.45 system-ui,sans-serif;color:#111;margin:24px}h1{font-size:18px;margin:0 0 2px}.s{color:#555;margin:0 0 18px}
    h2{font-size:14px;margin:18px 0 2px}.m{margin:0 0 6px;color:#555;font-size:12px}table{width:100%;border-collapse:collapse}
    td{padding:5px 6px;border-bottom:1px solid #ccc}.n{text-align:right;white-space:nowrap}tfoot td{font-weight:700;border-bottom:none}
  </style></head><body><h1>Compras sugeridas — Ohana</h1><p class="s">${escapeHtml(today)}</p>${body || '<p>No hay nada para pedir.</p>'}</body></html>`;

  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
  document.body.appendChild(frame);
  frame.contentDocument.open();
  frame.contentDocument.write(html);
  frame.contentDocument.close();
  setTimeout(() => {
    frame.contentWindow.focus();
    frame.contentWindow.print();
    setTimeout(() => document.body.removeChild(frame), 2000);
  }, 150);
}

function toPurchase(group) {
  emit('purchase', {
    supplier: group.supplier?.name || '',
    lines: chosen(group).map((r) => ({ ingredient_id: r.id, quantity: Number(r.units) })),
  });
}
</script>

<template>
  <div class="fin-page rm-host">
    <FinanceModal wide title="Compras sugeridas" subtitle="Lo que conviene pedir según el consumo y el punto de reorden de cada insumo." @close="emit('close')">
      <div v-if="loading" class="fin-loading">Calculando…</div>
      <div v-else-if="error" class="fin-note err" role="alert">{{ error }}</div>
      <template v-else>
        <div class="fin-kpis rm-kpis">
          <div class="fin-card fin-kpi">
            <span class="fin-kpi-label">Por reponer</span>
            <span class="fin-kpi-value">{{ report.summary.to_reorder }}</span>
            <span class="fin-kpi-sub">{{ report.summary.critical }} {{ report.summary.critical === 1 ? 'crítico' : 'críticos' }} · {{ report.summary.out }} {{ report.summary.out === 1 ? 'agotado' : 'agotados' }}</span>
          </div>
          <div class="fin-card fin-kpi">
            <span class="fin-kpi-label">Estimado de la lista</span>
            <span class="fin-kpi-value">{{ formatBs(grandTotal) }}</span>
            <span class="fin-kpi-sub">{{ chosenCount }} {{ chosenCount === 1 ? 'insumo elegido' : 'insumos elegidos' }} · al costo promedio</span>
          </div>
        </div>

        <div v-if="estimated" class="fin-note info">
          {{ estimated }} {{ estimated === 1 ? 'insumo usa' : 'insumos usan' }} una estimación o pocos datos (aún sin 4 semanas de ventas): revisa esas cantidades antes de pedir.
        </div>
        <div v-if="noData" class="fin-note">
          {{ noData }} {{ noData === 1 ? 'insumo no tiene' : 'insumos no tienen' }} datos para calcular su reposición. Completa su consumo diario estimado o un punto de reorden manual en «Editar».
        </div>

        <p v-if="!groups.length" class="fin-empty">No hay nada que pedir por ahora: todo lo controlado está sobre su punto de reorden.</p>

        <section v-for="g in groups" :key="g.key" class="rm-group">
          <header class="rm-group-head">
            <div>
              <h3>{{ g.title }}</h3>
              <p v-if="g.supplier" class="fin-small fin-muted">
                Llega en {{ g.supplier.delivery_days }} {{ g.supplier.delivery_days === 1 ? 'día' : 'días' }}<template v-if="g.supplier.order_days"> · pedidos: {{ g.supplier.order_days }}</template><template v-if="g.supplier.phone"> · {{ g.supplier.phone }}</template>
              </p>
              <p v-else-if="g.daily" class="fin-small fin-muted">Se compra el mismo día: cantidad para cubrir hoy.</p>
              <p v-else class="fin-small fin-muted">Asigna un proveedor al insumo para agruparlo y mandar el pedido.</p>
            </div>
            <div class="rm-group-actions">
              <button class="fin-btn fin-btn-secondary fin-btn-sm" type="button" :disabled="!chosen(g).length" @click="copy(textFor(g))">Copiar</button>
              <button v-if="g.supplier?.phone" class="fin-btn fin-btn-secondary fin-btn-sm" type="button" :disabled="!chosen(g).length" @click="whatsapp(g)">WhatsApp</button>
              <button class="fin-btn fin-btn-primary fin-btn-sm" type="button" :disabled="!chosen(g).length" @click="toPurchase(g)">Registrar compra</button>
            </div>
          </header>

          <div class="fin-table-wrap"><table class="fin-table stack">
            <thead><tr><th class="rm-check"><span class="sr-only">Pedir</span></th><th>Insumo</th><th>Hay</th><th>Pedir</th><th class="right">Estimado</th></tr></thead>
            <tbody>
              <tr v-for="r in g.rows" :key="r.key">
                <td class="rm-check" data-label="Pedir"><input v-model="r.checked" type="checkbox" :aria-label="`Pedir ${r.name}`" /></td>
                <td data-label="Insumo">
                  <span class="fin-cell-main">{{ r.name }}</span>
                  <span class="fin-badge" :class="REORDER_STATUS[r.status].tone" :title="REORDER_STATUS[r.status].hint">{{ REORDER_STATUS[r.status].label }}</span>
                  <div class="fin-small fin-muted">
                    {{ REORDER_BASIS[r.basis] }}<template v-if="r.daily_use > 0"> · gasta {{ formatQty(r.daily_use, 2) }} {{ r.unit }}/día</template><template v-if="r.suggested?.limited_by_shelf_life"> · limitado por su vida útil</template>
                  </div>
                </td>
                <td data-label="Hay">
                  <span class="fin-num">{{ formatQty(r.stock) }} {{ r.unit }}</span>
                  <div class="fin-small fin-muted"><template v-if="r.days_of_stock !== null">alcanza {{ formatQty(r.days_of_stock, 1) }} {{ r.days_of_stock === 1 ? 'día' : 'días' }}</template><template v-if="r.reorder_point > 0"> · reorden en {{ formatQty(r.reorder_point, 2) }}</template></div>
                </td>
                <td data-label="Pedir">
                  <div class="rm-qty">
                    <input v-model.number="r.units" class="fin-input fin-input-sm" type="number" min="0" step="any" inputmode="decimal" :aria-label="`Cantidad de ${r.name}`" />
                    <span class="rm-unit">{{ r.unit_label }}{{ r.pack ? ' ' + r.pack : '' }}</span>
                    <button v-if="Number(r.units) !== r.suggested?.units" class="fin-btn-link" type="button" @click="reset(r)">sugerido: {{ formatQty(r.suggested?.units ?? 0) }}</button>
                  </div>
                </td>
                <td class="right fin-num" data-label="Estimado">{{ formatBs(estimate(r)) }}</td>
              </tr>
            </tbody>
          </table></div>
          <p class="rm-subtotal fin-small fin-muted">Subtotal estimado: <strong class="fin-num">{{ formatBs(groupTotal(g)) }}</strong></p>
        </section>

        <p v-if="report.summary.expiring" class="fin-note">
          {{ report.summary.expiring }} {{ report.summary.expiring === 1 ? 'insumo vence' : 'insumos vencen' }} pronto o ya {{ report.summary.expiring === 1 ? 'venció' : 'vencieron' }}: se ve en la lista de Inventario. No lo compres de más.
        </p>
      </template>

      <div v-if="toast" class="fin-toast" role="status">{{ toast }}</div>

      <template #footer>
        <button class="fin-btn fin-btn-ghost" type="button" :disabled="loading" @click="load">Recalcular</button>
        <span class="fin-spacer"></span>
        <button class="fin-btn fin-btn-secondary" type="button" :disabled="!chosenCount" @click="print">Imprimir</button>
        <button class="fin-btn fin-btn-secondary" type="button" :disabled="!chosenCount" @click="copy(allText())">Copiar todo</button>
        <button class="fin-btn fin-btn-primary" type="button" @click="emit('close')">Cerrar</button>
      </template>
    </FinanceModal>
  </div>
</template>

<style scoped>
.rm-host { display: contents; }
.rm-kpis { grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); margin: 0; }
.rm-group { display: flex; flex-direction: column; gap: 6px; }
.rm-group-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.rm-group-head h3 { margin: 0; font-size: 15px; color: var(--ink-900); }
.rm-group-head p { margin: 2px 0 0; }
.rm-group-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.rm-check { width: 36px; }
.rm-check input { width: 17px; height: 17px; accent-color: var(--acai-500); }
.rm-qty { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.rm-qty .fin-input { width: 84px; }
.rm-unit { font-size: 12.5px; color: var(--ink-700); }
.rm-subtotal { margin: 0; text-align: right; }
.rm-group .fin-badge { margin-left: 6px; }
@media (max-width: 640px) {
  .rm-group-actions { width: 100%; }
}
</style>
