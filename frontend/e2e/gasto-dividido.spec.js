import { test, expect } from '@playwright/test';

/**
 * Gasto de caja dividido entre efectivo de la gaveta y QR o banco (docs/PLAN_GASTO_PAGO_DIVIDIDO.md §3.4 y §5).
 * - Registrar: «Dividir pago (efectivo + QR)», total, parte en efectivo y el QR que se calcula solo.
 * - El aviso «no alcanza» mira solo la parte en efectivo; un ingreso no se divide.
 * - Turno y Control diario muestran «Efectivo + QR» con el desglose.
 * - Corregir un gasto dividido (también en una caja cerrada).
 * Todo a 1366 × 611 y 1093 × 489, en claro y oscuro.
 */

const ME_CASHIER = {
  user: { id: 7, name: 'Cajero Prueba', email: 'caja@example.com', role: 'cashier' },
  permissions: [],
  effective_permissions: {
    pos: 'edit', register_self: 'edit', delivery_ops: 'edit', crm_read: 'read',
    voids: 'none', register_others: 'none', catalog: 'none', inventory: 'none',
    crm_config: 'none', reports: 'none', users: 'none', audit: 'none',
  },
};

const ME_ADMIN = {
  user: { id: 1, name: 'Admin Prueba', email: 'admin@example.com', role: 'admin' },
  permissions: ['sales_history:edit', 'register_corrections:edit'],
  effective_permissions: {
    pos: 'edit', register_self: 'edit', delivery_ops: 'edit', crm_read: 'read',
    voids: 'edit', register_others: 'edit', catalog: 'edit', inventory: 'edit',
    crm_config: 'edit', reports: 'read', users: 'none', audit: 'none',
    sales_history: 'edit', register_corrections: 'edit',
  },
};

const SPLIT = {
  id: 301, cash_register_session_id: 1, type: 'expense', payment_method: 'mixed', amount: 100, cash_amount: '40.00',
  description: 'Compra de frutas en el mercado', occurred_at: '2026-11-02T10:30:00Z', created_at: '2026-11-02T10:30:00Z',
  user: { id: 7, name: 'Cajero Prueba' }, expense_category: null, edit_count: 0, deleted_at: null,
};

const STATS = {
  cash_sales: 300, cash_expenses: 40, non_cash_expenses: 60, expected_cash: 460,
  total_sales: 500, qr_sales: 200, card_sales: 0, sales_count: 5,
};

const json = (body, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(body) });

async function useTheme(page, theme) {
  await page.addInitScript((t) => localStorage.setItem('theme', t), theme);
}

/** Turno con caja abierta (gaveta: Bs 460) y un gasto dividido 40 + 60. Devuelve los cuerpos enviados. */
async function setupTurno(page) {
  const posted = [];
  const updated = [];
  await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
  await page.route('**/api/me', (r) => r.fulfill(json(ME_CASHIER)));
  await page.route('**/api/cash-sessions/active', (r) => r.fulfill(json({
    session: { id: 1, status: 'open', opening_amount: 200, opened_at: '2026-11-02T08:00:00Z', cashier: { name: 'Cajero Prueba' } },
    stats: STATS,
    sales_session_id: 1,
  })));
  await page.route('**/api/cash-sessions/1/movements', (r) => {
    if (r.request().method() === 'POST') {
      posted.push(JSON.parse(r.request().postData() || '{}'));
      return r.fulfill(json({ movement: {}, stats: STATS }, 201));
    }
    return r.fulfill(json([SPLIT]));
  });
  await page.route('**/api/cash-sessions/1/movements/301', (r) => {
    updated.push(JSON.parse(r.request().postData() || '{}'));
    return r.fulfill(json({ movement: {}, stats: STATS }));
  });
  await page.route('**/api/sales**', (r) => r.fulfill(json({
    sales: { data: [], current_page: 1, last_page: 1, total: 0, per_page: 15 },
    summary: { total_sales: 0, total_discounts: 0, sales_count: 0, voided_count: 0 },
  })));
  await page.route('**/api/cash-sessions/1/sales**', (r) => r.fulfill(json({ data: [], current_page: 1, last_page: 1, total: 0 })));
  await page.route('**/api/expense-categories**', (r) => r.fulfill(json([{ id: 1, name: 'Varios', section: 'Gastos' }])));
  await page.route('**/api/catalog', (r) => r.fulfill(json({ categories: [], products: [], customers: [], ingredients: [] })));
  await page.route('**/api/inventory/reorder-summary', (r) => r.fulfill(json({ count: 0, items: [] })));
  await page.addInitScript(() => localStorage.setItem('auth_token', 'mock_cashier_jwt'));
  return { posted, updated };
}

