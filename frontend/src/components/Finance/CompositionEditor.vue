<script setup>
import { computed, ref } from 'vue';
import { apiFetch } from '../../services/api';
import { useFinanceStore, formatBs, errorText, UNIT_DISPLAY } from '../../stores/finance';
import FinanceModal from './FinanceModal.vue';

/**
 * Composición de un componente de costeo: qué insumos reales consume una unidad (o 1 kg / 1 litro) de «Porción
 * base açaí», «Kit descartable chico»… Las cantidades se escriben en g, ml o unidades. Al guardar, cada venta que
 * use el componente descuenta esos insumos del inventario y el costo del componente sale de su costo promedio.
 * Sin filas, el componente vuelve a su costo cargado a mano y deja de mover stock.
 */
const props = defineProps({
  component: { type: Object, required: true }, // { id, name, unit, unit_cost, composition: [{ingredient_id, quantity}] }
});
const emit = defineEmits(['close', 'saved']);
const finance = useFinanceStore();

// Solo insumos con stock: la composición no se arma con otros componentes
const stockIngredients = computed(() => (finance.costs?.ingredients || []).filter((i) => i.tracks_stock));
const byId = computed(() => Object.fromEntries(stockIngredients.value.map((i) => [i.id, i])));
const unitLabel = computed(() => ({ kg: '1 kg', litros: '1 litro' }[props.component.unit] || '1 unidad'));

function displayOf(ingredient) {
  return UNIT_DISPLAY[ingredient?.unit] || UNIT_DISPLAY.unidades;
}
function round(n) {
  return Math.round(Number(n) * 10000) / 10000;
}

let uid = 0;
const rows = ref((props.component.composition || []).map((r) => ({
  uid: uid++,
  ingredient_id: r.ingredient_id,
  amount: round(r.quantity * displayOf(byId.value[r.ingredient_id]).factor),
})));

function addRow() {
  rows.value.push({ uid: uid++, ingredient_id: '', amount: '' });
}
function removeRow(row) {
  rows.value = rows.value.filter((r) => r.uid !== row.uid);
}

function quantityOf(row) {
  const ing = byId.value[row.ingredient_id];
  if (!ing || row.amount === '' || row.amount === null) return null;
  return Number(row.amount) / displayOf(ing).factor;
}
function lineCost(row) {
  const ing = byId.value[row.ingredient_id];
  const q = quantityOf(row);
  return ing && q !== null ? q * ing.cost : null;
}

const total = computed(() => rows.value.reduce((sum, r) => sum + (lineCost(r) || 0), 0));
const missingCost = computed(() => rows.value.filter((r) => byId.value[r.ingredient_id] && byId.value[r.ingredient_id].cost <= 0));
const manualCost = computed(() => Number(props.component.unit_cost) || 0);

const saving = ref(false);
const error = ref('');

