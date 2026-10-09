<script setup>
import { computed, onMounted, ref } from 'vue';
import { apiFetch } from '../../services/api';
import { useAuthStore } from '../../stores/auth';
import { UNIT_DISPLAY, formatBs, errorText } from '../../stores/finance';
import { formatQty, formatDateTime } from '../../utils/inventoryLabels';
import FinanceModal from '../Finance/FinanceModal.vue';
import '../Finance/finance.css';

/**
 * Registrar una merma (plan de inventario, F3): lo que se cayó, venció o se usó sin venderse. La anota el cajero
 * desde Caja o el administrador desde Inventario; el motivo es obligatorio y el costo lo pone el sistema.
 * Se puede registrar varias seguidas: la ventana queda abierta con la lista de lo anotado hoy.
 */
const props = defineProps({
  ingredientId: { type: Number, default: null },
});
const emit = defineEmits(['close', 'saved']);

const auth = useAuthStore();
const seesCosts = computed(() => auth.user?.role !== 'cashier');

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const done = ref(null);
const categories = ref([]);
const ingredients = ref([]);
const today = ref([]);

const form = ref({ ingredient_id: props.ingredientId || '', amount: '', waste_category: '', notes: '' });
const search = ref('');

const selected = computed(() => ingredients.value.find((i) => i.id === Number(form.value.ingredient_id)) || null);
const display = computed(() => UNIT_DISPLAY[selected.value?.unit] || { short: selected.value?.unit || '', factor: 1 });
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  return q ? ingredients.value.filter((i) => i.name.toLowerCase().includes(q) || i.id === Number(form.value.ingredient_id)) : ingredients.value;
});

async function load() {
  try {
    const data = await apiFetch('/inventory/waste');
    categories.value = data.categories;
    ingredients.value = data.ingredients;
    today.value = data.today;
  } catch (err) {
    error.value = errorText(err, 'No se pudo cargar el formulario de mermas. Revisa la conexión.');
  } finally {
    loading.value = false;
  }
}
onMounted(load);

/** Lo anotado hoy, en la misma unidad en que se escribe (g y ml). */
function amountLabel(m) {
  const d = UNIT_DISPLAY[m.unit] || { short: m.unit, factor: 1 };
  return `${formatQty(m.quantity * d.factor)} ${d.short}`;
}

function validate() {
  if (!selected.value) return 'Elige el insumo.';
  if (!(Number(form.value.amount) > 0)) return 'Indica cuánto se perdió.';
  if (!form.value.waste_category) return 'Elige el motivo.';
  return '';
}

