<script setup>
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useFinanceStore, formatBs, formatPercent } from '../../stores/finance';
import { useReorderStore } from '../../stores/reorder';
import { formatQty, REORDER_STATUS } from '../../utils/inventoryLabels';
import FinanceStatementTable from './FinanceStatementTable.vue';
import FinanceBreakEven from './FinanceBreakEven.vue';

/** Pestaña Resumen: avisos de datos faltantes, indicadores, Estado de Resultados y canal PedidosYa. */
const finance = useFinanceStore();
const emit = defineEmits(['navigate']);

// Reposición del inventario (F4): lo que conviene pedir hoy, calculado por el servidor al momento
const router = useRouter();
const reorder = useReorderStore();
onMounted(() => reorder.fetchSummary());
const stock = computed(() => reorder.summary);

const pnl = computed(() => finance.pnl);
const warnings = computed(() => pnl.value?.warnings || []);
const pya = computed(() => pnl.value?.pedidosya_analysis || null);

const ACTIONS = {
  add_purchase: { label: 'Registrar compra', tab: 'expenses', intent: 'new-expense' },
  classify_cash: { label: 'Clasificar', tab: 'expenses', intent: 'cash' },
  generate_recurring: { label: 'Revisar y generar', tab: 'recurring', intent: 'period' },
  import_pedidosya: { label: 'Cargar semana', tab: 'pedidosya', intent: 'import' },
  view_pedidosya: { label: 'Ver semana', tab: 'pedidosya', intent: 'list' },
};

const kpis = computed(() => {
  const p = pnl.value;
  if (!p) return [];
  const r = p.revenue;
  return [
    {
      label: 'Ventas',
      value: formatBs(r.total),
      sub: `Local ${formatBs(r.local_sales)} · PedidosYa ${formatBs(Number(r.pedidosya_net) + Number(r.pedidosya_pos_unreconciled || 0))}`,
    },
    {
      label: 'Utilidad bruta',
      value: formatBs(p.gross_profit.amount),
      negative: p.gross_profit.amount < 0,
      sub: `Margen ${formatPercent(p.gross_profit.percentage)} · costo de ventas ${formatPercent(p.cost_of_sales.percentage)}`,
    },
    {
      label: 'Gastos operativos',
      value: formatBs(p.operating_expenses.total),
      sub: `${formatPercent(p.operating_expenses.percentage)} de las ventas`,
    },
    {
      label: 'Utilidad neta',
      value: formatBs(p.net_profit.amount),
      negative: p.net_profit.amount < 0,
      sub: `Margen neto ${formatPercent(p.net_profit.percentage)}`,
    },
  ];
});
</script>

