/**
 * Línea base de Finanzas en la Toshiba (backend real, copia de la base con datos de prueba).
 * Mide cada pestaña y cada modal del plan PLAN_MEJORA_UI_FINANZAS_PORTATIL.md, y audita contraste
 * recorriendo todo el contenido (no solo el primer pantallazo).
 * Uso: node finanzas-medicion.cjs <carpeta-salida>
 */
const { chromium } = require('playwright');
const fs = require('fs');
const OUT = process.argv[2];
fs.mkdirSync(OUT, { recursive: true });
const BASE = (process.env.AUDIT_URL || 'http://127.0.0.1:5173') + '';
const PROFILES = [
  { id: '100', viewport: { width: 1366, height: 611 }, dpr: 1 },
  { id: '125', viewport: { width: 1093, height: 489 }, dpr: 1.25 },
];
const THEMES = (process.env.THEMES || 'light,dark').split(',');
const src = fs.readFileSync(__dirname + '/simular-inventario.cjs', 'utf8');
const AUDIT = eval('(' + src.slice(src.indexOf('const AUDIT = () =>') + 'const AUDIT = '.length, src.indexOf('};\n\nconst GEOM') + 1) + ')');

(async () => {
  const browser = await chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM, args: ['--no-sandbox'] } : {});
  const report = {};
  for (const prof of PROFILES) for (const theme of THEMES) {
    const key = `${prof.id}-${theme}`;
    const ctx = await browser.newContext({ viewport: prof.viewport, deviceScaleFactor: prof.dpr });
    await ctx.addInitScript((t) => { try { localStorage.setItem('theme', t); } catch {} }, theme);
    const page = await ctx.newPage(); page.setDefaultTimeout(90000);
    report[key] = {};
    const auditAll = async (scrollSel) => {
      const issues = new Map();
      const steps = await page.evaluate((sel) => { const el = document.querySelector(sel); if (!el) return 1; return Math.max(1, Math.ceil(el.scrollHeight / Math.max(100, el.clientHeight - 40))); }, scrollSel);
      for (let s = 0; s < Math.min(steps, 14); s++) {
        await page.evaluate(([sel, k]) => { const el = document.querySelector(sel); if (el) el.scrollTop = k * Math.max(100, el.clientHeight - 40); }, [scrollSel, s]);
        await page.waitForTimeout(120);
        for (const i of await page.evaluate(AUDIT)) issues.set(i.text + i.color + i.bg, i);
      }
      await page.evaluate((sel) => { const el = document.querySelector(sel); if (el) el.scrollTop = 0; }, scrollSel);
      return [...issues.values()];
    };
    const measureTab = async (name) => {
      await page.waitForLoadState('networkidle');
      await page.waitForFunction(() => ![...document.querySelectorAll('.fin-loading')].some(e => e.getClientRects().length), null, { timeout: 90000 });
      await page.waitForLoadState('networkidle'); await page.waitForTimeout(500);
      const m = await page.evaluate(() => {
        const sc = document.querySelector('.oh-shell-content'); const r = (s) => { const e = document.querySelector(s); if (!e) return null; const b = e.getBoundingClientRect(); return { top: Math.round(b.top), h: Math.round(b.height), left: Math.round(b.left), w: Math.round(b.width), bottom: Math.round(b.bottom) }; };
        const main = r('.sum-main'), side = r('.sum-side');
        return { content: sc.scrollHeight, viewport: sc.clientHeight, overflowX: document.documentElement.scrollWidth - innerWidth, nav: r('.fin-nav') || r('.fin-tabs'), tabs: r('.fin-tabs'), hero: r('.hero'),
          sumSideBySide: main && side ? side.top < main.bottom && side.left > main.left + 50 : null, sumMain: main, sumSide: side };
      });
      await page.screenshot({ path: `${OUT}/fin-${key}-${name}.png` });
      m.contrast = await auditAll('.oh-shell-content');
      report[key][name] = m;
      console.log(key, name.padEnd(18), `contenido ${m.content}px / ${m.viewport}px`, m.hero ? `hero ${m.hero.h}` : '', m.sumSideBySide !== null ? `resumen ${m.sumSideBySide ? '2 columnas' : '1 columna'}` : '', m.overflowX > 0 ? `⚠ scroll horizontal ${m.overflowX}` : '', `contraste ${m.contrast.length}`);
    };
    const measureModal = async (name) => {
      await page.waitForSelector('.fin-modal'); await page.waitForLoadState('networkidle');
      await page.waitForFunction(() => ![...document.querySelectorAll('.fin-modal .fin-loading')].some(e => e.getClientRects().length), null, { timeout: 90000 }); await page.waitForTimeout(500);
      const m = await page.evaluate(() => {
        const ms = [...document.querySelectorAll('.fin-modal')]; const d = ms[ms.length - 1]; const b = d.getBoundingClientRect(); const body = d.querySelector('.fin-modal-body');
        const foot = [...d.querySelectorAll('.fin-modal-foot button')].map(x => { const r = x.getBoundingClientRect(); const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return `${x.textContent.trim()}${hit && (hit === x || x.contains(hit)) ? '' : ' ✗'}`; });
        const a = document.activeElement;
        return { title: d.getAttribute('aria-label'), w: Math.round(b.width), h: Math.round(b.height), top: Math.round(b.top), bottom: Math.round(b.bottom), bodyScroll: body ? body.scrollHeight - body.clientHeight : 0, bodyContent: body ? body.scrollHeight : 0, foot,
          focus: `${a.tagName.toLowerCase()}${a.id ? '#' + a.id : ''}${a.classList.contains('fin-modal') ? '.fin-modal' : ''}`, controls: [...new Set([...d.querySelectorAll('.fin-input, .fin-btn')].map(x => Math.round(x.getBoundingClientRect().height)).filter(Boolean))].sort((p, q) => p - q) };
      });
      await page.screenshot({ path: `${OUT}/fin-${key}-modal-${name}.png` });
      m.contrast = await auditAll('.fin-modal:last-of-type .fin-modal-body');
      report[key]['modal-' + name] = m;
      console.log(key, ('modal ' + name).padEnd(18), `${m.w}×${m.h} cuerpo ${m.bodyContent}px (scroll ${m.bodyScroll})`, '| pie', m.foot.join(' / '), '| foco', m.focus, '| alturas', m.controls.join(','), `| contraste ${m.contrast.length}`);
      await page.keyboard.press('Escape'); await page.waitForTimeout(400);
    };
    const tab = async (label) => { await page.locator('.fin-tab', { hasText: label }).first().click(); };

    await page.goto(BASE + '/login'); await page.fill('input[type="email"]', process.env.AUDIT_EMAIL); await page.fill('input[type="password"]', process.env.AUDIT_PASSWORD); await page.keyboard.press('Enter'); await page.waitForURL(/pos/);
    await page.waitForLoadState('networkidle');
    await page.locator('aside, nav, .oh-sidebar').getByText('Finanzas').first().click();
    await page.waitForSelector('.fin-tabs');
    await measureTab('resumen');
    await page.getByRole('button', { name: 'Informe del mes' }).click(); await measureModal('informe-inventario');
    await tab('PedidosYa'); await measureTab('pedidosya');
    await tab('Compras y gastos'); await measureTab('compras-gastos');
    await page.getByRole('button', { name: 'Registrar gasto', exact: true }).click(); await measureModal('registrar-gasto');
    await page.locator('.fin-card-head').getByRole('button', { name: /Registrar compra/ }).click(); await measureModal('registrar-compra');
    await tab('Gastos fijos'); await measureTab('gastos-fijos');
    await page.getByRole('button', { name: /Agregar gasto fijo/ }).click(); await measureModal('gasto-fijo');
    await tab('Costos y margen'); await measureTab('costos');
    await page.getByRole('button', { name: 'Cargar recetas base' }).click(); await measureModal('recetas-base');
    await tab('Categorías'); await measureTab('categorias');
    await page.getByRole('button', { name: /Agregar/ }).first().click(); await measureModal('categoria');
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(`${OUT}/finanzas-report.json`, JSON.stringify(report, null, 1));
  const all = [];
  for (const [k, r] of Object.entries(report)) for (const [s, v] of Object.entries(r)) for (const c of v.contrast || []) all.push(`${k} ${s}: ${c.ratio} < ${c.min} «${c.text}» (${c.cls || c.tag}, ${c.size}px) ${c.color} sobre ${c.bg}${c.img ? ' [fondo con imagen/degradado]' : ''}`);
  console.log(`\nContraste: ${all.length} textos bajo AA`); for (const a of all) console.log(' -', a);
})().catch(e => { console.error(e); process.exit(1); });
