<script setup>
import { computed, ref } from 'vue';
import { formatBs, formatDay } from '../../stores/finance';

/**
 * Estado de Resultados como tabla (línea, Bs, % de ventas), con el desglose por categoría y por movimiento.
 * Los costos y gastos se muestran en negativo; los reintegros de PedidosYa, en positivo.
 */
const props = defineProps({
  pnl: { type: Object, required: true },
});

const open = ref(new Set());
function toggle(key) {
  const next = new Set(open.value);
  next.has(key) ? next.delete(key) : next.add(key);
  open.value = next;
}
const isOpen = (key) => open.value.has(key);

const revenue = computed(() => Number(props.pnl.revenue?.total) || 0);
/** % sobre ventas. Las líneas de costo van sin signo; los resultados (utilidades) con signo. */
function pct(amount, signed = false) {
  if (!revenue.value) return '—';
  const raw = ((Number(amount) || 0) / revenue.value) * 100;
  const v = signed ? raw : Math.abs(raw);
  const text = Math.abs(v).toLocaleString('es-BO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  return `${v < 0 ? '−' : ''}${text} %`;
}

function costSection(key, label, block) {
  const items = block?.items || [];
  return {
    key,
    label,
    amount: -(Number(block?.total) || 0),
    categories: (block?.by_category || []).map((c) => ({
      key: `${key}:${c.name}`,
      name: c.name,
      count: c.count,
      amount: -(Number(c.amount) || 0),
      items: items
        .filter((i) => i.category_name === c.name)
        .map((i) => ({ ...i, amount: -(Number(i.amount) || 0) })),
    })),
  };
}

const revenueChildren = computed(() => {
  const r = props.pnl.revenue || {};
  const rows = [
    { label: 'Ventas del local', detail: `${r.local_sales_count || 0} tickets`, amount: r.local_sales },
    { label: 'PedidosYa (semanas importadas)', detail: 'ventas netas del estado de cuenta', amount: r.pedidosya_net },
  ];
  if (Number(r.pedidosya_pos_unreconciled) > 0) {
    rows.push({
      label: 'PedidosYa del POS sin conciliar',
      detail: `${r.pedidosya_pos_unreconciled_count} ventas en semanas sin importar`,
      amount: r.pedidosya_pos_unreconciled,
    });
  }
  return rows;
});

const cogs = computed(() => costSection('cogs', 'Costo de ventas', props.pnl.cost_of_sales));
const opex = computed(() => costSection('opex', 'Gastos operativos', props.pnl.operating_expenses));
const financial = computed(() => costSection('fin', 'Gastos financieros', props.pnl.financial_expenses));
const taxes = computed(() => costSection('tax', 'Impuestos', props.pnl.taxes));

const blocks = computed(() => [
  { section: cogs.value, subtotal: { label: 'Utilidad bruta', amount: props.pnl.gross_profit?.amount } },
  { section: opex.value, subtotal: { label: 'Utilidad operativa', amount: props.pnl.operating_profit?.amount } },
  { section: financial.value },
  { section: taxes.value },
]);

const allKeys = computed(() => {
  const keys = ['rev'];
  for (const b of blocks.value) {
    keys.push(b.section.key);
    b.section.categories.forEach((c) => keys.push(c.key));
  }
  return keys;
});
const allOpen = computed(() => allKeys.value.every((k) => open.value.has(k)));
function toggleAll() {
  open.value = allOpen.value ? new Set() : new Set(allKeys.value);
}

const sign = (n) => (Number(n) < 0 ? 'neg' : '');
</script>

<template>
  <div class="fin-card">
    <div class="fin-card-head">
      <div>
        <h2>Estado de Resultados</h2>
        <p>{{ pnl.period?.month_name }} · montos en Bs y % sobre las ventas</p>
      </div>
      <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="toggleAll">
        {{ allOpen ? 'Contraer detalle' : 'Ver todo el detalle' }}
      </button>
    </div>

    <div class="fin-table-wrap">
      <table class="fin-statement">
        <thead>
          <tr>
            <th scope="col">Concepto</th>
            <th scope="col">Bs</th>
            <th scope="col">%</th>
          </tr>
        </thead>
        <tbody>
          <!-- Ventas -->
          <tr class="section">
            <td>
              <button class="fin-toggle" :class="{ open: isOpen('rev') }" type="button" :aria-expanded="isOpen('rev')" @click="toggle('rev')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m9 6 6 6-6 6" /></svg>
                Ventas
              </button>
            </td>
            <td class="amount">{{ formatBs(pnl.revenue?.total) }}</td>
            <td class="pct">{{ revenue ? '100,0 %' : '—' }}</td>
          </tr>
          <template v-if="isOpen('rev')">
            <tr v-for="row in revenueChildren" :key="row.label" class="child">
              <td>
                {{ row.label }}
                <div class="fin-cell-sub">{{ row.detail }}</div>
              </td>
              <td class="amount">{{ formatBs(row.amount) }}</td>
              <td class="pct">{{ pct(row.amount) }}</td>
            </tr>
          </template>

          <template v-for="block in blocks" :key="block.section.key">
            <tr class="section">
              <td>
                <button
                  v-if="block.section.categories.length"
                  class="fin-toggle"
                  :class="{ open: isOpen(block.section.key) }"
                  type="button"
                  :aria-expanded="isOpen(block.section.key)"
                  @click="toggle(block.section.key)"
                >
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m9 6 6 6-6 6" /></svg>
                  {{ block.section.label }}
                </button>
                <span v-else><span class="fin-toggle-spacer"></span>{{ block.section.label }}</span>
              </td>
              <td class="amount" :class="sign(block.section.amount)">{{ formatBs(block.section.amount) }}</td>
              <td class="pct">{{ pct(block.section.amount) }}</td>
            </tr>

            <template v-if="isOpen(block.section.key)">
              <template v-for="cat in block.section.categories" :key="cat.key">
                <tr class="child">
                  <td>
                    <button class="fin-toggle" :class="{ open: isOpen(cat.key) }" type="button" :aria-expanded="isOpen(cat.key)" @click="toggle(cat.key)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m9 6 6 6-6 6" /></svg>
                      {{ cat.name }}
                      <span class="fin-muted fin-small">({{ cat.count }})</span>
                    </button>
                  </td>
                  <td class="amount" :class="sign(cat.amount)">{{ formatBs(cat.amount) }}</td>
                  <td class="pct">{{ pct(cat.amount) }}</td>
                </tr>
                <template v-if="isOpen(cat.key)">
                  <tr v-for="item in cat.items" :key="item.id" class="item">
                    <td>{{ formatDay(item.date, { year: false }) }} · {{ item.description || 'Sin detalle' }}</td>
                    <td class="amount">{{ formatBs(item.amount, { sign: true }) }}</td>
                    <td class="pct"></td>
                  </tr>
                </template>
              </template>
            </template>

            <tr v-if="block.subtotal" class="subtotal">
              <td><span class="fin-toggle-spacer"></span>{{ block.subtotal.label }}</td>
              <td class="amount" :class="sign(block.subtotal.amount)">{{ formatBs(block.subtotal.amount) }}</td>
              <td class="pct">{{ pct(block.subtotal.amount, true) }}</td>
            </tr>
          </template>

          <tr class="total">
            <td><span class="fin-toggle-spacer"></span>Utilidad neta</td>
            <td class="amount" :class="sign(pnl.net_profit?.amount)">{{ formatBs(pnl.net_profit?.amount) }}</td>
            <td class="pct">{{ pct(pnl.net_profit?.amount, true) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
