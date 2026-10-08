<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { apiFetch } from '../../services/api';
import { useFinanceStore, formatBs, errorText, UNIT_DISPLAY } from '../../stores/finance';
import FinanceModal from './FinanceModal.vue';

/**
 * Asistente «Cargar recetas base»: crea los componentes de costo (porciones, descartables, café…) y las recetas del
 * modelo por porciones con valores iniciales editables. Por defecto solo marca lo que todavía no tiene receta.
 */
const emit = defineEmits(['close', 'applied']);
const finance = useFinanceStore();

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const preview = ref(null);
const costs = reactive({});
const selectedProducts = ref(new Set());
const selectedOptions = ref(new Set());

const KIND = { bowl: 'Bowl', armable: 'Arma tu bowl', weight: 'Por peso', coffee: 'Café' };
const OPTION_KIND = {
  size: 'Tamaño', fruit: 'Fruta', without: 'Sin…', base_extra: 'Extra de la base', extra: 'Topping extra', coffee_size: 'Tamaño café',
};

onMounted(async () => {
  try {
    preview.value = await apiFetch('/finance/costs/base-recipes');
    for (const c of preview.value.components) costs[c.key] = c.unit_cost;
    selectedProducts.value = new Set(preview.value.products.filter((p) => !p.has_recipe).map((p) => p.product_id));
    selectedOptions.value = new Set(preview.value.options.filter((o) => !o.has_recipe).map((o) => o.option_id));
  } catch (err) {
    error.value = errorText(err, 'No se pudo preparar la propuesta.');
  } finally {
    loading.value = false;
  }
});

const applyLabel = computed(() => {
  const p = selectedProducts.value.size;
  const o = selectedOptions.value.size;
  if (!p && !o) return 'Guardar costos de componentes';
  return `Aplicar a ${p} ${p === 1 ? 'producto' : 'productos'} y ${o} ${o === 1 ? 'opción' : 'opciones'}`;
});
const components = computed(() => Object.fromEntries((preview.value?.components || []).map((c) => [c.key, c])));

function summary(recipe, weight = false) {
  return recipe.map((r) => {
    const c = components.value[r.key];
    if (weight && c?.unit === 'kg') return `${c.name} por kg vendido`;
    const d = UNIT_DISPLAY[c?.unit] || UNIT_DISPLAY.unidades;
    const amount = Math.round(r.quantity * d.factor * 1000) / 1000;
    return `${amount.toLocaleString('es-BO')} ${d.short} ${c?.name || r.key}`;
  }).join(' · ');
}

function estimate(recipe, weight = false) {
  return recipe.reduce((sum, r) => {
    const c = components.value[r.key];
    const scale = weight && c?.unit === 'kg' ? 0.35 : 1;
    return sum + r.quantity * (Number(costs[r.key]) || 0) * scale;
  }, 0);
}

function toggle(set, id) {
  const next = new Set(set.value);
  next.has(id) ? next.delete(id) : next.add(id);
  set.value = next;
}

const optionGroups = computed(() => {
  const groups = {};
  for (const o of preview.value?.options || []) (groups[o.group] ||= []).push(o);
  return Object.entries(groups).map(([name, options]) => ({ name, options }));
});

