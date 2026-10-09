<script setup>
import { computed, ref } from 'vue';
import { useFinanceStore, formatBs, formatPercent } from '../../stores/finance';

/**
 * Punto de equilibrio del mes: cuánto hay que vender para cubrir los costos fijos, la meta diaria y cómo va el mes
 * (docs/PLAN_COSTOS_PUNTO_EQUILIBRIO.md §3).
 */
const emit = defineEmits(['navigate']);
const finance = useFinanceStore();

const be = computed(() => finance.breakEven);
const point = computed(() => be.value?.break_even || null);
const progress = computed(() => be.value?.progress || null);
const showFixed = ref(false);

const fill = computed(() => Math.min(100, progress.value?.percent || 0));
const tick = computed(() => {
  if (!point.value || !progress.value) return null;
  const v = (progress.value.expected_to_date / point.value.amount) * 100;
  return v > 0 && v < 100 ? v : null;
});

const STATUS = {
  reached: { label: 'Punto de equilibrio alcanzado', kind: 'ok' },
  on_track: { label: 'Vas al ritmo necesario', kind: 'ok' },
  behind: { label: 'Por debajo del ritmo necesario', kind: 'warn' },
  missed: { label: 'No se alcanzó en el mes', kind: 'err' },
  not_started: { label: 'El mes aún no empieza', kind: '' },
};

const ACTIONS = {
  recurring: { label: 'Cargar gastos fijos', tab: 'recurring', intent: '' },
  costs: { label: 'Ir a Costos y margen', tab: 'costs', intent: '' },
};

function units(n) {
  return Number(n).toLocaleString('es-BO', { maximumFractionDigits: 1 });
}
</script>

