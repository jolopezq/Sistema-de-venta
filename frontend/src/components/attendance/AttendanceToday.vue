<script setup>
/** Asistencia → Hoy: cada persona con sus marcas del día (solo administradores). */
import { ref, onMounted } from 'vue';
import { apiFetch } from '../../services/api';
import { typeLabel, STATUS_LABELS } from '../../utils/attendance';

defineProps({ canEdit: { type: Boolean, default: false } });
const emit = defineEmits(['add-mark', 'people']);

const people = ref([]);
const loading = ref(true);
const error = ref('');

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const res = await apiFetch('/attendance/today');
    people.value = res.people;
    emit('people', res.people);
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
        <h2>Hoy en el local</h2>
        <p>Entradas y salidas de hoy. Las marcas «a revisar» todavía no cuentan para la planilla.</p>
      </div>
      <button v-if="canEdit" type="button" class="fin-btn fin-btn-secondary" @click="emit('add-mark', {})">Agregar marca</button>
    </div>
    <div class="fin-card-body">
      <p v-if="loading" class="fin-muted">Cargando…</p>
      <p v-else-if="error" class="fin-error">{{ error }}</p>
      <p v-else-if="people.length === 0" class="fin-empty">
        Nadie marca asistencia todavía. Se activa por persona en Usuarios → «Trabajo y asistencia».
      </p>
      <div v-else class="att-today">
        <article v-for="p in people" :key="p.id" class="att-person">
          <header>
            <strong>{{ p.name }}</strong>
            <span class="fin-badge" :class="p.open ? 'ok' : ''">{{ p.open ? 'En turno' : 'Fuera de turno' }}</span>
          </header>
          <p class="fin-small fin-muted">{{ p.schedule ? `Horario de hoy: ${p.schedule.start} – ${p.schedule.end}` : 'Sin horario hoy' }}</p>
          <ul v-if="p.marks.length" class="att-marks">
            <li v-for="m in p.marks" :key="m.id">
              <span class="att-time">{{ m.time }}</span>
              <span>{{ typeLabel(m.type) }}</span>
              <span v-if="m.status !== 'valid'" class="fin-badge warn">{{ STATUS_LABELS[m.status] || m.status }}</span>
              <span v-if="m.source === 'manual'" class="fin-badge info">A mano</span>
            </li>
          </ul>
          <p v-else class="fin-small fin-muted">Todavía no marcó.</p>
          <button v-if="canEdit" type="button" class="fin-btn fin-btn-ghost fin-btn-sm" @click="emit('add-mark', { user_id: p.id, type: p.open ? 'out' : 'in' })">
            Agregar marca
          </button>
        </article>
      </div>
    </div>
  </div>
</template>

<style scoped>
.att-today { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
.att-person { border: 1px solid var(--border); border-radius: 14px; padding: 12px 14px; display: flex; flex-direction: column; gap: 8px; background: var(--surface); }
.att-person header { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.att-person header strong { font-size: 15px; }
.att-person p { margin: 0; }
.att-person .fin-btn { align-self: flex-start; }
.att-marks { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; font-size: 13.5px; }
.att-marks li { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.att-time { font-family: var(--font-mono); font-weight: 600; min-width: 44px; }
</style>