async function apply() {
  saving.value = true;
  error.value = '';
  try {
    const res = await apiFetch('/finance/costs/base-recipes', {
      method: 'POST',
      body: JSON.stringify({
        costs: Object.fromEntries(Object.entries(costs).map(([k, v]) => [k, Number(v) || 0])),
        product_ids: [...selectedProducts.value],
        option_ids: [...selectedOptions.value],
      }),
    });
    finance.notify(res.message);
    emit('applied');
  } catch (err) {
    error.value = errorText(err, 'No se pudieron aplicar las recetas.');
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <FinanceModal
    title="Cargar recetas base"
    subtitle="Modelo por porciones del análisis de costos. Revisa los costos: son valores iniciales y se pueden cambiar después."
    wide
    :busy="saving"
    @close="emit('close')"
  >
    <div v-if="loading" class="fin-loading">Revisando el catálogo…</div>
    <template v-else-if="preview">
      <div class="fin-label">1. Costo de cada componente</div>
      <div class="fin-table-wrap fin-box">
        <table class="fin-table stack">
          <thead>
            <tr><th>Componente</th><th class="right">Costo (Bs)</th></tr>
          </thead>
          <tbody>
            <tr v-for="c in preview.components" :key="c.key">
              <td data-label="Componente">
                <span class="fin-cell-main">{{ c.name }}</span>
                <span v-if="c.exists" class="fin-badge info" style="margin-left: 6px">Ya existe</span>
                <span v-if="!Number(costs[c.key])" class="fin-badge warn" style="margin-left: 6px">Sin costo</span>
              </td>
              <td data-label="Costo" class="right">
                <span class="fin-cost-input">
                  <input v-model="costs[c.key]" type="number" min="0" step="0.01" class="fin-input fin-input-sm fin-num" :aria-label="`Costo de ${c.name}`" />
                  <span class="fin-small fin-muted">{{ UNIT_DISPLAY[c.unit]?.costLabel }}</span>
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="fin-hint">Los del café quedan en cero para que cargues tus precios; mientras tanto el margen del café aparece incompleto.</p>

      <div class="fin-label">2. Recetas de los productos</div>
      <div class="fin-box fin-checklist">
        <label v-for="p in preview.products" :key="p.product_id" class="fin-check-row">
          <input type="checkbox" :checked="selectedProducts.has(p.product_id)" @change="toggle(selectedProducts, p.product_id)" />
          <span class="fin-check-body">
            <span class="fin-cell-main">{{ p.name }}</span>
            <span class="fin-badge" style="margin-left: 6px">{{ KIND[p.kind] }}</span>
            <span v-if="p.has_recipe" class="fin-badge warn" style="margin-left: 4px">Ya tiene receta: se reemplaza si la marcas</span>
            <span class="fin-cell-sub">{{ summary(p.recipe, p.kind === 'weight') }}</span>
          </span>
          <span class="fin-num fin-small">{{ formatBs(estimate(p.recipe, p.kind === 'weight')) }}<template v-if="p.kind === 'weight'"> a 350 g</template></span>
        </label>
      </div>

      <div class="fin-label">3. Ajustes de las opciones</div>
      <div v-for="g in optionGroups" :key="g.name" class="fin-box fin-checklist">
        <div class="fin-small fin-muted" style="font-weight: 700">{{ g.name }}</div>
        <label v-for="o in g.options" :key="o.option_id" class="fin-check-row">
          <input type="checkbox" :checked="selectedOptions.has(o.option_id)" @change="toggle(selectedOptions, o.option_id)" />
          <span class="fin-check-body">
            <span class="fin-cell-main">{{ o.name }}</span>
            <span class="fin-badge" style="margin-left: 6px">{{ OPTION_KIND[o.kind] }}</span>
            <span v-if="o.has_recipe" class="fin-badge warn" style="margin-left: 4px">Ya tiene ajuste</span>
            <span class="fin-cell-sub">{{ summary(o.recipe) }}</span>
          </span>
          <span class="fin-num fin-small">{{ formatBs(estimate(o.recipe), { sign: true }) }}</span>
        </label>
      </div>
      <p v-if="!preview.products.length" class="fin-note">No se encontraron bowls, productos por peso ni cafés activos en el Menú.</p>
    </template>
    <div v-if="error" class="fin-note err" role="alert">{{ error }}</div>

    <template #footer>
      <button class="fin-btn fin-btn-secondary" type="button" :disabled="saving" @click="emit('close')">Cancelar</button>
      <button class="fin-btn fin-btn-primary" type="button" :disabled="saving || loading || !preview" @click="apply">
        {{ saving ? 'Aplicando…' : applyLabel }}
      </button>
    </template>
  </FinanceModal>
</template>

<style scoped>
.fin-box { border: 1px solid var(--border); border-radius: 12px; }
.fin-checklist { display: flex; flex-direction: column; padding: 6px 12px; }
.fin-check-row {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 8px 0;
  border-bottom: 1px solid var(--border);
  cursor: pointer;
  color: var(--ink-900);
  font-size: 13px;
}
.fin-check-row:last-child { border-bottom: none; }
.fin-check-row input { margin-top: 3px; width: 16px; height: 16px; accent-color: var(--acai-500); flex-shrink: 0; }
.fin-check-body { flex: 1; min-width: 0; display: flex; flex-wrap: wrap; align-items: center; gap: 2px; }
.fin-check-body .fin-cell-sub { flex-basis: 100%; }
.fin-cost-input { display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end; }
.fin-cost-input input { width: 96px; text-align: right; }
@media (max-width: 760px) {
  .fin-cost-input { justify-content: flex-start; }
}
</style>
