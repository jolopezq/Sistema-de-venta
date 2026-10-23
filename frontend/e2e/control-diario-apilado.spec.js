import { test, expect } from '@playwright/test';

/**
 * Control diario: comanda con varios ítems, opciones con el configurador de Ventas y toppings,
 * medida en la portátil de caja (1366 × 611 y 125 % ≈ 1093 × 489) sin romper escritorio ni teléfono.
 * Plan: docs/PLAN_VENTAS_MULTI_ITEM_Y_TOPPINGS.md (criterios P1–P9 y casos A–D).
 */

const json = (body, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(body) });
const ME = {
  user: { id: 1, name: 'Dueño Prueba', email: 'dueno@example.com', role: 'super_admin' }, permissions: [],
  effective_permissions: { pos: 'edit', register_self: 'edit', delivery_ops: 'edit', crm_read: 'edit', voids: 'edit', register_others: 'edit', catalog: 'edit', inventory: 'edit', crm_config: 'edit', reports: 'edit', users: 'edit', audit: 'edit' },
};
const opt = (id, name, extra, def = false) => ({ id, name, additional_price: extra, delivery_price: extra, is_active: true, is_default: def, sort_order: id });
const TOPS = ['Sin granola', 'Ositos de gomita', 'Leche condensada', 'Chips de Chocolate', 'Hojuelas de maiz', 'Chispas de chocolate', 'Chispas de Colores', 'Coco', 'Moritas', 'Chubi', 'Chispas de corazones', 'Gotas de chocolate', 'Extra granola', 'Extra Leche en Polvo', 'Gomitas azucaradas', 'Maní'];
const SIZE = { id: 2, name: 'Tamaño Acai', is_active: true, min_selections: 0, max_selections: 1, options: [opt(3, 'Mediano', 7), opt(5, 'Grande', 14), opt(14, 'Ohana', 32)] };
const FRUITS = { id: 5, name: 'Frutas', is_active: true, min_selections: 0, max_selections: 3, options: ['Banana', 'Frutilla', 'Maracuya', 'Piña', 'Kiwi', 'Mango', 'Durazno', 'Uva'].map((n, i) => opt(25 + i, n, 0)) };
const TOPPINGS = { id: 4, name: 'Toppings', is_active: true, min_selections: 0, max_selections: 10, options: TOPS.map((n, i) => opt(100 + i, n, n.startsWith('Sin ') ? 0 : 4)) };
const bowl = (id, name, cat = 1) => ({ id, name, price: 28, delivery_price: 30, category_id: cat, is_active: true, excluded_options: [], option_groups: [SIZE, FRUITS, TOPPINGS] });
const simple = (id, name, price, cat) => ({ id, name, price, delivery_price: price + 2, category_id: cat, is_active: true, option_groups: [] });
const CATALOG = {
  categories: [
    { id: 1, name: 'Açaí Bowls', sort_order: 1 }, { id: 2, name: 'Smoothies', sort_order: 2 }, { id: 8, name: 'Jugos', sort_order: 3 },
    { id: 9, name: 'Arma tu Bowl', sort_order: 4 }, { id: 10, name: 'Café', sort_order: 5 }, { id: 11, name: 'Toppings', sort_order: 6 },
    { id: 12, name: 'Vacía', sort_order: 7 },
  ],
  customers: [], ingredients: [],
  products: [
    bowl(5, 'Classic Bowl'), bowl(6, 'Hawaian Bowl'), bowl(23, 'Aloha'), bowl(25, 'Marley'), bowl(10, 'Arma tu Bowl', 9),
    simple(40, 'Smoothie Frutilla', 22, 2), simple(41, 'Smoothie Mango', 22, 2),
    simple(3, 'Jugo de Piña', 10, 8), simple(42, 'Jugo de Naranja', 10, 8), simple(43, 'Limonada', 9, 8), simple(31, 'Agua', 8, 8),
    { id: 32, name: 'Capuchino', price: 16, delivery_price: 18, category_id: 10, is_active: true, excluded_options: [], option_groups: [
      { id: 7, name: 'Tamaño Cafe', is_active: true, min_selections: 0, max_selections: 1, options: [opt(50, 'Grande', 4)] }] },
    { id: 33, name: 'Toppings', price: 0, delivery_price: 0, category_id: 11, is_active: true, excluded_options: [], option_groups: [TOPPINGS] },
  ],
};

