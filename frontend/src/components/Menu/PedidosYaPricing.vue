<script setup>
import { ref, computed, watch } from 'vue';
import { apiFetch } from '../../services/api.js';

/**
 * Tarifario PedidosYa: edita en una sola pantalla los precios de plataforma de productos y
 * opciones (tamaños, toppings) y la disponibilidad de cada producto en PedidosYa.
 * Vacío = se cobra el precio de mostrador (misma regla que utils/channelPricing.js).
 * Nunca toca el precio de mostrador.
 */
const props = defineProps({
  products: { type: Array, default: () => [] },
  optionGroups: { type: Array, default: () => [] },
  categories: { type: Array, default: () => [] },
});
const emit = defineEmits(['saved', 'alert']);

const productDrafts = ref({});
const optionDrafts = ref({});
const showInactive = ref(false);
const showFreeOptions = ref(false);
const bulkPercent = ref(25);
const bulkScope = ref('empty'); // 'empty' | 'all'
const isSaving = ref(false);

// ── Normalización: '' / null / 0 = sin precio PedidosYa (usa el de mostrador) ──
const toPrice = (v) => {
  if (v === '' || v === null || v === undefined) return null;
  const n = Math.round(Number(v) * 100) / 100;
  return Number.isFinite(n) && n > 0 ? n : null;
};
const fmt = (v) => Number(v || 0).toFixed(2);
const roundHalf = (v) => Math.round(v * 2) / 2;

function resetDrafts() {
  const pd = {};
  for (const p of props.products) {
    pd[p.id] = {
      delivery_price: toPrice(p.delivery_price) === null ? '' : fmt(p.delivery_price),
      available: p.available_on_pedidosya !== false,
    };
  }
  const od = {};
  for (const og of props.optionGroups) {
    if (!og.pedidosya_pricing) continue;
    for (const o of og.options || []) {
      od[o.id] = { delivery_price: toPrice(o.delivery_price) === null ? '' : fmt(o.delivery_price) };
    }
  }
  productDrafts.value = pd;
  optionDrafts.value = od;
}
watch(() => [props.products, props.optionGroups], resetDrafts, { immediate: true });

// ── Filas ──
const productSections = computed(() => {
  const visible = props.products.filter(p => showInactive.value || p.is_active !== false);
  const byCategory = props.categories
    .map(cat => ({ id: cat.id, name: cat.name, items: visible.filter(p => p.category_id === cat.id) }))
    .filter(sec => sec.items.length > 0);
  const known = new Set(props.categories.map(c => c.id));
  const orphans = visible.filter(p => !known.has(p.category_id));
  if (orphans.length) byCategory.push({ id: 'otros', name: 'Sin sección', items: orphans });
  return byCategory;
});

const optionSections = computed(() =>
  props.optionGroups
    .filter(og => Boolean(og.pedidosya_pricing))
    .map(og => ({
      id: og.id,
      name: og.name,
      items: (og.options || []).filter(o =>
        showFreeOptions.value || Number(o.additional_price) > 0 || toPrice(o.delivery_price) !== null
      ),
    }))
    .filter(sec => sec.items.length > 0)
);

// ── Cambios pendientes ──
const changedProducts = computed(() =>
  props.products.filter(p => {
    const d = productDrafts.value[p.id];
    if (!d) return false;
    return toPrice(d.delivery_price) !== toPrice(p.delivery_price)
      || d.available !== (p.available_on_pedidosya !== false);
  })
);
const changedOptions = computed(() =>
  props.optionGroups
    .filter(og => Boolean(og.pedidosya_pricing))
    .flatMap(og => og.options || [])
    .filter(o => {
      const d = optionDrafts.value[o.id];
      return d && toPrice(d.delivery_price) !== toPrice(o.delivery_price);
    })
);
const changesCount = computed(() => changedProducts.value.length + changedOptions.value.length);
const isProductChanged = (p) => changedProducts.value.some(c => c.id === p.id);
const isOptionChanged = (o) => changedOptions.value.some(c => c.id === o.id);

const invalidPrices = computed(() => {
  const bad = (v) => v !== '' && v !== null && (!Number.isFinite(Number(v)) || Number(v) < 0);
  return Object.values(productDrafts.value).some(d => bad(d.delivery_price))
    || Object.values(optionDrafts.value).some(d => bad(d.delivery_price));
});

