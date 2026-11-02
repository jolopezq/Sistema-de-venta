import { test, expect } from '@playwright/test';

/**
 * Pruebas E2E de corrección y anulación de gastos de caja (docs/PLAN_CORREGIR_GASTOS_CAJA.md).
 * Cobertura:
 * - Turno (/turno): listado de «Gastos del turno», visualización de corregidos y anulados, apertura de modales.
 * - Control diario (/admin/ventas-retroactivas): tabla de Gastos e Insumos con acciones y aviso de caja cerrada.
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

const MOCK_MOVEMENTS = [
  {
    id: 101,
    cash_register_session_id: 1,
    type: 'expense',
    payment_method: 'cash',
    amount: 74,
    description: 'Frutas del mercado',
    occurred_at: '2026-11-02T10:30:00Z',
    created_at: '2026-11-02T10:30:00Z',
    user: { id: 7, name: 'Cajero Prueba' },
    expense_category: { id: 2, name: 'Insumos y Frutas' },
    edit_count: 1,
    edit_reason: 'Se pagó en efectivo de la gaveta y no por QR',
    edit_previous: { payment_method: 'qr', amount: 74, description: 'Frutas del mercado' },
    deleted_at: null,
  },
  {
    id: 102,
    cash_register_session_id: 1,
    type: 'expense',
    payment_method: 'cash',
    amount: 15,
    description: 'Bolsas plásticas',
    occurred_at: '2026-11-02T11:00:00Z',
    created_at: '2026-11-02T11:00:00Z',
    user: { id: 7, name: 'Cajero Prueba' },
    expense_category: null,
    edit_count: 0,
    deleted_at: '2026-11-02T11:15:00Z',
    void_reason: 'Registro duplicado por error',
    voided_by: { id: 7, name: 'Cajero Prueba' },
  },
  {
    id: 103,
    cash_register_session_id: 1,
    type: 'expense',
    payment_method: 'qr',
    amount: 25,
    description: 'Servicio de delivery externo',
    occurred_at: '2026-11-02T12:00:00Z',
    created_at: '2026-11-02T12:00:00Z',
    user: { id: 7, name: 'Cajero Prueba' },
    expense_category: null,
    edit_count: 0,
    deleted_at: null,
  },
];

const json = (body, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(body) });

async function setupTurnoMocks(page) {
  page.on('console', msg => console.log('PAGE LOG:', msg.text()));
  page.on('pageerror', err => console.log('PAGE ERROR:', err.message));
  await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
  await page.route('**/api/me', (r) => r.fulfill(json(ME_CASHIER)));
  await page.route('**/api/cash-sessions/active', (r) => r.fulfill(json({
    session: {
      id: 1,
      status: 'open',
      opening_amount: 200,
      opened_at: '2026-11-02T08:00:00Z',
      cashier: { name: 'Cajero Prueba' },
    },
    stats: {
      cash_sales: 300,
      cash_expenses: 74,
      non_cash_expenses: 25,
      expected_cash: 426,
      total_sales: 500,
      qr_sales: 200,
      card_sales: 0,
      sales_count: 5,
    },
    sales_session_id: 1,
  })));
  await page.route('**/api/cash-sessions/1/movements', (r) => r.fulfill(json(MOCK_MOVEMENTS)));
  await page.route('**/api/sales**', (r) => r.fulfill(json({
    sales: { data: [], current_page: 1, last_page: 1, total: 0, per_page: 15 },
    summary: { total_sales: 0, total_discounts: 0, sales_count: 0, voided_count: 0 },
  })));
  await page.route('**/api/cash-sessions/1/sales**', (r) => r.fulfill(json({
    data: [],
    current_page: 1,
    last_page: 1,
    total: 0,
  })));
  await page.route('**/api/expense-categories', (r) => r.fulfill(json({
    data: [{ id: 1, name: 'Varios' }, { id: 2, name: 'Insumos y Frutas' }],
  })));
  await page.route('**/api/catalog', (r) => r.fulfill(json({ categories: [], products: [], customers: [], ingredients: [] })));
  await page.route('**/api/inventory/reorder-summary', (r) => r.fulfill(json({ count: 0, items: [] })));
  await page.addInitScript(() => localStorage.setItem('auth_token', 'mock_cashier_jwt'));
}

