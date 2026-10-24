/** Fase 1 de Finanzas contra el backend real (copia de la base), Toshiba al 125 %, claro y oscuro. */
const { chromium } = require('playwright');
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/simular-inventario.cjs', 'utf8');
const AUDIT = eval('(' + src.slice(src.indexOf('const AUDIT = () =>') + 'const AUDIT = '.length, src.indexOf('};\n\nconst GEOM') + 1) + ')');
const OUT = process.argv[2];
(async () => {
  const b = await chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM, args: ['--no-sandbox'] } : {});
  const res = [];
  const check = (n, ok, extra = '') => res.push(`${ok ? '✔' : '✘'} ${n}${extra ? ' — ' + extra : ''}`);
  for (const theme of ['light', 'dark']) {
    const ctx = await b.newContext({ viewport: { width: 1093, height: 489 }, deviceScaleFactor: 1.25 });
    await ctx.addInitScript((t) => { try { localStorage.setItem('theme', t); } catch {} }, theme);
    const p = await ctx.newPage(); p.setDefaultTimeout(90000);
    const api = (path, opts = {}) => p.evaluate(async ([path, opts]) => { const r = await fetch('/api' + path, { ...opts, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token')}` } }); return r.json(); }, [path, opts]);
    const focus = () => p.evaluate(() => document.activeElement.id || document.activeElement.className);
    const geom = () => p.evaluate(() => { const d = [...document.querySelectorAll('.fin-modal')].pop(); const r = d.getBoundingClientRect(); const body = d.querySelector('.fin-modal-body'); return { w: Math.round(r.width), h: Math.round(r.height), top: Math.round(r.top), bottom: Math.round(r.bottom), scroll: body.scrollHeight - body.clientHeight }; });
    await p.goto((process.env.AUDIT_URL || 'http://127.0.0.1:5173') + '/login'); await p.fill('input[type="email"]', process.env.AUDIT_EMAIL); await p.fill('input[type="password"]', process.env.AUDIT_PASSWORD); await p.keyboard.press('Enter'); await p.waitForURL(/pos/); await p.waitForLoadState('networkidle');
    await p.locator('aside, nav, .oh-sidebar').getByText('Finanzas').first().click(); await p.waitForSelector('.fin-tabs');
    await p.locator('.fin-tab', { hasText: 'Compras y gastos' }).click(); await p.waitForLoadState('networkidle');

    // Registrar gasto
    await p.getByRole('button', { name: 'Registrar gasto', exact: true }).click(); await p.waitForSelector('.fin-modal'); await p.waitForTimeout(400);
    const m = p.locator('.fin-modal').last();
    check(`[${theme}] Título «Registrar gasto»`, (await m.locator('h2').innerText()) === 'Registrar gasto');
    check(`[${theme}] Categoría vacía`, (await p.locator('#fx-cat option:checked').innerText()) === 'Elige una categoría…');
    check(`[${theme}] Foco en Monto`, (await focus()) === 'fx-amount', await focus());
    const g = await geom(); check(`[${theme}] Ventana entra al 125 %`, g.top >= 0 && g.bottom <= 489 && g.scroll <= 1, `${g.w}×${g.h}, desplazamiento ${g.scroll}`);
    const c1 = await p.evaluate(AUDIT); check(`[${theme}] Contraste de la ventana`, c1.length === 0, c1.map((c) => `${c.text} ${c.ratio}`).join('; '));
    await p.keyboard.type('84.5');
    await m.getByRole('button', { name: 'Guardar' }).click(); await p.waitForTimeout(300);
    check(`[${theme}] Sin categoría avisa junto al campo, a la vista, con foco en el selector`, (await p.locator('#fx-cat-err').innerText()) === 'Elige una categoría.' && (await focus()) === 'fx-cat' && await p.locator('#fx-cat-err').evaluate((e) => { const r = e.getBoundingClientRect(); const b = e.closest('.fin-modal-body').getBoundingClientRect(); return r.top >= b.top && r.bottom <= b.bottom; }));
    const c1b = await p.evaluate(AUDIT); check(`[${theme}] Contraste con el error a la vista`, c1b.length === 0, c1b.map((c) => `${c.text} ${c.ratio}`).join('; '));
    await p.screenshot({ path: `${OUT}/f1-${theme}-gasto-aviso.png` });
    await p.locator('#fx-supplier').fill('plásticos del oriente');
    const t0 = Date.now(); await p.waitForFunction(() => document.querySelector('#fx-cat').value !== '', null, { timeout: 8000 }).catch(() => {}); const waited = Date.now() - t0;
    const sug = await p.locator('#fx-cat option:checked').innerText();
    check(`[${theme}] Proveedor conocido sugiere su categoría`, sug !== 'Elige una categoría…', `${sug} (a los ${waited} ms)`);
    await p.screenshot({ path: `${OUT}/f1-${theme}-gasto-sugerencia.png` });
    const before = (await api('/finance/expenses?year=2026&month=10')).data.length;
    await m.getByRole('button', { name: 'Guardar' }).click(); await p.waitForSelector('#fx-amount', { state: 'detached' }); await p.waitForLoadState('networkidle');
    const list = (await api('/finance/expenses?year=2026&month=10')).data;
    const saved = list.find((e) => Number(e.amount) === 84.5);
    check(`[${theme}] Se guardó con la categoría sugerida`, list.length === before + 1 && saved?.category?.name === sug, `${saved?.supplier} · ${saved?.category?.name}`);
    check(`[${theme}] Al cerrar, el foco vuelve a «Registrar gasto»`, (await p.evaluate(() => document.activeElement.textContent.trim())) === 'Registrar gasto');

    // Gasto fijo
    await p.locator('.fin-tab', { hasText: 'Gastos fijos' }).click(); await p.waitForLoadState('networkidle');
    await p.getByRole('button', { name: /Agregar gasto fijo/ }).click(); await p.waitForSelector('.fin-modal'); await p.waitForTimeout(400);
    const m2 = p.locator('.fin-modal').last();
    check(`[${theme}] Gasto fijo: foco en Concepto`, (await focus()) === 'rx-name', await focus());
    check(`[${theme}] Gasto fijo: categoría vacía`, (await p.locator('#rx-cat option:checked').innerText()) === 'Elige una categoría…');
    const g2 = await geom(); check(`[${theme}] Gasto fijo entra al 125 %`, g2.top >= 0 && g2.bottom <= 489 && g2.scroll <= 1, `${g2.w}×${g2.h}`);
    const c2 = await p.evaluate(AUDIT); check(`[${theme}] Gasto fijo: contraste`, c2.length === 0, c2.map((c) => `${c.text} ${c.ratio}`).join('; '));
    await p.keyboard.type(`Contador ${theme}`); await p.locator('#rx-amount').fill('300');
    await m2.getByRole('button', { name: 'Guardar' }).click(); await p.waitForTimeout(300);
    check(`[${theme}] Gasto fijo sin categoría avisa junto al campo`, (await p.locator('#rx-cat-err').innerText()) === 'Elige una categoría.' && (await focus()) === 'rx-cat');
    await p.locator('#rx-cat').selectOption({ label: 'Otros gastos operativos' });
    await m2.getByRole('button', { name: 'Guardar' }).click(); await p.waitForSelector('#rx-name', { state: 'detached' }); await p.waitForLoadState('networkidle');
    const tpl = (await api('/finance/recurring-expenses')); const arr = tpl.data || tpl;
    const t = arr.find((x) => x.name === `Contador ${theme}`);
    check(`[${theme}] Gasto fijo guardado con su categoría`, t?.category?.name === 'Otros gastos operativos' || t?.expense_category_id > 0, `${t?.name} · ${t?.category?.name || t?.expense_category_id}`);
    await p.screenshot({ path: `${OUT}/f1-${theme}-fijos.png` });
    await ctx.close();
  }
  console.log(res.join('\n'));
  console.log(`\n${res.filter((r) => r.startsWith('✘')).length} fallas de ${res.length}`);
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
