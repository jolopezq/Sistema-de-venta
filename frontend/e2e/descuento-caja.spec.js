import { test, expect } from '@playwright/test';

/**
 * Descuento manual al cobrar (docs/PLAN_DESCUENTOS_CAJA.md §3.5 y §3.6).
 * Solo con el permiso «Descuentos en caja»; cajero hasta el 20 %, administrador hasta el 100 % (cortesía);
 * motivo obligatorio; el cobro (pagos, billetes, vuelto) se calcula sobre el total con descuento.
 */

const BASE_PERMS = {
  pos: 'edit', register_self: 'edit', delivery_ops: 'edit', crm_read: 'read',
  voids: 'none', register_others: 'none', catalog: 'none', inventory: 'none',
  crm_config: 'none', reports: 'none', users: 'none', audit: 'none',
};
const CASHIER_POLICY = { allowed: true, max_percent: 20, reason_min: 10, reason_max: 255 };
const ADMIN_POLICY = { allowed: true, max_percent: 100, reason_min: 10, reason_max: 255 };

const meFor = (role, policy) => ({
  user: { id: 7, name: role === 'admin' ? 'Lourdes' : 'Ana', email: 'caja@example.com', role },
  permissions: [],
  effective_permissions: { ...BASE_PERMS, pos_discounts: policy?.allowed ? 'edit' : 'none' },
  discount_policy: policy,
});

const CATALOG = {
  categories: [{ id: 1, name: 'Açaí', sort_order: 1 }],
  products: [
    { id: 1, name: 'Açaí Grande', price: 38.5, delivery_price: 42, category_id: 1, is_active: true, is_available: true, option_groups: [], available_on_pedidosya: true },
    { id: 2, name: 'Café Doble', price: 28, delivery_price: 30, category_id: 1, is_active: true, is_available: true, option_groups: [], available_on_pedidosya: true },
  ],
  customers: [],
  ingredients: [],
};

const json = (body) => ({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });

async function setup(page, { role = 'cashier', policy = CASHIER_POLICY, viewport = { width: 1366, height: 611 } } = {}) {
  const synced = [];
  await page.setViewportSize(viewport);
  await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
  await page.route('**/api/me', (r) => r.fulfill(json(meFor(role, policy))));
  await page.route('**/api/catalog', (r) => r.fulfill(json(CATALOG)));
  await page.route('**/api/cash-sessions/active', (r) => r.fulfill(json({
    session: { id: 1, status: 'open', opening_amount: 100, opened_at: new Date().toISOString() },
    stats: {},
    sales_session_id: 1,
  })));
  await page.route('**/api/inventory/reorder-summary', (r) => r.fulfill(json({ count: 0, items: [] })));
  await page.route('**/api/sales/sync', (r) => {
    const body = JSON.parse(r.request().postData() || '{}');
    synced.push(...(body.sales || []));
    return r.fulfill(json({ data: { synced: (body.sales || []).map((s) => s.id), failed: [] } }));
  });
  await page.addInitScript(() => window.localStorage.setItem('auth_token', 'mock_jwt'));
  return synced;
}

/** Café Doble (Bs 28) × 5 = Bs 140: el 20 % es Bs 28. */
async function openCheckout(page, { units = 5, product = 1, pedidosYa = false } = {}) {
  await page.goto('/pos');
  if (pedidosYa) await page.locator('.channel-btn--pya').click();
  const card = page.locator('.product-grid > *').nth(product);
  await expect(card).toBeVisible();
  for (let i = 0; i < units; i++) await card.click();
  await page.locator('.btn-charge').click();
  await expect(page.locator('.co-card')).toBeVisible();
}

const cobrar = (page) => page.locator('.co-btn-primary');
const discountLink = (page) => page.getByRole('button', { name: 'Aplicar descuento' });
const amount = (page) => page.locator('#co-discount');
const reason = (page) => page.getByLabel('Motivo del descuento');
const issue = (page) => page.locator('#co-discount-issue');

async function applyDiscount(page, value, text) {
  await discountLink(page).click();
  await amount(page).fill(String(value));
  if (text !== undefined) await reason(page).fill(text);
}

