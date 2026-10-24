import { test, expect } from '@playwright/test';

/**
 * Inventario en la portátil de caja (1366 × 611 y 125 % ≈ 1093 × 489), escritorio y teléfono.
 * Plan: docs/PLAN_REDISENO_INVENTARIO_PORTATIL.md — Fase 1: modal de insumo (INV-1 a INV-3).
 */

const json = (body, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(body) });
const ME = {
  user: { id: 1, name: 'Dueño Prueba', email: 'dueno@example.com', role: 'super_admin' }, permissions: [],
  effective_permissions: { pos: 'edit', register_self: 'edit', delivery_ops: 'edit', crm_read: 'edit', voids: 'edit', register_others: 'edit', catalog: 'edit', inventory: 'edit', crm_config: 'edit', reports: 'edit', users: 'edit', audit: 'edit' },
};
const CATS = ['Frutas y pulpa', 'Lácteos', 'Toppings', 'Descartables', 'Café', 'Limpieza'].map((name, i) => ({ id: i + 1, name }));
const SUPS = ['Distribuidora Açaí Sur', 'Frutería Don Pepe', 'Packaging Bolivia', 'Lácteos Andinos'].map((name, i) => ({ id: i + 1, name, phone: `7000000${i}`, delivery_days: i, ingredients_count: 6 - i, expenses_count: 3 * i, is_active: i !== 3, notes: null }));
const NAMES = ['Pulpa de açaí', 'Granola', 'Leche en polvo', 'Leche condensada', 'Frutilla', 'Banana', 'Kiwi', 'Mango', 'Maracuyá', 'Chubi', 'Ositos de gomita', 'Coco rallado', 'Chispas de chocolate', 'Vaso 12 oz', 'Vaso 16 oz', 'Tapa domo', 'Cuchara', 'Servilleta', 'Café en grano', 'Leche entera', 'Azúcar', 'Miel', 'Mantequilla de maní', 'Bolsa kraft', 'Detergente'];
const ING = NAMES.map((name, i) => ({
  id: i + 1, name, unit: i < 13 ? 'kg' : 'unidades', current_stock: (i * 3.7 % 40).toFixed(2), minimum_stock: 5, unit_cost: (8 + i).toFixed(2), weighted_avg_cost: (8.4 + i).toFixed(2),
  type: ['perecedero', 'no_perecedero', 'material_empaque', 'otros'][i % 4], category: CATS[i % 6], ingredient_category_id: CATS[i % 6].id, supplier: SUPS[i % 4], supplier_id: SUPS[i % 4].id,
  is_key: i % 3 === 0, reorder_policy: 'reorder', target_days: 7, safety_days: 2, purchase_unit: 'caja', purchase_factor: 10, lot_size: '', daily_estimate: 2, shelf_life_days: 10, expiration_date: '',
}));
const STAT = ['ok', 'reorder', 'critical', 'out', 'nodata', 'ok'];
const REPORT = { summary: { to_reorder: 6, expiring: 2, estimated_cost: 640 }, items: ING.map((g, i) => ({ id: g.id, status: STAT[i % 6], reorder_point: 6.5, days_of_stock: 3.2, expiry: { level: i % 9 === 0 ? 'soon' : null, date: '2026-10-28' } })) };

const SIZES = [
  { width: 1366, height: 611, desc: 'portátil 100 %', bodyScrollMax: 2 },
  { width: 1093, height: 489, desc: 'portátil 125 %', bodyScrollMax: 120 },
  { width: 1440, height: 900, desc: 'escritorio', bodyScrollMax: 2 },
  { width: 390, height: 844, desc: 'teléfono', bodyScrollMax: Infinity },
];

async function setup(page, viewport = { width: 1366, height: 611 }) {
  await page.setViewportSize(viewport);
  await page.addInitScript(() => localStorage.setItem('auth_token', 'mock'));
  await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
  await page.route('**/api/me', (r) => r.fulfill(json(ME)));
  await page.route('**/api/ingredients?**', (r) => r.fulfill(json({ data: ING })));
  await page.route('**/api/ingredient-categories', (r) => r.fulfill(json({ data: CATS })));
  await page.route('**/api/suppliers', (r) => r.fulfill(json({ data: SUPS })));
  await page.route('**/api/inventory/reorder', (r) => r.fulfill(json(REPORT)));
  await page.route('**/api/inventory/reorder/summary', (r) => r.fulfill(json(REPORT.summary)));
  await page.goto('/inventario');
  await page.waitForSelector('.oh-table tbody tr');
}

const modal = (page) => page.locator('.inv-modal-host .fin-modal');
const saveBtn = (page) => page.locator('.inv-modal-host .fin-modal-foot .fin-btn-primary');
const openNew = (page) => page.locator('.inv-new').click();
const openEdit = (page, row = 0) => page.locator('.oh-table tbody tr').nth(row).locator('button[aria-label^="Editar "]').click();

/** Un botón está «al alcance» si está dentro de la pantalla y nada lo tapa. */
const reachable = (page, selector) => page.evaluate((sel) => {
  const b = document.querySelector(sel);
  if (!b) return false;
  const r = b.getBoundingClientRect();
  const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
  return r.top >= 0 && r.bottom <= innerHeight && (el === b || b.contains(el));
}, selector);

