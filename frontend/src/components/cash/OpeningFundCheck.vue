<script setup>
import { computed } from 'vue';

/**
 * Al abrir una caja: con qué fondo dejó la caja anterior y, si se abre con otro monto, el motivo
 * (docs/PLAN_CONCILIACION_CAJA.md, E). El servidor lo exige igual (422 «opening_mismatch»).
 */
const props = defineProps({
  /** Respuesta de GET /cash-sessions/opening-info */
  info: { type: Object, default: null },
  amount: { type: [Number, String], default: null },
  idPrefix: { type: String, default: 'ofc' },
});
const note = defineModel('note', { type: String, default: '' });

const money = (n) => `Bs ${(Number(n) || 0).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const day = (iso) => {
  const [, m, d] = String(iso || '').split('-');
  return d && m ? `${d}/${m}` : '';
};

const expected = computed(() => (props.info?.suggested_opening ?? null));
const mismatch = computed(() => expected.value !== null && props.amount !== '' && props.amount !== null
  && Math.abs(Number(props.amount) - Number(expected.value)) > 0.005);
const diff = computed(() => Number(props.amount) - Number(expected.value));
const noteOk = computed(() => !mismatch.value || note.value.trim().length >= 5);

defineExpose({ mismatch, noteOk });
</script>

<template>
  <div v-if="info?.previous" class="ofc" :class="{ warn: mismatch }">
    <p class="ofc-hint">
      La caja del {{ day(info.previous.date) }}<template v-if="info.previous.cashier"> ({{ info.previous.cashier }})</template>
      dejó <strong>{{ money(info.previous.left_for_next) }}</strong> para el día siguiente.
    </p>
    <div v-if="mismatch" class="ofc-reason">
      <label :for="`${idPrefix}-note`">
        Abres con {{ diff > 0 ? `${money(diff)} más` : `${money(-diff)} menos` }}. ¿Por qué?
      </label>
      <input
        :id="`${idPrefix}-note`"
        v-model="note"
        type="text"
        maxlength="255"
        placeholder="Ej.: faltaba cambio y puse monedas"
        autocomplete="off"
      />
      <span class="ofc-help">Queda en la auditoría. Si es un error, pon {{ money(info.previous.left_for_next) }}.</span>
    </div>
  </div>
</template>

<style scoped>
.ofc { display: flex; flex-direction: column; gap: 6px; font-size: 13px; color: var(--ink-700); }
.ofc-hint { margin: 0; line-height: 1.4; }
.ofc-hint strong { color: var(--ink-900); font-variant-numeric: tabular-nums; }
.ofc-reason { display: flex; flex-direction: column; gap: 4px; padding: 8px 10px; border-radius: 10px; background: var(--gold-100); }
.ofc-reason label { font-weight: 600; color: var(--gold-text); }
.ofc-reason input { font: inherit; font-size: 14px; padding: 7px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--ink-900); min-width: 0; }
.ofc-reason input:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 1px; }
.ofc-help { font-size: 12px; color: var(--ink-700); }
html.dark .ofc-reason { background: rgba(250, 209, 92, 0.12); }
</style>
