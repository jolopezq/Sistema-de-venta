<script setup>
import { computed, ref } from 'vue';
import { useFinanceStore, formatBs, formatPercent, MONTHS } from '../../stores/finance';

/**
 * Punto de equilibrio del mes, en la columna lateral del Resumen: una frase con lo que importa (el día en que se
 * cubrieron los costos fijos, o cuánto falta), la barra de los días del mes y tres cifras. El detalle de cómo se
 * calcula (márgenes por canal, costo por recetas frente al real y la merma) queda a un clic
 * (docs/PLAN_COSTOS_PUNTO_EQUILIBRIO.md §3).
 */
const emit = defineEmits(['navigate']);
const finance = useFinanceStore();

const be = computed(() => finance.breakEven);
const point = computed(() => be.value?.break_even || null);
const progress = computed(() => be.value?.progress || null);
const showFixed = ref(false);
const showDetail = ref(false);

const ACTIONS = {
  recurring: { label: 'Cargar gastos fijos', tab: 'recurring', intent: '' },
  costs: { label: 'Ir a Costos y margen', tab: 'costs', intent: '' },
};

const monthIndex = computed(() => (be.value?.period?.month || finance.month) - 1);
const monthName = computed(() => MONTHS[monthIndex.value] || '');
const monthShort = computed(() => monthName.value.slice(0, 3).toLowerCase());

/** La frase principal según cómo va el mes. */
const lead = computed(() => {
  const p = progress.value;
  if (!p || !point.value) return '';
  const m = monthName.value.toLowerCase();
  if (p.status === 'reached') {
    if (p.reached_day) {
      return `Cubriste los costos fijos el ${p.reached_day} de ${m}. Desde ese día, cada venta ${p.closed ? 'dejó' : 'deja'} ganancia.`;
    }
    return `Ya cubriste los costos fijos del mes: lo que se vende ahora deja ganancia.`;
  }
  if (p.status === 'not_started') return 'El mes todavía no empieza.';
  if (p.status === 'missed') {
    return `${monthName.value} no cubrió los costos fijos: faltaron ${formatBs(point.value.amount - p.sales_to_date)} en ventas.`;
  }
  return `Llevas ${formatBs(p.sales_to_date)} de ${formatBs(point.value.amount)} necesarios para cubrir los costos fijos.`;
});

/** Segunda frase para el mes en curso: a qué ritmo se va. */
const pace = computed(() => {
  const p = progress.value;
  if (!p || p.closed || !['on_track', 'behind'].includes(p.status)) return '';
  const when = p.reach_day && p.reach_day <= p.days
    ? `A este ritmo los cubres el día ${p.reach_day}`
    : 'A este ritmo no los cubres este mes';
  const result = p.projected_profit >= 0 ? 'ganancia' : 'pérdida';
  return `${when}; al cierre quedaría una ${result} de ${formatBs(Math.abs(p.projected_profit))}.`;
});

/** Barra de días cuando se sabe el día del equilibrio; si no, barra de lo vendido frente a lo necesario. */
const dayBar = computed(() => {
  const p = progress.value;
  if (!p || p.status !== 'reached' || !p.reached_day) return null;
  return { before: Math.max(0, p.reached_day - 1), after: p.days - p.reached_day + 1, day: p.reached_day, days: p.days };
});
const salesBar = computed(() => {
  const p = progress.value;
  if (!p || dayBar.value || p.status === 'not_started') return null;
  const tick = point.value && !p.closed ? (p.expected_to_date / point.value.amount) * 100 : null;
  return {
    fill: Math.min(100, p.percent || 0),
    tick: tick !== null && tick > 0 && tick < 100 ? tick : null,
  };
});
</script>

