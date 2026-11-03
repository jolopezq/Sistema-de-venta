<script setup>
/**
 * Asistencia → Equipos y redes: autorizar este equipo, redes del local y el ajuste de la caja
 * (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.2 y D4).
 */
import { ref, onMounted } from 'vue';
import { apiFetch } from '../../services/api';
import { deviceInfo, saveDevice, forgetDevice } from '../../services/attendanceKiosk';

defineProps({ canEdit: { type: Boolean, default: false } });
const emit = defineEmits(['toast']);

const data = ref(null);
const loading = ref(true);
const error = ref('');
const localDevice = ref(deviceInfo());
const deviceForm = ref({ name: 'Portátil de caja', add_network: true });
const networkForm = ref({ cidr: '', label: '' });
const busy = ref(false);
const errors = ref({});

async function load() {
  loading.value = true;
  error.value = '';
  try {
    data.value = await apiFetch('/attendance/setup');
    localDevice.value = deviceInfo();
  } catch (err) {
    error.value = err.message;
  } finally {
    loading.value = false;
  }
}

async function run(fn, okMessage) {
  busy.value = true;
  errors.value = {};
  try {
    await fn();
    if (okMessage) emit('toast', okMessage);
    await load();
  } catch (err) {
    errors.value = err.validationErrors || {};
    emit('toast', err.message, 'error');
  } finally {
    busy.value = false;
  }
}

const authorize = () => run(async () => {
  const res = await apiFetch('/attendance/devices', { method: 'POST', body: JSON.stringify(deviceForm.value) });
  saveDevice(res.token, res.device.name, res.device.id);
}, 'Equipo autorizado. Ya se puede abrir el reloj desde aquí.');

const revoke = (device) => run(async () => {
  await apiFetch(`/attendance/devices/${device.id}`, { method: 'DELETE' });
  if (localDevice.value?.id === device.id) forgetDevice();
}, `«${device.name}» ya no puede marcar asistencia.`);

function forgetHere() {
  forgetDevice();
  localDevice.value = null;
  emit('toast', 'Este navegador olvidó la llave. Para volver a usar el reloj, autorízalo de nuevo.');
}

const addCurrentNetwork = () => run(async () => {
  await apiFetch('/attendance/networks', { method: 'POST', body: JSON.stringify({ label: 'Red del local' }) });
}, 'La red actual quedó como red del local.');

const addNetwork = () => run(async () => {
  await apiFetch('/attendance/networks', { method: 'POST', body: JSON.stringify(networkForm.value) });
  networkForm.value = { cidr: '', label: '' };
}, 'Red agregada.');

const removeNetwork = (network) => run(async () => {
  await apiFetch(`/attendance/networks/${network.id}`, { method: 'DELETE' });
}, 'Red quitada.');

const toggleRequire = (event) => run(async () => {
  await apiFetch('/attendance/settings', {
    method: 'PUT', body: JSON.stringify({ require_entry_to_open_register: event.target.checked }),
  });
}, event.target.checked ? 'Desde ahora, abrir la caja pide haber marcado la entrada.' : 'Abrir la caja solo avisa si falta la entrada.');

onMounted(load);
</script>

