import { test, expect } from '@playwright/test';
import { mockFinanzas } from './fixtures/finanzas-api.js';

const VIEWPORTS = [
  { width: 1366, height: 611, desc: 'Portátil 100%' },
  { width: 1093, height: 489, desc: 'Portátil 125%' },
];

test.describe('PedidosYa — Importación por carpeta y Facturas fiscales', () => {
  test.beforeEach(async ({ page }) => {
    await mockFinanzas(page);

    // Mock endpoint de read-week
    await page.route('**/api/finance/pedidosya-settlements/read-week', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          statement_number: '7800702619',
          start_date: '2026-09-21',
          end_date: '2026-09-27',
          payout_date: '2026-10-01',
          status: 'new',
          existing_settlement: null,
          statement: {
            statement_number: '7800702619',
            start_date: '2026-09-21',
            end_date: '2026-09-27',
            payout_date: '2026-10-01',
            net_sales: 838,
            delivery_fees: 0,
            service_fees: 209.5,
            operating_charges: 0,
            advertising: 105.85,
            refunds: 0,
            taxes: 47.11,
            total_payout: 475.54,
            calculated_payout: 475.54,
            discrepancy: 0,
          },
          invoice: {
            invoice_number: '688161',
            invoice_date: '2026-09-28',
            invoice_authorization: 'AUTH123',
            invoice_total: 362.46,
            fiscal_credit_base: 362.46,
          },
          claims: [],
          claims_count: 0,
          orders_count: 15,
          orders_source: 'csv',
          controls: {
            balance_ok: true,
            standard_lines_ok: true,
            xls_sales_match: true,
            xls_commission_match: true,
            xls_claims_match: true,
            invoice_match: true,
          },
          warnings: [],
          blockers: [],
          sales: {
            orders: [],
            summary: {
              create: 15,
              link: 0,
              linked: 0,
              cancelled: 0,
              error: 0,
              unresolved_names: 0,
              price_differences: 0,
            },
            unresolved: { products: [], options: [] },
          },
        }),
      });
    });

    // Mock endpoint de importación por carpeta
    await page.route('**/api/finance/pedidosya-settlements', async (route) => {
      if (route.request().method() === 'POST') {
        await route.fulfill({
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify({
            message: 'Semana de PedidosYa importada correctamente.',
            settlement: {
              id: 10,
              statement_number: '7800702619',
              start_date: '2026-09-21',
              end_date: '2026-09-27',
              payout_date: '2026-10-01',
              total_payout: 475.54,
            },
            warnings: [],
            sales: { created: 15, linked: 0, already: 0, errors: [] },
          }),
        });
      } else {
        await route.fallback();
      }
    });

    // Mock endpoint de descarga de archivos
    await page.route('**/api/finance/pedidosya-settlements/*/files/*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/pdf',
        headers: { 'content-disposition': 'attachment; filename="factura.pdf"' },
        body: '%PDF-1.4 mock content',
      });
    });
  });

  for (const vp of VIEWPORTS) {
    test(`1. Botón «Importar carpeta» y apertura de modal — ${vp.desc} (${vp.width}×${vp.height})`, async ({ page }) => {
      await page.setViewportSize(vp);
      await page.goto('/finanzas?tab=pedidosya');
      await page.waitForSelector('.fin-card');

      const btnFolder = page.getByRole('button', { name: /Importar carpeta/i });
      await expect(btnFolder).toBeVisible();

      await btnFolder.click();
      await expect(page.getByRole('heading', { name: /Importar carpeta de PedidosYa/i })).toBeVisible();
      await expect(page.locator('.fin-dropzone')).toBeVisible();

      // Cerrar modal con botón exacto
      await page.getByRole('button', { name: 'Cerrar', exact: true }).click();
      await expect(page.locator('.fin-dropzone')).not.toBeVisible();
    });

    test(`2. Pestaña «Facturas del mes (crédito fiscal)» muestra resumen y tabla — ${vp.desc} (${vp.width}×${vp.height})`, async ({ page }) => {
      await page.setViewportSize(vp);
      await page.goto('/finanzas?tab=pedidosya');
      await page.waitForSelector('.fin-card');

      const tabFacturas = page.getByRole('button', { name: /Facturas del mes/i });
      await expect(tabFacturas).toBeVisible();
      await tabFacturas.click();

      // Verifica KPIs de facturas
      await expect(page.getByText('Crédito fiscal IVA (13%)')).toBeVisible();
      await expect(page.getByText('Facturas emitidas')).toBeVisible();

      // Verifica filas de facturas
      await expect(page.getByText('688161')).toBeVisible();
      await expect(page.getByText('691234')).toBeVisible();

      // Botón de descarga de PDF
      const pdfBtn = page.getByTitle('Descargar factura en PDF').first();
      await expect(pdfBtn).toBeVisible();
    });
  }

  test('3. Simulación de carga de archivos en modal muestra tabla de previsualización', async ({ page }) => {
    await page.setViewportSize(VIEWPORTS[0]);
    await page.goto('/finanzas?tab=pedidosya');
    await page.getByRole('button', { name: /Importar carpeta/i }).click();

    // Inyectar la carpeta de prueba real
    const folderPath = '/Users/jolopez/Sistema de Ventas/docs/payoutArchive_PY_BO_20261001_to_20261101';
    const fileInput = page.locator('input[webkitdirectory]');
    await fileInput.setInputFiles(folderPath);

    // Esperar previsualización
    await expect(page.getByText(/semanas encontradas/i)).toBeVisible({ timeout: 5000 });
    await expect(page.getByText('Nueva').first()).toBeVisible();
    await expect(page.getByText('✓ Todo cuadra').first()).toBeVisible();

    // Botón para importar
    const importBtn = page.getByRole('button', { name: /Importar \d+ semanas/i });
    await expect(importBtn).toBeEnabled();

    await importBtn.click();
    await expect(page.getByText('Proceso completado')).toBeVisible({ timeout: 5000 });
    await expect(page.getByText('Completada').first()).toBeVisible();
  });
});
