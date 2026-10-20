import { test, expect } from '@playwright/test';

const MOCK_ME = {
  user: {
    id: 1,
    name: 'Super Admin Dueño',
    email: 'superadmin@example.com',
    role: 'super_admin',
  },
  permissions: [],
  effective_permissions: {
    pos: 'edit',
    register_self: 'edit',
    delivery_ops: 'edit',
    crm_read: 'read',
    voids: 'edit',
    register_others: 'edit',
    catalog: 'edit',
    inventory: 'edit',
    crm_config: 'edit',
    reports: 'read',
    users: 'edit',
    audit: 'read',
  },
};

const MOCK_USERS = [
  { id: 1, name: 'Super Admin', email: 'super@example.com', role: 'super_admin' },
  { id: 2, name: 'Carlos Admin', email: 'admin@example.com', role: 'admin' },
  { id: 3, name: 'María Cajera', email: 'cajera1@example.com', role: 'cashier' },
  { id: 4, name: 'Juan Cajero', email: 'cajero2@example.com', role: 'cashier' },
  { id: 5, name: 'Pedro Cocina', email: 'cocina@example.com', role: 'kitchen' },
];

const MOCK_LEVELS = {
  kitchen: {
    pos: ['none'],
    register_self: ['none'],
    delivery_ops: ['none', 'read', 'edit'],
    crm_read: ['none'],
    voids: ['none'],
    register_others: ['none'],
    catalog: ['none'],
    inventory: ['none'],
    crm_config: ['none'],
    reports: ['none'],
  },
  cashier: {
    pos: ['none', 'read', 'edit'],
    register_self: ['none', 'read', 'edit'],
    delivery_ops: ['none', 'read', 'edit'],
    crm_read: ['none', 'read'],
    voids: ['none'],
    register_others: ['none'],
    catalog: ['none'],
    inventory: ['none'],
    crm_config: ['none'],
    reports: ['none'],
  },
  admin: {
    pos: ['none', 'read', 'edit'],
    register_self: ['none', 'read', 'edit'],
    delivery_ops: ['none', 'read', 'edit'],
    crm_read: ['none', 'read'],
    voids: ['none', 'edit'],
    register_others: ['none', 'read', 'edit'],
    catalog: ['none', 'read', 'edit'],
    inventory: ['none', 'edit'],
    crm_config: ['none', 'edit'],
    reports: ['none', 'read'],
  },
};

const MOCK_DEFAULTS = {
  kitchen: {
    pos: 'none',
    register_self: 'none',
    delivery_ops: 'edit',
    crm_read: 'none',
    voids: 'none',
    register_others: 'none',
    catalog: 'none',
    inventory: 'none',
    crm_config: 'none',
    reports: 'none',
  },
  cashier: {
    pos: 'edit',
    register_self: 'edit',
    delivery_ops: 'edit',
    crm_read: 'read',
    voids: 'none',
    register_others: 'none',
    catalog: 'none',
    inventory: 'none',
    crm_config: 'none',
    reports: 'none',
  },
  admin: {
    pos: 'edit',
    register_self: 'edit',
    delivery_ops: 'edit',
    crm_read: 'read',
    voids: 'edit',
    register_others: 'edit',
    catalog: 'edit',
    inventory: 'edit',
    crm_config: 'edit',
    reports: 'read',
  },
};

const MOCK_SAVED_PERMISSIONS = [
  // kitchen
  { role: 'kitchen', module: 'delivery_ops', access_level: 'edit' },
  // cashier
  { role: 'cashier', module: 'pos', access_level: 'edit' },
  { role: 'cashier', module: 'register_self', access_level: 'edit' },
  { role: 'cashier', module: 'delivery_ops', access_level: 'edit' },
  { role: 'cashier', module: 'crm_read', access_level: 'read' },
  // admin
  { role: 'admin', module: 'pos', access_level: 'edit' },
  { role: 'admin', module: 'register_self', access_level: 'edit' },
  { role: 'admin', module: 'delivery_ops', access_level: 'edit' },
  { role: 'admin', module: 'crm_read', access_level: 'read' },
  { role: 'admin', module: 'voids', access_level: 'edit' },
  { role: 'admin', module: 'register_others', access_level: 'edit' },
  { role: 'admin', module: 'catalog', access_level: 'edit' },
  { role: 'admin', module: 'inventory', access_level: 'edit' },
  { role: 'admin', module: 'crm_config', access_level: 'edit' },
  { role: 'admin', module: 'reports', access_level: 'read' },
];

