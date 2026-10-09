<script setup>
import { computed } from 'vue';
import { useNetworkStore } from '../stores/network';

const network = useNetworkStore();

/** Estado en palabras: el punto de color nunca va solo. */
const stateText = computed(() => {
  if (!network.isOnline) return 'Sin conexión';
  if (network.isSyncing) return 'Enviando ventas';
  return 'En línea';
});
</script>

<template>
  <!-- Usa .net-dot y .count de style.css -->
  <span class="net-indicator" role="status">
    <span
      class="net-dot"
      :class="{
        'offline': !network.isOnline,
        'syncing': network.isOnline && network.isSyncing,
        'online': network.isOnline && !network.isSyncing
      }"
      aria-hidden="true"
    ></span>
    <span
      v-if="network.failedSyncCount > 0"
      class="count failed"
      title="El servidor no aceptó estas ventas. Revísalas en Caja: bloquean el cierre."
    >
      {{ network.failedSyncCount }} {{ network.failedSyncCount === 1 ? 'venta rechazada' : 'ventas rechazadas' }}
    </span>
    <span
      v-if="network.pendingSyncCount > 0"
      class="count"
      title="Ventas guardadas en este equipo que todavía no llegaron al servidor; se envían solas al volver la conexión."
    >
      {{ network.pendingSyncCount }} {{ network.pendingSyncCount === 1 ? 'venta por enviar' : 'ventas por enviar' }}
    </span>
    <span v-else-if="network.failedSyncCount === 0" class="net-text">{{ stateText }}</span>
  </span>
</template>

<style scoped>
.net-indicator { display: inline-flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--ink-700); }
.net-indicator .count {
  padding: 2px 9px;
  border-radius: 999px;
  background: var(--cream-200);
  color: var(--ink-900);
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}
.net-indicator .count.failed { background: var(--danger-100); color: var(--danger-text); }
</style>
