<script setup>
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useFinanceStore, formatBs, formatPercent } from '../../stores/finance';
import { useReorderStore } from '../../stores/reorder';
import { formatQty, REORDER_STATUS } from '../../utils/inventoryLabels';
import FinanceMonthHero from './FinanceMonthHero.vue';
import FinanceStatementTable from './FinanceStatementTable.vue';
import FinanceBreakEven from './FinanceBreakEven.vue';
import FinanceInventoryIndicators from './FinanceInventoryIndicators.vue';
import FinanceStatus from './FinanceStatus.vue';

/**
 * Pestaña Resumen (dirección visual del 09/10, evolución de la identidad de Ohana):
 *  1. el resultado del mes como una frase, la barra de cada Bs 100 y lo que falta cargar para que sea definitivo;
 *  2. a la izquierda el Estado de Resultados y PedidosYa; a la derecha el punto de equilibrio y la reposición;
 *  3. los indicadores de inventario como tabla.
 */
const finance = useFinanceStore();
const emit = defineEmits(['navigate']);
const navigate = (tab, intent) => emit('navigate', tab, intent);

// Reposición del inventario (F4): lo que conviene pedir hoy, calculado por el servidor al momento
const router = useRouter();
const reorder = useReorderStore();
onMounted(() => reorder.fetchSummary());
const stock = computed(() => reorder.summary);

const pnl = computed(() => finance.pnl);
const pya = computed(() => pnl.value?.pedidosya_analysis || null);

/** Tono del estado de un insumo por reponer (agotado y crítico, urgentes; un vencido no tiene estado de reorden). */
const stockTone = (status) => REORDER_STATUS[status]?.tone || 'err';
</script>

<template>
  <div v-if="finance.loading.pnl && !pnl" class="fin-card fin-loading">Calculando el Estado de Resultados…</div>
  <div v-else-if="finance.errors.pnl" class="fin-card"><div class="fin-error">{{ finance.errors.pnl }}</div></div>

  <template v-else-if="pnl">
    <FinanceMonthHero :pnl="pnl" :updating="finance.loading.pnl" @navigate="navigate" />

    <div class="sum-grid">
      <div class="sum-main">
        <FinanceStatementTable :pnl="pnl" />

        <section v-if="pya" class="fin-card sum-pya" aria-labelledby="sum-pya-title">
          <div class="sum-pya-head">
            <h3 id="sum-pya-title">PedidosYa en el mes</h3>
            <p>
              <template v-if="pya.settlements_count">
                {{ pya.settlements_count }} {{ pya.settlements_count === 1 ? 'semana importada' : 'semanas importadas' }}<template v-if="pya.prorated">; una cruza meses y se prorratea</template>.
              </template>
              <template v-else>Aún no hay semanas importadas en este mes.</template>
            </p>
            <button
              v-if="pya.settlements_count"
              class="fin-btn-link"
              type="button"
              @click="navigate('pedidosya', 'list')"
            >
              Ver semanas
            </button>
            <button v-else class="fin-btn fin-btn-ghost" type="button" @click="navigate('pedidosya', 'import')">Cargar semana</button>
          </div>
          <template v-if="pya.settlements_count">
            <dl class="sum-pya-facts fin-num">
              <div><dt>Vendido en la plataforma</dt><dd>{{ formatBs(pya.gross_sales) }}</dd></div>
              <div><dt>Se quedó PedidosYa</dt><dd>{{ formatBs(pya.retention_amount) }} <span>({{ formatPercent(pya.retention_percent) }})</span></dd></div>
              <div><dt>Te depositaron</dt><dd>{{ formatBs(pya.total_payout) }}</dd></div>
            </dl>
            <p class="sum-pya-note">
              {{ pya.delivered_count }} {{ pya.delivered_count === 1 ? 'pedido entregado' : 'pedidos entregados' }},
              ticket promedio {{ formatBs(pya.average_ticket) }}<template v-if="pya.cancelled_count">, {{ pya.cancelled_count }} {{ pya.cancelled_count === 1 ? 'cancelado' : 'cancelados' }}</template>.
              Ventas netas {{ formatBs(pya.net_sales) }}.
            </p>
          </template>
        </section>
      </div>

      <aside class="fin-card sum-side" aria-label="Punto de equilibrio y reposición">
        <FinanceBreakEven @navigate="navigate" />

        <section v-if="stock" class="sum-stock" aria-labelledby="sum-stock-title">
          <h3 id="sum-stock-title">Reposición</h3>
          <p>
            <template v-if="stock.to_reorder">
              {{ stock.to_reorder }} {{ stock.to_reorder === 1 ? 'insumo por reponer' : 'insumos por reponer' }}<template v-if="stock.estimated_cost > 0">, unos {{ formatBs(stock.estimated_cost) }}</template>.
            </template>
            <template v-else>Nada por reponer ahora.</template>
          </p>
          <ul v-if="stock.urgent?.length" class="sum-stock-list">
            <li v-for="u in stock.urgent.slice(0, 4)" :key="u.id">
              <span>{{ u.name }}, quedan <span class="fin-num">{{ formatQty(u.stock) }} {{ u.unit }}</span></span>
              <FinanceStatus :tone="stockTone(u.status)" :label="REORDER_STATUS[u.status]?.label || 'Vencido'" />
            </li>
          </ul>
          <p v-if="stock.expiring" class="sum-stock-note">{{ stock.expiring }} {{ stock.expiring === 1 ? 'insumo vence' : 'insumos vencen' }} pronto o ya {{ stock.expiring === 1 ? 'venció' : 'vencieron' }}.</p>
          <p v-if="stock.no_data" class="sum-stock-note">{{ stock.no_data }} {{ stock.no_data === 1 ? 'insumo sin datos' : 'insumos sin datos' }} para calcular su reposición.</p>
          <button class="fin-btn fin-btn-ghost" type="button" @click="router.push({ path: '/inventario', query: { compras: '1' } })">Ver compras sugeridas</button>
        </section>
      </aside>
    </div>

    <FinanceInventoryIndicators />
  </template>
