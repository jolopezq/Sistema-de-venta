import { test, expect } from '@playwright/test';

/**
 * Recuperar la contraseña desde el login con un código enviado al correo (docs/PLAN_RECUPERAR_CONTRASENA.md).
 * API simulada; el flujo contra el backend real se probó aparte con el correo en el log.
 */

const json = (body, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(body) });
const SENT = 'Si el correo está registrado, te enviamos un código de 6 dígitos. Vence en 15 minutos.';
const CODE_ERROR = 'El código no es correcto o ya venció. Revísalo o pide uno nuevo.';

/** API de recuperación: el código válido es 482913. Devuelve lo que el navegador envió. */
async function mockApi(page, { codeStatus = 200, resetExpired = false } = {}) {
  const calls = [];
  await page.route('**/api/password/code', (r) => {
    calls.push({ step: 'code', body: JSON.parse(r.request().postData()) });
    return codeStatus === 429
      ? r.fulfill(json({ message: 'Demasiados intentos. Espera unos minutos y vuelve a intentar.' }, 429))
      : r.fulfill(json({ message: SENT, resend_after: 60 }));
  });
  await page.route('**/api/password/verify', (r) => {
    const body = JSON.parse(r.request().postData());
    calls.push({ step: 'verify', body });
    return body.code === '482913'
      ? r.fulfill(json({ reset_token: 't'.repeat(64), expires_in: 600 }))
      : r.fulfill(json({ message: CODE_ERROR, errors: { code: [CODE_ERROR] } }, 422));
  });
  await page.route('**/api/password/reset', (r) => {
    calls.push({ step: 'reset', body: JSON.parse(r.request().postData()) });
    return resetExpired
      ? r.fulfill(json({ message: 'x', errors: { reset_token: ['El permiso para cambiar la contraseña venció. Pide un código nuevo.'] } }, 422))
      : r.fulfill(json({ message: 'Listo: tu contraseña se cambió. Inicia sesión con la nueva.' }));
  });
  return calls;
}

async function toStep2(page, email = 'dueno@ohana.test') {
  await page.goto(`/recuperar-contrasena?email=${encodeURIComponent(email)}`);
  await page.getByRole('button', { name: 'Enviar código' }).click();
  await expect(page.locator('#rp-code')).toBeFocused();
}