// ── Resumen ──
const missingCount = computed(() =>
  props.products.filter(p =>
    p.is_active !== false && !p.is_weight_based && productDrafts.value[p.id]?.available
    && toPrice(productDrafts.value[p.id]?.delivery_price) === null
  ).length
);

function diffLabel(local, draft) {
  const pya = toPrice(draft);
  const base = Number(local) || 0;
  if (pya === null || base <= 0) return '';
  const pct = Math.round(((pya - base) / base) * 100);
  return pct === 0 ? '=' : `${pct > 0 ? '+' : ''}${pct}%`;
}

// ── Aplicar +X % (vista previa: solo llena los campos, no guarda) ──
function applyPercent() {
  const pct = Number(bulkPercent.value);
  if (!Number.isFinite(pct) || pct < -90 || pct > 300) {
    emit('alert', 'Ingresa un porcentaje entre -90 y 300.');
    return;
  }
  const onlyEmpty = bulkScope.value === 'empty';
  for (const sec of productSections.value) {
    for (const p of sec.items) {
      const d = productDrafts.value[p.id];
      if (!d || p.is_weight_based || Number(p.price) <= 0) continue;
      if (onlyEmpty && toPrice(d.delivery_price) !== null) continue;
      d.delivery_price = fmt(roundHalf(Number(p.price) * (1 + pct / 100)));
    }
  }
  for (const sec of optionSections.value) {
    for (const o of sec.items) {
      const d = optionDrafts.value[o.id];
      if (!d || Number(o.additional_price) <= 0) continue;
      if (onlyEmpty && toPrice(d.delivery_price) !== null) continue;
      d.delivery_price = fmt(roundHalf(Number(o.additional_price) * (1 + pct / 100)));
    }
  }
}

async function save() {
  if (changesCount.value === 0 || isSaving.value) return;
  if (invalidPrices.value) {
    emit('alert', 'Hay precios inválidos: corrige los campos marcados.');
    return;
  }
  isSaving.value = true;
  try {
    const body = {
      products: changedProducts.value.map(p => ({
        id: p.id,
        delivery_price: toPrice(productDrafts.value[p.id].delivery_price),
        available_on_pedidosya: productDrafts.value[p.id].available,
      })),
      options: changedOptions.value.map(o => ({
        id: o.id,
        delivery_price: toPrice(optionDrafts.value[o.id].delivery_price),
      })),
    };
    const res = await apiFetch('/admin/pricing/pedidosya', { method: 'PUT', body: JSON.stringify(body) });
    emit('alert', `Tarifario guardado: ${res.updated_products} productos y ${res.updated_options} opciones actualizados.`);
    emit('saved');
  } catch (error) {
    emit('alert', 'No se pudo guardar el tarifario: ' + (error.message || error));
  } finally {
    isSaving.value = false;
  }
}
</script>

