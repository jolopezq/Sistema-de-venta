<script setup>
/**
 * Reloj de asistencia (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.3). Pantalla completa en el equipo autorizado:
 * elegir persona → PIN → cámara (rostro y foto) → listo. No cambia la sesión del POS.
 * El trabajador ve solo sus horas de entrada y salida (§5.8): nunca montos, porcentajes ni motivos de revisión.
 */
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { landingFor } from '../router';
import {
  deviceInfo, fetchRoster, cachedRoster, checkPin, sendMark, fetchMyMarks, queueMark, queuedTypesFor,
  flushQueue, queueSummary, clearFailed, OfflineError, newUuid,
} from '../services/attendanceKiosk';
import { faceEngine } from '../services/faceEngine';
import { pinIsValid, offlineNextType, workerMessage, doneTitle, typeLabel } from '../utils/attendance';

const router = useRouter();
const auth = useAuthStore();

const STEPS = [
  { id: 'pick', label: 'Quién eres' },
  { id: 'pin', label: 'PIN' },
  { id: 'camera', label: 'Rostro' },
  { id: 'done', label: 'Listo' },
];
const DAYS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const ENGINE_TIMEOUT_MS = 20000;

const device = ref(deviceInfo());
const step = ref('pick');
const roster = ref(null);
const loadingRoster = ref(false);
const rosterError = ref('');
const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
const queue = ref({ pending: 0, failed: 0 });
const now = ref(new Date());

const selected = ref(null);
const pin = ref('');
const pinError = ref('');
const pinBusy = ref(false);
const nextType = ref('in');
const offlineMode = ref(false);

const videoEl = ref(null);
const cameraError = ref('');
const engineState = ref('idle'); // idle | loading | ready | manual
const analysis = ref({ faces: 0, blink: false, descriptor: null, liveness: null });
const photo = ref(null);
const descriptor = ref(null);
const liveness = ref(null);
const sending = ref(false);
const sendError = ref('');

const result = ref(null);
const history = ref([]);
const historyState = ref('idle'); // idle | loading | ready | offline | error
const historyError = ref('');

let stream = null;
let analyzing = false;
let engineTimer = null;
let idleTimer = null;
let clockTimer = null;
let rosterTimer = null;

const people = computed(() => roster.value?.people ?? []);
const stepIndex = computed(() => (step.value === 'history' ? STEPS.length : STEPS.findIndex((s) => s.id === step.value)));
const stepsView = computed(() => STEPS.map((s, i) => ({
  ...s, n: i + 1, done: i < stepIndex.value, current: i === stepIndex.value,
})));
const clockText = computed(() => `${String(now.value.getHours()).padStart(2, '0')}:${String(now.value.getMinutes()).padStart(2, '0')}`);
const dateText = computed(() => `${DAYS[now.value.getDay()]} ${now.value.getDate()} de ${MONTHS[now.value.getMonth()]}`);
const networkChip = computed(() => {
  if (!online.value) return { tone: 'off', label: 'Sin internet' };
  if (roster.value && roster.value.network_ok === false) return { tone: 'warn', label: 'Red nueva' };
  return { tone: 'ok', label: 'Red del local' };
});
const actionLabel = computed(() => typeLabel(nextType.value));
const pinDots = computed(() => Array.from({ length: Math.max(4, pin.value.length) }, (_, i) => i < pin.value.length));
const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'del', '0', 'ok'];

const checks = computed(() => {
  const a = analysis.value;
  const faceOk = a.faces === 1;
  return [
    {
      id: 'face',
      state: faceOk ? 'ok' : (a.faces > 1 ? 'warn' : 'pending'),
      label: faceOk ? 'Hay una sola cara frente a la cámara' : (a.faces > 1 ? 'Hay más de una cara: que quede solo la tuya' : 'Buscando tu rostro…'),
    },
    {
      id: 'blink',
      state: a.blink ? 'ok' : (faceOk ? 'pending' : 'todo'),
      label: a.blink ? 'Parpadeo detectado' : 'Parpadea una vez',
    },
    {
      id: 'read',
      state: a.descriptor ? 'ok' : (faceOk && a.blink ? 'pending' : 'todo'),
      label: a.descriptor ? 'Rostro leído' : 'Leyendo tu rostro',
    },
  ];
});
const canConfirm = computed(() => Boolean(photo.value) && !sending.value);
const cameraCaption = computed(() => {
  if (cameraError.value) return 'Sin cámara';
  if (photo.value) return 'Listo';
  if (engineState.value === 'loading') return 'Preparando la cámara…';
  if (engineState.value === 'manual') return 'Mira a la cámara';
  if (analysis.value.faces !== 1) return 'Mira a la cámara';
  if (!analysis.value.blink) return 'Parpadea una vez';
  return 'Quieto un momento…';
});

