<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue';

/**
 * Ventana modal del módulo de Finanzas: cabecera, cuerpo con scroll y pie de acciones.
 *
 * Teclado (la caja se usa con teclado y touchpad):
 * - Al abrir, el foco entra a la ventana: al campo marcado con `data-autofocus` (aunque aparezca después de
 *   cargar datos) o, si no hay, a la ventana misma. En pantallas táctiles no se enfoca un campo, para no
 *   abrir el teclado en pantalla.
 * - Tab y Mayús+Tab no salen de la ventana; Escape la cierra (solo la de arriba, y no mientras `busy`).
 * - Al cerrar, el foco vuelve al botón que la abrió.
 */
const props = defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  wide: { type: Boolean, default: false },
  /** Ancho: '' (520 px), 'wide' (760 px) o 'xl' (920 px, formularios de tres columnas). `wide` se mantiene por compatibilidad. */
  size: { type: String, default: '', validator: (v) => ['', 'wide', 'xl'].includes(v) },
  busy: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const root = ref(null);
const dialog = ref(null);
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
let opener = null;
let observer = null;
let observerTimer = null;

function isTop() {
  // Con dos ventanas abiertas (p. ej. una confirmación sobre un formulario) solo responde la de arriba
  const overlays = document.querySelectorAll('.fin-modal-overlay');
  return overlays[overlays.length - 1] === root.value;
}

function focusables() {
  return [...(dialog.value?.querySelectorAll(FOCUSABLE) || [])].filter((el) => el.getClientRects().length > 0);
}

function onKey(e) {
  if (!isTop()) return;
  if (e.key === 'Escape') {
    if (!props.busy) emit('close');
    return;
  }
  if (e.key !== 'Tab' || !dialog.value) return;
  const items = focusables();
  const active = document.activeElement;
  if (!items.length) { e.preventDefault(); dialog.value.focus(); return; }
  const first = items[0];
  const last = items[items.length - 1];
  if (!dialog.value.contains(active)) { e.preventDefault(); (e.shiftKey ? last : first).focus(); }
  else if (e.shiftKey && (active === first || active === dialog.value)) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && active === last) { e.preventDefault(); first.focus(); }
}

function stopObserving() {
  observer?.disconnect();
  observer = null;
  clearTimeout(observerTimer);
}

function focusMarked() {
  const target = dialog.value?.querySelector('[data-autofocus]');
  if (!target) return false;
  target.focus({ preventScroll: true });
  return true;
}

function focusInitial() {
  const d = dialog.value;
  if (!d || d.contains(document.activeElement)) return; // el contenido ya eligió dónde empezar
  const finePointer = window.matchMedia?.('(pointer: fine)').matches ?? true;
  if (finePointer && focusMarked()) return;
  d.focus({ preventScroll: true });
  if (!finePointer) return;
  // El campo marcado puede aparecer después (el formulario espera sus opciones): se enfoca entonces,
  // salvo que la persona ya haya movido el foco.
  observer = new MutationObserver(() => {
    if (document.activeElement !== d) { stopObserving(); return; }
    if (focusMarked()) stopObserving();
  });
  observer.observe(d, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-autofocus'] });
  observerTimer = setTimeout(stopObserving, 4000);
}

onMounted(() => {
  opener = document.activeElement;
  window.addEventListener('keydown', onKey);
  nextTick(focusInitial);
});

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey);
  stopObserving();
  const active = document.activeElement;
  const focusWasHere = !active || active === document.body || dialog.value?.contains(active);
  if (focusWasHere && opener && opener !== document.body && opener.isConnected) opener.focus({ preventScroll: true });
});
</script>

<template>
  <div ref="root" class="fin-modal-overlay" @click.self="!busy && emit('close')">
    <div ref="dialog" class="fin-modal" :class="{ wide: wide || size === 'wide', xl: size === 'xl' }" role="dialog" aria-modal="true" :aria-label="title" tabindex="-1">
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
