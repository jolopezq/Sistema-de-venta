import { test } from 'node:test';
import assert from 'node:assert/strict';
import { sanitizeCount, sanitizeCounts, countTotal, subtotal, piecesCount, sanitizeMoneyText, moneyFromText, DENOMINATIONS } from './cashCount.js';

test('sanitizeCount: solo dígitos, sin signos ni decimales ni letras', () => {
  assert.equal(sanitizeCount('-3'), 3);
  assert.equal(sanitizeCount('2,5'), 25);
  assert.equal(sanitizeCount('e'), 0);
  assert.equal(sanitizeCount('1e3'), 13);
  assert.equal(sanitizeCount(''), 0);
  assert.equal(sanitizeCount(null), 0);
  assert.equal(sanitizeCount('007'), 7);
  assert.equal(sanitizeCount('99999'), 9999);
  assert.equal(sanitizeCount(35), 35);
  assert.equal(sanitizeCount(-4), 4);
});

test('sanitizeCounts: borrador inválido se sanea y se completan las claves', () => {
  const r = sanitizeCounts({ 200: -3, 100: 2.5, 50: '7', '0.5': 4 });
  assert.equal(r['200'], 3);
  assert.equal(r['100'], 25);
  assert.equal(r['50'], 7);
  assert.equal(r['0.50'], 4);
  assert.equal(r['20'], 0);
  assert.equal(Object.keys(r).length, DENOMINATIONS.length);
  assert.deepEqual(Object.keys(sanitizeCounts(null)).sort(), DENOMINATIONS.map((d) => d.key).sort());
});

test('countTotal: 3×200 + 5×50 + 2×0,50 = 851', () => {
  assert.equal(countTotal({ 200: 3, 50: 5, '0.50': 2 }), 851);
  assert.equal(countTotal({}), 0);
  assert.equal(countTotal({ '0.50': 3 }), 1.5);
  assert.equal(countTotal({ 1: 3, '0.50': 3, 2: 3 }), 10.5);
});

test('subtotal y piezas', () => {
  const d = DENOMINATIONS.find((x) => x.key === '0.50');
  assert.equal(subtotal(d, 7), 3.5);
  assert.equal(piecesCount({ 200: 3, 50: 5, '0.50': 2 }), 10);
});

test('sanitizeMoneyText / moneyFromText', () => {
  assert.equal(sanitizeMoneyText('12,5'), '12.5');
  assert.equal(sanitizeMoneyText('12.345'), '12.34');
  assert.equal(sanitizeMoneyText('-5'), '5');
  assert.equal(sanitizeMoneyText('1.2.3'), '1.23');
  assert.equal(sanitizeMoneyText('007'), '7');
  assert.equal(sanitizeMoneyText('.5'), '0.5');
  assert.equal(sanitizeMoneyText('abc'), '');
  assert.equal(moneyFromText(''), 0);
  assert.equal(moneyFromText('100.50'), 100.5);
  assert.equal(moneyFromText('10.'), 10);
});
