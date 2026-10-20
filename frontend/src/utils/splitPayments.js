/**
 * Reparto automático del pago dividido (modal de Cobro).
 * Plan: docs/PLAN_COBRO_DIVIDIDO_AUTOCOMPLETAR.md
 *
 * Funciones puras, con montos en centavos enteros para evitar errores de decimales (0,1 + 0,2).
 * Cada línea: { method, amount, touched }; `touched` es el "turno" en que el cajero la editó a mano
 * (0 = nunca). La línea menos reciente es la que absorbe lo que falta o sobra.
 */

/** Monto (número o texto) → centavos enteros; vacío, NaN o negativo valen 0. */
export const toCents = (v) => {
  const n = Number(v);
  return Number.isFinite(n) && n > 0 ? Math.round(n * 100) : 0;
};
const toAmount = (cents) => cents / 100;

/**
 * Reparte `need` centavos entre las líneas indicadas: las más recientes conservan su monto
 * (recortado si ya no cabe) y la menos reciente recibe lo que quede.
 * @param {Array} lines  líneas (se modifican)
 * @param {number[]} idx índices a repartir
 * @param {number} need  centavos a repartir
 */
function distribute(lines, idx, need) {
  if (idx.length === 0) return;
  // De la más antigua a la más reciente; en empate (nunca tocadas) la última línea es la que completa
  const ordered = [...idx].sort((a, b) => (lines[a].touched || 0) - (lines[b].touched || 0) || b - a);
  const target = ordered[0];
  let left = need;
  for (let k = ordered.length - 1; k >= 1; k--) {
    const i = ordered[k];
    const keep = Math.min(toCents(lines[i].amount), left);
    if (keep !== toCents(lines[i].amount)) lines[i].amount = toAmount(keep);
    left -= keep;
  }
  lines[target].amount = toAmount(left);
}

/**
 * El cajero editó el monto de la línea `index`: las demás se reacomodan para que todo sume el total.
 * La línea editada conserva exactamente lo que se escribió (salvo que supere el total, que se limita).
 * @returns {Array} líneas nuevas (no modifica las recibidas)
 */
export function rebalance(payments, index, raw, total, stamp) {
  const lines = payments.map((p) => ({ ...p }));
  if (index < 0 || index >= lines.length) return lines;
  const T = toCents(total);
  const edited = lines[index];
  edited.touched = stamp;
  const v = toCents(raw);
  if (v > T) edited.amount = toAmount(T);
  else edited.amount = raw;
  if (lines.length === 1) return lines;
  const others = lines.map((_, i) => i).filter((i) => i !== index);
  distribute(lines, others, T - Math.min(v, T));
  return lines;
}

/** Hace que las líneas sumen el total (p. ej. al quitar una línea): la menos reciente absorbe la diferencia. */
export function fillRemainder(payments, total) {
  const lines = payments.map((p) => ({ ...p }));
  if (lines.length < 2) return lines;
  distribute(lines, lines.map((_, i) => i), toCents(total));
  return lines;
}

/**
 * Efectivo que realmente hay que cobrar: lo que dice la línea de efectivo, sin pasar de lo que
 * falta una vez descontados QR/tarjeta.
 */
export function cashDue(payments, total) {
  const T = toCents(total);
  let cash = 0;
  let other = 0;
  let hasCash = false;
  for (const p of payments) {
    if (p.method === 'cash') { cash += toCents(p.amount); hasCash = true; } else other += toCents(p.amount);
  }
  if (!hasCash) return 0;
  return toAmount(Math.min(cash, Math.max(0, T - other)));
}
