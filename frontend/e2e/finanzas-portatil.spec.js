import { test, expect } from '@playwright/test';
import { mockFinanzas } from './fixtures/finanzas-api.js';

/**
 * Finanzas en la portátil de caja (1366 × 768; viewport útil 1366 × 611) y al 125 % (1093 × 489).
 * Plan: docs/PLAN_REDISENO_FINANZAS_PORTATIL.md. API simulada con datos abundantes (peor caso).
 */

const TABS = ['summary', 'pedidosya', 'expenses', 'recurring', 'costs', 'categories'];
const LAPTOP = [
  { width: 1366, height: 611, desc: '100 % (peor caso)' },
  { width: 1366, height: 768, desc: 'F11' },
  { width: 1093, height: 489, desc: '125 %' },
];

async function openTab(page, tab) {
  await page.goto(`/finanzas?tab=${tab}`);
  await page.waitForSelector('.fin-tabs', { timeout: 15000 });
  await page.waitForTimeout(500);
}

const shellMetrics = (page) => page.evaluate(() => {
  const sc = document.querySelector('.oh-shell-content');
  const r = (s) => document.querySelector(s)?.getBoundingClientRect();
  const tabs = r('.fin-tabs');
  const nav = r('.fin-nav');
  return { hx: sc.scrollWidth - sc.clientWidth, scTop: sc.getBoundingClientRect().top, tabsTop: tabs?.top, tabsH: tabs?.height, navTop: nav?.height ? nav.top : tabs?.top };
});

