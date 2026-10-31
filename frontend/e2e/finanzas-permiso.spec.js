import { test, expect } from '@playwright/test';
import { mockFinanzas } from './fixtures/finanzas-api.js';

/**
 * Finanzas por persona (docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md, Fase 2): un administrador con «Ver» consulta
 * todo sin acciones de registro; con «Editar», como el dueño; sin permiso, no ve la pantalla.
 */

const adminWith = (finance) => ({
  user: { id: 5, name: 'Lourdes', email: 'lourdes@ohana.test', role: 'admin' },
  permissions: [],
  effective_permissions: {
    pos: 'edit', register_self: 'edit', delivery_ops: 'edit', crm_read: 'read', voids: 'edit', register_others: 'edit',
    catalog: 'edit', inventory: 'edit', crm_config: 'edit', reports: 'read', users: 'none', audit: 'none',
    sales_history: 'edit', register_corrections: 'none', finance,
  },
});

const tab = (page, name) => page.getByRole('tab', { name: new RegExp(`^${name}`) });

test.beforeEach(async ({ page }) => { await page.setViewportSize({ width: 1366, height: 611 }); });

test('Con «Ver»: Finanzas en el menú, aviso de solo lectura y sin acciones de registro', async ({ page }) => {
  await mockFinanzas(page, { me: adminWith('read') });
  await page.goto('/finanzas');
  await expect(page.locator('.fin-head h1')).toContainText('Solo lectura');
  await expect(page.locator('.oh-nav a[href="/finanzas"]')).toBeVisible();

  await tab(page, 'Compras y gastos').click();
  await expect(page.locator('.fin-body')).toContainText('Compra menor 1');
  await expect(page.getByRole('button', { name: 'Registrar gasto' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: /Registrar compra/ })).toHaveCount(0);
  await expect(page.getByRole('button', { name: /^Editar / })).toHaveCount(0);

  await tab(page, 'Gastos fijos').click();
  await expect(page.getByRole('button', { name: /pendientes|Nada pendiente/ })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Generar', exact: true })).toHaveCount(0);

  await tab(page, 'Costos y margen').click();
  await expect(page.getByRole('button', { name: 'Cargar recetas base' })).toHaveCount(0);

  await tab(page, 'PedidosYa').click();
  await expect(page.getByRole('button', { name: /Cargar semana|Cargar la primera/ })).toHaveCount(0);
});

test('Con «Editar»: las acciones están y no hay aviso de solo lectura', async ({ page }) => {
  await mockFinanzas(page, { me: adminWith('edit') });
  await page.goto('/finanzas');
  await expect(page.locator('.fin-head h1')).not.toContainText('Solo lectura');
  await tab(page, 'Compras y gastos').click();
  await expect(page.getByRole('button', { name: 'Registrar gasto' })).toBeVisible();
});

test('Sin permiso: Finanzas no aparece en el menú y la dirección lleva a otra pantalla', async ({ page }) => {
  await mockFinanzas(page, { me: adminWith('none') });
  await page.goto('/finanzas');
  await expect(page).not.toHaveURL(/\/finanzas/);
  await expect(page.locator('.oh-nav a[href="/finanzas"]')).toHaveCount(0);
});