const VIEWPORTS = [{ width: 1366, height: 611 }, { width: 1093, height: 489 }];

for (const vp of VIEWPORTS) {
  for (const theme of ['light', 'dark']) {
    test(`registrar un gasto dividido 40 + 60 (${vp.width} × ${vp.height}, ${theme})`, async ({ page }) => {
      await page.setViewportSize(vp);
      await useTheme(page, theme);
      const { posted } = await setupTurno(page);
      await page.goto('/turno');

      await page.getByRole('button', { name: /Registrar gasto o ingreso/ }).first().click();
      const modal = page.locator('.ce-host');
      await expect(modal).toBeVisible();

      await modal.locator('#ce-amount').fill('100');
      await modal.getByRole('button', { name: 'Dividir pago (efectivo + QR)' }).click();

      // Los botones de método se reemplazan por la línea del pago dividido
      await expect(modal.locator('.ce-split-pill')).toHaveText('Efectivo de la gaveta + QR o banco');
      await expect(modal.getByRole('radio', { name: 'QR o banco' })).toHaveCount(0);
      await expect(modal.locator('label[for="ce-amount"]')).toHaveText('Total del gasto');
      // El campo de efectivo recibe el foco
      await expect(modal.locator('#ce-cash-amount')).toBeFocused();

      await modal.locator('#ce-cash-amount').fill('40');
      await expect(modal.getByTestId('ce-split-qr')).toHaveText('Bs 60.00');
      // La gaveta solo baja la parte en efectivo: 460 − 40
      await expect(modal.locator('.ce-drawer').first()).toContainText('Bs 460.00');
      await expect(modal.locator('.ce-drawer').first()).toContainText('Bs 420.00');

      await modal.locator('#ce-description').fill('Compra de frutas en el mercado');
      const save = modal.getByRole('button', { name: 'Registrar gasto' });
      await expect(save).toBeEnabled();
      await expect(save).toBeInViewport();
      await save.click();

      await expect.poll(() => posted.length).toBe(1);
      expect(posted[0]).toMatchObject({ type: 'expense', payment_method: 'mixed', amount: 100, cash_amount: 40 });
    });
  }
}

test('validaciones del desglose, «no alcanza» solo con el efectivo y «Quitar división»', async ({ page }) => {
  const { posted } = await setupTurno(page);
  await page.goto('/turno');
  await page.getByRole('button', { name: /Registrar gasto o ingreso/ }).first().click();
  const modal = page.locator('.ce-host');
  const save = modal.getByRole('button', { name: 'Registrar gasto' });

  await modal.locator('#ce-amount').fill('100');
  await modal.locator('#ce-description').fill('Compra de frutas en el mercado');
  await modal.getByRole('button', { name: 'Dividir pago (efectivo + QR)' }).click();

  // Sin la parte en efectivo no se puede guardar
  await expect(save).toBeDisabled();
  for (const v of ['0', '100', '120']) {
    await modal.locator('#ce-cash-amount').fill(v);
    await expect(modal.locator('#ce-split-help')).toHaveText('La parte en efectivo tiene que ser mayor que 0 y menor que el total.');
    await expect(save).toBeDisabled();
  }
  await modal.locator('#ce-cash-amount').fill('99.99');
  await expect(modal.getByTestId('ce-split-qr')).toHaveText('Bs 0.01');
  await expect(save).toBeEnabled();

  // Total 600 con 500 de la gaveta (hay 460): no alcanza; con 400 sí, aunque el total pase lo de la gaveta
  await modal.locator('#ce-amount').fill('600');
  await modal.locator('#ce-cash-amount').fill('500');
  await expect(modal.locator('.fin-note.err')).toHaveText('La parte en efectivo es mayor que lo que hay en la gaveta. Revisa el desglose.');
  await expect(save).toBeDisabled();
  await modal.locator('#ce-cash-amount').fill('400');
  await expect(modal.locator('.fin-note.err')).toHaveCount(0);
  await expect(save).toBeEnabled();

  // «Quitar división» vuelve a un solo método (efectivo) y ya no envía la parte en efectivo
  await modal.getByRole('button', { name: 'Quitar división' }).click();
  await expect(modal.getByRole('radio', { name: 'Efectivo de la gaveta' })).toHaveAttribute('aria-checked', 'true');
  await expect(modal.locator('#ce-cash-amount')).toHaveCount(0);
  await modal.getByRole('radio', { name: 'QR o banco' }).click();
  await save.click();
  await expect.poll(() => posted.length).toBe(1);
  expect(posted[0].payment_method).toBe('qr');
  expect(posted[0]).not.toHaveProperty('cash_amount');
});