const LAPTOP = [
  { width: 1366, height: 611, desc: 'portátil 100 %', minRows: 4 },
  { width: 1093, height: 489, desc: 'portátil 125 %', minRows: 3 },
];

async function setup(page, viewport = { width: 1366, height: 611 }) {
  await page.setViewportSize(viewport);
  await page.addInitScript(() => localStorage.setItem('auth_token', 'mock'));
  await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
  await page.route('**/api/me', (r) => r.fulfill(json(ME)));
  await page.route('**/api/catalog', (r) => r.fulfill(json(CATALOG)));
  await page.route('**/api/cash-sessions/**', (r) => r.fulfill(json({
    session: { id: 1, status: 'open', opening_amount: 100, opened_at: new Date().toISOString() }, stats: { total_sales: 0 }, data: [],
  })));
  await page.goto('/admin/ventas-retroactivas');
  await page.waitForSelector('.product-picker-card');
}

const card = (page, name) => page.locator('.product-picker-card').filter({ has: page.locator('.card-name', { hasText: new RegExp(`^${name}$`) }) });
const tab = (page, name) => page.locator('.cd-cat-tabs .cat-tab', { hasText: new RegExp(`^\\s*${name}\\s*$`) });
const lines = (page) => page.locator('.cd-ticket .cd-line');
const modal = (page) => page.locator('.modal-content');
const confirmBtn = (page) => page.locator('.btn-confirm');

async function pick(page, name) {
  await page.locator('.modal-content').locator('.size-btn, .option-chip')
    .filter({ has: page.locator('.size-name, .chip-name', { hasText: new RegExp(`^\\s*${name}\\s*$`) }) }).first().click();
}

/** Bowl configurado desde el catálogo con el configurador de Ventas. */
async function addBowl(page, name, choices) {
  await card(page, name).click();
  await expect(modal(page)).toBeVisible();
  for (const c of choices) await pick(page, c);
  await confirmBtn(page).click();
  await expect(modal(page)).toBeHidden();
}

/** Rectángulos útiles: área desplazable y barra fija. */
const frame = (page) => page.evaluate(() => {
  const sc = document.querySelector('.oh-shell-content').getBoundingClientRect();
  const dock = document.querySelector('.cd-dock').getBoundingClientRect();
  return { top: sc.top, bottom: sc.bottom, dockTop: dock.top, dockBottom: dock.bottom };
});

// ───────────────────────── Casos funcionales ─────────────────────────

