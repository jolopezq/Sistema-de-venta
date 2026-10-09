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

/** Cantidad sin ceros sobrantes (hasta 4 decimales; menos en cifras calculadas) y con coma decimal. */
export function formatQty(value, decimals = 4) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '—';
  return Number(n.toFixed(decimals)).toLocaleString('es-BO', { maximumFractionDigits: decimals });
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

// ── Reposición (F4) ────────────────────────────────────────────────────────────────────────────────────────────

/** Estados de reposición del servidor, con el texto y el color de la insignia (clases `fin-badge`). */
export const REORDER_STATUS = {
  out:      { label: 'Agotado',        tone: 'err',    hint: 'Sin stock (o en negativo): hay que reponer y revisar si falta cargar una compra o un conteo.' },
  critical: { label: 'Crítico',        tone: 'err',    hint: 'Se acaba antes de que llegue un pedido nuevo.' },
  reorder:  { label: 'Reordenar',      tone: 'warn',   hint: 'Llegó al punto de reorden: conviene pedir.' },
  ok:       { label: 'OK',             tone: 'ok',     hint: 'Hay stock de sobra por ahora.' },
  daily:    { label: 'Compra diaria',  tone: 'info',   hint: 'Se compra el mismo día: no genera alertas de reorden.' },
  nodata:   { label: 'Sin datos',      tone: 'acai',   hint: 'Falta su consumo diario estimado o un punto de reorden manual.' },
  none:     { label: 'Sin control',    tone: 'acai',   hint: 'No se controla la reposición de este insumo.' },
};

/** De dónde sale el consumo diario con que se calculó. */
export const REORDER_BASIS = {
  real:     'Ventas reales (28 días)',
  partial:  'Pocas ventas registradas',
  estimate: 'Estimado por el dueño',
  manual:   'Punto de reorden manual',
  none:     'Sin datos',
};

export const EXPIRY_LABEL = { soon: 'Vence pronto', expired: 'Vencido' };

/** «vence hoy», «vence mañana», «vence en 3 días», «venció hace 2 días». */
export function expiryText(expiry) {
  if (!expiry || expiry.days_left === null || expiry.days_left === undefined) return '';
  const d = expiry.days_left;
  if (d === 0) return 'vence hoy';
  if (d === 1) return 'vence mañana';
  if (d > 1) return `vence en ${d} días`;
  return d === -1 ? 'venció ayer' : `venció hace ${Math.abs(d)} días`;
}

/** Texto de la lista de compras de un proveedor, para copiar o mandar por WhatsApp. */
export function shoppingListText(supplierName, rows, { heading = true } = {}) {
  const lines = rows.map((r) => `• ${r.name}: ${formatQty(r.units)} ${r.unit_label}${r.pack ? ` ${r.pack}` : ''}`);
  const title = supplierName ? `Pedido para ${supplierName}` : 'Lista de compras';
  return [heading ? `${title} — Ohana` : '', ...lines].filter((l) => l !== '').join('\n');
}

/** Enlace de WhatsApp con el pedido ya escrito (sin servicios externos). El teléfono se deja a Bolivia si no trae código. */
export function whatsappLink(phone, text) {
  const digits = String(phone || '').replace(/\D/g, '');
  const number = digits.length === 8 ? `591${digits}` : digits;

  return `https://wa.me/${number}?text=${encodeURIComponent(text)}`;
}