test.describe('Descuento manual al cobrar', () => {
  test('1. Sin el permiso no aparece el enlace', async ({ page }) => {
    await setup(page, { policy: { allowed: false, max_percent: 20, reason_min: 10, reason_max: 255 } });
    await openCheckout(page);
    await expect(page.locator('.co-total-amount')).toHaveText('Bs 140.00');
    await expect(discountLink(page)).toHaveCount(0);
  });

  test('2. En PedidosYa no aparece aunque tenga el permiso', async ({ page }) => {
    await setup(page);
    await openCheckout(page, { pedidosYa: true });
    await expect(page.locator('#co-pya-id')).toBeVisible();
    await expect(discountLink(page)).toHaveCount(0);
  });

  test('3. Cajero: tope del 20 % y motivo obligatorio antes de cobrar', async ({ page }) => {
    await setup(page);
    await openCheckout(page);
    await discountLink(page).click();
    await expect(amount(page)).toBeFocused();
    await expect(page.locator('.co-discount .co-row-label-sub')).toHaveText('máx. Bs 28.00');

    await amount(page).fill('30');
    await expect(issue(page)).toHaveText('Máximo Bs 28.00 (20 % del subtotal).');
    await expect(cobrar(page)).toBeDisabled();

    await amount(page).fill('20');
    await expect(issue(page)).toHaveText('Escribe el motivo del descuento (mínimo 10 caracteres).');
    await expect(cobrar(page)).toBeDisabled();
    await expect(page.locator('.co-total-amount')).toHaveText('Bs 120.00');
    await expect(page.locator('.co-total-sub')).toContainText('Subtotal Bs 140.00 · Descuento −Bs 20.00 (14.3 %)');

    // Enter en «Recibe» con el motivo pendiente: el foco va al motivo
    await page.locator('#co-received').press('Enter');
    await expect(reason(page)).toBeFocused();
    await expect(page.locator('.co-card')).toBeVisible();

    await reason(page).fill('Pedido de 5 cafés para la oficina');
    await expect(cobrar(page)).toBeEnabled();
    await expect(cobrar(page)).toHaveText('Cobrar Bs 120.00');
  });

  test('4. Efectivo: billetes y vuelto sobre el total con descuento; la venta lleva monto y motivo', async ({ page }) => {
    const synced = await setup(page);
    await openCheckout(page);
    await applyDiscount(page, 20, 'Pedido de 5 cafés para la oficina');
    await expect(page.locator('#co-received')).toHaveValue('120');
    await page.locator('.co-bill', { hasText: 'Bs 200' }).click();
    await expect(page.locator('.co-result.change .co-result-amount')).toHaveText('Bs 80.00');

    await cobrar(page).click();
    await expect(page.locator('.rc-card')).toBeVisible();
    await expect(page.locator('.rc-facts')).toContainText('Subtotal');
    await expect(page.locator('.rc-facts')).toContainText('−Bs 20.00');
    await expect(page.locator('.rc-reason')).toHaveText('Motivo: Pedido de 5 cafés para la oficina');
    await expect(page.locator('.rc-change strong')).toHaveText('Bs 80.00');

    await expect.poll(() => synced.length).toBe(1);
    expect(synced[0]).toMatchObject({
      subtotal: 140,
      discount_amount: 20,
      discount_reason: 'Pedido de 5 cafés para la oficina',
      total_amount: 120,
      payments: [{ method: 'cash', amount: 120 }],
    });
  });

  test('5. QR y pago dividido suman el total con descuento', async ({ page }) => {
    const synced = await setup(page);
    await openCheckout(page);
    await page.getByRole('button', { name: 'Dividir el pago' }).click();
    await applyDiscount(page, 28, 'Cliente frecuente del local');
    const lines = page.locator('.co-split-line .co-money-input input');
    // La línea menos reciente absorbe el cambio del total
    await lines.nth(1).fill('50');
    await expect(lines.nth(0)).toHaveValue('62');
    await cobrar(page).click();
    await expect.poll(() => synced.length).toBe(1);
    const paid = synced[0].payments.reduce((s, p) => s + p.amount, 0);
    expect(paid).toBe(112);
    expect(synced[0].total_amount).toBe(112);
  });

  test('6. Quitar el descuento y volver a abrir el cobro: empieza sin descuento', async ({ page }) => {
    await setup(page);
    await openCheckout(page);
    await applyDiscount(page, 10, 'Cliente frecuente del local');
    await page.getByRole('button', { name: 'Quitar descuento' }).click();
    await expect(page.locator('.co-total-amount')).toHaveText('Bs 140.00');
    await expect(page.locator('#co-received')).toHaveValue('140');

    await applyDiscount(page, 10, 'Cliente frecuente del local');
    await page.getByRole('button', { name: 'Cerrar sin cobrar' }).click();
    await page.locator('.btn-charge').click();
    await expect(page.locator('.co-total-amount')).toHaveText('Bs 140.00');
    await expect(amount(page)).toHaveCount(0);
    await expect(discountLink(page)).toBeVisible();
  });

  test('7. Administrador: cortesía del 100 % sin pago ni vuelto', async ({ page }) => {
    const synced = await setup(page, { role: 'admin', policy: ADMIN_POLICY });
    await openCheckout(page);
    await applyDiscount(page, 140, 'Cortesía por el error del pedido de ayer');
    await expect(page.locator('.co-total-label')).toHaveText('Cortesía: no se cobra');
    await expect(page.locator('.co-result.courtesy')).toBeVisible();
    await expect(page.locator('#co-received')).toHaveCount(0);
    await expect(page.getByRole('radio', { name: 'QR' })).toHaveCount(0);
    await expect(cobrar(page)).toHaveText('Registrar cortesía');

    await cobrar(page).click();
    await expect(page.locator('#rc-title')).toHaveText('Cortesía registrada');
    await expect.poll(() => synced.length).toBe(1);
    expect(synced[0]).toMatchObject({
      discount_amount: 140,
      total_amount: 0,
      payments: [{ method: 'cash', amount: 0 }],
    });
  });

  test('8. Un cajero no puede hacer una cortesía', async ({ page }) => {
    await setup(page);
    await openCheckout(page);
    await applyDiscount(page, 140, 'Le regalo el pedido');
    await expect(issue(page)).toHaveText('Máximo Bs 28.00 (20 % del subtotal).');
    await expect(cobrar(page)).toBeDisabled();
  });
});

