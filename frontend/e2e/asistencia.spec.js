import { test, expect } from '@playwright/test';

/**
 * Reloj de asistencia, caja y sueldos (docs/PLAN_ASISTENCIA_Y_SUELDOS.md), con la API simulada.
 * El trabajador ve solo sus horas de entrada y salida; nunca montos, porcentajes ni motivos de revisión (§5.8).
 */

test.use({
  launchOptions: { args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] },
  permissions: ['camera'],
});
test.beforeEach(async ({ browserName }, testInfo) => {
  test.skip(testInfo.project.name !== 'Portátil de caja' || browserName !== 'chromium', 'Se prueba en la portátil de caja');
});

const json = (body, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(body) });
const DEVICE = { token: 'x'.repeat(64), name: 'Portátil de caja', id: 1 };
const ROSTER = {
  device: { name: 'Portátil de caja' },
  network_ok: true,
  server_time: new Date().toISOString(),
  people: [
    { id: 7, name: 'Ana Cajera', initials: 'AC', next: 'out', has_pin: true, face_enrolled: true },
    { id: 8, name: 'Luis Ayudante', initials: 'LA', next: 'in', has_pin: true, face_enrolled: false },
  ],
};

async function kioskSetup(page, { clock } = {}) {
  const sent = [];
  // Sin la librería de rostro: la foto se toma a mano (la marca igual se guarda; si hay rostro registrado, se revisa)
  await page.route('**/vendor/human/**', (r) => r.abort());
  await page.route('**/api/attendance/kiosk/roster', (r) => r.fulfill(json(ROSTER)));
  await page.route('**/api/attendance/kiosk/pin', (r) => {
    const body = JSON.parse(r.request().postData() || '{}');
    return body.pin === '1234'
      ? r.fulfill(json({ next: body.user_id === 7 ? 'out' : 'in', face_enrolled: true }))
      : r.fulfill(json({ message: 'El PIN no es correcto.', errors: { pin: ['El PIN no es correcto.'] } }, 422));
  });
  await page.route('**/api/attendance/kiosk/clock', (r) => {
    sent.push(JSON.parse(r.request().postData() || '{}'));
    return clock ? clock(r) : r.fulfill(json({ type: 'in', time: '14:02', entry_time: null, status: 'registered', duplicate: false, name: 'Luis Ayudante' }, 201));
  });
  await page.route('**/api/attendance/kiosk/my-marks', (r) => r.fulfill(json({
    name: 'Luis Ayudante',
    days: [{ date: '2026-11-03', label: 'Hoy', in: '14:02', out: null }, { date: '2026-11-02', label: 'Ayer', in: '14:00', out: '22:05' }],
  })));
  await page.addInitScript((device) => window.localStorage.setItem('ohana_attendance_device', JSON.stringify(device)), DEVICE);
  return sent;
}

async function markAs(page, name, pin = '1234') {
  await page.locator('.k-person', { hasText: name }).click();
  for (const d of pin) await page.locator('.k-key', { hasText: new RegExp(`^${d}$`) }).click();
  await page.locator('.k-key-ok').click();
}

test.describe('Reloj de asistencia', () => {
  test('1. Sin equipo autorizado no se puede marcar', async ({ page }) => {
    await page.goto('/asistencia/reloj');
    await expect(page.getByRole('heading', { name: 'Este equipo no está autorizado' })).toBeVisible();
  });

  test('2. La lista muestra nombres y si toca entrada o salida, nunca horas', async ({ page }) => {
    await kioskSetup(page);
    await page.goto('/asistencia/reloj');
    await expect(page.locator('.k-person')).toHaveCount(2);
    await expect(page.locator('.k-person', { hasText: 'Ana Cajera' })).toContainText('Marcar salida');
    await expect(page.locator('.k-person', { hasText: 'Luis Ayudante' })).toContainText('Marcar entrada');
    await expect(page.locator('.k-pick')).not.toContainText(/\d{1,2}:\d{2}/);
  });

  test('3. PIN incorrecto, después entrada con foto y «Mis marcas»', async ({ page }) => {
    const sent = await kioskSetup(page);
    await page.goto('/asistencia/reloj');
    await markAs(page, 'Luis Ayudante', '9999');
    await expect(page.locator('.k-error')).toHaveText('El PIN no es correcto.');

    for (const d of '1234') await page.locator('.k-key', { hasText: new RegExp(`^${d}$`) }).click();
    await page.locator('.k-key-ok').click();
    await page.getByRole('button', { name: 'Tomar foto' }).click({ timeout: 25000 });
    await page.getByRole('button', { name: 'Confirmar entrada' }).click();

    await expect(page.locator('.k-done-title')).toHaveText('Entrada marcada');
    await expect(page.locator('.k-done-time')).toHaveText('14:02');
    await expect(page.locator('.k-done-msg')).toHaveText('Tu marca quedó registrada.');
    expect(sent).toHaveLength(1);
    expect(sent[0].photo).toMatch(/^data:image\/jpeg;base64,/);
    expect(sent[0].pin).toBe('1234');
    expect(sent[0].type).toBeUndefined();

    await page.getByRole('button', { name: 'Ver mis marcas' }).click();
    await expect(page.locator('.k-table tbody tr')).toHaveCount(2);
    await expect(page.locator('.k-table thead')).toHaveText(/Día\s*Entrada\s*Salida/);
    await expect(page.locator('.k-history')).not.toContainText(/Bs|%|horas? extra/);
  });

  test('4. Una marca para revisar no dice por qué ni muestra el porcentaje', async ({ page }) => {
    await kioskSetup(page, {
      clock: (r) => r.fulfill(json({ type: 'out', time: '22:15', entry_time: '08:01', status: 'saved_for_review', duplicate: false, name: 'Ana Cajera' }, 201)),
    });
    await page.goto('/asistencia/reloj');
    await markAs(page, 'Ana Cajera');
    await page.getByRole('button', { name: 'Tomar foto' }).click({ timeout: 25000 });
    await page.getByRole('button', { name: 'Confirmar salida' }).click();
    await expect(page.locator('.k-done-title')).toHaveText('Salida guardada');
    await expect(page.locator('.k-done-detail')).toContainText('Turno de hoy: 08:01 → 22:15');
    await expect(page.locator('.k-done-msg')).toHaveText('Tu marca quedó guardada con su hora. Un administrador la revisa.');
    await expect(page.locator('.kiosk-card')).not.toContainText('%');
  });

  test('5. Sin internet la marca queda en la cola del equipo', async ({ page }) => {
    await kioskSetup(page, { clock: (r) => r.abort('internetdisconnected') });
    await page.goto('/asistencia/reloj');
    await markAs(page, 'Luis Ayudante');
    await page.getByRole('button', { name: 'Tomar foto' }).click({ timeout: 25000 });
    await page.getByRole('button', { name: 'Confirmar entrada' }).click();
    await expect(page.locator('.k-done-title')).toHaveText('Entrada guardada');
    await expect(page.locator('.k-done-msg')).toContainText('No hay internet');
    await page.getByRole('button', { name: 'Listo' }).click();
    await expect(page.locator('.kiosk-chips')).toContainText('1 marca por enviar');
  });
});