// ───────────── INV-1 / INV-2: el modal entra en pantalla en los cuatro tamaños ─────────────
for (const vp of SIZES) {
  test.describe(`${vp.desc} ${vp.width}×${vp.height}`, () => {
    test.beforeEach(async ({ page }) => { await setup(page, { width: vp.width, height: vp.height }); });

    for (const mode of ['nuevo', 'editar']) {
      test(`INV-1/2. Modal de insumo (${mode}): título, ✕ y Guardar a la vista`, async ({ page }) => {
        if (mode === 'nuevo') await openNew(page); else await openEdit(page, 1);
        await expect(modal(page)).toBeVisible();
        const m = await page.evaluate(() => {
          const box = document.querySelector('.inv-modal-host .fin-modal').getBoundingClientRect();
          const head = document.querySelector('.inv-modal-host .fin-modal-head h2').getBoundingClientRect();
          const body = document.querySelector('.inv-modal-host .fin-modal-body');
          return { top: box.top, bottom: box.bottom, headTop: head.top, vh: innerHeight, scroll: body.scrollHeight - body.clientHeight };
        });
        expect(m.top, 'el modal no se corta arriba').toBeGreaterThanOrEqual(0);
        expect(m.bottom, 'el modal no se corta abajo').toBeLessThanOrEqual(m.vh);
        expect(m.headTop).toBeGreaterThanOrEqual(0);
        expect(await reachable(page, '.inv-modal-host .fin-modal-foot .fin-btn-primary'), 'Guardar al alcance').toBe(true);
        expect(await reachable(page, '.inv-modal-host .fin-modal-head .fin-icon-btn'), '✕ al alcance').toBe(true);
        expect(m.scroll, 'scroll interno del cuerpo').toBeLessThanOrEqual(vp.bodyScrollMax);
      });
    }
  });
}

// ───────────── INV-3 y comportamiento ─────────────
test.describe('Modal de insumo: comportamiento', () => {
  test.beforeEach(async ({ page }) => { await setup(page); });

  test('Tres columnas por tarea a 1366 y foco inicial en Nombre', async ({ page }) => {
    await openNew(page);
    await expect(page.locator('.inv-col-title')).toHaveText(['Insumo', 'Compra', 'Reposición']);
    const tops = await page.locator('.inv-col').evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().top)));
    expect(new Set(tops).size, 'las tres columnas en la misma fila').toBe(1);
    await expect(page.locator('#inv-name')).toBeFocused();
    await expect(page.locator('.inv-modal-host .fin-modal-head h2')).toHaveText('Nuevo insumo');
  });

  test('INV-3. Escape y ✕ cierran el modal', async ({ page }) => {
    await openNew(page);
    await page.keyboard.press('Escape');
    await expect(modal(page)).toHaveCount(0);
    await openNew(page);
    await page.locator('.inv-modal-host .fin-modal-head .fin-icon-btn').click();
    await expect(modal(page)).toHaveCount(0);
  });

  test('Guardar crea el insumo con los datos del formulario y Enter también guarda', async ({ page }) => {
    const posts = [];
    await page.route('**/api/ingredients', async (r) => {
      if (r.request().method() !== 'POST') return r.fallback();
      posts.push(JSON.parse(r.request().postData()));
      return r.fulfill(json({ data: { id: 99 } }, 201));
    });
    await openNew(page);
    await page.locator('#inv-name').fill('Pulpa de mango');
    await page.locator('#inv-type').selectOption('no_perecedero');
    await page.locator('#inv-cat').selectOption({ label: 'Frutas y pulpa' });
    await page.locator('#inv-sup').selectOption({ label: 'Frutería Don Pepe' });
    await page.locator('#inv-punit').fill('caja');
    await expect(page.locator('label[for="inv-factor"]')).toHaveText('kg por caja');
    await page.locator('#inv-factor').fill('10');
    await page.locator('#inv-daily').fill('1.5');
    await page.locator('#inv-shelf').press('Enter');
    await expect(modal(page)).toHaveCount(0);
    expect(posts).toHaveLength(1);
    expect(posts[0]).toMatchObject({ name: 'Pulpa de mango', type: 'no_perecedero', ingredient_category_id: 1, supplier_id: 2, purchase_unit: 'caja', purchase_factor: 10, daily_estimate: 1.5 });
  });

  test('Con un error del servidor: el campo queda marcado, recibe el foco y Guardar sigue a la vista (1093×489)', async ({ page }) => {
    await page.setViewportSize({ width: 1093, height: 489 });
    await page.route('**/api/ingredients', (r) => (r.request().method() === 'POST'
      ? r.fulfill(json({ message: 'Datos inválidos', errors: { shelf_life_days: ['La vida útil debe ser al menos 1.'] } }, 422))
      : r.fallback()));
    await openNew(page);
    await page.locator('#inv-name').fill('Algo');
    await saveBtn(page).click();
    await expect(page.locator('#inv-shelf')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#inv-shelf-err')).toHaveText('La vida útil debe ser al menos 1.');
    await expect(page.locator('#inv-shelf')).toBeFocused();
    expect(await reachable(page, '.inv-modal-host .fin-modal-foot .fin-btn-primary')).toBe(true);
    await expect(modal(page)).toBeVisible();
  });

  test('Mientras guarda no se puede enviar dos veces ni cerrar', async ({ page }) => {
    let calls = 0;
    let release;
    const gate = new Promise((res) => { release = res; });
    await page.route('**/api/ingredients', async (r) => {
      if (r.request().method() !== 'POST') return r.fallback();
      calls += 1;
      await gate;
      return r.fulfill(json({ data: { id: 1 } }, 201));
    });
    await openNew(page);
    await page.locator('#inv-name').fill('Algo');
    await saveBtn(page).click();
    await expect(saveBtn(page)).toHaveText('Guardando…');
    await expect(saveBtn(page)).toBeDisabled();
    await page.keyboard.press('Escape');
    await expect(modal(page)).toBeVisible();
    release();
    await expect(modal(page)).toHaveCount(0);
    expect(calls).toBe(1);
  });

  test('Editar: título con el nombre, stock bloqueado con su explicación y PUT al insumo', async ({ page }) => {
    let put = null;
    await page.route('**/api/ingredients/2', (r) => {
      if (r.request().method() !== 'PUT') return r.fallback();
      put = JSON.parse(r.request().postData());
      return r.fulfill(json({ data: { id: 2 } }));
    });
    await openEdit(page, 1);
    await expect(page.locator('.inv-modal-host .fin-modal-head h2')).toHaveText('Editar Granola');
    await expect(page.locator('#inv-stock')).toBeDisabled();
    await expect(page.locator('#inv-stock')).toHaveAttribute('aria-describedby', 'inv-stock-hint');
    await expect(page.locator('#inv-stock-hint')).toContainText('Ingreso, Merma o Conteo');
    await page.locator('#inv-target').fill('10');
    await saveBtn(page).click();
    await expect(modal(page)).toHaveCount(0);
    expect(put).toMatchObject({ id: 2, name: 'Granola', target_days: 10 });
  });
});

