<script setup>
import { useNetworkStore } from '../stores/network';

const network = useNetworkStore();
</script>

<template>
  <!-- Uses global .sync-pill, .net-dot, .count from style.css -->
  <span
    class="net-dot"
    :class="{
      'offline': !network.isOnline,
      'syncing': network.isOnline && network.isSyncing,
      'online': network.isOnline && !network.isSyncing
    }"
    :title="!network.isOnline ? 'Offline' : (network.isSyncing ? 'Sincronizando' : 'En línea')"
  ></span>
  <span
    v-if="network.failedSyncCount > 0"
    class="count failed"
    title="El servidor no aceptó estas ventas. Revísalas en Turno: bloquean el cierre de caja."
  >
    {{ network.failedSyncCount }} {{ network.failedSyncCount === 1 ? 'rechazada' : 'rechazadas' }}
  </span>
  <span v-if="network.pendingSyncCount > 0" class="count">
    🔄 {{ network.pendingSyncCount }} pendientes
  </span>
  <span v-else-if="network.failedSyncCount === 0" style="font-size:12px;">
    {{ !network.isOnline ? 'Offline' : (network.isSyncing ? 'Sincronizando' : 'En línea') }}
  </span>
</template>
