/**
 * API simulada de Finanzas con datos abundantes (peor caso para la portátil de caja).
 * Se usa con `await mockFinanzas(page)` antes de `page.goto('/finanzas')`.
 */
const json = (body, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(body) });

const ME = {
  user: { id: 1, name: 'Dueño Prueba', email: 'dueno@example.com', role: 'super_admin' },
  permissions: [],
  effective_permissions: {
    pos: 'edit', register_self: 'edit', delivery_ops: 'edit', crm_read: 'edit', voids: 'edit', register_others: 'edit',
    catalog: 'edit', inventory: 'edit', crm_config: 'edit', reports: 'edit', users: 'edit', audit: 'edit',
  },
};

const SECTIONS = ['cost_of_sales', 'operating', 'financial', 'taxes'];
const CATS = [
  ['Insumos de açaí', 'cost_of_sales', 'variable'], ['Frutas y toppings', 'cost_of_sales', 'variable'], ['Descartables', 'cost_of_sales', 'variable'], ['Lácteos', 'cost_of_sales', 'variable'],
  ['Alquiler', 'operating', 'fixed'], ['Sueldos', 'operating', 'fixed'], ['Servicios básicos', 'operating', 'fixed'], ['Internet', 'operating', 'fixed'], ['Publicidad', 'operating', 'variable'], ['Mantenimiento', 'operating', 'variable'],
  ['Comisión bancaria', 'financial', 'variable'], ['Intereses', 'financial', 'fixed'],
  ['IVA', 'taxes', 'variable'], ['IT', 'taxes', 'variable'], ['Régimen simplificado', 'taxes', 'fixed'],
].map(([name, section, cost_behavior], i) => ({ id: i + 1, name, section, group_name: null, cost_behavior, is_active: true, sort_order: i }));

const amount = (n) => Number(n.toFixed(2));
const pnlCat = (name, total, n = 2) => ({ name, count: n, amount: total });
const item = (cat, a, i) => ({ id: i, category_name: cat, amount: a, date: '2026-10-05', description: `${cat} #${i}`, supplier: 'Proveedor' });

function pnl(warnings = true) {
  const mk = (cats) => ({
    total: amount(cats.reduce((s, c) => s + c[1], 0)),
    by_category: cats.map(([n, a]) => pnlCat(n, a)),
    items: cats.flatMap(([n, a], i) => [item(n, a / 2, i * 2 + 1), item(n, a / 2, i * 2 + 2)]),
  });
  const cogs = mk([['Insumos de açaí', 4200], ['Frutas y toppings', 1800], ['Descartables', 650], ['Lácteos', 420]]);
  const opex = mk([['Alquiler', 2500], ['Sueldos', 5200], ['Servicios básicos', 800], ['Internet', 220], ['Publicidad', 400]]);
  const fin = mk([['Comisión bancaria', 180]]);
  const tax = mk([['IT', 540]]);
  const revenue = 19800;
  const gross = revenue - cogs.total;
  const op = gross - opex.total;
  const net = op - fin.total - tax.total;
  return {
    period: { year: 2026, month: 10 },
    revenue: { total: revenue, local_sales: 17000, local_sales_count: 640, pedidosya_net: 2800, pedidosya_pos_unreconciled: 0 },
    cost_of_sales: cogs, operating_expenses: opex, financial_expenses: fin, taxes: tax,
    gross_profit: { amount: gross }, operating_profit: { amount: op }, net_profit: { amount: net },
    pedidosya_analysis: { settlements_count: 2, prorated: false, orders_count: 120, gross: 3300, commission: 500, net: 2800, average_ticket: 27.5, cancelled_count: 3, cancelled_amount: 80 },
    warnings: warnings ? [
      { code: 'cash_unclassified', level: 'warning', message: '4 egresos de caja sin clasificar por Bs 320.', action: 'classify_cash' },
      { code: 'recurring_pending', level: 'warning', message: 'Faltan generar 2 gastos fijos del mes.', action: 'generate_recurring' },
    ] : [],
    reorder: { to_reorder: 3, estimated_cost: 640, urgent: [{ name: 'Pulpa de açaí', stock: 2, unit: 'kg', status: 'urgent' }, { name: 'Granola', stock: 1, unit: 'kg', status: 'urgent' }], expiring: 1, no_data: 0 },
  };
}

const expenses = Array.from({ length: 14 }, (_, i) => ({
  id: i + 1, date: `2026-10-${String((i % 28) + 1).padStart(2, '0')}`, amount: 120 + i * 37, notes: i % 3 ? 'Compra semanal' : null,
  expense_category_id: (i % 15) + 1, category: { id: (i % 15) + 1, name: CATS[i % 15].name, section: CATS[i % 15].section },
  supplier: ['Distribuidora Sur', 'Frutería Don Pepe', 'Packaging Bolivia', 'Lácteos Andinos'][i % 4],
  payment_method: 'cash', receipt_number: i % 2 ? `F-${1000 + i}` : null, items_count: i % 2 ? 3 : 0, recurring_expense_id: null,
}));

const templates = Array.from({ length: 9 }, (_, i) => ({
  id: i + 1, name: ['Alquiler del local', 'Sueldo cajero', 'Sueldo cocina', 'Internet', 'Agua', 'Luz', 'Contador', 'Seguro', 'Software'][i],
  amount: 150 + i * 220, frequency: 'monthly', day_of_month: i + 1, start_month: '2026-01', is_active: true, payment_method: 'cash',
  expense_category_id: 5 + (i % 4), category: { id: 5 + (i % 4), name: CATS[4 + (i % 4)].name, section: 'operating' }, notes: null,
}));

