import { test, expect } from '@playwright/test';

/**
 * Cerrar caja (asistente de arqueo) en la portátil de caja (1366 × 768, viewport útil 1366 × 611).
 * Garantía: los pasos «Conteo» y «Resultado» entran sin scroll, y el conteo se escribe solo con el teclado.
 * Plan: docs/PLAN_CIERRE_CAJA_CONTEO_DIRECTO.md
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

const ACTIVE = {
  session: {
    id: 1, status: 'open', opening_amount: 200, opened_at: new Date().toISOString(),
    cashier: { name: 'Cajero Prueba' }, user: { name: 'Cajero Prueba' },
  },
  stats: {
    cash_sales: 540, cash_extra_income: 0, cash_expenses: 40, non_cash_expenses: 0,
    expected_cash: 700, total_sales: 800, qr_sales: 120, card_sales: 140, sales_count: 20,
  },
  sales_session_id: 1,
};

const json = (body, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(body) });

async function setupMockApi(page, { onClose } = {}) {
  await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
  await page.route('**/api/me', (r) => r.fulfill(json(ME)));
  await page.route('**/api/cash-sessions/active', (r) => r.fulfill(json(ACTIVE)));
  await page.route('**/api/inventory/reorder-summary', (r) => r.fulfill(json({ count: 0, items: [] })));
  await page.route('**/api/catalog', (r) => r.fulfill(json({ categories: [], products: [], customers: [], ingredients: [] })));
  await page.route('**/api/cash-sessions/1/close', async (r) => {
    if (onClose) onClose(r.request().postDataJSON());
    await r.fulfill(json({ session: { ...ACTIVE.session, status: 'closed' }, report: {} }));
  });
  await page.addInitScript(() => localStorage.setItem('auth_token', 'mock_cashier_jwt'));
}

async function openWizard(page) {
  await page.goto('/turno');
  await page.getByRole('button', { name: /Cerrar caja/ }).first().click();
  await page.waitForSelector('.count-board', { timeout: 15000 });
}

async function measure(page) {
  return page.evaluate(() => {
    const b = document.querySelector('.wizard-body');
    const m = document.querySelector('.wizard-modal');
    const f = document.querySelector('.wizard-footer').getBoundingClientRect();
    return {
      vy: b.scrollHeight - b.clientHeight,
      hx: b.scrollWidth - b.clientWidth,
      mx: m.scrollWidth - m.clientWidth,
      footBottom: f.bottom,
      vh: window.innerHeight,
    };
  });
}

async function expectFits(page, { vertical = true } = {}) {
  const m = await measure(page);
  expect(m.hx, 'scroll horizontal en el cuerpo').toBeLessThanOrEqual(1);
  expect(m.mx, 'scroll horizontal en el modal').toBeLessThanOrEqual(1);
  if (vertical) expect(m.vy, 'scroll vertical en el cuerpo').toBeLessThanOrEqual(1);
  expect(m.footBottom, 'el pie debe estar dentro del viewport').toBeLessThanOrEqual(m.vh + 0.5);
}

const FIT_VIEWPORTS = [
  { width: 1366, height: 611, desc: 'Chrome con favoritos (peor caso)' },
  { width: 1366, height: 641, desc: 'Chrome maximizado' },
  { width: 1366, height: 697, desc: 'App instalada' },
  { width: 1366, height: 768, desc: 'F11' },
  { width: 1280, height: 720, desc: '1280 × 720' },
  { width: 1093, height: 489, desc: 'Windows al 125 %' },
];

const counts = (page) => page.locator('.count-input');