<template>
  <p v-if="loading" class="fin-muted">Cargando…</p>
  <p v-else-if="error" class="fin-error">{{ error }}</p>
  <template v-else-if="data">
    <div class="fin-card">
      <div class="fin-card-head">
        <div>
          <h2>Este equipo</h2>
          <p>El reloj solo funciona en un equipo autorizado. Autorízalo desde el local, en la portátil de caja.</p>
        </div>
      </div>
      <div class="fin-card-body att-stack">
        <p class="att-line">
          Red de este equipo ahora: <span class="att-mono">{{ data.current.ip }}</span>
          <span class="fin-badge" :class="data.current.network_ok ? 'ok' : 'warn'">{{ data.current.network_ok ? 'Es una red del local' : 'No está en las redes del local' }}</span>
        </p>
        <div v-if="localDevice" class="fin-note info att-row">
          <span>Este navegador está autorizado como <strong>«{{ localDevice.name }}»</strong>.</span>
          <router-link to="/asistencia/reloj" class="fin-btn fin-btn-primary fin-btn-sm">Abrir el reloj</router-link>
          <button type="button" class="fin-btn fin-btn-ghost fin-btn-sm" @click="forgetHere">Olvidar en este equipo</button>
        </div>
        <form v-else-if="canEdit" class="att-row att-form" @submit.prevent="authorize">
          <div class="fin-field">
            <label for="dev-name">Nombre del equipo</label>
            <input id="dev-name" v-model="deviceForm.name" class="fin-input" maxlength="120" required />
            <span v-if="errors.name" class="fin-field-err">{{ errors.name[0] }}</span>
          </div>
          <label class="fin-check-inline att-check">
            <input v-model="deviceForm.add_network" type="checkbox" />
            Registrar también esta red como red del local
          </label>
          <button type="submit" class="fin-btn fin-btn-primary fin-btn-lg" :disabled="busy">Autorizar este equipo</button>
        </form>
        <p v-else class="fin-muted">Este navegador no está autorizado.</p>
      </div>
    </div>

    <div class="fin-card">
      <div class="fin-card-head">
        <div>
          <h2>Equipos autorizados</h2>
          <p>Si un equipo se pierde o se reemplaza, quítalo aquí: su llave deja de servir al instante.</p>
        </div>
      </div>
      <div class="fin-table-wrap">
        <table class="fin-table">
          <thead><tr><th scope="col">Equipo</th><th scope="col">Autorizado por</th><th scope="col">Visto por última vez</th><th v-if="canEdit" scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
          <tbody>
            <tr v-for="d in data.devices" :key="d.id">
              <td><strong>{{ d.name }}</strong><span v-if="localDevice?.id === d.id" class="fin-badge acai att-gap">Este equipo</span></td>
              <td>{{ d.authorized_by || '—' }} · {{ d.created_at }}</td>
              <td>{{ d.last_seen_at || '—' }} <span class="att-mono fin-muted">{{ d.last_ip }}</span></td>
              <td v-if="canEdit" class="actions"><button type="button" class="fin-btn fin-btn-ghost fin-btn-sm" :disabled="busy" @click="revoke(d)">Quitar</button></td>
            </tr>
            <tr v-if="data.devices.length === 0"><td :colspan="canEdit ? 4 : 3" class="fin-muted">Todavía no hay equipos autorizados.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="fin-card">
      <div class="fin-card-head">
        <div>
          <h2>Redes del local</h2>
          <p>Si el proveedor cambia la IP, las marcas quedan «Red nueva» y se confirman con un toque en «A revisar».</p>
        </div>
        <button v-if="canEdit && !data.current.network_ok" type="button" class="fin-btn fin-btn-secondary" :disabled="busy" @click="addCurrentNetwork">
          Usar la red actual
        </button>
      </div>
      <div class="fin-table-wrap">
        <table class="fin-table">
          <thead><tr><th scope="col">Nombre</th><th scope="col">Red</th><th scope="col">Agregada por</th><th v-if="canEdit" scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
          <tbody>
            <tr v-for="n in data.networks" :key="n.id">
              <td>{{ n.label }}</td>
              <td class="att-mono">{{ n.cidr }}</td>
              <td>{{ n.added_by || '—' }} · {{ n.created_at }}</td>
              <td v-if="canEdit" class="actions"><button type="button" class="fin-btn fin-btn-ghost fin-btn-sm" :disabled="busy" @click="removeNetwork(n)">Quitar</button></td>
            </tr>
            <tr v-if="data.networks.length === 0"><td :colspan="canEdit ? 4 : 3" class="fin-muted">Todavía no hay redes del local. Todas las marcas quedarán «Red nueva».</td></tr>
          </tbody>
        </table>
      </div>
      <form v-if="canEdit" class="fin-card-body att-row att-form" @submit.prevent="addNetwork">
        <div class="fin-field">
          <label for="net-cidr">Agregar una red a mano</label>
          <input id="net-cidr" v-model="networkForm.cidr" class="fin-input att-mono" placeholder="181.115.1.20 o 2800:cd0:1a2b:3c4d::/64" required />
          <span v-if="errors.cidr" class="fin-field-err">{{ errors.cidr[0] }}</span>
        </div>
        <div class="fin-field">
          <label for="net-label">Nombre</label>
          <input id="net-label" v-model="networkForm.label" class="fin-input" placeholder="Fibra del local" maxlength="120" />
        </div>
        <button type="submit" class="fin-btn fin-btn-secondary fin-btn-lg" :disabled="busy">Agregar</button>
      </form>
    </div>

    <div class="fin-card">
      <div class="fin-card-head">
        <div>
          <h2>Caja</h2>
          <p>Las primeras semanas, abrir la caja sin marcar la entrada solo avisa. Cuando todos se acostumbren, actívalo.</p>
        </div>
      </div>
      <div class="fin-card-body">
        <label class="fin-check-inline att-check">
          <input type="checkbox" :checked="data.settings.require_entry_to_open_register" :disabled="!canEdit || busy" @change="toggleRequire" />
          Exigir marcar la entrada para abrir la caja
        </label>
      </div>
    </div>
  </template>
</template>

<style scoped>
.att-stack { display: flex; flex-direction: column; gap: 12px; }
.att-line { margin: 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 13.5px; }
.att-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.att-form { align-items: flex-end; }
.att-form .fin-field { min-width: 220px; }
.att-check { display: inline-flex; gap: 8px; align-items: center; font-size: 13.5px; min-height: 44px; cursor: pointer; }
.att-check input { width: 18px; height: 18px; accent-color: var(--acai-700); }
.att-mono { font-family: var(--font-mono); }
.att-gap { margin-left: 8px; }
</style>