const costs = {
  platform_rate: 0.3, reference_grams: 300,
  ingredients: [{ id: 1, name: 'Pulpa de açaí', unit: 'kg' }, { id: 2, name: 'Granola', unit: 'kg' }, { id: 3, name: 'Banana', unit: 'unidades' }],
  products: Array.from({ length: 12 }, (_, i) => ({
    id: i + 1, name: `Bowl ${['Hawaiian', 'Tropical', 'Fit', 'Choco', 'Berry', 'Mango', 'Coco', 'Maní', 'Frutilla', 'Kiwi', 'Piña', 'Clásico'][i]}`,
    price_local: 38 + i, price_pya: 45 + i, cost: 14 + i * 0.4, margin_local: 58 - i, margin_pya: 40 - i, profit_local: 22 + i, profit_pya: 14 + i,
    has_recipe: true, complete: true, missing: [], is_weight_based: false, recipe: [{ ingredient_id: 1, quantity: 0.12 }, { ingredient_id: 2, quantity: 0.03 }], variants: [],
  })),
  components: Array.from({ length: 6 }, (_, i) => ({
    id: i + 1, name: ['Base açaí 200 ml', 'Kit descartable', 'Topping granola', 'Salsa chocolate', 'Cuchara', 'Vaso'][i], unit: 'unidades', unit_cost: 2 + i,
    tracks_stock: i % 2 === 1, use_manual_cost: i % 2 === 0, composition: [], composition_complete: true, composition_cost: 0, from_purchases: false, used_in: 4,
  })),
  option_groups: [],
};

const breakEven = {
  period: { year: 2026, month: 10 },
  break_even: { amount: 9800, daily: 316, fixed_costs: 9420 },
  contribution: { based_on: 'current', percent: 62 },
  fixed: { total: 9420, items: [{ source: 'template', name: 'Alquiler', amount: 2500 }, { source: 'template', name: 'Sueldos', amount: 5200 }] },
  progress: { status: 'reached', reached_day: 14, days: 31, sales_to_date: 19800, percent: 100, closed: false, expected_to_date: 9000, elapsed_days: 20, reach_day: 14, projected_profit: 3800 },
  warnings: [],
};


const settlements = [
  { id: 1, week_start: '2026-10-05', week_end: '2026-10-11', gross: 1650, commission: 250, net: 1400, orders_count: 60, status: 'ok', checks: [] },
  { id: 2, week_start: '2026-10-12', week_end: '2026-10-18', gross: 1650, commission: 250, net: 1400, orders_count: 60, status: 'ok', checks: [] },
];

export async function mockFinanzas(page, { warnings = true } = {}) {
  await page.addInitScript(() => localStorage.setItem('auth_token', 'mock_owner_jwt'));
  await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
  const get = (pattern, body) => page.route(pattern, (r) => (r.request().method() === 'GET' ? r.fulfill(json(typeof body === 'function' ? body() : body)) : r.fulfill(json({ message: 'ok' }))));
  await get('**/api/me', ME);
  await get('**/api/inventory/reorder-summary', { count: 0, items: [] });
  await get('**/api/cash-sessions/active', { session: null, stats: null, sales_session_id: null });
  await get('**/api/finance/pnl*', () => pnl(warnings));
  await get('**/api/expense-categories', CATS);
  await get('**/api/finance/expenses*', { data: expenses, totals: { count: expenses.length, amount: expenses.reduce((s, e) => s + e.amount, 0), by_section: { cost_of_sales: 1200, operating: 900, financial: 100, taxes: 80 } } });
  await get('**/api/finance/recurring-expenses/pending*', { items: templates.slice(0, 6).map((t, i) => ({ id: t.id, name: t.name, amount: t.amount, day_of_month: t.day_of_month, status: i < 4 ? 'generated' : 'pending', category: t.category, expense: null })), pending_total: 800 });
  await get('**/api/finance/recurring-expenses', templates);
  await get('**/api/finance/cash-movements*', { data: Array.from({ length: 6 }, (_, i) => ({ id: i + 1, amount: 40 + i * 10, description: `Compra menor ${i + 1}`, created_at: '2026-10-08T10:00:00Z', expense_category_id: i % 2 ? null : 1, user: { name: 'Cajero' } })), count: 6, total: 390 });
  await get('**/api/finance/suppliers', [{ id: 1, name: 'Distribuidora Sur' }, { id: 2, name: 'Frutería Don Pepe' }]);
  await get('**/api/finance/costs', costs);
  await get('**/api/finance/break-even*', breakEven);
  await get('**/api/finance/inventory-indicators*', null);
  await get('**/api/finance/pedidosya-settlements*', settlements);
  await get('**/api/purchases/options', {
    categories: CATS.filter((c) => c.section === 'cost_of_sales'),
    suppliers: [{ id: 1, name: 'Distribuidora Sur' }, { id: 2, name: 'Frutería Don Pepe' }],
    ingredients: [
      { id: 1, name: 'Pulpa de açaí', unit: 'kg', purchase_unit: 'caja', purchase_factor: 10, supplier_id: 1 },
      { id: 2, name: 'Granola', unit: 'kg', purchase_unit: 'kg', purchase_factor: 1, supplier_id: null },
      { id: 3, name: 'Banana', unit: 'unidades', purchase_unit: 'unidades', purchase_factor: 1, supplier_id: 2 },
    ],
  });
}
