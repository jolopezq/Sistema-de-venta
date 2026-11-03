<script setup>
/**
 * Aviso de asistencia en la caja (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.5). Solo usa las horas de la propia persona.
 * - mode «entry»: al abrir la caja sin haber marcado la entrada (aviso o, con el ajuste activo, bloqueo).
 * - mode «exit»: al cerrar la caja con el turno abierto, para que marque la salida al irse.
 */
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { hasAttendanceDevice } from '../../services/attendanceKiosk';

const props = defineProps({
  mode: { type: String, required: true },
  today: { type: Object, default: null },
});
const emit = defineEmits(['go']);
const router = useRouter();

const device = hasAttendanceDevice();
const show = computed(() => {
  if (!props.today?.enabled) return false;
  return props.mode === 'entry' ? !props.today.entry : props.today.open;
});
const blocking = computed(() => props.mode === 'entry' && props.today?.require_entry_to_open_register);

function goToClock() {
  emit('go');
  router.push('/asistencia/reloj');
}
</script>

<template>
  <div v-if="show" class="man" :class="[`man-${mode}`, { 'man-block': blocking }]" role="note">
    <span class="man-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
    </span>
    <div class="man-text">
      <template v-if="mode === 'entry'">
        <strong>Todavía no marcaste tu entrada de hoy</strong>
        <span v-if="blocking">Márcala en el reloj de asistencia para poder abrir la caja.</span>
        <span v-else>Márcala antes de abrir la caja: así tus horas cuentan desde ahora.</span>
      </template>
      <template v-else>
        <strong>¿Ya terminaste tu turno?</strong>
        <span>Tu entrada fue a las {{ today.entry }}. Marca la salida cuando termines de limpiar y ordenar: ese tiempo también cuenta.</span>
      </template>
      <button v-if="device" type="button" class="man-btn" @click="goToClock">
        {{ mode === 'entry' ? 'Marcar mi entrada' : 'Marcar mi salida' }}
      </button>
      <span v-else class="man-hint">Se marca en el reloj de la portátil de caja.</span>
    </div>
  </div>
</template>

<style scoped>
.man { display: flex; gap: 12px; padding: 14px; border-radius: 14px; text-align: left; }
.man-entry { background: var(--gold-100); border: 1px solid #F1D98A; color: #5E4700; }
.man-entry.man-block { background: var(--danger-100); border-color: #F2B8B5; color: var(--danger-text); }
.man-exit { background: var(--acai-50); border: 1px solid #D9C9EC; color: var(--acai-text); }
.man-icon { width: 36px; height: 36px; border-radius: 50%; background: var(--surface); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.man-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
.man-text { display: flex; flex-direction: column; gap: 6px; font-size: 13.5px; line-height: 1.45; }
.man-text strong { font-size: 15px; }
.man-btn {
  align-self: flex-start; height: 40px; padding: 0 14px; border-radius: 10px; border: none; cursor: pointer;
  background: var(--passion-500); color: var(--on-accent); font: inherit; font-size: 14px; font-weight: 700;
}
.man-hint { font-size: 12.5px; opacity: 0.9; }
html.dark .man-entry { background: rgba(217, 164, 4, 0.16); border-color: rgba(250, 209, 92, 0.35); color: var(--gold-text); }
html.dark .man-entry.man-block { background: rgba(229, 72, 77, 0.16); border-color: rgba(255, 161, 161, 0.35); color: var(--danger-text); }
html.dark .man-exit { background: rgba(116, 72, 166, 0.2); border-color: rgba(168, 134, 198, 0.4); color: var(--acai-text); }
</style>
