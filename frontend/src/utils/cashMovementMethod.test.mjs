import { test } from 'node:test';
import assert from 'node:assert/strict';
import { methodLabel, methodBadge, cashPart, nonCashPart, methodDetail, methodWithAmount } from './cashMovementMethod.js';

const SPLIT = { type: 'expense', payment_method: 'mixed', amount: '100.00', cash_amount: '40.00' };

test('etiquetas: un gasto dividido no sale como «Tarjeta»', () => {
  assert.equal(methodLabel({ payment_method: 'cash' }), 'Efectivo');
  assert.equal(methodLabel({ payment_method: 'qr' }), 'QR');
  assert.equal(methodLabel({ payment_method: 'card' }), 'Tarjeta');
  assert.equal(methodLabel(SPLIT), 'Efectivo + QR');
  assert.equal(methodLabel({}), 'Efectivo');
  assert.equal(methodBadge(SPLIT), '💵📱 Efectivo + QR');
});

test('partes en efectivo y fuera de la gaveta', () => {
  assert.equal(cashPart(SPLIT), 40);
  assert.equal(nonCashPart(SPLIT), 60);
  assert.equal(cashPart({ payment_method: 'cash', amount: 74 }), 74);
  assert.equal(cashPart({ payment_method: 'qr', amount: 74 }), 0);
  assert.equal(nonCashPart({ payment_method: 'qr', amount: 74 }), 74);
  assert.equal(cashPart({ amount: 12.1 }), 12.1);
});

test('desglose y «Antes»', () => {
  assert.equal(methodDetail(SPLIT), 'Bs 40.00 efectivo + Bs 60.00 QR');
  assert.equal(methodDetail({ payment_method: 'qr', amount: 5 }), '');
  assert.equal(methodWithAmount({ payment_method: 'qr', amount: 74 }), 'QR · Bs 74.00');
  assert.equal(methodWithAmount(SPLIT), 'Efectivo + QR · Bs 100.00 (Bs 40.00 efectivo + Bs 60.00 QR)');
});
