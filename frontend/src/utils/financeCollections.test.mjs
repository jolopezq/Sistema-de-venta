import { test } from 'node:test';
import assert from 'node:assert/strict';
import { gapSaleText, gapDetail, localPaymentRows, depositsText, cashInNotes } from './financeCollections.js';

const REVENUE = {
  local_sales: 465, cash_sales: 190, qr_sales: 260, card_sales: 80, other_sales: 0,
  payments_gap: -65, payments_gap_count: 2,
  payments_gap_sales: [
    { date: '2026-10-14', total: 35, paid: 0 },
    { date: '2026-10-22', total: 60, paid: 160 },
  ],
};

test('gapSaleText: sin pago, de más y faltante', () => {
  assert.equal(gapSaleText({ date: '2026-10-14', total: 35, paid: 0 }), '14/10 sin pago');
  assert.equal(gapSaleText({ date: '2026-10-22', total: 60, paid: 160 }), '22/10 Bs 100,00 de más');
  assert.equal(gapSaleText({ date: '2026-10-03', total: 50, paid: 45 }), '03/10 faltan Bs 5,00');
});

test('gapDetail: cuenta, lista y «y N más»', () => {
  assert.equal(gapDetail(REVENUE), '2 ventas no cuadran con sus pagos: 14/10 sin pago, 22/10 Bs 100,00 de más. Corrígelas en Control diario.');
  const many = { payments_gap_count: 5, payments_gap_sales: Array.from({ length: 5 }, (_, i) => ({ date: `2026-10-0${i + 1}`, total: 10, paid: 0 })) };
  assert.match(gapDetail(many), /01\/10 sin pago, 02\/10 sin pago, 03\/10 sin pago y 2 más\./);
  assert.equal(gapDetail({ payments_gap_count: 0 }), '');
});

test('localPaymentRows: suman las ventas del local, con % del local y «Sin cuadrar»', () => {
  const rows = localPaymentRows(REVENUE);
  assert.deepEqual(rows.map((r) => r.key), ['cash', 'qr', 'card', 'gap']);
  const sum = rows.reduce((s, r) => s + r.amount, 0);
  assert.equal(Math.round(sum * 100) / 100, 465);
  assert.equal(rows[0].detail, '40,9 % de las ventas del local');
  assert.equal(rows[3].warn, true);

  const clean = localPaymentRows({ local_sales: 100, cash_sales: 70, qr_sales: 0, card_sales: 30, other_sales: 0, payments_gap: 0 });
  assert.deepEqual(clean.map((r) => r.key), ['cash', 'qr', 'card']);
  const withOther = localPaymentRows({ local_sales: 100, cash_sales: 90, other_sales: 10, payments_gap: 0 });
  assert.deepEqual(withOther.map((r) => r.key), ['cash', 'qr', 'card', 'other']);
  assert.equal(localPaymentRows({ local_sales: 0 })[0].detail, '');
});

test('depositsText', () => {
  assert.equal(depositsText({ pedidosya_deposits: [] }), 'Sin depósitos en el mes');
  assert.equal(depositsText({ pedidosya_deposits: [{ payout_date: '2026-10-01' }] }), 'Depósito del 01/10');
  assert.equal(
    depositsText({ pedidosya_deposits: [{ payout_date: '2026-10-01' }, { payout_date: '2026-10-08' }, { payout_date: '2026-10-15' }] }),
    'Depósitos del 01/10, 08/10 y 15/10',
  );
});

test('cashInNotes: lo que entra el mes siguiente, sin fecha, sin semana, sin cuadrar y tarjeta', () => {
  const notes = cashInNotes({
    card: 80,
    pedidosya_paid_later: 600,
    pedidosya_paid_later_weeks: [{ start_date: '2026-10-26', end_date: '2026-11-01', payout_date: '2026-11-05' }],
    pedidosya_without_payout_date: [{ start_date: '2026-10-12', end_date: '2026-10-18' }],
    pedidosya_uncovered_ranges: [{ from: '2026-10-19', to: '2026-10-25' }, { from: '2026-10-31', to: '2026-10-31' }],
  }, REVENUE);
  assert.deepEqual(notes, [
    'PedidosYa: la semana del 26/10 al 01/11 (Bs 600,00) se deposita el 05/11, ya en el mes siguiente.',
    'La semana del 12/10 al 18/10 de PedidosYa no tiene fecha de pago: no se cuenta como depositada.',
    'Sin semana de PedidosYa importada: del 19/10 al 25/10 y el 31/10.',
    '2 ventas del local no cuadran con sus pagos: aquí cuentan los pagos registrados.',
    'La tarjeta llega a la cuenta con el descuento del banco.',
  ]);
  assert.deepEqual(cashInNotes({}, {}), []);
});

test('cashInNotes: varias semanas que se depositan el mes siguiente', () => {
  const [note] = cashInNotes({
    pedidosya_paid_later: 900,
    pedidosya_paid_later_weeks: [
      { start_date: '2026-10-19', end_date: '2026-10-25', payout_date: '2026-11-01' },
      { start_date: '2026-10-26', end_date: '2026-11-01', payout_date: '2026-11-05' },
    ],
  }, {});
  assert.equal(note, 'PedidosYa: las semanas del 19/10 al 25/10 y del 26/10 al 01/11 (Bs 900,00) se depositan el 01/11 y 05/11, ya en el mes siguiente.');
});