/**
 * Configura la simulación de red con page.route para autenticación y permisos.
 */
async function setupMockApi(page, options = {}) {
  let { failPermissionsCount = 0, onPutPermissions = null } = options;

  // 1. Interceptar cualquier otra llamada a la API con respuesta 200 para evitar deslogueos no intencionales
  await page.route('**/api/**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [] }),
    });
  });

  // 2. Rutas específicas (en Playwright las rutas registradas después tienen precedencia)
  await page.route('**/api/me', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(MOCK_ME),
    });
  });

  await page.route('**/api/users', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: MOCK_USERS }),
    });
  });

  await page.route('**/api/inventory/reorder-summary', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ count: 0, items: [] }),
    });
  });

  await page.route('**/api/permissions', async (route) => {
    if (route.request().method() === 'GET') {
      if (failPermissionsCount > 0) {
        failPermissionsCount--;
        await route.fulfill({
          status: 500,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'Error interno simulado al cargar permisos.' }),
        });
      } else {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            permissions: MOCK_SAVED_PERMISSIONS,
            modules: Object.keys(MOCK_LEVELS.admin),
            ceilings: {},
            defaults: MOCK_DEFAULTS,
            restrictions: {},
            levels: MOCK_LEVELS,
          }),
        });
      }
    } else if (route.request().method() === 'PUT') {
      if (onPutPermissions) {
        const postData = route.request().postDataJSON();
        await onPutPermissions(postData);
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Matriz guardada con éxito.', permissions: [] }),
      });
    } else {
      await route.continue();
    }
  });

  // Inyectar token en localStorage antes de que cargue la app
  await page.addInitScript(() => {
    window.localStorage.setItem('auth_token', 'mock_super_admin_jwt');
  });
}

