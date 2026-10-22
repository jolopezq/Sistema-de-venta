import { test, expect } from '@playwright/test';

/**
 * Control diario: apilar varios ítems en una comanda y vender toppings sueltos.
 * Plan: docs/PLAN_VENTAS_MULTI_ITEM_Y_TOPPINGS.md
 */
const json = (body, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(body) });
const ME = {
  user: { id: 1, name: 'Dueño Prueba', email: 'dueno@example.com', role: 'super_admin' }, permissions: [],
  effective_permissions: { pos: 'edit', register_self: 'edit', delivery_ops: 'edit', crm_read: 'edit', voids: 'edit', register_others: 'edit', catalog: 'edit', inventory: 'edit', crm_config: 'edit', reports: 'edit', users: 'edit', audit: 'edit' },
};
const opt = (id, name, extra, def = false) => ({ id, name, additional_price: extra, delivery_price: extra, is_active: true, is_default: def, sort_order: id });
const CATALOG = {
  categories: [{ id: 1, name: 'Açaí' }, { id: 2, name: 'Jugos' }, { id: 3, name: 'Toppings' }],
  customers: [], ingredients: [],
  products: [
    { id: 1, name: 'Classic Bowl', price: 28, category_id: 1, is_active: true, is_weight_based: false, excluded_options: [], option_groups: [
      { id: 10, name: 'Tamaño Acai', is_active: true, min_selections: 1, max_selections: 1, options: [opt(100, 'Mediano', 0), opt(101, 'Grande', 5)] }] },
    { id: 2, name: 'Bowl con tamaño fijo', price: 30, category_id: 1, is_active: true, is_weight_based: false, excluded_options: [], option_groups: [
      { id: 11, name: 'Tamaño Acai', is_active: true, min_selections: 1, max_selections: 1, options: [opt(110, 'Único', 0, true)] }] },
    { id: 3, name: 'Jugo de Piña', price: 10, category_id: 2, is_active: true, is_weight_based: false, option_groups: [] },
    { id: 4, name: 'Ositos de gomita', price: 4, delivery_price: 4, category_id: 3, is_active: true, is_weight_based: false, option_groups: [] },
    { id: 5, name: 'Chubi', price: 4, delivery_price: 4, category_id: 3, is_active: true, is_weight_based: false, option_groups: [] },
  ],
};

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem('auth_token', 'mock'));
  await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
  await page.route('**/api/me', (r) => r.fulfill(json(ME)));
  await page.route('**/api/catalog', (r) => r.fulfill(json(CATALOG)));
  await page.goto('/admin/ventas-retroactivas');
  await page.waitForSelector('.product-picker-panel');
});

const card = (page, name) => page.locator('.product-picker-card', { hasText: name });
const rows = (page) => page.locator('.items-cards-list .item-card');

test('1. El catálogo queda abierto y los ítems se apilan sin cerrarlo', async ({ page }) => {
  await card(page, 'Jugo de Piña').click();
  await expect(page.locator('.product-picker-panel')).toBeVisible();
  await card(page, 'Ositos de gomita').click();
  await card(page, 'Chubi').click();
  await expect(rows(page)).toHaveCount(3);
  await expect(page.locator('.items-count-tag')).toContainText('3 productos');
});

test('2. El mismo ítem sin ajustes sube la cantidad en vez de duplicar la fila', async ({ page }) => {
  await card(page, 'Ositos de gomita').click();
  await card(page, 'Ositos de gomita').click();
  await card(page, 'Ositos de gomita').click();
  await expect(rows(page)).toHaveCount(1);
  await expect(rows(page).first().locator('.qty-input')).toHaveValue('3');
  await expect(rows(page).first().locator('.item-subtotal-val')).toContainText('12.00');
});

test('3. Topping suelto: se vende solo, a su precio, sin elegir envase de açaí', async ({ page }) => {
  await page.locator('.picker-category-select').selectOption({ label: 'Toppings' });
  await expect(page.locator('.product-picker-card')).toHaveCount(2);
  await card(page, 'Chubi').click();
  await expect(rows(page)).toHaveCount(1);
  await expect(rows(page).first().locator('.item-subtotal-val')).toContainText('4.00');
  await expect(rows(page).first().locator('.item-card-body')).toBeHidden();
});

test('4. Ítem con grupo obligatorio sin elegir se abre; sin nada que elegir queda compacto', async ({ page }) => {
  await card(page, 'Classic Bowl').click();
  await expect(rows(page).first().locator('.item-card-body')).toBeVisible();
  await card(page, 'Jugo de Piña').click();
  await expect(rows(page).nth(1).locator('.item-card-body')).toBeHidden();
  await card(page, 'Bowl con tamaño fijo').click();
  await expect(rows(page).nth(2)).toHaveClass(/item-card--compact/);
  await expect(rows(page).nth(2).locator('.item-summary-line')).toContainText('Único');
});

test('5. El total suma toda la comanda y se puede quitar un ítem', async ({ page }) => {
  await card(page, 'Jugo de Piña').click();
  await card(page, 'Ositos de gomita').click();
  await card(page, 'Chubi').click();
  await expect(page.locator('body')).toContainText('18.00');
  await rows(page).nth(1).locator('.btn-remove-item').click();
  await expect(rows(page)).toHaveCount(2);
  await expect(page.locator('body')).toContainText('14.00');
});

test('6. Portátil 125 %: el catálogo abierto y las filas caben sin desbordar a lo ancho', async ({ page }) => {
  await page.setViewportSize({ width: 1093, height: 489 });
  await card(page, 'Jugo de Piña').click();
  await card(page, 'Chubi').click();
  const w = await page.evaluate(() => ({ s: document.documentElement.scrollWidth, v: innerWidth }));
  expect(w.s).toBeLessThanOrEqual(w.v);
  await expect(rows(page)).toHaveCount(2);
});
