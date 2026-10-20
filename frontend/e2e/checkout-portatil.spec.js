import { test, expect } from '@playwright/test';

/**
 * Modal de cobro en la portátil de caja (1366 × 768, viewport útil 1366 × 611).
 * Garantía: sin scroll horizontal ni vertical en el cuerpo, y pie siempre visible.
 * Plan: docs/PLAN_PANTALLA_COBRO_PORTATIL.md
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

const CATALOG = {
  categories: [{ id: 1, name: 'Açaí', sort_order: 1 }],
  products: [
    { id: 1, name: 'Açaí Grande', price: 38.5, delivery_price: 42, category_id: 1, is_active: true, is_available: true, option_groups: [], available_on_pedidosya: true },
    { id: 2, name: 'Café Doble', price: 28, delivery_price: 30, category_id: 1, is_active: true, is_available: true, option_groups: [], available_on_pedidosya: true },
  ],
  customers: [],
  ingredients: [],
};

async function setupMockApi(page) {
  await page.route('**/api/**', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) }));
  await page.route('**/api/me', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(ME) }));
  await page.route('**/api/catalog', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(CATALOG) }));
  await page.route('**/api/cash-sessions/active', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        session: { id: 1, status: 'open', opening_amount: 100, opened_at: new Date().toISOString() },
        stats: {},
        sales_session_id: 1,
      }),
    }));
  await page.route('**/api/inventory/reorder-summary', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ count: 0, items: [] }) }));
  await page.addInitScript(() => {
    window.localStorage.setItem('auth_token', 'mock_cashier_jwt');
  });
}

async function openCheckout(page, { pedidosYa = false, units = 1, product = 0 } = {}) {
  await page.goto('/pos');
  if (pedidosYa) await page.locator('.channel-btn--pya').click();
  const card = page.locator('.product-grid > *').nth(product);
  await expect(card).toBeVisible();
  for (let i = 0; i < units; i++) await card.click();
  await page.locator('.btn-charge').click();
  await expect(page.locator('.co-card')).toBeVisible();
}

/** Mide el desborde del modal. */
async function measure(page) {
  return page.evaluate(() => {
    const body = document.querySelector('.co-body');
    const card = document.querySelector('.co-card');
    const foot = document.querySelector('.co-foot').getBoundingClientRect();
    const primary = document.querySelector('.co-btn-primary').getBoundingClientRect();
    return {
      hx: body.scrollWidth - body.clientWidth,
      cx: card.scrollWidth - card.clientWidth,
      vy: body.scrollHeight - body.clientHeight,
      footBottom: foot.bottom,
      primaryTop: primary.top,
      primaryBottom: primary.bottom,
      vh: window.innerHeight,
    };
  });
}

async function expectFits(page, { vertical = true } = {}) {
  const m = await measure(page);
  expect(m.hx, 'scroll horizontal en .co-body').toBeLessThanOrEqual(1);
  expect(m.cx, 'scroll horizontal en .co-card').toBeLessThanOrEqual(1);
  if (vertical) expect(m.vy, 'scroll vertical en .co-body').toBeLessThanOrEqual(1);
  expect(m.footBottom, 'el pie debe estar dentro del viewport').toBeLessThanOrEqual(m.vh + 0.5);
  expect(m.primaryTop).toBeGreaterThanOrEqual(0);
  await expect(page.locator('.co-btn-primary')).toBeVisible();
}

const VIEWPORTS_FIT = [
  { width: 1366, height: 611, desc: 'Chrome con favoritos (peor caso)' },
  { width: 1366, height: 641, desc: 'Chrome maximizado' },
  { width: 1366, height: 697, desc: 'App instalada' },
  { width: 1366, height: 768, desc: 'F11' },
  { width: 1280, height: 720, desc: '1280 × 720' },
];