test.describe('Gastos del turno (Turno.vue)', () => {
  test('muestra la sección de gastos con movimientos corregidos y anulados', async ({ page }) => {
    await setupTurnoMocks(page);
    await page.goto('/turno');

    // La sección de Gastos del turno está visible
    const card = page.locator('.tn-movements-card');
    await expect(card).toBeVisible();
    await expect(card.locator('#tn-movs-title')).toHaveText('Gastos del turno');

    // Movimiento corregido: muestra insignia Corregido y motivo de corrección
    const correctedRow = card.locator('tr:has-text("Frutas del mercado")');
    await expect(correctedRow).toBeVisible();
    await expect(correctedRow.locator('.badge-corrected')).toHaveText('Corregido');
    await expect(correctedRow).toContainText('Se pagó en efectivo de la gaveta y no por QR');
    await expect(correctedRow).toContainText('Antes: QR · Bs 74.00');

    // Movimiento anulado: fila con estilo tachado y motivo de anulación
    const voidedRow = card.locator('tr:has-text("Bolsas plásticas")');
    await expect(voidedRow).toBeVisible();
    await expect(voidedRow).toHaveClass(/voided-row/);
    await expect(voidedRow.locator('.badge-voided')).toHaveText('Anulado');
    await expect(voidedRow).toContainText('Registro duplicado por error');
    await expect(voidedRow.locator('.void-text')).toHaveText('Sin acciones');

    // Movimiento activo con botones Corregir y Anular
    const activeRow = card.locator('tr:has-text("Servicio de delivery externo")');
    await expect(activeRow.locator('.tn-act-btn.edit')).toBeVisible();
    await expect(activeRow.locator('.tn-act-btn.void')).toBeVisible();
  });

  test('abre el modal de corrección con los valores del movimiento cargados y tipo bloqueado', async ({ page }) => {
    await setupTurnoMocks(page);
    await page.goto('/turno');

    const card = page.locator('.tn-movements-card');
    const correctedRow = card.locator('tr:has-text("Frutas del mercado")');
    await correctedRow.locator('.tn-act-btn.edit').click();

    // Modal de corrección abierto
    const modal = page.locator('.ce-host');
    await expect(modal).toBeVisible();
    await expect(modal.locator('.ce-locked-banner')).toBeVisible();
    await expect(modal.locator('.ce-locked-banner')).toContainText('El tipo no se puede cambiar');

    // Campo de motivo obligatorio presente en modo edición
    const reasonInput = modal.locator('#ce-reason');
    await expect(reasonInput).toBeVisible();
    await expect(modal.locator('.fin-btn-primary')).toHaveText('Guardar corrección');
  });

  test('abre el modal de anulación pidiendo motivo obligatorio', async ({ page }) => {
    await setupTurnoMocks(page);
    await page.goto('/turno');

    const card = page.locator('.tn-movements-card');
    const activeRow = card.locator('tr:has-text("Servicio de delivery externo")');
    await activeRow.locator('.tn-act-btn.void').click();

    // Modal de anulación abierto
    const modal = page.locator('.vm-host');
    await expect(modal).toBeVisible();
    await expect(modal.locator('.vm-summary')).toContainText('Bs 25.00');
    await expect(modal.locator('.vm-summary')).toContainText('Servicio de delivery externo');

    const submitBtn = modal.locator('.fin-btn-danger');
    await expect(submitBtn).toBeDisabled();

    // Al ingresar menos de 10 caracteres sigue deshabilitado
    const reasonInput = modal.locator('#vm-reason');
    await reasonInput.fill('Error');
    await expect(submitBtn).toBeDisabled();

    // Con 10 o más caracteres se habilita
    await reasonInput.fill('Error de duplicación al registrar');
    await expect(submitBtn).toBeEnabled();
  });
});

