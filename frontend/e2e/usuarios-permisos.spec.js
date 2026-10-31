import { test, expect } from '@playwright/test';

/**
 * Usuarios → permisos de una persona (docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md, Fase 1):
 * Finanzas, ventas de días pasados y corregir cajas se dan por persona a un administrador;
 * nombrar a un Super Admin pide la contraseña de quien lo hace.
 */

const json = (body, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(body) });
const ME = {
  user: { id: 1, name: 'Dueño', email: 'dueno@ohana.test', role: 'super_admin' }, permissions: [],
  effective_permissions: {},
};
const USERS = [
  { id: 1, name: 'Dueño', email: 'dueno@ohana.test', role: 'super_admin', permission_overrides: {} },
  { id: 2, name: 'Rox', email: 'rox@ohana.test', role: 'admin', permission_overrides: {} },
  { id: 3, name: 'Lourdes', email: 'lourdes@ohana.test', role: 'admin', permission_overrides: { finance: 'read', sales_history: 'none' } },
  { id: 4, name: 'Cajera', email: 'caja@ohana.test', role: 'cashier', permission_overrides: {} },
];
const modulesFor = (levels) => Object.fromEntries(['sales_history', 'register_corrections', 'finance'].map((m) => [m, { effective: levels[m] }]));

async function setup(page) {
  const calls = [];
  await page.setViewportSize({ width: 1366, height: 611 });
  await page.addInitScript(() => localStorage.setItem('auth_token', 'mock'));
  await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
  await page.route('**/api/me', (r) => r.fulfill(json(ME)));
  await page.route('**/api/users', (r) => r.fulfill(json({ data: USERS })));
  await page.route(/\/api\/users\/\d+$/, (r) => {
    calls.push({ method: r.request().method(), url: r.request().url(), body: JSON.parse(r.request().postData() || '{}') });
    return r.fulfill(json({ data: { id: 2 } }));
  });
  await page.route(/\/api\/users\/\d+\/permissions$/, (r) => {
    if (r.request().method() === 'GET') {
      const id = Number(r.request().url().match(/users\/(\d+)/)[1]);
      return r.fulfill(json({ modules: id === 3 ? modulesFor({ sales_history: 'none', register_corrections: 'none', finance: 'read' }) : modulesFor({ sales_history: 'edit', register_corrections: 'none', finance: 'none' }) }));
    }
    calls.push({ method: 'PUT', url: r.request().url(), body: JSON.parse(r.request().postData()) });
    return r.fulfill(json({ message: 'Permisos guardados.' }));
  });
  await page.goto('/users');
  await expect(page.locator('.oh-table tbody tr')).toHaveCount(4);
  return calls;
}

const row = (page, name) => page.locator('.oh-table tbody tr', { hasText: name });
const modal = (page) => page.locator('.oh-modal-overlay.open .oh-modal');

test('La lista muestra los permisos propios de cada administrador', async ({ page }) => {
  await setup(page);
  await expect(row(page, 'Lourdes').locator('.oh-perm-badge')).toHaveText(['Sin días pasados', 'Finanzas: ver']);
  await expect(row(page, 'Rox').locator('.oh-perm-badge')).toHaveCount(0);
});

test('Dar Finanzas a un administrador: guarda solo lo que difiere de su rol', async ({ page }) => {
  const calls = await setup(page);
  await row(page, 'Rox').getByTitle('Editar').click();
  await expect(modal(page).locator('.pp-row')).toHaveCount(3);
  await expect(modal(page).getByRole('button', { name: 'Ventas de días pasados: Cargar y corregir' })).toHaveAttribute('aria-pressed', 'true');
  await expect(modal(page).getByRole('button', { name: 'Finanzas: Sin acceso' })).toHaveAttribute('aria-pressed', 'true');

  await modal(page).getByRole('button', { name: 'Finanzas: Editar' }).click();
  await modal(page).getByRole('button', { name: 'Corregir cajas cerradas: Corregir' }).click();
  await expect(modal(page).locator('.pp-row[data-module="finance"] .pp-own')).toBeVisible();
  // Con la ficha larga, Guardar queda a la vista en la portátil
  await expect(modal(page).getByRole('button', { name: 'Guardar usuario' })).toBeInViewport();
  await modal(page).getByRole('button', { name: 'Guardar usuario' }).click();

  await expect.poll(() => calls.length).toBe(2);
  expect(calls[0].method).toBe('PUT');
  expect(calls[0].body.current_password).toBeUndefined();
  expect(calls[1].body.permissions).toEqual([
    { module: 'sales_history', access_level: null },
    { module: 'register_corrections', access_level: 'edit' },
    { module: 'finance', access_level: 'edit' },
  ]);
});

test('Sin cambios en los permisos no se llama a su API; un cajero no puede recibirlos', async ({ page }) => {
  const calls = await setup(page);
  await row(page, 'Cajera').getByTitle('Editar').click();
  await expect(modal(page).locator('.pp-note')).toHaveText('Finanzas, ventas de días pasados y corregir cajas solo se dan a administradores.');
  await modal(page).getByRole('button', { name: 'Guardar usuario' }).click();
  await expect.poll(() => calls.length).toBe(1);
  expect(calls[0].url).toMatch(/\/api\/users\/4$/);
});

test('Nombrar Super Admin avisa que tendrá todo y pide la contraseña', async ({ page }) => {
  const calls = await setup(page);
  await row(page, 'Rox').getByTitle('Editar').click();
  await modal(page).locator('select').selectOption('super_admin');
  await expect(modal(page).locator('.oh-super-warning')).toContainText('Tendrá todos los permisos,');
  await modal(page).getByLabel('Tu contraseña, para confirmar').fill('clave-dueno');
  await modal(page).getByRole('button', { name: 'Guardar usuario' }).click();
  await expect.poll(() => calls.length).toBe(1);
  expect(calls[0].body.role).toBe('super_admin');
  expect(calls[0].body.current_password).toBe('clave-dueno');
});

test('El dueño no puede cambiar su propio rol', async ({ page }) => {
  await setup(page);
  await row(page, 'Dueño').getByTitle('Editar').click();
  await expect(modal(page).locator('select')).toBeDisabled();
  await expect(modal(page).locator('.hint-text')).toHaveText('No puedes cambiar tu propio rol.');
  await expect(modal(page).locator('.pp-note')).toContainText('El Super Admin tiene todos los permisos');
});
