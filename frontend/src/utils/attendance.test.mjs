import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  pinIsValid, offlineNextType, workerMessage, doneTitle, hourlyFromMonthly, belowMinimum,
  scheduleFromRows, rowsFromSchedule, reviewReasons, formatScore,
} from './attendance.js';

test('el PIN tiene de 4 a 6 dígitos', () => {
  assert.equal(pinIsValid('1234'), true);
  assert.equal(pinIsValid('123456'), true);
  assert.equal(pinIsValid('123'), false);
  assert.equal(pinIsValid('12a4'), false);
  assert.equal(pinIsValid(''), false);
});

test('sin conexión, la cola manda sobre lo último que dijo el servidor', () => {
  assert.equal(offlineNextType('in', []), 'in');
  assert.equal(offlineNextType('out', []), 'out');
  assert.equal(offlineNextType(undefined, []), 'in');
  assert.equal(offlineNextType('in', ['in']), 'out');
  assert.equal(offlineNextType('in', ['in', 'out']), 'in');
});

test('el mensaje al trabajador nunca explica la revisión ni muestra porcentajes', () => {
  assert.deepEqual(workerMessage({ status: 'registered' }), { tone: 'ok', text: 'Tu marca quedó registrada.' });
  const pending = workerMessage({ status: 'saved_for_review' });
  assert.equal(pending.tone, 'pending');
  assert.doesNotMatch(pending.text, /%|red|rostro/i);
  assert.equal(workerMessage({ offline: true }).tone, 'pending');
  assert.equal(doneTitle('in', 'ok'), 'Entrada marcada');
  assert.equal(doneTitle('out', 'pending'), 'Salida guardada');
});

test('valor de la hora y salario mínimo', () => {
  assert.equal(hourlyFromMonthly(3300), 13.75);
  assert.equal(hourlyFromMonthly(0), 0);
  assert.equal(belowMinimum({ pay_mode: 'hourly', hourly_rate: 13 }, 3300), true);
  assert.equal(belowMinimum({ pay_mode: 'hourly', hourly_rate: 13.75 }, 3300), false);
  assert.equal(belowMinimum({ pay_mode: 'monthly', monthly_salary: 3000 }, 3300), true);
  assert.equal(belowMinimum({ pay_mode: 'per_shift', shift_rate: 120, shift_hours: 8 }, 3300), false);
  assert.equal(belowMinimum({ pay_mode: 'hourly', hourly_rate: '' }, 3300), false);
});

test('el horario va y vuelve entre el formulario y el servidor', () => {
  const rows = rowsFromSchedule({ 1: { start: '14:00', end: '22:00' }, 6: { start: '12:00', end: '22:30' } });
  assert.equal(rows.length, 7);
  assert.deepEqual(rows[0], { day: 'Lun', works: true, start: '14:00', end: '22:00' });
  assert.equal(rows[6].works, false);
  rows[6] = { ...rows[6], works: true, start: '', end: '' };
  assert.deepEqual(scheduleFromRows(rows), { 1: { start: '14:00', end: '22:00' }, 6: { start: '12:00', end: '22:30' } });
});

test('motivos de la bandeja para administradores', () => {
  assert.deepEqual(reviewReasons({ status: 'pending_network', network_ok: false, face_score: 0.93 }).map(r => r.id), ['network']);
  assert.deepEqual(reviewReasons({ status: 'face_review', network_ok: true, face_score: 0.41 }).map(r => r.id), ['face']);
  assert.deepEqual(reviewReasons({ status: 'offline_review', network_ok: true, face_score: null }).map(r => r.id), ['offline']);
  assert.deepEqual(reviewReasons({ status: 'pending_network', network_ok: false, liveness_ok: false, face_score: null }).map(r => r.id), ['network', 'face']);
  assert.equal(formatScore(0.61), '61 %');
  assert.equal(formatScore(null), '—');
});
