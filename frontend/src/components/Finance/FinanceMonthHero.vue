<script setup>
import { computed } from 'vue';
import { MONTHS } from '../../stores/finance';

/**
 * Resultado del mes como una frase («Septiembre cerró con Bs 9.094 de utilidad»), la barra de qué queda de cada
 * Bs 100 vendidos y, pegado al resultado, lo que falta cargar para que sea definitivo (avisos del Estado de
 * Resultados con su acción). Dirección visual: evolución de la identidad de Ohana (maqueta del 09/10).
 */
const props = defineProps({
  pnl: { type: Object, required: true },
  // Mientras llega otro mes se siguen viendo estas cifras: se avisa para no mezclarlas con el mes nuevo
  updating: { type: Boolean, default: false },
});
const emit = defineEmits(['navigate']);

const ACTIONS = {
  add_purchase: { label: 'Registrar compra', tab: 'expenses', intent: 'new-expense' },
  classify_cash: { label: 'Clasificar egresos', tab: 'expenses', intent: 'cash' },
  generate_recurring: { label: 'Revisar gastos fijos', tab: 'recurring', intent: 'period' },
  import_pedidosya: { label: 'Cargar semana', tab: 'pedidosya', intent: 'import' },
  view_pedidosya: { label: 'Ver semana', tab: 'pedidosya', intent: 'list' },
};