<template>
  <section class="be" aria-labelledby="be-title">
    <h3 id="be-title">Punto de equilibrio</h3>

    <div v-if="finance.errors.breakEven" class="fin-error" style="margin: 0">{{ finance.errors.breakEven }}</div>
    <p v-else-if="!be" class="be-muted">Calculando…</p>

    <template v-else>
      <template v-if="point && progress">
        <p class="be-lead">{{ lead }}</p>
        <p v-if="pace" class="be-muted">{{ pace }}</p>

        <div v-if="dayBar">
          <div class="be-bar" role="img" :aria-label="`Equilibrio alcanzado el día ${dayBar.day} de ${dayBar.days}`">
            <div v-if="dayBar.before" class="be-seg covering" :style="{ flexGrow: dayBar.before }"></div>
            <div class="be-seg profit" :style="{ flexGrow: dayBar.after }"></div>
          </div>
          <div class="be-days fin-num">
            <span v-if="dayBar.before" :style="{ flexGrow: dayBar.before }">1 {{ monthShort }}</span>
            <span class="be-days-after" :style="{ flexGrow: dayBar.after }">
              <strong>{{ dayBar.day }} {{ monthShort }}</strong><span>{{ dayBar.days }} {{ monthShort }}</span>
            </span>
          </div>
          <ul class="be-legend">
            <li><span class="be-swatch covering" aria-hidden="true"></span>Cubriendo costos fijos</li>
            <li><span class="be-swatch profit" aria-hidden="true"></span>Ganancia</li>
          </ul>
        </div>

        <div v-else-if="salesBar">
          <div
            class="be-meter"
            role="meter"
            :aria-valuenow="progress.percent"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="`Ventas del mes: ${formatPercent(progress.percent)} del punto de equilibrio`"
          >
            <div class="be-meter-fill" :style="{ width: `${salesBar.fill}%` }"></div>
            <div v-if="salesBar.tick !== null" class="be-meter-tick" :style="{ left: `${salesBar.tick}%` }" aria-hidden="true"></div>
          </div>
          <p class="be-meter-legend fin-num">
            <span>Vendido {{ formatPercent(progress.percent) }}</span>
            <span v-if="salesBar.tick !== null">Esperado al día {{ progress.elapsed_days }}: {{ formatBs(progress.expected_to_date) }}</span>
          </p>
        </div>

        <dl class="be-facts">
          <div><dt>Ventas necesarias en el mes</dt><dd class="fin-num">{{ formatBs(point.amount) }}</dd></div>
          <div><dt>Meta por día</dt><dd class="fin-num">{{ formatBs(point.daily) }}</dd></div>
          <div>
            <dt>Margen de contribución<template v-if="be.contribution.based_on === 'previous_month'"> (del mes anterior)</template></dt>
            <dd class="fin-num">{{ be.contribution.percent !== null ? formatPercent(be.contribution.percent) : '—' }}</dd>
          </div>
          <div>
            <dt>
              Costos fijos del mes
              <button class="fin-btn-link" type="button" :aria-expanded="showFixed" @click="showFixed = !showFixed">{{ showFixed ? 'ocultar' : 'ver' }}</button>
            </dt>
            <dd class="fin-num">{{ formatBs(be.fixed.total) }}</dd>
          </div>
        </dl>
        <ul v-if="showFixed" class="be-fixed">
          <li v-for="f in be.fixed.items" :key="`${f.source}-${f.name}`">
            <span>{{ f.name }}<template v-if="f.note"> ({{ f.note }})</template><template v-if="f.source === 'month'">, del mes</template></span>
            <span class="fin-num">{{ formatBs(f.amount) }}</span>
          </li>
          <li v-if="!be.fixed.items.length"><span>Sin gastos fijos cargados</span></li>
        </ul>
      </template>

      <!-- Sin punto de equilibrio (faltan gastos fijos o margen): los avisos dicen qué cargar -->
      <p v-else class="be-lead">Todavía no se puede calcular cuánto hay que vender para cubrir los costos fijos.</p>

      <ul v-if="be.warnings?.length" class="be-warnings">
        <li v-for="w in be.warnings" :key="w.code">
          <span>{{ w.message }}</span>
          <button
            v-if="ACTIONS[w.action]"
            class="fin-btn fin-btn-ghost fin-btn-sm"
            type="button"
            @click="emit('navigate', ACTIONS[w.action].tab, ACTIONS[w.action].intent)"
          >
            {{ ACTIONS[w.action].label }}
          </button>
        </li>
      </ul>

      <button class="fin-btn-link be-more" type="button" :aria-expanded="showDetail" @click="showDetail = !showDetail">
        {{ showDetail ? 'Ocultar de dónde sale' : 'Ver de dónde sale' }}
      </button>
      <div v-if="showDetail" class="be-detail">
        <div class="fin-pairs">
          <div v-if="be.contribution.local_percent !== null" class="fin-pair">
            <span>Margen del local</span><span class="fin-num">{{ formatPercent(be.contribution.local_percent) }}</span>
          </div>
          <div v-if="be.contribution.pedidosya_percent !== null" class="fin-pair">
            <span>Margen de PedidosYa (después de la plataforma)</span><span class="fin-num">{{ formatPercent(be.contribution.pedidosya_percent) }}</span>
          </div>
          <div class="fin-pair">
            <span>Costo de productos según recetas</span>
            <span class="fin-num">{{ be.variable.source === 'recipes' ? formatPercent(be.variable.cost_ratio) : '—' }}</span>
          </div>
          <div v-if="be.real_cost_of_sales.percent !== null && be.real_cost_of_sales.total > 0" class="fin-pair">
            <span>Costo real (compras del mes)</span><span class="fin-num">{{ formatPercent(be.real_cost_of_sales.percent) }}</span>
          </div>
        </div>
        <p v-if="be.variable.source === 'recipes' && be.real_cost_of_sales.total > 0 && !be.cost_gap" class="be-muted">
          Si el costo real supera al de las recetas, hay merma, desperdicio o porciones más grandes que las de la receta.
        </p>
        <div v-if="be.cost_gap" class="fin-pairs be-gap">
          <div class="fin-pair"><span>Compras del mes (costo real)</span><strong class="fin-num">{{ formatBs(be.cost_gap.real) }}</strong></div>
          <div class="fin-pair"><span>Costo según recetas</span><strong class="fin-num">{{ formatBs(be.cost_gap.recipes) }}</strong></div>
          <div class="fin-pair"><span>Diferencia</span><strong class="fin-num">{{ formatBs(be.cost_gap.difference, { sign: true }) }}</strong></div>
          <div class="fin-pair be-sub"><span>Merma anotada</span><span class="fin-num">{{ formatBs(be.cost_gap.registered_waste) }}</span></div>
          <div class="fin-pair be-sub">
            <span>Merma oculta (conteos)</span>
            <span class="fin-num">{{ be.cost_gap.hidden_lines ? formatBs(be.cost_gap.hidden_waste) : 'sin medir' }}<template v-if="be.cost_gap.hidden_lines && be.cost_gap.hidden_waste < 0"> (sobrante)</template></span>
          </div>
          <div class="fin-pair be-sub"><span>Compras que siguen en stock y otros</span><span class="fin-num">{{ formatBs(be.cost_gap.other, { sign: true }) }}</span></div>
        </div>
        <p v-if="be.cost_gap" class="be-muted">La merma oculta sale de los conteos físicos (Inventario → Conteo): lo que faltó sin que nadie lo anotara.</p>
      </div>
    </template>
  </section>
