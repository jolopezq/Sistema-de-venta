<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { apiFetch } from '../../services/api';
import { formatBs, formatDay, errorText } from '../../stores/finance';

/**
 * Ventas que la importación crea o vincula (plan de Finanzas §6.2–6.4): equivalencias de nombres pendientes,
 * pedido por pedido con su acción, y elección de la venta del POS cuando hay que vincular.
 * `links` es [order_number → sale_id | 'new'] y se envía al importar o al sincronizar.
 */
const props = defineProps({
  analysis: { type: Object, required: true },
  links: { type: Object, required: true },
});
const emit = defineEmits(['update:links', 'mappings-saved']);

// ---------- Equivalencias pendientes ----------
const choice = reactive({ products: {}, options: {} });
const savingMappings = ref(false);
const mappingError = ref('');

watch(() => props.analysis, (a) => {
  for (const p of a?.unresolved?.products || []) {
    if (!(p.name in choice.products)) choice.products[p.name] = p.suggestions?.[0]?.id ?? '';
  }
  for (const o of a?.unresolved?.options || []) {
    if (!(o.name in choice.options)) choice.options[o.name] = '';
  }
}, { immediate: true });

const unresolvedCount = computed(() => (props.analysis?.unresolved?.products?.length || 0) + (props.analysis?.unresolved?.options?.length || 0));
const mappingsReady = computed(() => (props.analysis?.unresolved?.products || []).every((p) => choice.products[p.name] !== '')
  && (props.analysis?.unresolved?.options || []).every((o) => choice.options[o.name] !== ''));

async function saveMappings() {
  savingMappings.value = true;
  mappingError.value = '';
  try {
    const mappings = [
      ...(props.analysis.unresolved.products || []).map((p) => ({ entity_type: 'product', name: p.name, internal_id: Number(choice.products[p.name]) })),
      ...(props.analysis.unresolved.options || []).map((o) => ({ entity_type: 'option', name: o.name, internal_id: Number(choice.options[o.name]) })),
    ];
    await apiFetch('/finance/pedidosya-mappings', { method: 'POST', body: JSON.stringify({ mappings }) });
    emit('mappings-saved');
  } catch (err) {
    mappingError.value = errorText(err, 'No se pudieron guardar las equivalencias.');
  } finally {
    savingMappings.value = false;
  }
}

// ---------- Pedido por pedido ----------
const posSales = computed(() => props.analysis?.pos_sales || []);

function defaultValue(row) {
  return row.action === 'link' ? row.sale?.id : 'new';
}
function valueOf(row) {
  return props.links[row.order_number] ?? defaultValue(row);
}
function setLink(row, value) {
  emit('update:links', { ...props.links, [row.order_number]: value });
}

/** Ventas del POS elegibles para un pedido: las que no eligió otro pedido. */
function optionsFor(row) {
  const takenByOthers = new Set(
    (props.analysis?.orders || [])
      .filter((r) => r.order_number !== row.order_number && ['link', 'create'].includes(r.action))
      .map((r) => valueOf(r))
      .filter((v) => v && v !== 'new'),
  );
  const candidates = new Set((row.candidates || []).map((c) => c.id));
  return posSales.value
    .filter((s) => !takenByOthers.has(s.id))
    .sort((a, b) => (candidates.has(b.id) ? 1 : 0) - (candidates.has(a.id) ? 1 : 0));
}

function effectiveAction(row) {
  if (!['link', 'create'].includes(row.action)) return row.action;
  return valueOf(row) === 'new' ? 'create' : 'link';
}

const summary = computed(() => {
  const counts = { create: 0, link: 0, linked: 0, cancelled: 0, error: 0 };
  for (const row of props.analysis?.orders || []) counts[effectiveAction(row)] = (counts[effectiveAction(row)] || 0) + 1;
  return counts;
});

const ACTION = {
  create: { label: 'Se crea la venta', kind: 'acai' },
  link: { label: 'Se vincula', kind: 'info' },
  linked: { label: 'Ya registrada', kind: 'ok' },
  cancelled: { label: 'Cancelado · sin venta', kind: '' },
  error: { label: 'No se puede crear', kind: 'err' },
};

function difference(row) {
  if (row.catalog_total === null || row.catalog_total === undefined) return null;
  const d = Math.round((row.total - row.catalog_total) * 100) / 100;
  return Math.abs(d) > 0.01 ? d : 0;
}
function hour(value) {
  return String(value || '').slice(11, 16);
}
function saleLabel(s) {
  return `#${s.order_number} · ${formatDay(s.created_at, { year: false })} ${hour(s.created_at)} · ${formatBs(s.total)}`;
}
</script>