async function save() {
  error.value = '';
  const payload = [];
  for (const r of rows.value) {
    if (!r.ingredient_id && (r.amount === '' || r.amount === null)) continue; // fila vacía
    const q = quantityOf(r);
    if (!r.ingredient_id) { error.value = 'Elige el insumo de cada fila (o quítala).'; return; }
    if (q === null || !(q > 0)) { error.value = 'La cantidad de cada fila debe ser mayor a 0.'; return; }
    payload.push({ ingredient_id: Number(r.ingredient_id), quantity: round(q) });
  }
  if (new Set(payload.map((p) => p.ingredient_id)).size !== payload.length) {
    error.value = 'Un insumo aparece dos veces: junta las cantidades en una sola fila.';
    return;
  }
  saving.value = true;
  try {
    const res = await apiFetch(`/finance/costs/components/${props.component.id}/composition`, { method: 'PUT', body: JSON.stringify({ rows: payload }) });
    finance.notify(res.message);
    emit('saved');
  } catch (err) {
    error.value = errorText(err, 'No se pudo guardar la composición.');
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <FinanceModal
    :title="`Composición de «${component.name}»`"
    :subtitle="`Qué insumos del inventario consume ${unitLabel} de este componente.`"
    wide
    :busy="saving"
    @close="emit('close')"
  >
    <div v-if="!stockIngredients.length" class="fin-note">
      Aún no hay insumos con stock. Créalos en Inventario (con su costo o con una compra) y vuelve aquí.
    </div>
    <div v-else class="fin-table-wrap">
      <table class="fin-table stack fin-recipe-table">
        <thead>
          <tr>
            <th>Insumo</th>
            <th class="right">Cantidad</th>
            <th class="right">Costo</th>
            <th class="actions"><span class="sr-only">Quitar</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.uid">
            <td data-label="Insumo">
              <select v-model="row.ingredient_id" class="fin-input fin-input-sm" aria-label="Insumo de la fila">
                <option value="" disabled>Elegir…</option>
                <option v-for="i in stockIngredients" :key="i.id" :value="i.id">{{ i.name }}{{ i.cost > 0 ? '' : ' (sin costo)' }}</option>
              </select>
            </td>
            <td data-label="Cantidad" class="right">
              <span class="fin-qty">
                <input
                  v-model="row.amount"
                  type="number"
                  min="0"
                  step="any"
                  inputmode="decimal"
                  class="fin-input fin-input-sm fin-num"
                  :aria-label="`Cantidad de ${byId[row.ingredient_id]?.name || 'insumo'}`"
                />
                <span class="fin-small fin-muted">{{ displayOf(byId[row.ingredient_id]).short }}</span>
              </span>
            </td>
            <td data-label="Costo" class="right fin-num">
              <template v-if="lineCost(row) !== null">{{ formatBs(lineCost(row)) }}</template>
              <span v-else class="fin-muted">—</span>
            </td>
            <td class="actions">
              <button class="fin-icon-btn danger" type="button" aria-label="Quitar fila" @click="removeRow(row)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6 6 18M6 6l12 12" /></svg>
              </button>
            </td>
          </tr>
          <tr v-if="!rows.length">
            <td colspan="4" class="fin-muted">Sin composición: el componente usa su costo cargado y no descuenta stock.</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="fin-row">
      <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" :disabled="!stockIngredients.length" @click="addRow">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="M12 5v14M5 12h14" /></svg>
        Agregar insumo
      </button>
      <span class="fin-spacer"></span>
      <strong class="fin-num">Costo {{ formatBs(rows.length ? total : manualCost) }} {{ UNIT_DISPLAY[component.unit]?.costLabel }}</strong>
    </div>
    <p v-if="rows.length" class="fin-hint">
      Hoy el costo cargado a mano es {{ formatBs(manualCost) }}; con la composición el costo sigue al costo promedio de los insumos.
    </p>
    <div v-if="missingCost.length" class="fin-note">
      Sin costo: {{ [...new Set(missingCost.map((r) => byId[r.ingredient_id].name))].join(', ') }}. Registra una compra o carga su costo en Inventario; mientras tanto las recetas que usan este componente quedan incompletas.
    </div>
    <div class="fin-note info">
      Al guardar, cada venta que use este componente descuenta estos insumos del inventario. Las ventas anteriores no se recalculan: lo descontado antes queda como se registró.
    </div>
    <div v-if="error" class="fin-note err" role="alert">{{ error }}</div>

    <template #footer>
      <button class="fin-btn fin-btn-secondary" type="button" :disabled="saving" @click="emit('close')">Cancelar</button>
      <button class="fin-btn fin-btn-primary" type="button" :disabled="saving" @click="save">{{ saving ? 'Guardando…' : 'Guardar composición' }}</button>
    </template>
  </FinanceModal>
</template>

<style scoped>
.fin-qty { display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end; }
.fin-qty input { width: 96px; text-align: right; }
.fin-recipe-table select { min-width: 220px; }
@media (max-width: 760px) {
  .fin-recipe-table select { min-width: 0; width: 100%; }
  .fin-qty { justify-content: flex-start; }
}
</style>
