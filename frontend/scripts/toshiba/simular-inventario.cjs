/**
 * Simulación de la portátil de caja Toshiba (1366 × 768) contra el backend real.
 *   100 %: viewport 1366 × 611, DPR 1
 *   125 %: viewport 1093 × 489, DPR 1.25
 * CPU limitada 4× (equipo antiguo). Claro y oscuro.
 * Uso: node toshiba-sim.cjs <carpeta-salida> [escenario]
 */
const { chromium } = require('playwright');
const fs = require('fs');
const OUT = process.argv[2];
const SCEN = process.argv[3] || 'base';
const BASE = (process.env.AUDIT_URL || 'http://127.0.0.1:5173') + '';
fs.mkdirSync(OUT, { recursive: true });

const PROFILES = [
  { id: '100', viewport: { width: 1366, height: 611 }, dpr: 1 },
  { id: '125', viewport: { width: 1093, height: 489 }, dpr: 1.25 },
];
const THEMES = (process.env.THEMES || 'light,dark').split(',');

const AUDIT = () => {
  const parse = c => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const lum = ({ r, g, b }) => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
  const blend = (top, bot) => ({ r: top.r * top.a + bot.r * (1 - top.a), g: top.g * top.a + bot.g * (1 - top.a), b: top.b * top.a + bot.b * (1 - top.a), a: 1 });
  const bgOf = el => { const stack = []; let e = el; let img = false;
    while (e && e.nodeType === 1) { const cs = getComputedStyle(e); if (cs.backgroundImage !== 'none') img = true; const c = parse(cs.backgroundColor); if (c && c.a > 0) { stack.push(c); if (c.a >= 1) break; } e = e.parentElement; }
    let base = { r: 255, g: 255, b: 255, a: 1 }; for (let i = stack.length - 1; i >= 0; i--) base = blend(stack[i], base); return { c: base, img }; };
  // Si hay un modal abierto, solo se audita el modal (lo de atrás está velado).
  const modals = [...document.querySelectorAll('.fin-modal')].filter(m => m.getBoundingClientRect().height > 0);
  const scope = modals.length ? modals[modals.length - 1] : document.body;
  const out = []; const seen = new Set();
  const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT);
  while (walker.nextNode()) { const t = walker.currentNode; const txt = t.textContent.trim(); if (!txt) continue; const el = t.parentElement; if (!el) continue;
    if (el.closest('option, select')) continue;
    const r = el.getBoundingClientRect(); if (r.width === 0 || r.height === 0 || r.bottom < 0 || r.top > innerHeight) continue;
    const cs = getComputedStyle(el); if (cs.visibility === 'hidden' || +cs.opacity === 0) continue;
    if (el.closest('[disabled]') || el.closest('[aria-disabled="true"]')) continue; // texto deshabilitado: exento en WCAG
    const fg = parse(cs.color); const { c: bg, img } = bgOf(el); const fgB = blend(fg, bg);
    const L1 = lum(fgB), L2 = lum(bg); const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
    const size = parseFloat(cs.fontSize), weight = +cs.fontWeight || 400; const large = size >= 24 || (size >= 18.66 && weight >= 700);
    const min = large ? 3 : 4.5; if (ratio < min) { const key = txt.slice(0, 40) + cs.color + bg.r + bg.g + bg.b; if (seen.has(key)) continue; seen.add(key);
      out.push({ text: txt.slice(0, 50), ratio: +ratio.toFixed(2), min, color: cs.color, bg: `rgb(${bg.r|0}, ${bg.g|0}, ${bg.b|0})`, img, cls: (typeof el.className === 'string' ? el.className : '').slice(0, 40), tag: el.tagName.toLowerCase(), size }); } }
  return out.sort((a, b) => a.ratio - b.ratio);
};

