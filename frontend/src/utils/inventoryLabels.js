/** Etiquetas y formatos compartidos por las ventanas de mermas, conteo e historial de Inventario (F3). */

export const MOVEMENT_TYPES = {
  initial: 'Stock inicial',
  restock: 'Compra',
  purchase_reversal: 'Compra revertida',
  sale: 'Venta',
  waste: 'Merma',
  adjustment: 'Ajuste',
};

/** El reverso de una venta anulada o editada usa el tipo «ajuste» con la referencia de la venta. */
export function movementLabel(m) {
  if (m.type === 'adjustment') {
    if (m.reference_type === 'inventory_count') return 'Conteo';
    if (String(m.reference_type || '').startsWith('sale_item')) return 'Reverso de venta';
  }
  return MOVEMENT_TYPES[m.type] || m.type;
}

/** Cantidad sin ceros sobrantes (hasta 4 decimales) y con coma decimal. */
export function formatQty(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '—';
  return Number(n.toFixed(4)).toLocaleString('es-BO', { maximumFractionDigits: 4 });
}

/** Con signo explícito: +2,5 / −2,5. */
export function formatSigned(value) {
  const n = Number(value);
  if (!Number.isFinite(n) || n === 0) return '0';
  return `${n > 0 ? '+' : '−'}${formatQty(Math.abs(n))}`;
}

/** «hoy», «ayer» o «hace 6 días»; vacío si nunca. */
export function relativeDays(iso) {
  if (!iso) return '';
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
  if (days <= 0) return 'hoy';
  if (days === 1) return 'ayer';
  return `hace ${days} días`;
}

export function formatDateTime(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
