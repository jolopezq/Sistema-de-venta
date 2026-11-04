<script setup>
/**
 * Asistencia → A revisar: marcas que no entraron solas y entradas sin salida (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.5).
 * Cada una se aprueba o se rechaza; la planilla del período no se aprueba mientras quede alguna.
 */
import { ref, onMounted } from 'vue';
import { apiFetch } from '../../services/api';
import { typeLabel, reviewReasons, formatScore } from '../../utils/attendance';
import MarkPhoto from './MarkPhoto.vue';

defineProps({ canEdit: { type: Boolean, default: false } });
const emit = defineEmits(['changed', 'toast']);

const pending = ref([]);
const withoutExit = ref([]);
const loading = ref(true);
const error = ref('');
const busy = ref(null);
const rejecting = ref(null);
const rejectReason = ref('');
const exitForms = ref({});

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const res = await apiFetch('/attendance/review');
    pending.value = res.pending;
    withoutExit.value = res.without_exit;
    exitForms.value = Object.fromEntries(res.without_exit.map((r) => [r.mark.id, { time: r.schedule_end || '', reason: '' }]));
    emit('changed', res.count);
  } catch (err) {
    error.value = err.message;
  } finally {
    loading.value = false;
  }
}

async function approve(mark, addNetwork = false) {
  busy.value = mark.id;
  try {
    const res = await apiFetch(`/attendance/marks/${mark.id}/approve`, {
      method: 'POST', body: JSON.stringify({ add_network: addNetwork }),
    });
    emit('toast', addNetwork && res.revalidated > 1
      ? `Red registrada. ${res.revalidated} marcas de esa red quedaron revisadas.`
      : 'Marca aprobada.');
    await load();
  } catch (err) {
    emit('toast', err.message, 'error');
  } finally {
    busy.value = null;
  }
}

async function reject(mark) {
  busy.value = mark.id;
  try {
    await apiFetch(`/attendance/marks/${mark.id}/reject`, { method: 'POST', body: JSON.stringify({ reason: rejectReason.value }) });
    rejecting.value = null;
    rejectReason.value = '';
    emit('toast', 'Marca rechazada. No cuenta para la planilla.');
    await load();
  } catch (err) {
    emit('toast', err.message, 'error');
  } finally {
    busy.value = null;
  }
}

async function saveExit(row) {
  const f = exitForms.value[row.mark.id];
  busy.value = row.mark.id;
  try {
    await apiFetch('/attendance/marks', {
      method: 'POST',
      body: JSON.stringify({ user_id: row.mark.user_id, type: 'out', marked_at: `${row.mark.date} ${f.time}`, reason: f.reason }),
    });
    emit('toast', `Salida de ${row.mark.user_name} guardada.`);
    await load();
  } catch (err) {
    emit('toast', err.message, 'error');
  } finally {
    busy.value = null;
  }
}

defineExpose({ load });
onMounted(load);
</script>

