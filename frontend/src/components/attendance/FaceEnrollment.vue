<script setup>
/**
 * Registrar el rostro de una persona (Usuarios → Trabajo y asistencia, solo Super Admin; docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.4).
 * Primero el consentimiento firmado; después 3 a 5 capturas mirando de frente y un poco hacia los lados.
 * El servidor guarda el descriptor y una foto de referencia; la comparación al marcar la hace él.
 */
import { ref, computed, onBeforeUnmount, nextTick } from 'vue';
import { apiFetch } from '../../services/api';
import { faceEngine } from '../../services/faceEngine';

const props = defineProps({
  user: { type: Object, required: true },
  face: { type: Object, required: true },
});
const emit = defineEmits(['changed']);

const POSES = ['De frente', 'Un poco a la izquierda', 'Un poco a la derecha', 'Un poco hacia arriba', 'Un poco hacia abajo'];
const MIN_CAPTURES = 3;

const consent = ref(false);
const cameraOn = ref(false);
const engineState = ref('idle'); // idle | loading | ready | failed
const busy = ref(false);
const message = ref('');
const error = ref('');
const confirmClear = ref(false);
const videoEl = ref(null);
let stream = null;

const count = computed(() => props.face.count ?? 0);
const max = computed(() => props.face.max ?? 5);
const hasConsent = computed(() => Boolean(props.face.consent_at));
const complete = computed(() => count.value >= max.value);
const nextPose = computed(() => POSES[Math.min(count.value, POSES.length - 1)]);
const slots = computed(() => POSES.slice(0, max.value).map((label, i) => ({
  label, done: i < count.value, next: i === count.value,
})));
const statusLabel = computed(() => {
  if (complete.value) return 'Registrado';
  if (count.value >= MIN_CAPTURES) return `Registrado · ${count.value} de ${max.value} fotos`;
  if (count.value > 0) return `${count.value} de ${max.value} fotos`;
  return 'Sin rostro registrado';
});

async function openCamera() {
  error.value = '';
  message.value = '';
  cameraOn.value = true;
  await nextTick();
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } } });
    videoEl.value.srcObject = stream;
    await videoEl.value.play();
  } catch {
    error.value = 'No se pudo abrir la cámara. Revisa que esté conectada y que el navegador tenga permiso.';
    cameraOn.value = false;
    return;
  }
  engineState.value = 'loading';
  engineState.value = (await faceEngine.load()) ? 'ready' : 'failed';
  if (engineState.value === 'failed') {
    error.value = 'No se pudo cargar el reconocimiento de rostro en este equipo. Vuelve a intentar en la portátil de caja.';
  }
}

function stopCamera() {
  if (stream) {
    stream.getTracks().forEach((t) => t.stop());
    stream = null;
  }
  cameraOn.value = false;
}

function photoFromVideo() {
  const video = videoEl.value;
  const width = Math.min(640, video.videoWidth);
  const height = Math.round((video.videoHeight / video.videoWidth) * width);
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  canvas.getContext('2d').drawImage(video, 0, 0, width, height);
  return canvas.toDataURL('image/jpeg', 0.85);
}

async function capture() {
  if (!hasConsent.value && !consent.value) {
    error.value = 'Primero marca que la persona firmó el consentimiento.';
    return;
  }
  busy.value = true;
  error.value = '';
  message.value = 'Quieto un momento…';
  try {
    // Hasta 3 segundos para encontrar una sola cara nítida
    let found = null;
    for (let i = 0; i < 15 && !found; i++) {
      const res = await faceEngine.describe(videoEl.value);
      if (res.faces > 1) {
        message.value = '';
        error.value = 'Hay más de una cara frente a la cámara: que quede solo la de la persona.';
        return;
      }
      if (res.descriptor) found = res;
      else await new Promise((r) => setTimeout(r, 200));
    }
    if (!found) {
      message.value = '';
      error.value = 'No se vio bien el rostro. Acércate a la cámara, con buena luz, y vuelve a intentar.';
      return;
    }
    const res = await apiFetch(`/users/${props.user.id}/face`, {
      method: 'POST',
      body: JSON.stringify({ consent: hasConsent.value || consent.value, descriptor: found.descriptor, photo: photoFromVideo() }),
    });
    message.value = res.face_count >= max.value
      ? 'Listo: rostro registrado con todas las fotos.'
      : `Foto ${res.face_count} guardada. Ahora: ${POSES[Math.min(res.face_count, POSES.length - 1)].toLowerCase()}.`;
    emit('changed');
    if (res.face_count >= max.value) stopCamera();
  } catch (err) {
    message.value = '';
    error.value = err.validationErrors ? Object.values(err.validationErrors).flat()[0] : err.message;
  } finally {
    busy.value = false;
  }
}

