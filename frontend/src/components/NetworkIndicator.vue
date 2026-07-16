<script setup>
import { useNetworkStore } from '../stores/network';

const network = useNetworkStore();
</script>

<template>
  <div class="network-badge" :class="{'is-offline': !network.isOnline, 'is-syncing': network.isSyncing}">
    <span v-if="!network.isOnline" class="dot red"></span>
    <span v-else-if="network.isSyncing" class="dot yellow"></span>
    <span v-else class="dot green"></span>
    
    <span class="text">
      {{ !network.isOnline ? 'Modo Offline' : (network.isSyncing ? 'Sincronizando...' : 'En Línea') }}
    </span>
  </div>
</template>

<style scoped>
.network-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  background: var(--bg-secondary);
  border: 1px solid var(--border-color);
  border-radius: 2rem;
  font-size: 0.85rem;
  font-weight: 600;
  box-shadow: var(--shadow-sm);
  transition: all 0.3s ease;
}
.network-badge.is-offline {
  background-color: #FEF2F2;
  border-color: #FCA5A5;
  color: var(--color-danger);
}
.network-badge.is-syncing {
  background-color: #FEF3C7;
  border-color: #FCD34D;
  color: var(--color-warning);
}
.dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  display: inline-block;
}
.dot.green { 
  background-color: var(--color-success); 
  box-shadow: 0 0 8px var(--color-success);
}
.dot.red { 
  background-color: var(--color-danger); 
}
.dot.yellow { 
  background-color: var(--color-warning); 
  animation: pulse 1.5s infinite;
}

@keyframes pulse {
  0% { opacity: 1; }
  50% { opacity: 0.5; }
  100% { opacity: 1; }
}
</style>
