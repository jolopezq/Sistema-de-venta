const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  
  await page.goto('http://localhost:5173/login');
  
  // Login
  await page.fill('input[type="email"]', 'admin@ohana.com');
  await page.fill('input[type="password"]', '123456');
  await page.click('button[type="submit"]');
  
  await page.waitForTimeout(2000); // Wait for login to complete

  // Toggle Dark Mode
  await page.evaluate(() => {
    document.documentElement.classList.add('dark');
    localStorage.setItem('theme', 'dark');
  });

  const views = [
    { name: 'pos', url: 'http://localhost:5173/pos' },
    { name: 'turno', url: 'http://localhost:5173/turno' },
    { name: 'delivery', url: 'http://localhost:5173/delivery' },
    { name: 'admin', url: 'http://localhost:5173/admin' },
    { name: 'inventario', url: 'http://localhost:5173/inventario' },
    { name: 'users', url: 'http://localhost:5173/users' },
  ];

  for (const view of views) {
    await page.goto(view.url);
    await page.waitForTimeout(1000); // Wait for rendering
    await page.screenshot({ path: path.join(__dirname, `screenshot_${view.name}.png`) });
    console.log(`Captured ${view.name}`);
  }

  await browser.close();
})();