<template>
  <div class="tariff-view">
    <div class="tariff-header">
      <div>
        <h2>Tarifario PedidosYa</h2>
        <p class="tariff-sub">
          Precios que se cobran en los pedidos PedidosYa. Vacío = se cobra el precio de mostrador.
          El precio de mostrador no cambia desde aquí.
        </p>
      </div>
      <div class="tariff-summary" :class="{ ok: missingCount === 0 }">
        {{ missingCount === 0
          ? 'Todos los productos disponibles tienen precio PedidosYa'
          : `${missingCount} ${missingCount === 1 ? 'producto disponible usa' : 'productos disponibles usan'} el precio local` }}
      </div>
    </div>

    <!-- Herramienta de porcentaje -->
    <div class="tariff-tools">
      <label class="tool-field">
        <span>Aplicar</span>
        <input v-model.number="bulkPercent" type="number" step="1" class="tool-input" aria-label="Porcentaje sobre el precio de mostrador" />
        <span>% sobre el precio de mostrador a</span>
      </label>
      <select v-model="bulkScope" class="tool-select" aria-label="A qué precios aplicar">
        <option value="empty">solo los vacíos</option>
        <option value="all">todos</option>
      </select>
      <button type="button" class="btn-tool" @click="applyPercent">Calcular</button>
      <span class="tool-hint">Redondea a 0,50. Revisa la vista previa y guarda.</span>
    </div>

    <div class="tariff-filters">
      <label><input v-model="showInactive" type="checkbox" /> Incluir productos inactivos</label>
      <label><input v-model="showFreeOptions" type="checkbox" /> Mostrar opciones sin costo</label>
    </div>

    <!-- Productos -->
    <section class="tariff-card">
      <h3 class="tariff-card-title">Productos</h3>
      <div class="table-scroll">
        <table class="tariff-table">
          <thead>
            <tr>
              <th scope="col">Producto</th>
              <th scope="col" class="num">Mostrador</th>
              <th scope="col" class="num">PedidosYa</th>
              <th scope="col" class="num">Dif.</th>
              <th scope="col" class="center">Disponible</th>
            </tr>
          </thead>
          <tbody v-for="sec in productSections" :key="sec.id">
            <tr class="section-row"><th colspan="5" scope="colgroup">{{ sec.name }}</th></tr>
            <tr v-for="p in sec.items" :key="p.id" :class="{ changed: isProductChanged(p), off: !productDrafts[p.id]?.available }">
              <td>
                {{ p.name }}
                <span v-if="p.is_active === false" class="row-tag">inactivo</span>
              </td>
              <td class="num">
                {{ p.is_weight_based ? `${fmt(Number(p.price_per_gram) * 100)} / 100 g` : fmt(p.price) }}
              </td>
              <td class="num">
                <span v-if="p.is_weight_based" class="muted">por peso</span>
                <template v-else>
                  <input
                    v-model="productDrafts[p.id].delivery_price"
                    type="number"
                    min="0"
                    step="0.5"
                    class="price-input"
                    :placeholder="fmt(p.price)"
                    :aria-label="`Precio PedidosYa de ${p.name}`"
                  />
                  <span v-if="!productDrafts[p.id].delivery_price" class="row-tag">usa local</span>
                </template>
              </td>
              <td class="num muted">{{ p.is_weight_based ? '' : diffLabel(p.price, productDrafts[p.id].delivery_price) }}</td>
              <td class="center">
                <input
                  v-model="productDrafts[p.id].available"
                  type="checkbox"
                  :aria-label="`${p.name} disponible en PedidosYa`"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Opciones (Solo tamaños) -->
    <section class="tariff-card" v-if="optionSections.length > 0">
      <h3 class="tariff-card-title">Tamaños</h3>
      <div class="table-scroll">
        <table class="tariff-table">
          <thead>
            <tr>
              <th scope="col">Opción</th>
              <th scope="col" class="num">Mostrador</th>
              <th scope="col" class="num">PedidosYa</th>
              <th scope="col" class="num">Dif.</th>
            </tr>
          </thead>
          <tbody v-for="sec in optionSections" :key="sec.id">
            <tr class="section-row"><th colspan="4" scope="colgroup">{{ sec.name }}</th></tr>
            <tr v-for="o in sec.items" :key="o.id" :class="{ changed: isOptionChanged(o) }">
              <td>{{ o.name }}</td>
              <td class="num">+{{ fmt(o.additional_price) }}</td>
              <td class="num">
                <input
                  v-if="optionDrafts[o.id]"
                  v-model="optionDrafts[o.id].delivery_price"
                  type="number"
                  min="0"
                  step="0.5"
                  class="price-input"
                  :placeholder="fmt(o.additional_price)"
                  :aria-label="`Precio PedidosYa de ${sec.name} ${o.name}`"
                />
                <span v-if="optionDrafts[o.id] && !optionDrafts[o.id].delivery_price" class="row-tag">usa local</span>
              </td>
              <td class="num muted">{{ diffLabel(o.additional_price, optionDrafts[o.id]?.delivery_price) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="tariff-note">
        Toppings, frutas y demás opcionales cobran el mismo precio en PedidosYa.
      </p>
    </section>

    <!-- Barra de guardado -->
    <div class="save-bar" :class="{ visible: changesCount > 0 }">
      <span>{{ changesCount }} {{ changesCount === 1 ? 'cambio sin guardar' : 'cambios sin guardar' }}</span>
      <div class="save-actions">
        <button type="button" class="btn-ghost" :disabled="isSaving" @click="resetDrafts">Descartar</button>
        <button type="button" class="btn-save" :disabled="isSaving || changesCount === 0" @click="save">
          {{ isSaving ? 'Guardando…' : 'Guardar tarifario' }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.tariff-view {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding-bottom: 72px;
}
.tariff-header {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--border);
}
.tariff-header h2 {
  margin: 0;
  font-size: 26px;
  font-weight: 800;
  color: var(--ink-900);
}
.tariff-sub {
  margin: 4px 0 0;
  font-size: 13.5px;
  color: var(--ink-500);
  max-width: 560px;
}
.tariff-summary {
  font-size: 13px;
  font-weight: 700;
  padding: 6px 12px;
  border-radius: 99px;
  background: #FEF3C7;
  color: #7A4A00;
}
.tariff-summary.ok {
  background: #DCFCE7;
  color: #14532D;
}
html.dark .tariff-summary {
  background: #3A2A06;
  color: #FCD98A;
}
html.dark .tariff-summary.ok {
  background: #0F2E1A;
  color: #86EFAC;
}
.tariff-tools,
.tariff-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  font-size: 14px;
  color: var(--ink-700);
}
.tariff-tools {
  padding: 12px 14px;
  border: 1px solid var(--border);
  border-radius: 14px;
  background: var(--surface);
}
.tool-field {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.tool-input {
  width: 64px;
}
.tool-input,
.tool-select,
.price-input {
  padding: 6px 8px;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  background: var(--surface);
  color: var(--ink);
  font-family: inherit;
  font-size: 14px;
}
.tool-input:focus-visible,
.tool-select:focus-visible,
.price-input:focus-visible {
  outline: 2px solid var(--acai-700);
  outline-offset: 1px;
}
.btn-tool {
  padding: 7px 14px;
  border-radius: 99px;
  border: 1.5px solid var(--passion-500);
  background: transparent;
  color: var(--passion-text);
  font-family: inherit;
  font-weight: 700;
  cursor: pointer;
}
.tool-hint {
  font-size: 12.5px;
  color: var(--ink-500);
}
.tariff-filters label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
}
.tariff-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 14px 16px;
  box-shadow: var(--shadow-card);
}
.tariff-card-title {
  margin: 0 0 8px;
  font-size: 17px;
  font-weight: 800;
  color: var(--ink-900);
}
.table-scroll {
  overflow-x: auto;
}
.tariff-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
  color: var(--ink);
}
.tariff-table th,
.tariff-table td {
  padding: 7px 8px;
  border-bottom: 1px solid var(--border);
  text-align: left;
  white-space: nowrap;
}
.tariff-table thead th {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--ink-500);
}
.tariff-table .section-row th {
  padding-top: 14px;
  font-size: 13px;
  font-weight: 800;
  color: var(--acai-text);
  border-bottom: none;
}
html.dark .tariff-table .section-row th {
  color: var(--ink-700);
}
.tariff-table .num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.tariff-table .center {
  text-align: center;
}
.price-input {
  width: 88px;
  text-align: right;
}
.tariff-table tr.changed td {
  background: rgba(194, 0, 63, 0.07);
}
html.dark .tariff-table tr.changed td {
  background: rgba(255, 107, 150, 0.12);
}
.tariff-table tr.off td:first-child {
  color: var(--ink-500);
}
.row-tag {
  display: inline-block;
  margin-left: 6px;
  padding: 1px 6px;
  border-radius: 99px;
  font-size: 11px;
  font-weight: 700;
  background: var(--surface-hover);
  color: var(--ink-700);
}
.muted {
  color: var(--ink-500);
}
.tariff-note {
  margin: 8px 0 0;
  font-size: 12.5px;
  color: var(--ink-500);
}
.save-bar {
  position: sticky;
  bottom: 0;
  display: none;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 16px;
  border-radius: 14px;
  border: 1px solid #C2003F;
  background: var(--surface);
  box-shadow: 0 -6px 18px rgba(0, 0, 0, 0.08);
  font-weight: 700;
  color: var(--ink);
}
.save-bar.visible {
  display: flex;
}
.save-actions {
  display: flex;
  gap: 8px;
}
.btn-ghost,
.btn-save {
  padding: 9px 16px;
  border-radius: 11px;
  font-family: inherit;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
.btn-ghost {
  border: 1px solid var(--border);
  background: var(--surface-hover);
  color: var(--ink-700);
}
.btn-save {
  border: 1px solid #C2003F;
  background: #C2003F;
  color: #fff;
}
.btn-save:disabled,
.btn-ghost:disabled {
  opacity: 0.6;
  cursor: default;
}
.btn-tool:focus-visible,
.btn-ghost:focus-visible,
.btn-save:focus-visible {
  outline: 2px solid var(--acai-700);
  outline-offset: 2px;
}
</style>
