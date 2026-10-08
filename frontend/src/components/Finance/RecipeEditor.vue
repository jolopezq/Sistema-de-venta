<script setup>
import { computed, reactive, ref } from 'vue';
import { apiFetch } from '../../services/api';
import { useFinanceStore, formatBs, errorText, UNIT_DISPLAY } from '../../stores/finance';
import FinanceModal from './FinanceModal.vue';

/**
 * Editor de una receta (producto) o de un ajuste (opción). Las cantidades se escriben en g, ml o unidades y una
 * línea puede indicar cuántas tazas o porciones rinde (18 g que rinden 2 tazas = 9 g por taza).
 * En los ajustes de opción las cantidades pueden ser negativas («Sin granola» resta toppings).
 */
const props = defineProps({
  target: { type: Object, required: true }, // { kind: 'product' | 'option', id, name, weightBased, recipe: [{ingredient_id, quantity}] }
});
const emit = defineEmits(['close', 'saved']);
const finance = useFinanceStore();

const ingredients = computed(() => finance.costs?.ingredients || []);
const byId = computed(() => Object.fromEntries(ingredients.value.map((i) => [i.id, i])));
const isOption = computed(() => props.target.kind === 'option');

function displayOf(ingredient) {
  // En un producto por peso, lo que va en kg se escribe como «kg por kg vendido»
  if (props.target.weightBased && ingredient?.unit === 'kg') return { short: 'kg por kg vendido', factor: 1 };
  return UNIT_DISPLAY[ingredient?.unit] || UNIT_DISPLAY.unidades;
}

let uid = 0;
const rows = ref((props.target.recipe || []).map((r) => {
  const ing = byId.value[r.ingredient_id];
  return { uid: uid++, ingredient_id: r.ingredient_id, amount: round(r.quantity * displayOf(ing).factor), yields: 1 };
}));

function round(n) {
  return Math.round(Number(n) * 10000) / 10000;
}

function addRow() {
  rows.value.push({ uid: uid++, ingredient_id: '', amount: '', yields: 1 });
}
function removeRow(row) {
  rows.value = rows.value.filter((r) => r.uid !== row.uid);
}

function quantityOf(row) {
  const ing = byId.value[row.ingredient_id];
  if (!ing || row.amount === '' || row.amount === null) return null;
  const yields = Number(row.yields) > 0 ? Number(row.yields) : 1;
  return Number(row.amount) / displayOf(ing).factor / yields;
}

function lineCost(row) {
  const ing = byId.value[row.ingredient_id];
  const q = quantityOf(row);
  if (!ing || q === null) return null;
  // Producto por peso: el costo de la línea en kg se muestra para el peso de referencia
  const scale = props.target.weightBased && ing.unit === 'kg' ? (finance.costs?.reference_grams || 350) / 1000 : 1;
  return q * ing.cost * scale;
}

const total = computed(() => rows.value.reduce((sum, r) => sum + (lineCost(r) || 0), 0));
const missingCost = computed(() => rows.value.filter((r) => byId.value[r.ingredient_id] && byId.value[r.ingredient_id].cost <= 0));

const saving = ref(false);
const error = ref('');

async function save() {
  error.value = '';
  const payload = [];
  for (const r of rows.value) {
    if (!r.ingredient_id) continue;
    const q = quantityOf(r);
    if (q === null || q === 0) { error.value = 'Completa la cantidad de cada línea (o quítala).'; return; }
    if (!isOption.value && q < 0) { error.value = 'En la receta de un producto las cantidades son positivas.'; return; }
    payload.push({ ingredient_id: Number(r.ingredient_id), quantity: Math.round(q * 10000) / 10000 });
  }
  saving.value = true;
  try {
    const url = isOption.value ? `/finance/costs/options/${props.target.id}/recipe` : `/finance/costs/products/${props.target.id}/recipe`;
    const res = await apiFetch(url, { method: 'PUT', body: JSON.stringify({ rows: payload }) });
    finance.notify(res.message);
    emit('saved');
  } catch (err) {
    error.value = errorText(err, 'No se pudo guardar la receta.');
  } finally {
    saving.value = false;
  }
}

// ---------- Nuevo componente desde el editor ----------
const newComp = reactive({ open: false, name: '', unit: 'unidades', unit_cost: '', forRow: null, saving: false, error: '' });
function openNewComponent(row) {
  Object.assign(newComp, { open: true, name: '', unit: 'unidades', unit_cost: '', forRow: row, error: '' });
}
async function createComponent() {
  if (!newComp.name.trim()) { newComp.error = 'Escribe el nombre.'; return; }
  newComp.saving = true;
  newComp.error = '';
  try {
    const res = await apiFetch('/finance/costs/components', {
      method: 'POST',
      body: JSON.stringify({ name: newComp.name, unit: newComp.unit, unit_cost: Number(newComp.unit_cost) || 0 }),
    });
    await finance.loadCosts();
    if (newComp.forRow) newComp.forRow.ingredient_id = res.component.id;
    newComp.open = false;
  } catch (err) {
    newComp.error = errorText(err, 'No se pudo crear el componente.');
  } finally {
    newComp.saving = false;
  }
}

function onSelect(row, value) {
  if (value === '__new__') {
    openNewComponent(row);
    return;
  }
  row.ingredient_id = value === '' ? '' : Number(value);
}
</script>