const GEOM = () => {
  const vw = innerWidth, vh = innerHeight;
  const res = { vw, vh, docOverflowX: document.documentElement.scrollWidth - vw, problems: [] };
  const modals = [...document.querySelectorAll('.fin-modal')].filter(m => m.getBoundingClientRect().height > 0);
  const m = modals[modals.length - 1];
  if (m) {
    const r = m.getBoundingClientRect();
    res.modal = { title: m.getAttribute('aria-label'), top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), h: Math.round(r.height), w: Math.round(r.width) };
    if (r.top < -0.5 || r.bottom > vh + 0.5 || r.left < -0.5 || r.right > vw + 0.5) res.problems.push('modal fuera de la ventana');
    const body = m.querySelector('.fin-modal-body');
    if (body) { res.modal.bodyScroll = body.scrollHeight - body.clientHeight; res.modal.bodyOverflowX = body.scrollWidth - body.clientWidth; if (res.modal.bodyOverflowX > 1) res.problems.push(`cuerpo del modal desborda ${res.modal.bodyOverflowX}px a lo ancho`); }
    const foot = m.querySelector('.fin-modal-foot');
    if (foot) {
      const btns = [...foot.querySelectorAll('button')].filter(b => b.getBoundingClientRect().width > 0);
      res.modal.footButtons = btns.map(b => {
        const br = b.getBoundingClientRect(); const cx = br.left + br.width / 2, cy = br.top + br.height / 2;
        const hit = document.elementFromPoint(cx, cy); const reachable = !!hit && (hit === b || b.contains(hit));
        if (!reachable) res.problems.push(`botón «${b.textContent.trim()}» tapado o fuera`);
        return `${b.textContent.trim()}${reachable ? '' : ' ✗'}`;
      });
    }
    // Campos que se salen de su columna
    for (const el of m.querySelectorAll('input, select, textarea, button')) {
      const er = el.getBoundingClientRect(); if (er.width === 0) continue;
      if (er.right > r.right + 0.5 || er.left < r.left - 0.5) res.problems.push(`${el.tagName.toLowerCase()} ${el.id || el.name || el.textContent.trim().slice(0, 20)} sale del modal`);
    }
  }
  const tb = document.querySelector('.inv-toolbar');
  if (tb && !m) {
    const kids = [...tb.querySelectorAll('.inv-hbtn, h1')].filter(k => k.getBoundingClientRect().width > 0);
    const tops = new Set(kids.map(k => Math.round(k.getBoundingClientRect().top / 8)));
    res.headerLines = tops.size;
    res.headerBottom = Math.round(tb.getBoundingClientRect().bottom);
    const rows = [...document.querySelectorAll('.inv-table tbody tr')];
    const scroller = document.querySelector('.oh-shell-content') || document.scrollingElement;
    const sr = scroller.getBoundingClientRect();
    res.rows = rows.length;
    res.rowsVisible = rows.filter(rw => { const q = rw.getBoundingClientRect(); return q.top >= sr.top - 1 && q.bottom <= Math.min(vh, sr.bottom) + 1; }).length;
    res.rowHeights = [...new Set(rows.map(rw => Math.round(rw.getBoundingClientRect().height)))];
    const tc = document.querySelector('.table-container');
    if (tc) { res.tableOverflowX = tc.scrollWidth - tc.clientWidth; if (res.tableOverflowX > 1) res.problems.push(`tabla desborda ${res.tableOverflowX}px`); }
    for (const rw of rows) { const tools = [...rw.querySelectorAll('.inv-tool')]; const t = new Set(tools.map(x => Math.round(x.getBoundingClientRect().top))); if (t.size > 1) { res.problems.push(`herramientas en ${t.size} líneas (${rw.querySelector('th')?.textContent.trim().slice(0, 30)})`); break; } }
    // Textos recortados sin title
    for (const el of document.querySelectorAll('.inv-table td *, .inv-table th *')) {
      const cs = getComputedStyle(el); if (cs.textOverflow !== 'ellipsis') continue;
      if (el.scrollWidth > el.clientWidth + 1 && !el.title && !el.closest('[title]')) res.problems.push(`texto recortado sin title: «${el.textContent.trim().slice(0, 40)}»`);
    }
  }
  if (res.docOverflowX > 0) res.problems.push(`scroll horizontal de la página ${res.docOverflowX}px`);
  const a = document.activeElement; res.focus = a ? `${a.tagName.toLowerCase()}${a.id ? '#' + a.id : ''}${a.getAttribute('aria-label') ? '[' + a.getAttribute('aria-label') + ']' : ''}${a.textContent && a.tagName === 'BUTTON' ? ' «' + a.textContent.trim().slice(0, 25) + '»' : ''}` : null;
  return res;
};