test('un ingreso no se divide', async ({ page }) => {
  await setupTurno(page);
  await page.goto('/turno');
  await page.getByRole('button', { name: /Registrar gasto o ingreso/ }).first().click();
  const modal = page.locator('.ce-host');
  await modal.getByRole('button', { name: 'Dividir pago (efectivo + QR)' }).click();
  await expect(modal.locator('.ce-split-pill')).toBeVisible();
  // Pasar a ingreso quita la división y el enlace
  await modal.getByRole('radio', { name: 'Ingreso: entra dinero' }).click();
  await expect(modal.locator('.ce-split-pill')).toHaveCount(0);
  await expect(modal.getByRole('button', { name: 'Dividir pago (efectivo + QR)' })).toHaveCount(0);
  await expect(modal.getByRole('radio', { name: 'Efectivo de la gaveta' })).toHaveAttribute('aria-checked', 'true');
});

for (const vp of VIEWPORTS) {
  test(`Turno muestra el desglose y corrige un gasto dividido (${vp.width} × ${vp.height})`, async ({ page }) => {
    await page.setViewportSize(vp);
    const { updated } = await setupTurno(page);
    await page.goto('/turno');

    const row = page.locator('.tn-movements-card tr:has-text("Compra de frutas en el mercado")');
    await expect(row).toContainText('💵📱 Efectivo + QR');
    await expect(row.locator('.tn-method-detail')).toHaveText('Bs 40.00 efectivo + Bs 60.00 QR');

    await row.locator('.tn-act-btn.edit').click();
    const modal = page.locator('.ce-host');
    // Se abre ya dividido
    await expect(modal.locator('.ce-split-pill')).toBeVisible();
    await expect(modal.locator('#ce-cash-amount')).toHaveValue('40');
    await expect(modal.getByTestId('ce-split-qr')).toHaveText('Bs 60.00');
    // La gaveta (460) ya tiene restados los 40: sin cambios no aparece «Quedaría» distinto
    await modal.locator('#ce-reason').fill('Eran 50 en efectivo y 50 por QR');
    const save = modal.getByRole('button', { name: 'Guardar corrección' });
    await expect(save).toBeDisabled();

    // Cambiar solo el desglose cuenta como cambio
    await modal.locator('#ce-cash-amount').fill('50');
    await expect(modal.getByTestId('ce-split-qr')).toHaveText('Bs 50.00');
    await expect(modal.locator('.ce-drawer').first()).toContainText('Bs 450.00');
    await expect(save).toBeEnabled();
    await expect(save).toBeInViewport();
    await save.click();
    await expect.poll(() => updated.length).toBe(1);
    expect(updated[0]).toMatchObject({ payment_method: 'mixed', cash_amount: 50, amount: 100, reason: 'Eran 50 en efectivo y 50 por QR' });
  });
}

test('corregir un gasto dividido a todo en efectivo', async ({ page }) => {
  const { updated } = await setupTurno(page);
  await page.goto('/turno');
  await page.locator('.tn-movements-card tr:has-text("Compra de frutas en el mercado") .tn-act-btn.edit').click();
  const modal = page.locator('.ce-host');
  await modal.getByRole('button', { name: 'Quitar división' }).click();
  // Pasa a efectivo: la gaveta (460, sin los 40) quedaría en 460 + 40 − 100
  await expect(modal.locator('.ce-preview-note')).toContainText('Bs 460.00');
  await expect(modal.locator('.ce-preview-note')).toContainText('Bs 400.00');
  await modal.locator('#ce-reason').fill('Al final se pagó todo en efectivo');
  await modal.getByRole('button', { name: 'Guardar corrección' }).click();
  await expect.poll(() => updated.length).toBe(1);
  expect(updated[0].payment_method).toBe('cash');
  expect(updated[0]).not.toHaveProperty('cash_amount');
});

test('anular un gasto dividido muestra el desglose', async ({ page }) => {
  await setupTurno(page);
  await page.goto('/turno');
  await page.locator('.tn-movements-card tr:has-text("Compra de frutas en el mercado") .tn-act-btn.void').click();
  const summary = page.locator('.vm-host .vm-summary');
  await expect(summary).toContainText('Bs 100.00');
  await expect(summary.locator('.vm-method')).toHaveText('Efectivo + QR (Bs 40.00 efectivo + Bs 60.00 QR)');
});