test.describe('Cerrar caja — portátil de caja', () => {
  test.beforeEach(async ({ page }) => { await setupMockApi(page); });

  for (const vp of FIT_VIEWPORTS) {
    test(`1. Conteo entra completo en ${vp.width} × ${vp.height} (${vp.desc})`, async ({ page }) => {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await openWizard(page);
      await expectFits(page);
    });

    test(`2. Resultado con faltante y nota entra completo en ${vp.width} × ${vp.height} (${vp.desc})`, async ({ page }) => {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await openWizard(page);
      await counts(page).first().fill('3'); // 3 × 200 = 600 < 700 esperado → faltante
      await page.getByRole('button', { name: 'Ver el resultado' }).click();
      await expect(page.locator('.diff-banner.faltante')).toBeVisible();
      await expectFits(page);
    });
  }

  test('3. Las 9 denominaciones, el total y los botones se ven a la vez en 1366 × 611', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openWizard(page);
    await expect(counts(page)).toHaveCount(9);
    for (let i = 0; i < 9; i++) await expect(counts(page).nth(i)).toBeInViewport({ ratio: 1 });
    await expect(page.locator('.footer-total')).toBeInViewport({ ratio: 1 });
    await expect(page.getByRole('button', { name: 'Ver el resultado' })).toBeInViewport({ ratio: 1 });
  });

  test('4. Teclado: foco inicial, Enter avanza, Shift+Enter vuelve, la última enfoca el botón', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openWizard(page);
    await expect(counts(page).nth(0)).toBeFocused();
    await page.keyboard.type('3');
    await page.keyboard.press('Enter');
    await expect(counts(page).nth(1)).toBeFocused();
    await page.keyboard.press('Shift+Enter');
    await expect(counts(page).nth(0)).toBeFocused();
    await expect(counts(page).nth(0)).toHaveValue('3');
    await page.keyboard.press('ArrowDown');
    await expect(counts(page).nth(1)).toBeFocused();
    await page.keyboard.press('ArrowUp');
    await expect(counts(page).nth(0)).toBeFocused();
    await counts(page).nth(8).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('button', { name: 'Ver el resultado' })).toBeFocused();
  });

  test('5. Tab recorre solo casillas (sin pasar por − / +)', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openWizard(page);
    await counts(page).nth(0).focus();
    await page.keyboard.press('Tab');
    await expect(counts(page).nth(1)).toBeFocused();
  });

  test('6. Entrada saneada: sin signos, decimales ni letras; máximo 4 cifras', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openWizard(page);
    const first = counts(page).nth(0);
    await first.focus();
    await page.keyboard.type('-3');
    await expect(first).toHaveValue('3');
    await first.fill('');
    await page.keyboard.type('2.5e');
    await expect(first).toHaveValue('25');
    await first.fill('');
    await page.keyboard.type('123456');
    await expect(first).toHaveValue('1234');
    await first.fill('');
    await expect(page.locator('.footer-total')).toContainText('Bs 0.00');
  });

  test('7. Aritmética: 3×200 + 5×50 + 2×0,50 = Bs 851,00 en el pie y en el resultado', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openWizard(page);
    await counts(page).nth(0).fill('3');
    await counts(page).nth(2).fill('5');
    await counts(page).nth(8).fill('2');
    await expect(page.locator('.footer-total')).toContainText('Bs 851.00');
    await expect(page.locator('.footer-total')).toContainText('10 piezas');
    await page.keyboard.press('Control+Enter');
    await expect(page.locator('.diff-banner.sobrante')).toBeVisible();
    await expect(page.locator('.card-box.highlight')).toContainText('Bs 851.00');
  });

  test('8. Borrador: recargar conserva el conteo y un borrador inválido se sanea', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await page.addInitScript(() => {
      localStorage.setItem('cashClose_inProgress', JSON.stringify({
        sessionId: 1, step: 1, billCounts: { 200: -3, 100: 2.5, 50: 4 }, cashLeftForNext: 0,
      }));
    });
    await page.goto('/turno');
    await page.waitForSelector('.count-board', { timeout: 15000 });
    await expect(counts(page).nth(0)).toHaveValue('3');
    await expect(counts(page).nth(1)).toHaveValue('25');
    await expect(counts(page).nth(2)).toHaveValue('4');
  });

  test('9. Resultado: foco en el motivo con faltante; fondo con atajos y Enter no cierra', async ({ page }) => {
    let sent = null;
    await page.unroute('**/api/cash-sessions/1/close');
    await page.route('**/api/cash-sessions/1/close', async (r) => {
      sent = r.request().postDataJSON();
      await r.fulfill(json({ session: { ...ACTIVE.session, status: 'closed' }, report: {} }));
    });
    await page.setViewportSize({ width: 1366, height: 611 });
    await openWizard(page);
    await counts(page).nth(0).fill('3');
    await page.keyboard.press('Control+Enter');
    await expect(page.locator('.diff-textarea')).toBeFocused();
    await page.keyboard.type('Vuelto mal dado');
    await page.getByRole('button', { name: /Como al abrir/ }).click();
    await expect(page.locator('#cash-left-input')).toHaveValue('200');
    await page.locator('#cash-left-input').focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('.wizard-footer .btn-primary')).toBeFocused();
    expect(sent).toBeNull();
    await page.keyboard.press('Enter');
    await expect.poll(() => sent, { timeout: 8000 }).not.toBeNull();
    expect(sent.actual_closing).toBe(600);
    expect(sent.cash_left_for_next).toBe(200);
    expect(sent.cash_withdrawn).toBe(400);
    expect(sent.bill_breakdown['200']).toBe(3);
  });

  test('10. Tema oscuro: entra completo', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openWizard(page);
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    await counts(page).nth(0).fill('3');
    await expectFits(page);
    await page.keyboard.press('Control+Enter');
    await expectFits(page);
  });

  test('11. Teléfono 390 × 844: sin scroll horizontal, total y botón a la vista', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openWizard(page);
    await expectFits(page, { vertical: false });
    await expect(page.locator('.footer-total')).toBeInViewport();
    await expect(page.getByRole('button', { name: 'Ver el resultado' })).toBeInViewport();
  });
});

test.describe('Cerrar caja — desde el menú', () => {
  test.beforeEach(async ({ page }) => { await setupMockApi(page); await page.setViewportSize({ width: 1366, height: 611 }); });

  test('12. Menú: el indicador «Turno abierto» lleva directo al cierre', async ({ page }) => {
    await page.goto('/pos');
    const chip = page.locator('.oh-turno-chip');
    await expect(chip).toBeVisible({ timeout: 15000 });
    await expect(chip).toContainText('Turno abierto');
    await chip.click();
    await page.waitForSelector('.count-board', { timeout: 15000 });
    await expect(page).toHaveURL(/\/turno$/);
  });
});
