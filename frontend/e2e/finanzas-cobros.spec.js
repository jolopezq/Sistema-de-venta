import { test, expect } from '@playwright/test';
import { mockFinanzas } from './fixtures/finanzas-api.js';

/**
 * Finanzas: cómo se cobraron las ventas del local (Estado de Resultados → Ventas → Ventas del local) y
 * «Dinero que entró en el mes» (Resumen), en la portátil de caja y al 125 %, en claro y oscuro.
 */

const VIEWPORTS = [{ width: 1366, height: 611 }, { width: 1093, height: 489 }];

async function openSummary(page, { theme = 'light', vp } = {}) {
  if (vp) await page.setViewportSize(vp);
  await page.addInitScript((t) => localStorage.setItem('theme', t), theme);
  await page.goto('/finanzas?tab=summary');
  await page.waitForSelector('.fin-statement', { timeout: 15000 });
}

const noHorizontalScroll = (page) => page.evaluate(() => {
  const sc = document.querySelector('.oh-shell-content');
  return sc.scrollWidth - sc.clientWidth;
});

for (const vp of VIEWPORTS) {
  for (const theme of ['light', 'dark']) {
    test(`Ventas del local: efectivo, QR, tarjeta y «Sin cuadrar» (${vp.width} × ${vp.height}, ${theme})`, async ({ page }) => {
      await mockFinanzas(page);
      await openSummary(page, { theme, vp });

      const table = page.locator('.fin-statement');
      await table.getByRole('button', { name: 'Ventas', exact: true }).click();

      const local = table.getByRole('button', { name: 'Ventas del local' });
      await expect(local).toHaveAttribute('aria-expanded', 'true');
      const localRow = table.locator('tr.child').filter({ has: page.getByRole('button', { name: 'Ventas del local' }) });
      await expect(localRow).toContainText('640 tickets');
      await expect(localRow).toContainText('Bs 17.000,00');

      const item = (key) => table.locator(`tr.item[data-method="${key}"]`);
      await expect(item('cash')).toContainText('Efectivo');
      await expect(item('cash')).toContainText('Bs 9.800,00');
      await expect(item('cash')).toContainText('57,6 % de las ventas del local');
      await expect(item('qr')).toContainText('QR o banco');
      await expect(item('qr')).toContainText('Bs 5.600,00');
      await expect(item('card')).toContainText('Bs 1.520,00');
      await expect(item('gap')).toContainText('Sin cuadrar');
      await expect(item('gap')).toContainText('Bs 80,00');
      await expect(item('gap')).toContainText('2 ventas no cuadran con sus pagos: 14/10 sin pago, 22/10 faltan Bs 45,00. Corrígelas en Control diario.');

      // PedidosYa sigue con sus ventas netas, fuera de «Ventas del local»
      await expect(table.locator('tr.child', { hasText: 'PedidosYa (semanas importadas)' })).toContainText('Bs 2.800,00');

      await item('gap').scrollIntoViewIfNeeded();
      await expect(item('gap')).toBeInViewport();
      expect(await noHorizontalScroll(page)).toBeLessThanOrEqual(1);
    });

    test(`«Dinero que entró en el mes» (${vp.width} × ${vp.height}, ${theme})`, async ({ page }) => {
      await mockFinanzas(page);
      await openSummary(page, { theme, vp });

      const card = page.locator('.cin');
      await card.scrollIntoViewIfNeeded();
      await expect(card.getByRole('heading', { name: 'Dinero que entró en el mes' })).toBeVisible();
      const row = (key) => card.locator(`tr[data-row="${key}"]`);
      await expect(row('cash')).toContainText('Bs 9.800,00');
      await expect(row('qr')).toContainText('Bs 5.600,00');
      await expect(row('card')).toContainText('Bs 1.520,00');
      await expect(row('pya')).toContainText('Depósitos del 01/10, 08/10, 15/10 y 22/10');
      await expect(row('pya')).toContainText('Bs 2.390,80');
      await expect(row('total')).toContainText('Bs 19.310,80');
      await expect(row('total')).toContainText('A la cuenta Bs 9.510,80 (QR, tarjeta y PedidosYa)');

      const notes = card.locator('.cin-notes li');
      await expect(notes).toHaveText([
        'PedidosYa: la semana del 26/10 al 01/11 (Bs 614,33) se deposita el 05/11, ya en el mes siguiente.',
        'La semana del 19/10 al 25/10 de PedidosYa no tiene fecha de pago: no se cuenta como depositada.',
        '2 ventas del local no cuadran con sus pagos: aquí cuentan los pagos registrados.',
        'La tarjeta llega a la cuenta con el descuento del banco.',
      ]);

      const box = await card.boundingBox();
      expect(box.x + box.width).toBeLessThanOrEqual(vp.width);
      expect(await noHorizontalScroll(page)).toBeLessThanOrEqual(1);
    });
  }
}

