/**
 * Descuento manual (docs/PLAN_DESCUENTOS_CAJA.md): reglas puras que comparten el cobro y Control diario.
 *
 * El tope por rol (cajero 20 %, administrador 100 %) llega del servidor en `auth.discount_policy`
 * (backend/app/Services/DiscountPolicy.php): aquí no se repite. Montos en centavos enteros para evitar
 * errores de coma flotante, igual que utils/splitPayments.js.
 */

/** Monto (número o texto) → centavos enteros; vacío, NaN o negativo valen 0. */
export const toCents = (v) => {
  const n = Number(v);
  return Number.isFinite(n) && n > 0 ? Math.round(n * 100) : 0;
};

/** Política por defecto mientras no llegue la del servidor: sin permiso. */
export const NO_DISCOUNT = Object.freeze({ allowed: false, max_percent: 0, reason_min: 10, reason_max: 255 });

/** Descuento máximo para ese subtotal: el % del rol, redondeado hacia abajo al centavo. */
export function maxDiscount(subtotal, maxPercent) {
  const cents = toCents(subtotal);
  const pct = Math.max(0, Number(maxPercent) || 0);
  if (pct >= 100) return cents / 100;
  return Math.floor((cents * pct) / 100) / 100;
}

/** Total a cobrar con el descuento (nunca negativo). */
export function netTotal(subtotal, discount) {
  const sub = toCents(subtotal);
  return (sub - Math.min(toCents(discount), sub)) / 100;
}

/** % del subtotal, con un decimal. */
export function discountPercent(discount, subtotal) {
  const sub = toCents(subtotal);
  if (sub === 0) return 0;
  return Math.round((toCents(discount) / sub) * 1000) / 10;
}

/** «13.3 %», «20 %». */
export function formatPercent(pct) {
  return `${Number.isInteger(pct) ? pct : pct.toFixed(1)} %`;
}

/**
 * Qué impide aplicar el descuento, o null si se puede. Sin descuento (0 o vacío) no hay nada que validar.
 * @returns {{ field: 'amount' | 'reason', message: string } | null}
 */
export function discountProblem({ amount, reason, subtotal, policy = NO_DISCOUNT }) {
  const cents = toCents(amount);
  if (cents === 0) return null;

  if (!policy?.allowed) {
    return { field: 'amount', message: 'Necesitas el permiso «Descuentos en caja».' };
  }
  const max = maxDiscount(subtotal, policy.max_percent);
  if (cents > toCents(max)) {
    return { field: 'amount', message: `Máximo Bs ${max.toFixed(2)} (${policy.max_percent} % del subtotal).` };
  }
  const min = Number(policy.reason_min) || 10;
  if (String(reason || '').trim().length < min) {
    return { field: 'reason', message: `Escribe el motivo del descuento (mínimo ${min} caracteres).` };
  }
  return null;
}
