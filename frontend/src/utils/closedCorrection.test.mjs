import { test } from 'node:test';
import assert from 'node:assert/strict';
import { closingPreview, cashEffectCents, signedBs } from './closedCorrection.js';

// El caso real: se contaron 322; el sistema esperaba 396 porque el gasto de 74 estaba como QR
const CLOSED = { expected_closing: 396, actual_closing: 322, difference: -74 };
const QR_74 = { type: 'expense', payment_method: 'qr', amount: 74 };

test('pasar el gasto de QR a efectivo deja la caja cuadrada', () => {
  const p = closingPreview(CLOSED, QR_74, { ...QR_74, payment_method: 'cash' });
  assert.deepEqual(p, { expectedBefore: 396, expectedAfter: 322, actual: 322, diffBefore: -74, diffAfter: 0 });
});

test('anular un gasto en efectivo sube el teórico', () => {
  const p = closingPreview({ expected_closing: 386, actual_closing: 322, difference: -64 },
    { type: 'expense', payment_method: 'cash', amount: 10 }, null);
  assert.equal(p.expectedAfter, 396);
  assert.equal(p.diffAfter, -74);
});

test('un ingreso en efectivo suma; QR y tarjeta no tocan el efectivo', () => {
  assert.equal(cashEffectCents({ type: 'extra_income', payment_method: 'cash', amount: 20 }), 2000);
  assert.equal(cashEffectCents({ type: 'expense', payment_method: 'card', amount: 20 }), 0);
  assert.equal(cashEffectCents({ type: 'expense', amount: 12.1 }), -1210);
});

test('diferencia con signo', () => {
  assert.equal(signedBs(-74), '−Bs 74.00');
  assert.equal(signedBs(0), 'Bs 0.00');
  assert.equal(signedBs(5.5), '+Bs 5.50');
});

test('gasto dividido: solo su parte en efectivo cuenta para el cierre', () => {
  // QR 100 → 40 efectivo + 60 QR, con 260 contados (faltaban 40): la caja queda cuadrada
  const p = closingPreview({ expected_closing: 300, actual_closing: 260, difference: -40 },
    { type: 'expense', payment_method: 'qr', amount: 100 },
    { type: 'expense', payment_method: 'mixed', amount: 100, cash_amount: 40 });
  assert.equal(p.expectedAfter, 260);
  assert.equal(p.diffAfter, 0);
  assert.equal(cashEffectCents({ type: 'expense', payment_method: 'mixed', amount: 100, cash_amount: 40 }), -4000);
});
