import test from 'node:test';
import assert from 'node:assert/strict';
import { getSalePaymentBadges, getSalePaymentText } from './paymentDisplay.js';

test('getSalePaymentBadges: venta nula o sin pagos muestra "Sin pago"', () => {
  assert.deepEqual(getSalePaymentBadges(null), [{ label: 'Sin pago', class: 'badge-gray' }]);
  assert.deepEqual(getSalePaymentBadges({}), [{ label: 'Sin pago', class: 'badge-gray' }]);
  assert.deepEqual(getSalePaymentBadges({ payments: [] }), [{ label: 'Sin pago', class: 'badge-gray' }]);
});

test('getSalePaymentBadges: venta de PedidosYa sin pagos muestra pastilla de PedidosYa', () => {
  const badges = getSalePaymentBadges({ source: 'pedidosya', payments: [] });
  assert.equal(badges.length, 1);
  assert.equal(badges[0].label, '🛵 PedidosYa');
  assert.equal(badges[0].class, 'badge-pedidosya');
});

test('getSalePaymentBadges: un único pago no incluye desglose de monto', () => {
  const saleCash = { payments: [{ method: 'cash', amount: 37 }] };
  const badgesCash = getSalePaymentBadges(saleCash);
  assert.equal(badgesCash.length, 1);
  assert.equal(badgesCash[0].label, '💵 Efectivo');
  assert.equal(badgesCash[0].class, 'badge-cash');

  const saleQr = { payments: [{ method: 'qr', amount: 45 }] };
  const badgesQr = getSalePaymentBadges(saleQr);
  assert.equal(badgesQr.length, 1);
  assert.equal(badgesQr[0].label, '📱 QR');
  assert.equal(badgesQr[0].class, 'badge-qr');
});

test('getSalePaymentBadges: pago dividido / mixto muestra todas las pastillas con sus montos', () => {
  const saleSplit = {
    payments: [
      { method: 'qr', amount: 40 },
      { method: 'cash', amount: 5 },
    ],
  };
  const badges = getSalePaymentBadges(saleSplit);
  assert.equal(badges.length, 2);
  assert.equal(badges[0].label, '📱 QR Bs 40.00');
  assert.equal(badges[0].class, 'badge-qr');
  assert.equal(badges[1].label, '💵 Efectivo Bs 5.00');
  assert.equal(badges[1].class, 'badge-cash');
});

test('getSalePaymentBadges: filtra métodos con monto 0 en pagos divididos', () => {
  const sale = {
    payments: [
      { method: 'qr', amount: 40 },
      { method: 'cash', amount: 0 },
    ],
  };
  const badges = getSalePaymentBadges(sale);
  assert.equal(badges.length, 1);
  assert.equal(badges[0].label, '📱 QR');
});

test('getSalePaymentText: formato texto para tablas compactas', () => {
  assert.equal(getSalePaymentText({ payments: [{ method: 'cash', amount: 37 }] }), 'Efectivo');
  assert.equal(
    getSalePaymentText({
      payments: [
        { method: 'qr', amount: 40 },
        { method: 'cash', amount: 5 },
      ],
    }),
    'Mixto: QR (Bs 40.00) + Efectivo (Bs 5.00)'
  );
});

test('cortesía (total 0 con descuento): «Cortesía», no «Efectivo»', () => {
  const sale = { subtotal: 140, discount_amount: 140, total_amount: 0, payments: [{ method: 'cash', amount: 0 }] };
  const badges = getSalePaymentBadges(sale);
  assert.equal(badges.length, 1);
  assert.equal(badges[0].label, '🎁 Cortesía');
  assert.equal(badges[0].class, 'badge-courtesy');
  assert.equal(getSalePaymentText(sale), 'Cortesía');
});

test('un descuento parcial sigue mostrando el método de pago', () => {
  const sale = { subtotal: 140, discount_amount: 20, total_amount: 120, payments: [{ method: 'cash', amount: 120 }] };
  assert.equal(getSalePaymentBadges(sale)[0].label, '💵 Efectivo');
});