async function save() {
  error.value = validate();
  done.value = null;
  if (error.value) return;
  saving.value = true;
  try {
    const quantity = Number(form.value.amount) / display.value.factor;
    const res = await apiFetch('/inventory/waste', {
      method: 'POST',
      body: JSON.stringify({
        ingredient_id: selected.value.id,
        quantity: Number(quantity.toFixed(4)),
        waste_category: form.value.waste_category,
        notes: form.value.notes.trim() || null,
      }),
    });
    done.value = {
      text: `Registrado: ${formatQty(form.value.amount)} ${display.value.short} de ${selected.value.name}`,
      cost: res.movement.cost,
      warning: res.warning,
    };
    emit('saved', res);
    // Queda lista para otra merma, con el mismo motivo (suelen venir en tandas)
    form.value = { ingredient_id: '', amount: '', waste_category: form.value.waste_category, notes: '' };
    search.value = '';
    await load();
  } catch (err) {
    error.value = errorText(err, 'No se pudo registrar la merma.');
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <!-- Los estilos de Finanzas cuelgan de .fin-page; este contenedor no añade caja al diseño -->
  <div class="fin-page wm-host">
    <FinanceModal
      title="Registrar merma"
      subtitle="Lo que se cayó, se venció o se usó sin venderse. Queda anotado para que el dueño lo revise."
      :busy="saving"
      @close="emit('close')"
    >
      <div v-if="loading" class="fin-loading">Cargando…</div>
      <template v-else>
        <div v-if="done" class="fin-note info" role="status">
          {{ done.text }}<template v-if="seesCosts && done.cost !== null"> · valor {{ formatBs(done.cost) }}</template>.
          <div v-if="done.warning" class="wm-warning">{{ done.warning }}</div>
        </div>

        <div class="fin-field">
          <label for="wm-search">Insumo</label>
          <input id="wm-search" v-model="search" type="search" class="fin-input" placeholder="Buscar por nombre…" autocomplete="off" />
          <select id="wm-ingredient" v-model="form.ingredient_id" class="fin-input wm-select" aria-label="Insumo">
            <option value="">Elige un insumo…</option>
            <option v-for="i in filtered" :key="i.id" :value="i.id">{{ i.name }} ({{ i.unit }})</option>
          </select>
          <p v-if="selected" class="fin-hint">El sistema tiene {{ formatQty(selected.current_stock) }} {{ selected.unit }}.</p>
        </div>

        <div class="fin-field">
          <label for="wm-amount">Cantidad perdida<template v-if="selected"> ({{ display.short }})</template></label>
          <input id="wm-amount" v-model="form.amount" type="number" min="0" step="any" inputmode="decimal" class="fin-input" placeholder="0" />
        </div>

        <div class="fin-field">
          <span class="fin-label" id="wm-reason-label">Motivo</span>
          <div class="fin-chips" role="radiogroup" aria-labelledby="wm-reason-label">
            <button
              v-for="c in categories"
              :key="c.key"
              type="button"
              role="radio"
              class="fin-chip wm-chip"
              :class="{ active: form.waste_category === c.key }"
              :aria-checked="form.waste_category === c.key"
              @click="form.waste_category = c.key"
            >{{ c.label }}</button>
          </div>
        </div>

        <div class="fin-field">
          <label for="wm-notes">Nota (opcional)</label>
          <input id="wm-notes" v-model="form.notes" type="text" maxlength="500" class="fin-input" placeholder="Ej.: se cayó el balde al sacarlo del congelador" />
        </div>

        <div v-if="error" class="fin-note err" role="alert">{{ error }}</div>

        <div v-if="today.length" class="wm-today">
          <h3>Anotado hoy</h3>
          <ul>
            <li v-for="m in today" :key="m.id">
              <span class="wm-today-main"><strong>{{ amountLabel(m) }}</strong> de {{ m.ingredient }}</span>
              <span class="wm-today-sub">{{ m.category }} · {{ formatDateTime(m.created_at).slice(11) }}<template v-if="m.user"> · {{ m.user }}</template></span>
            </li>
          </ul>
        </div>
      </template>

      <template #footer>
        <span class="fin-spacer"></span>
        <button class="fin-btn fin-btn-secondary" type="button" :disabled="saving" @click="emit('close')">Cerrar</button>
        <button class="fin-btn fin-btn-primary" type="button" :disabled="saving || loading" @click="save">
          {{ saving ? 'Guardando…' : 'Registrar merma' }}
        </button>
      </template>
    </FinanceModal>
  </div>
</template>

<style scoped>
.wm-host { display: contents; }
.wm-select { margin-top: 6px; }
.wm-chip { padding: 8px 14px; font-size: 13px; }
.wm-warning { margin-top: 4px; }
.wm-today { border-top: 1px solid var(--border); padding-top: 12px; }
.wm-today h3 { margin: 0 0 6px; font-size: 13px; font-weight: 700; color: var(--ink-700); }
.wm-today ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; max-height: 160px; overflow-y: auto; }
.wm-today li { display: flex; flex-direction: column; gap: 1px; font-size: 13px; color: var(--ink-900); }
.wm-today-sub { font-size: 12px; color: var(--ink-500); }
</style>
