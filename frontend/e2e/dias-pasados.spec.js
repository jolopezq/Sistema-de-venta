import { test, expect } from '@playwright/test';

/**
 * Control diario, ventas de días pasados (docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md, Fase 3):
 * día histórico, N.º de comanda repetido, caja a nombre del cajero, inicio del sistema y ventas sin sincronizar.
 */

const json = (body, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(body) });
const ME = {
  user: { id: 1, name: 'Dueño', email: 'dueno@ohana.test', role: 'super_admin' }, permissions: [], effective_permissions: {},
};
const CATALOG = {
  categories: [{ id: 1, name: 'Café', sort_order: 1 }], customers: [], ingredients: [],
  products: [{ id: 2, name: 'Café', price: 12, delivery_price: 14, category_id: 1, is_active: true, option_groups: [] }],
};
const SELLERS = [{ id: 1, name: 'Dueño', role: 'super_admin' }, { id: 4, name: 'Cajera Ana', role: 'cashier' }];

async function setup(page, { historical = false, settled = 0, sales = [] } = {}) {
  const calls = [];
  await page.setViewportSize({ width: 1366, height: 611 });
  await page.addInitScript(() => localStorage.setItem('auth_token', 'mock'));
  await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
  await page.route('**/api/me', (r) => r.fulfill(json(ME)));
  await page.route('**/api/catalog', (r) => r.fulfill(json(CATALOG)));
  await page.route('**/api/users/sellers', (r) => r.fulfill(json({ data: SELLERS })));
  await page.route('**/api/cash-sessions/active', (r) => r.fulfill(json({ session: null, stats: null })));
  await page.route((url) => url.pathname === '/api/cash-sessions', (r) => {
    if (r.request().method() === 'POST') {
      calls.push({ open: JSON.parse(r.request().postData()) });
      return r.fulfill(json({ session: { id: 30, status: 'open', opening_amount: 100, opened_at: '2026-10-20T00:00:00-04:00' }, stats: {} }, 201));
    }
    return r.fulfill(json({ data: [] }));
  });
  await page.route((url) => url.pathname === '/api/sales', (r) => r.fulfill(json({ sales: { data: sales, current_page: 1, last_page: 1, total: sales.length } })));
  await page.route('**/api/admin/sales/day-info*', (r) => {
    const date = new URL(r.request().url()).searchParams.get('date');
    return r.fulfill(json({ date, system_start_date: '2026-10-01', start_configured: false, historical, settled_count: settled }));
  });
  await page.route('**/api/admin/settings/system-start-date', (r) => {
    calls.push({ start: JSON.parse(r.request().postData()) });
    return r.fulfill(json({ system_start_date: '2026-09-01', start_configured: true }));
  });
  await page.route('**/api/admin/sales', (r) => {
    if (r.request().method() !== 'POST') return r.fulfill(json({ data: [] }));
    calls.push({ sale: JSON.parse(r.request().postData()) });
    return r.fulfill(json({ sale: { id: 'n1', order_number: '200926-0009', created_at: '2026-09-20T12:00:00-04:00' } }, 201));
  });
  await page.goto('/admin/ventas-retroactivas');
  await page.waitForSelector('.product-picker-card');
  return calls;
}

async function pickDate(page, date) {
  await page.locator('#cd-date-input').fill(date);
  await page.locator('#cd-date-input').dispatchEvent('change');
}

test('Día histórico: Control diario y el editor avisan que no descuenta stock ni suma puntos', async ({ page }) => {
  await setup(page, { historical: true });
  await pickDate(page, '2026-09-20');
  await expect(page.locator('.cd-day-notice.is-historical')).toContainText('Día histórico: es anterior al inicio del sistema (01/10/2026).');
  await expect(page.locator('.audit-warning-banner--compact')).toHaveText(/Venta histórica \(antes del inicio del sistema, 01\/10\/2026\): no descuenta stock ni suma puntos/);
});

test('Insumos contados después: el editor dice cuántos no se descuentan', async ({ page }) => {
  await setup(page, { settled: 2 });
  await pickDate(page, '2026-10-15');
  await expect(page.locator('.audit-warning-banner--compact')).toContainText('2 insumos se contaron después de este día: esos no se descuentan; el resto sí.');
  await expect(page.locator('.cd-day-notice.is-historical')).toHaveCount(0);
});

test('N.º de comanda: avisa si ya está cargada ese día, se envía y sugiere el siguiente', async ({ page }) => {
  const calls = await setup(page, {
    sales: [{ id: 's1', order_number: '201026-0003', paper_ref: '045', status: 'completed', created_at: '2026-10-20T11:00:00', total_amount: 12, payments: [], items: [] }],
  });
  await pickDate(page, '2026-10-20');
  const ref = page.locator('#cd-f-comanda');
  await ref.fill('045');
  await expect(page.locator('.cd-paper-dup')).toContainText('La comanda N.º 045 ya está cargada ese día (Ticket #201026-0003).');
  await ref.fill('046');
  await expect(page.locator('.cd-paper-dup')).toHaveCount(0);

  await page.locator('.product-picker-card', { hasText: 'Café' }).first().click();
  await page.locator('.cd-reason-chip', { hasText: 'Talonario manual' }).click();
  await page.locator('.cd-bar-save').click();
  await expect.poll(() => calls.filter((c) => c.sale).length).toBe(1);
  expect(calls.find((c) => c.sale).sale.paper_ref).toBe('046');
  await expect(ref).toHaveValue('047');
});

test('Abrir la caja de un día pasado a nombre de la cajera', async ({ page }) => {
  const calls = await setup(page);
  await pickDate(page, '2026-10-20');
  await page.locator('#cd-open-for').selectOption({ label: 'Cajera Ana (Cajero)' });
  await page.locator('.btn-open-historical').click();
  await expect.poll(() => calls.find((c) => c.open)?.open).toEqual({ opening_amount: 0, date: '2026-10-20', cashier_id: 4 });
});

test('El Super Admin ve y cambia la fecha de inicio del sistema', async ({ page }) => {
  const calls = await setup(page);
  await expect(page.locator('.cd-start-line')).toHaveCount(0); // hoy no se muestra
  await pickDate(page, '2026-10-20');
  const line = page.locator('.cd-start-line');
  await expect(line).toContainText('Inicio del sistema: 01/10/2026 (primera venta del POS).');
  await line.getByRole('button', { name: 'Cambiar' }).click();
  await page.locator('#cd-start-date').fill('2026-09-01');
  await line.getByRole('button', { name: 'Guardar' }).click();
  await expect.poll(() => calls.find((c) => c.start)?.start).toEqual({ date: '2026-09-01' });
});

test('Avisa si este dispositivo tiene ventas de ese día sin sincronizar', async ({ page }) => {
  await setup(page);
  await page.evaluate(() => new Promise((resolve, reject) => {
    const req = indexedDB.open('OhanaAcaiDB');
    req.onerror = () => reject(req.error);
    req.onsuccess = () => {
      const tx = req.result.transaction('sales', 'readwrite');
      tx.objectStore('sales').put({ id: 'off-1', created_at: '2026-10-18T15:00:00-04:00', sync_status: 'pending', items: [], payments: [] });
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    };
  }));
  await pickDate(page, '2026-10-18');
  await expect(page.locator('.cd-day-notice.is-warning')).toContainText('Este dispositivo tiene 1 venta de este día sin sincronizar.');
  await pickDate(page, '2026-10-19');
  await expect(page.locator('.cd-day-notice.is-warning')).toHaveCount(0);
});