test.describe('Finanzas — portátil de caja', () => {
  test.beforeEach(async ({ page }) => { await mockFinanzas(page); });

  for (const vp of LAPTOP) {
    test(`1. Sin scroll horizontal y con pestañas visibles en las 6 pestañas — ${vp.width}×${vp.height} (${vp.desc})`, async ({ page }) => {
      await page.setViewportSize(vp);
      for (const t of TABS) {
        await openTab(page, t);
        const m = await shellMetrics(page);
        expect(m.hx, `scroll horizontal en ${t}`).toBeLessThanOrEqual(1);
        expect(m.tabsH, `las pestañas no deben aplastarse (${t})`).toBeGreaterThan(24);
      }
    });

    test(`2. La navegación queda pegada al desplazarse y se puede cambiar de pestaña — ${vp.width}×${vp.height} (${vp.desc})`, async ({ page }) => {
      await page.setViewportSize(vp);
      await openTab(page, 'expenses');
      await page.evaluate(() => { document.querySelector('.oh-shell-content').scrollTop = 400; });
      await page.waitForTimeout(150);
      const m = await shellMetrics(page);
      expect(Math.abs(m.navTop - m.scTop), 'la navegación debe seguir arriba').toBeLessThanOrEqual(1);
      expect(m.tabsTop - m.scTop, 'las pestañas quedan a la vista').toBeLessThan(60);
      await page.getByRole('tab', { name: /Categorías/ }).click();
      await expect(page.locator('.cat-grid')).toBeVisible();
    });
  }

  test('3. Resumen 1366×611: cabecera compacta; Estado de resultados y Punto de equilibrio lado a lado, a la vista', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openTab(page, 'summary');
    const g = await page.evaluate(() => {
      const r = (s) => document.querySelector(s).getBoundingClientRect();
      return { hero: r('.hero').height, main: r('.sum-main'), side: r('.sum-side'), vh: innerHeight, stmt: r('.fin-statement').top };
    });
    expect(g.hero, 'alto de la cabecera del resultado').toBeLessThanOrEqual(190);
    expect(g.side.left, 'punto de equilibrio a la derecha').toBeGreaterThan(g.main.right - 4);
    expect(g.stmt, 'el Estado de resultados empieza en el primer pantallazo').toBeLessThan(g.vh - 150);
    await expect(page.getByRole('heading', { name: 'Punto de equilibrio' })).toBeInViewport();
    // Los avisos conservan todas sus acciones
    await expect(page.getByRole('button', { name: 'Clasificar egresos' })).toBeInViewport();
    await expect(page.getByRole('button', { name: 'Revisar gastos fijos' })).toBeInViewport();
  });

  test('4. Categorías: las 4 secciones en 2 columnas × 2 filas', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openTab(page, 'categories');
    const cards = await page.locator('.cat-grid > .fin-card').evaluateAll((els) => els.map((e) => { const r = e.getBoundingClientRect(); return { l: Math.round(r.left), t: Math.round(r.top), w: Math.round(r.width) }; }));
    expect(cards).toHaveLength(4);
    expect(cards[0].t).toBe(cards[1].t);
    expect(cards[2].t).toBe(cards[3].t);
    expect(cards[1].l).toBeGreaterThan(cards[0].l + cards[0].w - 4);
  });

  test('5. Costos 1366×611: al menos 7 productos visibles en el primer pantallazo', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openTab(page, 'costs');
    const visible = await page.locator('.fin-table tbody tr').evaluateAll((rows) => rows.filter((r) => r.getBoundingClientRect().bottom <= innerHeight).length);
    expect(visible).toBeGreaterThanOrEqual(7);
  });

  const MODALS = [
    { name: 'Registrar gasto', tab: 'expenses', open: (p) => p.getByRole('button', { name: 'Registrar gasto' }).click() },
    { name: 'Registrar compra con 4 líneas', tab: 'expenses', open: async (p) => {
      await p.getByRole('button', { name: /Registrar compra/ }).first().click();
      await p.waitForSelector('.pm-lines');
      for (let i = 0; i < 3; i++) await p.getByRole('button', { name: /Agregar otra línea/ }).click();
    } },
    { name: 'Receta de un producto', tab: 'costs', open: (p) => p.getByRole('button', { name: 'Receta' }).first().click() },
    { name: 'Agregar gasto fijo', tab: 'recurring', open: (p) => p.getByRole('button', { name: /Agregar gasto fijo/ }).first().click() },
    { name: 'Agregar categoría', tab: 'categories', open: (p) => p.getByRole('button', { name: /Agregar/ }).first().click() },
  ];
  for (const vp of LAPTOP) {
    for (const m of MODALS) {
      test(`6. Modal «${m.name}»: cabecera y botones dentro de la pantalla — ${vp.width}×${vp.height}`, async ({ page }) => {
        await page.setViewportSize(vp);
        await openTab(page, m.tab);
        await m.open(page);
        const modal = page.locator('.fin-modal').last();
        await expect(modal).toBeVisible();
        const g = await page.evaluate(() => {
          const md = [...document.querySelectorAll('.fin-modal')].pop();
          const r = md.getBoundingClientRect();
          const f = md.querySelector('.fin-modal-foot')?.getBoundingClientRect();
          const h = md.querySelector('.fin-modal-head').getBoundingClientRect();
          const body = md.querySelector('.fin-modal-body');
          const x = innerWidth / 2; const y = 60;
          const top = document.elementFromPoint(x, Math.max(y, 5));
          return { top: r.top, bottom: r.bottom, footBottom: f ? f.bottom : null, headTop: h.top, vh: innerHeight, hx: md.scrollWidth - md.clientWidth, bodyHx: body.scrollWidth - body.clientWidth, overlayOnTop: !!top?.closest('.fin-modal-overlay') };
        });
        expect(g.top).toBeGreaterThanOrEqual(0);
        expect(g.bottom).toBeLessThanOrEqual(g.vh + 0.5);
        if (g.footBottom !== null) expect(g.footBottom).toBeLessThanOrEqual(g.vh + 0.5);
        expect(g.hx).toBeLessThanOrEqual(1);
        expect(g.bodyHx).toBeLessThanOrEqual(1);
        expect(g.overlayOnTop, 'el modal queda por encima de la navegación pegada').toBe(true);
        await expect(modal.getByRole('button', { name: /Guardar|Cancelar/ }).first()).toBeInViewport();
      });
    }
  }

  test('7. Tema oscuro 1366×611: sin scroll horizontal y pestañas visibles', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await openTab(page, 'summary');
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    const m = await shellMetrics(page);
    expect(m.hx).toBeLessThanOrEqual(1);
    expect(m.tabsH).toBeGreaterThan(24);
  });
});

test.describe('Finanzas — sin cambios fuera de la portátil', () => {
  test.beforeEach(async ({ page }) => { await mockFinanzas(page); });

  test('8. Monitor 1440×900: el diseño amplio se conserva y la navegación no es pegajosa', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openTab(page, 'summary');
    const g = await page.evaluate(() => ({ hero: document.querySelector('.hero').getBoundingClientRect().height, pos: getComputedStyle(document.querySelector('.fin-nav')).position, h1: parseFloat(getComputedStyle(document.querySelector('.fin-head h1')).fontSize) }));
    expect(g.hero).toBeGreaterThan(300);
    expect(g.pos).toBe('static');
    expect(g.h1).toBeGreaterThanOrEqual(28);
  });

  test('9. Teléfono 390×844: sin scroll horizontal, navegación no pegajosa, categorías en 1 columna', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    for (const t of TABS) {
      await openTab(page, t);
      const m = await shellMetrics(page);
      expect(m.hx, `scroll horizontal en ${t}`).toBeLessThanOrEqual(1);
    }
    const pos = await page.evaluate(() => getComputedStyle(document.querySelector('.fin-nav')).position);
    expect(pos).toBe('static');
    await openTab(page, 'categories');
    const lefts = await page.locator('.cat-grid > .fin-card').evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().left)));
    expect(new Set(lefts).size).toBe(1);
  });
});
