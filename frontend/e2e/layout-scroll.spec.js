import { test, expect } from '@playwright/test';
import { mockFinanzas } from './fixtures/finanzas-api.js';

/**
 * La ventana nunca se desplaza con el menú lateral: solo el contenido. Evita la «franja blanca» al llegar al final
 * de una página larga (el menú se despegaba hacia arriba). Plan: docs/PLAN_CORRECCION_SCROLL_SISTEMA.md
 *
 * Causa original: elementos con position:absolute (los .sr-only de los encabezados de tabla) se ubicaban respecto de
 * la ventana y no del contenedor que se desplaza; estiraban la página y la rueda terminaba moviendo la ventana.
 */

const ROUTES = ['/pos', '/turno', '/delivery', '/menu', '/inventario', '/admin/ventas-retroactivas', '/users', '/audit-logs', '/sin-acceso',
  '/finanzas?tab=summary', '/finanzas?tab=pedidosya', '/finanzas?tab=expenses', '/finanzas?tab=recurring', '/finanzas?tab=costs', '/finanzas?tab=categories'];
const VIEWPORTS = [
  { width: 1366, height: 611, desc: 'portátil 100 %' },
  { width: 1093, height: 489, desc: 'portátil 125 %' },
  { width: 390, height: 844, desc: 'teléfono' },
];

/** Posicionados absolutos dentro del contenido cuyo bloque contenedor queda fuera de él (se ubicarían respecto de la ventana). */
const ESCAPES = () => {
  const sc = document.querySelector('.oh-shell-content');
  const isCB = (el) => { const cs = getComputedStyle(el); return cs.position !== 'static' || cs.transform !== 'none' || cs.filter !== 'none' || /paint|layout|strict|content/.test(cs.contain); };
  const out = [];
  for (const el of sc.querySelectorAll('*')) {
    if (getComputedStyle(el).position !== 'absolute') continue;
    let a = el.parentElement; let inside = false;
    while (a) { if (isCB(a)) { inside = sc.contains(a); break; } if (a === sc) break; a = a.parentElement; }
    if (!inside) out.push(`${el.tagName.toLowerCase()}.${String(el.className).trim().replace(/\s+/g, '.')}`);
  }
  return out;
};

async function wheelToBottom(page) {
  await page.mouse.move(page.viewportSize().width - 200, page.viewportSize().height / 2);
  for (let i = 0; i < 20; i++) await page.mouse.wheel(0, 500);
  await page.waitForTimeout(250);
}

