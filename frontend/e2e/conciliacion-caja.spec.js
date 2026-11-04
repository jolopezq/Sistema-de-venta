import { test, expect } from '@playwright/test';
import { mockFinanzas } from './fixtures/finanzas-api.js';

/**
 * Que el efectivo y el QR cuadren con lo que recibe el dueño (docs/PLAN_CONCILIACION_CAJA.md), con la API simulada
 * y los datos de octubre 2026 de la auditoría.
 */

test.beforeEach(async ({ browserName }, testInfo) => {
  test.skip(testInfo.project.name !== 'Portátil de caja' || browserName !== 'chromium', 'Se prueba en la portátil de caja');
});

const json = (body, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(body) });

const DRAWER = {
  collected: 2868, in_open_sessions: 0, outside_sessions: 0, closed_sales: 2868, drawer_expenses: 171, extra_income: 244,
  differences: 0, fund_start: 242, fund_end: 256, fund_change: 14, fund_jumps: -240, withdrawn: 2687, unexplained: 0,
  closed_count: 24,
  fund_jump_items: [
    { session_id: 13, date: '2026-10-03', cashier: 'Roxana', opening: 0, previous_left: 244, amount: -244, in_total: true },
    { session_id: 25, date: '2026-10-09', cashier: 'Roxana', opening: 253, previous_left: 249, amount: 4, in_total: true },
  ],
  open_sessions: [{ session_id: 1, date: '2026-10-14', cashier: 'Lourdes', cash_sales: 0 }],
  difference_items: [],
  qr_bank: { reported_count: 1, closed_count: 24, system: 60, bank: 30, difference: -30, items: [{ session_id: 11, date: '2026-10-22', cashier: 'Lourdes', system: 60, bank: 30, difference: -30 }] },
};

test.describe('Finanzas: efectivo que debía llegar a la mano', () => {
  test('1. De lo cobrado a lo retirado, con los avisos de la caja', async ({ page }) => {
    await mockFinanzas(page, {
      pnlPatch: (p) => ({
        ...p,
        cash_in: { ...p.cash_in, drawer: DRAWER },
        warnings: [
          { code: 'sales_without_session', level: 'warning', message: '1 venta(s) del local (Bs 260,00) no están en ninguna caja y no entran a ningún arqueo: 17/10 #171026-0005.', count: 1, amount: 260, date: '2026-10-17', action: 'daily_control' },
          { code: 'missing_salaries', level: 'warning', message: 'No hay sueldos cargados en octubre: si alguien trabajó, la utilidad sale más alta de lo real.', action: 'add_expense' },
        ],
      }),
    });
    await page.goto('/finanzas?tab=summary');
    await page.waitForSelector('.fin-statement', { timeout: 15000 });

    const drawer = page.locator('[data-testid="cin-drawer"]');
    await drawer.scrollIntoViewIfNeeded();
    await expect(drawer.locator('summary')).toContainText('Efectivo que te debía llegar a la mano');
    await expect(drawer.locator('summary')).toContainText('Bs 2.687,00');
    await drawer.locator('summary').click();

    const row = (key) => drawer.locator(`tr[data-row="${key}"]`);
    await expect(row('collected')).toContainText('Bs 2.868,00');
    await expect(row('expenses')).toContainText('−Bs 171,00');
    await expect(row('income')).toContainText('+Bs 244,00');
    await expect(row('fund')).toContainText('Bs 242,00 al empezar → Bs 256,00 al terminar');
    await expect(row('fund')).toContainText('−Bs 14,00');
    await expect(row('jumps')).toContainText('03/10 −Bs 244,00 y 09/10 +Bs 4,00');
    await expect(row('withdrawn')).toContainText('Bs 2.687,00');
    await expect(drawer.locator('.cin-notes li')).toHaveText(['La caja 14/10 (Lourdes) sigue abierta: ciérrala en Control diario.']);
    await expect(page.locator('.cin > .cin-notes li').first()).toHaveText(
      'QR comparado con la app del banco en 1 de 24 cierres: faltan Bs 30,00 en el banco (22/10 −Bs 30,00).',
    );

    // El aviso de la venta sin caja lleva a Control diario en ese día
    await page.getByRole('button', { name: 'Ir a Control diario' }).click();
    await expect(page).toHaveURL(/\/admin\/ventas-retroactivas\?fecha=2026-10-17/);
  });
});

const CASHIER = {
  user: { id: 7, name: 'Lourdes', email: 'lourdes@example.com', role: 'cashier' },
  permissions: [],
  effective_permissions: {
    pos: 'edit', register_self: 'edit', delivery_ops: 'edit', crm_read: 'read', voids: 'none', register_others: 'none',
    catalog: 'none', inventory: 'none', crm_config: 'none', reports: 'none', users: 'none', audit: 'none',
  },
};

