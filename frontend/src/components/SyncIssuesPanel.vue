<script setup>
import { ref, watch, onMounted } from 'vue';
import { useNetworkStore } from '../stores/network';
import { listFailedSales } from '../services/syncService';

/**
 * Ventas de este dispositivo que todavía no están en ninguna caja: las pendientes de enviar y las que
 * el servidor rechazó (con su motivo). Se muestra en Turno y en la revisión previa al cierre de caja,
 * porque su efectivo ya está en la gaveta pero no en el esperado.
 */
const network = useNetworkStore();
const failed = ref([]);
const working = ref(false);

async function loadFailed() {
  try {
    failed.value = await listFailedSales();
  } catch (e) {
    console.warn('No se pudieron leer las ventas rechazadas:', e);
  }
}

onMounted(loadFailed);
watch(() => [network.failedSyncCount, network.pendingSyncCount], loadFailed);

async function retry() {
  working.value = true;
  try {
    await network.retryFailed();
  } finally {
    working.value = false;
    await loadFailed();
  }
}

async function sendNow() {
  working.value = true;
  try {
    await network.syncNow();
  } finally {
    working.value = false;
    await loadFailed();
  }
}

function saleTotal(sale) {
  return `Bs ${Number(sale.total_amount || 0).toFixed(2)}`;
}

function saleTime(sale) {
  if (!sale.created_at) return '';
  return new Date(sale.created_at).toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
  <div v-if="network.unsyncedCount > 0" class="sync-issues" role="status">
    <div class="si-head">
      <strong>
        {{ network.unsyncedCount }} {{ network.unsyncedCount === 1 ? 'venta no llegó' : 'ventas no llegaron' }} al servidor
      </strong>
      <span class="si-sub">Su cobro aún no cuenta en la caja: hay que enviarlas antes de cerrar.</span>
    </div>

    <p v-if="network.pendingSyncCount > 0" class="si-pending">
      {{ network.pendingSyncCount }} {{ network.pendingSyncCount === 1 ? 'pendiente de enviar' : 'pendientes de enviar' }}.
      <template v-if="!network.isOnline">Se enviarán cuando vuelva la conexión.</template>
    </p>

    <ul v-if="failed.length" class="si-list">
      <li v-for="sale in failed" :key="sale.id" class="si-item">
        <div class="si-item-main">
          <span class="si-ticket">Ticket {{ sale.order_number || sale.id.slice(0, 8) }}</span>
          <span class="si-meta">{{ saleTime(sale) }} · {{ saleTotal(sale) }}</span>
        </div>
        <div class="si-error">Rechazada: {{ sale.sync_error || 'el servidor no aceptó la venta.' }}</div>
      </li>
    </ul>

    <div class="si-actions">
      <button
        v-if="failed.length"
        class="btn btn-ghost btn-sm"
        type="button"
        :disabled="working || !network.isOnline"
        @click="retry"
      >
        {{ working ? 'Enviando…' : 'Reintentar envío' }}
      </button>
      <button
        v-else-if="network.pendingSyncCount > 0"
        class="btn btn-ghost btn-sm"
        type="button"
        :disabled="working || !network.isOnline || network.isSyncing"
        @click="sendNow"
      >
        {{ working || network.isSyncing ? 'Enviando…' : 'Enviar ahora' }}
      </button>
      <span v-if="failed.length" class="si-hint">
        Si sigue rechazada, avisa a un administrador: puede revisar el motivo y cerrar la caja dejando constancia.
      </span>
    </div>
  </div>
</template>

<style scoped>
.sync-issues {
  border: 2px solid var(--danger-500);
  background: var(--danger-100);
  border-radius: 14px;
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  color: var(--danger-text);
}
.si-head {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.si-head strong {
  font-size: 15px;
}
.si-sub,
.si-pending,
.si-hint {
  font-size: 13px;
  margin: 0;
}
.si-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.si-item {
  background: var(--surface);
  color: var(--ink-900);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 8px 12px;
}
.si-item-main {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-weight: 700;
  font-size: 14px;
}
.si-meta {
  font-weight: 600;
  color: var(--ink-700);
  white-space: nowrap;
}
.si-error {
  font-size: 13px;
  color: var(--ink-700);
  margin-top: 2px;
  overflow-wrap: anywhere;
}
.si-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.si-actions .btn {
  background: var(--surface);
}
</style>