<template>
  <div class="fin-sales-panel">
    <div class="fin-chips">
      <span class="fin-badge acai">{{ summary.create }} nuevas</span>
      <span class="fin-badge info">{{ summary.link }} a vincular</span>
      <span v-if="summary.linked" class="fin-badge ok">{{ summary.linked }} ya registradas</span>
      <span class="fin-badge">{{ summary.cancelled }} canceladas</span>
      <span v-if="summary.error" class="fin-badge err">{{ summary.error }} con error</span>
      <span v-if="analysis.summary?.price_differences" class="fin-badge warn">{{ analysis.summary.price_differences }} con otro precio que el Tarifario</span>
    </div>

    <!-- Nombres sin equivalencia -->
    <div v-if="unresolvedCount" class="fin-mapping-box">
      <div class="fin-label">Artículos de PedidosYa sin equivalencia en el POS</div>
      <p class="fin-hint">Se guardan para las próximas semanas. Una opción sin equivalencia se anota en la venta sin descontar inventario.</p>
      <div v-for="p in analysis.unresolved.products" :key="`p-${p.name}`" class="fin-mapping-row">
        <span class="fin-mapping-name">{{ p.name }} <span class="fin-muted fin-small">· {{ p.orders }} pedido(s)</span></span>
        <select v-model="choice.products[p.name]" class="fin-input fin-input-sm" :aria-label="`Producto para ${p.name}`">
          <option value="" disabled>Elegir producto…</option>
          <optgroup v-if="p.suggestions?.length" label="Parecidos">
            <option v-for="s in p.suggestions" :key="`s-${s.id}`" :value="s.id">{{ s.name }} · {{ formatBs(s.price) }}</option>
          </optgroup>
          <optgroup label="Todos los productos">
            <option v-for="prod in analysis.products" :key="prod.id" :value="prod.id">{{ prod.name }} · {{ formatBs(prod.price) }}</option>
          </optgroup>
        </select>
      </div>
      <div v-for="o in analysis.unresolved.options" :key="`o-${o.name}`" class="fin-mapping-row">
        <span class="fin-mapping-name">{{ o.name }} <span class="fin-muted fin-small">· opción · {{ o.orders }} pedido(s)</span></span>
        <select v-model="choice.options[o.name]" class="fin-input fin-input-sm" :aria-label="`Opción para ${o.name}`">
          <option value="" disabled>Elegir opción…</option>
          <option :value="0">Sin equivalencia (solo anotar en la venta)</option>
          <option v-for="c in o.choices" :key="c.id" :value="c.id">{{ c.name }} · {{ c.group }}</option>
        </select>
      </div>
      <div v-if="mappingError" class="fin-note err" role="alert">{{ mappingError }}</div>
      <div class="fin-row">
        <span class="fin-spacer"></span>
        <button class="fin-btn fin-btn-secondary fin-btn-sm" type="button" :disabled="!mappingsReady || savingMappings" @click="saveMappings">
          {{ savingMappings ? 'Guardando…' : 'Guardar equivalencias' }}
        </button>
      </div>
    </div>

    <!-- Pedidos -->
    <div class="fin-table-wrap fin-sales-orders">
      <table class="fin-table stack">
        <thead>
          <tr>
            <th>Pedido</th>
            <th>Artículos</th>
            <th class="right">Total</th>
            <th>En el POS</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in analysis.orders" :key="row.order_number">
            <td data-label="Pedido">
              <span class="fin-cell-main fin-num">{{ formatDay(row.order_date, { year: false }) }} {{ hour(row.order_date) }}</span>
              <div class="fin-cell-sub">Nº {{ row.order_number }}</div>
            </td>
            <td data-label="Artículos" class="fin-small">
              {{ row.items_text }}
              <div v-if="row.notes?.length" class="fin-cell-sub">{{ row.notes.join(' ') }}</div>
              <div v-for="e in row.errors" :key="e" class="fin-cell-sub neg">{{ e }}</div>
            </td>
            <td data-label="Total" class="right fin-num">
              {{ formatBs(row.total) }}
              <div v-if="difference(row)" class="fin-cell-sub">Tarifario {{ formatBs(row.catalog_total) }}</div>
            </td>
            <td data-label="En el POS">
              <template v-if="['link', 'create'].includes(row.action)">
                <select
                  class="fin-input fin-input-sm"
                  :value="valueOf(row)"
                  :aria-label="`Venta para el pedido ${row.order_number}`"
                  @change="setLink(row, $event.target.value)"
                >
                  <option value="new">Crear venta nueva</option>
                  <option v-for="s in optionsFor(row)" :key="s.id" :value="s.id">Vincular {{ saleLabel(s) }}</option>
                </select>
                <div v-if="row.unresolved" class="fin-cell-sub">Falta la equivalencia de un artículo</div>
              </template>
              <template v-else>
                <span class="fin-badge" :class="ACTION[row.action]?.kind">{{ ACTION[row.action]?.label }}</span>
                <div v-if="row.sale" class="fin-cell-sub">Venta #{{ row.sale.order_number }}</div>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.fin-sales-panel { display: flex; flex-direction: column; gap: 12px; }
.fin-mapping-box {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px;
  border-radius: 12px;
  border: 1px solid var(--border);
  background: var(--cream-50);
}
.fin-mapping-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 10px; align-items: center; }
.fin-mapping-name { font-size: 13px; font-weight: 600; color: var(--ink-900); }
.fin-sales-orders { border: 1px solid var(--border); border-radius: 12px; max-height: 360px; overflow-y: auto; }
@media (max-width: 760px) {
  .fin-mapping-row { grid-template-columns: 1fr; gap: 4px; }
  .fin-sales-orders { max-height: none; }
}
</style>