async function clearFace() {
  if (!confirmClear.value) {
    confirmClear.value = true;
    return;
  }
  busy.value = true;
  try {
    await apiFetch(`/users/${props.user.id}/face`, { method: 'DELETE' });
    message.value = 'Rostro borrado. Se puede registrar de nuevo.';
    confirmClear.value = false;
    emit('changed');
  } catch (err) {
    error.value = err.message;
  } finally {
    busy.value = false;
  }
}

onBeforeUnmount(stopCamera);
</script>

<template>
  <section class="fe">
    <div class="fe-head">
      <h3>Rostro para marcar</h3>
      <span class="fin-badge" :class="count >= 3 ? 'ok' : 'warn'">{{ statusLabel }}</span>
    </div>

    <p v-if="hasConsent" class="fin-hint">Consentimiento registrado el {{ face.consent_at }}{{ face.consent_by ? ` por ${face.consent_by}` : '' }}.</p>
    <label v-else class="fe-consent">
      <input v-model="consent" type="checkbox" />
      <span>La persona firmó el consentimiento para usar su rostro al marcar. Se anota la fecha y quién lo registró.</span>
    </label>

    <div class="fe-body">
      <div v-if="cameraOn" class="fe-cam">
        <video ref="videoEl" playsinline muted></video>
        <span class="fe-cam-caption">{{ engineState === 'loading' ? 'Preparando…' : nextPose }}</span>
      </div>
      <ol class="fe-slots" aria-label="Fotos del rostro">
        <li v-for="s in slots" :key="s.label" :class="{ done: s.done, next: s.next }">
          <span class="fe-slot">
            <svg v-if="s.done" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
          </span>
          <span class="fe-slot-label">{{ s.label }}</span>
        </li>
      </ol>
    </div>

    <p v-if="message" class="fin-hint" role="status">{{ message }}</p>
    <p v-if="error" class="fin-field-err" role="alert">{{ error }}</p>

    <div class="fin-row">
      <button v-if="!cameraOn && !complete" type="button" class="fin-btn fin-btn-secondary fin-btn-lg" :disabled="busy" @click="openCamera">
        {{ count ? 'Seguir con las fotos' : 'Abrir la cámara' }}
      </button>
      <button
        v-if="cameraOn"
        type="button"
        class="fin-btn fin-btn-primary fin-btn-lg"
        :disabled="busy || engineState !== 'ready' || (!hasConsent && !consent)"
        @click="capture"
      >{{ busy ? 'Leyendo el rostro…' : `Tomar la foto ${count + 1} de ${max}` }}</button>
      <button v-if="cameraOn" type="button" class="fin-btn fin-btn-ghost fin-btn-lg" @click="stopCamera">Cerrar la cámara</button>
      <button v-if="count && !cameraOn" type="button" class="fin-btn fin-btn-ghost fin-btn-lg fe-danger" :disabled="busy" @click="clearFace">
        {{ confirmClear ? '¿Seguro? Borrar el rostro' : 'Borrar rostro' }}
      </button>
    </div>
    <p class="fin-hint">Hazlo en el local, con la cámara y la luz de la portátil de caja. Con 3 fotos ya funciona; 5 es mejor.</p>
  </section>
</template>

<style scoped>
.fe { display: flex; flex-direction: column; gap: 10px; }
.fe-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.fe-head h3 { margin: 0; font-size: 14px; font-weight: 700; color: var(--ink-900); }
.fe-consent { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border-radius: 12px; background: var(--cream-100); border: 1px solid var(--border); font-size: 13.5px; line-height: 1.45; cursor: pointer; }
.fe-consent input { width: 20px; height: 20px; margin: 1px 0 0; accent-color: var(--acai-700); flex-shrink: 0; }
.fe-body { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-start; }
.fe-cam { position: relative; width: 320px; max-width: 100%; aspect-ratio: 4 / 3; border-radius: 14px; overflow: hidden; background: #1A1020; }
.fe-cam video { width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); }
.fe-cam-caption { position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); padding: 6px 14px; border-radius: 999px; background: rgba(0,0,0,0.65); color: #FFFFFF; font-size: 13.5px; font-weight: 700; white-space: nowrap; }
.fe-slots { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(5, minmax(64px, 1fr)); gap: 8px; flex: 1 1 320px; }
.fe-slots li { display: flex; flex-direction: column; align-items: center; gap: 4px; text-align: center; }
.fe-slot { width: 100%; aspect-ratio: 1; border-radius: 12px; border: 2px dashed var(--border); background: var(--cream-100); box-sizing: border-box; display: flex; align-items: center; justify-content: center; }
.fe-slot svg { width: 22px; height: 22px; fill: none; stroke: #FFFFFF; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }
.fe-slots li.done .fe-slot { border: none; background: var(--lime-700); }
.fe-slots li.next .fe-slot { border-color: var(--passion-600); background: var(--passion-50); }
.fe-slot-label { font-size: 11.5px; color: var(--ink-700); line-height: 1.25; }
.fe-danger { color: var(--danger-text); border-color: var(--danger-100); }
html.dark .fe-slots li.next .fe-slot { background: rgba(251, 120, 16, 0.1); }
</style>
