import { test } from 'node:test';
import assert from 'node:assert/strict';
import { makeLine, addLine, replaceLine, increaseLine, decreaseLine, lineSignature, newLineKey } from './cartLines.js';

// Autoservicio a Bs 0,24 el gramo, redondeado como en el modal de peso
const PPG = 0.24;
const bowl = (grams, extra = {}) => ({
  id: 5, name: 'Autoservicio', price: Math.round(grams * PPG), modifiers: [], base_price: 0,
  is_takeaway: false, is_weight_based: true, weight_grams: grams, price_per_gram: PPG, ...extra,
});
const cafe = (extra = {}) => ({ id: 9, name: 'Café', price: 12, modifiers: [], base_price: 12, is_takeaway: false, ...extra });
const total = (items) => items.reduce((s, i) => s + i.subtotal, 0);

test('Dos bowls por peso con la misma configuración quedan en dos líneas, cada una con sus gramos y su precio', () => {
  const items = [];
  addLine(items, makeLine(bowl(151)));
  addLine(items, makeLine(bowl(200)));
  assert.equal(items.length, 2);
  assert.deepEqual(items.map((i) => [i.quantity, i.weight_grams, i.subtotal]), [[1, 151, 36], [1, 200, 48]]);
  assert.equal(total(items), 84);
  assert.notEqual(items[0].cart_key, items[1].cart_key);
});

test('Dos bowls del mismo peso tampoco se juntan', () => {
  const items = [];
  addLine(items, makeLine(bowl(200)));
  addLine(items, makeLine(bowl(200)));
  assert.equal(items.length, 2);
  assert.equal(total(items), 96);
});

test('Un bowl por peso entra siempre con cantidad 1', () => {
  const items = [];
  addLine(items, makeLine(bowl(151), 3));
  assert.equal(items[0].quantity, 1);
  assert.equal(items[0].subtotal, 36);
});

test('«+» no hace nada en un bowl por peso; «Quitar» saca solo ese bowl', () => {
  const items = [];
  addLine(items, makeLine(bowl(151)));
  addLine(items, makeLine(bowl(200)));
  assert.equal(increaseLine(items, items[0].cart_key), false);
  assert.equal(items[0].quantity, 1);
  assert.equal(items[0].subtotal, 36);
  const second = items[1].cart_key;
  assert.equal(decreaseLine(items, items[0].cart_key), true);
  assert.deepEqual(items.map((i) => [i.cart_key, i.weight_grams]), [[second, 200]]);
});

test('Editar el peso de un bowl lo cambia en su lugar y no toca al otro', () => {
  const items = [];
  addLine(items, makeLine(bowl(151)));
  addLine(items, makeLine(bowl(200)));
  const [first, second] = items.map((i) => i.cart_key);
  assert.equal(replaceLine(items, first, makeLine(bowl(160))), true);
  assert.deepEqual(items.map((i) => [i.cart_key, i.weight_grams, i.subtotal]), [[first, 160, 38], [second, 200, 48]]);
});

test('Editar un bowl para que quede igual a otro no los junta', () => {
  const items = [];
  addLine(items, makeLine(bowl(151)));
  addLine(items, makeLine(bowl(200)));
  replaceLine(items, items[0].cart_key, makeLine(bowl(200)));
  assert.equal(items.length, 2);
  assert.equal(total(items), 96);
});

test('Producto normal: la misma configuración suma cantidad; otro destino, otra nota u otras opciones van aparte', () => {
  const items = [];
  addLine(items, makeLine(cafe()));
  addLine(items, makeLine(cafe()));
  assert.equal(items.length, 1);
  assert.deepEqual([items[0].quantity, items[0].subtotal], [2, 24]);
  addLine(items, makeLine(cafe({ is_takeaway: true })));
  addLine(items, makeLine(cafe(), 1, 'sin azúcar'));
  addLine(items, makeLine(cafe({ modifiers: [{ option_id: 3, quantity: 1 }] })));
  assert.equal(items.length, 4);
});

test('Las opciones se comparan sin importar el orden', () => {
  const a = makeLine(cafe({ modifiers: [{ option_id: 3 }, { option_id: 7, quantity: 2 }] }));
  const b = makeLine(cafe({ modifiers: [{ option_id: 7, quantity: 2 }, { option_id: 3, quantity: 1 }] }));
  assert.equal(lineSignature(a), lineSignature(b));
  assert.equal(lineSignature(makeLine(bowl(151))), null);
});

test('Cambiar el destino de una línea no hace que otra se junte con ella por error', () => {
  const items = [];
  addLine(items, makeLine(cafe()));
  items[0].is_takeaway = true; // el chip «En mesa → Para llevar» del ticket
  addLine(items, makeLine(cafe())); // otro café en mesa: va aparte
  assert.deepEqual(items.map((i) => [i.is_takeaway, i.quantity]), [[true, 1], [false, 1]]);
  addLine(items, makeLine(cafe({ is_takeaway: true }))); // otro para llevar: se suma al primero
  assert.deepEqual(items.map((i) => [i.is_takeaway, i.quantity]), [[true, 2], [false, 1]]);
});

test('Editar una línea normal conserva su lugar, su id y su cantidad; si queda igual a otra, se juntan', () => {
  const items = [];
  addLine(items, makeLine(cafe(), 3));
  addLine(items, makeLine(cafe({ is_takeaway: true })));
  const [first, second] = items.map((i) => i.cart_key);
  replaceLine(items, first, makeLine(cafe(), 1, 'tibio'));
  assert.deepEqual(items.map((i) => [i.cart_key, i.quantity, i.item_note, i.subtotal]), [[first, 3, 'tibio', 36], [second, 1, '', 12]]);
  replaceLine(items, second, makeLine(cafe(), 1, 'tibio'));
  assert.deepEqual(items.map((i) => [i.cart_key, i.quantity, i.subtotal]), [[first, 4, 48]]);
});

test('«+» y «−» en un producto normal; con una unidad, «−» quita la línea', () => {
  const items = [];
  addLine(items, makeLine(cafe()));
  const key = items[0].cart_key;
  increaseLine(items, key);
  assert.deepEqual([items[0].quantity, items[0].subtotal], [2, 24]);
  decreaseLine(items, key);
  assert.deepEqual([items[0].quantity, items[0].subtotal], [1, 12]);
  decreaseLine(items, key);
  assert.equal(items.length, 0);
  assert.equal(decreaseLine(items, key), false);
  assert.equal(replaceLine(items, key, makeLine(cafe())), false);
});

test('Los ids de línea no se repiten', () => {
  const keys = new Set(Array.from({ length: 2000 }, () => newLineKey()));
  assert.equal(keys.size, 2000);
});