function initials(name) {
  return (name || '?').split(/\s+/).slice(0, 2).map((p) => p[0]?.toUpperCase() ?? '').join('') || '?';
}

function armIdle(ms) {
  clearTimeout(idleTimer);
  idleTimer = setTimeout(() => reset(), ms);
}

async function refreshQueue() {
  try {
    queue.value = await queueSummary();
  } catch {
    // IndexedDB no disponible: sin cola
  }
}

async function loadRoster() {
  if (!device.value) return;
  loadingRoster.value = !roster.value;
  rosterError.value = '';
  try {
    roster.value = await fetchRoster();
    online.value = true;
  } catch (err) {
    if (err instanceof OfflineError) {
      online.value = false;
      roster.value = roster.value ?? cachedRoster();
      if (!roster.value) rosterError.value = 'Sin internet y sin la lista guardada en este equipo.';
    } else {
      rosterError.value = err.message;
      if (err.reason === 'device_not_authorized') device.value = null;
    }
  } finally {
    loadingRoster.value = false;
  }
}

function choose(person) {
  selected.value = person;
  pin.value = '';
  pinError.value = '';
  nextType.value = person.next;
  step.value = 'pin';
  armIdle(60000);
}

function press(key) {
  armIdle(60000);
  pinError.value = '';
  if (key === 'del') {
    pin.value = pin.value.slice(0, -1);
  } else if (key === 'ok') {
    submitPin();
  } else if (pin.value.length < 6) {
    pin.value += key;
  }
}

function onKeydown(event) {
  if (step.value !== 'pin') return;
  if (/^\d$/.test(event.key)) press(event.key);
  else if (event.key === 'Backspace') press('del');
  else if (event.key === 'Enter') press('ok');
}

async function submitPin() {
  if (!pinIsValid(pin.value)) {
    pinError.value = 'El PIN tiene de 4 a 6 dígitos.';
    return;
  }
  pinBusy.value = true;
  offlineMode.value = false;
  try {
    const res = await checkPin(selected.value.id, pin.value);
    nextType.value = res.next;
    online.value = true;
  } catch (err) {
    if (err instanceof OfflineError) {
      // Sin conexión el PIN se comprueba al enviar la marca
      online.value = false;
      offlineMode.value = true;
      nextType.value = offlineNextType(selected.value.next, await queuedTypesFor(selected.value.id).catch(() => []));
    } else {
      pinError.value = err.message;
      pin.value = '';
      return;
    }
  } finally {
    pinBusy.value = false;
  }
  await openCamera();
}

async function openCamera() {
  step.value = 'camera';
  photo.value = null;
  descriptor.value = null;
  liveness.value = null;
  cameraError.value = '';
  sendError.value = '';
  analysis.value = { faces: 0, blink: false, descriptor: null, liveness: null };
  armIdle(90000);
  await nextTick();
  try {
    stream = await navigator.mediaDevices.getUserMedia({
      audio: false,
      video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
    });
    videoEl.value.srcObject = stream;
    await videoEl.value.play();
  } catch {
    cameraError.value = 'No se pudo abrir la cámara. Revisa que esté conectada y que el navegador tenga permiso.';
    return;
  }
  engineState.value = 'loading';
  faceEngine.reset();
  const ready = await faceEngine.load().catch(() => false);
  if (step.value !== 'camera') return;
  if (!ready) {
    engineState.value = 'manual';
    return;
  }
  engineState.value = 'ready';
  clearTimeout(engineTimer);
  engineTimer = setTimeout(() => {
    // La cámara o la luz no ayudan: se toma la foto igual y la marca queda para revisar
    if (step.value === 'camera' && !photo.value) engineState.value = 'manual';
  }, ENGINE_TIMEOUT_MS);
  analyzeLoop();
}

async function analyzeLoop() {
  if (analyzing) return;
  analyzing = true;
  while (step.value === 'camera' && engineState.value === 'ready' && !photo.value && videoEl.value) {
    try {
      const res = await faceEngine.analyze(videoEl.value);
      analysis.value = {
        faces: res.faces,
        blink: analysis.value.blink || res.blink,
        descriptor: res.descriptor,
        liveness: res.liveness,
      };
      if (res.ready) {
        descriptor.value = res.descriptor;
        liveness.value = res.liveness ?? true;
        capturePhoto();
        break;
      }
    } catch {
      engineState.value = 'manual';
      break;
    }
    await new Promise((r) => setTimeout(r, 120));
  }
  analyzing = false;
}

