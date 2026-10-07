<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';

/** Ventana modal del módulo de Finanzas: cabecera, cuerpo con scroll y pie de acciones. */
const props = defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  wide: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const root = ref(null);

function onKey(e) {
  if (e.key !== 'Escape' || props.busy) return;
  // Con dos ventanas abiertas (p. ej. una confirmación sobre un formulario) solo se cierra la de arriba
  const overlays = document.querySelectorAll('.fin-modal-overlay');
  if (overlays[overlays.length - 1] === root.value) emit('close');
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
  <div ref="root" class="fin-modal-overlay" @click.self="!busy && emit('close')">
    <div class="fin-modal" :class="{ wide }" role="dialog" aria-modal="true" :aria-label="title">
      <div class="fin-modal-head">
        <div>
          <h2>{{ title }}</h2>
          <p v-if="subtitle">{{ subtitle }}</p>
        </div>
        <button class="fin-icon-btn" type="button" aria-label="Cerrar" :disabled="busy" @click="emit('close')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M18 6 6 18M6 6l12 12" /></svg>
        </button>
      </div>
      <div class="fin-modal-body">
        <slot />
      </div>
      <div v-if="$slots.footer" class="fin-modal-foot">
        <slot name="footer" />
      </div>
    </div>
  </div>
</template>