test('El tipo «No perecedero» se lee completo en su selector (1366 y 1093)', async ({ page }) => {
  for (const [w, h] of [[1366, 611], [1093, 489]]) {
    await setup(page, { width: w, height: h });
    await openEdit(page, 1);
    const fits = await page.locator('#inv-type').evaluate((el) => {
      const probe = document.createElement('span');
      const cs = getComputedStyle(el);
      probe.style.cssText = `position:fixed;visibility:hidden;white-space:nowrap;font:${cs.font}`;
      probe.textContent = el.options[el.selectedIndex].text;
      document.body.appendChild(probe);
      const textW = probe.getBoundingClientRect().width;
      probe.remove();
      const pad = parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight) + 22; // flecha del selector
      return el.clientWidth >= textW + pad;
    });
    expect(fits, `${w}×${h}`).toBe(true);
  }
});

// ───────────── Fase 2: Categorías, Proveedores, confirmación y aviso ─────────────
/** Proveedores y Categorías están en el menú «Más» de la cabecera. */
const openAdmin = async (page, label) => {
  await page.locator('.inv-more-btn').click();
  await page.getByRole('menuitem', { name: label }).click();
};
const adminModal = (page) => page.locator('.inv-modal-host .fin-modal').first();

for (const vp of SIZES) {
  test(`INV-4. ${vp.desc} ${vp.width}×${vp.height}: Categorías y Proveedores entran en pantalla con el pie visible`, async ({ page }) => {
    await setup(page, { width: vp.width, height: vp.height });
    for (const label of ['Categorías', 'Proveedores']) {
      await openAdmin(page, label);
      await expect(adminModal(page)).toBeVisible();
      const m = await page.evaluate(() => { const r = document.querySelector('.inv-modal-host .fin-modal').getBoundingClientRect(); return { top: r.top, bottom: r.bottom, vh: innerHeight }; });
      expect(m.top, `${label}: no se corta arriba`).toBeGreaterThanOrEqual(0);
      expect(m.bottom, `${label}: no se corta abajo`).toBeLessThanOrEqual(m.vh);
      expect(await reachable(page, '.inv-modal-host .fin-modal-foot .fin-btn'), `${label}: Cerrar al alcance`).toBe(true);
      expect(await reachable(page, '.inv-modal-host .fin-modal-foot .fin-btn-primary'), `${label}: Crear al alcance`).toBe(true);
      await page.keyboard.press('Escape');
      await expect(page.locator('.inv-modal-host')).toHaveCount(0);
    }
  });
}

