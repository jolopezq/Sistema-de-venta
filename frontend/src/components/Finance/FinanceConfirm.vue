<script setup>
import { useFinanceStore } from '../../stores/finance';
import FinanceModal from './FinanceModal.vue';

/** Confirmación del sistema (reemplaza a window.confirm): se abre con finance.confirm({...}). */
const finance = useFinanceStore();
</script>

<template>
  <FinanceModal v-if="finance.dialog.open" :title="finance.dialog.title" @close="finance.closeDialog(false)">
    <p class="fin-confirm-text">{{ finance.dialog.message }}</p>
    <template #footer>
      <button class="fin-btn fin-btn-secondary" type="button" @click="finance.closeDialog(false)">Cancelar</button>
      <button
        class="fin-btn"
        :class="finance.dialog.danger ? 'fin-btn-danger' : 'fin-btn-primary'"
        type="button"
        @click="finance.closeDialog(true)"
      >
        {{ finance.dialog.confirmLabel }}
      </button>
    </template>
  </FinanceModal>
</template>

<style scoped>
.fin-confirm-text { margin: 0; font-size: 14px; line-height: 1.5; color: var(--ink-700); white-space: pre-line; }
</style>
