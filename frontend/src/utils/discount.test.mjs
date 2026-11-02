import { test } from 'node:test';
import assert from 'node:assert/strict';
import { maxDiscount, netTotal, discountPercent, formatPercent, discountProblem } from './discount.js';

const CASHIER = { allowed: true, max_percent: 20, reason_min: 10, reason_max: 255 };
const ADMIN = { allowed: true, max_percent: 100, reason_min: 10, reason_max: 255 };

test('tope del cajero: 20 % redondeado hacia abajo al centavo', () => {
  assert.equal(maxDiscount(150, 20), 30);
  assert.equal(maxDiscount(47.5, 20), 9.5);
  assert.equal(maxDiscount(33.47, 20), 6.69);
  assert.equal(maxDiscount('', 20), 0);
});

test('tope del administrador: el subtotal completo (cortesía)', () => {
  assert.equal(maxDiscount(150, 100), 150);
  assert.equal(maxDiscount(38.5, 100), 38.5);
});

test('total con descuento sin errores de coma flotante y nunca negativo', () => {
  assert.equal(netTotal(38.5, 12.1), 26.4);
  assert.equal(netTotal(150, 30), 120);
  assert.equal(netTotal(150, 150), 0);
  assert.equal(netTotal(150, 999), 0);
  assert.equal(netTotal(150, ''), 150);
});

test('porcentaje equivalente con un decimal', () => {
  assert.equal(discountPercent(20, 150), 13.3);
  assert.equal(discountPercent(30, 150), 20);
  assert.equal(formatPercent(13.3), '13.3 %');
  assert.equal(formatPercent(20), '20 %');
});

test('sin descuento no hay nada que validar', () => {
  assert.equal(discountProblem({ amount: 0, reason: '', subtotal: 150, policy: CASHIER }), null);
  assert.equal(discountProblem({ amount: '', reason: '', subtotal: 150 }), null);
});

test('supera el tope de su rol', () => {
  const p = discountProblem({ amount: 30.01, reason: 'Pedido grande del colegio', subtotal: 150, policy: CASHIER });
  assert.equal(p.field, 'amount');
  assert.equal(p.message, 'Máximo Bs 30.00 (20 % del subtotal).');
  assert.equal(discountProblem({ amount: 150, reason: 'Cortesía al proveedor', subtotal: 150, policy: ADMIN }), null);
});

test('motivo obligatorio de 10 caracteres sin contar espacios de los extremos', () => {
  const p = discountProblem({ amount: 10, reason: '  123456789  ', subtotal: 150, policy: CASHIER });
  assert.equal(p.field, 'reason');
  assert.equal(p.message, 'Escribe el motivo del descuento (mínimo 10 caracteres).');
  assert.equal(discountProblem({ amount: 10, reason: '1234567890', subtotal: 150, policy: CASHIER }), null);
});

test('sin permiso no se puede descontar', () => {
  const p = discountProblem({ amount: 5, reason: 'Cliente frecuente', subtotal: 150 });
  assert.equal(p.message, 'Necesitas el permiso «Descuentos en caja».');
});