test.describe('Fase 2: comportamiento', () => {
  test.beforeEach(async ({ page }) => { await setup(page); });

  test('Categorías: crear envía los datos; editar marca la fila y se puede cancelar', async ({ page }) => {
    const sent = [];
    await page.route('**/api/ingredient-categories**', (r) => {
      const m = r.request().method();
      if (m === 'POST' || m === 'PUT') { sent.push([m, r.request().url(), JSON.parse(r.request().postData())]); return r.fulfill(json({ data: { id: 9 } }, 201)); }
      return r.fulfill(json({ data: CATS }));
    });
    await openAdmin(page, 'Categorías');
    await page.locator('#inv-catf-name').fill('Bebidas');
    await page.locator('#inv-catf-name').press('Enter');
    await expect.poll(() => sent.length).toBe(1);
    expect(sent[0][0]).toBe('POST');
    expect(sent[0][2]).toMatchObject({ name: 'Bebidas' });
    await page.locator('.inv-admin-row', { hasText: 'Lácteos' }).locator('button[aria-label="Editar Lácteos"]').click();
    await expect(page.locator('.inv-admin-form .inv-col-title')).toHaveText('Editar «Lácteos»');
    await expect(page.locator('.inv-admin-row.is-editing')).toHaveText(/Lácteos/);
    await expect(page.locator('#inv-catf-name')).toBeFocused();
    await page.locator('.inv-modal-host .fin-modal-foot button', { hasText: 'Cancelar edición' }).click();
    await expect(page.locator('.inv-admin-form .inv-col-title')).toHaveText('Nueva categoría');
  });

  test('Los errores de Categorías no aparecen en el formulario de insumo', async ({ page }) => {
    await page.route('**/api/ingredient-categories', (r) => (r.request().method() === 'POST'
      ? r.fulfill(json({ message: 'Datos inválidos', errors: { name: ['El nombre ya existe.'] } }, 422))
      : r.fulfill(json({ data: CATS }))));
    await openAdmin(page, 'Categorías');
    await page.locator('#inv-catf-name').fill('Lácteos');
    await page.locator('.inv-modal-host .fin-modal-foot .fin-btn-primary').click();
    await expect(page.locator('#inv-catf-name-err')).toHaveText('El nombre ya existe.');
    await expect(page.locator('#inv-catf-name')).toHaveAttribute('aria-invalid', 'true');
    await page.keyboard.press('Escape');
    await openNew(page);
    await expect(page.locator('#inv-name')).not.toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#inv-name-err')).toHaveCount(0);
  });

  test('Proveedores: crear con días de entrega como número', async ({ page }) => {
    let body = null;
    await page.route('**/api/suppliers', (r) => {
      if (r.request().method() === 'POST') { body = JSON.parse(r.request().postData()); return r.fulfill(json({ data: { id: 7 } }, 201)); }
      return r.fulfill(json({ data: SUPS }));
    });
    await openAdmin(page, 'Proveedores');
    await expect(page.locator('.inv-admin-row')).toHaveCount(4);
    await page.locator('#inv-supf-name').fill('Mercado Rodríguez');
    await page.locator('#inv-supf-days').fill('0');
    await page.locator('#inv-supf-order').fill('martes');
    await page.locator('.inv-modal-host .fin-modal-foot .fin-btn-primary').click();
    await expect.poll(() => body).not.toBeNull();
    expect(body).toMatchObject({ name: 'Mercado Rodríguez', delivery_days: 0, order_days: 'martes', is_active: true });
  });

  test('Eliminar insumo: dice qué se borra, foco en Cancelar y espera a que termine', async ({ page }) => {
    let release; const gate = new Promise((res) => { release = res; });
    let deleted = 0;
    await page.route('**/api/ingredients/2', async (r) => {
      if (r.request().method() !== 'DELETE') return r.fallback();
      deleted += 1; await gate; return r.fulfill(json({ ok: true }));
    });
    await page.locator('.oh-table tbody tr').nth(1).locator('button[aria-label^="Eliminar "]').click();
    const dlg = page.locator('.inv-modal-host .fin-modal');
    await expect(dlg.locator('h2')).toHaveText('Eliminar insumo');
    await expect(dlg).toContainText('¿Eliminar «Granola» del catálogo de insumos?');
    await expect(page.locator('#inv-confirm-cancel')).toBeFocused();
    await dlg.locator('.fin-btn-danger').click();
    await expect(dlg.locator('.fin-btn-danger')).toHaveText('Eliminando…');
    await page.keyboard.press('Escape');
    await expect(dlg).toBeVisible();
    release();
    await expect(page.locator('.inv-modal-host')).toHaveCount(0);
    expect(deleted).toBe(1);
  });

  test('Si falla un borrado desde Categorías, el aviso queda encima y Escape cierra solo el de arriba', async ({ page }) => {
    await page.route('**/api/ingredient-categories/2', (r) => (r.request().method() === 'DELETE'
      ? r.fulfill(json({ message: 'La categoría tiene insumos.' }, 409))
      : r.fallback()));
    await openAdmin(page, 'Categorías');
    await page.locator('button[aria-label="Eliminar Lácteos"]').click();
    await expect(page.locator('.inv-modal-host .fin-modal h2').last()).toHaveText('Eliminar categoría');
    await page.locator('.inv-modal-host .fin-btn-danger').click();
    const top = page.locator('.inv-modal-host .fin-modal').last();
    await expect(top.locator('h2')).toHaveText('No se pudo eliminar');
    await expect(top).toContainText('La categoría tiene insumos.');
    await expect(page.locator('#inv-alert-ok')).toBeFocused();
    const onTop = await page.evaluate(() => {
      const m = [...document.querySelectorAll('.fin-modal')].pop().getBoundingClientRect();
      const el = document.elementFromPoint(m.left + m.width / 2, m.top + 12);
      return [...document.querySelectorAll('.fin-modal')].pop().contains(el);
    });
    expect(onTop, 'el aviso está por encima de Categorías').toBe(true);
    await page.keyboard.press('Escape');
    await expect(page.locator('.inv-modal-host .fin-modal h2')).toHaveText(['Categorías de insumos']);
  });
});

// ───────────── Fase 3: cabecera ─────────────
const toolbarTops = (page) => page.evaluate(() => [...document.querySelectorAll('.inv-daily .inv-hbtn, .inv-more-btn, .inv-new')]
  .filter((b) => b.offsetParent !== null).map((b) => Math.round(b.getBoundingClientRect().top)));
const tableTop = (page) => page.evaluate(() => Math.round(document.querySelector('.table-container').getBoundingClientRect().top - document.querySelector('.oh-shell-content').getBoundingClientRect().top));

