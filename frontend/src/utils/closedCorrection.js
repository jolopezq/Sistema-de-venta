/**
 * Corrección de un gasto o ingreso en una caja ya cerrada (docs/PLAN_CORREGIR_GASTOS_CAJA.md §3.8).
 * El servidor rehace el cierre con el mismo efectivo contado; aquí solo se calcula la vista previa del modal.
 * Montos en centavos para no arrastrar errores de decimales.
 */

import { cashPartCents } from './cashMovementMethod.js';

const cents = (v) => {
  const n = Number(v);
  return Number.isFinite(n) ? Math.round(n * 100) : 0;
};

/**
 * Efecto de un movimiento en el efectivo esperado: un gasto resta su parte en efectivo (todo si es en efectivo, la parte
 * en efectivo si está dividido), un ingreso en efectivo suma.
 */
export function cashEffectCents(m) {
  const cash = cashPartCents(m);
  return m.type === 'extra_income' ? cash : 0 - cash;
}

/**
 * Teórico y diferencia del cierre antes y después de cambiar (o anular, con `after = null`) un movimiento.
 * @param {{ expected_closing, actual_closing, difference }} session caja cerrada
 * @param {object} before movimiento como está guardado
 * @param {object|null} after movimiento corregido, o null si se anula
 */
export function closingPreview(session, before, after) {
  const expectedBefore = cents(session?.expected_closing);
  const actual = cents(session?.actual_closing);
  const expectedAfter = expectedBefore - cashEffectCents(before) + (after ? cashEffectCents(after) : 0);
  const diffBefore = session?.difference !== null && session?.difference !== undefined
    ? cents(session.difference)
    : actual - expectedBefore;
  return {
    expectedBefore: expectedBefore / 100,
    expectedAfter: expectedAfter / 100,
    actual: actual / 100,
    diffBefore: diffBefore / 100,
    diffAfter: (actual - expectedAfter) / 100,
  };
}

/** «−Bs 74.00», «Bs 0.00», «+Bs 5.00»: la diferencia de un arqueo. */
export function signedBs(n) {
  const v = Math.round(Number(n || 0) * 100) / 100;
  if (v === 0) return 'Bs 0.00';
  return `${v < 0 ? '−' : '+'}Bs ${Math.abs(v).toFixed(2)}`;
}
