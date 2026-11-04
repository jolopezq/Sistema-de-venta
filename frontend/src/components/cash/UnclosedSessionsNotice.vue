<script setup>
import { computed } from 'vue';
import { useAuthStore } from '../../stores/auth';

/**
 * Cajas de días anteriores que siguen abiertas (docs/PLAN_CONCILIACION_CAJA.md, D): su efectivo no entra en lo
 * retirado del mes. Lleva a Control diario en ese día (o lo pide a un administrador sin ese permiso).
 */
const props = defineProps({
  sessions: { type: Array, default: () => [] },
  /** En Control diario: cambia de día sin salir de la pantalla */
  inPlace: { type: Boolean, default: false },
});
const emit = defineEmits(['select-date']);
const auth = useAuthStore();
const canClose = computed(() => auth.hasPermission('sales_history', 'edit'));

const day = (iso) => {
  const [, m, d] = String(iso || '').split('-');
  return d && m ? `${d}/${m}` : '';
};
</script>

<template>
  <div v-if="sessions.length" class="usn" role="status" data-testid="unclosed-sessions">
    <p class="usn-title">
      {{ sessions.length === 1 ? 'Una caja de un día anterior sigue abierta' : `${sessions.length} cajas de días anteriores siguen abiertas` }}
    </p>
    <ul>
      <li v-for="s in sessions" :key="s.session_id">
        <span>Caja del {{ day(s.date) }}<template v-if="s.cashier"> · {{ s.cashier }}</template></span>
        <template v-if="canClose">
          <button v-if="inPlace" type="button" class="usn-link" @click="emit('select-date', s.date)">Cerrarla</button>
          <router-link v-else class="usn-link" :to="{ name: 'RetroactiveSales', query: { fecha: s.date } }">Cerrarla en Control diario</router-link>
        </template>
      </li>
    </ul>
    <p v-if="!canClose" class="usn-help">Pide a un administrador que la cierre en Control diario.</p>
  </div>
</template>

<style scoped>
.usn { padding: 10px 12px; border-radius: 10px; background: var(--gold-100); color: var(--ink-900); font-size: 13px; display: flex; flex-direction: column; gap: 4px; }
.usn-title { margin: 0; font-weight: 700; color: var(--gold-text); }
.usn ul { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 2px; }
.usn li { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 4px 12px; }
.usn-link { font: inherit; font-weight: 600; color: var(--acai-text); background: none; border: 0; padding: 0; cursor: pointer; text-decoration: underline; text-underline-offset: 2px; }
.usn-link:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; border-radius: 3px; }
.usn-help { margin: 0; font-size: 12px; color: var(--ink-700); }
html.dark .usn { background: rgba(250, 209, 92, 0.12); }
</style>