for (const vp of [{ width: 1366, height: 611, max: 150, short: false }, { width: 1093, height: 489, max: 160, short: true }]) {
  test(`INV-5. ${vp.width}×${vp.height}: acciones en una línea y la tabla empieza a ≤ ${vp.max} px`, async ({ page }) => {
    await setup(page, { width: vp.width, height: vp.height });
    const tops = await toolbarTops(page);
    expect(tops).toHaveLength(6);
    expect(Math.max(...tops) - Math.min(...tops), 'todas en la misma línea').toBeLessThanOrEqual(2);
    expect(await tableTop(page)).toBeLessThanOrEqual(vp.max);
    const merma = page.getByRole('button', { name: 'Registrar merma', exact: true });
    await expect(merma).toBeVisible();
    expect((await merma.innerText()).trim(), 'texto visible').toBe(vp.short ? 'Merma' : 'Registrar merma');
    await expect(page.getByRole('button', { name: 'Compras sugeridas: 6 por reponer', exact: true })).toBeVisible();
    const emoji = await page.locator('.inventory-header, .filters-bar').evaluateAll((els) => els.some((e) => /\p{Extended_Pictographic}/u.test(e.textContent)));
    expect(emoji, 'sin emojis en la cabecera').toBe(false);
  });
}

test.describe('Menú «Más»', () => {
  test.beforeEach(async ({ page }) => { await setup(page); });

  test('Se abre con el foco en la primera opción; las flechas recorren; Escape cierra y devuelve el foco', async ({ page }) => {
    const btn = page.locator('.inv-more-btn');
    await expect(btn).toHaveAttribute('aria-expanded', 'false');
    await btn.click();
    await expect(btn).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByRole('menuitem', { name: 'Proveedores' })).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('menuitem', { name: 'Categorías' })).toBeFocused();
    await page.keyboard.press('End');
    await expect(page.getByRole('menuitem', { name: 'Mermas del mes' })).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('menuitem', { name: 'Proveedores' })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('menu')).toHaveCount(0);
    await expect(btn).toBeFocused();
  });

  test('Flecha abajo sobre el botón lo abre; un clic fuera lo cierra', async ({ page }) => {
    await page.locator('.inv-more-btn').focus();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('menu')).toBeVisible();
    await page.locator('.inv-title h2').click();
    await expect(page.getByRole('menu')).toHaveCount(0);
  });

  test('Mermas del mes abre el reporte', async ({ page }) => {
    await page.locator('.inv-more-btn').click();
    await page.getByRole('menuitem', { name: 'Mermas del mes' }).click();
    await expect(page.getByRole('menu')).toHaveCount(0);
    await expect(page.locator('.fin-modal')).toBeVisible();
  });
});

test('Teléfono: cabecera compacta, acciones del día deslizables y botones de 44 px', async ({ page }) => {
  await setup(page, { width: 390, height: 844 });
  const h = await page.locator('.inventory-header').evaluate((e) => e.getBoundingClientRect().height);
  expect(h).toBeLessThanOrEqual(130);
  const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, vw: innerWidth }));
  expect(m.sw).toBeLessThanOrEqual(m.vw);
  const sizes = await page.locator('.inv-daily .inv-hbtn, .inv-more-btn, .inv-new').evaluateAll((els) => els.map((e) => e.getBoundingClientRect().height));
  for (const s of sizes) expect(s).toBeGreaterThanOrEqual(44);
  expect((await page.locator('.inv-new').innerText()).trim()).toBe('Nuevo');
  await page.locator('.inv-more-btn').click();
  const menu = await page.getByRole('menu').boundingBox();
  expect(menu.x).toBeGreaterThanOrEqual(0);
  expect(menu.x + menu.width).toBeLessThanOrEqual(390);
});


// ───────────── Fase 4: tabla ─────────────
const firstScreenRows = (page) => page.evaluate(() => {
  const sc = document.querySelector('.oh-shell-content').getBoundingClientRect();
  return [...document.querySelectorAll('.oh-table tbody tr')].filter((tr) => tr.getBoundingClientRect().bottom <= sc.bottom + 1).length;
});

for (const vp of [
  { width: 1366, height: 611, rows: 6, maxRow: 64 },
  { width: 1093, height: 489, rows: 3, maxRow: 64 },
  { width: 1440, height: 900, rows: 10, maxRow: 64 },
]) {
  test(`INV-6/7/9. ${vp.width}×${vp.height}: ≥ ${vp.rows} filas a la vista, filas ≤ ${vp.maxRow} px y herramientas en una línea`, async ({ page }) => {
    await setup(page, { width: vp.width, height: vp.height });
    expect(await firstScreenRows(page), 'filas visibles sin desplazar').toBeGreaterThanOrEqual(vp.rows);
    const rows = await page.locator('.oh-table tbody tr').evaluateAll((els) => els.slice(0, 8).map((e) => e.getBoundingClientRect().height));
    for (const h of rows) expect(h).toBeLessThanOrEqual(vp.maxRow);
    const tools = await page.locator('.oh-table tbody tr').first().locator('.inv-tool').evaluateAll((els) => els.map((e) => { const r = e.getBoundingClientRect(); return { top: Math.round(r.top), w: r.width, h: r.height, label: e.getAttribute('aria-label') }; }));
    expect(tools).toHaveLength(6);
    expect(new Set(tools.map((t) => t.top)).size, 'una sola línea').toBe(1);
    for (const t of tools) { expect(t.w).toBeGreaterThanOrEqual(32); expect(t.h).toBeGreaterThanOrEqual(32); expect(t.label).toContain('Pulpa de açaí'); }
    const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, vw: innerWidth, tw: document.querySelector('.table-container').scrollWidth, cw: document.querySelector('.table-container').clientWidth }));
    expect(m.sw, 'sin desborde de la ventana').toBeLessThanOrEqual(m.vw);
    expect(m.tw, 'la tabla cabe sin desplazarse a lo ancho').toBeLessThanOrEqual(m.cw);
  });
}

