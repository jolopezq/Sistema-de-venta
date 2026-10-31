import { test, expect } from '@playwright/test';

/**
 * Caja cerrada que cambió después del cierre (venta editada, anulada o sincronizada tarde).
 * Control diario y el ticket de cierre muestran el cierre guardado tal cual y, aparte, el teórico y la
 * diferencia con las cifras de hoy, con los cambios auditados.
 */

const json = (body) => ({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
const ME = {
  user: { id: 1, name: 'Dueño Prueba', email: 'dueno@example.com', role: 'super_admin' }, permissions: [],
  effective_permissions: { pos: 'edit', register_self: 'edit', delivery_ops: 'edit', crm_read: 'edit', voids: 'edit', register_others: 'edit', catalog: 'edit', inventory: 'edit', crm_config: 'edit', reports: 'edit', users: 'edit', audit: 'edit' },
};
const CATALOG = { categories: [{ id: 1, name: 'Café', sort_order: 1 }], customers: [], ingredients: [],
  products: [{ id: 2, name: 'Café', price: 12, category_id: 1, is_active: true, option_groups: [] }] };

const SESSION = {
  id: 9, status: 'closed', cashier_id: 1, cashier: { id: 1, name: 'Dueño Prueba' },
  opening_amount: '100.00', expected_closing: '230.00', actual_closing: '230.00', difference: '0.00',
  cash_withdrawn: '130.00', cash_left_for_next: '100.00', total_expenses: '0.00',
  opened_at: new Date().toISOString(), closed_at: new Date().toISOString(),
};
const SUMMARY = { sales_count: 3, voided_count: 0, total_sales: 130, cash_sales: 100, qr_sales: 30, card_sales: 0, pedidosya_count: 0, pedidosya_total: 0, opening_amount: 100, total_expenses: 0, cash_expenses: 0, non_cash_expenses: 0, extra_income: 0, cash_extra_income: 0, expected_cash: 200 };
const report = (changed) => ({
  session: SESSION, cashier_name: 'Dueño Prueba', opened_at: SESSION.opened_at, closed_at: SESSION.closed_at, duration: '8h 0m',
  opening_amount: 100, expected_closing: 230, actual_closing: 230, difference: 0, total_expenses: 0,
  cash_withdrawn: 130, cash_left_for_next: 100, diff_note: null, bill_breakdown: null, movements: [],
  summary: changed ? SUMMARY : { ...SUMMARY, cash_sales: 130, qr_sales: 0, expected_cash: 230 },
  recalculated: {
    changed, expected_closing: changed ? 200 : 230, difference: changed ? 30 : 0, total_expenses: 0,
    changes: changed ? [{ at: new Date().toISOString(), user: 'Admin Operativo', description: 'Edición de venta Ticket #311026-0004 (Bs 30.00)' }] : [],
  },
});

async function setup(page, changed) {
  await page.setViewportSize({ width: 1366, height: 611 });
  await page.addInitScript(() => localStorage.setItem('auth_token', 'mock'));
  await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
  await page.route('**/api/me', (r) => r.fulfill(json(ME)));
  await page.route('**/api/catalog', (r) => r.fulfill(json(CATALOG)));
  await page.route((url) => url.pathname === '/api/cash-sessions', (r) => r.fulfill(json({ data: [SESSION] })));
  await page.route('**/api/cash-sessions/active', (r) => r.fulfill(json({ session: null, stats: null })));
  await page.route('**/api/cash-sessions/9', (r) => r.fulfill(json({ session: SESSION, stats: SUMMARY })));
  await page.route('**/api/cash-sessions/9/report', (r) => r.fulfill(json(report(changed))));
  await page.goto('/admin/ventas-retroactivas');
  await expect(page.locator('.closed-summary-box')).toBeVisible();
}

test('Caja cerrada que cambió: Control diario lo avisa y el ticket muestra el cierre guardado y el de hoy', async ({ page }) => {
  await setup(page, true);
  const notice = page.locator('.closed-changed');
  await expect(notice).toBeVisible();
  await expect(notice).toContainText('Cambió después del cierre.');
  await expect(notice).toContainText('el efectivo teórico es Bs 200.00 (al cierre: Bs 230.00)');
  await expect(notice).toContainText('la diferencia sería +Bs 30.00');

  await page.getByRole('button', { name: /Ver Ticket Completo de Cierre/ }).click();
  const ticket = page.locator('.report-ticket');
  await expect(ticket).toContainText('(=) Efectivo Teórico hoy:');
  await expect(ticket.locator('.row', { hasText: 'Efectivo Teórico hoy' })).toContainText('Bs 200.00');
  await expect(ticket.locator('.row', { hasText: 'Teórico al cierre' })).toContainText('Bs 230.00');
  await expect(ticket.locator('.row', { hasText: 'DIF. AL CIERRE' })).toContainText('Bs 0.00 (Exacto)');
  await expect(ticket.locator('.row', { hasText: 'Diferencia hoy' })).toContainText('+Bs 30.00 (Sobrante)');
  await expect(ticket).toContainText('CAMBIOS DESPUÉS DEL CIERRE');
  await expect(ticket).toContainText('Admin Operativo');
  await expect(ticket).toContainText('Edición de venta Ticket #311026-0004 (Bs 30.00)');
});

test('Caja cerrada sin cambios: ni aviso ni filas de «hoy»', async ({ page }) => {
  await setup(page, false);
  await expect(page.locator('.closed-changed')).toHaveCount(0);
  await page.getByRole('button', { name: /Ver Ticket Completo de Cierre/ }).click();
  const ticket = page.locator('.report-ticket');
  await expect(ticket.locator('.row', { hasText: '(=) Efectivo Teórico:' })).toContainText('Bs 230.00');
  await expect(ticket).not.toContainText('Diferencia hoy');
  await expect(ticket).not.toContainText('CAMBIOS DESPUÉS DEL CIERRE');
});
