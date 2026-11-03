import { test, expect } from '@playwright/test';
import fs from 'fs';
import os from 'os';
import path from 'path';
import { mockFinanzas } from './fixtures/finanzas-api.js';

/**
 * Importación por carpeta de PedidosYa: todos los avisos a la vista, la semana que se completa con el CSV y la
 * que ya estaba importada (se informa como saltada, no como importada otra vez). Servidor simulado.
 */

const VIEWPORTS = [{ width: 1366, height: 611 }, { width: 1093, height: 489 }];

const week = (number, start, end, total, extra = {}) => ({
  statement_number: number, start_date: start, end_date: end, payout_date: end, existing_settlement: null,
  statement: { statement_number: number, start_date: start, end_date: end, total_payout: total, net_sales: total * 1.6 },
  invoice: null, claims: [], claims_count: 0,
  controls: { balance_ok: true, standard_lines_ok: true, xls_sales_match: true, xls_commission_match: true, xls_claims_match: true, invoice_match: null },
  blockers: [], warnings: [], ...extra,
});

const READ = {
  '7800000001': week('7800000001', '2026-10-05', '2026-10-11', 338.64, {
    status: 'new', orders_count: 13, orders_with_detail: 12, orders_source: 'mixed',
    warnings: ['Falta la factura fiscal de esta semana en la carpeta.', '1 pedido(s) del Excel no están en el CSV: se guardan sin artículos y no se crea su venta.'],
    sales: { orders: [], summary: { create: 12, link: 0, linked: 0, cancelled: 0, error: 0, unresolved_names: 0 }, unresolved: { products: [], options: [] } },
  }),
  '7800000002': week('7800000002', '2026-10-12', '2026-10-18', 746.54, {
    status: 'complete', orders_count: 20, orders_with_detail: 20, orders_source: 'csv',
    warnings: ['Ya estaba importada, pero a 20 pedido(s) les faltaba el detalle: se completan con el CSV.'],
    sales: { orders: [], summary: { create: 20, link: 0, linked: 0, cancelled: 0, error: 0, unresolved_names: 0 }, unresolved: { products: [], options: [] } },
  }),
  '7800000003': week('7800000003', '2026-10-19', '2026-10-25', 614.33, {
    status: 'already_imported', orders_count: 17, orders_with_detail: 17, orders_source: 'csv', sales: null,
  }),
};

async function setup(page) {
  await mockFinanzas(page);
  await page.route('**/api/finance/pedidosya-settlements/read-week', async (route) => {
    const body = route.request().postDataBuffer()?.toString('latin1') || '';
    const number = (body.match(/name="statement_number"\r\n\r\n(\d+)/) || [])[1];
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(READ[number]) });
  });
  const posted = [];
  await page.route('**/api/finance/pedidosya-settlements', async (route) => {
    if (route.request().method() !== 'POST') return route.fallback();
    const body = route.request().postDataBuffer()?.toString('latin1') || '';
    const number = (body.match(/name="statement_number"\r\n\r\n(\d+)/) || [])[1];
    posted.push(number);
    const res = number === '7800000002'
      ? { message: 'Semana de PedidosYa completada.', completed: true, skipped: false, settlement: { id: 2 }, warnings: [], sales: { created: 20, linked: 0, already: 0, errors: [] } }
      : { message: 'Semana de PedidosYa importada correctamente.', completed: false, skipped: false, settlement: { id: 1 }, warnings: ['1 pedido(s) sin detalle de artículos: no se creó su venta.'], sales: { created: 12, linked: 0, already: 0, errors: [] } };
    await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify(res) });
  });
  return posted;
}

function folder() {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'payoutArchive_PY_BO_'));
  for (const n of ['7800000001', '7800000002', '7800000003']) {
    fs.writeFileSync(path.join(dir, `account_statement_${n}_1.pdf`), 'prueba');
    fs.writeFileSync(path.join(dir, `invoice-${n}.xls`), 'prueba');
  }
  fs.writeFileSync(path.join(dir, 'orderDetails.csv'), 'prueba');
  return dir;
}

for (const vp of VIEWPORTS) {
  test(`avisos, semana para completar y ya importada (${vp.width} × ${vp.height})`, async ({ page }) => {
    await page.setViewportSize(vp);
    const posted = await setup(page);
    await page.goto('/finanzas?tab=pedidosya');
    await page.getByRole('button', { name: /Importar carpeta/i }).click();
    await page.locator('input[webkitdirectory]').setInputFiles(folder());

    await expect(page.getByText('3 semanas encontradas')).toBeVisible();
    const rowOf = (n) => page.locator('tr', { hasText: `Liquidación Nº ${n}` });

    // Todos los avisos de la semana, no solo el primero
    await expect(rowOf('7800000001')).toContainText('Falta la factura fiscal de esta semana en la carpeta.');
    await expect(rowOf('7800000001')).toContainText('1 pedido(s) del Excel no están en el CSV');
    await expect(rowOf('7800000001')).toContainText('CSV parcial');
    await expect(rowOf('7800000001')).toContainText('13 pedidos, 12 con artículos');
    await expect(rowOf('7800000002')).toContainText('Completar');
    await expect(rowOf('7800000003')).toContainText('Ya importada');

    const importBtn = page.getByRole('button', { name: 'Importar 2 semanas' });
    await expect(importBtn).toBeEnabled();
    await importBtn.click();

    await expect(page.getByText('Proceso completado')).toBeVisible();
    expect(posted.sort()).toEqual(['7800000001', '7800000002']);
    const results = page.locator('.fin-result-item');
    await expect(results.nth(0)).toContainText('12 ventas creadas');
    await expect(results.nth(0)).toContainText('1 pedido(s) sin detalle de artículos: no se creó su venta.');
    await expect(results.nth(1)).toContainText('Semana de PedidosYa completada. 20 ventas creadas.');
    await expect(page.getByRole('button', { name: 'Cerrar y ver liquidaciones' })).toBeInViewport();
  });
}

test('una semana que ya estaba se informa como saltada', async ({ page }) => {
  await setup(page);
  // El servidor responde que ya estaba importada (por ejemplo, otra persona la cargó mientras tanto)
  await page.route('**/api/finance/pedidosya-settlements', async (route) => {
    if (route.request().method() !== 'POST') return route.fallback();
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ message: 'Esta semana ya estaba importada: no se cambió nada.', skipped: true, settlement: { id: 1 }, warnings: [], sales: null }) });
  });
  await page.goto('/finanzas?tab=pedidosya');
  await page.getByRole('button', { name: /Importar carpeta/i }).click();
  await page.locator('input[webkitdirectory]').setInputFiles(folder());
  await page.getByRole('button', { name: 'Importar 2 semanas' }).click();

  const first = page.locator('.fin-result-item').first();
  await expect(first).toContainText('Esta semana ya estaba importada: no se cambió nada.');
  await expect(first.locator('.fin-badge')).toHaveText('Saltada');
});
