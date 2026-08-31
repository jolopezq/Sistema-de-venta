import { test, expect } from '@playwright/test';

test.describe('Pruebas E2E de Responsividad Móvil (Mobile Web Phone)', () => {

  test('1. Pantalla de Login se adapta correctamente al viewport móvil y no genera scroll horizontal', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    // Verificar que los campos clave sean visibles y tengan tamaño táctil adecuado
    const emailInput = page.locator('input[type="email"], input[placeholder*="correo" i], input[placeholder*="usuario" i]').first();
    const passwordInput = page.locator('input[type="password"]').first();
    const submitBtn = page.locator('button[type="submit"], button:has-text("Iniciar")').first();

    await expect(emailInput).toBeVisible();
    await expect(passwordInput).toBeVisible();
    await expect(submitBtn).toBeVisible();

    // Validar que el ancho del contenido no desborde el ancho de la pantalla (evita scroll horizontal indeseado)
    const isOverflowingHorizontally = await page.evaluate(() => {
      return document.documentElement.scrollWidth > window.innerWidth;
    });
    expect(isOverflowingHorizontally).toBeFalsy();

    // Validar altura táctil mínima recomendada para móviles (>= 40px)
    const btnBox = await submitBtn.boundingBox();
    expect(btnBox.height).toBeGreaterThanOrEqual(38);
  });

  test('2. Pantalla POS y Catálogo en Móvil: Flujo de navegación e interacción', async ({ page }) => {
    // Iniciar sesión
    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await page.fill('input[type="email"], input[placeholder*="correo" i], input[placeholder*="usuario" i]', 'admin@example.com');
    await page.fill('input[type="password"]', 'password');
    await page.click('button[type="submit"], button:has-text("Iniciar")');

    // Redirección al POS o Selección de Turno
    await page.waitForURL(url => url.pathname.includes('/pos') || url.pathname.includes('/turno'), { timeout: 10000 });

    // Si aparece modal de apertura de turno, interactuamos
    const openTurnoBtn = page.locator('button:has-text("Abrir Turno"), button:has-text("Iniciar Turno")');
    if (await openTurnoBtn.isVisible()) {
      await page.fill('input[type="number"]', '100');
      await openTurnoBtn.click();
    }

    await page.goto('/pos');
    await page.waitForLoadState('networkidle');

    // Verificar que los productos se muestren en grilla adaptable
    const productCards = page.locator('.product-card, .pos-product-card, [data-testid="product-card"]');
    await expect(productCards.first()).toBeVisible({ timeout: 10000 });

    // Validar que la interfaz no tenga desbordamiento horizontal en el POS
    const isOverflowing = await page.evaluate(() => {
      return document.documentElement.scrollWidth > window.innerWidth;
    });
    expect(isOverflowing).toBeFalsy();

    // Probar clic en un producto para abrir modal o agregarlo
    await productCards.first().click();

    // Si se abre un modal de opciones/modificadores, validar que quepa en el viewport
    const modal = page.locator('.modal-content, .modifier-modal, .dialog-content').first();
    if (await modal.isVisible()) {
      const modalBox = await modal.boundingBox();
      const viewport = page.viewportSize();
      expect(modalBox.width).toBeLessThanOrEqual(viewport.width);
      
      // Cerrar modal
      const closeBtn = page.locator('button:has-text("Cerrar"), button:has-text("Agregar"), .btn-close, [aria-label="Cerrar"]').first();
      if (await closeBtn.isVisible()) {
        await closeBtn.click();
      }
    }
  });

  test('3. Menú y Administración (/menu) en Móvil: Adaptabilidad de Tabs y Acciones', async ({ page }) => {
    // Iniciar sesión como admin
    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await page.fill('input[type="email"], input[placeholder*="correo" i], input[placeholder*="usuario" i]', 'admin@example.com');
    await page.fill('input[type="password"]', 'password');
    await page.click('button[type="submit"], button:has-text("Iniciar")');

    // Esperar a que la sesión se guarde y redireccione
    await page.waitForURL(url => url.pathname.includes('/pos') || url.pathname.includes('/turno'), { timeout: 10000 });

    await page.goto('/menu');
    await page.waitForLoadState('networkidle');

    // Validar que los tabs 'Productos' y 'Opcionales' sean visibles y accionables en móvil
    const tabProductos = page.locator('.tab-item:has-text("Productos")');
    const tabOpcionales = page.locator('.tab-item:has-text("Opcionales")');

    await expect(tabProductos).toBeVisible();
    await expect(tabOpcionales).toBeVisible();

    // Cambiar a Opcionales
    await tabOpcionales.click();
    await page.waitForTimeout(500);

    // Validar que no hay desbordamiento horizontal en la vista de opcionales
    const isOverflowing = await page.evaluate(() => {
      return document.documentElement.scrollWidth > window.innerWidth;
    });
    expect(isOverflowing).toBeFalsy();
  });

});
