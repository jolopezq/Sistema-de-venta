/**
 * Motor de rostro del reloj de asistencia (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.4), con la librería Human copiada en
 * public/vendor/human (licencia MIT). Corre en el navegador: detecta el rostro, el parpadeo y si es una cara real, y
 * calcula el descriptor. La comparación con el rostro registrado la hace el servidor (FaceMatcher), con la misma
 * escala, así que el porcentaje nunca sale de este equipo.
 *
 * Interfaz (la usan AttendanceKiosk.vue y FaceEnrollment.vue):
 *   load(): Promise<boolean>            ¿quedó listo? (la primera vez descarga ~13 MB, después sale de la caché)
 *   available(): boolean
 *   analyze(video): Promise<{ faces, blink, descriptor, liveness, score, ready }>
 *   describe(video): Promise<{ faces, descriptor, score }>   para registrar el rostro (sin parpadeo)
 *   reset(): void                       olvida el parpadeo de la persona anterior
 */

const BASE = '/vendor/human/';
const LOAD_TIMEOUT_MS = 45000;
const MIN_FACE_SCORE = 0.6;
const MIN_REAL = 0.5;
const BLINK_MIN_MS = 10;
const BLINK_MAX_MS = 800;

const CONFIG = {
  modelBasePath: `${BASE}models/`,
  debug: false,
  cacheSensitivity: 0,
  filter: { enabled: true, equalization: true },
  face: {
    enabled: true,
    detector: { rotation: true, maxDetected: 3, return: false },
    mesh: { enabled: true },
    attention: { enabled: false },
    iris: { enabled: false },
    description: { enabled: true },
    emotion: { enabled: false },
    antispoof: { enabled: true },
    liveness: { enabled: true },
  },
  body: { enabled: false },
  hand: { enabled: false },
  object: { enabled: false },
  segmentation: { enabled: false },
  gesture: { enabled: true },
};

let human = null;
let loading = null;
let ready = false;
const blink = { closedAt: 0, done: false };

function withTimeout(promise, ms) {
  return Promise.race([promise, new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), ms))]);
}

async function create(backend) {
  const mod = await import(/* @vite-ignore */ `${BASE}human.esm.js`);
  const H = mod.Human || mod.default;
  const instance = new H({ ...CONFIG, backend });
  await instance.load();
  await instance.warmup();
  return instance;
}

export const faceEngine = {
  async load() {
    if (ready) return true;
    if (!loading) {
      loading = withTimeout((async () => {
        try {
          human = await create('webgl');
        } catch {
          // Sin WebGL (equipo viejo o controlador): más lento, pero funciona
          human = await create('cpu');
        }
        ready = true;
        return true;
      })(), LOAD_TIMEOUT_MS).catch(() => {
        loading = null;
        return false;
      });
    }
    return loading;
  },

  available() {
    return ready;
  },

  async analyze(video) {
    if (!ready || !video) return { faces: 0, blink: false, descriptor: null, liveness: null, score: 0, ready: false };
    const res = await human.detect(video);
    const faces = res.face.length;
    const face = faces === 1 ? res.face[0] : null;
    const gestures = res.gesture.map((g) => g.gesture);
    const closed = gestures.includes('blink left eye') || gestures.includes('blink right eye');
    const now = performance.now();
    if (face && closed && !blink.closedAt) blink.closedAt = now;
    if (face && !closed && blink.closedAt) {
      const ms = now - blink.closedAt;
      if (ms > BLINK_MIN_MS && ms < BLINK_MAX_MS) blink.done = true;
      blink.closedAt = 0;
    }
    const score = face ? (face.faceScore || face.boxScore || 0) : 0;
    const descriptor = face && score >= MIN_FACE_SCORE && face.embedding?.length ? Array.from(face.embedding) : null;
    const real = face ? (face.real ?? 0) >= MIN_REAL && (face.live ?? 0) >= MIN_REAL : false;
    return {
      faces,
      blink: blink.done,
      descriptor,
      liveness: blink.done && real,
      score,
      ready: Boolean(face && descriptor && blink.done),
    };
  },

  async describe(video) {
    if (!ready || !video) return { faces: 0, descriptor: null, score: 0 };
    const res = await human.detect(video);
    const faces = res.face.length;
    const face = faces === 1 ? res.face[0] : null;
    const score = face ? (face.faceScore || face.boxScore || 0) : 0;
    return {
      faces,
      score,
      descriptor: face && score >= MIN_FACE_SCORE && face.embedding?.length ? Array.from(face.embedding) : null,
    };
  },

  reset() {
    blink.closedAt = 0;
    blink.done = false;
  },
};