test.describe('Comanda multi-ítem', () => {
  test.beforeEach(async ({ page }) => { await setup(page); });

  test('1. Productos sin opciones se apilan con un toque y el catálogo queda abierto', async ({ page }) => {
    await card(page, 'Jugo de Piña').click();
    await card(page, 'Agua').click();
    await card(page, 'Limonada').click();
    await expect(page.locator('.product-picker-panel')).toBeVisible();
    await expect(lines(page)).toHaveCount(3);
    await expect(page.locator('.cd-bar-count')).toHaveText('3 ítems');
    await expect(page.locator('.cd-bar-total')).toHaveText('Bs 27.00');
  });

  test('2. El mismo producto sin ajustes suma cantidad en la misma fila', async ({ page }) => {
    for (let i = 0; i < 3; i++) await card(page, 'Jugo de Piña').click();
    await expect(lines(page)).toHaveCount(1);
    await expect(lines(page).first().locator('.cd-qty-input')).toHaveValue('3');
    await expect(lines(page).first().locator('.cd-line-subtotal')).toHaveText('Bs 30.00');
    await expect(page.locator('.cd-bar-added')).toContainText('Jugo de Piña (3)');
  });

  test('A. Bowl con opciones: abre el configurador, entra con su precio y se puede editar', async ({ page }) => {
    await addBowl(page, 'Classic Bowl', ['Mediano', 'Frutilla']);
    await expect(lines(page)).toHaveCount(1);
    await expect(lines(page).first().locator('.cd-line-subtotal')).toHaveText('Bs 35.00');
    await expect(lines(page).first().locator('.cd-line-pills')).toContainText('Mediano');
    await expect(lines(page).first().locator('.cd-line-pills')).toContainText('Frutilla');
    await expect(page.locator('.product-picker-panel')).toBeVisible();

    await lines(page).first().locator('.cd-tool--edit').click();
    await expect(confirmBtn(page)).toContainText('Guardar cambios');
    await pick(page, 'Grande');
    await confirmBtn(page).click();
    await expect(lines(page)).toHaveCount(1);
    await expect(lines(page).first().locator('.cd-line-subtotal')).toHaveText('Bs 42.00');

    await lines(page).first().locator('.cd-tool--edit').click();
    await pick(page, 'Ohana');
    await confirmBtn(page).click();
    await expect(lines(page).first().locator('.cd-line-subtotal')).toHaveText('Bs 60.00');
  });

  test('A2. El mismo bowl con las mismas opciones suma cantidad; con otras opciones es otra fila', async ({ page }) => {
    await addBowl(page, 'Classic Bowl', ['Mediano', 'Frutilla']);
    await addBowl(page, 'Classic Bowl', ['Mediano', 'Frutilla']);
    await expect(lines(page)).toHaveCount(1);
    await expect(lines(page).first().locator('.cd-line-subtotal')).toHaveText('Bs 70.00');
    await addBowl(page, 'Classic Bowl', ['Grande', 'Kiwi']);
    await expect(lines(page)).toHaveCount(2);
  });

  test('A3. Si al editar una fila queda igual a otra, se juntan', async ({ page }) => {
    await addBowl(page, 'Classic Bowl', ['Mediano', 'Frutilla']);
    await addBowl(page, 'Classic Bowl', ['Grande', 'Frutilla']);
    await expect(lines(page)).toHaveCount(2);
    await lines(page).nth(1).locator('.cd-tool--edit').click();
    await pick(page, 'Mediano');
    await confirmBtn(page).click();
    await expect(lines(page)).toHaveCount(1);
    await expect(lines(page).first().locator('.cd-qty-input')).toHaveValue('2');
  });

  test('B. Toppings: pestaña a un toque, no entra vacío y suma por topping', async ({ page }) => {
    await tab(page, 'Toppings').click();
    await expect(page.locator('.product-picker-card')).toHaveCount(1);
    await card(page, 'Toppings').click();
    await expect(confirmBtn(page)).toBeDisabled();
    await expect(confirmBtn(page)).toContainText('Elige al menos una opción');
    await pick(page, 'Sin granola');
    await expect(confirmBtn(page)).toBeDisabled();
    await pick(page, 'Sin granola');
    await pick(page, 'Chips de Chocolate');
    await pick(page, 'Chubi');
    await expect(confirmBtn(page)).toBeEnabled();
    await confirmBtn(page).click();
    await expect(lines(page).first().locator('.cd-line-subtotal')).toHaveText('Bs 8.00');
    await lines(page).first().locator('.cd-qty-btn', { hasText: '+' }).click();
    await expect(lines(page).first().locator('.cd-line-subtotal')).toHaveText('Bs 16.00');
    await expect(lines(page).first().locator('.cd-line-unit')).toHaveText('Bs 8.00 c/u');
  });

  test('B2. Las pestañas solo muestran categorías con productos; buscar ignora la pestaña', async ({ page }) => {
    await expect(tab(page, 'Vacía')).toHaveCount(0);
    await tab(page, 'Jugos').click();
    await expect(card(page, 'Classic Bowl')).toHaveCount(0);
    await page.locator('.picker-search-input').fill('bowl');
    await expect(card(page, 'Classic Bowl')).toHaveCount(1);
    await expect(card(page, 'Arma tu Bowl')).toHaveCount(1);
  });

  test('C. Registrar: envía ítems con opciones, limpia la comanda y conserva el motivo', async ({ page }) => {
    let sent = null;
    await page.route('**/api/admin/sales', async (r) => {
      if (r.request().method() === 'POST') { sent = JSON.parse(r.request().postData()); return r.fulfill(json({ sale: { id: 9, order_number: 'C-0101' } })); }
      return r.fulfill(json({ data: [] }));
    });
    await addBowl(page, 'Classic Bowl', ['Mediano', 'Frutilla']);
    await tab(page, 'Toppings').click();
    await card(page, 'Toppings').click();
    await pick(page, 'Chubi');
    await confirmBtn(page).click();
    await page.locator('.cd-reason-chip', { hasText: 'Talonario manual' }).click();
    await expect(page.locator('.cd-bar-total')).toHaveText('Bs 39.00');
    await page.locator('.cd-bar-save').click();
    await expect(page.locator('.cd-bar-added')).toContainText('C-0101');
    expect(sent.items).toHaveLength(2);
    expect(sent.items[0].modifiers.map((m) => m.option_name)).toEqual(expect.arrayContaining(['Mediano', 'Frutilla']));
    expect(sent.items[1].modifiers.map((m) => m.option_name)).toEqual(['Chubi']);
    expect(sent.edit_reason).toBe('Talonario manual');
    await expect(lines(page)).toHaveCount(0, { timeout: 4000 });
    await expect(page.locator('textarea.form-textarea')).toHaveValue('Talonario manual');
  });

  test('D. PedidosYa: el configurador y la comanda usan la tarifa de delivery', async ({ page }) => {
    await page.locator('.cd-meta .dest-btn', { hasText: 'PedidosYa' }).click();
    await addBowl(page, 'Classic Bowl', ['Mediano', 'Frutilla']);
    await expect(lines(page).first().locator('.cd-line-subtotal')).toHaveText('Bs 37.00');
    await card(page, 'Agua').click();
    await expect(lines(page).nth(1).locator('.cd-line-subtotal')).toHaveText('Bs 10.00');
  });

  test('E. Datos de la venta plegados en una línea; el origen siempre a la vista', async ({ page }) => {
    await expect(page.locator('.cd-meta-fields')).toBeHidden();
    await expect(page.locator('.cd-meta-part').nth(1)).toHaveText('Registra tu usuario');
    await expect(page.locator('.cd-meta .dest-btn')).toHaveCount(3);
    await page.locator('.cd-meta-toggle').click();
    await expect(page.locator('#cd-f-fecha')).toBeVisible();
    await page.locator('#cd-f-nota').fill('Talonario 045');
    await page.locator('.cd-meta-toggle').click();
    await expect(page.locator('.cd-meta-part').last()).toHaveText('Talonario 045');
  });
});

