<script setup>
import { computed, ref } from 'vue';
import { useFinanceStore, MONTHS } from '../../stores/finance';
import { indicatorRows, rowBadge } from '../../utils/inventoryIndicators';
import InventoryIndicatorsModal from './InventoryIndicatorsModal.vue';
import FinanceStatus from './FinanceStatus.vue';

/**
 * Indicadores de inventario del mes (plan de inventario, F5 §5) como tabla: indicador, valor, meta, estado y qué
 * mirar, del que está peor al que está mejor. El informe mensual imprimible sigue en «Informe del mes».
 */
const finance = useFinanceStore();
const data = computed(() => finance.inventoryIndicators);
const reportOpen = ref(false);
// Las etiquetas usan el mes de los propios datos: mientras carga otro mes siguen mostrándose los anteriores
const periodText = computed(() => (data.value ? `${MONTHS[data.value.period.month - 1]} ${data.value.period.year}` : ''));

const ORDER = { err: 0, warn: 1, ok: 2, none: 3, info: 4 };
const rows = computed(() => indicatorRows(data.value)
  .map((r) => {
    const badge = rowBadge(r);
    const statusKey = r.informative ? 'info' : (r.status || 'none');
    return {
      ...r,
      badge,
      statusKey,
      meta: metaText(r),
      // Lo que conviene revisar solo se muestra cuando el indicador no está en meta
      note: r.hint && r.status !== 'ok' ? r.hint : '',
    };
  })
  .sort((a, b) => ORDER[a.statusKey] - ORDER[b.statusKey]));

function metaText(r) {
  if (r.informative) return 'Sin meta';
  if (r.key === 'coverage') return data.value?.coverage?.key_only ? 'Ningún insumo clave por reponer' : 'Ningún insumo por reponer';
  return r.goal.replace(/^Meta:\s*/, '').replace(/^./, (c) => c.toUpperCase());
}

const summary = computed(() => {
  const count = (k) => rows.value.filter((r) => r.statusKey === k).length;
  const parts = [];
  if (count('ok')) parts.push(`${count('ok')} en meta`);
  if (count('warn')) parts.push(`${count('warn')} cerca de la meta`);
  if (count('err')) parts.push(`${count('err')} fuera de meta`);
  if (count('none')) parts.push(`${count('none')} sin medir`);
  if (!parts.length) return '';
  const list = parts.length > 1 ? `${parts.slice(0, -1).join(', ')} y ${parts.at(-1)}` : parts[0];
  return `${list.charAt(0).toUpperCase()}${list.slice(1)}.`;
});
</script>

<template>
  <div class="fin-card inv">
    <div class="fin-card-head inv-head">
      <div>
        <h2>Inventario</h2>
        <p v-if="data">{{ summary }} El valor y la cobertura son de hoy; el resto, de {{ periodText }}.</p>
        <p v-else>Cuánto vale, cuánto dura, qué se pierde y si el conteo coincide con el estante.</p>
      </div>
      <div class="fin-row">
        <span v-if="data && finance.loading.inventoryIndicators" class="fin-small fin-muted" role="status">Actualizando…</span>
        <button class="fin-btn fin-btn-ghost" type="button" :disabled="!data" @click="reportOpen = true">Informe del mes</button>
      </div>
    </div>

    <div v-if="finance.errors.inventoryIndicators" class="fin-error">{{ finance.errors.inventoryIndicators }}</div>
    <div v-else-if="!data" class="fin-loading">Calculando los indicadores…</div>

    <div v-else class="fin-table-wrap" :aria-busy="finance.loading.inventoryIndicators">
      <table class="fin-table inv-table">
        <thead>
          <tr>
            <th scope="col">Indicador</th>
            <th scope="col" class="right">Valor</th>
            <th scope="col">Meta</th>
            <th scope="col">Estado</th>
            <th scope="col">Qué mirar</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.key">
            <td class="inv-name">
              {{ r.label }}
              <span class="inv-scope">{{ r.scope === 'hoy' ? 'Hoy' : periodText }}</span>
            </td>
            <td class="right inv-value fin-num">{{ r.value }}</td>
            <td class="inv-meta" :class="{ 'no-goal': r.informative }">{{ r.meta }}</td>
            <td class="inv-status"><FinanceStatus :tone="r.badge.tone" :label="r.badge.label" /></td>
            <td class="inv-detail">
              {{ r.detail }}
              <span v-if="r.note" class="inv-note">{{ r.note }}</span>
              <span v-if="r.previous" class="inv-prev">{{ r.previous }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <InventoryIndicatorsModal v-if="reportOpen" @close="reportOpen = false" />
</template>

<style scoped>
.inv-head { align-items: flex-start; }
.inv-head p { max-width: 720px; }
.inv-table { font-size: 14px; }
.inv-table td { vertical-align: top; padding-top: 12px; padding-bottom: 12px; }
.inv-name { font-weight: 600; min-width: 200px; }
.inv-scope { display: block; margin-top: 2px; font-size: 12.5px; font-weight: 400; color: var(--ink-500); }
.inv-value { font-weight: 600; }
.inv-meta { color: var(--ink-700); min-width: 160px; }
.inv-detail { color: var(--ink-700); min-width: 220px; }
.inv-note, .inv-prev { display: block; margin-top: 4px; font-size: 12.5px; color: var(--ink-500); }

/* Celular: cada indicador como una ficha de lista (nombre y valor arriba, meta, estado y detalle debajo) */
@media (max-width: 760px) {
  .inv-table thead { display: none; }
  .inv-table tr { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 6px 12px; padding: 14px 16px; border-bottom: 1px solid var(--border); }
  .inv-table tr:last-child { border-bottom: none; }
  .inv-table td { display: block; padding: 0; border: 0; min-width: 0; }
  .inv-table td.inv-value { text-align: right; }
  .inv-table td.inv-meta, .inv-table td.inv-status, .inv-table td.inv-detail { grid-column: 1 / -1; }
  .inv-table td.inv-meta { font-size: 13px; color: var(--ink-500); }
  .inv-table td.inv-meta::before { content: 'Meta: '; }
  .inv-table td.inv-meta.no-goal { display: none; }
  .inv-table td.inv-detail { font-size: 13px; }
}
</style>
