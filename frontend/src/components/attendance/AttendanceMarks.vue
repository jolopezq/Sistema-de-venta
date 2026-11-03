<script setup>
/** Asistencia → Todas las marcas: filtro por persona y fechas, y corregir con motivo. */
import { ref, onMounted } from 'vue';
import { apiFetch } from '../../services/api';
import { typeLabel, STATUS_LABELS } from '../../utils/attendance';

defineProps({
  canEdit: { type: Boolean, default: false },
  people: { type: Array, default: () => [] },
});
const emit = defineEmits(['add-mark']);

function iso(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
const now = new Date();
const filters = ref({ from: iso(new Date(now.getFullYear(), now.getMonth(), 1)), to: iso(now), user_id: '' });
const marks = ref([]);
const loading = ref(true);
const error = ref('');

const SOURCE = { kiosk: 'Reloj', offline: 'Sin internet', manual: 'A mano' };
const BADGE = { valid: 'ok', rejected: 'err', corrected: '' };

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const q = new URLSearchParams({ from: filters.value.from, to: filters.value.to });
    if (filters.value.user_id) q.set('user_id', filters.value.user_id);
    marks.value = (await apiFetch(`/attendance/marks?${q}`)).data;
  } catch (err) {
    error.value = err.message;
  } finally {
    loading.value = false;
  }
}

defineExpose({ load });
onMounted(load);
</script>

<template>
  <div class="fin-card">
    <div class="fin-card-head">
      <div>
        <h2>Todas las marcas</h2>
        <p>Una marca nunca se borra: una corrección crea otra y la original queda como «corregida».</p>
      </div>
      <button v-if="canEdit" type="button" class="fin-btn fin-btn-secondary" @click="emit('add-mark', {})">Agregar marca</button>
    </div>
    <div class="fin-card-body att-filters">
      <div class="fin-field">
        <label for="am-from">Desde</label>
        <input id="am-from" v-model="filters.from" type="date" class="fin-input" @change="load" />
      </div>
      <div class="fin-field">
        <label for="am-to">Hasta</label>
        <input id="am-to" v-model="filters.to" type="date" class="fin-input" @change="load" />
      </div>
      <div class="fin-field">
        <label for="am-user">Persona</label>
        <select id="am-user" v-model="filters.user_id" class="fin-input" @change="load">
          <option value="">Todas</option>
          <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </div>
    </div>
    <p v-if="loading" class="fin-muted att-pad">Cargando…</p>
    <p v-else-if="error" class="fin-error att-pad">{{ error }}</p>
    <div v-else class="fin-table-wrap">
      <table class="fin-table">
        <thead>
          <tr>
            <th scope="col">Día</th>
            <th scope="col">Hora</th>
            <th scope="col">Persona</th>
            <th scope="col">Tipo</th>
            <th scope="col">Estado</th>
            <th scope="col">Origen</th>
            <th scope="col">Motivo o nota</th>
            <th v-if="canEdit" scope="col"><span class="sr-only">Acciones</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="m in marks" :key="m.id" :class="{ inactive: ['rejected', 'corrected'].includes(m.status) }">
            <td>{{ m.day_label }}</td>
            <td class="att-mono">{{ m.time }}</td>
            <td>{{ m.user_name }}</td>
            <td>{{ typeLabel(m.type) }}</td>
            <td><span class="fin-badge" :class="BADGE[m.status] ?? 'warn'">{{ STATUS_LABELS[m.status] || m.status }}</span></td>
            <td>{{ SOURCE[m.source] || m.source }}<template v-if="m.creator_name"> · {{ m.creator_name }}</template></td>
            <td class="att-note">{{ m.reason || m.review_note || '' }}</td>
            <td v-if="canEdit" class="actions">
              <button
                v-if="!['rejected', 'corrected'].includes(m.status)"
                type="button"
                class="fin-btn fin-btn-ghost fin-btn-sm"
                @click="emit('add-mark', { user_id: m.user_id, type: m.type, date: m.date, time: m.time, corrects: m })"
              >Corregir</button>
            </td>
          </tr>
          <tr v-if="marks.length === 0"><td :colspan="canEdit ? 8 : 7" class="fin-muted">No hay marcas en esas fechas.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.att-filters { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
.att-filters .fin-field { width: 170px; }
.att-mono { font-family: var(--font-mono); }
.att-note { max-width: 320px; font-size: 12.5px; color: var(--ink-700); }
.att-pad { padding: 0 18px 16px; }
</style>
