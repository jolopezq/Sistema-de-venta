/** Flujo solo con teclado en la Toshiba al 125 % (1093 × 489, DPR 1.25) contra el backend real. */
const { chromium } = require('playwright');
const OUT = process.argv[2];
(async () => {
  const b = await chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM, args: ['--no-sandbox'] } : {});
  const ctx = await b.newContext({ viewport: { width: 1093, height: 489 }, deviceScaleFactor: 1.25 });
  const p = await ctx.newPage(); p.setDefaultTimeout(90000);
  const focus = () => p.evaluate(() => { const a = document.activeElement; return `${a.tagName.toLowerCase()}${a.id ? '#' + a.id : ''}${a.getAttribute('aria-label') ? '[' + a.getAttribute('aria-label') + ']' : ''}${a.classList.contains('fin-modal') ? '.fin-modal' : ''}`; });
  const inModal = () => p.evaluate(() => { const ms = [...document.querySelectorAll('.fin-modal')]; const m = ms[ms.length - 1]; return !!m && m.contains(document.activeElement); });
  const results = [];
  const check = (name, ok, extra = '') => { results.push(`${ok ? '✔' : '✘'} ${name}${extra ? ' — ' + extra : ''}`); };

  await p.goto((process.env.AUDIT_URL || 'http://127.0.0.1:5173') + '/login'); await p.fill('input[type="email"]', process.env.AUDIT_EMAIL); await p.fill('input[type="password"]', process.env.AUDIT_PASSWORD); await p.keyboard.press('Enter'); await p.waitForURL(/pos/);
  await p.waitForLoadState('networkidle');
  await p.locator('aside, nav, .oh-sidebar').getByText('Inventario').first().click();
  await p.waitForSelector('.inv-table tbody tr'); await p.waitForLoadState('networkidle');

  // 1. Registrar compra desde la cabecera, todo con teclado
  const hdr = p.locator('.inv-daily .inv-hbtn', { hasText: 'Registrar compra' });
  await hdr.focus(); await p.keyboard.press('Enter');
  await p.waitForSelector('.pm-line'); await p.waitForTimeout(400);
  check('Compra: el foco empieza en «Insumo»', (await focus()) === 'select#pm-ing-1', await focus());
  await p.keyboard.type('Kiwi'); await p.waitForTimeout(150);
  check('Compra: con teclas se elige el insumo', (await p.locator('#pm-ing-1 option:checked').innerText()).startsWith('Kiwi'), await p.locator('#pm-ing-1 option:checked').innerText());
  const order = [];
  for (let k = 0; k < 30; k++) { await p.keyboard.press('Tab'); order.push(await focus()); if (!(await inModal())) { order.push('⟂ FUERA'); break; } }
  check('Compra: Tab nunca sale de la ventana (30 pulsaciones)', !order.includes('⟂ FUERA'));
  results.push('   orden de Tab: ' + [...new Set(order)].join(' → '));
  // Rellenar y guardar con teclado
  await p.locator('#pm-qty-1').focus(); await p.keyboard.type('1');
  await p.keyboard.press('Tab'); // Se compra en
  await p.keyboard.press('Tab'); await p.keyboard.type('28');
  check('Compra: Tab de Cantidad pasa por «Se compra en» y llega a «Pagado»', (await focus()) === 'input#pm-total-1', await focus());
  await p.locator('#pm-cat').focus(); await p.keyboard.type('Ingredientes');
  const guardar = p.locator('.fin-modal .fin-modal-foot button', { hasText: /Guardar/ });
  await guardar.focus(); await p.keyboard.press('Enter');
  await p.waitForSelector('.pm-line', { state: 'detached', timeout: 90000 }); await p.waitForTimeout(400);
  check('Compra: al guardar, el foco vuelve a «Registrar compra»', (await focus()).includes('[Registrar compra]'), await focus());
  const kiwi = await p.evaluate(async () => { const h = { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token')}` }; return (await (await fetch('/api/ingredients', { headers: h })).json()).data.find(i => i.name === 'Kiwi'); });
  check('Compra: el stock de Kiwi subió de 0,8 a 1,8 kg', Math.abs(kiwi.current_stock - 1.8) < 1e-6, `stock ${kiwi.current_stock}, costo ${kiwi.unit_cost}, prom ${kiwi.weighted_avg_cost}`);

  // 2. Desde la fila: foco en Cantidad; Escape devuelve el foco a la herramienta
  const tool = p.locator('button[aria-label="Registrar compra de Granola"]');
  await tool.focus(); await p.keyboard.press('Enter'); await p.waitForSelector('.pm-line'); await p.waitForTimeout(400);
  check('Compra desde la fila: el foco empieza en «Cantidad»', (await focus()) === 'input#pm-qty-1', await focus());
  await p.keyboard.press('Escape'); await p.waitForTimeout(300);
  check('Escape: el foco vuelve a la herramienta de la fila', (await focus()) === 'button[Registrar compra de Granola]', await focus());

  // 3. Merma desde la fila y desde la cabecera
  await p.locator('button[aria-label="Registrar merma de Granola"]').focus(); await p.keyboard.press('Enter'); await p.waitForSelector('.fin-modal'); await p.waitForTimeout(500);
  check('Merma desde la fila: foco en «Cantidad perdida»', (await focus()) === 'input#wm-amount', await focus());
  await p.keyboard.press('Escape'); await p.waitForTimeout(300);
  await p.locator('.inv-daily .inv-hbtn', { hasText: 'Registrar merma' }).focus(); await p.keyboard.press('Enter'); await p.waitForSelector('.fin-modal'); await p.waitForTimeout(500);
  check('Merma desde la cabecera: foco en «Buscar»', (await focus()) === 'input#wm-search', await focus());
  await p.keyboard.press('Escape'); await p.waitForTimeout(300);

  // 4. Conteo: foco en el primer «Contado»; Enter pasa al siguiente
  await p.locator('.inv-daily .inv-hbtn', { hasText: 'Contar stock' }).focus(); await p.keyboard.press('Enter'); await p.waitForSelector('.cm-input'); await p.waitForTimeout(500);
  const f4 = await focus();
  check('Conteo: foco en el primer «Contado»', f4.startsWith('input[Contado de'), f4);
  await p.keyboard.type('90'); await p.keyboard.press('Enter');
  const f4b = await focus();
  check('Conteo: Enter pasa al siguiente insumo', f4b.startsWith('input[Contado de') && f4b !== f4, f4b);
  await p.keyboard.press('Escape'); await p.waitForTimeout(300);

  // 5. Más → Categorías: foco en Nombre; Escape vuelve a «Más»
  await p.locator('.inv-more-btn').focus(); await p.keyboard.press('Enter'); await p.waitForTimeout(200);
  await p.keyboard.press('ArrowDown'); await p.keyboard.press('Enter'); await p.waitForSelector('.fin-modal'); await p.waitForTimeout(400);
  check('Más → Categorías: foco en «Nombre»', (await focus()) === 'input#inv-catf-name', await focus());
  await p.keyboard.press('Escape'); await p.waitForTimeout(300);
  check('Escape: el foco vuelve a «Más»', (await focus()).includes('inv-more') || (await p.evaluate(() => document.activeElement.classList.contains('inv-more-btn'))), await focus());

  // 6. Compras sugeridas (revisión): foco en la ventana, Tab va al primer control
  await p.locator('.inv-daily .inv-hbtn', { hasText: 'Compras sugeridas' }).focus(); await p.keyboard.press('Enter'); await p.waitForSelector('.fin-modal'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(300);
  check('Compras sugeridas: el foco entra a la ventana (sin tocar cantidades)', (await focus()).endsWith('.fin-modal'), await focus());
  await p.keyboard.press('Shift+Tab');
  check('Mayús+Tab desde la ventana va al último control', await inModal(), await focus());
  await p.keyboard.press('Escape'); await p.waitForTimeout(300);

  // 7. Nuevo insumo sigue empezando en «Nombre»
  await p.locator('.inv-new').focus(); await p.keyboard.press('Enter'); await p.waitForSelector('.fin-modal'); await p.waitForTimeout(300);
  check('Nuevo insumo: foco en «Nombre»', (await focus()) === 'input#inv-name', await focus());
  await p.keyboard.press('Escape'); await p.waitForTimeout(300);
  check('Escape: el foco vuelve a «Nuevo insumo»', (await focus()).includes('[Nuevo insumo]'), await focus());

  await p.screenshot({ path: `${OUT}/teclado-125-final.png` });
  console.log(results.join('\n'));
  console.log(`\n${results.filter(r => r.startsWith('✘')).length} fallas de ${results.filter(r => /^[✔✘]/.test(r)).length}`);
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
