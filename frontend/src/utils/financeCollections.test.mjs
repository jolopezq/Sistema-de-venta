import { test } from 'node:test';
import assert from 'node:assert/strict';
import { gapSaleText, gapDetail, localPaymentRows, depositsText, cashInNotes, drawerBreakdown, qrBankNote } from './financeCollections.js';

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

// ── Efectivo que debía llegar a la mano (plan de conciliación, A) ──

test('drawerBreakdown: octubre 2026 suma lo retirado de las cajas', () => {
  const drawer = {
    collected: 2868, in_open_sessions: 0, outside_sessions: 0, drawer_expenses: 171, extra_income: 244, differences: 0,
    fund_start: 242, fund_end: 256, fund_change: 14, fund_jumps: -240, withdrawn: 2687, unexplained: 0,
    fund_jump_items: [
      { date: '2026-10-03', amount: -244, in_total: true },
      { date: '2026-10-09', amount: 4, in_total: true },
    ],
    open_sessions: [{ date: '2026-10-14', cashier: 'Lourdes' }, { date: '2026-10-27', cashier: 'Lourdes' }],
    difference_items: [],
  };
  const { rows, total, notes } = drawerBreakdown(drawer);
  assert.deepEqual(rows.map((r) => r.key), ['collected', 'expenses', 'income', 'fund', 'jumps']);
  assert.equal(Math.round(rows.reduce((s, r) => s + r.amount, 0) * 100) / 100, total);
  assert.equal(total, 2687);
  assert.equal(rows.find((r) => r.key === 'fund').label, 'Quedó de más en la gaveta como cambio');
  assert.match(rows.find((r) => r.key === 'jumps').detail, /03\/10 −Bs 244,00 y 09\/10 \+Bs 4,00/);
  assert.equal(notes[0], 'Las cajas 14/10 (Lourdes) y 27/10 (Lourdes) siguen abiertas: ciérralas en Control diario.');
});

test('drawerBreakdown: faltante, cajas abiertas y ventas sin caja también suman lo retirado', () => {
  const drawer = {
    collected: 1000, in_open_sessions: 120, outside_sessions: 80, drawer_expenses: 50, extra_income: 0, differences: -15,
    fund_start: 200, fund_end: 180, fund_change: -20, fund_jumps: 0, withdrawn: 755, unexplained: 0,
    difference_items: [{ date: '2026-10-05', amount: -15 }],
  };
  const { rows, total } = drawerBreakdown(drawer);
  assert.equal(Math.round(rows.reduce((s, r) => s + r.amount, 0) * 100) / 100, total);
  assert.equal(rows.find((r) => r.key === 'differences').label, 'Faltantes al cerrar caja');
  assert.equal(rows.find((r) => r.key === 'fund').label, 'Se usó cambio de la gaveta');
});

test('qrBankNote: solo con cierres que anotaron el QR del banco', () => {
  assert.equal(qrBankNote({ reported_count: 0, closed_count: 24 }), null);
  assert.equal(qrBankNote({ reported_count: 2, closed_count: 3, difference: 0, items: [] }), 'QR comparado con la app del banco en 2 de 3 cierres: coincide.');
  assert.equal(
    qrBankNote({ reported_count: 1, closed_count: 1, difference: -30, items: [{ date: '2026-10-22', difference: -30 }] }),
    'QR comparado con la app del banco en 1 de 1 cierre: faltan Bs 30,00 en el banco (22/10 −Bs 30,00).',
  );
});