function capturePhoto() {
  const video = videoEl.value;
  if (!video || !video.videoWidth) return;
  const width = Math.min(640, video.videoWidth);
  const height = Math.round((video.videoHeight / video.videoWidth) * width);
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  canvas.getContext('2d').drawImage(video, 0, 0, width, height);
  photo.value = canvas.toDataURL('image/jpeg', 0.8);
  if (engineState.value === 'manual') {
    // Foto a mano: sin descriptor ni parpadeo. Si la persona tiene rostro registrado, la marca se revisa.
    descriptor.value = analysis.value.descriptor;
    liveness.value = analysis.value.descriptor ? analysis.value.blink : null;
  }
  clearTimeout(engineTimer);
}

function retakePhoto() {
  photo.value = null;
  if (engineState.value === 'ready') analyzeLoop();
}

function stopCamera() {
  clearTimeout(engineTimer);
  if (stream) {
    stream.getTracks().forEach((t) => t.stop());
    stream = null;
  }
}

async function confirmMark() {
  if (!canConfirm.value) return;
  sending.value = true;
  sendError.value = '';
  const payload = {
    user_id: selected.value.id,
    pin: pin.value,
    photo: photo.value,
    descriptor: descriptor.value,
    liveness_ok: liveness.value,
    local_uuid: newUuid(),
    device_time: new Date().toISOString(),
  };
  try {
    if (offlineMode.value || !navigator.onLine) throw new OfflineError('Sin conexión');
    const res = await sendMark(payload);
    const msg = workerMessage({ status: res.status });
    result.value = { type: res.type, time: res.time, entry: res.entry_time, ...msg, title: doneTitle(res.type, msg.tone) };
    loadRoster();
  } catch (err) {
    if (err instanceof OfflineError) {
      const queued = await queueMark({ ...payload, type: nextType.value });
      const msg = workerMessage({ offline: true });
      const t = new Date(queued.device_time);
      result.value = {
        type: nextType.value,
        time: `${String(t.getHours()).padStart(2, '0')}:${String(t.getMinutes()).padStart(2, '0')}`,
        entry: null,
        ...msg,
        title: doneTitle(nextType.value, msg.tone),
      };
      online.value = false;
      refreshQueue();
    } else {
      sendError.value = err.message;
      sending.value = false;
      if (err.status === 422 || err.status === 429) {
        // PIN incorrecto o bloqueado: volver al PIN
        stopCamera();
        pin.value = '';
        pinError.value = err.message;
        step.value = 'pin';
      }
      return;
    }
  }
  sending.value = false;
  stopCamera();
  step.value = 'done';
  armIdle(9000);
}

async function showHistory() {
  step.value = 'history';
  history.value = [];
  historyError.value = '';
  historyState.value = 'loading';
  armIdle(20000);
  try {
    const res = await fetchMyMarks(selected.value.id, pin.value);
    history.value = res.days;
    historyState.value = 'ready';
  } catch (err) {
    historyState.value = err instanceof OfflineError ? 'offline' : 'error';
    historyError.value = err.message;
  }
}

function reset() {
  clearTimeout(idleTimer);
  stopCamera();
  faceEngine.reset();
  step.value = 'pick';
  selected.value = null;
  pin.value = '';
  pinError.value = '';
  photo.value = null;
  result.value = null;
  history.value = [];
  sending.value = false;
  offlineMode.value = false;
  engineState.value = 'idle';
}

function closeKiosk() {
  reset();
  router.push(auth.isAuthenticated ? landingFor(auth) : '/login');
}

async function onOnline() {
  online.value = true;
  await flushQueue().catch(() => {});
  await refreshQueue();
  loadRoster();
}
function onOffline() {
  online.value = false;
}

async function dismissFailed() {
  await clearFailed();
  refreshQueue();
}

onMounted(async () => {
  clockTimer = setInterval(() => { now.value = new Date(); }, 10000);
  rosterTimer = setInterval(() => { if (step.value === 'pick') loadRoster(); }, 60000);
  window.addEventListener('online', onOnline);
  window.addEventListener('offline', onOffline);
  window.addEventListener('keydown', onKeydown);
  await loadRoster();
  if (online.value) await flushQueue().catch(() => {});
  refreshQueue();
});

