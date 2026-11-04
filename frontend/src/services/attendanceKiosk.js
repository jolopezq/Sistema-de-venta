import { API_URL } from './api';
import { db } from '../db/database';

/**
 * Reloj de asistencia en el equipo autorizado (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.2 y §5.3).
 * La llave del equipo se guarda en este navegador; el servidor guarda solo su huella. Las marcas sin conexión
 * esperan en IndexedDB y se envían solas al volver la conexión.
 */

const DEVICE_KEY = 'ohana_attendance_device';
const ROSTER_KEY = 'ohana_attendance_roster';

export function deviceInfo() {
  try {
    const saved = JSON.parse(localStorage.getItem(DEVICE_KEY) || 'null');
    return saved?.token ? saved : null;
  } catch {
    return null;
  }
}

export function hasAttendanceDevice() {
  return deviceInfo() !== null;
}

export function saveDevice(token, name, id) {
  localStorage.setItem(DEVICE_KEY, JSON.stringify({ token, name, id, saved_at: new Date().toISOString() }));
  window.dispatchEvent(new Event('ohana-attendance-device'));
}

export function forgetDevice() {
  localStorage.removeItem(DEVICE_KEY);
  localStorage.removeItem(ROSTER_KEY);
  window.dispatchEvent(new Event('ohana-attendance-device'));
}

export function cachedRoster() {
  try {
    return JSON.parse(localStorage.getItem(ROSTER_KEY) || 'null');
  } catch {
    return null;
  }
}

function cacheRoster(roster) {
  try {
    localStorage.setItem(ROSTER_KEY, JSON.stringify({ ...roster, cached_at: new Date().toISOString() }));
  } catch {
    // Sin almacenamiento: el reloj vuelve a pedir la lista
  }
}

/** Error de red (sin conexión o el servidor no respondió): la marca se guarda en la cola. */
export class OfflineError extends Error {}

export async function kioskFetch(endpoint, { method = 'GET', body } = {}) {
  const device = deviceInfo();
  if (!device) {
    const err = new Error('Este equipo no está autorizado para marcar asistencia.');
    err.status = 403;
    err.reason = 'device_not_authorized';
    throw err;
  }
  let response;
  try {
    response = await fetch(`${API_URL}/attendance/kiosk${endpoint}`, {
      method,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Attendance-Device': device.token,
      },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new OfflineError('Sin conexión');
  }
  let data = null;
  try {
    data = await response.json();
  } catch {
    data = null;
  }
  if (!response.ok) {
    if (response.status >= 500 || response.status === 0) throw new OfflineError('El servidor no respondió');
    const firstError = data?.errors ? Object.values(data.errors).flat()[0] : null;
    const err = new Error(firstError || data?.message || 'No se pudo marcar.');
    err.status = response.status;
    err.reason = data?.reason;
    err.errors = data?.errors;
    if (data?.reason === 'device_not_authorized') forgetDevice();
    throw err;
  }
  return data;
}

export async function fetchRoster() {
  const roster = await kioskFetch('/roster');
  cacheRoster(roster);
  return roster;
}

export const checkPin = (userId, pin) => kioskFetch('/pin', { method: 'POST', body: { user_id: userId, pin } });
export const sendMark = (payload) => kioskFetch('/clock', { method: 'POST', body: payload });
export const fetchMyMarks = (userId, pin) => kioskFetch('/my-marks', { method: 'POST', body: { user_id: userId, pin } });

export function newUuid() {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

/** Guarda una marca hecha sin conexión. La hora es la del equipo; el servidor la deja para aprobar. */
export async function queueMark(payload) {
  const item = {
    ...payload,
    local_uuid: payload.local_uuid || newUuid(),
    offline: true,
    device_time: payload.device_time || new Date().toISOString(),
    status: 'pending',
  };
  await db.attendanceQueue.put(item);
  return item;
}

export async function queuedTypesFor(userId) {
  const items = await db.attendanceQueue.where('user_id').equals(userId).filter((i) => i.status === 'pending').sortBy('device_time');
  return items.map((i) => i.type);
}

export async function queueSummary() {
  const all = await db.attendanceQueue.toArray();
  return {
    pending: all.filter((i) => i.status === 'pending').length,
    failed: all.filter((i) => i.status === 'failed').length,
  };
}

/** Envía la cola en orden. Una marca que el servidor rechaza (PIN incorrecto) queda como fallida y no se reintenta. */
let flushing = false;
export async function flushQueue() {
  if (flushing || !hasAttendanceDevice()) return queueSummary();
  flushing = true;
  try {
    const items = (await db.attendanceQueue.where('status').equals('pending').toArray())
      .sort((a, b) => String(a.device_time).localeCompare(String(b.device_time)));
    for (const item of items) {
      try {
        const { status: _s, error: _e, ...payload } = item;
        await sendMark(payload);
        await db.attendanceQueue.delete(item.local_uuid);
      } catch (err) {
        if (err instanceof OfflineError) break;
        await db.attendanceQueue.update(item.local_uuid, { status: 'failed', error: err.message });
      }
    }
  } finally {
    flushing = false;
  }
  return queueSummary();
}

export async function clearFailed() {
  await db.attendanceQueue.where('status').equals('failed').delete();
}
