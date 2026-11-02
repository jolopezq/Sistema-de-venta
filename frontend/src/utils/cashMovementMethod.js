/**
 * Cómo se pagó un gasto o ingreso de caja, en un solo lugar (docs/PLAN_GASTO_PAGO_DIVIDIDO.md).
 * Antes cada pantalla repetía `=== 'cash' ? … : (=== 'qr' ? … : 'Tarjeta')`, y cualquier otro método salía como «Tarjeta».
 *
 * Métodos: cash (efectivo de la gaveta), qr (QR o banco), card (tarjeta) y mixed (gasto dividido: `cash_amount` en
 * efectivo y el resto por QR o banco).
 */

const toCents = (v) => {
  const n = Number(v);
  return Number.isFinite(n) ? Math.round(n * 100) : 0;
};
const bs = (cents) => `Bs ${(cents / 100).toFixed(2)}`;

export const methodOf = (m) => m?.payment_method || 'cash';

/** «Efectivo», «QR», «Tarjeta» o «Efectivo + QR». */
export function methodLabel(m) {
  switch (methodOf(m)) {
    case 'cash': return 'Efectivo';
    case 'qr': return 'QR';
    case 'card': return 'Tarjeta';
    case 'mixed': return 'Efectivo + QR';
    default: return String(m.payment_method);
  }
}

/** Con ícono, para las insignias de las listas. */
export function methodBadge(m) {
  switch (methodOf(m)) {
    case 'cash': return '💵 Efectivo';
    case 'qr': return '📱 QR';
    case 'card': return '💳 Tarjeta';
    case 'mixed': return '💵📱 Efectivo + QR';
    default: return methodLabel(m);
  }
}

/** Parte que salió o entró por la gaveta, en centavos. */
export function cashPartCents(m) {
  switch (methodOf(m)) {
    case 'cash': return toCents(m.amount);
    case 'mixed': return toCents(m.cash_amount);
    default: return 0;
  }
}

export const cashPart = (m) => cashPartCents(m) / 100;

/** Parte por QR, banco o tarjeta. */
export const nonCashPart = (m) => (toCents(m?.amount) - cashPartCents(m)) / 100;

/** Desglose de un gasto dividido: «Bs 40.00 efectivo + Bs 60.00 QR». Vacío si no está dividido. */
export function methodDetail(m) {
  if (methodOf(m) !== 'mixed') return '';
  const cash = cashPartCents(m);
  return `${bs(cash)} efectivo + ${bs(toCents(m.amount) - cash)} QR`;
}

/** «Efectivo · Bs 74.00» o «Efectivo + QR · Bs 100.00 (Bs 40.00 efectivo + Bs 60.00 QR)»: para el «Antes» de una corrección. */
export function methodWithAmount(m) {
  if (!m) return '';
  const detail = methodDetail(m);
  return `${methodLabel(m)} · ${bs(toCents(m.amount))}${detail ? ` (${detail})` : ''}`;
}