const NBSP = ' ';
/** «Bs 9.094» sin decimales, con el número pegado a «Bs». */
const whole = (n) => `Bs${NBSP}${Math.round(Math.abs(Number(n) || 0)).toLocaleString('es-BO')}`;
/** «Bs 18,70» de cada Bs 100. */
const per100Text = (n) => `Bs${NBSP}${Math.abs(n).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const period = computed(() => props.pnl.period || {});
const monthName = computed(() => MONTHS[(period.value.month || 1) - 1] || '');
/** future | current | closed, según el mes de los propios datos (no el que se está cargando). */
const timing = computed(() => {
  const now = new Date();
  const key = (period.value.year || 0) * 12 + (period.value.month || 0);
  const nowKey = now.getFullYear() * 12 + now.getMonth() + 1;
  if (key > nowKey) return 'future';
  return key === nowKey ? 'current' : 'closed';
});
const closed = computed(() => timing.value === 'closed');

const revenue = computed(() => Number(props.pnl.revenue?.total) || 0);
const net = computed(() => Number(props.pnl.net_profit?.amount) || 0);

const title = computed(() => {
  const m = monthName.value;
  if (timing.value === 'future') return `${m} todavía no empieza`;
  if (revenue.value <= 0) return closed.value ? `${m} no tuvo ventas` : `${m} todavía no tiene ventas`;
  const kind = net.value >= 0 ? 'utilidad' : 'pérdida';
  return `${m} ${closed.value ? 'cerró' : 'va'} con ${whole(net.value)} de ${kind}`;
});

const lead = computed(() => {
  if (revenue.value <= 0) return `Cuando haya ventas, aquí verás cuánto te queda de cada Bs${NBSP}100 vendidos.`;
  const per100 = per100Text((net.value / revenue.value) * 100);
  if (net.value >= 0) {
    return `Vendiste ${whole(revenue.value)}. Después de costos, gastos e impuestos, de cada Bs${NBSP}100 te ${closed.value ? 'quedaron' : 'quedan'} ${per100}.`;
  }
  return `Vendiste ${whole(revenue.value)}, pero los costos y gastos sumaron ${whole(revenue.value - net.value)}: por cada Bs${NBSP}100 vendidos ${closed.value ? 'faltaron' : 'faltan'} ${per100}.`;
});

/** Barra de cada Bs 100: los costos en tonos neutros y, al final, lo que queda en açaí. */
const bar = computed(() => {
  const r = revenue.value;
  if (r <= 0) return null;
  const p = props.pnl;
  const costs = [
    { key: 'cogs', label: 'Costo de ventas', amount: p.cost_of_sales?.total, color: 'var(--fin-seg-1)' },
    { key: 'opex', label: 'Gastos operativos', amount: p.operating_expenses?.total, color: 'var(--fin-seg-2)' },
    { key: 'fin', label: 'Gastos financieros', amount: p.financial_expenses?.total, color: 'var(--fin-seg-3)' },
    { key: 'tax', label: 'Impuestos', amount: p.taxes?.total, color: 'var(--fin-seg-4)' },
  ]
    .map((c) => ({ ...c, per100: ((Number(c.amount) || 0) / r) * 100 }))
    .filter((c) => c.per100 >= 0.005);
  const keep = (net.value / r) * 100;
  const costTotal = costs.reduce((s, c) => s + c.per100, 0);
  const basis = Math.max(100, costTotal + Math.max(0, keep));
  const parts = [...costs];
  if (keep >= 0.005) {
    parts.push({ key: 'keep', label: closed.value ? 'Te quedaron' : 'Te quedan', per100: keep, color: 'var(--fin-keep)', keep: true });
  }
  return {
    parts: parts.map((c) => ({ ...c, width: (c.per100 / basis) * 100, text: per100Text(c.per100) })),
    // Con pérdida los costos pasan de 100: una marca muestra dónde llegaron las ventas
    marker: costTotal > 100.005 ? (100 / basis) * 100 : null,
    shortfall: keep < 0 ? per100Text(keep) : null,
    label: `De cada Bs 100 vendidos: ${parts.map((c) => `${c.label.toLowerCase()} ${per100Text(c.per100)}`).join(', ')}`,
  };
});

const warnings = computed(() => props.pnl.warnings || []);
const trustTitle = computed(() => {
  const n = warnings.value.length;
  const sentence = closed.value ? 'este resultado sea definitivo' : 'este resultado esté completo';
  return n === 1 ? `Falta 1 dato para que ${sentence}` : `Faltan ${n} datos para que ${sentence}`;
});
</script>

<template>
  <section class="hero" aria-labelledby="hero-title" :aria-busy="updating">
    <p v-if="updating" class="hero-updating" role="status">Actualizando…</p>
    <div class="hero-text">
      <h2 id="hero-title" class="hero-title">{{ title }}</h2>
      <p class="hero-lead">{{ lead }}</p>
    </div>

    <div v-if="bar" class="hero-bar-wrap">
      <div class="hero-bar" role="img" :aria-label="bar.label">
        <div
          v-for="(c, i) in bar.parts"
          :key="c.key"
          class="hero-seg"
          :class="{ first: i === 0, last: i === bar.parts.length - 1 }"
          :style="{ flexGrow: c.width, background: c.color }"
        ></div>
        <div v-if="bar.marker !== null" class="hero-marker" :style="{ left: `${bar.marker}%` }" aria-hidden="true"></div>
      </div>
      <ul class="hero-legend">
        <li v-for="c in bar.parts" :key="c.key" :class="{ keep: c.keep }">
          <span class="hero-swatch" :style="{ background: c.color }" aria-hidden="true"></span>
          {{ c.label }} <strong class="fin-num">{{ c.text }}</strong>
        </li>
        <li v-if="bar.shortfall" class="short">
          <span class="hero-swatch marker" aria-hidden="true"></span>
          La marca es lo vendido: {{ closed ? 'faltaron' : 'faltan' }} <strong class="fin-num">{{ bar.shortfall }}</strong> de cada Bs&nbsp;100
        </li>
      </ul>
    </div>

    <div class="hero-trust">
      <template v-if="warnings.length">
        <p class="hero-trust-title">{{ trustTitle }}</p>
        <ul>
          <li v-for="(w, i) in warnings" :key="w.code + i">
            <span class="hero-trust-text">
              <svg v-if="w.level === 'info'" class="info" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 11v6" /><path d="M12 7.5v.5" /></svg>
              <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v6" /><path d="M12 16.5v.5" /></svg>
              {{ w.message }}
            </span>
            <button
              v-if="ACTIONS[w.action]"
              class="fin-btn fin-btn-primary fin-btn-lg"
              type="button"
              @click="emit('navigate', ACTIONS[w.action].tab, ACTIONS[w.action].intent)"
            >
              {{ ACTIONS[w.action].label }}
            </button>
          </li>
        </ul>
      </template>
      <p v-else class="hero-trust-ok">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
        Datos del mes completos: compras, egresos de caja, gastos fijos y semanas de PedidosYa.
      </p>
    </div>
  </section>
</template>

<style scoped>
.hero {
  position: relative;
  background: var(--fin-band);
  border-radius: 24px;
  padding: 34px 40px 30px;
  display: flex;
  flex-direction: column;
  gap: 24px;
}
.hero-text { display: flex; flex-direction: column; gap: 10px; max-width: 1000px; }
.hero-updating {
  position: absolute;
  top: 16px;
  right: 22px;
  margin: 0;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--surface);
  font-size: 12.5px;
  font-weight: 600;
  color: var(--ink-700);
}
.hero-title {
  margin: 0;
  font-family: var(--font-heading);
  font-size: 44px;
  line-height: 1.05;
  font-weight: 700;
  letter-spacing: -0.01em;
  color: var(--ink-900);
  text-wrap: balance;
}
.hero-lead {
  margin: 0;
  max-width: 660px;
  font-size: 17px;
  line-height: 1.5;
  color: var(--ink-700);
  text-wrap: pretty;
  font-variant-numeric: tabular-nums;
}
.hero-bar { position: relative; display: flex; gap: 3px; height: 32px; }
.hero-seg { flex-basis: 0; flex-shrink: 1; min-width: 4px; border-radius: 3px; }
.hero-seg.first { border-top-left-radius: 10px; border-bottom-left-radius: 10px; }
.hero-seg.last { border-top-right-radius: 10px; border-bottom-right-radius: 10px; }
.hero-marker {
  position: absolute;
  top: -6px;
  bottom: -6px;
  width: 3px;
  border-radius: 2px;
  background: var(--danger-500);
  transform: translateX(-1px);
}
.hero-legend {
  list-style: none;
  margin: 14px 0 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 10px 26px;
  font-size: 14px;
  color: var(--ink-700);
}
.hero-legend li { display: flex; align-items: center; gap: 8px; }
.hero-legend strong { color: var(--ink-900); }
.hero-legend li.keep, .hero-legend li.keep strong { color: var(--acai-text); font-weight: 700; }
.hero-legend li.short, .hero-legend li.short strong { color: var(--danger-text); }
.hero-swatch { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; }
.hero-swatch.marker { width: 4px; background: var(--danger-500); }

.hero-trust { background: var(--surface); border-radius: 18px; padding: 6px 22px; }
.hero-trust-title { margin: 14px 0 6px; font-size: 15px; font-weight: 600; color: var(--ink-900); }
.hero-trust ul { list-style: none; margin: 0; padding: 0; }
.hero-trust li {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px 20px;
  padding: 12px 0;
  border-top: 1px solid var(--border);
}
.hero-trust-text {
  flex: 1 1 280px;
  display: flex;
  gap: 10px;
  align-items: flex-start;
  font-size: 14.5px;
  line-height: 1.45;
  color: var(--ink-700);
}
.hero-trust-text svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; color: var(--gold-text); }
.hero-trust-text svg.info { color: var(--teal-700); }
.hero-trust-ok {
  margin: 0;
  padding: 14px 0;
  display: flex;
  gap: 10px;
  align-items: center;
  font-size: 14.5px;
  color: var(--ink-700);
}
.hero-trust-ok svg { width: 18px; height: 18px; flex-shrink: 0; color: var(--lime-700); }

@media (max-width: 760px) {
  .hero { padding: 24px 20px 20px; border-radius: 20px; gap: 20px; }
  .hero-title { font-size: 30px; }
  .hero-lead { font-size: 16px; }
  .hero-legend { gap: 8px 18px; font-size: 13.5px; }
  .hero-trust { padding: 4px 16px; }
  .hero-trust li .fin-btn { width: 100%; }
}
</style>