/** Control diario: caja cerrada en la que el gasto de Bs 74 quedó como QR y el cierre salió con un faltante de Bs 74. */
async function setupClosed(page) {
  const calls = [];
  const CLOSED = {
    id: 2, status: 'closed', opening_amount: 294, opened_at: '2026-11-01T12:00:00Z', closed_at: '2026-11-01T23:00:00Z',
    expected_closing: 396, actual_closing: 322, difference: -74,
  };
  await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
  await page.route('**/api/me', (r) => r.fulfill(json(ME_ADMIN)));
  await page.route('**/api/sales**', (r) => r.fulfill(json({
    sales: { data: [], current_page: 1, last_page: 1, total: 0, per_page: 15 },
    summary: { total_sales: 0, total_discounts: 0, sales_count: 0, voided_count: 0 },
  })));
  await page.route('**/api/admin/sales/day-info**', (r) => r.fulfill(json({ historical: false })));
  await page.route('**/api/users/sellers', (r) => r.fulfill(json({ data: [] })));
  await page.route('**/api/cash-sessions/active', (r) => r.fulfill(json({ session: CLOSED, stats: { total_sales: 102 } })));
  await page.route(/\/api\/cash-sessions\/2$/, (r) => r.fulfill(json({ session: CLOSED, stats: { total_sales: 102, expected_cash: 396 } })));
  await page.route('**/api/cash-sessions/2/report', (r) => r.fulfill(json({ session: CLOSED, summary: {} })));
  await page.route('**/api/cash-sessions/2/movements', (r) => r.fulfill(json([
    {
      id: 201, cash_register_session_id: 2, type: 'expense', payment_method: 'qr', amount: 74,
      description: 'Frutas del mercado', created_at: '2026-11-01T15:00:00Z', user: { name: 'Cajero' },
      deleted_at: null, edit_count: 0,
    },
    {
      id: 202, cash_register_session_id: 2, type: 'expense', payment_method: 'mixed', amount: 100, cash_amount: '40.00',
      description: 'Bolsas y servilletas', created_at: '2026-11-01T16:00:00Z', user: { name: 'Cajero' },
      deleted_at: null, edit_count: 0,
    },
  ])));
  await page.route('**/api/cash-sessions/2/movements/201', (r) => {
    calls.push(JSON.parse(r.request().postData() || '{}'));
    return r.fulfill(json({ message: 'Movimiento corregido y cierre de caja rehecho con el mismo conteo.', movement: {}, stats: {} }));
  });
  await page.route('**/api/catalog', (r) => r.fulfill(json({ categories: [], products: [], customers: [], ingredients: [] })));
  await page.addInitScript(() => localStorage.setItem('auth_token', 'mock_admin_jwt'));
  await page.goto('/admin/ventas-retroactivas');
  await page.locator('button.tab-btn:has-text("Gastos e Insumos")').click();
  return calls;
}

for (const vp of VIEWPORTS) {
  test(`Control diario: desglose en la tabla y caja cerrada QR → dividido (${vp.width} × ${vp.height})`, async ({ page }) => {
    await page.setViewportSize(vp);
    const calls = await setupClosed(page);
    const table = page.locator('.records-content table.cd-table');

    const splitRow = table.locator('tr:has-text("Bolsas y servilletas")');
    await expect(splitRow.locator('.badge-mixed')).toContainText('Efectivo + QR');
    await expect(splitRow.locator('.method-detail')).toHaveText('Bs 40.00 efectivo + Bs 60.00 QR');

    await table.locator('tr:has-text("Frutas del mercado") .btn-table-edit').click();
    const modal = page.locator('.ce-host');
    await expect(modal.locator('.ce-closed-title')).toHaveText('Esta caja ya está cerrada.');
    await modal.getByRole('button', { name: 'Dividir pago (efectivo + QR)' }).click();
    await modal.locator('#ce-cash-amount').fill('40');
    // Salieron 40 de la gaveta: el teórico baja 40 y el faltante pasa de 74 a 34
    await expect(modal.locator('.ce-closed-grid')).toContainText('Bs 396.00 → Bs 356.00');
    await expect(modal.locator('.ce-closed-grid')).toContainText('−Bs 74.00 → −Bs 34.00');

    await modal.locator('#ce-reason').fill('Se pagaron 40 con efectivo de la gaveta');
    const save = modal.getByRole('button', { name: 'Guardar corrección' });
    await expect(save).toBeEnabled();
    await expect(save).toBeInViewport();
    await save.click();
    await expect.poll(() => calls.length).toBe(1);
    expect(calls[0]).toMatchObject({ payment_method: 'mixed', cash_amount: 40, amount: 74 });
  });
}