test.describe('Caja y administración', () => {
  const PERMS = {
    pos: 'edit', register_self: 'edit', delivery_ops: 'edit', crm_read: 'read', voids: 'none', register_others: 'none',
    catalog: 'none', inventory: 'none', crm_config: 'none', reports: 'none', users: 'none', audit: 'none', attendance: 'none',
  };

  async function cashierSetup(page, today) {
    await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
    await page.route('**/api/me', (r) => r.fulfill(json({
      user: { id: 7, name: 'Ana Cajera', email: 'ana@example.com', role: 'cashier' }, permissions: [], effective_permissions: PERMS,
    })));
    await page.route('**/api/cash-sessions/active', (r) => r.fulfill(json({ session: null, stats: null })));
    await page.route('**/api/attendance/me/today', (r) => r.fulfill(json(today)));
    await page.addInitScript(() => window.localStorage.setItem('auth_token', 'mock_jwt'));
  }

  test('6. Abrir caja sin entrada avisa, y con el ajuste activo no deja abrir', async ({ page }) => {
    await cashierSetup(page, { enabled: true, entry: null, exit: null, open: false, require_entry_to_open_register: false });
    await page.goto('/turno?abrir=1');
    await expect(page.locator('.man')).toContainText('Todavía no marcaste tu entrada de hoy');
    await expect(page.getByRole('button', { name: /Abrir caja con/ })).toBeEnabled();

    await page.unroute('**/api/attendance/me/today');
    await page.route('**/api/attendance/me/today', (r) => r.fulfill(json({ enabled: true, entry: null, exit: null, open: false, require_entry_to_open_register: true })));
    await page.goto('/turno?abrir=1');
    await expect(page.locator('.man')).toContainText('para poder abrir la caja');
    await expect(page.getByRole('button', { name: /Abrir caja con/ })).toBeDisabled();
  });

  test('7. Con la caja cerrada y el turno abierto, recuerda marcar la salida', async ({ page }) => {
    await cashierSetup(page, { enabled: true, entry: '08:01', exit: null, open: true, require_entry_to_open_register: false });
    await page.goto('/turno');
    await expect(page.locator('.man-exit')).toContainText('Tu entrada fue a las 08:01');
  });

  test('8. Sueldos: con marcas por revisar no se puede aprobar', async ({ page }) => {
    await page.route('**/api/**', (r) => r.fulfill(json({ data: [] })));
    await page.route('**/api/me', (r) => r.fulfill(json({
      user: { id: 1, name: 'Dueño', email: 'd@example.com', role: 'super_admin' }, permissions: [], effective_permissions: {},
    })));
    await page.route('**/api/attendance/review', (r) => r.fulfill(json({ pending: [], without_exit: [], count: 1 })));
    await page.route('**/api/attendance/today', (r) => r.fulfill(json({ date: '2026-11-03', people: [] })));
    await page.route('**/api/attendance/payroll?*', (r) => r.fulfill(json({
      from: '2026-10-01', to: '2026-10-31',
      lines: [{
        user_id: 7, name: 'Ana Cajera', pay_mode: 'monthly', rate: 3300, days_worked: 26, complete_shifts: 26,
        normal_minutes: 12435, overtime_minutes: 240, sunday_minutes: 0, late_minutes: 45, absences: 1,
        breakdown: { base: 3300, overtime: 110, sunday: 0, late_deduction: 10.31, absence_deduction: 110, hour_value: 13.75 },
        gross: 3289.69, advances: 0, net: 3289.69, days: [],
      }],
      totals: { gross: 3289.69, advances: 0, net: 3289.69 },
      blockers: { pending: 1, without_exit: 0, total: 1 },
      approved: null,
      warnings: [],
    })));
    await page.addInitScript(() => window.localStorage.setItem('auth_token', 'mock_jwt'));
    await page.goto('/asistencia?tab=payroll');
    await expect(page.locator('.pay-table tbody tr').first()).toContainText('3.289,69');
    await expect(page.locator('.fin-note.err')).toContainText('Quedan 1 marcas por revisar');
    await expect(page.getByRole('button', { name: 'Aprobar planilla' })).toBeDisabled();
  });
});
