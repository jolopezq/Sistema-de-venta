/**
 * Conteo de efectivo del cierre de caja (billetes y monedas bolivianos).
 * Plan: docs/PLAN_CIERRE_CAJA_CONTEO_DIRECTO.md
 *
 * Funciones puras: lo que se escribe se filtra a dígitos y la suma se hace en centavos enteros.
 */

/** Máximo de piezas por denominación (4 cifras). */
export const MAX_PIECES = 9999;

/**
 * Denominaciones en el orden en que se recorren con el teclado (columna de billetes y luego la de monedas).
 * `key` es la clave del desglose que se guarda y se envía al servidor (`bill_breakdown`).
 */
export const DENOMINATIONS = [
  { key: '200',  value: 200, label: 'Bs 200',  type: 'billete', color: '#1B5E20' },
  { key: '100',  value: 100, label: 'Bs 100',  type: 'billete', color: '#B71C1C' },
  { key: '50',   value: 50,  label: 'Bs 50',   type: 'billete', color: '#E65100' },
  { key: '20',   value: 20,  label: 'Bs 20',   type: 'billete', color: '#F57F17' },
  { key: '10',   value: 10,  label: 'Bs 10',   type: 'billete', color: '#006064' },
  { key: '5',    value: 5,   label: 'Bs 5',    type: 'moneda',  color: '#4E342E' },
  { key: '2',    value: 2,   label: 'Bs 2',    type: 'moneda',  color: '#546E7A' },
  { key: '1',    value: 1,   label: 'Bs 1',    type: 'moneda',  color: '#546E7A' },
  { key: '0.50', value: 0.5, label: 'Bs 0,50', type: 'moneda',  color: '#78909C' },
];

/** Cualquier valor → entero de 0 a MAX_PIECES (descarta signos, comas, letras y ceros a la izquierda). */
export function sanitizeCount(raw) {
  const digits = String(raw ?? '').replace(/\D/g, '').slice(0, 4);
  return digits === '' ? 0 : Math.min(MAX_PIECES, parseInt(digits, 10));
}

/** Borrador o desglose cualquiera → objeto con todas las denominaciones saneadas. */
export function sanitizeCounts(counts) {
  const out = {};
  for (const d of DENOMINATIONS) {
    const src = counts ? (counts[d.key] ?? (d.key === '0.50' ? counts['0.5'] : undefined)) : undefined;
    out[d.key] = sanitizeCount(src);
  }
  return out;
}

/** Total del desglose en Bs (centavos enteros: sin errores de coma flotante). */
export function countTotal(counts) {
  let cents = 0;
  for (const d of DENOMINATIONS) cents += sanitizeCount(counts?.[d.key]) * Math.round(d.value * 100);
  return cents / 100;
}

/** Subtotal de una denominación en Bs. */
export function subtotal(denom, qty) {
  return (sanitizeCount(qty) * Math.round(denom.value * 100)) / 100;
}

/** Cantidad total de piezas. */
export function piecesCount(counts) {
  return DENOMINATIONS.reduce((s, d) => s + sanitizeCount(counts?.[d.key]), 0);
}

/**
 * Monto en Bs escrito a mano (fondo para el próximo turno): solo dígitos y un separador decimal,
 * máximo 2 decimales. Devuelve el texto limpio (la coma se cambia a punto).
 */
export function sanitizeMoneyText(raw) {
  let t = String(raw ?? '').replace(',', '.').replace(/[^\d.]/g, '');
  const firstDot = t.indexOf('.');
  if (firstDot !== -1) {
    t = t.slice(0, firstDot + 1) + t.slice(firstDot + 1).replace(/\./g, '').slice(0, 2);
  }
  const [ent, dec] = t.split('.');
  const entClean = (ent ?? '').replace(/^0+(?=\d)/, '').slice(0, 7);
  return dec !== undefined ? `${entClean || '0'}.${dec}` : entClean;
}

/** Texto de monto → número (vacío = 0). */
export function moneyFromText(text) {
  const n = Number(text);
  return Number.isFinite(n) && n > 0 ? Math.round(n * 100) / 100 : 0;
}

/** Formato de moneda para pantalla: «Bs 1 240,50» no se usa; se mantiene «Bs 1240.50» como el resto de la app. */
export const money = (n) => `Bs ${Number(n || 0).toFixed(2)}`;
