<script setup>
/**
 * Usuarios → «Trabajo y asistencia» (solo Super Admin): si marca asistencia, cómo se le paga, su horario y su PIN
 * para el reloj (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.1). Estos datos nunca los ve el trabajador (§5.8).
 */
import { ref, computed, watch } from 'vue';
import { apiFetch } from '../../services/api';
import { rowsFromSchedule, scheduleFromRows, hourlyFromMonthly, belowMinimum, pinIsValid } from '../../utils/attendance';
import FaceEnrollment from './FaceEnrollment.vue';
import '../Finance/finance.css';

const props = defineProps({
  open: { type: Boolean, default: false },
  user: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);

const MODES = [
  { id: 'hourly', label: 'Por hora' },
  { id: 'monthly', label: 'Sueldo mensual' },
  { id: 'per_shift', label: 'Por turno' },
];

const loading = ref(false);
const saving = ref(false);
const loadError = ref('');
const errors = ref({});
const form = ref(null);
const rows = ref(rowsFromSchedule({}));
const hasPin = ref(false);
const face = ref({ count: 0, max: 5, consent_at: null, consent_by: null });
const minimumWage = ref(3300);
const pin = ref('');
const pinMessage = ref('');
const pinError = ref('');

function defaults() {
  return {
    attendance_enabled: true,
    pay_mode: 'hourly',
    hourly_rate: '',
    monthly_salary: '',
    shift_rate: '',
    shift_hours: 8,
    late_tolerance_min: 10,
    overtime_after_hours: 8,
    overtime_percent: 100,
    sunday_percent: 0,
    holiday_percent: 0,
  };
}

const toPercent = (multiplier) => Math.round((Number(multiplier ?? 1) - 1) * 100);
const toMultiplier = (percent) => Math.round((1 + Number(percent || 0) / 100) * 100) / 100;

async function load() {
  if (!props.user) return;
  loading.value = true;
  loadError.value = '';
  errors.value = {};
  pin.value = '';
  pinMessage.value = '';
  pinError.value = '';
  try {
    const res = await apiFetch(`/users/${props.user.id}/employee-profile`);
    const p = res.profile;
    form.value = p ? {
      attendance_enabled: p.attendance_enabled,
      pay_mode: p.pay_mode,
      hourly_rate: p.hourly_rate ?? '',
      monthly_salary: p.monthly_salary ?? '',
      shift_rate: p.shift_rate ?? '',
      shift_hours: Number(p.shift_hours ?? 8),
      late_tolerance_min: p.late_tolerance_min ?? 10,
      overtime_after_hours: Number(p.overtime_after_hours ?? 8),
      overtime_percent: toPercent(p.overtime_multiplier ?? 2),
      sunday_percent: toPercent(p.sunday_multiplier),
      holiday_percent: toPercent(p.holiday_multiplier),
    } : defaults();
    rows.value = rowsFromSchedule(p?.schedule ?? {});
    hasPin.value = res.has_pin;
    face.value = res.face;
    minimumWage.value = res.minimum_wage ?? 3300;
  } catch (err) {
    loadError.value = err.message;
  } finally {
    loading.value = false;
  }
}

watch(() => [props.open, props.user?.id], ([isOpen]) => { if (isOpen) load(); }, { immediate: true });

const hourValue = computed(() => (form.value?.pay_mode === 'monthly' ? hourlyFromMonthly(form.value.monthly_salary) : null));
const minimumHour = computed(() => hourlyFromMonthly(minimumWage.value));
const underMinimum = computed(() => form.value && belowMinimum(form.value, minimumWage.value));
const money = (n) => Number(n || 0).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

async function save() {
  saving.value = true;
  errors.value = {};
  const f = form.value;
  const amount = (v) => (v === '' || v === null ? null : Number(v));
  try {
    await apiFetch(`/users/${props.user.id}/employee-profile`, {
      method: 'PUT',
      body: JSON.stringify({
        attendance_enabled: f.attendance_enabled,
        pay_mode: f.pay_mode,
        hourly_rate: amount(f.hourly_rate),
        monthly_salary: amount(f.monthly_salary),
        shift_rate: amount(f.shift_rate),
        shift_hours: amount(f.shift_hours),
        schedule: scheduleFromRows(rows.value),
        late_tolerance_min: amount(f.late_tolerance_min),
        overtime_after_hours: amount(f.overtime_after_hours),
        overtime_multiplier: toMultiplier(f.overtime_percent),
        sunday_multiplier: toMultiplier(f.sunday_percent),
        holiday_multiplier: toMultiplier(f.holiday_percent),
      }),
    });
    emit('saved', `Trabajo y asistencia de ${props.user.name} guardado.`);
  } catch (err) {
    errors.value = err.validationErrors || { general: [err.message] };
  } finally {
    saving.value = false;
  }
}

async function savePin() {
  pinMessage.value = '';
  pinError.value = '';
  if (!pinIsValid(pin.value)) {
    pinError.value = 'El PIN tiene de 4 a 6 dígitos.';
    return;
  }
  try {
    if (!form.value || loadError.value) return;
    await apiFetch(`/users/${props.user.id}/employee-profile/pin`, { method: 'PUT', body: JSON.stringify({ pin: pin.value }) });
    hasPin.value = true;
    pin.value = '';
    pinMessage.value = 'PIN guardado. Díselo a la persona; nadie más lo ve.';
  } catch (err) {
    pinError.value = err.validationErrors?.pin?.[0] || err.message;
  }
}

const fieldError = (key) => errors.value?.[key]?.[0];

/** Después de una foto del rostro: solo se actualiza el resumen del rostro, sin tocar lo que se está editando. */
async function reloadFace() {
  try {
    const res = await apiFetch(`/users/${props.user.id}/employee-profile`);
    face.value = res.face;
  } catch {
    // se ve al volver a abrir
  }
}
</script>

<template>
  <div v-if="open" class="fin-page att-host">
    <div class="fin-modal-overlay" @click.self="emit('close')">
      <form class="fin-modal xl" role="dialog" aria-labelledby="ep-title" @submit.prevent="save">
        <div class="fin-modal-head">
          <div>
            <h2 id="ep-title">Trabajo y asistencia · {{ user?.name }}</h2>
            <p>Solo lo ven los administradores. La persona solo ve sus horas de entrada y salida.</p>
          </div>
          <button type="button" class="fin-icon-btn" aria-label="Cerrar" @click="emit('close')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M18 6 6 18M6 6l12 12" /></svg>
          </button>
        </div>

        <div class="fin-modal-body">
          <p v-if="loading" class="fin-muted">Cargando…</p>
          <p v-else-if="loadError" class="fin-error">{{ loadError }}</p>
          <template v-else-if="form">
            <label class="att-switch">
              <input v-model="form.attendance_enabled" type="checkbox" />
              <span><strong>Marca asistencia en el reloj del local</strong><br /><span class="fin-hint">Si se desactiva, deja de aparecer en el reloj. Sus marcas quedan.</span></span>
            </label>

            <section class="att-section" :class="{ dim: !form.attendance_enabled }">
              <h3>Cómo se le paga</h3>
              <div class="att-seg" role="group" aria-label="Modo de pago">
                <button
                  v-for="m in MODES"
                  :key="m.id"
                  type="button"
                  :aria-pressed="form.pay_mode === m.id"
                  :class="{ on: form.pay_mode === m.id }"
                  @click="form.pay_mode = m.id"
                >{{ m.label }}</button>
              </div>

              <div v-if="form.pay_mode === 'hourly'" class="fin-field att-amount">
                <label for="ep-hourly">Tarifa por hora (Bs)</label>
                <input id="ep-hourly" v-model="form.hourly_rate" type="number" min="0" step="0.01" class="fin-input att-mono" placeholder="15,00" />
                <span v-if="fieldError('hourly_rate')" class="fin-field-err">{{ fieldError('hourly_rate') }}</span>
              </div>
              <div v-else-if="form.pay_mode === 'monthly'" class="fin-field att-amount">
                <label for="ep-monthly">Sueldo mensual (Bs)</label>
                <input id="ep-monthly" v-model="form.monthly_salary" type="number" min="0" step="0.01" class="fin-input att-mono" placeholder="3300,00" />
                <span class="fin-hint">Valor de la hora: Bs {{ money(hourValue) }} (sueldo ÷ 240). Las faltas y los atrasos se descuentan a ese valor.</span>
                <span v-if="fieldError('monthly_salary')" class="fin-field-err">{{ fieldError('monthly_salary') }}</span>
              </div>
              <div v-else class="fin-grid-2">
                <div class="fin-field">
                  <label for="ep-shift">Monto por turno (Bs)</label>
                  <input id="ep-shift" v-model="form.shift_rate" type="number" min="0" step="0.01" class="fin-input att-mono" />
                  <span v-if="fieldError('shift_rate')" class="fin-field-err">{{ fieldError('shift_rate') }}</span>
                </div>
                <div class="fin-field">
                  <label for="ep-shift-hours">Duración del turno (h)</label>
                  <input id="ep-shift-hours" v-model="form.shift_hours" type="number" min="1" max="16" step="0.5" class="fin-input" />
                </div>
              </div>
              <p v-if="underMinimum" class="fin-note err">
                Queda por debajo del salario mínimo: Bs {{ money(minimumWage) }} al mes, Bs {{ money(minimumHour) }} por hora.
              </p>

              <div class="att-grid-3">
                <div class="fin-field">
                  <label for="ep-ot-after">Horas extra desde (h por día)</label>
                  <input id="ep-ot-after" v-model="form.overtime_after_hours" type="number" min="1" max="16" step="0.5" class="fin-input" />
                </div>
                <div class="fin-field">
                  <label for="ep-ot">Recargo de la hora extra (%)</label>
                  <input id="ep-ot" v-model="form.overtime_percent" type="number" min="0" max="300" step="5" class="fin-input" />
                  <span class="fin-hint">100 % = el doble.</span>
                </div>
                <div class="fin-field">
                  <label for="ep-tol">Tolerancia de atraso (min)</label>
                  <input id="ep-tol" v-model="form.late_tolerance_min" type="number" min="0" max="120" class="fin-input" />
                </div>
                <div class="fin-field">
                  <label for="ep-sun">Recargo en domingo (%)</label>
                  <input id="ep-sun" v-model="form.sunday_percent" type="number" min="0" max="300" step="5" class="fin-input" />
                </div>
              </div>
              <p class="fin-hint">Confirma los recargos con tu contador. Pasada la tolerancia se cuenta todo el atraso. Los feriados todavía no se calculan solos: van como bono en la planilla. La planilla no calcula aportes, aguinaldo ni finiquito.</p>
            </section>

            <section class="att-section" :class="{ dim: !form.attendance_enabled }">
              <h3>Horario</h3>
              <div class="att-days">
                <div v-for="(r, i) in rows" :key="r.day" class="att-day" :class="{ off: !r.works }">
                  <label class="att-day-name">
                    <input v-model="r.works" type="checkbox" />
                    {{ r.day }}
                  </label>
                  <template v-if="r.works">
                    <label class="sr-only" :for="`ep-start-${i}`">Entrada del {{ r.day }}</label>
                    <input :id="`ep-start-${i}`" v-model="r.start" type="time" class="fin-input fin-input-sm" />
                    <label class="sr-only" :for="`ep-end-${i}`">Salida del {{ r.day }}</label>
                    <input :id="`ep-end-${i}`" v-model="r.end" type="time" class="fin-input fin-input-sm" />
                  </template>
                  <span v-else class="fin-small fin-muted">Libre</span>
                </div>
              </div>
              <p class="fin-hint">Con horario se calculan atrasos y faltas. Sin horario, solo se suman las horas.</p>
            </section>

            <section class="att-section">
              <h3>PIN para marcar</h3>
              <div class="att-pin">
                <div class="fin-field">
                  <label for="ep-pin">{{ hasPin ? 'Cambiar el PIN' : 'PIN nuevo' }} (4 a 6 dígitos, distinto de la contraseña)</label>
                  <input id="ep-pin" v-model="pin" inputmode="numeric" autocomplete="off" maxlength="6" class="fin-input att-mono" :placeholder="hasPin ? '•••• (ya tiene)' : ''" />
                </div>
                <button type="button" class="fin-btn fin-btn-secondary fin-btn-lg" :disabled="!pin" @click="savePin">Guardar PIN</button>
              </div>
              <p v-if="pinMessage" class="fin-hint" role="status">{{ pinMessage }}</p>
              <p v-if="pinError" class="fin-field-err" role="alert">{{ pinError }}</p>
              <p v-if="!hasPin && !pinMessage" class="fin-note err">Sin PIN no puede marcar.</p>
            </section>

            <FaceEnrollment :user="user" :face="face" @changed="reloadFace" />

            <p v-if="errors.general" class="fin-field-err" role="alert">{{ errors.general[0] }}</p>
          </template>
        </div>

        <div class="fin-modal-foot">
          <button type="button" class="fin-btn fin-btn-ghost fin-btn-lg" @click="emit('close')">Cerrar</button>
          <button type="submit" class="fin-btn fin-btn-primary fin-btn-lg" :disabled="saving || loading || !form">
            {{ saving ? 'Guardando…' : 'Guardar' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<style scoped>
.att-host { display: contents; }
.att-switch { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border-radius: 12px; background: var(--cream-100); border: 1px solid var(--border); cursor: pointer; font-size: 14px; }
.att-switch input { width: 20px; height: 20px; margin: 2px 0 0; accent-color: var(--acai-700); flex-shrink: 0; }
.att-section { display: flex; flex-direction: column; gap: 10px; }
.att-section.dim { opacity: 0.55; }
.att-section h3 { margin: 0; font-size: 14px; font-weight: 700; color: var(--ink-900); }
.att-seg { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 4px; padding: 4px; border-radius: 12px; background: var(--cream-200); }
.att-seg button { height: 40px; border-radius: 9px; border: none; background: transparent; font: inherit; font-size: 14px; font-weight: 500; color: var(--ink-700); cursor: pointer; }
.att-seg button.on { background: var(--surface); box-shadow: 0 1px 3px rgba(32,17,47,0.15); font-weight: 700; color: var(--ink-900); }
.att-amount { max-width: 360px; }
.att-mono { font-family: var(--font-mono); }
.att-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.att-days { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
.att-day { display: flex; flex-direction: column; gap: 4px; padding: 8px 6px; border-radius: 10px; border: 1px solid var(--passion-300); background: var(--passion-50); }
.att-day.off { border-color: var(--border); background: var(--cream-100); }
.att-day-name { display: flex; gap: 6px; align-items: center; font-size: 12.5px; font-weight: 700; color: var(--ink-900); cursor: pointer; }
.att-day-name input { accent-color: var(--acai-700); }
.att-day .fin-input-sm { padding: 4px 4px; font-size: 12px; }
.att-pin { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
.att-pin .fin-field { flex: 1 1 260px; }
html.dark .att-day { background: rgba(251, 120, 16, 0.08); border-color: rgba(255, 150, 64, 0.35); }
@media (max-width: 760px) {
  .att-grid-3 { grid-template-columns: 1fr 1fr; }
  .att-days { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}
</style>