test.describe('Scroll del sistema: la ventana queda fija con el menú lateral', () => {
  test.beforeEach(async ({ page }) => { await mockFinanzas(page); });

  for (const vp of VIEWPORTS) {
    test(`1. Ninguna pantalla estira la ventana ni la desplaza — ${vp.width}×${vp.height} (${vp.desc})`, async ({ page }) => {
      test.setTimeout(180000);
      await page.setViewportSize(vp);
      for (const r of ROUTES) {
        await page.goto(r);
        await page.waitForSelector('.oh-shell-content', { timeout: 15000 });
        await page.waitForTimeout(500);
        const m = await page.evaluate(() => ({
          html: document.documentElement.scrollHeight, htmlW: document.documentElement.scrollWidth,
          vh: innerHeight, vw: innerWidth, locked: document.documentElement.classList.contains('oh-shell-active'),
          overflow: getComputedStyle(document.documentElement).overflowY,
        }));
        expect(m.locked, `${r}: la ventana debe quedar bloqueada con el menú`).toBe(true);
        expect(m.overflow, r).toBe('hidden');
        expect(m.html, `${r}: alto del documento`).toBeLessThanOrEqual(m.vh);
        expect(m.htmlW, `${r}: ancho del documento`).toBeLessThanOrEqual(m.vw);
        expect(await page.evaluate(ESCAPES), `${r}: posicionados fuera del contenido`).toEqual([]);
        const shrunk = await page.evaluate(() => [...document.querySelector('.oh-shell-content').children]
          .filter((c) => getComputedStyle(c).overflowY === 'visible' && c.scrollHeight > c.getBoundingClientRect().height + 1)
          .map((c) => `${c.className}: ${Math.round(c.getBoundingClientRect().height)} < ${c.scrollHeight}`));
        expect(shrunk, `${r}: la pantalla no debe encogerse por debajo de su contenido`).toEqual([]);
        await wheelToBottom(page);
        const after = await page.evaluate(() => ({ y: window.scrollY, side: document.querySelector('.oh-sidebar').getBoundingClientRect().top }));
        expect(after.y, `${r}: la ventana no se desplaza`).toBe(0);
        expect(after.side, `${r}: el menú no se despega`).toBe(0);
      }
    });
  }

  test('2. Costos y margen (el caso reportado): al final del scroll el menú y la navegación siguen en su lugar', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await page.goto('/finanzas?tab=costs');
    await page.waitForSelector('.fin-table');
    await wheelToBottom(page);
    const m = await page.evaluate(() => {
      const sc = document.querySelector('.oh-shell-content');
      return {
        atEnd: Math.abs(sc.scrollHeight - sc.clientHeight - sc.scrollTop) <= 1,
        y: window.scrollY,
        shellBottom: document.querySelector('.oh-shell').getBoundingClientRect().bottom,
        vh: innerHeight,
        navTop: document.querySelector('.fin-nav').getBoundingClientRect().top - sc.getBoundingClientRect().top,
      };
    });
    expect(m.atEnd, 'el contenido llegó al final').toBe(true);
    expect(m.y).toBe(0);
    expect(m.shellBottom, 'sin franja blanca debajo').toBe(m.vh);
    expect(Math.abs(m.navTop), 'pestañas de Finanzas a la vista').toBeLessThanOrEqual(1);
  });

  test('3. Con un modal abierto (receta) la ventana tampoco crece', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await page.goto('/finanzas?tab=costs');
    await page.getByRole('button', { name: 'Receta' }).first().click();
    await expect(page.locator('.fin-modal')).toBeVisible();
    const h = await page.evaluate(() => document.documentElement.scrollHeight);
    expect(h).toBeLessThanOrEqual(611);
  });

  test('4. «Clasificar egresos» lleva a Egresos de caja sin mover la ventana ni dejarla debajo de las pestañas', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await page.goto('/finanzas?tab=summary');
    await page.getByRole('button', { name: 'Clasificar egresos' }).click();
    const card = page.locator('.fin-card', { has: page.getByRole('heading', { name: 'Egresos de caja del mes' }) });
    await expect(card).toBeVisible();
    await page.waitForTimeout(900); // desplazamiento suave
    const m = await page.evaluate(() => {
      const h = [...document.querySelectorAll('h2')].find((e) => e.textContent.includes('Egresos de caja del mes'));
      return { y: window.scrollY, cardTop: h.closest('.fin-card').getBoundingClientRect().top, navBottom: document.querySelector('.fin-nav').getBoundingClientRect().bottom, side: document.querySelector('.oh-sidebar').getBoundingClientRect().top };
    });
    expect(m.y).toBe(0);
    expect(m.side).toBe(0);
    expect(m.cardTop, 'la tarjeta empieza debajo de las pestañas').toBeGreaterThanOrEqual(m.navBottom - 1);
  });

  test('5. Al imprimir (comprobantes) la raíz no recorta: overflow visible', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 611 });
    await page.goto('/turno');
    await page.waitForSelector('.oh-shell-content');
    await page.emulateMedia({ media: 'print' });
    const o = await page.evaluate(() => [getComputedStyle(document.documentElement).overflowY, getComputedStyle(document.body).overflowY]);
    expect(o).toEqual(['visible', 'visible']);
  });
});

test('6. Sin menú (login) la ventana no queda bloqueada', async ({ page }) => {
  await page.route('**/api/**', (r) => r.fulfill({ status: 401, contentType: 'application/json', body: '{"message":"Unauthenticated."}' }));
  await page.setViewportSize({ width: 390, height: 480 });
  await page.goto('/login');
  await expect(page.getByRole('heading', { name: /Ohana/ })).toBeVisible();
  const m = await page.evaluate(() => ({ locked: document.documentElement.classList.contains('oh-shell-active'), overflow: getComputedStyle(document.documentElement).overflowY }));
  expect(m.locked).toBe(false);
  expect(m.overflow).not.toBe('hidden');
});
