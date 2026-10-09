<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useReorderStore } from '../../stores/reorder';
import { formatQty, REORDER_STATUS, EXPIRY_LABEL, expiryText } from '../../utils/inventoryLabels';
import FinanceModal from '../Finance/FinanceModal.vue';
import '../Finance/finance.css';

/**
 * Aviso al abrir caja (plan de inventario, F4, §2.5): al administrador, una vez por turno, lo que está agotado,
 * crítico o por vencer. Las alertas se calculan al momento; aquí solo se muestran. No hay nada que confirmar.
 */
const emit = defineEmits(['close']);
const router = useRouter();
const reorder = useReorderStore();

const summary = computed(() => reorder.summary || {});
const urgent = computed(() => summary.value.urgent || []);
const expiring = computed(() => summary.value.expiring_items || []);

function openList() {
  emit('close');
  router.push({ path: '/inventario', query: { compras: '1' } });
}
</script>

<template>
  <div class="fin-page rn-host">
    <FinanceModal title="Antes de abrir" subtitle="Lo que conviene atender hoy en el inventario." @close="emit('close')">
      <section v-if="summary.to_reorder" class="rn-block">
        <h3>Por reponer: {{ summary.to_reorder }}</h3>
        <ul v-if="urgent.length" class="rn-list">
          <li v-for="u in urgent" :key="u.id">
            <span>{{ u.name }}</span>
            <span class="fin-badge" :class="REORDER_STATUS[u.status]?.tone || 'err'">{{ REORDER_STATUS[u.status]?.label || 'Vencido' }}</span>
            <span class="fin-num rn-qty">{{ formatQty(u.stock) }} {{ u.unit }}</span>
          </li>
        </ul>
        <p v-if="summary.to_reorder > urgent.length" class="fin-small fin-muted">y {{ summary.to_reorder - urgent.length }} más por debajo de su punto de reorden.</p>
      </section>

      <section v-if="expiring.length" class="rn-block">
        <h3>Vencen pronto: {{ summary.expiring }}</h3>
        <ul class="rn-list">
          <li v-for="e in expiring" :key="e.id">
            <span>{{ e.name }}</span>
            <span class="fin-badge" :class="e.expiry.level === 'expired' ? 'err' : 'warn'">{{ EXPIRY_LABEL[e.expiry.level] }}</span>
            <span class="fin-small fin-muted rn-qty">{{ expiryText(e.expiry) }}</span>
          </li>
        </ul>
      </section>

      <template #footer>
        <button class="fin-btn fin-btn-ghost" type="button" @click="emit('close')">Más tarde</button>
        <span class="fin-spacer"></span>
        <button v-if="summary.to_reorder" class="fin-btn fin-btn-primary" type="button" @click="openList">Ver compras sugeridas</button>
        <button v-else class="fin-btn fin-btn-primary" type="button" @click="emit('close')">Entendido</button>
      </template>
    </FinanceModal>
  </div>
</template>

<style scoped>
.rn-host { display: contents; }
.rn-block h3 { margin: 0 0 6px; font-size: 14px; color: var(--ink-900); }
.rn-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.rn-list li { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13.5px; color: var(--ink-900); }
.rn-qty { margin-left: auto; }
</style>
