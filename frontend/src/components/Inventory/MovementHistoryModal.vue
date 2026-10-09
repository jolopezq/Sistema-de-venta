<script setup>
import { computed, onMounted, ref } from 'vue';
import { apiFetch } from '../../services/api';
import { formatBs, errorText } from '../../stores/finance';
import { MOVEMENT_TYPES, movementLabel, formatQty, formatSigned, formatDateTime } from '../../utils/inventoryLabels';
import FinanceModal from '../Finance/FinanceModal.vue';
import '../Finance/finance.css';

/** Historial de movimientos de un insumo (plan de inventario, F3, H18): del más reciente al más antiguo, paginado. */
const props = defineProps({
  ingredient: { type: Object, required: true },
});
const emit = defineEmits(['close']);

const rows = ref([]);
const page = ref(0);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(true);
const error = ref('');
const type = ref('');

const hasMore = computed(() => page.value < lastPage.value);

async function load(reset = false) {
  if (reset) { rows.value = []; page.value = 0; lastPage.value = 1; }
  loading.value = true;
  error.value = '';
  try {
    const q = new URLSearchParams({ page: String(page.value + 1), per_page: '25' });
    if (type.value) q.set('type', type.value);
    const res = await apiFetch(`/ingredients/${props.ingredient.id}/movements?${q}`);
    rows.value.push(...res.data);
    page.value = res.current_page;
    lastPage.value = res.last_page;
    total.value = res.total;
  } catch (err) {
    error.value = errorText(err, 'No se pudo cargar el historial.');
  } finally {
    loading.value = false;
  }
}
onMounted(() => load());

function detail(m) {
  const bits = [];
  if (m.type === 'waste' && m.waste_label) bits.push(m.waste_label);
  if (m.notes) bits.push(m.notes);
  return bits.join(' · ');
}
</script>

<template>
  <div class="fin-page mh-host">
    <FinanceModal wide :title="`Historial · ${ingredient.name}`" :subtitle="`Stock actual: ${formatQty(ingredient.current_stock)} ${ingredient.unit}`" @close="emit('close')">
      <div class="mh-filter">
        <label for="mh-type" class="fin-label">Mostrar</label>
        <select id="mh-type" v-model="type" class="fin-input fin-filter-select" @change="load(true)">
          <option value="">Todos los movimientos</option>
          <option v-for="(label, key) in MOVEMENT_TYPES" :key="key" :value="key">{{ label }}</option>
        </select>
        <span class="fin-small">{{ total }} movimientos</span>
      </div>

      <div class="fin-table-wrap">
        <table class="fin-table">
          <thead>
            <tr><th>Fecha</th><th>Tipo</th><th class="right">Cantidad</th><th class="right">Valor</th><th>Detalle</th><th>Quién</th></tr>
          </thead>
          <tbody>
            <tr v-for="m in rows" :key="m.id">
              <td>{{ formatDateTime(m.created_at) }}</td>
              <td><span class="fin-badge" :class="m.type === 'waste' ? 'warn' : m.type === 'sale' ? '' : 'info'">{{ movementLabel(m) }}</span></td>
              <td class="right fin-num" :class="Number(m.quantity_changed) < 0 ? 'mh-neg' : 'mh-pos'">{{ formatSigned(m.quantity_changed) }} {{ ingredient.unit }}</td>
              <td class="right fin-num">{{ m.total_cost !== null && m.total_cost !== undefined ? formatBs(m.total_cost) : '—' }}</td>
              <td class="mh-detail">{{ detail(m) }}</td>
              <td>{{ m.performed_by_user?.name || '—' }}</td>
            </tr>
            <tr v-if="!rows.length && !loading"><td colspan="6" class="fin-empty">Sin movimientos.</td></tr>
          </tbody>
        </table>
      </div>

      <div v-if="loading" class="fin-loading">Cargando…</div>
      <button v-else-if="hasMore" class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="load()">Cargar más</button>
      <div v-if="error" class="fin-note err" role="alert">{{ error }}</div>

      <template #footer>
        <span class="fin-spacer"></span>
        <button class="fin-btn fin-btn-secondary" type="button" @click="emit('close')">Cerrar</button>
      </template>
    </FinanceModal>
  </div>
</template>

<style scoped>
.mh-host { display: contents; }
.mh-filter { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.mh-neg { color: var(--danger-text); }
.mh-pos { color: var(--lime-700); }
.mh-detail { max-width: 280px; color: var(--ink-700); }
</style>
