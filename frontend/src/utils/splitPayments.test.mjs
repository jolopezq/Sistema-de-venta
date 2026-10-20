import { test } from 'node:test';
import assert from 'node:assert/strict';
import { rebalance, fillRemainder, cashDue, toCents } from './splitPayments.js';

const L = (method, amount, touched = 0) => ({ method, amount, touched });
const sum = (ls) => ls.reduce((s, p) => s + toCents(p.amount), 0);

test('caso de la captura: total 28, QR = 8 → Efectivo pasa a 20', () => {
  const r = rebalance([L('cash', 28), L('qr', 0)], 1, '8', 28, 1);
  assert.equal(r[0].amount, 20);
  assert.equal(r[1].amount, '8');
  assert.equal(sum(r), 2800);
});

test('inverso: Efectivo = 20 → QR pasa a 8', () => {
  const r = rebalance([L('cash', 28), L('qr', 0)], 0, '20', 28, 1);
  assert.equal(r[1].amount, 8);
});

test('decimales sin error de coma flotante', () => {
  const r = rebalance([L('cash', 38.5), L('qr', 0)], 1, '12.1', 38.5, 1);
  assert.equal(r[0].amount, 26.4);
  const r2 = rebalance([L('cash', 0.3), L('qr', 0)], 1, '0.1', 0.3, 1);
  assert.equal(r2[0].amount, 0.2);
});

test('campo vacío cuenta como 0 y no rompe', () => {
  const r = rebalance([L('cash', 20), L('qr', 8, 1)], 1, '', 28, 2);
  assert.equal(r[0].amount, 28);
  assert.equal(r[1].amount, '');
});

test('tope: más que el total se limita; nunca negativos ni NaN', () => {
  const r = rebalance([L('cash', 28), L('qr', 0)], 1, '40', 28, 1);
  assert.equal(r[1].amount, 28);
  assert.equal(r[0].amount, 0);
  const n = rebalance([L('cash', 28), L('qr', 0)], 1, '-5', 28, 1);
  assert.equal(n[0].amount, 28);
});

test('tres métodos: edita una y se completa la que no se tocó', () => {
  // Efectivo 20 (auto), QR 8 (tocada en turno 1), Tarjeta 0 sin tocar
  const base = [L('cash', 20), L('qr', 8, 1), L('card', 0)];
  const r = rebalance(base, 2, '5', 28, 2);
  assert.equal(sum(r), 2800);
  assert.equal(r[1].amount, 8); // la tocada recientemente se respeta
  assert.equal(r[0].amount, 15); // la menos reciente absorbe
  assert.equal(r[2].amount, '5');
});

test('tres métodos, las tres tocadas: absorbe la menos reciente y todo suma el total', () => {
  const base = [L('cash', 10, 1), L('qr', 10, 2), L('card', 8, 3)];
  const r = rebalance(base, 2, '12', 28, 4);
  assert.equal(sum(r), 2800);
  assert.equal(r[1].amount, 10);
  assert.equal(r[0].amount, 6);
});

test('tres métodos: si lo editado deja poco, recorta primero a la más antigua y luego a las recientes', () => {
  const base = [L('cash', 10, 1), L('qr', 10, 2), L('card', 8, 3)];
  const r = rebalance(base, 2, '25', 28, 4);
  assert.equal(sum(r), 2800);
  assert.equal(r[2].amount, '25');
  assert.equal(r[1].amount, 3); // la reciente conserva lo que cabe
  assert.equal(r[0].amount, 0);
});

test('fillRemainder: al quitar una línea la menos reciente absorbe la diferencia', () => {
  const r = fillRemainder([L('cash', 20), L('qr', 3, 1)], 28);
  assert.equal(sum(r), 2800);
  assert.equal(r[0].amount, 25);
});

test('cashDue: no pasa de lo que falta tras QR/tarjeta', () => {
  assert.equal(cashDue([L('cash', 28), L('qr', 8)], 28), 20);
  assert.equal(cashDue([L('cash', 10), L('qr', 8)], 28), 10);
  assert.equal(cashDue([L('qr', 28)], 28), 0);
  assert.equal(cashDue([L('cash', 20), L('qr', 8)], 28), 20);
});

test('no modifica las líneas recibidas', () => {
  const base = [L('cash', 28), L('qr', 0)];
  rebalance(base, 1, '8', 28, 1);
  assert.equal(base[0].amount, 28);
});