<template>
  <div class="fin-card">
    <div class="fin-card-head">
      <div>
        <h2>A revisar</h2>
        <p>Estas marcas no entraron solas. Mira la foto y decide. La planilla no se aprueba mientras quede alguna.</p>
      </div>
    </div>
    <div class="fin-card-body">
      <p v-if="loading" class="fin-muted">Cargando…</p>
      <p v-else-if="error" class="fin-error">{{ error }}</p>
      <p v-else-if="pending.length === 0 && withoutExit.length === 0" class="fin-empty">Todo revisado.</p>
      <div v-else class="att-review">
        <article v-for="m in pending" :key="m.id" class="att-item">
          <header>
            <span v-for="r in reviewReasons(m)" :key="r.id" class="fin-badge" :class="r.id === 'offline' ? 'info' : 'warn'">{{ r.label }}</span>
            <strong>{{ m.user_name }}</strong>
            <span class="fin-muted">{{ typeLabel(m.type) }} · {{ m.day_label }} · <span class="att-mono">{{ m.time }}</span></span>
          </header>
          <div class="att-item-body">
            <div class="att-photos">
              <MarkPhoto v-if="m.has_photo" :endpoint="`/attendance/marks/${m.id}/photo`" caption="Esta marca" :alt="`Foto de la marca de ${m.user_name}`" />
              <MarkPhoto :endpoint="`/attendance/users/${m.user_id}/face-photo`" caption="Registrada" :alt="`Rostro registrado de ${m.user_name}`" />
            </div>
            <ul class="att-facts">
              <li>Equipo: {{ m.device_ok ? 'autorizado' : 'no autorizado' }}</li>
              <li v-if="m.status !== 'offline_review'">Red: {{ m.network_ok ? 'del local' : 'nueva' }} <span class="att-mono fin-muted">({{ m.ip || 'sin IP' }})</span></li>
              <li>Rostro: {{ m.face_score === null ? 'sin rostro registrado' : formatScore(m.face_score) }}<template v-if="m.liveness_ok === false"> · sin parpadeo</template></li>
              <li v-if="m.status === 'offline_review'">Hora del equipo: <span class="att-mono">{{ m.time }}</span> · llegó: <span class="att-mono">{{ (m.received_at || '').slice(11, 16) }}</span></li>
            </ul>
          </div>
          <div v-if="canEdit && rejecting !== m.id" class="fin-row">
            <template v-if="!m.network_ok && m.status === 'pending_network'">
              <button type="button" class="fin-btn fin-btn-primary" :disabled="busy === m.id" @click="approve(m, true)">Es la red del local</button>
              <button type="button" class="fin-btn fin-btn-ghost" :disabled="busy === m.id" @click="approve(m)">Aprobar solo esta</button>
            </template>
            <button v-else type="button" class="fin-btn fin-btn-primary" :disabled="busy === m.id" @click="approve(m)">
              {{ reviewReasons(m).some(r => r.id === 'face') ? 'Es la persona' : 'Aprobar' }}
            </button>
            <button type="button" class="fin-btn fin-btn-ghost" @click="rejecting = m.id; rejectReason = ''">Rechazar</button>
          </div>
          <div v-else-if="canEdit" class="att-reject">
            <label :for="`rej-${m.id}`" class="fin-label">Motivo del rechazo (obligatorio)</label>
            <input :id="`rej-${m.id}`" v-model="rejectReason" class="fin-input" maxlength="255" placeholder="Ej.: no es la persona; la hora del equipo estaba mal" />
            <div class="fin-row">
              <button type="button" class="fin-btn fin-btn-danger" :disabled="busy === m.id || rejectReason.trim().length < 10" @click="reject(m)">Rechazar</button>
              <button type="button" class="fin-btn fin-btn-ghost" @click="rejecting = null">Cancelar</button>
            </div>
          </div>
        </article>

        <article v-for="row in withoutExit" :key="`sx-${row.mark.id}`" class="att-item">
          <header>
            <span class="fin-badge err">Sin salida</span>
            <strong>{{ row.mark.user_name }}</strong>
            <span class="fin-muted">{{ row.mark.day_label }} · entrada <span class="att-mono">{{ row.mark.time }}</span></span>
          </header>
          <p class="fin-small fin-muted">No marcó la salida. Hasta que pongas la hora, ese día cuenta 0 horas.</p>
          <div v-if="canEdit" class="att-exit">
            <div class="fin-field">
              <label :for="`sx-t-${row.mark.id}`">Hora de salida</label>
              <input :id="`sx-t-${row.mark.id}`" v-model="exitForms[row.mark.id].time" type="time" class="fin-input" />
            </div>
            <div class="fin-field att-grow">
              <label :for="`sx-r-${row.mark.id}`">Motivo (obligatorio)</label>
              <input :id="`sx-r-${row.mark.id}`" v-model="exitForms[row.mark.id].reason" class="fin-input" maxlength="255" placeholder="Ej.: se olvidó de marcar; cerró conmigo" />
            </div>
            <button type="button" class="fin-btn fin-btn-primary" :disabled="busy === row.mark.id || !exitForms[row.mark.id].time || exitForms[row.mark.id].reason.trim().length < 10" @click="saveExit(row)">
              Guardar salida
            </button>
          </div>
          <p v-if="canEdit && row.schedule_end" class="fin-hint">Sugerido: fin de su horario ({{ row.schedule_end }}).</p>
        </article>
      </div>
    </div>
  </div>
</template>

<style scoped>
.att-review { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 14px; }
.att-item { border: 1px solid var(--border); border-radius: 14px; padding: 14px; display: flex; flex-direction: column; gap: 10px; background: var(--surface); }
.att-item header { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13.5px; }
.att-item header strong { font-size: 15px; }
.att-item p { margin: 0; }
.att-item-body { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-start; }
.att-photos { display: flex; gap: 10px; }
.att-facts { list-style: none; margin: 0; padding: 0; font-size: 13px; display: flex; flex-direction: column; gap: 4px; color: var(--ink-700); flex: 1 1 160px; }
.att-mono { font-family: var(--font-mono); }
.att-reject { display: flex; flex-direction: column; gap: 6px; }
.att-exit { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
.att-grow { flex: 1 1 200px; }
@media (max-width: 760px) {
  .att-review { grid-template-columns: 1fr; }
}
</style>