test.describe('Gastos en Control Diario (RetroactiveSales.vue)', () => {
  test('en caja abierta permite corregir y anular movimientos en la pestaña de gastos', async ({ page }) => {
    await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
    await page.route('**/api/me', (r) => r.fulfill(json(ME_ADMIN)));
    await page.route('**/api/sales**', (r) => r.fulfill(json({
      sales: { data: [], current_page: 1, last_page: 1, total: 0, per_page: 15 },
      summary: { total_sales: 0, total_discounts: 0, sales_count: 0, voided_count: 0 },
    })));
    await page.route('**/api/admin/sales/day-info**', (r) => r.fulfill(json({ historical: false })));
    await page.route('**/api/users/sellers', (r) => r.fulfill(json({ data: [] })));
    await page.route('**/api/cash-sessions/active', (r) => r.fulfill(json({
      session: { id: 1, status: 'open', opening_amount: 200 },
      stats: {
        cash_sales: 300,
        cash_expenses: 74,
        non_cash_expenses: 25,
        expected_cash: 426,
        total_sales: 500,
      },
    })));
    await page.route('**/api/cash-sessions/by-date**', (r) => r.fulfill(json({
      id: 1,
      status: 'open',
      opening_amount: 200,
      opened_at: '2026-11-02T08:00:00Z',
    })));
    await page.route('**/api/cash-sessions/1', (r) => r.fulfill(json({
      session: { id: 1, status: 'open', opening_amount: 200 },
      stats: {
        cash_sales: 300,
        cash_expenses: 74,
        non_cash_expenses: 25,
        expected_cash: 426,
        total_sales: 500,
      },
    })));
    await page.route('**/api/cash-sessions/1/movements', (r) => r.fulfill(json(MOCK_MOVEMENTS)));
    await page.route('**/api/expense-categories', (r) => r.fulfill(json({
      data: [{ id: 1, name: 'Varios' }, { id: 2, name: 'Insumos y Frutas' }],
    })));
    await page.route('**/api/catalog', (r) => r.fulfill(json({ categories: [], products: [], customers: [], ingredients: [] })));
    await page.addInitScript(() => localStorage.setItem('auth_token', 'mock_admin_jwt'));

    await page.goto('/admin/ventas-retroactivas');

    // Cambiar a la pestaña de Gastos e Insumos
    const tabExpenses = page.locator('button.tab-btn:has-text("Gastos e Insumos")');
    await tabExpenses.click();

    // Tabla de gastos visible con encabezado de Acciones
    const table = page.locator('.records-content table.cd-table');
    await expect(table).toBeVisible();
    await expect(table.locator('th:has-text("Acciones")')).toBeVisible();

    // Botones de acción en fila activa
    const activeRow = table.locator('tr:has-text("Servicio de delivery externo")');
    await expect(activeRow.locator('.btn-table-edit')).toHaveText('✏️ Corregir');
    await expect(activeRow.locator('.btn-table-void')).toHaveText('🗑️ Anular');
  });

  test('en caja cerrada muestra el aviso informativo «Reabre la caja para corregir»', async ({ page }) => {
    await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
    await page.route('**/api/me', (r) => r.fulfill(json(ME_ADMIN)));
    await page.route('**/api/sales**', (r) => r.fulfill(json({
      sales: { data: [], current_page: 1, last_page: 1, total: 0, per_page: 15 },
      summary: { total_sales: 0, total_discounts: 0, sales_count: 0, voided_count: 0 },
    })));
    await page.route('**/api/admin/sales/day-info**', (r) => r.fulfill(json({ historical: false })));
    await page.route('**/api/users/sellers', (r) => r.fulfill(json({ data: [] })));
    await page.route('**/api/cash-sessions/active', (r) => r.fulfill(json({
      session: {
        id: 2,
        status: 'closed',
        opening_amount: 200,
        closed_at: '2026-11-02T22:00:00Z',
      },
      stats: { total_sales: 500 },
    })));
    await page.route('**/api/cash-sessions/by-date**', (r) => r.fulfill(json({
      session: {
        id: 2,
        status: 'closed',
        opening_amount: 200,
        closed_at: '2026-11-02T22:00:00Z',
      },
    })));
    await page.route(/\/api\/cash-sessions\/2$/, (r) => r.fulfill(json({
      session: { id: 2, status: 'closed', opening_amount: 200 },
      stats: { total_sales: 500 },
    })));
    await page.route('**/api/cash-sessions/2/report', (r) => r.fulfill(json({
      session: { id: 2, status: 'closed' },
      summary: {},
    })));
    await page.route('**/api/cash-sessions/2/movements', (r) => r.fulfill(json([
      {
        id: 201,
        type: 'expense',
        payment_method: 'cash',
        amount: 50,
        description: 'Hielo para el día',
        created_at: '2026-11-02T10:00:00Z',
        user: { name: 'Cajero' },
        deleted_at: null,
        edit_count: 0,
      },
    ])));
    await page.route('**/api/catalog', (r) => r.fulfill(json({ categories: [], products: [], customers: [], ingredients: [] })));
    await page.addInitScript(() => localStorage.setItem('auth_token', 'mock_admin_jwt'));

    await page.goto('/admin/ventas-retroactivas');

    const tabExpenses = page.locator('button.tab-btn:has-text("Gastos e Insumos")');
    await tabExpenses.click();

    // La fila no tiene botones de edición directa sino el aviso
    const row = page.locator('.records-content table.cd-table tr:has-text("Hielo para el día")');
    await expect(row.locator('.text-muted-notice')).toHaveText('Reabre la caja para corregir');
  });
});