</template>

<style scoped>
.sum-grid { display: flex; flex-wrap: wrap; gap: 22px; align-items: flex-start; }
.sum-main { flex: 999 1 560px; min-width: 0; display: flex; flex-direction: column; gap: 22px; }
.sum-side { flex: 1 1 320px; min-width: 0; display: flex; flex-direction: column; }

.sum-pya { padding: 20px 22px 18px; display: flex; flex-direction: column; gap: 14px; }
.sum-pya-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 14px; }
.sum-pya-head h3, .sum-stock h3 { margin: 0; font-family: var(--font-heading); font-size: 19px; line-height: 1.25; font-weight: 700; color: var(--ink-900); }
.sum-pya-head p { flex: 1 1 auto; margin: 0; font-size: 14px; color: var(--ink-700); }
.sum-pya-facts { margin: 0; display: flex; flex-wrap: wrap; gap: 14px 40px; }
.sum-pya-facts dt { font-size: 13px; color: var(--ink-500); }
.sum-pya-facts dd { margin: 4px 0 0; font-size: 20px; font-weight: 600; color: var(--ink-900); }
.sum-pya-facts dd span { font-size: 14px; font-weight: 500; color: var(--ink-500); }
.sum-pya-note { margin: 0; font-size: 13px; line-height: 1.5; color: var(--ink-500); }

.sum-stock { display: flex; flex-direction: column; gap: 12px; padding: 20px 22px 22px; border-top: 1px solid var(--border); }
.sum-stock > p { margin: 0; font-size: 14px; line-height: 1.5; color: var(--ink-700); }
.sum-stock-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.sum-stock-list li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 9px 0; border-top: 1px solid var(--border); font-size: 14px; color: var(--ink-900); }
.sum-stock .sum-stock-note { font-size: 13px; color: var(--ink-500); }
.sum-stock .fin-btn { align-self: flex-start; }

@media (max-width: 760px) {
  .sum-grid, .sum-main { gap: 18px; }
  .sum-pya { padding: 18px 16px; }
  .sum-pya-facts { gap: 12px 28px; }
}
</style>
