<script setup>
/**
 * Estado con icono y texto para Vender y Caja (completada, anulada, abierto…): el color nunca va solo.
 * `tone`: ok | warn | err | info | '' (neutro). Es la misma pieza que FinanceStatus, fuera de Finanzas.
 */
defineProps({
  tone: { type: String, default: '' },
  label: { type: String, required: true },
});
</script>

<template>
  <span class="status-tag" :class="tone">
    <svg v-if="tone === 'ok'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
    <svg v-else-if="tone === 'warn'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v6" /><path d="M12 16.5v.5" /></svg>
    <svg v-else-if="tone === 'err'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14" /></svg>
    {{ label }}
  </span>
</template>

<style scoped>
.status-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px 3px 8px;
  border-radius: 999px;
  font-size: 12.5px;
  font-weight: 600;
  white-space: nowrap;
  background: var(--cream-200);
  color: var(--ink-700);
}
.status-tag svg { width: 13px; height: 13px; flex-shrink: 0; }
.status-tag.ok { background: var(--lime-100); color: var(--lime-700); }
.status-tag.warn { background: var(--gold-100); color: var(--gold-text); }
.status-tag.err { background: var(--danger-100); color: var(--danger-text); }
.status-tag.info { background: var(--acai-50); color: var(--acai-text); }
</style>
