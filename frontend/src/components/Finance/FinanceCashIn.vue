<script setup>
import { computed } from 'vue';
import { formatBs } from '../../stores/finance';
import { depositsText, cashInNotes, drawerBreakdown } from '../../utils/financeCollections';

/**
 * Dinero que entró en el mes, por fecha de cobro (no por fecha de venta): lo del local al vender y lo de
 * PedidosYa el día que deposita. Responde «cuánto entró a la cuenta», que el Estado de Resultados no dice.
 */
const props = defineProps({
  pnl: { type: Object, required: true },
});

const c = computed(() => props.pnl.cash_in || null);
const rows = computed(() => {
  if (!c.value) return [];
  const list = [
    { key: 'cash', label: 'Efectivo', detail: 'ventas del local', amount: c.value.cash },
    { key: 'qr', label: 'QR o banco', detail: 'ventas del local', amount: c.value.qr },
    { key: 'card', label: 'Tarjeta', detail: 'ventas del local', amount: c.value.card },
  ];
  if (Number(c.value.other)) list.push({ key: 'other', label: 'Otros medios', detail: 'ventas del local', amount: c.value.other });
  list.push({ key: 'pya', label: 'PedidosYa', detail: depositsText(c.value), amount: c.value.pedidosya_deposited });
  return list;
});
const notes = computed(() => (c.value ? cashInNotes(c.value, props.pnl.revenue) : []));
// Efectivo que debía llegar a la mano: de lo cobrado a lo retirado de las cajas (plan de conciliación, A)
const drawer = computed(() => (c.value?.drawer ? drawerBreakdown(c.value.drawer) : null));
</script>

<template>
  <section v-if="c" class="fin-card cin" aria-labelledby="cin-title">
    <div class="cin-head">
      <h3 id="cin-title">Dinero que entró en el mes</h3>
      <p>Por fecha de cobro: lo del local al vender, lo de PedidosYa el día que deposita.</p>
    </div>

    <table class="cin-table">
      <tbody>
        <tr v-for="row in rows" :key="row.key" :data-row="row.key">
          <th scope="row">
            {{ row.label }}
            <span class="cin-sub">{{ row.detail }}</span>
          </th>
          <td class="fin-num">{{ formatBs(row.amount) }}</td>
        </tr>
      </tbody>
      <tfoot>
        <tr data-row="total">
          <th scope="row">
            Total
            <span class="cin-sub">A la cuenta {{ formatBs(c.to_bank) }} (QR, tarjeta y PedidosYa)</span>
          </th>
          <td class="fin-num">{{ formatBs(c.total) }}</td>
        </tr>
      </tfoot>
    </table>

    <details v-if="drawer && (drawer.total || drawer.rows.length > 1)" class="cin-drawer" data-testid="cin-drawer">
      <summary>
        <span class="cin-drawer-title">
          Efectivo que te debía llegar a la mano
          <span class="cin-sub">lo retirado de las cajas cerradas: compáralo con lo que juntaste</span>
        </span>
        <strong class="fin-num">{{ formatBs(drawer.total) }}</strong>
      </summary>
      <table class="cin-table cin-drawer-table">
        <tbody>
          <tr v-for="row in drawer.rows" :key="row.key" :data-row="row.key">
            <th scope="row">
              {{ row.label }}
              <span v-if="row.detail" class="cin-sub">{{ row.detail }}</span>
            </th>
            <td class="fin-num">{{ formatBs(row.amount, { sign: row.key !== 'collected' }) }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr data-row="withdrawn">
            <th scope="row">Te debía llegar a la mano</th>
            <td class="fin-num">{{ formatBs(drawer.total) }}</td>
          </tr>
        </tfoot>
      </table>
      <ul v-if="drawer.notes.length" class="cin-notes">
        <li v-for="note in drawer.notes" :key="note">{{ note }}</li>
      </ul>
    </details>

    <ul v-if="notes.length" class="cin-notes">
      <li v-for="note in notes" :key="note">{{ note }}</li>
    </ul>
  </section>
</template>

<style scoped>
.cin { padding: 20px 22px 18px; display: flex; flex-direction: column; gap: 12px; }
.cin-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 14px; }
.cin-head h3 { margin: 0; font-family: var(--font-heading); font-size: 19px; line-height: 1.25; font-weight: 700; color: var(--ink-900); }
.cin-head p { margin: 0; font-size: 13px; color: var(--ink-500); }

.cin-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.cin-table th { text-align: left; font-weight: 500; color: var(--ink-900); padding: 8px 0; border-top: 1px solid var(--border); }
.cin-table td { text-align: right; white-space: nowrap; color: var(--ink-900); padding: 8px 0 8px 16px; border-top: 1px solid var(--border); font-variant-numeric: tabular-nums; vertical-align: top; }
.cin-sub { display: block; margin-top: 1px; font-size: 12px; font-weight: 400; color: var(--ink-500); }
.cin-table tfoot th, .cin-table tfoot td { border-top: 2px solid var(--border-strong, var(--border)); font-weight: 700; }
.cin-table tfoot td { font-size: 16px; }

.cin-drawer { border-top: 1px solid var(--border); padding-top: 8px; }
.cin-drawer summary { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; cursor: pointer; list-style: none; font-size: 14px; font-weight: 600; color: var(--ink-900); padding: 4px 0; }
.cin-drawer summary::-webkit-details-marker { display: none; }
.cin-drawer summary::before { content: '▸'; color: var(--ink-500); margin-right: 6px; transition: transform .15s; }
.cin-drawer[open] summary::before { transform: rotate(90deg); }
.cin-drawer-title { flex: 1; }
.cin-drawer summary strong { font-size: 15px; white-space: nowrap; font-variant-numeric: tabular-nums; }
.cin-drawer summary:focus-visible { outline: 2px solid var(--acai-text, currentColor); outline-offset: 2px; border-radius: 4px; }
.cin-drawer-table { margin-top: 4px; }
.cin-drawer .cin-notes { margin-top: 6px; }
.cin-notes { margin: 0; padding-left: 18px; display: flex; flex-direction: column; gap: 3px; font-size: 13px; line-height: 1.45; color: var(--ink-700); }

/* Portátil de caja (≤ 1366 px o ≤ 800 px de alto) */
@media (min-width: 761px) and (max-width: 1366px), (min-width: 761px) and (max-height: 800px) {
  .cin { padding: 12px 16px; gap: 8px; }
  .cin-head h3 { font-size: 17px; }
  .cin-table { font-size: 13.5px; }
  .cin-table th, .cin-table td { padding-top: 6px; padding-bottom: 6px; }
  .cin-notes { font-size: 12.5px; }
}
@media (min-width: 761px) and (max-height: 520px) {
  .cin { padding: 10px 14px; gap: 6px; }
  .cin-table th, .cin-table td { padding-top: 5px; padding-bottom: 5px; }
}
@media (max-width: 760px) {
  .cin { padding: 18px 16px; }
}
</style>