</template>

<style scoped>
.be { display: flex; flex-direction: column; gap: 14px; padding: 22px 22px 20px; }
.be h3 { margin: 0; font-family: var(--font-heading); font-size: 19px; line-height: 1.25; font-weight: 700; color: var(--ink-900); }
.be-lead { margin: 0; font-size: 15.5px; line-height: 1.5; color: var(--ink-900); text-wrap: pretty; }
.be-muted { margin: 0; font-size: 13px; line-height: 1.5; color: var(--ink-500); }

.be-bar { display: flex; gap: 3px; height: 14px; }
.be-seg { flex-basis: 0; min-width: 4px; }
.be-seg:first-child { border-radius: 7px 2px 2px 7px; }
.be-seg:last-child { border-radius: 2px 7px 7px 2px; }
.be-seg:only-child { border-radius: 7px; }
.be-seg.covering, .be-swatch.covering { background: var(--fin-seg-2); }
.be-seg.profit, .be-swatch.profit { background: var(--fin-keep); }
.be-days { display: flex; gap: 3px; margin-top: 8px; font-size: 12.5px; color: var(--ink-500); }
.be-days > span { flex-basis: 0; min-width: 0; }
.be-days-after { display: flex; justify-content: space-between; gap: 6px; }
.be-days-after strong { color: var(--acai-text); font-weight: 600; }
.be-legend { list-style: none; margin: 10px 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: 6px 18px; font-size: 12.5px; color: var(--ink-700); }
.be-legend li { display: flex; align-items: center; gap: 7px; }
.be-swatch { width: 11px; height: 11px; border-radius: 3px; flex-shrink: 0; }

.be-meter { position: relative; height: 14px; border-radius: 999px; background: var(--fin-seg-1); overflow: hidden; }
.be-meter-fill { height: 100%; border-radius: 999px; background: var(--fin-seg-3); }
.be-meter-tick { position: absolute; top: 0; bottom: 0; width: 2px; background: var(--ink-900); transform: translateX(-1px); }
.be-meter-legend { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 4px 10px; margin: 8px 0 0; font-size: 12.5px; color: var(--ink-500); }

.be-facts { margin: 0; display: flex; flex-direction: column; }
.be-facts > div { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; padding: 9px 0; border-top: 1px solid var(--border); font-size: 14px; }
.be-facts dt { color: var(--ink-700); }
.be-facts dd { margin: 0; font-weight: 600; color: var(--ink-900); }
.be-fixed { list-style: none; margin: -6px 0 0; padding: 0 0 0 12px; display: flex; flex-direction: column; gap: 6px; font-size: 13px; color: var(--ink-500); }
.be-fixed li { display: flex; justify-content: space-between; gap: 10px; }

.be-warnings { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.be-warnings li { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; padding: 12px 14px; border-radius: 12px; background: var(--gold-100); color: var(--gold-text); font-size: 13.5px; line-height: 1.45; }
.be-warnings .fin-btn { background: var(--surface); color: var(--ink-900); }

.be-more { align-self: flex-start; }
.be-detail { display: flex; flex-direction: column; gap: 10px; }
.be-gap { padding-top: 10px; border-top: 1px solid var(--border); }
.be-detail .fin-pair { font-size: 13px; }
.be-sub { padding-left: 12px; color: var(--ink-500); }
</style>