test.describe('Recuperar contraseña', () => {
  test('El login enlaza a la recuperación con el correo ya escrito', async ({ page }) => {
    await page.goto('/login');
    await page.locator('input[type="email"]').fill('cajera@ohana.test');
    await page.getByRole('link', { name: '¿Olvidaste tu contraseña?' }).click();
    await expect(page).toHaveURL(/\/recuperar-contrasena\?email=cajera(@|%40)ohana\.test/);
    await expect(page.locator('#rp-email')).toHaveValue('cajera@ohana.test');
    await expect(page.locator('#rp-email')).toBeFocused();
    await expect(page.getByText('Paso 1 de 3')).toBeVisible();
  });

  test('Flujo completo: código, verificación, contraseña nueva y vuelta al login', async ({ page }) => {
    const calls = await mockApi(page);
    await toStep2(page);
    await expect(page.locator('.rp-info')).toContainText(SENT);
    await expect(page.locator('.rp-info')).toContainText('Spam');
    await expect(page.getByRole('button', { name: /Reenviar código en \d+ s/ })).toBeDisabled();
    await page.keyboard.type('482913');
    await page.keyboard.press('Enter');
    await expect(page.locator('#rp-password')).toBeFocused();
    await expect(page.getByText('Paso 3 de 3')).toBeVisible();
    await page.locator('#rp-password').fill('NuevaClave1');
    await page.locator('#rp-confirm').fill('NuevaClave1');
    await page.getByRole('button', { name: 'Guardar contraseña' }).click();
    await expect(page).toHaveURL(/\/login\?email=dueno(@|%40)ohana\.test&restablecida=1/);
    await expect(page.getByRole('status')).toHaveText('Listo: tu contraseña se cambió. Inicia sesión con la nueva.');
    await expect(page.locator('input[type="email"]')).toHaveValue('dueno@ohana.test');
    await expect(page.locator('input[type="password"]')).toHaveValue('');
    expect(calls).toEqual([
      { step: 'code', body: { email: 'dueno@ohana.test' } },
      { step: 'verify', body: { email: 'dueno@ohana.test', code: '482913' } },
      { step: 'reset', body: { email: 'dueno@ohana.test', reset_token: 't'.repeat(64), password: 'NuevaClave1', password_confirmation: 'NuevaClave1' } },
    ]);
  });

  test('Código equivocado: aviso junto al campo y el foco vuelve al código', async ({ page }) => {
    await mockApi(page);
    await toStep2(page);
    await page.keyboard.type('000000');
    await page.keyboard.press('Enter');
    await expect(page.locator('#rp-code-err')).toHaveText(CODE_ERROR);
    await expect(page.locator('#rp-code')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#rp-code')).toBeFocused();
  });

  test('El código acepta solo dígitos (pegado con espacios o texto)', async ({ page }) => {
    await mockApi(page);
    await toStep2(page);
    await page.locator('#rp-code').pressSequentially('Código: 482 913');
    await expect(page.locator('#rp-code')).toHaveValue('482913');
    await page.locator('#rp-code').fill('');
    await page.locator('#rp-code').pressSequentially('12');
    await page.getByRole('button', { name: 'Verificar código' }).click();
    await expect(page.locator('#rp-code-err')).toHaveText('El código tiene 6 dígitos.');
  });

  test('Demasiados intentos: aviso en español', async ({ page }) => {
    await mockApi(page, { codeStatus: 429 });
    await page.goto('/recuperar-contrasena?email=dueno%40ohana.test');
    await page.getByRole('button', { name: 'Enviar código' }).click();
    await expect(page.getByRole('alert')).toHaveText('Demasiados intentos. Espera unos minutos y vuelve a intentar.');
    await expect(page.locator('#rp-email')).toBeVisible();
  });

  test('Contraseñas distintas o cortas no se envían', async ({ page }) => {
    const calls = await mockApi(page);
    await toStep2(page);
    await page.keyboard.type('482913');
    await page.keyboard.press('Enter');
    await page.locator('#rp-password').fill('12345');
    await page.locator('#rp-confirm').fill('12345');
    await page.getByRole('button', { name: 'Guardar contraseña' }).click();
    await expect(page.locator('#rp-password-err')).toHaveText('La contraseña nueva debe tener al menos 6 caracteres.');
    await page.locator('#rp-password').fill('NuevaClave1');
    await page.locator('#rp-confirm').fill('NuevaClave2');
    await page.getByRole('button', { name: 'Guardar contraseña' }).click();
    await expect(page.locator('#rp-confirm-err')).toHaveText('Las dos contraseñas no coinciden.');
    await expect(page.locator('#rp-confirm')).toBeFocused();
    expect(calls.filter((c) => c.step === 'reset')).toHaveLength(0);
    await page.getByRole('button', { name: 'Mostrar contraseña' }).click();
    await expect(page.locator('#rp-password')).toHaveAttribute('type', 'text');
  });

  test('Si el permiso venció, vuelve al paso 1 con el aviso', async ({ page }) => {
    await mockApi(page, { resetExpired: true });
    await toStep2(page);
    await page.keyboard.type('482913');
    await page.keyboard.press('Enter');
    await page.locator('#rp-password').fill('NuevaClave1');
    await page.locator('#rp-confirm').fill('NuevaClave1');
    await page.getByRole('button', { name: 'Guardar contraseña' }).click();
    await expect(page.getByRole('alert')).toHaveText('El permiso para cambiar la contraseña venció. Pide un código nuevo.');
    await expect(page.locator('#rp-email')).toBeFocused();
  });

  test('«Usar otro correo» vuelve al paso 1', async ({ page }) => {
    await mockApi(page);
    await toStep2(page);
    await page.getByRole('button', { name: 'Usar otro correo' }).click();
    await expect(page.locator('#rp-email')).toBeFocused();
    await expect(page.locator('#rp-email')).toHaveValue('dueno@ohana.test');
  });

  for (const vp of [{ width: 1366, height: 611 }, { width: 1093, height: 489 }, { width: 390, height: 844 }]) {
    test(`Entra en ${vp.width}×${vp.height}: sin scroll lateral y con el botón a la vista en los 3 pasos`, async ({ page }) => {
      await page.setViewportSize(vp);
      await mockApi(page);
      const fits = async () => {
        const g = await page.evaluate(() => ({ x: document.documentElement.scrollWidth - innerWidth, btn: document.querySelector('.login-card .btn-primary').getBoundingClientRect().bottom, vh: innerHeight }));
        expect(g.x).toBeLessThanOrEqual(0);
        expect(g.btn).toBeLessThanOrEqual(g.vh);
      };
      await page.goto('/recuperar-contrasena?email=dueno%40ohana.test');
      await expect(page.locator('#rp-email')).toBeFocused();
      await fits();
      await page.getByRole('button', { name: 'Enviar código' }).click();
      await expect(page.locator('#rp-code')).toBeFocused();
      await fits();
      await page.keyboard.type('482913');
      await page.keyboard.press('Enter');
      await expect(page.locator('#rp-password')).toBeFocused();
      await fits();
    });
  }

  test('Con la sesión abierta, la recuperación lleva a la pantalla de inicio', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('auth_token', 'mock'));
    await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
    await page.route('**/api/me', (r) => r.fulfill(json({
      user: { id: 1, name: 'Dueño', email: 'dueno@ohana.test', role: 'super_admin' }, permissions: [],
      effective_permissions: { pos: 'edit', register_self: 'edit', delivery_ops: 'edit', crm_read: 'edit', voids: 'edit', register_others: 'edit', catalog: 'edit', inventory: 'edit', crm_config: 'edit', reports: 'edit', users: 'edit', audit: 'edit' },
    })));
    await page.goto('/recuperar-contrasena');
    await expect(page).not.toHaveURL(/recuperar-contrasena/);
  });
});