<template>
  <div class="fin-card">
    <div class="fin-card-head">
      <div>
        <h2>Punto de equilibrio</h2>
        <p>Lo que hay que vender en el mes para cubrir los costos fijos con el margen de lo que se vende.</p>
      </div>
      <span v-if="progress && STATUS[progress.status]" class="fin-badge" :class="STATUS[progress.status].kind">
        <span class="fin-dot" aria-hidden="true"></span>{{ STATUS[progress.status].label }}
      </span>
    </div>

    <div v-if="finance.errors.breakEven" class="fin-error">{{ finance.errors.breakEven }}</div>
    <div v-else-if="!be" class="fin-loading">Calculando…</div>

    <div v-else class="fin-be">
      <div v-if="point" class="fin-be-main">
        <div class="fin-be-figure">
          <span class="fin-be-value">{{ formatBs(point.amount) }}</span>
          <span class="fin-be-unit">al mes</span>
        </div>
        <p class="fin-be-target">
          Meta diaria <strong>{{ formatBs(point.daily) }}</strong>
          <template v-if="point.units_per_day"> · ≈ <strong>{{ units(point.units_per_day) }} productos por día</strong> (precio promedio {{ formatBs(point.unit_price) }})</template>
        </p>

        <div v-if="progress" class="fin-meter-wrap">
          <div
            class="fin-meter"
            role="meter"
            :aria-valuenow="progress.percent"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="`Ventas del mes: ${formatPercent(progress.percent)} del punto de equilibrio`"
          >
            <div class="fin-meter-fill" :style="{ width: `${fill}%` }"></div>
            <div v-if="tick !== null" class="fin-meter-tick" :style="{ left: `${tick}%` }" aria-hidden="true"></div>
          </div>
          <div class="fin-meter-legend">
            <span>Vendido <strong>{{ formatBs(progress.sales_to_date) }}</strong> · {{ formatPercent(progress.percent) }}</span>
            <span v-if="tick !== null" class="fin-muted"><span class="fin-tick-key" aria-hidden="true"></span> Lo esperado al día {{ progress.elapsed_days }}: {{ formatBs(progress.expected_to_date) }}</span>
          </div>
          <p v-if="progress.closed" class="fin-be-projection">
            El mes cerró con {{ formatBs(progress.sales_to_date) }} de ventas<template v-if="progress.status === 'missed'"> (faltaron {{ formatBs(point.amount - progress.sales_to_date) }})</template>:
            <strong :class="{ neg: progress.projected_profit < 0, pos: progress.projected_profit >= 0 }">{{ progress.projected_profit >= 0 ? 'ganancia' : 'pérdida' }} de {{ formatBs(Math.abs(progress.projected_profit)) }}</strong>
            según el margen de contribución.
          </p>
          <p v-else-if="progress.status !== 'not_started'" class="fin-be-projection">
            <template v-if="progress.status === 'reached'">Desde aquí, cada venta deja su margen de contribución como ganancia.</template>
            <template v-else-if="progress.reach_day && progress.reach_day <= progress.days">A este ritmo ({{ formatBs(progress.pace) }} por día) se alcanza el día {{ progress.reach_day }}.</template>
            <template v-else>A este ritmo ({{ formatBs(progress.pace) }} por día) no se alcanza este mes.</template>
            Proyección al cierre: {{ formatBs(progress.projection) }}, con
            <strong :class="{ neg: progress.projected_profit < 0, pos: progress.projected_profit >= 0 }">{{ progress.projected_profit >= 0 ? 'ganancia' : 'pérdida' }} de {{ formatBs(Math.abs(progress.projected_profit)) }}</strong>.
          </p>
        </div>
      </div>

      <div class="fin-be-side">
        <div class="fin-pairs">
          <div class="fin-pair">
            <span>Margen de contribución</span>
            <strong class="fin-num">{{ be.contribution.percent !== null ? formatPercent(be.contribution.percent) : '—' }}</strong>
          </div>
          <div v-if="be.contribution.local_percent !== null" class="fin-pair fin-pair-sub">
            <span>Local</span><span class="fin-num">{{ formatPercent(be.contribution.local_percent) }}</span>
          </div>
          <div v-if="be.contribution.pedidosya_percent !== null" class="fin-pair fin-pair-sub">
            <span>PedidosYa (después de la plataforma)</span><span class="fin-num">{{ formatPercent(be.contribution.pedidosya_percent) }}</span>
          </div>
          <div class="fin-pair">
            <span>
              Costos fijos del mes
              <button class="fin-btn-link" type="button" :aria-expanded="showFixed" @click="showFixed = !showFixed">{{ showFixed ? 'ocultar' : 'ver' }}</button>
            </span>
            <strong class="fin-num">{{ formatBs(be.fixed.total) }}</strong>
          </div>
          <template v-if="showFixed">
            <div v-for="f in be.fixed.items" :key="`${f.source}-${f.name}`" class="fin-pair fin-pair-sub">
              <span>{{ f.name }}<template v-if="f.note"> ({{ f.note }})</template><template v-if="f.source === 'month'"> · del mes</template></span>
              <span class="fin-num">{{ formatBs(f.amount) }}</span>
            </div>
            <div v-if="!be.fixed.items.length" class="fin-pair fin-pair-sub"><span>Sin gastos fijos cargados</span></div>
          </template>
          <div class="fin-pair">
            <span>Costo de productos según recetas</span>
            <strong class="fin-num">{{ be.variable.source === 'recipes' ? formatPercent(be.variable.cost_ratio) : '—' }}</strong>
          </div>
          <div v-if="be.real_cost_of_sales.percent !== null && be.real_cost_of_sales.total > 0" class="fin-pair fin-pair-sub">
            <span>Costo real (compras del mes)</span><span class="fin-num">{{ formatPercent(be.real_cost_of_sales.percent) }}</span>
          </div>
        </div>
        <p v-if="be.variable.source === 'recipes' && be.real_cost_of_sales.total > 0 && !be.cost_gap" class="fin-hint" style="margin: 8px 0 0">
          Si el costo real supera al de las recetas, hay merma, desperdicio o porciones más grandes que las de la receta.
        </p>

        <!-- Costo real frente a recetas: cuánto se explica por merma anotada y por la que encontró el conteo -->
        <div v-if="be.cost_gap" class="fin-gap">
          <div class="fin-pairs">
            <div class="fin-pair"><span>Compras del mes (costo real)</span><strong class="fin-num">{{ formatBs(be.cost_gap.real) }}</strong></div>
            <div class="fin-pair"><span>Costo según recetas</span><strong class="fin-num">{{ formatBs(be.cost_gap.recipes) }}</strong></div>
            <div class="fin-pair"><span>Diferencia</span><strong class="fin-num">{{ formatBs(be.cost_gap.difference, { sign: true }) }}</strong></div>
            <div class="fin-pair fin-pair-sub"><span>Merma anotada</span><span class="fin-num">{{ formatBs(be.cost_gap.registered_waste) }}</span></div>
            <div class="fin-pair fin-pair-sub">
              <span>Merma oculta (conteos)</span>
              <span class="fin-num">{{ be.cost_gap.hidden_lines ? formatBs(be.cost_gap.hidden_waste) : 'sin medir' }}<template v-if="be.cost_gap.hidden_lines && be.cost_gap.hidden_waste < 0"> (sobrante)</template></span>
            </div>
            <div class="fin-pair fin-pair-sub"><span>Compras que siguen en stock y otros</span><span class="fin-num">{{ formatBs(be.cost_gap.other, { sign: true }) }}</span></div>
          </div>
          <p class="fin-hint" style="margin: 6px 0 0">
            La merma oculta sale de los conteos físicos (Inventario → Conteo): lo que faltó sin que nadie lo anotara.
          </p>
        </div>
      </div>
    </div>

    <div v-if="be?.warnings?.length" class="fin-warnings fin-be-warnings">
      <div v-for="w in be.warnings" :key="w.code" class="fin-warning">
        <span class="fin-warning-dot" aria-hidden="true"></span>
        <div class="fin-warning-text">
          {{ w.message }}
          <div v-if="ACTIONS[w.action]" style="margin-top: 4px">
            <button class="fin-btn-link" type="button" @click="emit('navigate', ACTIONS[w.action].tab, ACTIONS[w.action].intent)">{{ ACTIONS[w.action].label }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.fin-be { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(260px, 1fr); gap: 24px; padding: 16px 18px; }
.fin-be-figure { display: flex; align-items: baseline; gap: 8px; }
.fin-be-value { font-family: var(--font-heading); font-size: 34px; font-weight: 700; line-height: 1.1; color: var(--ink-900); }
.fin-be-unit { font-size: 14px; color: var(--ink-500); }
.fin-be-target { margin: 4px 0 14px; font-size: 13.5px; color: var(--ink-700); }
.fin-be-target strong { color: var(--ink-900); }
.fin-meter-wrap { display: flex; flex-direction: column; gap: 6px; }
.fin-meter {
  position: relative;
  height: 14px;
  border-radius: 999px;
  background: var(--acai-50);
  border: 1px solid var(--border);
  overflow: hidden;
}
.fin-meter-fill { height: 100%; border-radius: 999px; background: var(--acai-500); transition: width .3s ease; }
.fin-meter-tick { position: absolute; top: -1px; bottom: -1px; width: 2px; background: var(--ink-700); transform: translateX(-1px); }
.fin-tick-key { display: inline-block; width: 2px; height: 10px; background: var(--ink-700); vertical-align: middle; margin-right: 2px; }
.fin-meter-legend { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; font-size: 12.5px; color: var(--ink-700); }
.fin-meter-legend strong { color: var(--ink-900); }
.fin-be-projection { margin: 6px 0 0; font-size: 13px; line-height: 1.5; color: var(--ink-700); }
.fin-gap { margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--border); }
.fin-be-side .fin-pair-sub { padding-left: 14px; font-size: 12.5px; color: var(--ink-500); }
.fin-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; display: inline-block; }
.fin-be-warnings { border-top: 1px solid var(--border); }
@media (max-width: 1180px) {
  .fin-be { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 760px) {
  .fin-be { padding: 12px 14px; gap: 16px; }
  .fin-be-value { font-size: 28px; }
}
</style>