test.describe('Pantalla de Roles y Permisos — Portátil de caja (1366 × 768 y variantes)', () => {

  test('1. Verificación de scroll en viewports de portátil (1366 × 611, 641, 697, 768)', async ({ page }) => {
    await setupMockApi(page);

    const viewports = [
      { width: 1366, height: 611, desc: 'Chrome con barra de marcadores (peor caso)' },
      { width: 1366, height: 641, desc: 'Chrome maximizado' },
      { width: 1366, height: 697, desc: 'Instalado como app' },
      { width: 1366, height: 768, desc: 'Pantalla completa F11' },
    ];

    for (const vp of viewports) {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.goto('/users');
      await expect(page.locator('.oh-title-text')).toBeVisible();

      // Cambiar a la pestaña de permisos
      const tabPermisos = page.locator('.oh-tab:has-text("Roles y permisos")');
      await tabPermisos.click();
      await expect(page.locator('.perm-panel-body')).toBeVisible();

      // 1. Criterio fundamental: NUNCA scroll horizontal
      const hasHorizontalScroll = await page.evaluate(() => {
        const rootOverflow = document.documentElement.scrollWidth > window.innerWidth;
        const shellContent = document.querySelector('.oh-shell-content');
        const shellOverflow = shellContent ? shellContent.scrollWidth > shellContent.clientWidth : false;
        return rootOverflow || shellOverflow;
      });
      expect(hasHorizontalScroll, `Scroll horizontal detectado en ${vp.width}x${vp.height} (${vp.desc})`).toBeFalsy();

      // Probar rol Administrador con 10 módulos
      const btnAdmin = page.locator('.role-tab-btn:has-text("Administrador")');
      await btnAdmin.click();

      const rows = page.locator('.perm-row');
      await expect(rows).toHaveCount(10);

      // Cambiar nivel en las 3 primeras filas mediante su data-module
      await page.locator('.perm-row[data-module="pos"]').locator('.seg-btn').first().click(); // pos -> none
      await page.locator('.perm-row[data-module="register_self"]').locator('.seg-btn').first().click(); // register_self -> none
      await page.locator('.perm-row[data-module="delivery_ops"]').locator('.seg-btn').first().click(); // delivery_ops -> none

      // Verificar que el badge de 3 cambios aparece en la barra superior
      const statusChanges = page.locator('.status-changes');
      await expect(statusChanges).toContainText('3 cambios sin guardar');

      // En 1366 × 611 (peor caso), Administrador con 3 cambios debe caber sin scroll vertical en .oh-shell-content
      if (vp.height === 611) {
        const hasVerticalScroll = await page.evaluate(() => {
          const shell = document.querySelector('.oh-shell-content');
          if (!shell) return false;
          // Permitir hasta 2px de tolerancia por subpixel / redondeo
          return (shell.scrollHeight - shell.clientHeight) > 2;
        });
        expect(hasVerticalScroll, 'En 1366x611 el rol Administrador con 3 cambios debe entrar sin scroll vertical').toBeFalsy();
      }

      // Descartar cambios para la siguiente iteración
      await page.locator('.btn-discard').click();
    }
  });

  test('2. Comportamiento responsive en anchos menores: 1024 × 768 y 390 × 844 sin scroll horizontal', async ({ page }) => {
    await setupMockApi(page);

    const responsiveViewports = [
      { width: 1024, height: 768 },
      { width: 390, height: 844 },
    ];

    for (const vp of responsiveViewports) {
      await page.setViewportSize(vp);
      await page.goto('/users');
      await expect(page.locator('.oh-title-text')).toBeVisible();

      const tabPermisos = page.locator('.oh-tab:has-text("Roles y permisos")');
      await tabPermisos.click();
      await expect(page.locator('.perm-panel-body')).toBeVisible();

      const hasHorizontalScroll = await page.evaluate(() => {
        const rootOverflow = document.documentElement.scrollWidth > window.innerWidth;
        const shellContent = document.querySelector('.oh-shell-content');
        const shellOverflow = shellContent ? shellContent.scrollWidth > shellContent.clientWidth : false;
        return rootOverflow || shellOverflow;
      });
      expect(hasHorizontalScroll, `Scroll horizontal detectado en ${vp.width}x${vp.height}`).toBeFalsy();
    }
  });

  test('3. Interacciones: contador de cambios, descarte, aviso de rol sin pantallas y PUT diferencial', async ({ page }) => {
    let capturedPutPayload = null;

    await setupMockApi(page, {
      onPutPermissions: (payload) => {
        capturedPutPayload = payload;
      },
    });

    await page.setViewportSize({ width: 1366, height: 641 });
    await page.goto('/users');
    await expect(page.locator('.oh-title-text')).toBeVisible();

    await page.locator('.oh-tab:has-text("Roles y permisos")').click();
    await expect(page.locator('.perm-panel-body')).toBeVisible();

    // Inicialmente: 0 cambios, botón Guardar deshabilitado
    const btnSave = page.locator('.btn-save');
    const btnDiscard = page.locator('.btn-discard');
    await expect(btnSave).toBeDisabled();
    await expect(btnDiscard).toBeDisabled();

    // Seleccionar Cajero y cambiar Ventas a "Ver ventas" (1 cambio sin quitar pantallas)
    await page.locator('.role-tab-btn:has-text("Cajero")').click();
    const posRow = page.locator('.perm-row[data-module="pos"]');
    await posRow.locator('.seg-btn:has-text("Ver ventas")').click();

    // Contador sube a 1 cambio sin guardar
    await expect(page.locator('.status-changes')).toContainText('1 cambio sin guardar');
    await expect(btnSave).toBeEnabled();
    await expect(btnDiscard).toBeEnabled();

    // Ahora seleccionar Cocina y quitarle Pedidos y comandas -> Cocina queda sin pantallas
    await page.locator('.role-tab-btn:has-text("Cocina")').click();
    const deliveryRow = page.locator('.perm-row[data-module="delivery_ops"]');
    await deliveryRow.locator('.seg-btn:has-text("Sin acceso")').click();

    // Aviso de rol sin pantallas debe aparecer
    await expect(page.locator('.status-warning')).toContainText('La cuenta de cocina quedará sin ninguna pantalla.');

    // Descartar cambios: todo vuelve a la normalidad
    await btnDiscard.click();
    await expect(btnSave).toBeDisabled();
    await expect(btnDiscard).toBeDisabled();
    await expect(page.locator('.status-changes')).toHaveCount(0);
    await expect(page.locator('.status-warning')).toHaveCount(0);

    // Ahora hacer 2 cambios reales en Cajero
    await page.locator('.role-tab-btn:has-text("Cajero")').click();
    await posRow.locator('.seg-btn:has-text("Ver ventas")').click(); // edit -> read

    const crmRow = page.locator('.perm-row[data-module="crm_read"]');
    await crmRow.locator('.seg-btn:has-text("Sin acceso")').click(); // read -> none

    await expect(page.locator('.status-changes')).toContainText('2 cambios sin guardar');

    // Guardar cambios y verificar que el PUT solo lleva las 2 filas modificadas
    await btnSave.click();

    // Esperar mensaje de éxito
    await expect(page.locator('.status-saved')).toContainText('Cambios guardados');

    // Validar el payload del PUT capturado
    expect(capturedPutPayload).not.toBeNull();
    expect(capturedPutPayload.permissions).toHaveLength(2);
    expect(capturedPutPayload.permissions).toEqual(
      expect.arrayContaining([
        { role: 'cashier', module: 'pos', access_level: 'read' },
        { role: 'cashier', module: 'crm_read', access_level: 'none' },
      ])
    );
  });

  test('4. Restaurar a valores recomendados (Volver a lo recomendado)', async ({ page }) => {
    await setupMockApi(page);

    await page.setViewportSize({ width: 1366, height: 697 });
    await page.goto('/users');
    await expect(page.locator('.oh-title-text')).toBeVisible();

    await page.locator('.oh-tab:has-text("Roles y permisos")').click();
    await expect(page.locator('.perm-panel-body')).toBeVisible();

    // En Cajero, modificar un valor que lo aleje de los defaults
    await page.locator('.role-tab-btn:has-text("Cajero")').click();
    const btnRestore = page.locator('.btn-restore-defaults');
    await expect(btnRestore).toHaveCount(0); // Coincide con defaults

    const posRow = page.locator('.perm-row[data-module="pos"]');
    await posRow.locator('.seg-btn:has-text("Sin acceso")').click();

    // Ahora difiere de defaults: aparece el enlace
    await expect(btnRestore).toBeVisible();
    await btnRestore.click();

    // Se restaura al valor por defecto
    await expect(posRow.locator('.seg-btn.is-active')).toHaveText('Vender');
    await expect(btnRestore).toHaveCount(0);
  });

  test('5. Manejo de error de carga (500) y reintento', async ({ page }) => {
    await setupMockApi(page, { failPermissionsCount: 1 });

    await page.setViewportSize({ width: 1366, height: 611 });
    await page.goto('/users');
    await expect(page.locator('.oh-title-text')).toBeVisible();

    await page.locator('.oh-tab:has-text("Roles y permisos")').click();

    // Aparece tarjeta de error y botón Reintentar
    const errorCard = page.locator('.state-card.error-card');
    await expect(errorCard).toBeVisible();
    await expect(errorCard).toContainText('No se pudo cargar la configuración de permisos');

    const btnRetry = page.locator('.btn-retry');
    await expect(btnRetry).toBeVisible();

    // Al hacer clic en Reintentar, se recupera
    await btnRetry.click();
    await expect(page.locator('.perm-panel-body')).toBeVisible();
  });

  test('6. La vista previa del menú refleja screensFor() en tiempo real', async ({ page }) => {
    await setupMockApi(page);

    await page.setViewportSize({ width: 1366, height: 611 });
    await page.goto('/users');
    await expect(page.locator('.oh-title-text')).toBeVisible();

    await page.locator('.oh-tab:has-text("Roles y permisos")').click();
    await expect(page.locator('.perm-panel-body')).toBeVisible();

    // 1. Cocina: solo ve Delivery con "Abre aquí"
    await page.locator('.role-tab-btn:has-text("Cocina")').click();
    const mockNav = page.locator('.mock-nav');
    await expect(mockNav.locator('.mock-item')).toHaveCount(1);
    await expect(mockNav.locator('.mock-item')).toContainText('Delivery');
    await expect(mockNav.locator('.tag-landing')).toContainText('Abre aquí');

    // 2. Cajero: ve Vender (Abre aquí), Caja y Delivery
    await page.locator('.role-tab-btn:has-text("Cajero")').click();
    await expect(mockNav.locator('.mock-item')).toHaveCount(3);
    const cashierItems = mockNav.locator('.mock-item');
    await expect(cashierItems.nth(0)).toContainText('Vender');
    await expect(cashierItems.nth(0).locator('.tag-landing')).toContainText('Abre aquí');
    await expect(cashierItems.nth(1)).toContainText('Caja');
    await expect(cashierItems.nth(2)).toContainText('Delivery');

    // 3. Si al cajero se le quita 'pos' (Ventas -> none), ahora su landing es Caja
    const posRow = page.locator('.perm-row[data-module="pos"]');
    await posRow.locator('.seg-btn:has-text("Sin acceso")').click();
    await expect(mockNav.locator('.mock-item')).toHaveCount(2);
    await expect(mockNav.locator('.mock-item').first()).toContainText('Caja');
    await expect(mockNav.locator('.mock-item').first().locator('.tag-landing')).toContainText('Abre aquí');
  });

});