(async () => {
  const browser = await chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM, args: ['--no-sandbox'] } : {});
  const report = {};
  for (const prof of PROFILES) for (const theme of THEMES) {
    const key = `${prof.id}-${theme}`;
    const ctx = await browser.newContext({ viewport: prof.viewport, deviceScaleFactor: prof.dpr, locale: 'es-BO', timezoneId: 'America/La_Paz' });
    await ctx.addInitScript((t) => { try { localStorage.setItem('theme', t); } catch {} }, theme);
    const page = await ctx.newPage(); page.setDefaultTimeout(60000);
    const errors = []; page.on('pageerror', e => errors.push(e.message)); page.on('console', m => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
    const cdp = await ctx.newCDPSession(page);
    report[key] = { states: {}, errors };
    const snap = async (name) => {
      await page.waitForTimeout(500);
      await page.screenshot({ path: `${OUT}/${SCEN}-${key}-${name}.png` });
      const geom = await page.evaluate(GEOM); const contrast = await page.evaluate(AUDIT);
      report[key].states[name] = { geom, contrast };
      console.log(key, name.padEnd(22), geom.modal ? `modal ${geom.modal.w}×${geom.modal.h} top ${geom.modal.top} bottom ${geom.modal.bottom} scroll ${geom.modal.bodyScroll}` : `filas ${geom.rowsVisible}/${geom.rows} alto ${geom.rowHeights} cabecera ${geom.headerLines}L`, '| foco', geom.focus, '| contraste', contrast.length, geom.problems.length ? '| ⚠ ' + geom.problems.join('; ') : '');
    };
    const timed = async (label, fn) => { await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 }); const t0 = Date.now(); await fn(); const ms = Date.now() - t0; await cdp.send('Emulation.setCPUThrottlingRate', { rate: 1 }); (report[key].timings ||= {})[label] = ms; };
    const closeTop = async () => { await page.keyboard.press('Escape'); await page.waitForTimeout(350); };

    await page.goto(BASE + '/login');
    await page.fill('input[type="email"]', process.env.AUDIT_EMAIL);
    await page.fill('input[type="password"]', process.env.AUDIT_PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL(/pos/);
    await page.goto(BASE + '/inventario');
    await page.waitForSelector('.inv-table tbody tr, .inv-empty, .empty-state', { timeout: 90000 });
    await page.waitForLoadState('networkidle');
    await snap('01-lista');

    await page.locator('.inv-more-btn').click(); await snap('02-menu-mas'); await page.keyboard.press('Escape');

    await timed('abrir Nuevo insumo', async () => { await page.locator('.inv-new').click(); await page.waitForSelector('.fin-modal'); });
    await snap('03-nuevo-insumo');
    await page.locator('.fin-modal .fin-modal-foot button', { hasText: 'Guardar' }).click(); await snap('03b-nuevo-insumo-errores'); await closeTop();

    const first = page.locator('.inv-table tbody tr').first();
    const firstName = (await first.locator('th').innerText()).split('\n')[0].trim();
    report[key].firstName = firstName;
    await first.locator('button[aria-label^="Editar "]').click(); await page.waitForSelector('.fin-modal'); await snap('04-editar-insumo'); await closeTop();

    await page.locator('.inv-more-btn').click(); await page.getByRole('menuitem', { name: /Categorías/ }).click(); await page.waitForSelector('.fin-modal'); await snap('05-categorias'); await closeTop();
    await page.locator('.inv-more-btn').click(); await page.getByRole('menuitem', { name: /Proveedores/ }).click(); await page.waitForSelector('.fin-modal'); await snap('06-proveedores'); await closeTop();

    await timed('abrir Registrar compra', async () => { await page.locator('.inv-daily .inv-hbtn', { hasText: 'Registrar compra' }).click(); await page.waitForSelector('.pm-line'); });
    await snap('07-compra');
    await page.locator('.fin-modal .fin-modal-foot button', { hasText: /Guardar/ }).click(); await snap('07b-compra-aviso');
    await page.locator('.pm-line select').first().selectOption({ index: 1 }); await snap('07c-compra-insumo');
    await page.getByRole('button', { name: /Agregar otra línea/ }).click(); await page.locator('.pm-line').nth(1).locator('select').first().selectOption({ index: (await page.locator('.pm-line').nth(1).locator('select').first().locator('option').count()) - 1 });
    await snap('07d-compra-dos-lineas'); await closeTop();

    await first.locator('button[aria-label^="Registrar compra de "]').click(); await page.waitForSelector('.pm-line'); await snap('08-compra-desde-fila'); await closeTop();
    await page.locator('.inv-daily .inv-hbtn', { hasText: 'Registrar merma' }).click(); await page.waitForSelector('.fin-modal'); await snap('09-merma'); await closeTop();
    await page.locator('.inv-daily .inv-hbtn', { hasText: 'Contar stock' }).click(); await page.waitForSelector('.fin-modal'); await page.waitForLoadState('networkidle'); await snap('10-conteo'); await closeTop();
    await page.locator('.inv-daily .inv-hbtn', { hasText: 'Compras sugeridas' }).click(); await page.waitForSelector('.fin-modal'); await page.waitForLoadState('networkidle'); await snap('11-compras-sugeridas'); await closeTop();
    await first.locator('button[aria-label^="Historial de "]').click(); await page.waitForSelector('.fin-modal'); await page.waitForLoadState('networkidle'); await snap('12-historial'); await closeTop();
    await page.locator('.inv-more-btn').click(); await page.getByRole('menuitem', { name: /Mermas/ }).click(); await page.waitForSelector('.fin-modal'); await page.waitForLoadState('networkidle'); await snap('13-mermas-del-mes'); await closeTop();
    await first.locator('button[aria-label^="Eliminar "]').click(); await page.waitForSelector('.fin-modal'); await snap('14-confirmar-borrado'); await closeTop();

    if (SCEN === 'completo' && key === '125-light') {
      // Compra real de punta a punta contra el backend: un insumo y un otro gasto
      await page.locator('.inv-daily .inv-hbtn', { hasText: 'Registrar compra' }).click(); await page.waitForSelector('.pm-line');
      const l1 = page.locator('.pm-line').nth(0);
      await l1.locator('select').first().selectOption({ label: 'Frutilla (kg)' });
      await l1.locator('input[id^="pm-qty-"]').fill('2'); await l1.locator('input[id^="pm-total-"]').fill('36');
      await page.getByRole('button', { name: /Agregar otra línea/ }).click();
      const l2 = page.locator('.pm-line').nth(1);
      await l2.locator('select').first().selectOption({ label: 'Otro gasto (gas, limpieza, bolsas…)' });
      await l2.locator('input[id^="pm-desc-"]').fill('Hielo en bolsa'); await l2.locator('input[id^="pm-qty-"]').fill('1'); await l2.locator('input[id^="pm-total-"]').fill('15');
      await page.locator('#pm-cat').selectOption({ label: 'Ingredientes y frutas' });
      await snap('15-compra-lista-para-guardar');
      await page.locator('.fin-modal .fin-modal-foot button', { hasText: /Guardar/ }).click();
      await page.waitForSelector('.pm-line', { state: 'detached', timeout: 90000 });
      await page.waitForLoadState('networkidle');
      await snap('16-despues-de-guardar');
      report[key].compra = await page.evaluate(async () => {
        const h = { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token')}` };
        const ings = (await (await fetch('/api/ingredients', { headers: h })).json()).data;
        return ings.filter(i => i.name === 'Frutilla').map(i => ({ stock: i.current_stock, costo: i.unit_cost, prom: i.weighted_avg_cost }));
      });
      console.log('compra guardada → Frutilla', JSON.stringify(report[key].compra));
    }
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(`${OUT}/${SCEN}-report.json`, JSON.stringify(report, null, 1));
  const issues = [];
  for (const [k, r] of Object.entries(report)) for (const [s, v] of Object.entries(r.states)) { for (const p of v.geom.problems) issues.push(`${k} ${s}: ${p}`); for (const c of v.contrast) issues.push(`${k} ${s}: contraste ${c.ratio} < ${c.min} «${c.text}» (${c.cls || c.tag}) ${c.color} sobre ${c.bg}`); }
  for (const [k, r] of Object.entries(report)) { if (r.errors.length) issues.push(`${k}: errores de consola: ${[...new Set(r.errors)].slice(0, 5).join(' | ')}`); console.log(k, 'tiempos (CPU 4×):', JSON.stringify(r.timings)); }
  console.log(`\n${issues.length} hallazgos`); for (const i of issues) console.log(' -', i);
})().catch(e => { console.error(e); process.exit(1); });