<template>
  <FinanceModal
    :title="isOption ? `Ajuste de «${target.name}»` : `Receta de ${target.name}`"
    :subtitle="isOption
      ? 'Lo que la opción suma o resta a la receta del producto (en negativo para restar, p. ej. «Sin granola»).'
      : target.weightBased
        ? 'Producto por peso: lo que va en kg se multiplica por los kg vendidos; envase y cucharilla van una vez.'
        : 'Lo que lleva una unidad del producto en su tamaño base.'"
    wide
    :busy="saving"
    @close="emit('close')"
  >
    <div class="fin-table-wrap">
      <table class="fin-table stack fin-recipe-table">
        <thead>
          <tr>
            <th>Componente</th>
            <th class="right">Cantidad</th>
            <th class="right">Rinde</th>
            <th class="right">Costo</th>
            <th class="actions"><span class="sr-only">Quitar</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.uid">
            <td data-label="Componente">
              <select
                class="fin-input fin-input-sm"
                :value="row.ingredient_id"
                :aria-label="`Componente de la línea`"
                @change="onSelect(row, $event.target.value)"
              >
                <option value="" disabled>Elegir…</option>
                <option v-for="i in ingredients" :key="i.id" :value="i.id">{{ i.name }}{{ i.cost > 0 ? '' : ' (sin costo)' }}</option>
                <option value="__new__">+ Nuevo componente…</option>
              </select>
            </td>
            <td data-label="Cantidad" class="right">
              <span class="fin-qty">
                <input
                  v-model="row.amount"
                  type="number"
                  step="any"
                  inputmode="decimal"
                  class="fin-input fin-input-sm fin-num"
                  :aria-label="`Cantidad de ${byId[row.ingredient_id]?.name || 'componente'}`"
                />
                <span class="fin-small fin-muted">{{ displayOf(byId[row.ingredient_id]).short }}</span>
              </span>
            </td>
            <td data-label="Rinde" class="right">
              <span class="fin-qty">
                <input v-model="row.yields" type="number" min="1" step="1" class="fin-input fin-input-sm fin-num" aria-label="Rinde" />
                <span class="fin-small fin-muted">{{ Number(row.yields) > 1 ? 'tazas' : 'taza' }}</span>
              </span>
            </td>
            <td data-label="Costo" class="right fin-num">
              <template v-if="lineCost(row) !== null">{{ formatBs(lineCost(row), { sign: isOption }) }}</template>
              <span v-else class="fin-muted">—</span>
            </td>
            <td class="actions">
              <button class="fin-icon-btn danger" type="button" aria-label="Quitar línea" @click="removeRow(row)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6 6 18M6 6l12 12" /></svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="fin-row">
      <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="addRow">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="M12 5v14M5 12h14" /></svg>
        Agregar línea
      </button>
      <span class="fin-spacer"></span>
      <strong class="fin-num">{{ isOption ? 'Ajuste' : 'Costo' }} {{ formatBs(total, { sign: isOption }) }}<template v-if="target.weightBased"> (a {{ finance.costs?.reference_grams || 350 }} g)</template></strong>
    </div>
    <p class="fin-hint">«Rinde» divide la cantidad: 18 g de café que rinden 2 tazas = 9 g por taza. Para una taza que usa la dosis completa deja 1.</p>
    <div v-if="missingCost.length" class="fin-note">
      Sin costo cargado: {{ [...new Set(missingCost.map((r) => byId[r.ingredient_id].name))].join(', ') }}. Complétalo en «Componentes de costo»; mientras tanto la receta queda incompleta.
    </div>

    <div v-if="newComp.open" class="fin-mapping-box">
      <div class="fin-label">Nuevo componente</div>
      <div class="fin-grid-3">
        <div class="fin-field">
          <label for="rc-name">Nombre</label>
          <input id="rc-name" v-model="newComp.name" type="text" maxlength="150" class="fin-input fin-input-sm" placeholder="Ej.: Sirope de caramelo" />
        </div>
        <div class="fin-field">
          <label for="rc-unit">Se compra por</label>
          <select id="rc-unit" v-model="newComp.unit" class="fin-input fin-input-sm">
            <option value="unidades">Unidad</option>
            <option value="kg">Kilo (se usa en g)</option>
            <option value="litros">Litro (se usa en ml)</option>
          </select>
        </div>
        <div class="fin-field">
          <label for="rc-cost">Costo (Bs {{ UNIT_DISPLAY[newComp.unit].costLabel }})</label>
          <input id="rc-cost" v-model="newComp.unit_cost" type="number" min="0" step="0.01" class="fin-input fin-input-sm" />
        </div>
      </div>
      <div v-if="newComp.error" class="fin-note err" role="alert">{{ newComp.error }}</div>
      <div class="fin-row">
        <span class="fin-spacer"></span>
        <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="newComp.open = false">Cancelar</button>
        <button class="fin-btn fin-btn-secondary fin-btn-sm" type="button" :disabled="newComp.saving" @click="createComponent">Crear componente</button>
      </div>
    </div>

    <div v-if="error" class="fin-note err" role="alert">{{ error }}</div>
    <template #footer>
      <button class="fin-btn fin-btn-secondary" type="button" :disabled="saving" @click="emit('close')">Cancelar</button>
      <button class="fin-btn fin-btn-primary" type="button" :disabled="saving" @click="save">{{ saving ? 'Guardando…' : 'Guardar' }}</button>
    </template>
  </FinanceModal>
</template>

<style scoped>
.fin-qty { display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end; }
.fin-qty input { width: 88px; text-align: right; }
.fin-recipe-table select { min-width: 200px; }
.fin-mapping-box {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px;
  border-radius: 12px;
  border: 1px solid var(--border);
  background: var(--cream-50);
}
@media (max-width: 760px) {
  .fin-recipe-table select { min-width: 0; width: 100%; }
  .fin-qty { justify-content: flex-start; }
}
</style>