test('«Ver cada línea» abre y cierra también el detalle de cobros', async ({ page }) => {
  await mockFinanzas(page);
  await openSummary(page);
  const table = page.locator('.fin-statement');

  await page.getByRole('button', { name: 'Ver cada línea' }).click();
  await expect(table.locator('tr.item[data-method="cash"]')).toBeVisible();
  await page.getByRole('button', { name: 'Ocultar el detalle' }).click();
  await expect(table.locator('tr.item[data-method="cash"]')).toHaveCount(0);

  // Después de «Ocultar el detalle», «Ventas del local» queda plegada y se abre o cierra por separado
  await table.getByRole('button', { name: 'Ventas', exact: true }).click();
  await expect(table.locator('tr.item[data-method="cash"]')).toHaveCount(0);
  await expect(table.locator('tr.child', { hasText: 'PedidosYa (semanas importadas)' })).toBeVisible();
  const local = table.getByRole('button', { name: 'Ventas del local' });
  await local.click();
  await expect(table.locator('tr.item[data-method="cash"]')).toBeVisible();
  await local.click();
  await expect(table.locator('tr.item[data-method="cash"]')).toHaveCount(0);
});

test('un mes que cuadra no muestra «Sin cuadrar» ni su aviso', async ({ page }) => {
  await mockFinanzas(page);
  // Mes simple: todo en efectivo y QR, sin PedidosYa ni avisos (reemplaza el Estado de Resultados del mock)
  await page.route('**/api/finance/pnl*', (r) => r.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      period: { year: 2026, month: 10 },
      revenue: { total: 1000, local_sales: 1000, local_sales_count: 20, pedidosya_net: 0, pedidosya_pos_unreconciled: 0, cash_sales: 600, qr_sales: 400, card_sales: 0, other_sales: 0, payments_gap: 0, payments_gap_count: 0, payments_gap_sales: [] },
      cash_in: { cash: 600, qr: 400, card: 0, other: 0, pedidosya_deposited: 0, pedidosya_deposits: [], pedidosya_paid_later: 0, pedidosya_paid_later_weeks: [], pedidosya_without_payout_date: [], pedidosya_uncovered_ranges: [], to_bank: 400, total: 1000 },
      cost_of_sales: { total: 0, by_category: [], items: [] }, operating_expenses: { total: 0, by_category: [], items: [] },
      financial_expenses: { total: 0, by_category: [], items: [] }, taxes: { total: 0, by_category: [], items: [] },
      gross_profit: { amount: 1000 }, operating_profit: { amount: 1000 }, net_profit: { amount: 1000 },
      pedidosya_analysis: { settlements_count: 0 }, warnings: [],
    }),
  }));
  await openSummary(page);
  const table = page.locator('.fin-statement');
  await table.getByRole('button', { name: 'Ventas', exact: true }).click();
  await expect(table.locator('tr.item[data-method="cash"]')).toContainText('60,0 % de las ventas del local');
  await expect(table.locator('tr.item[data-method="gap"]')).toHaveCount(0);

  const card = page.locator('.cin');
  await expect(card.locator('tr[data-row="pya"]')).toContainText('Sin depósitos en el mes');
  await expect(card.locator('.cin-notes')).toHaveCount(0);
});
