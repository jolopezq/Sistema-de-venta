<script setup>
/**
 * Estado con icono y texto (en meta, cerca, fuera, informativo): el color nunca va solo.
 * `tone`: ok | warn | err | info | '' (sin medir).
 */
defineProps({
  tone: { type: String, default: '' },
  label: { type: String, required: true },
});
</script>

<template>
  <span class="fin-status" :class="tone">
    <svg v-if="tone === 'ok'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
    <svg v-else-if="tone === 'warn'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v6" /><path d="M12 16.5v.5" /></svg>
    <svg v-else-if="tone === 'err'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14" /></svg>
    {{ label }}
  </span>
</template>
