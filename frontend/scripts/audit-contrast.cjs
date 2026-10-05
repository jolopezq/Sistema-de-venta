/**
 * Auditoría de contraste (WCAG 2.1 AA) de las pantallas principales.
 *
 * Uso (con backend y frontend levantados):
 *   node scripts/audit-contrast.cjs <carpeta-salida> [dark|light]
 * Variables opcionales: AUDIT_URL (http://localhost:5173), AUDIT_EMAIL, AUDIT_PASSWORD.
 *
 * Genera una captura por pantalla y report.json con cada texto bajo el mínimo
 * (4.5:1 texto normal, 3:1 texto grande). Los textos sobre imágenes o degradados
 * se marcan con img=true porque el fondo real no se puede medir.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const OUT = process.argv[2] || 'contrast-report';
const THEME = process.argv[3] === 'light' ? 'light' : 'dark';
const BASE = process.env.AUDIT_URL || 'http://localhost:5173';
fs.mkdirSync(OUT, { recursive: true });
const AUDIT = () => {
  const parse = c => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const lum = ({ r, g, b }) => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
  const blend = (top, bot) => ({ r: top.r * top.a + bot.r * (1 - top.a), g: top.g * top.a + bot.g * (1 - top.a), b: top.b * top.a + bot.b * (1 - top.a), a: 1 });
  const bgOf = el => { const stack = []; let e = el; let img = false;
    while (e && e.nodeType === 1) { const cs = getComputedStyle(e); if (cs.backgroundImage !== 'none') img = true; const c = parse(cs.backgroundColor); if (c && c.a > 0) { stack.push(c); if (c.a >= 1) break; } e = e.parentElement; }
    let base = { r: 255, g: 255, b: 255, a: 1 }; for (let i = stack.length - 1; i >= 0; i--) base = blend(stack[i], base); return { c: base, img }; };
  const out = []; const seen = new Set();
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  while (walker.nextNode()) { const t = walker.currentNode; const txt = t.textContent.trim(); if (!txt) continue; const el = t.parentElement; if (!el) continue;
    const r = el.getBoundingClientRect(); if (r.width === 0 || r.height === 0 || r.bottom < 0 || r.top > innerHeight) continue;
    const cs = getComputedStyle(el); if (cs.visibility === 'hidden' || +cs.opacity === 0) continue;
    const fg = parse(cs.color); const { c: bg, img } = bgOf(el); const fgB = blend(fg, bg);
    const L1 = lum(fgB), L2 = lum(bg); const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
    const size = parseFloat(cs.fontSize), weight = +cs.fontWeight || 400; const large = size >= 24 || (size >= 18.66 && weight >= 700);
    const min = large ? 3 : 4.5; if (ratio < min) { const key = txt.slice(0, 40) + cs.color + bg.r + bg.g + bg.b; if (seen.has(key)) continue; seen.add(key);
      out.push({ text: txt.slice(0, 50), ratio: +ratio.toFixed(2), min, color: cs.color, bg: `rgb(${bg.r|0}, ${bg.g|0}, ${bg.b|0})`, img, cls: (el.className && el.className.baseVal === undefined ? el.className : '').toString().slice(0, 40), tag: el.tagName.toLowerCase(), size }); } }
  const bodyBg = getComputedStyle(document.body).backgroundColor;
  return { bodyBg, issues: out.sort((a, b) => a.ratio - b.ratio) };
};
(async () => {
  const browser = await chromium.launch({ headless: true });
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 } });
  await ctx.addInitScript((t) => { localStorage.setItem('theme', t); }, THEME);
  const page = await ctx.newPage(); page.setDefaultTimeout(6000);
  const report = {};
  const snap = async (name) => { await page.waitForTimeout(1200); await page.screenshot({ path: `${OUT}/${name}.png` }); report[name] = await page.evaluate(AUDIT); console.log(name, 'issues:', report[name].issues.length, 'bodyBg', report[name].bodyBg); };
  await page.goto(BASE + '/login'); await snap('01_login');
  await page.fill('input[type="email"]', process.env.AUDIT_EMAIL || 'superadmin@example.com'); await page.fill('input[type="password"]', process.env.AUDIT_PASSWORD || 'password');
  await page.keyboard.press('Enter'); await page.waitForURL(/pos/, { timeout: 10000 }).catch(() => {});
  const nav = async (label) => { await page.locator('aside, nav, .oh-sidebar').getByText(label, { exact: false }).first().click(); };
  await page.waitForTimeout(1500); await snap('02_pos');
  const card = page.locator('.product-card, [class*="product-card"]').first();
  if (await card.count()) { await card.click(); await snap('03_pos_modal_producto');
    const add = page.getByText('Agregar al Carrito', { exact: false }).first(); if (await add.count()) { await add.click(); }
    await page.waitForTimeout(500); await snap('03b_pos_carrito');
    const cobrar = page.getByText('Cobrar venta', { exact: false }).first(); if (await cobrar.count()) { await cobrar.click(); await snap('03c_checkout'); await page.mouse.click(874, 93); await page.waitForTimeout(600); }
  }
  for (const [n, label] of [['04_turno', 'Caja / Turno'], ['05_delivery', 'Delivery'], ['06_menu', 'Menú'], ['07_inventario', 'Inventario'], ['08_control_diario', 'Control Diario'], ['09_usuarios', 'Usuarios'], ['10_auditoria', 'Auditoría']]) {
    try { await nav(label); await snap(n); } catch (e) { console.log('no se pudo abrir', n, e.message.slice(0,120)); }
  }
  fs.writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 1));
  const total = Object.values(report).reduce((n, r) => n + r.issues.length, 0);
  console.log(`Tema ${THEME}: ${total} textos bajo el mínimo AA`);
  await browser.close();
})();