<template>
  <div v-if="finance.loading.pnl && !pnl" class="fin-card fin-loading">Calculando el Estado de Resultados…</div>
  <div v-else-if="finance.errors.pnl" class="fin-card"><div class="fin-error">{{ finance.errors.pnl }}</div></div>

  <template v-else-if="pnl">
    <div class="fin-kpis">
      <div v-for="k in kpis" :key="k.label" class="fin-card fin-kpi">
        <span class="fin-kpi-label">{{ k.label }}</span>
        <span class="fin-kpi-value fin-num" :class="{ neg: k.negative }">{{ k.value }}</span>
        <span class="fin-kpi-sub">{{ k.sub }}</span>
      </div>
    </div>

    <FinanceBreakEven @navigate="(tab, intent) => emit('navigate', tab, intent)" />

    <div class="fin-summary">
      <FinanceStatementTable :pnl="pnl" />

      <div class="fin-side">
        <div class="fin-card">
          <div class="fin-card-head">
            <div>
              <h3>Para que el resultado sea real</h3>
              <p v-if="warnings.length">{{ warnings.length }} {{ warnings.length === 1 ? 'punto pendiente' : 'puntos pendientes' }} en el mes</p>
              <p v-else>Datos del mes completos</p>
            </div>
            <span class="fin-badge" :class="warnings.length ? 'warn' : 'ok'">{{ warnings.length ? 'Revisar' : 'Al día' }}</span>
          </div>
          <div v-if="warnings.length" class="fin-warnings">
            <div v-for="(w, i) in warnings" :key="w.code + i" class="fin-warning" :class="w.level">
              <span class="fin-warning-dot" aria-hidden="true"></span>
              <div class="fin-warning-text">
                {{ w.message }}
                <div v-if="ACTIONS[w.action]" style="margin-top: 4px">
                  <button class="fin-btn-link" type="button" @click="emit('navigate', ACTIONS[w.action].tab, ACTIONS[w.action].intent)">
                    {{ ACTIONS[w.action].label }}
                  </button>
                </div>
              </div>
            </div>
          </div>
          <div v-else class="fin-card-body fin-small fin-muted">
            Compras, egresos de caja, gastos fijos y semanas de PedidosYa del mes están cargados.
          </div>
        </div>

        <div v-if="stock" class="fin-card">
          <div class="fin-card-head">
            <div>
              <h3>Reposición del inventario</h3>
              <p v-if="stock.to_reorder">{{ stock.to_reorder }} {{ stock.to_reorder === 1 ? 'insumo por reponer' : 'insumos por reponer' }}<template v-if="stock.estimated_cost > 0"> · unos {{ formatBs(stock.estimated_cost) }}</template></p>
              <p v-else>Nada por reponer ahora</p>
            </div>
            <span class="fin-badge" :class="stock.critical || stock.out ? 'err' : stock.to_reorder ? 'warn' : 'ok'">{{ stock.critical || stock.out ? 'Urgente' : stock.to_reorder ? 'Reordenar' : 'Al día' }}</span>
          </div>
          <div class="fin-card-body">
            <div v-if="stock.urgent?.length" class="fin-pairs">
              <div v-for="u in stock.urgent.slice(0, 4)" :key="u.id" class="fin-pair">
                <span>{{ u.name }} <span class="fin-small fin-muted">({{ REORDER_STATUS[u.status]?.label || 'Vencido' }})</span></span>
                <strong class="fin-num">{{ formatQty(u.stock) }} {{ u.unit }}</strong>
              </div>
            </div>
            <p v-if="stock.expiring" class="fin-small fin-muted">{{ stock.expiring }} {{ stock.expiring === 1 ? 'insumo vence' : 'insumos vencen' }} pronto o ya {{ stock.expiring === 1 ? 'venció' : 'vencieron' }}.</p>
            <p v-if="stock.no_data" class="fin-small fin-muted">{{ stock.no_data }} {{ stock.no_data === 1 ? 'insumo sin datos' : 'insumos sin datos' }} para calcular su reposición.</p>
            <button class="fin-btn fin-btn-secondary fin-btn-sm" type="button" @click="router.push({ path: '/inventario', query: { compras: '1' } })">Ver compras sugeridas</button>
          </div>
        </div>

        <div v-if="pya" class="fin-card">
          <div class="fin-card-head">
            <div>
              <h3>PedidosYa en el mes</h3>
              <p>{{ pya.settlements_count }} {{ pya.settlements_count === 1 ? 'semana importada' : 'semanas importadas' }}<template v-if="pya.prorated"> · una cruza meses (prorrateada)</template></p>
            </div>
            <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="emit('navigate', 'pedidosya', 'list')">Ver semanas</button>
          </div>
          <div v-if="pya.settlements_count" class="fin-card-body">
            <div class="fin-pairs">
              <div class="fin-pair"><span>Ventas brutas ({{ pya.delivered_count }} pedidos)</span><strong class="fin-num">{{ formatBs(pya.gross_sales) }}</strong></div>
              <div class="fin-pair"><span>Ventas netas</span><strong class="fin-num">{{ formatBs(pya.net_sales) }}</strong></div>
              <div class="fin-pair"><span>Total liquidado (banco)</span><strong class="fin-num">{{ formatBs(pya.total_payout) }}</strong></div>
              <div class="fin-pair total">
                <span>Se queda PedidosYa</span>
                <span class="fin-num">{{ formatBs(pya.retention_amount) }} · {{ formatPercent(pya.retention_percent) }}</span>
              </div>
              <div class="fin-pair"><span>Ticket promedio</span><strong class="fin-num">{{ formatBs(pya.average_ticket) }}</strong></div>
              <div class="fin-pair"><span>Cancelados</span><strong class="fin-num">{{ pya.cancelled_count }}</strong></div>
            </div>
          </div>
          <div v-else class="fin-card-body fin-small fin-muted">
            Aún no hay semanas importadas en este mes.
          </div>
        </div>
      </div>
    </div>
  </template>
</template>