test.describe('Cobro — portátil de caja', () => {
  test.beforeEach(async ({ page }) => { await setupMockApi(page); });

  for (const vp of VIEWPORTS_FIT) {
    test(`1. Efectivo con vuelto entra completo en ${vp.width} × ${vp.height} (${vp.desc})`, async ({ page }) => {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await openCheckout(page);
      await page.locator('.co-bill', { hasText: 'Bs 50' }).click();
      await expect(page.locator('.co-result.change')).toBeVisible();
      await expectFits(page);
    });
  }

  test('2. QR y Tarjeta entran completos', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openCheckout(page);
    await page.getByRole('radio', { name: 'QR' }).click();
    await expect(page.locator('.co-result.qr')).toBeVisible();
    await expectFits(page);
    await page.getByRole('radio', { name: 'Tarjeta' }).click();
    await expect(page.locator('.co-result.card')).toBeVisible();
    await expectFits(page);
  });

  test('3. Pago dividido de 2 y de 3 métodos: sin desborde y con monto y × visibles', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openCheckout(page);
    await page.getByRole('button', { name: 'Dividir el pago' }).click();
    await expect(page.locator('.co-split-line')).toHaveCount(2);
    await expectFits(page);

    await page.getByRole('button', { name: 'Agregar otro método' }).click();
    await expect(page.locator('.co-split-line')).toHaveCount(3);
    await expectFits(page);

    // Cada línea cabe dentro de la tarjeta (monto y × visibles)
    const lines = await page.evaluate(() => {
      const card = document.querySelector('.co-card').getBoundingClientRect();
      return [...document.querySelectorAll('.co-split-line')].map((l) => {
        const sel = l.querySelector('.co-split-method').getBoundingClientRect();
        const btn = l.querySelector('.co-icon-btn').getBoundingClientRect();
        return { selW: Math.round(sel.width), btnRight: btn.right, cardRight: card.right };
      });
    });
    for (const l of lines) {
      expect(l.selW, 'el selector de método no debe ocupar toda la columna').toBeLessThanOrEqual(130);
      expect(l.btnRight).toBeLessThanOrEqual(l.cardRight);
    }
  });

  test('4. Estado "lo recibido no alcanza" (resultado en una línea) entra completo', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openCheckout(page);
    await page.getByRole('button', { name: 'Dividir el pago' }).click();
    await page.locator('.co-split-line .co-money-input input').nth(1).fill('8');
    await page.locator('#co-received').fill('10'); // el reparto siempre suma el total: lo que puede faltar es el efectivo recibido
    await expect(page.locator('.co-result.cash-short')).toBeVisible();
    await expectFits(page);
  });

  test('5. PedidosYa entra completo', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openCheckout(page, { pedidosYa: true });
    await expect(page.locator('#co-pya-id')).toBeVisible();
    await expectFits(page);
  });

  test('6. Tema oscuro entra completo', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openCheckout(page);
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    await page.locator('.co-bill', { hasText: 'Bs 50' }).click();
    await expectFits(page);
  });

  test('7. Escala de Windows al 125 % (≈ 1093 × 489): sin scroll horizontal y con el pie visible', async ({ page }) => {
    await page.setViewportSize({ width: 1093, height: 489 });
    await openCheckout(page);
    await page.locator('.co-bill', { hasText: 'Bs 50' }).click();
    await expectFits(page, { vertical: false });
  });

  test('8. Cobrar un pago dividido cierra el modal (la lógica no cambió)', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openCheckout(page);
    await page.getByRole('button', { name: 'Dividir el pago' }).click();
    // Segunda línea: cubre 20; la primera cubre el resto
    const amounts = page.locator('.co-split-line .co-money-input input');
    await amounts.nth(1).fill('20');
    await amounts.nth(0).fill('18.5');
    await page.locator('.co-btn-primary').click();
    await expect(page.locator('.co-card')).toBeHidden();
  });

  test('9. Teléfono 390 × 844: sin scroll horizontal y el botón Cobrar visible', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openCheckout(page);
    await expectFits(page, { vertical: false });
  });

  // ── Reparto automático del pago dividido (docs/PLAN_COBRO_DIVIDIDO_AUTOCOMPLETAR.md) ──
  const amountInputs = (page) => page.locator('.co-split-line .co-money-input input');
  const total28 = { product: 1 }; // Café Doble, Bs 28

  test('10. Caso de la captura: total 28, QR = 8 → Efectivo pasa a 20 y Cobrar queda habilitado', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openCheckout(page, total28);
    await page.getByRole('button', { name: 'Dividir el pago' }).click();
    await amountInputs(page).nth(1).fill('8');
    await expect(amountInputs(page).nth(0)).toHaveValue('20');
    await expect(page.locator('#co-received')).toHaveValue('20');
    await expect(page.locator('.co-result.short, .co-result.cash-short')).toHaveCount(0);
    await expect(page.locator('.co-btn-primary')).toBeEnabled();
    await expectFits(page);
  });

  test('11. Inverso: Efectivo = 20 → QR pasa a 8', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openCheckout(page, total28);
    await page.getByRole('button', { name: 'Dividir el pago' }).click();
    await amountInputs(page).nth(0).fill('20');
    await expect(amountInputs(page).nth(1)).toHaveValue('8');
    await expect(page.locator('.co-btn-primary')).toBeEnabled();
  });

  test('12. Recibe mayor a lo que toca en efectivo: vuelto correcto (no se suma el QR)', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openCheckout(page, total28);
    await page.getByRole('button', { name: 'Dividir el pago' }).click();
    await amountInputs(page).nth(1).fill('8');
    await page.locator('.co-bill', { hasText: 'Bs 50' }).click();
    await expect(page.locator('.co-result.change .co-result-amount')).toHaveText('Bs 30.00');
  });

  test('13. Decimales: total 38,50 con QR 12,10 → Efectivo 26,4', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openCheckout(page);
    await page.getByRole('button', { name: 'Dividir el pago' }).click();
    await amountInputs(page).nth(1).fill('12.1');
    await expect(amountInputs(page).nth(0)).toHaveValue('26.4');
  });

  test('14. Campo vacío y tope: sin NaN ni negativos', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openCheckout(page, total28);
    await page.getByRole('button', { name: 'Dividir el pago' }).click();
    await amountInputs(page).nth(1).fill('8');
    await amountInputs(page).nth(1).fill('');
    await expect(amountInputs(page).nth(0)).toHaveValue('28');
    await amountInputs(page).nth(1).fill('99');
    await expect(amountInputs(page).nth(1)).toHaveValue('28');
    await expect(amountInputs(page).nth(0)).toHaveValue('0');
  });

  test('15. Tres métodos: se completan solos, caben en la portátil y en escala 125 %', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openCheckout(page, total28);
    await expect(page.locator('html')).toHaveClass(/screen-compact/);
    await page.getByRole('button', { name: 'Dividir el pago' }).click();
    await amountInputs(page).nth(1).fill('8');            // QR 8 → Efectivo 20
    await page.getByRole('button', { name: 'Agregar otro método' }).click();
    await expect(amountInputs(page)).toHaveCount(3);
    await amountInputs(page).nth(2).fill('5');            // Tarjeta 5 → Efectivo (la menos reciente) 15
    await expect(amountInputs(page).nth(0)).toHaveValue('15');
    await expect(amountInputs(page).nth(1)).toHaveValue('8');
    await expect(page.locator('.co-btn-primary')).toBeEnabled();
    await expectFits(page);
    await amountInputs(page).nth(0).fill('10');           // Efectivo 10 → la menos reciente (QR) absorbe: 13
    await expect(amountInputs(page).nth(1)).toHaveValue('13');
    await page.setViewportSize({ width: 1093, height: 489 });
    await expectFits(page, { vertical: false });
  });

  test('16. Quitar una línea deja las sumas correctas', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openCheckout(page, total28);
    await page.getByRole('button', { name: 'Dividir el pago' }).click();
    await amountInputs(page).nth(1).fill('8');
    await page.getByRole('button', { name: 'Agregar otro método' }).click();
    await page.getByRole('button', { name: 'Quitar el pago 3' }).click();
    await expect(amountInputs(page)).toHaveCount(2);
    const vals = await amountInputs(page).evaluateAll((els) => els.map((e) => Number(e.value)));
    expect(vals[0] + vals[1]).toBeCloseTo(28, 2);
    await page.getByRole('button', { name: 'Quitar el pago 2' }).click();
    await expect(page.getByRole('radio', { name: 'Efectivo' })).toBeVisible();
  });

  test('17. La venta se envía con los dos pagos y sin vuelto', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    let sent = null;
    await page.route('**/api/sales/sync', async (route) => {
      sent = route.request().postDataJSON();
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ synced: [], results: [], data: [] }) });
    });
    await openCheckout(page, total28);
    await page.getByRole('button', { name: 'Dividir el pago' }).click();
    await amountInputs(page).nth(1).fill('8');
    await page.locator('.co-btn-primary').click();
    await expect(page.locator('.co-card')).toBeHidden();
    await expect.poll(() => sent, { timeout: 8000 }).not.toBeNull();
    const sale = (sent.sales || [])[0];
    const pays = sale.payments.map((p) => [p.method, Number(p.amount)]).sort();
    expect(pays).toEqual([['cash', 20], ['qr', 8]]);
  });
});