test('INV-8. Teléfono: la tabla se desliza dentro de su contenedor y el nombre queda fijo; herramientas de 44 px', async ({ page }) => {
  await setup(page, { width: 390, height: 844 });
  const m = await page.evaluate(() => { const t = document.querySelector('.table-container'); return { overflowX: getComputedStyle(t).overflowX, sw: t.scrollWidth, cw: t.clientWidth, docW: document.documentElement.scrollWidth, vw: innerWidth }; });
  expect(m.overflowX).toBe('auto');
  expect(m.sw).toBeGreaterThan(m.cw);
  expect(m.docW).toBeLessThanOrEqual(m.vw);
  const before = await page.locator('.inv-cell-name').first().boundingBox();
  await page.locator('.table-container').evaluate((t) => { t.scrollLeft = 400; });
  const after = await page.locator('.inv-cell-name').first().boundingBox();
  expect(Math.abs(after.x - before.x), 'el nombre no se mueve al deslizar').toBeLessThanOrEqual(1);
  const sizes = await page.locator('.oh-table tbody tr').first().locator('.inv-tool').evaluateAll((els) => els.map((e) => Math.min(e.getBoundingClientRect().width, e.getBoundingClientRect().height)));
  for (const s of sizes) expect(s).toBeGreaterThanOrEqual(44);
});

test.describe('Fase 4: contenido de la tabla', () => {
  test.beforeEach(async ({ page }) => { await setup(page); });

  test('Tipo legible, categoría como píldora, costo y promedio en una columna', async ({ page }) => {
    const row = page.locator('.oh-table tbody tr').nth(1);
    await expect(row.locator('.inv-type')).toHaveText('No perecedero');
    await expect(row.locator('.inv-cat-chip')).toHaveText('Lácteos');
    await expect(row.locator('.inv-cost')).toHaveText('Bs 9,00');
    await expect(row.locator('.inv-avg')).toHaveText('prom. 9,40');
    await expect(page.locator('.oh-table')).not.toContainText('no_perecedero');
    await expect(page.locator('.oh-table')).not.toContainText('⚠');
  });

  test('Nivel de stock y franja: solo para lo que pide atención, sin teñir la fila', async ({ page }) => {
    const rows = page.locator('.oh-table tbody tr');
    await expect(rows.nth(0)).not.toHaveClass(/tone-/);           // OK
    await expect(rows.nth(1)).toHaveClass(/tone-warn/);           // Reordenar
    await expect(rows.nth(2)).toHaveClass(/tone-err/);            // Crítico
    await expect(rows.nth(4).locator('.inv-level')).toHaveCount(0); // Sin datos
    const fill = await rows.nth(1).locator('.inv-level-fill').evaluate((e) => parseFloat(e.style.width));
    expect(fill).toBeCloseTo((3.7 / 13) * 100, 2);
    const bg = await rows.nth(2).evaluate((tr) => getComputedStyle(tr).backgroundColor);
    expect(bg === 'rgba(0, 0, 0, 0)' || bg === 'transparent', 'la fila no se tiñe').toBe(true);
  });

  test('125 %: la categoría pasa a la línea del insumo', async ({ page }) => {
    await page.setViewportSize({ width: 1093, height: 489 });
    await expect(page.locator('thead th.col-cat')).toBeHidden();
    await expect(page.locator('.oh-table tbody tr').nth(1).locator('.inv-meta')).toHaveText(/Lácteos · kg · Frutería Don Pepe/);
  });

  test('Las herramientas abren lo suyo con el insumo correcto', async ({ page }) => {
    await page.locator('button[aria-label="Editar Granola"]').click();
    await expect(page.locator('.inv-modal-host .fin-modal-head h2')).toHaveText('Editar Granola');
    await page.keyboard.press('Escape');
    await page.locator('button[aria-label="Historial de Granola"]').click();
    await expect(page.locator('.fin-modal').last()).toContainText('Granola');
  });
});