async function cashierApi(page, active) {
  await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
  await page.route('**/api/me', (r) => r.fulfill(json(CASHIER)));
  await page.route('**/api/inventory/reorder-summary', (r) => r.fulfill(json({ count: 0, items: [] })));
  await page.route('**/api/catalog', (r) => r.fulfill(json({ categories: [], products: [], customers: [], ingredients: [] })));
  await page.route('**/api/cash-sessions/active', (r) => r.fulfill(json(active)));
  await page.addInitScript(() => localStorage.setItem('auth_token', 'mock_cashier_jwt'));
}

test.describe('Caja: fondo con motivo, cajas sin cerrar y QR del banco', () => {
  test('2. Abrir con un fondo distinto al que dejó la caja anterior pide el motivo', async ({ page }) => {
    await cashierApi(page, { session: null, stats: null, sales_session_id: null });
    await page.route('**/api/cash-sessions/opening-info*', (r) => r.fulfill(json({
      date: '2026-11-04', suggested_opening: 249,
      previous: { session_id: 24, date: '2026-11-03', cashier: 'Lourdes', left_for_next: 249 },
      unclosed: [{ session_id: 16, date: '2026-10-27', cashier: 'Lourdes' }],
    })));
    let body = null;
    await page.route('**/api/cash-sessions', async (r) => {
      if (r.request().method() !== 'POST') return r.fallback();
      body = r.request().postDataJSON();
      return r.fulfill(json({ session: { id: 30, status: 'open', opening_amount: body.opening_amount, opened_at: new Date().toISOString() }, stats: { expected_cash: body.opening_amount }, unclosed: [] }, 201));
    });

    await page.goto('/turno');
    await page.getByRole('button', { name: 'Abrir caja' }).first().click();
    const modal = page.locator('.fin-modal').filter({ hasText: 'Abrir caja' });
    await expect(page.locator('#tn-opening')).toHaveValue('249');
    await expect(modal).toContainText('La caja del 03/11 (Lourdes) dejó Bs 249,00 para el día siguiente.');
    await expect(page.locator('[data-testid="unclosed-sessions"]')).toContainText('27/10 · Lourdes');
    await expect(page.locator('[data-testid="unclosed-sessions"]')).toContainText('Pide a un administrador que la cierre en Control diario.');

    await page.locator('#tn-opening').fill('253');
    await expect(modal).toContainText('Abres con Bs 4,00 más. ¿Por qué?');
    const openBtn = page.getByRole('button', { name: /Abrir caja con/ });
    await expect(openBtn).toBeDisabled();
    await page.locator('#tn-open-note').fill('Encontré monedas en el cajón');
    await expect(openBtn).toBeEnabled();
    await openBtn.click();
    await expect.poll(() => body).not.toBeNull();
    expect(body).toMatchObject({ opening_amount: 253, opening_note: 'Encontré monedas en el cajón' });
  });

  test('3. Al cerrar se anota el QR de la app del banco y se ve la diferencia', async ({ page }) => {
    await cashierApi(page, {
      session: { id: 1, status: 'open', opening_amount: 200, opened_at: new Date().toISOString(), cashier: { name: 'Lourdes' } },
      stats: { cash_sales: 100, cash_extra_income: 0, cash_expenses: 0, non_cash_expenses: 0, expected_cash: 300, total_sales: 220, qr_sales: 120, card_sales: 0, sales_count: 5 },
      sales_session_id: 1,
    });
    let payload = null;
    await page.route('**/api/cash-sessions/1/close', async (r) => {
      payload = r.request().postDataJSON();
      await r.fulfill(json({ session: { id: 1, status: 'closed' }, report: {} }));
    });

    await page.goto('/turno');
    await page.getByRole('button', { name: /Cerrar caja/ }).first().click();
    await page.waitForSelector('.count-board', { timeout: 15000 });
    await page.locator('.count-input').first().fill('1');   // 1 × Bs 200: faltan 100, pide el motivo
    await page.getByRole('button', { name: 'Ver el resultado' }).click();

    const qr = page.locator('[data-testid="qr-bank"]');
    await expect(qr).toContainText('Sistema: Bs 120.00');
    await page.locator('#qr-bank-field').fill('90');
    await expect(qr).toContainText('Faltan Bs 30.00 en el banco');
    await page.locator('#qr-bank-field').fill('120');
    await expect(qr).toContainText('Coincide con el sistema');
    await page.locator('#qr-bank-field').fill('90');

    await page.locator('#diff-note-field').fill('Conteo de prueba');
    await page.locator('.wizard-footer').getByRole('button', { name: 'Cerrar caja', exact: true }).click();
    await expect.poll(() => payload).not.toBeNull();
    expect(payload.qr_bank_reported).toBe(90);
  });
});
