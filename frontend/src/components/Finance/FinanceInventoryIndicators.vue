<script setup>
import { computed, ref } from 'vue';
import { useFinanceStore, MONTHS } from '../../stores/finance';
import { indicatorRows, indicatorsSummary, statusOf } from '../../utils/inventoryIndicators';
import InventoryIndicatorsModal from './InventoryIndicatorsModal.vue';

/**
 * Indicadores de inventario del mes (plan de inventario, F5 §5): valor, cobertura, quiebres, merma, diferencia de
 * costo y exactitud del conteo, cada uno con su estado frente a la meta y el informe mensual imprimible.
 */
const finance = useFinanceStore();
const data = computed(() => finance.inventoryIndicators);
const rows = computed(() => indicatorRows(data.value));
const summary = computed(() => indicatorsSummary(rows.value));
const reportOpen = ref(false);
// Las etiquetas usan el mes de los propios datos: mientras carga otro mes siguen mostrándose los anteriores
const periodText = computed(() => (data.value ? `${MONTHS[data.value.period.month - 1]} ${data.value.period.year}` : ''));
</script>

<template>
  <div class="fin-card ind-card">
    <div class="fin-card-head">
      <div>
        <h2>Indicadores de inventario</h2>
        <p>Si el inventario está bien llevado: cuánto vale, cuánto dura, qué se pierde y si el conteo coincide con el estante.</p>
      </div>
      <div class="fin-row">
        <span v-if="data && finance.loading.inventoryIndicators" class="fin-small fin-muted" role="status">Actualizando…</span>
        <span v-if="data" class="fin-badge" :class="summary.tone"><span class="fin-dot" aria-hidden="true"></span>{{ summary.label }}</span>
        <button class="fin-btn fin-btn-secondary fin-btn-sm" type="button" :disabled="!data" @click="reportOpen = true">Informe del mes</button>
      </div>
    </div>

    <div v-if="finance.errors.inventoryIndicators" class="fin-error">{{ finance.errors.inventoryIndicators }}</div>
    <div v-else-if="!data" class="fin-loading">Calculando los indicadores…</div>

    <div v-else class="ind-grid" :aria-busy="finance.loading.inventoryIndicators">
      <article v-for="r in rows" :key="r.key" class="ind-tile" :aria-label="r.label">
        <header class="ind-top">
          <span class="ind-label">{{ r.label }}<span class="ind-scope"> · {{ r.scope === 'hoy' ? 'hoy' : periodText }}</span></span>
          <span v-if="r.status" class="fin-badge" :class="statusOf(r.status).tone">{{ statusOf(r.status).label }}</span>
        </header>
        <span class="ind-value fin-num">{{ r.value }}</span>
        <span class="ind-goal">{{ r.goal }}</span>
        <span class="ind-detail">{{ r.detail }}</span>
        <span v-if="r.previous" class="ind-prev">{{ r.previous }}</span>
        <span v-if="r.hint && r.status !== 'ok'" class="ind-hint">{{ r.hint }}</span>
      </article>
    </div>

    <div v-if="data" class="fin-card-foot">
      Las metas son iniciales. El valor del inventario y la cobertura son de hoy; los demás indicadores son del mes elegido.
    </div>
  </div>

  <InventoryIndicatorsModal v-if="reportOpen" @close="reportOpen = false" />
</template>

<style scoped>
.fin-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; display: inline-block; }
.ind-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; padding: 16px 18px; }
.ind-tile { display: flex; flex-direction: column; gap: 4px; padding: 13px 14px; border: 1px solid var(--border); border-radius: 12px; background: var(--cream-100); min-width: 0; }
.ind-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; flex-wrap: wrap; }
.ind-label { font-size: 12.5px; font-weight: 700; color: var(--ink-700); }
.ind-scope { font-weight: 500; color: var(--ink-500); }
.ind-value { font-family: var(--font-heading); font-size: 26px; font-weight: 700; line-height: 1.15; color: var(--ink-900); }
.ind-goal { font-size: 12px; font-weight: 600; color: var(--ink-700); }
.ind-detail, .ind-prev { font-size: 12px; color: var(--ink-500); overflow-wrap: anywhere; }
.ind-hint { margin-top: 4px; padding-top: 6px; border-top: 1px dashed var(--border); font-size: 12px; color: var(--ink-700); }
@media (max-width: 1100px) {
  .ind-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 760px) {
  .ind-grid { padding: 12px; grid-template-columns: minmax(0, 1fr); }
}
</style>
