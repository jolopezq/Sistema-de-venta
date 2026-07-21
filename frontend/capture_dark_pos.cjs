const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 1280, height: 800 }
  });
  const page = await context.newPage();
  
  // Navigate to login
  await page.goto('http://localhost:5173/login');
  await page.waitForLoadState('networkidle');
  
  // login
  await page.fill('input[type="email"]', 'admin@ohana.com');
  await page.fill('input[type="password"]', 'admin123');
  await page.click('button[type="submit"]');
  await page.waitForURL('http://localhost:5173/pos', { timeout: 10000 });
  await page.waitForLoadState('networkidle');

  // Turn dark mode on
  await page.evaluate(() => {
    localStorage.setItem('ohana_theme', 'dark');
    document.documentElement.classList.add('dark');
  });

  // Take screenshot
  const outPath = '/Users/jolopez/.gemini/antigravity-ide/brain/2d97a6db-6c70-4ccd-8f41-1919318c571d/pos_dark_mode_verify.png';
  await page.screenshot({ path: outPath });
  console.log('Saved to: ' + outPath);
  
  await browser.close();
})();
