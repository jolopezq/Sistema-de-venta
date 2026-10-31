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

/* ───────── Reabrir para corregir (docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md, Fase 4) ───────── */

const ADMIN_NO_CORRECTIONS = {
  user: { id: 5, name: 'Rox', email: 'rox@ohana.test', role: 'admin' }, permissions: [],
  effective_permissions: { pos: 'edit', register_self: 'edit', register_others: 'edit', sales_history: 'edit', register_corrections: 'none', finance: 'none' },
};

async function setupReopen(page, { me = ME } = {}) {
  const calls = [];
  let reopened = false;
  const session = () => (reopened
    ? { ...SESSION, status: 'open', reopened_at: new Date().toISOString(), bill_breakdown: { 100: 1, 10: 3 } }
    : SESSION);
  await page.setViewportSize({ width: 1366, height: 611 });
  await page.addInitScript(() => localStorage.setItem('auth_token', 'mock'));
  await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
  await page.route('**/api/me', (r) => r.fulfill(json(me)));
  await page.route('**/api/catalog', (r) => r.fulfill(json(CATALOG)));
  await page.route((url) => url.pathname === '/api/cash-sessions', (r) => r.fulfill(json({ data: [session()] })));
  await page.route('**/api/cash-sessions/active', (r) => r.fulfill(json({ session: null, stats: null })));
  await page.route('**/api/cash-sessions/9', (r) => r.fulfill(json({ session: session(), stats: SUMMARY })));
  await page.route('**/api/cash-sessions/9/report', (r) => r.fulfill(json(report(false))));
  await page.route('**/api/cash-sessions/9/reopen', (r) => {
    calls.push(JSON.parse(r.request().postData()));
    reopened = true;
    return r.fulfill(json({ session: session(), stats: SUMMARY }));
  });
  await page.goto('/admin/ventas-retroactivas');
  await expect(page.locator('.closed-summary-box')).toBeVisible();
  return calls;
}

test('Reabrir para corregir: pide motivo, reabre y la caja queda lista para corregir y volver a cerrar', async ({ page }) => {
  const calls = await setupReopen(page);
  await page.getByRole('button', { name: /Reabrir para corregir/ }).click();
  await page.locator('#cd-reopen-reason').fill('corto');
  await page.getByRole('button', { name: 'Reabrir caja' }).click();
  await expect(page.locator('.cd-reopen-error')).toHaveText('Escribe el motivo (al menos 10 caracteres).');
  expect(calls).toHaveLength(0);

  await page.locator('#cd-reopen-reason').fill('El conteo se copió mal del papel');
  await page.getByRole('button', { name: 'Reabrir caja' }).click();
  await expect.poll(() => calls).toEqual([{ reason: 'El conteo se copió mal del papel' }]);
  await expect(page.locator('.cd-day-notice.is-correcting')).toContainText('Caja reabierta para corregir.');
  await expect(page.getByRole('button', { name: /Corregir y volver a cerrar/ })).toBeVisible();
});

test('Sin el permiso «Corregir cajas cerradas» no aparece «Reabrir para corregir»', async ({ page }) => {
  await setupReopen(page, { me: ADMIN_NO_CORRECTIONS });
  await expect(page.getByRole('button', { name: /Ver Ticket Completo de Cierre/ })).toBeVisible();
  await expect(page.getByRole('button', { name: /Reabrir para corregir/ })).toHaveCount(0);
});

test('El ticket de una caja rectificada muestra el cierre anterior, los motivos y el fondo de la caja siguiente', async ({ page }) => {
  await setup(page, false);
  await page.route('**/api/cash-sessions/9/report', (r) => r.fulfill(json({
    ...report(false),
    closings: [{
      closed_at: SESSION.closed_at, expected_closing: 230, actual_closing: 23, difference: -207, cash_left_for_next: 100,
      reopened_by: 'Lourdes', reopened_at: new Date().toISOString(), reopen_reason: 'Se escribió 23 en vez de 230',
      reclosed_by: 'Lourdes', reclosed_at: new Date().toISOString(), reclose_reason: 'Conteo de la hoja firmada',
    }],
    chain: { next_id: 10, next_opened_at: new Date().toISOString(), next_opening_amount: 50, left_for_next: 100, matches: false },
  })));
  await page.reload();
  await page.getByRole('button', { name: /Ver Ticket Completo de Cierre/ }).click();
  const ticket = page.locator('.report-ticket');
  await expect(ticket).toContainText('CAJA RECTIFICADA 1 VEZ');
  await expect(ticket).toContainText('Bs 23.00 / 230.00');
  await expect(ticket).toContainText('por Lourdes: «Se escribió 23 en vez de 230»');
  await expect(ticket).toContainText('Cerrada otra vez por Lourdes: «Conteo de la hoja firmada»');
  await expect(ticket).toContainText('La caja siguiente (#10) abrió con Bs 50.00; este cierre dejó Bs 100.00 para mañana.');
});

test('Corregir y volver a cerrar: el conteo parte del cierre anterior y pide el motivo de la corrección', async ({ page }) => {
  await setupReopen(page);
  let closeBody = null;
  await page.route('**/api/cash-sessions/9/close', (r) => {
    closeBody = JSON.parse(r.request().postData());
    return r.fulfill(json({ session: { ...SESSION, actual_closing: '130.00' }, report: report(false) }));
  });
  await page.getByRole('button', { name: /Reabrir para corregir/ }).click();
  await page.locator('#cd-reopen-reason').fill('El conteo se copió mal del papel');
  await page.getByRole('button', { name: 'Reabrir caja' }).click();
  await page.getByRole('button', { name: /Corregir y volver a cerrar/ }).click();

  await page.waitForSelector('.count-board', { timeout: 15000 });
  // Parte del conteo del cierre anterior (1 × 100 + 3 × 10)
  await expect(page.locator('.wizard-footer')).toContainText('130');
  await page.getByRole('button', { name: 'Ver el resultado' }).click();
  // Teórico de hoy Bs 200 y contado Bs 130: faltante, con su nota
  await page.locator('#diff-note-field').fill('Faltan los 70 del vuelto');
  const reason = page.locator('#correction-reason-field');
  await expect(reason).toBeVisible();
  const closeBtn = page.locator('.wizard-footer .btn-primary');
  await expect(closeBtn).toBeDisabled();
  await reason.fill('Conteo corregido según la hoja firmada');
  await expect(closeBtn).toBeEnabled();
  await closeBtn.click();
  await expect.poll(() => closeBody?.correction_reason).toBe('Conteo corregido según la hoja firmada');
  expect(closeBody.actual_closing).toBe(130);
});