// ───────────────────────── Portátil de caja: P1–P8 ─────────────────────────

for (const vp of LAPTOP) {
  test.describe(`Portátil ${vp.width}×${vp.height} (${vp.desc})`, () => {
    test.beforeEach(async ({ page }) => { await setup(page, { width: vp.width, height: vp.height }); });

    test('P1. Al abrir, la primera fila del catálogo se ve completa sin desplazar', async ({ page }) => {
      const f = await frame(page);
      const c = await card(page, 'Classic Bowl').boundingBox();
      expect(c.y, 'arriba del catálogo').toBeGreaterThanOrEqual(f.top);
      expect(c.y + c.height, 'el catálogo no queda tapado por la barra').toBeLessThanOrEqual(f.dockTop);
    });

    test('P2–P3. Con 10 ítems, Registrar siempre visible y la última fila no queda tapada', async ({ page }) => {
      for (const n of ['Jugo de Piña', 'Jugo de Naranja', 'Limonada', 'Agua', 'Smoothie Frutilla', 'Smoothie Mango']) await card(page, n).click();
      await addBowl(page, 'Classic Bowl', ['Mediano', 'Frutilla']);
      await addBowl(page, 'Hawaian Bowl', ['Grande', 'Kiwi', 'Chubi']);
      await addBowl(page, 'Aloha', ['Banana']);
      await addBowl(page, 'Marley', ['Ohana', 'Mango', 'Coco']);
      await expect(lines(page)).toHaveCount(10);
      // Mientras el editor está en pantalla (a 125 % el panel de Arqueo queda debajo y la barra se va con el editor)
      for (const where of ['inicio', 'mitad', 'final del editor']) {
        await page.evaluate((w) => {
          const sc = document.querySelector('.oh-shell-content');
          const ed = document.querySelector('.inline-editor-card');
          const edTop = ed.getBoundingClientRect().top - sc.getBoundingClientRect().top + sc.scrollTop;
          const edEnd = edTop + ed.offsetHeight - sc.clientHeight;
          sc.scrollTop = w === 'inicio' ? 0 : w === 'mitad' ? edEnd / 2 : edEnd;
        }, where);
        await page.waitForTimeout(80);
        const hit = await page.evaluate(() => {
          const b = document.querySelector('.cd-bar-save'); const r = b.getBoundingClientRect();
          const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
          return { inside: r.top >= 0 && r.bottom <= innerHeight, hits: b === el || b.contains(el) };
        });
        expect(hit.inside, `Registrar dentro de la pantalla (${where})`).toBe(true);
        expect(hit.hits, `nada tapa Registrar (${where})`).toBe(true);
      }
      // Al fondo, la última fila queda por encima de la barra
      // P3: se puede llevar la última fila por encima de la barra fija
      await page.evaluate(() => {
        const sc = document.querySelector('.oh-shell-content');
        const rows = document.querySelectorAll('.cd-ticket .cd-line');
        const last = rows[rows.length - 1].getBoundingClientRect();
        const dock = document.querySelector('.cd-dock').getBoundingClientRect();
        sc.scrollTop += last.bottom - dock.top + 8;
      });
      const lastRow = await lines(page).last().boundingBox();
      const f = await frame(page);
      expect(lastRow.y + lastRow.height, 'última fila sobre la barra').toBeLessThanOrEqual(f.dockTop + 1);
    });

    test(`P4. Catálogo y al menos ${vp.minRows} filas de comanda visibles a la vez`, async ({ page }) => {
      for (const n of ['Jugo de Piña', 'Limonada', 'Agua']) await card(page, n).click();
      await addBowl(page, 'Classic Bowl', ['Mediano', 'Frutilla', 'Chubi']);
      // Un solo gesto del usuario: llevar el catálogo al tope
      await page.evaluate(() => document.querySelector('.items-section-header').scrollIntoView({ block: 'start' }));
      const f = await frame(page);
      const grid = await page.locator('.picker-products-grid').boundingBox();
      expect(grid.y).toBeGreaterThanOrEqual(f.top - 1);
      const boxes = await lines(page).evaluateAll((els) => els.map((e) => e.getBoundingClientRect().bottom));
      const visible = boxes.filter((b) => b <= f.dockTop + 1).length;
      expect(visible, 'filas visibles sobre la barra').toBeGreaterThanOrEqual(vp.minRows);
    });

    test('P5–P6. Toppings a un toque, sin desborde horizontal y con la ventana fija', async ({ page }) => {
      await tab(page, 'Toppings').click();
      await expect(card(page, 'Toppings')).toBeVisible();
      const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, vw: innerWidth, sh: document.documentElement.scrollHeight, vh: innerHeight }));
      expect(m.sw).toBeLessThanOrEqual(m.vw);
      expect(m.sh).toBeLessThanOrEqual(m.vh);
    });

    test('P7. El configurador del bowl deja el botón de confirmar dentro de la pantalla', async ({ page }) => {
      await card(page, 'Classic Bowl').click();
      const b = await confirmBtn(page).boundingBox();
      expect(b.y + b.height).toBeLessThanOrEqual(vp.height);
      expect(b.y).toBeGreaterThanOrEqual(0);
    });

    test('P8. Botones de cantidad y herramientas de al menos 32 × 32 px', async ({ page }) => {
      await addBowl(page, 'Classic Bowl', ['Frutilla']);
      const sizes = await page.locator('.cd-line .cd-qty-btn, .cd-line .cd-tool').evaluateAll((els) => els.map((e) => { const r = e.getBoundingClientRect(); return [r.width, r.height]; }));
      expect(sizes.length).toBeGreaterThanOrEqual(5);
      for (const [w, h] of sizes) { expect(w).toBeGreaterThanOrEqual(32); expect(h).toBeGreaterThanOrEqual(32); }
    });
  });
}

