/**
 * Reglas del reloj de asistencia que no dependen de la pantalla (docs/PLAN_ASISTENCIA_Y_SUELDOS.md).
 * Lo que ve el trabajador sale de aquí: solo entrada, salida y si su marca quedó registrada o se revisa (§5.8).
 */

export const PIN_PATTERN = /^\d{4,6}$/;

/** Divisor del sueldo mensual para el valor de la hora (30 días × 8 h). */
export const MONTHLY_HOURS = 240;

export const DAY_NAMES = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

export function pinIsValid(pin) {
  return PIN_PATTERN.test(String(pin ?? ''));
}

export function typeLabel(type) {
  return type === 'out' ? 'Salida' : 'Entrada';
}

/**
 * Sin conexión el equipo decide si toca entrada o salida: lo último que dijo el servidor y, encima, las marcas que
 * quedaron en la cola para esa persona.
 */
export function offlineNextType(cachedNext, queuedTypes = []) {
  if (queuedTypes.length > 0) {
    return queuedTypes[queuedTypes.length - 1] === 'in' ? 'out' : 'in';
  }
  return cachedNext === 'out' ? 'out' : 'in';
}

/** Mensaje para el trabajador al terminar de marcar. Nunca dice por qué se revisa ni muestra porcentajes. */
export function workerMessage({ status, offline }) {
  if (offline) {
    return {
      tone: 'pending',
      text: 'No hay internet: tu marca quedó guardada en esta portátil con su hora y se envía sola al volver la conexión.',
    };
  }
  if (status === 'registered') {
    return { tone: 'ok', text: 'Tu marca quedó registrada.' };
  }
  return { tone: 'pending', text: 'Tu marca quedó guardada con su hora. Un administrador la revisa.' };
}

export function doneTitle(type, tone) {
  return `${typeLabel(type)} ${tone === 'ok' ? 'marcada' : 'guardada'}`;
}

/** Valor de la hora de un sueldo mensual, redondeado al centavo. */
export function hourlyFromMonthly(monthly) {
  const value = Number(monthly);
  if (!Number.isFinite(value) || value <= 0) return 0;
  return Math.round((value / MONTHLY_HOURS) * 100) / 100;
}

/** ¿El pago por hora (o su equivalente) queda debajo del salario mínimo? */
export function belowMinimum({ pay_mode: mode, hourly_rate: hourly, monthly_salary: monthly, shift_rate: shift, shift_hours: hours }, minimumWage) {
  const minHour = hourlyFromMonthly(minimumWage);
  if (!minHour) return false;
  let perHour = 0;
  if (mode === 'monthly') perHour = hourlyFromMonthly(monthly);
  else if (mode === 'per_shift') perHour = Number(hours) > 0 ? Number(shift) / Number(hours) : 0;
  else perHour = Number(hourly);
  return Number.isFinite(perHour) && perHour > 0 && perHour + 1e-9 < minHour;
}

/** Del formulario (7 filas, lunes primero) al formato del servidor: {"1": {start, end}, …}. */
export function scheduleFromRows(rows) {
  const schedule = {};
  rows.forEach((row, i) => {
    if (row.works && row.start && row.end) {
      schedule[String(i + 1)] = { start: row.start, end: row.end };
    }
  });
  return schedule;
}

/** Del servidor a las 7 filas del formulario. */
export function rowsFromSchedule(schedule) {
  return DAY_NAMES.map((day, i) => {
    const hours = schedule?.[String(i + 1)] ?? schedule?.[i + 1];
    return { day, works: Boolean(hours?.start && hours?.end), start: hours?.start ?? '', end: hours?.end ?? '' };
  });
}

/** Motivos por los que una marca está en la bandeja (para administradores). */
export function reviewReasons(mark, threshold = 0.55) {
  const reasons = [];
  if (mark.status === 'offline_review') reasons.push({ id: 'offline', label: 'Sin internet' });
  if (!mark.network_ok && mark.source !== 'manual' && mark.status !== 'offline_review') reasons.push({ id: 'network', label: 'Red nueva' });
  if (mark.liveness_ok === false || (mark.face_score !== null && mark.face_score !== undefined && mark.face_score < threshold)) {
    reasons.push({ id: 'face', label: 'Rostro a revisar' });
  }
  return reasons;
}

export function formatScore(score) {
  if (score === null || score === undefined) return '—';
  return `${Math.round(Number(score) * 100)} %`;
}

export const STATUS_LABELS = {
  valid: 'Válida',
  pending_network: 'Red nueva',
  face_review: 'Rostro a revisar',
  offline_review: 'Sin internet',
  rejected: 'Rechazada',
  corrected: 'Corregida',
};

/** Minutos a «176 h», «7 h 55 min» o «45 min». */
export function formatMinutes(minutes) {
  const total = Math.max(0, Math.round(Number(minutes) || 0));
  const h = Math.floor(total / 60);
  const m = total % 60;
  if (!h) return `${m} min`;
  return m ? `${h} h ${m} min` : `${h} h`;
}

const pad = (n) => String(n).padStart(2, '0');

/**
 * Fechas de un período de planilla: el mes entero o una de sus quincenas (1–15 y 16–fin de mes).
 * @param {number} year
 * @param {number} month 1–12
 * @param {'month'|'first'|'second'} part
 */
export function payrollRange(year, month, part = 'month') {
  const last = new Date(year, month, 0).getDate();
  const ym = `${year}-${pad(month)}`;
  if (part === 'first') return { from: `${ym}-01`, to: `${ym}-15` };
  if (part === 'second') return { from: `${ym}-16`, to: `${ym}-${pad(last)}` };
  return { from: `${ym}-01`, to: `${ym}-${pad(last)}` };
}

export const PAY_MODE_LABELS = { hourly: 'Por hora', monthly: 'Sueldo mensual', per_shift: 'Por turno' };

/** «Sueldo mensual · Bs 3.300» */
export function payModeText(mode, rate) {
  const amount = Number(rate || 0).toLocaleString('es-BO', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  return `${PAY_MODE_LABELS[mode] || mode} · Bs ${amount}`;
}