onBeforeUnmount(() => {
  clearInterval(clockTimer);
  clearInterval(rosterTimer);
  clearTimeout(idleTimer);
  stopCamera();
  window.removeEventListener('online', onOnline);
  window.removeEventListener('offline', onOffline);
  window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
  <div class="kiosk">
    <header class="kiosk-head">
      <div class="kiosk-brand">
        <img src="/logo.png" alt="" class="kiosk-logo" />
        <div>
          <div class="kiosk-title">Reloj de asistencia</div>
          <div class="kiosk-date">{{ dateText }}</div>
        </div>
      </div>
      <div class="kiosk-clock" aria-label="Hora actual">{{ clockText }}</div>
      <div class="kiosk-chips">
        <span v-if="device" class="chip chip-ok">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>{{ device.name || 'Equipo autorizado' }}
        </span>
        <span class="chip" :class="`chip-${networkChip.tone}`">
          <svg v-if="networkChip.tone === 'ok'" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
          <svg v-else-if="networkChip.tone === 'warn'" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4M12 17h.01" /><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /></svg>
          <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M2 2l20 20M8.5 16.5a5 5 0 0 1 7 0M12 20h.01" /></svg>
          {{ networkChip.label }}
        </span>
        <span v-if="queue.pending" class="chip chip-off">{{ queue.pending }} {{ queue.pending === 1 ? 'marca' : 'marcas' }} por enviar</span>
        <button type="button" class="kiosk-close" @click="closeKiosk">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>Cerrar
        </button>
      </div>
    </header>

    <main class="kiosk-main">
      <section class="kiosk-card">
        <!-- Equipo no autorizado -->
        <div v-if="!device" class="kiosk-empty">
          <h1>Este equipo no está autorizado</h1>
          <p>Un administrador lo autoriza en <strong>Asistencia → Equipos y redes</strong>, desde el local.</p>
          <button type="button" class="k-btn k-btn-ghost" @click="closeKiosk">Volver</button>
        </div>

        <template v-else>
          <ol class="k-steps" aria-label="Pasos">
            <li v-for="s in stepsView" :key="s.id" :class="{ done: s.done, current: s.current }">
              <span class="k-step-n">
                <svg v-if="s.done" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
                <template v-else>{{ s.n }}</template>
              </span>
              <span class="k-step-label">{{ s.label }}</span>
            </li>
          </ol>

          <!-- 1 · Quién eres -->
          <div v-if="step === 'pick'" class="k-pick">
            <div>
              <h1 class="k-h1">¿Quién va a marcar?</h1>
              <p class="k-sub">Toca tu nombre. La sesión del POS no cambia.</p>
            </div>
            <p v-if="loadingRoster" class="k-sub">Cargando…</p>
            <p v-else-if="rosterError" class="k-error" role="alert">{{ rosterError }}</p>
            <p v-else-if="people.length === 0" class="k-sub">Todavía no hay nadie que marque asistencia. Se agrega en Usuarios → Trabajo y asistencia.</p>
            <div v-else class="k-people">
              <button v-for="p in people" :key="p.id" type="button" class="k-person" @click="choose(p)">
                <span class="k-avatar">{{ p.initials || initials(p.name) }}</span>
                <span class="k-person-info">
                  <span class="k-person-name">{{ p.name }}</span>
                  <span class="k-person-status">{{ p.next === 'out' ? 'En turno' : 'Fuera de turno' }}</span>
                </span>
                <span class="k-action" :class="p.next === 'out' ? 'k-action-out' : 'k-action-in'">{{ p.next === 'out' ? 'Marcar salida' : 'Marcar entrada' }}</span>
              </button>
            </div>
            <p class="k-hint">Aquí no se ven horas ni montos. Cada persona ve sus horas de entrada y salida después de poner su PIN.</p>
            <p v-if="queue.failed" class="k-error" role="alert">
              {{ queue.failed }} {{ queue.failed === 1 ? 'marca sin conexión no se pudo enviar' : 'marcas sin conexión no se pudieron enviar' }} (PIN incorrecto). Avísale a un administrador.
              <button type="button" class="k-link" @click="dismissFailed">Entendido</button>
            </p>
          </div>

          <!-- 2 · PIN -->
          <div v-else-if="step === 'pin'" class="k-split">
            <div class="k-split-side">
              <div class="k-who">
                <span class="k-avatar">{{ selected.initials || initials(selected.name) }}</span>
                <div>
                  <div class="k-who-name">{{ selected.name }}</div>
                  <div class="k-who-action">Va a marcar: <strong>{{ actionLabel }}</strong></div>
                </div>
              </div>
              <p class="k-text">El PIN es personal y distinto de la contraseña. Después de 5 intentos fallidos, la persona queda bloqueada 15 minutos en este equipo.</p>
              <button type="button" class="k-btn k-btn-ghost" @click="reset">← No soy yo</button>
            </div>
            <div class="k-pad-wrap">
              <h2 class="k-h2">Escribe tu PIN</h2>
              <div class="k-dots" role="status" :aria-label="`${pin.length} dígitos escritos`">
                <span v-for="(filled, i) in pinDots" :key="i" class="k-dot" :class="{ filled }"></span>
              </div>
              <p v-if="pinError" class="k-error" role="alert">{{ pinError }}</p>
              <div class="k-pad">
                <template v-for="k in keys" :key="k">
                  <button v-if="k === 'del'" type="button" class="k-key k-key-del" aria-label="Borrar un dígito" @click="press('del')">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z" /><path d="m18 9-6 6M12 9l6 6" /></svg>
                  </button>
                  <button v-else-if="k === 'ok'" type="button" class="k-key k-key-ok" :disabled="pin.length < 4 || pinBusy" @click="press('ok')">
                    {{ pinBusy ? '…' : 'Seguir' }}
                  </button>
                  <button v-else type="button" class="k-key" @click="press(k)">{{ k }}</button>
                </template>
              </div>
            </div>
          </div>

          <!-- 3 · Rostro -->
          <div v-else-if="step === 'camera'" class="k-split">
            <div class="k-cam" :class="{ found: analysis.faces === 1 }">
              <video ref="videoEl" class="k-video" :class="{ hidden: Boolean(photo) }" playsinline muted></video>
              <img v-if="photo" :src="photo" alt="Foto de esta marca" class="k-video" />
              <svg class="k-oval" viewBox="0 0 400 300" aria-hidden="true">
                <ellipse cx="200" cy="140" rx="86" ry="110" />
              </svg>
              <span class="k-cam-tag"><span class="k-rec"></span>Cámara</span>
              <span class="k-cam-caption">{{ cameraCaption }}</span>
            </div>
            <div class="k-split-side">
              <div class="k-who">
                <span class="k-avatar k-avatar-sm">{{ selected.initials || initials(selected.name) }}</span>
                <div>
                  <div class="k-who-name">{{ selected.name }}</div>
                  <div class="k-who-action">{{ actionLabel }}</div>
                </div>
              </div>
              <p v-if="cameraError" class="k-error" role="alert">{{ cameraError }}</p>
              <ul v-else-if="engineState === 'ready' || (engineState === 'manual' && analysis.faces)" class="k-checks">
                <li v-for="c in checks" :key="c.id" :class="`is-${c.state}`">
                  <span class="k-check-icon">
                    <svg v-if="c.state === 'ok'" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
                    <template v-else-if="c.state === 'warn'">!</template>
                  </span>
                  {{ c.label }}
                </li>
              </ul>
              <p v-else-if="engineState === 'manual'" class="k-text">Mira a la cámara y toca «Tomar foto».</p>
              <p v-else class="k-text">Preparando la cámara…</p>
              <p class="k-hint">Se guarda una foto de esta marca. Solo la ven los administradores, y se borra a los 90 días.</p>
              <p v-if="sendError" class="k-error" role="alert">{{ sendError }}</p>
              <div class="k-row">
                <button type="button" class="k-btn k-btn-ghost" @click="reset">Cancelar</button>
                <button v-if="cameraError" type="button" class="k-btn k-btn-ghost" @click="openCamera">Reintentar</button>
                <button v-else-if="engineState === 'manual' && !photo" type="button" class="k-btn k-btn-primary k-grow" @click="capturePhoto">Tomar foto</button>
                <template v-else-if="photo">
                  <button type="button" class="k-btn k-btn-ghost" :disabled="sending" @click="retakePhoto">Otra foto</button>
                  <button type="button" class="k-btn k-btn-primary k-grow" :disabled="!canConfirm" @click="confirmMark">
                    {{ sending ? 'Guardando…' : `Confirmar ${actionLabel.toLowerCase()}` }}
                  </button>
                </template>
                <button v-else type="button" class="k-btn k-btn-primary k-grow" disabled>Validando…</button>
              </div>
            </div>
          </div>

          <!-- 4 · Listo -->
          <div v-else-if="step === 'done' && result" class="k-done">
            <span class="k-done-icon" :class="result.tone === 'ok' ? 'is-ok' : 'is-pending'">
              <svg v-if="result.tone === 'ok'" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
              <svg v-else viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
            </span>
            <h1 class="k-done-title">{{ result.title }}</h1>
            <div class="k-done-time">{{ result.time }}</div>
            <div class="k-done-detail">
              {{ selected.name }} ·
              <template v-if="result.type === 'out' && result.entry">Turno de hoy: {{ result.entry }} → {{ result.time }}</template>
              <template v-else-if="result.type === 'out'">¡Hasta mañana!</template>
              <template v-else>¡Buen turno!</template>
            </div>
            <p class="k-done-msg" :class="result.tone === 'ok' ? 'is-ok' : 'is-pending'">{{ result.text }}</p>
            <div class="k-row k-row-center">
              <button type="button" class="k-btn k-btn-ghost" @click="showHistory">Ver mis marcas</button>
              <button type="button" class="k-btn k-btn-acai" @click="reset">Listo</button>
            </div>
            <span class="k-hint">Vuelve al inicio en unos segundos.</span>
          </div>

          <!-- Mis marcas -->
          <div v-else-if="step === 'history'" class="k-history">
            <div class="k-history-head">
              <div class="k-who">
                <span class="k-avatar k-avatar-sm">{{ selected.initials || initials(selected.name) }}</span>
                <div>
                  <h1 class="k-h1">Mis marcas</h1>
                  <div class="k-who-action">{{ selected.name }} · últimas marcas</div>
                </div>
              </div>
              <button type="button" class="k-btn k-btn-acai" @click="reset">Cerrar</button>
            </div>
            <p v-if="historyState === 'loading'" class="k-sub">Cargando…</p>
            <p v-else-if="historyState === 'offline'" class="k-sub">Sin internet: tus marcas se pueden ver cuando vuelva la conexión.</p>
            <p v-else-if="historyState === 'error'" class="k-error" role="alert">{{ historyError }}</p>
            <div v-else class="k-table-wrap">
              <table class="k-table">
                <thead>
                  <tr><th scope="col">Día</th><th scope="col">Entrada</th><th scope="col">Salida</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(d, i) in history" :key="`${d.date}-${i}`">
                    <td>{{ d.label }}</td>
                    <td class="mono">{{ d.in || '—' }}</td>
                    <td class="mono">{{ d.out || '—' }}</td>
                  </tr>
                  <tr v-if="history.length === 0"><td colspan="3">Todavía no hay marcas.</td></tr>
                </tbody>
              </table>
            </div>
            <p class="k-hint">Solo se muestran tus horas de entrada y salida. Esta pantalla se cierra sola en 20 segundos.</p>
          </div>
        </template>
      </section>
    </main>
  </div>
</template>

<style scoped>
.kiosk {
  min-height: 100vh;
  min-height: 100dvh;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  background: var(--acai-900);
  font-family: var(--font-sans);
  color: var(--ink);
}
.kiosk-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px 20px;
  flex-wrap: wrap;
  padding: 12px 20px;
  color: #F4EEF5;
}
.kiosk-brand { display: flex; align-items: center; gap: 12px; }
.kiosk-logo { width: 38px; height: 38px; border-radius: 10px; background: #FFFFFF; padding: 3px; box-sizing: border-box; object-fit: contain; }
.kiosk-title { font-family: var(--font-heading); font-weight: 700; font-size: 20px; line-height: 1.1; }
.kiosk-date { font-size: 12.5px; color: #CDB8D0; }
.kiosk-clock { font-family: var(--font-mono); font-weight: 600; font-size: 30px; color: #FFFFFF; letter-spacing: 0.02em; }
.kiosk-chips { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.chip {
  display: inline-flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 999px;
  font-size: 12.5px; font-weight: 600; border: 1px solid transparent;
}
.chip svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round; }
.chip-ok { background: rgba(60,174,73,0.18); border-color: rgba(155,227,164,0.45); color: #C8F2CD; }
.chip-warn { background: rgba(217,164,4,0.20); border-color: rgba(250,209,92,0.55); color: #FAD15C; }
.chip-off { background: rgba(255,255,255,0.10); border-color: rgba(255,255,255,0.25); color: #E6DCE8; }
.kiosk-close {
  display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 12px; border-radius: 10px;
  border: 1px solid rgba(255,255,255,0.25); background: transparent; color: #F4EEF5; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
}
.kiosk-close svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; }
.kiosk-close:hover { color: #FFFFFF; border-color: rgba(255,255,255,0.5); }
.kiosk-main { flex: 1; display: flex; justify-content: center; align-items: flex-start; padding: 4px 16px 16px; }
.kiosk-card {
  width: 100%; max-width: 1000px; box-sizing: border-box; background: var(--surface); border-radius: var(--radius-lg);
  padding: 18px 26px 22px; box-shadow: 0 20px 60px rgba(0,0,0,0.35); display: flex; flex-direction: column; gap: 16px;
}
.kiosk-empty { display: flex; flex-direction: column; align-items: flex-start; gap: 10px; padding: 10px 0; }
.kiosk-empty h1 { margin: 0; font-family: var(--font-heading); font-size: 26px; }
.kiosk-empty p { margin: 0; color: var(--ink-700); }

.k-steps { list-style: none; margin: 0; padding: 0; display: flex; gap: 8px; flex-wrap: wrap; }
.k-steps li { display: flex; align-items: center; gap: 8px; flex: 1 1 140px; font-size: 13px; color: var(--ink-500); font-weight: 500; }
.k-steps li::after { content: ''; flex: 1; height: 2px; background: var(--surface-hover); min-width: 12px; }
.k-step-n {
  width: 26px; height: 26px; border-radius: 50%; border: 2px solid var(--border); box-sizing: border-box;
  display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; flex-shrink: 0;
}
.k-step-n svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }
.k-steps li.done { color: var(--lime-700); font-weight: 600; }
.k-steps li.done .k-step-n { background: var(--lime-700); border-color: var(--lime-700); color: #FFFFFF; }
.k-steps li.current { color: var(--ink); font-weight: 700; }
.k-steps li.current .k-step-n { background: var(--passion-500); border-color: var(--passion-500); color: var(--on-accent); }

.k-h1 { margin: 0; font-family: var(--font-heading); font-weight: 700; font-size: 26px; line-height: 1.1; }
.k-h2 { margin: 0; font-family: var(--font-heading); font-weight: 700; font-size: 22px; }
.k-sub { margin: 4px 0 0; font-size: 14px; color: var(--ink-500); }
.k-text { margin: 0; font-size: 14px; line-height: 1.5; color: var(--ink-700); }
.k-hint { margin: 0; font-size: 12.5px; line-height: 1.45; color: var(--ink-500); }
.k-error { margin: 0; font-size: 13.5px; font-weight: 600; color: var(--danger-text); }
.k-link { border: none; background: none; color: var(--passion-text); font: inherit; font-weight: 700; cursor: pointer; text-decoration: underline; }

.k-pick { display: flex; flex-direction: column; gap: 14px; }
.k-people { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; }
.k-person {
  text-align: left; cursor: pointer; font: inherit; color: inherit; background: var(--cream-50);
  border: 2px solid var(--border); border-radius: 18px; padding: 16px; display: flex; gap: 14px; align-items: center;
}
.k-person:hover, .k-person:focus-visible { border-color: var(--passion-400); outline: none; }
.k-avatar {
  width: 60px; height: 60px; border-radius: 50%; background: var(--acai-700); color: #FFFFFF; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center; font-family: var(--font-heading); font-weight: 700; font-size: 22px;
}
.k-avatar-sm { width: 44px; height: 44px; font-size: 17px; }
.k-person-info { display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0; }
.k-person-name { font-family: var(--font-heading); font-weight: 700; font-size: 21px; line-height: 1.15; }
.k-person-status { font-size: 13.5px; color: var(--ink-700); }
.k-action { flex-shrink: 0; padding: 10px 14px; border-radius: 12px; font-weight: 700; font-size: 14px; }
.k-action-in { background: var(--passion-500); color: var(--on-accent); }
.k-action-out { background: var(--acai-700); color: #FFFFFF; }

.k-split { display: flex; gap: 28px; flex-wrap: wrap; align-items: flex-start; }
.k-split-side { flex: 1 1 280px; display: flex; flex-direction: column; gap: 12px; }
.k-who { display: flex; align-items: center; gap: 12px; }
.k-who-name { font-family: var(--font-heading); font-weight: 700; font-size: 23px; line-height: 1.1; }
.k-who-action { font-size: 14px; color: var(--ink-700); }

.k-pad-wrap { flex: 0 1 300px; display: flex; flex-direction: column; align-items: center; gap: 10px; }
.k-dots { display: flex; gap: 14px; min-height: 18px; }
.k-dot { width: 18px; height: 18px; border-radius: 50%; border: 2px solid var(--ink-300); box-sizing: border-box; }
.k-dot.filled { background: var(--ink); border-color: var(--ink); }
.k-pad { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; width: 100%; }
.k-key {
  height: 50px; border-radius: 12px; border: 2px solid var(--border); background: var(--cream-50); color: var(--ink);
  font: inherit; font-size: 22px; font-weight: 600; cursor: pointer;
}
.k-key:hover { border-color: var(--ink-300); }
.k-key-del { background: var(--surface-hover); color: var(--ink-700); display: inline-flex; align-items: center; justify-content: center; }
.k-key-del svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.k-key-ok { border: none; background: var(--passion-500); color: var(--on-accent); font-size: 15px; font-weight: 700; }
.k-key-ok:disabled { background: var(--surface-hover); color: var(--ink-500); cursor: default; }

.k-cam {
  position: relative; flex: 0 1 400px; aspect-ratio: 4 / 3; border-radius: 18px; overflow: hidden; background: #1A1020;
}
.k-video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); }
img.k-video { transform: none; }
.k-video.hidden { visibility: hidden; }
.k-oval { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
.k-oval ellipse { fill: none; stroke: #FFFFFF; stroke-width: 4; stroke-dasharray: 12 10; }
.k-cam.found .k-oval ellipse { stroke: var(--lime-500); stroke-dasharray: none; stroke-width: 5; }
.k-cam-tag {
  position: absolute; top: 12px; left: 12px; display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px;
  border-radius: 999px; background: rgba(0,0,0,0.55); color: #FFFFFF; font-size: 12px; font-weight: 600;
}
.k-rec { width: 8px; height: 8px; border-radius: 50%; background: var(--danger-500); }
.k-cam-caption {
  position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%); padding: 8px 16px; border-radius: 999px;
  background: rgba(0,0,0,0.65); color: #FFFFFF; font-size: 15px; font-weight: 700; white-space: nowrap;
}
.k-checks { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.k-checks li {
  display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 12px; background: var(--cream-100);
  border: 1px solid var(--surface-hover); font-size: 14px; color: var(--ink-500);
}
.k-check-icon {
  width: 24px; height: 24px; border-radius: 50%; border: 2px solid var(--border); box-sizing: border-box; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px;
}
.k-check-icon svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }
.k-checks li.is-ok { color: var(--ink); font-weight: 600; }
.k-checks li.is-ok .k-check-icon { background: var(--lime-700); border-color: var(--lime-700); color: #FFFFFF; }
.k-checks li.is-pending { color: var(--passion-text); font-weight: 600; }
.k-checks li.is-pending .k-check-icon { border: 3px solid var(--passion-300); border-top-color: var(--passion-600); animation: k-spin 0.9s linear infinite; }
.k-checks li.is-warn { color: var(--gold-text); font-weight: 600; }
.k-checks li.is-warn .k-check-icon { background: var(--gold-100); border-color: var(--gold-500); color: var(--gold-text); }
@keyframes k-spin { to { transform: rotate(360deg); } }

.k-row { display: flex; gap: 10px; flex-wrap: wrap; }
.k-row-center { justify-content: center; margin-top: 8px; }
.k-grow { flex: 1; }
.k-btn {
  height: 46px; padding: 0 18px; border-radius: 12px; font: inherit; font-size: 15px; font-weight: 700; cursor: pointer; border: none;
}
.k-btn:disabled { cursor: default; }
.k-btn-primary { background: var(--passion-500); color: var(--on-accent); }
.k-btn-primary:disabled { background: var(--surface-hover); color: var(--ink-500); }
.k-btn-ghost { background: transparent; border: 2px solid var(--border); color: var(--ink); font-weight: 600; }
.k-btn-acai { background: var(--acai-700); color: #FFFFFF; padding: 0 28px; }

.k-done { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 8px; padding: 6px 0 2px; }
.k-done-icon { width: 68px; height: 68px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; }
.k-done-icon svg { width: 36px; height: 36px; fill: none; stroke: currentColor; stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round; }
.k-done-icon.is-ok { background: var(--lime-100); color: var(--lime-700); }
.k-done-icon.is-pending { background: var(--gold-100); color: var(--gold-text); }
.k-done-title { margin: 0; font-family: var(--font-heading); font-weight: 800; font-size: 30px; line-height: 1.1; }
.k-done-time { font-family: var(--font-mono); font-weight: 600; font-size: 44px; line-height: 1; }
.k-done-detail { font-size: 15px; color: var(--ink-700); }
.k-done-msg { margin: 6px 0 0; max-width: 600px; padding: 12px 16px; border-radius: 14px; font-size: 14px; line-height: 1.45; }
.k-done-msg.is-ok { background: var(--lime-100); color: #1B5E25; }
.k-done-msg.is-pending { background: var(--gold-100); color: #5E4700; }

.k-history { display: flex; flex-direction: column; gap: 12px; }
.k-history-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.k-table-wrap { border: 1px solid var(--border); border-radius: 14px; overflow: auto; max-height: 300px; }
.k-table { width: 100%; border-collapse: collapse; font-size: 14.5px; }
.k-table th { background: var(--cream-100); color: var(--ink-700); text-align: left; font-weight: 600; padding: 8px 16px; position: sticky; top: 0; }
.k-table td { padding: 7px 16px; border-top: 1px solid var(--surface-hover); }
.k-table td:first-child { font-weight: 600; }

html.dark .k-done-msg.is-ok { background: rgba(60,174,73,0.18); color: #C8F2CD; }
html.dark .k-done-msg.is-pending { background: rgba(217,164,4,0.18); color: #FAD15C; }
html.dark .k-done-icon.is-ok { background: rgba(60,174,73,0.18); color: #9BE3A4; }
html.dark .k-steps li.done { color: #9BE3A4; }

@media (max-height: 640px) {
  .kiosk-head { padding: 8px 16px; }
  .kiosk-clock { font-size: 26px; }
  .kiosk-card { padding: 14px 22px 16px; gap: 12px; }
  .k-key { height: 46px; }
  .k-done-time { font-size: 38px; }
  .k-cam { flex-basis: 360px; }
}
@media (max-width: 640px) {
  .kiosk-clock { order: -1; width: 100%; text-align: center; }
  .kiosk-card { padding: 14px 16px 16px; }
  .k-people { grid-template-columns: 1fr; }
  .k-cam { flex-basis: 100%; }
}
</style>