test.describe('Descuento en la portátil de caja', () => {
  /** El modal no desborda a lo ancho y el pie con Cobrar queda dentro de la pantalla. */
  async function expectLayout(page, { vertical }) {
    const m = await page.evaluate(() => {
      const body = document.querySelector('.co-body');
      const card = document.querySelector('.co-card');
      return {
        hx: body.scrollWidth - body.clientWidth,
        cx: card.scrollWidth - card.clientWidth,
        vy: body.scrollHeight - body.clientHeight,
        footBottom: document.querySelector('.co-foot').getBoundingClientRect().bottom,
        vh: window.innerHeight,
      };
    });
    expect(m.hx).toBeLessThanOrEqual(1);
    expect(m.cx).toBeLessThanOrEqual(1);
    if (vertical) expect(m.vy, 'scroll vertical en .co-body').toBeLessThanOrEqual(1);
    expect(m.footBottom).toBeLessThanOrEqual(m.vh + 0.5);
    await expect(cobrar(page)).toBeInViewport();
  }

  test('9. 1366 × 611: descuento con motivo y vuelto entran completos', async ({ page }) => {
    await setup(page);
    await openCheckout(page);
    await applyDiscount(page, 20, 'Pedido de 5 cafés para la oficina');
    await page.locator('.co-bill', { hasText: 'Bs 200' }).click();
    await expect(page.locator('.co-result.change')).toBeInViewport();
    await expectLayout(page, { vertical: true });
  });

  test('10. 125 % (1093 × 489): monto, motivo, vuelto y Cobrar a la vista', async ({ page }) => {
    await setup(page, { viewport: { width: 1093, height: 489 } });
    await openCheckout(page);
    await applyDiscount(page, 20, 'Pedido de 5 cafés para la oficina');
    await page.locator('.co-bill', { hasText: 'Bs 200' }).click();
    await expect(amount(page)).toBeInViewport();
    await expect(reason(page)).toBeInViewport();
    await expect(page.locator('.co-result.change')).toBeInViewport();
    await expectLayout(page, { vertical: false });
  });

  test('11. Tema oscuro y teléfono: sin desborde', async ({ page }) => {
    await setup(page, { viewport: { width: 390, height: 844 } });
    await openCheckout(page);
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    await applyDiscount(page, 20, 'Pedido de 5 cafés para la oficina');
    await expectLayout(page, { vertical: false });
  });
});
