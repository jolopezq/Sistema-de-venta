import { test, expect } from '@playwright/test';

/**
 * Autoservicio (producto por peso) en el ticket del POS.
 * Cada bowl se pesa aparte y es su propia línea: un segundo bowl igual no se suma al primero, no hay «+»,
 * «Quitar» saca solo ese bowl y el peso se escribe en cada bowl (no viene uno puesto).
 */

const ME = {
  user: { id: 7, name: 'Cajero Prueba', email: 'caja@example.com', role: 'cashier' },
  permissions: [],
  effective_permissions: {
    pos: 'edit', register_self: 'edit', delivery_ops: 'edit', crm_read: 'read',
    voids: 'none', register_others: 'none', catalog: 'none', inventory: 'none',
    crm_config: 'none', reports: 'none', users: 'none', audit: 'none',
  },
};

// 0,24 Bs el gramo: 151 g → Bs 36, 160 g → Bs 38, 200 g → Bs 48
const CATALOG = {
  categories: [{ id: 1, name: 'Açaí', sort_order: 1 }],
  products: [
    { id: 5, name: 'Autoservicio', price: 0, category_id: 1, is_active: true, is_available: true, option_groups: [], is_weight_based: true, price_per_gram: 0.24 },
    { id: 2, name: 'Café Doble', price: 28, category_id: 1, is_active: true, is_available: true, option_groups: [] },
  ],
  customers: [],
  ingredients: [],
};

const json = (body) => ({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });

async function setupMockApi(page) {
  const synced = [];
  await page.route('**/api/**', (route) => route.fulfill(json({ data: [] })));
  await page.route('**/api/me', (route) => route.fulfill(json(ME)));
  await page.route('**/api/catalog', (route) => route.fulfill(json(CATALOG)));
  await page.route('**/api/cash-sessions/active', (route) => route.fulfill(json({
    session: { id: 1, status: 'open', opening_amount: 100, opened_at: new Date().toISOString() },
    stats: {},
    sales_session_id: 1,
  })));
  await page.route('**/api/inventory/reorder-summary', (route) => route.fulfill(json({ count: 0, items: [] })));
  await page.route('**/api/sales/sync', (route) => {
    const { sales } = JSON.parse(route.request().postData());
    synced.push(...sales);
    return route.fulfill(json({ data: { synced: sales.map((s) => s.id), failed: [] } }));
  });
  await page.addInitScript(() => window.localStorage.setItem('auth_token', 'mock_cashier_jwt'));
  return synced;
}

const lines = (page) => page.locator('.ticket-items .ticket-item');
const weight = (page) => page.locator('#mm-weight');

/** Toca Autoservicio, escribe los gramos y confirma con Enter. */
async function addBowl(page, grams) {
  await page.locator('.product-grid > *', { hasText: 'Autoservicio' }).click();
  await expect(weight(page)).toBeVisible();
  await expect(weight(page)).toHaveValue('');
  await expect(weight(page)).toBeFocused();
  await page.keyboard.type(String(grams));
  await page.keyboard.press('Enter');
  await expect(weight(page)).toBeHidden();
}