// ───────────────────────── P9: sin regresión ─────────────────────────

for (const vp of [{ width: 1440, height: 900, desc: 'escritorio', minTarget: 32 }, { width: 390, height: 844, desc: 'teléfono', minTarget: 44 }]) {
  test(`P9. ${vp.desc} ${vp.width}×${vp.height}: sin desborde, Registrar alcanzable y botones cómodos`, async ({ page }) => {
    await setup(page, { width: vp.width, height: vp.height });
    await card(page, 'Jugo de Piña').click();
    await addBowl(page, 'Classic Bowl', ['Frutilla']);
    const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, vw: innerWidth }));
    expect(m.sw).toBeLessThanOrEqual(m.vw);
    const save = await page.locator('.cd-bar-save').boundingBox();
    expect(save.y + save.height).toBeLessThanOrEqual(vp.height);
    const sizes = await page.locator('.cd-line .cd-qty-btn, .cd-line .cd-tool').evaluateAll((els) => els.map((e) => Math.min(e.getBoundingClientRect().width, e.getBoundingClientRect().height)));
    for (const s of sizes) expect(s).toBeGreaterThanOrEqual(vp.minTarget);
  });
}

// ───────────────────────── Fuera de Control diario ─────────────────────────

test('Ventas (POS): «Toppings» no se cobra vacío y suma por topping', async ({ page }) => {
  await setup(page);
  await page.goto('/pos');
  await page.locator('.product-grid > *', { hasText: 'Toppings' }).first().click();
  await expect(confirmBtn(page)).toBeDisabled();
  await expect(confirmBtn(page)).toContainText('Elige al menos una opción');
  await pick(page, 'Chubi');
  await expect(confirmBtn(page)).toBeEnabled();
  await confirmBtn(page).click();
  await expect(page.locator('.ticket-item')).toHaveCount(1);
  await expect(page.locator('.ticket-item')).toContainText('Chubi');
});

test('Turno: el editor en ventana abre el configurador encima y apila', async ({ page }) => {
  await setup(page);
  await page.goto('/turno');
  await page.getByRole('button', { name: /retroactiva|registrar venta/i }).first().click();
  await expect(page.locator('.editor-overlay')).toBeVisible();
  await page.locator('.editor-overlay .product-picker-card').filter({ has: page.locator('.card-name', { hasText: /^Classic Bowl$/ }) }).click();
  await expect(modal(page)).toBeVisible();
  const z = await page.evaluate(() => {
    const m = document.querySelector('.modal-content').getBoundingClientRect();
    const el = document.elementFromPoint(m.left + m.width / 2, m.top + 20);
    return document.querySelector('.modal-content').contains(el);
  });
  expect(z, 'el configurador queda por encima del editor').toBe(true);
  await pick(page, 'Frutilla');
  await confirmBtn(page).click();
  await expect(page.locator('.editor-overlay .cd-line')).toHaveCount(1);
  await expect(page.locator('.editor-overlay .modal-footer .btn-save')).toBeVisible();
  await expect(page.locator('.editor-overlay .cd-bar')).toHaveCount(0);
});
