<script setup>
/**
 * Agregar o corregir una marca a mano, con motivo (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.5).
 * Si corrige otra, la original se conserva como «corregida» y queda en auditoría.
 */
import { ref, computed, watch } from 'vue';
import { apiFetch } from '../../services/api';

const props = defineProps({
  open: { type: Boolean, default: false },
  people: { type: Array, default: () => [] },
  preset: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'saved']);

const form = ref({});
const errors = ref({});
const saving = ref(false);

function today() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

watch(() => props.open, (isOpen) => {
  if (!isOpen) return;
  form.value = {
    user_id: props.preset.user_id ?? props.people[0]?.id ?? null,
    type: props.preset.type ?? 'in',
    date: props.preset.date ?? today(),
    time: props.preset.time ?? '',
    reason: '',
  };
  errors.value = {};
}, { immediate: true });

const correcting = computed(() => Boolean(props.preset.corrects));
const reasonLength = computed(() => form.value.reason?.trim().length ?? 0);

async function save() {
  errors.value = {};
  if (!form.value.time) {
    errors.value = { marked_at: ['Escribe la hora.'] };
    return;
  }
  saving.value = true;
  try {
    const res = await apiFetch('/attendance/marks', {
      method: 'POST',
      body: JSON.stringify({
        user_id: form.value.user_id,
        type: form.value.type,
        marked_at: `${form.value.date} ${form.value.time}`,
        reason: form.value.reason,
        corrects_id: props.preset.corrects?.id ?? null,
      }),
    });
    emit('saved', res);
  } catch (err) {
    errors.value = err.validationErrors || { general: [err.message] };
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <div v-if="open" class="fin-modal-overlay" @click.self="emit('close')">
    <form class="fin-modal" role="dialog" aria-labelledby="mm-title" @submit.prevent="save">
      <div class="fin-modal-head">
        <div>
          <h2 id="mm-title">{{ correcting ? 'Corregir marca' : 'Agregar marca' }}</h2>
          <p v-if="correcting">Antes: {{ preset.corrects.type === 'out' ? 'Salida' : 'Entrada' }} del {{ preset.corrects.day_label }} a las {{ preset.corrects.time }}. Se conserva como «corregida».</p>
          <p v-else>Para una marca que faltó (por ejemplo, una salida olvidada). Queda en auditoría con tu nombre.</p>
        </div>
        <button type="button" class="fin-icon-btn" aria-label="Cerrar" @click="emit('close')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M18 6 6 18M6 6l12 12" /></svg>
        </button>
      </div>
      <div class="fin-modal-body">
        <div class="fin-field">
          <label for="mm-person">Persona</label>
          <select id="mm-person" v-model="form.user_id" class="fin-input" :disabled="correcting">
            <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div class="fin-grid-2">
          <div class="fin-field">
            <label for="mm-type">Tipo</label>
            <select id="mm-type" v-model="form.type" class="fin-input">
              <option value="in">Entrada</option>
              <option value="out">Salida</option>
            </select>
          </div>
          <div class="fin-field">
            <label for="mm-date">Día</label>
            <input id="mm-date" v-model="form.date" type="date" class="fin-input" :max="today()" required />
          </div>
        </div>
        <div class="fin-field">
          <label for="mm-time">Hora</label>
          <input id="mm-time" v-model="form.time" type="time" class="fin-input" required />
          <span v-if="errors.marked_at" class="fin-field-err">{{ errors.marked_at[0] }}</span>
        </div>
        <div class="fin-field">
          <label for="mm-reason">Motivo (obligatorio)</label>
          <input id="mm-reason" v-model="form.reason" class="fin-input" maxlength="255" placeholder="Ej.: se olvidó de marcar; cerró conmigo a las 22:10" />
          <span class="fin-hint">{{ reasonLength }} / 255 · mínimo 10</span>
          <span v-if="errors.reason" class="fin-field-err">{{ errors.reason[0] }}</span>
        </div>
        <p v-if="errors.general || errors.corrects_id || errors.user_id" class="fin-field-err" role="alert">
          {{ (errors.general || errors.corrects_id || errors.user_id)[0] }}
        </p>
      </div>
      <div class="fin-modal-foot">
        <button type="button" class="fin-btn fin-btn-ghost fin-btn-lg" @click="emit('close')">Cancelar</button>
        <button type="submit" class="fin-btn fin-btn-primary fin-btn-lg" :disabled="saving || reasonLength < 10">
          {{ saving ? 'Guardando…' : 'Guardar marca' }}
        </button>
      </div>
    </form>
  </div>
</template>
