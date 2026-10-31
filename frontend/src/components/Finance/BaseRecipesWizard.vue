<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { apiFetch } from '../../services/api';
import { useFinanceStore, formatBs, errorText, UNIT_DISPLAY } from '../../stores/finance';
import FinanceModal from './FinanceModal.vue';

/**
 * Asistente «Cargar recetas base»: crea los componentes de costo (porciones, descartables, café…) y las recetas del
 * modelo por porciones con valores iniciales editables. Por defecto solo marca lo que todavía no tiene receta.
 * Optimizado con stepper de 3 pasos para la portátil de caja (Fase 4 del plan ergonómico).
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

// Control del stepper (Paso 1: Componentes, Paso 2: Productos, Paso 3: Opciones)
const currentStep = ref(1);

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

const STEPS = computed(() => [
  {
    number: 1,
    id: 'components',
    title: '1. Costo de componentes',
    subtitle: `${preview.value?.components?.length || 0} componentes base`,
  },
  {
    number: 2,
    id: 'products',
    title: '2. Recetas de productos',
    subtitle: `${selectedProducts.value.size}/${preview.value?.products?.length || 0} seleccionados`,
  },
  {
    number: 3,
    id: 'options',
    title: '3. Ajustes de opciones',
    subtitle: `${selectedOptions.value.size}/${preview.value?.options?.length || 0} seleccionadas`,
  },
]);

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

// Acciones rápidas de selección
function selectAllProducts() {
  selectedProducts.value = new Set(preview.value.products.map((p) => p.product_id));
}
function selectUnassignedProducts() {
  selectedProducts.value = new Set(preview.value.products.filter((p) => !p.has_recipe).map((p) => p.product_id));
}
function clearProducts() {
  selectedProducts.value = new Set();
}

function selectAllOptions() {
  selectedOptions.value = new Set(preview.value.options.map((o) => o.option_id));
}
function selectUnassignedOptions() {
  selectedOptions.value = new Set(preview.value.options.filter((o) => !o.has_recipe).map((o) => o.option_id));
}
function clearOptions() {
  selectedOptions.value = new Set();
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
      <!-- Stepper de 3 pasos accesible (botones simples, sin role=tab que exigiría flechas) -->
      <nav class="brw-stepper" aria-label="Pasos para cargar recetas base">
        <button
          v-for="s in STEPS"
          :key="s.number"
          class="brw-step"
          :class="{ active: currentStep === s.number, done: currentStep > s.number }"
          type="button"
          :aria-current="currentStep === s.number ? 'step' : undefined"
          @click="currentStep = s.number"
        >
          <span class="brw-step-badge">{{ s.number }}</span>
          <span class="brw-step-info">
            <span class="brw-step-title">{{ s.title }}</span>
            <span class="brw-step-sub">{{ s.subtitle }}</span>
          </span>
        </button>
      </nav>

      <!-- PASO 1: Costo de cada componente -->
      <section v-if="currentStep === 1" class="brw-step-panel">
        <div class="fin-table-wrap fin-box brw-table-wrap">
          <table class="fin-table brw-table">
            <thead>
              <tr>
                <th scope="col">Componente</th>
                <th scope="col" class="right">Costo unitario</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in preview.components" :key="c.key">
                <td>
                  <span class="fin-cell-main">{{ c.name }}</span>
                  <span v-if="c.exists" class="fin-badge info" style="margin-left: 6px">Ya existe</span>
                  <span v-if="!Number(costs[c.key])" class="fin-badge warn" style="margin-left: 6px">Sin costo</span>
                </td>
                <td class="right">
                  <span class="fin-cost-input">
                    <input
                      v-model="costs[c.key]"
                      type="number"
                      min="0"
                      step="0.01"
                      class="fin-input fin-input-sm fin-num"
                      :aria-label="`Costo de ${c.name}`"
                    />
                    <span class="fin-small fin-muted">{{ UNIT_DISPLAY[c.unit]?.costLabel }}</span>
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="fin-hint">Los del café quedan en cero para que cargues tus precios; mientras tanto el margen del café aparece incompleto.</p>
      </section>

      <!-- PASO 2: Recetas de los productos -->
      <section v-else-if="currentStep === 2" class="brw-step-panel">
        <div class="brw-quick-bar">
          <span class="fin-small fin-muted">
            <strong>{{ selectedProducts.size }}</strong> de {{ preview.products.length }} productos seleccionados
          </span>
          <div class="brw-quick-actions">
            <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="selectAllProducts">Todos</button>
            <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="selectUnassignedProducts">Solo sin receta</button>
            <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="clearProducts">Ninguno</button>
          </div>
        </div>

        <div class="fin-box fin-checklist brw-checklist">
          <label v-for="p in preview.products" :key="p.product_id" class="fin-check-row">
            <input type="checkbox" :checked="selectedProducts.has(p.product_id)" @change="toggle(selectedProducts, p.product_id)" />
            <span class="fin-check-body">
              <span class="fin-cell-main">{{ p.name }}</span>
              <span class="fin-badge" style="margin-left: 6px">{{ KIND[p.kind] }}</span>
              <span v-if="p.has_recipe" class="fin-badge warn" style="margin-left: 4px">Ya tiene receta: se reemplaza</span>
              <span class="fin-cell-sub">{{ summary(p.recipe, p.kind === 'weight') }}</span>
            </span>
            <span class="fin-num fin-small">
              {{ formatBs(estimate(p.recipe, p.kind === 'weight')) }}<template v-if="p.kind === 'weight'"> a 350 g</template>
            </span>
          </label>
        </div>
        <p v-if="!preview.products.length" class="fin-note">No se encontraron bowls, productos por peso ni cafés activos en el Menú.</p>
      </section>

      <!-- PASO 3: Ajustes de las opciones -->
      <section v-else-if="currentStep === 3" class="brw-step-panel">
        <div class="brw-quick-bar">
          <span class="fin-small fin-muted">
            <strong>{{ selectedOptions.size }}</strong> de {{ preview.options.length }} opciones seleccionadas
          </span>
          <div class="brw-quick-actions">
            <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="selectAllOptions">Todas</button>
            <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="selectUnassignedOptions">Solo sin ajuste</button>
            <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="clearOptions">Ninguna</button>
          </div>
        </div>

        <div class="brw-options-wrap">
          <div v-for="g in optionGroups" :key="g.name" class="fin-box fin-checklist brw-group-box">
            <div class="fin-small fin-muted brw-group-title">{{ g.name }}</div>
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
        </div>
      </section>
    </template>

    <div v-if="error" class="fin-note err" role="alert">{{ error }}</div>

    <template #footer>
      <button class="fin-btn fin-btn-secondary" type="button" :disabled="saving" @click="emit('close')">Cancelar</button>
      <div class="brw-foot-actions">
        <button
          v-if="currentStep > 1"
          class="fin-btn fin-btn-secondary"
          type="button"
          :disabled="saving"
          @click="currentStep--"
        >
          ← Anterior
        </button>
        <button
          v-if="currentStep < 3"
          class="fin-btn fin-btn-secondary"
          type="button"
          :disabled="saving"
          @click="currentStep++"
        >
          Siguiente: {{ currentStep === 1 ? 'Productos' : 'Opciones' }} →
        </button>
        <button
          v-if="currentStep === 3"
          class="fin-btn fin-btn-primary"
          type="button"
          :disabled="saving || loading || !preview"
          @click="apply"
        >
          {{ saving ? 'Aplicando…' : applyLabel }}
        </button>
      </div>
    </template>
  </FinanceModal>
</template>

<style scoped>
.brw-stepper {
  display: flex;
  gap: 8px;
  position: sticky;
  top: 0;
  z-index: 3;
  background: var(--surface);
  padding: 0 0 10px;
  border-bottom: 1px solid var(--border);
  margin-bottom: 12px;
}
.brw-step {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  border-radius: 9px;
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--ink-700);
  cursor: pointer;
  text-align: left;
  transition: all 0.15s ease;
  min-height: 42px;
}
.brw-step:hover {
  border-color: var(--ink-300);
}
.brw-step.active {
  border-color: var(--acai-500);
  background: var(--primary-subtle, rgba(106, 27, 154, 0.08));
}
.brw-step-badge {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 11.5px;
  font-weight: 700;
  background: var(--ink-100);
  color: var(--ink-900);
  flex-shrink: 0;
}
.brw-step.active .brw-step-badge {
  background: var(--acai-500);
  /* Texto claro sobre el morado (--on-accent es oscuro: daba 2,83 de contraste) */
  color: #fff;
}
.brw-step.done .brw-step-badge {
  background: var(--ink-300);
  color: var(--ink-900);
}
.brw-step-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.brw-step-title {
  font-size: 12.5px;
  font-weight: 700;
  color: var(--ink-900);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.brw-step-sub {
  font-size: 11px;
  color: var(--ink-500);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.brw-step-panel {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.brw-quick-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
  padding: 2px 2px 4px;
}
.brw-quick-actions {
  display: flex;
  gap: 4px;
}
.brw-quick-actions .fin-btn-ghost {
  padding: 3px 8px;
  font-size: 11.5px;
  height: 26px;
}
.brw-options-wrap {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.brw-group-box {
  margin-bottom: 0;
}
.brw-group-title {
  font-weight: 700;
  margin-bottom: 4px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  font-size: 11px;
}
.brw-table th,
.brw-table td {
  padding: 6px 10px;
  font-size: 13px;
}
.fin-box {
  border: 1px solid var(--border);
  border-radius: 12px;
}
.fin-checklist {
  display: flex;
  flex-direction: column;
  padding: 4px 12px;
}
.fin-check-row {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 6px 0;
  border-bottom: 1px solid var(--border);
  cursor: pointer;
  color: var(--ink-900);
  font-size: 13px;
}
.fin-check-row:last-child {
  border-bottom: none;
}
.fin-check-row input {
  margin-top: 3px;
  width: 16px;
  height: 16px;
  accent-color: var(--acai-500);
  flex-shrink: 0;
}
.fin-check-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 2px;
}
.fin-check-body .fin-cell-sub {
  flex-basis: 100%;
  font-size: 12px;
}
.fin-cost-input {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  justify-content: flex-end;
}
.fin-cost-input input {
  width: 82px;
  text-align: right;
  height: 32px;
}
.brw-foot-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}
@media (max-width: 760px) {
  .brw-stepper {
    overflow-x: auto;
    flex-wrap: nowrap;
    padding-bottom: 6px;
  }
  .brw-step {
    flex: 0 0 auto;
    min-width: 180px;
  }
  .fin-cost-input {
    justify-content: flex-start;
  }
}
</style>