test.describe('Autoservicio por peso', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
  });

  test('Dos bowls con la misma configuración quedan en dos líneas y el total suma los dos', async ({ page }) => {
    await setupMockApi(page);
    await page.goto('/pos');
    await addBowl(page, 151);
    await addBowl(page, 200);

    await expect(lines(page)).toHaveCount(2);
    await expect(lines(page).nth(0)).toContainText('151 g pesados');
    await expect(lines(page).nth(0)).toContainText('Bs 36');
    await expect(lines(page).nth(1)).toContainText('200 g pesados');
    await expect(lines(page).nth(1)).toContainText('Bs 48');
    await expect(page.locator('.ticket-count')).toHaveText('2 productos');
    await expect(page.locator('.sum-row.total .amt')).toHaveText('Bs 84.00');
  });

  test('Sin peso escrito no se puede agregar; el bowl siguiente no hereda el peso del anterior', async ({ page }) => {
    await setupMockApi(page);
    await page.goto('/pos');
    await page.locator('.product-grid > *', { hasText: 'Autoservicio' }).click();
    const confirm = page.locator('.btn-confirm');
    await expect(confirm).toBeDisabled();
    await expect(confirm).toContainText('Escribe el peso');
    await expect(weight(page)).toHaveAttribute('aria-invalid', 'true');
    await page.keyboard.press('Enter');
    await expect(weight(page)).toBeVisible();
    await expect(lines(page)).toHaveCount(0);
    await page.keyboard.type('151');
    await expect(confirm).toBeEnabled();
    await expect(weight(page)).toHaveAttribute('aria-invalid', 'false');
    await page.keyboard.press('Enter');
    await expect(lines(page)).toHaveCount(1);
    await page.locator('.product-grid > *', { hasText: 'Autoservicio' }).click();
    await expect(weight(page)).toHaveValue('');
  });

  test('Un bowl no tiene «+»; «Quitar» saca solo ese bowl', async ({ page }) => {
    await setupMockApi(page);
    await page.goto('/pos');
    await addBowl(page, 151);
    await addBowl(page, 200);

    await expect(page.getByRole('button', { name: /Agregar otro Autoservicio/ })).toHaveCount(0);
    // Los gramos se leen una sola vez por línea
    await expect(lines(page).nth(0).getByText('151 g')).toHaveCount(1);

    await page.getByRole('button', { name: 'Quitar Autoservicio de 151 g' }).click();
    await expect(lines(page)).toHaveCount(1);
    await expect(lines(page).nth(0)).toContainText('200 g pesados');
    await expect(page.locator('.sum-row.total .amt')).toHaveText('Bs 48.00');
  });

  test('Editar el peso de un bowl lo cambia en su lugar y no toca al otro', async ({ page }) => {
    await setupMockApi(page);
    await page.goto('/pos');
    await addBowl(page, 151);
    await addBowl(page, 200);

    await lines(page).nth(0).getByRole('button', { name: 'Editar Autoservicio' }).click();
    await expect(weight(page)).toHaveValue('151');
    await weight(page).fill('160');
    await page.locator('.btn-confirm').click();

    await expect(lines(page)).toHaveCount(2);
    await expect(lines(page).nth(0)).toContainText('160 g pesados');
    await expect(lines(page).nth(0)).toContainText('Bs 38');
    await expect(lines(page).nth(1)).toContainText('200 g pesados');
    await expect(page.locator('.sum-row.total .amt')).toHaveText('Bs 86.00');
  });

  test('Los productos normales siguen sumando con «+» y «−»', async ({ page }) => {
    await setupMockApi(page);
    await page.goto('/pos');
    const cafe = page.locator('.product-grid > *', { hasText: 'Café Doble' });
    await cafe.click();
    await cafe.click();
    await expect(lines(page)).toHaveCount(1);
    await expect(lines(page).nth(0).locator('.qty-val')).toHaveText('2');
    await page.getByRole('button', { name: 'Agregar otro Café Doble' }).click();
    await expect(lines(page).nth(0).locator('.qty-val')).toHaveText('3');
    await page.getByRole('button', { name: 'Quitar uno de Café Doble' }).click();
    await expect(lines(page).nth(0).locator('.qty-val')).toHaveText('2');
    await expect(page.locator('.sum-row.total .amt')).toHaveText('Bs 56.00');
  });

  test('Cambiar a «Para llevar» una línea no hace que el siguiente producto en mesa se sume a ella', async ({ page }) => {
    await setupMockApi(page);
    await page.goto('/pos');
    const cafe = page.locator('.product-grid > *', { hasText: 'Café Doble' });
    await cafe.click();
    await page.getByRole('button', { name: 'Café Doble: en mesa. Cambiar a para llevar' }).click();
    await cafe.click();
    await expect(lines(page)).toHaveCount(2);
    await expect(lines(page).nth(0).locator('.item-dest-chip')).toHaveText('Para llevar');
    await expect(lines(page).nth(1).locator('.item-dest-chip')).toHaveText('En mesa');
  });

  test('La venta llega al servidor con un ítem por bowl, cantidad 1 y sus gramos', async ({ page }) => {
    const synced = await setupMockApi(page);
    await page.goto('/pos');
    await addBowl(page, 151);
    await addBowl(page, 200);
    await page.locator('.btn-charge').click();
    await page.getByRole('radio', { name: 'QR' }).click();
    await page.locator('.co-btn-primary').click();

    await expect.poll(() => synced.length, { timeout: 15000 }).toBe(1);
    const items = synced[0].items.map((i) => [i.product_id, i.quantity, i.weight_grams, i.unit_price, i.subtotal]);
    expect(items).toEqual([[5, 1, 151, 36, 36], [5, 1, 200, 48, 48]]);
    expect(synced[0].total_amount).toBe(84);
  });
});