test('INV-10. Sin tokens inexistentes ni colores fijos en la pantalla de Inventario', async ({ page }) => {
  await setup(page);
  const bad = await page.evaluate(() => {
    const out = [];
    for (const sheet of [...document.styleSheets]) {
      let rules; try { rules = [...sheet.cssRules]; } catch { continue; }
      for (const r of rules) {
        const t = r.cssText || '';
        if (!/inv-|oh-table|inventory-|rp-|filters-bar|search-box/.test(t)) continue;
        if (/--ink-400|--danger-300|--danger-50\b|--danger-pale|#e3f2fd|#f3e5f5|#f59e0b/i.test(t)) out.push(t.slice(0, 120));
      }
    }
    return out;
  });
  expect(bad).toEqual([]);
});

// ───────────── Registrar compra: sin valores por defecto engañosos ─────────────
const PURCHASE_OPTIONS = {
  categories: [{ id: 1, name: 'Helado y açaí' }, { id: 2, name: 'Insumos' }, { id: 3, name: 'Gas y limpieza' }],
  suppliers: SUPS.map((s) => ({ id: s.id, name: s.name })),
  ingredients: ING.slice(0, 5).map((g) => ({ id: g.id, name: g.name, unit: g.unit, purchase_unit: 'caja', purchase_factor: 10, supplier_id: g.supplier_id })),
};

test.describe('Registrar compra (desde Inventario)', () => {
  test.beforeEach(async ({ page }) => {
    await setup(page);
    await page.route('**/api/purchases/options', (r) => r.fulfill(json(PURCHASE_OPTIONS)));
  });

  test('Desde la cabecera: la línea pide elegir insumo y la categoría empieza vacía', async ({ page }) => {
    await page.locator('.inv-daily .inv-hbtn', { hasText: 'Registrar compra' }).click();
    const ing = page.locator('.pm-line select').first();
    await expect(ing).toBeVisible();
    await expect(ing.locator('option:checked')).toHaveText('Elige un insumo…');
    await expect(page.locator('#pm-cat option:checked')).toHaveText('Elige una categoría…');
    await expect(page.locator('.pm-line label', { hasText: 'Se compra en' })).toHaveCount(0);
    await expect(page.locator('.pm-line label', { hasText: 'Concepto' })).toHaveCount(0);
    // Guardar sin elegir dice qué falta
    await page.locator('.fin-modal-foot .fin-btn-primary').click();
    await expect(page.locator('.fin-note.err')).toHaveText('Elige una categoría.');
    await page.locator('#pm-cat').selectOption({ label: 'Insumos' });
    await page.locator('.fin-modal-foot .fin-btn-primary').click();
    await expect(page.locator('.fin-note.err')).toHaveText('Línea 1: elige un insumo, u «Otro gasto» si no es del inventario.');
  });

  test('Elegir un insumo muestra cómo se compra; «Otro gasto» pide el concepto', async ({ page }) => {
    await page.locator('.inv-daily .inv-hbtn', { hasText: 'Registrar compra' }).click();
    const ing = page.locator('.pm-line select').first();
    await ing.selectOption({ label: 'Granola (kg)' });
    await expect(page.locator('.pm-line label', { hasText: 'Se compra en' })).toBeVisible();
    await ing.selectOption({ label: 'Otro gasto (gas, limpieza, bolsas…)' });
    await expect(page.locator('.pm-line label', { hasText: 'Concepto' })).toBeVisible();
    await expect(page.locator('.pm-line label', { hasText: 'Se compra en' })).toHaveCount(0);
  });

  test('Guarda lo elegido: insumo, categoría y gasto suelto', async ({ page }) => {
    let body = null;
    await page.route('**/api/purchases', (r) => {
      if (r.request().method() !== 'POST') return r.fallback();
      body = JSON.parse(r.request().postData());
      return r.fulfill(json({ id: 1, warnings: [] }, 201));
    });
    await page.locator('.inv-daily .inv-hbtn', { hasText: 'Registrar compra' }).click();
    const lines = page.locator('.pm-line');
    await lines.nth(0).locator('select').selectOption({ label: 'Granola (kg)' });
    await lines.nth(0).locator('input[id^="pm-qty-"]').fill('2');
    await lines.nth(0).locator('input[id^="pm-total-"]').fill('90');
    await page.locator('.pm-lines .fin-btn', { hasText: 'Agregar otra línea' }).click();
    await expect(lines.nth(1).locator('select option:checked')).toHaveText('Elige un insumo…');
    await lines.nth(1).locator('select').selectOption({ label: 'Otro gasto (gas, limpieza, bolsas…)' });
    await lines.nth(1).locator('input[id^="pm-desc-"]').fill('Bolsas');
    await lines.nth(1).locator('input[id^="pm-qty-"]').fill('1');
    await lines.nth(1).locator('input[id^="pm-total-"]').fill('15');
    await page.locator('#pm-cat').selectOption({ label: 'Insumos' });
    await page.locator('.fin-modal-foot .fin-btn-primary').click();
    await expect.poll(() => body).not.toBeNull();
    expect(body.expense_category_id).toBe(2);
    expect(body.items).toEqual([
      expect.objectContaining({ ingredient_id: 2, description: null, quantity: 2, total: 90 }),
      expect.objectContaining({ ingredient_id: null, description: 'Bolsas', quantity: 1, total: 15 }),
    ]);
  });

  test('Desde la fila de un insumo: la línea ya viene con ese insumo', async ({ page }) => {
    await page.locator('button[aria-label="Registrar compra de Granola"]').click();
    await expect(page.locator('.pm-line select').first().locator('option:checked')).toHaveText('Granola (kg)');
    await expect(page.locator('#pm-cat option:checked')).toHaveText('Elige una categoría…');
  });
});

// ───────────── Fase 5: teclado en la Toshiba (foco dentro de la ventana) y nombres largos ─────────────
test.describe('Fase 5 — teclado y nombres largos (125 %)', () => {
  const focusId = (page) => page.evaluate(() => document.activeElement?.id || document.activeElement?.getAttribute('aria-label') || document.activeElement?.className || '');
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/purchases/options', (r) => r.fulfill(json(PURCHASE_OPTIONS)));
    await setup(page, { width: 1093, height: 489 });
  });

  test('Registrar compra: el foco entra a «Insumo» y Escape lo devuelve al botón', async ({ page }) => {
    const btn = page.locator('.inv-daily .inv-hbtn', { hasText: 'Registrar compra' });
    await btn.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('select[id^="pm-ing-"]').first()).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(btn).toBeFocused();
  });

  test('Desde la fila: el foco entra a «Cantidad»', async ({ page }) => {
    await page.locator('button[aria-label="Registrar compra de Granola"]').click();
    await expect(page.locator('input[id^="pm-qty-"]').first()).toBeFocused();
  });

  test('Tab y Mayús+Tab no salen de la ventana', async ({ page }) => {
    await page.locator('.inv-daily .inv-hbtn', { hasText: 'Registrar compra' }).click();
    await expect(page.locator('select[id^="pm-ing-"]').first()).toBeFocused();
    for (let k = 0; k < 25; k++) {
      await page.keyboard.press('Tab');
      expect(await page.evaluate(() => document.querySelector('.fin-modal').contains(document.activeElement)), `Tab ${k + 1}: ${await focusId(page)}`).toBe(true);
    }
    await page.locator('.fin-modal-head .fin-icon-btn').focus();
    await page.keyboard.press('Shift+Tab');
    await expect(page.locator('.fin-modal-foot .fin-btn-primary')).toBeFocused();
  });

  test('Más → Categorías: foco en «Nombre»; al cerrar vuelve a «Más»', async ({ page }) => {
    await page.locator('.inv-more-btn').click();
    await page.getByRole('menuitem', { name: /Categorías/ }).click();
    await expect(page.locator('#inv-catf-name')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.locator('.inv-more-btn')).toBeFocused();
  });

  test('Compras sugeridas (revisión): el foco entra a la ventana sin tocar cantidades', async ({ page }) => {
    await page.locator('.inv-daily .inv-hbtn').first().click();
    await expect(page.locator('.fin-modal')).toBeFocused();
  });

  test('Compras sugeridas (ergonomía portátil): franja KPI ≤ 42 px, cantidades alineadas sin wrap y ≥ 4 insumos visibles a 1093×489', async ({ page }) => {
    await setup(page, { width: 1093, height: 489 });
    await page.locator('.inv-daily .inv-hbtn', { hasText: 'Compras sugeridas' }).click();
    const modal = page.locator('.fin-modal');
    await expect(modal).toBeVisible();
    await expect(page.locator('.rm-kpis')).toBeVisible();

    const m = await page.evaluate(() => {
      const kpis = document.querySelector('.rm-kpis')?.getBoundingClientRect();
      const body = document.querySelector('.fin-modal-body')?.getBoundingClientRect();
      const rows = [...document.querySelectorAll('.rm-table tbody tr')];
      const visibleRows = rows.filter((r) => {
        const rect = r.getBoundingClientRect();
        return rect.top >= body.top && rect.bottom <= body.bottom + 2;
      });

      const qtyAlignment = [...document.querySelectorAll('.rm-qty')].map((q) => {
        const input = q.querySelector('.fin-input').getBoundingClientRect();
        const unit = q.querySelector('.rm-unit').getBoundingClientRect();
        const inputCenter = input.top + input.height / 2;
        const unitCenter = unit.top + unit.height / 2;
        return Math.abs(inputCenter - unitCenter);
      });

      return {
        kpiHeight: Math.round(kpis?.height ?? 0),
        visibleCount: visibleRows.length,
        maxQtyDiff: Math.max(...qtyAlignment, 0),
      };
    });

    expect(m.kpiHeight, 'altura de la franja horizontal de KPIs').toBeLessThanOrEqual(42);
    expect(m.maxQtyDiff, 'la unidad y el campo de pedir deben estar en la misma línea (sin wrap)').toBeLessThan(6);
    expect(m.visibleCount, 'al menos 4 insumos visibles sin scroll al 125 %').toBeGreaterThanOrEqual(4);
  });

  test('Compras sugeridas (portátil 100 %): ≥ 6 insumos visibles sin scroll a 1366×611', async ({ page }) => {
    await setup(page, { width: 1366, height: 611 });
    await page.locator('.inv-daily .inv-hbtn', { hasText: 'Compras sugeridas' }).click();
    await expect(page.locator('.rm-kpis')).toBeVisible();

    const m = await page.evaluate(() => {
      const body = document.querySelector('.fin-modal-body')?.getBoundingClientRect();
      const rows = [...document.querySelectorAll('.rm-table tbody tr')];
      const visibleRows = rows.filter((r) => {
        const rect = r.getBoundingClientRect();
        return rect.top >= body.top && rect.bottom <= body.bottom + 2;
      });
      return visibleRows.length;
    });

    expect(m, 'al menos 6 insumos visibles sin scroll al 100 %').toBeGreaterThanOrEqual(6);
  });


  test('Nombre largo: máximo dos líneas con el nombre completo en el título', async ({ page }) => {
    const long = 'Pulpa de açaí orgánica sin azúcar (paquete de 1 kg, importada de Brasil)';
    await page.route('**/api/ingredients?**', (r) => r.fulfill(json({ data: [{ ...ING[0], name: long }, ...ING.slice(1)] })));
    await page.reload();
    const name = page.locator('.oh-table tbody tr').first().locator('.inv-name');
    await expect(name).toHaveAttribute('title', long);
    const lines = await name.evaluate((el) => Math.round(el.getBoundingClientRect().height / parseFloat(getComputedStyle(el).lineHeight)));
    expect(lines).toBeLessThanOrEqual(2);
    expect((await page.locator('.oh-table tbody tr').first().boundingBox()).height).toBeLessThanOrEqual(72);
  });
});
