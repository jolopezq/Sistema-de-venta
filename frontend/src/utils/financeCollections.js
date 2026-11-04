/**
 * Finanzas: cómo se cobraron las ventas del local (Estado de Resultados) y dinero que entró en el mes (Resumen).
 * Funciones puras sobre `pnl.revenue` y `pnl.cash_in` (FinanceReportService).
 */

const num = (v) => Number(v) || 0;

const bs = (v) => {
  const n = num(v);
  const text = Math.abs(n).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return `${n < 0 ? '−' : ''}Bs ${text}`;
};

/** "2026-10-14" → "14/10" */
const dayMonth = (iso) => {
  const [, m, d] = String(iso || '').slice(0, 10).split('-');
  return d && m ? `${d}/${m}` : '—';
};

/** "a, b y c" */
function joinList(parts) {
  if (parts.length <= 1) return parts.join('');
  return `${parts.slice(0, -1).join(', ')} y ${parts[parts.length - 1]}`;
}

function percentOf(amount, base) {
  if (!base) return '';
  const p = (num(amount) / base) * 100;
  return `${p.toLocaleString('es-BO', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} % de las ventas del local`;
}

/** «14/10 sin pago», «22/10 Bs 100,00 de más», «03/10 faltan Bs 5,00» */
export function gapSaleText(sale) {
  const total = num(sale.total);
  const paid = num(sale.paid);
  const day = dayMonth(sale.date);
  if (paid === 0) return `${day} sin pago`;
  const diff = Math.round((paid - total) * 100) / 100;
  return diff > 0 ? `${day} ${bs(diff)} de más` : `${day} faltan ${bs(-diff)}`;
}

/** Detalle de «Sin cuadrar»: cuántas ventas, cuáles (hasta 3) y dónde corregirlas. */
export function gapDetail(revenue) {
  const count = num(revenue?.payments_gap_count);
  if (!count) return '';
  const sales = (revenue.payments_gap_sales || []).slice(0, 3).map(gapSaleText);
  const more = count - sales.length;
  const list = sales.join(', ') + (more > 0 ? ` y ${more} más` : '');
  const which = count === 1 ? '1 venta no cuadra con sus pagos' : `${count} ventas no cuadran con sus pagos`;
  return `${which}: ${list}. ${count === 1 ? 'Corrígela' : 'Corrígelas'} en Control diario.`;
}

/**
 * Filas «Cómo se cobró» debajo de Ventas del local. Efectivo, QR o banco y tarjeta siempre; otros medios y
 * «Sin cuadrar» solo si no son 0. Sumadas dan exactamente las ventas del local.
 */
export function localPaymentRows(revenue) {
  const r = revenue || {};
  const local = num(r.local_sales);
  const rows = [
    { key: 'cash', label: 'Efectivo', amount: num(r.cash_sales) },
    { key: 'qr', label: 'QR o banco', amount: num(r.qr_sales) },
    { key: 'card', label: 'Tarjeta', amount: num(r.card_sales) },
  ];
  if (num(r.other_sales) !== 0) rows.push({ key: 'other', label: 'Otros medios', amount: num(r.other_sales) });
  rows.forEach((row) => { row.detail = percentOf(row.amount, local); });
  if (Math.abs(num(r.payments_gap)) >= 0.01) {
    rows.push({ key: 'gap', label: 'Sin cuadrar', amount: num(r.payments_gap), detail: gapDetail(r), warn: true });
  }
  return rows;
}

/** «Depósitos del 01/10, 08/10 y 15/10» o «Sin depósitos en el mes». */
export function depositsText(cashIn) {
  const deposits = cashIn?.pedidosya_deposits || [];
  if (!deposits.length) return 'Sin depósitos en el mes';
  const days = deposits.map((d) => dayMonth(d.payout_date));
  return `${deposits.length === 1 ? 'Depósito del' : 'Depósitos del'} ${joinList(days)}`;
}

const weekText = (w) => `del ${dayMonth(w.start_date)} al ${dayMonth(w.end_date)}`;

/** Avisos debajo de «Dinero que entró en el mes». */
export function cashInNotes(cashIn, revenue) {
  const c = cashIn || {};
  const notes = [];

  const later = c.pedidosya_paid_later_weeks || [];
  if (later.length === 1) {
    const w = later[0];
    notes.push(`PedidosYa: la semana ${weekText(w)} (${bs(c.pedidosya_paid_later)}) se deposita el ${dayMonth(w.payout_date)}, ya en el mes siguiente.`);
  } else if (later.length > 1) {
    const days = joinList(later.map((w) => dayMonth(w.payout_date)));
    notes.push(`PedidosYa: las semanas ${joinList(later.map(weekText))} (${bs(c.pedidosya_paid_later)}) se depositan el ${days}, ya en el mes siguiente.`);
  }

  const noDate = c.pedidosya_without_payout_date || [];
  if (noDate.length) {
    notes.push(`${noDate.length === 1 ? 'La semana' : 'Las semanas'} ${joinList(noDate.map(weekText))} de PedidosYa ${noDate.length === 1 ? 'no tiene' : 'no tienen'} fecha de pago: no se ${noDate.length === 1 ? 'cuenta' : 'cuentan'} como depositada${noDate.length === 1 ? '' : 's'}.`);
  }

  const ranges = c.pedidosya_uncovered_ranges || [];
  if (ranges.length) {
    const text = joinList(ranges.map((r) => (r.from === r.to ? `el ${dayMonth(r.from)}` : `del ${dayMonth(r.from)} al ${dayMonth(r.to)}`)));
    notes.push(`Sin semana de PedidosYa importada: ${text}.`);
  }

  const gapCount = num(revenue?.payments_gap_count);
  if (gapCount) {
    notes.push(`${gapCount === 1 ? '1 venta del local no cuadra' : `${gapCount} ventas del local no cuadran`} con sus pagos: aquí cuentan los pagos registrados.`);
  }

  if (num(c.card) > 0) notes.push('La tarjeta llega a la cuenta con el descuento del banco.');

  return notes;
}

/**
 * Efectivo que debía llegar a la mano (docs/PLAN_CONCILIACION_CAJA.md, A): de lo cobrado en efectivo a lo
 * retirado de las cajas cerradas del mes. Las filas suman exactamente `withdrawn`.
 * @param {object} drawer `pnl.cash_in.drawer` (CashDrawerService)
 * @returns {{ rows: Array<{key: string, label: string, detail?: string, amount: number}>, total: number, notes: string[] }}
 */
export function drawerBreakdown(drawer) {
  const d = drawer || {};
  const rows = [{ key: 'collected', label: 'Cobrado en efectivo', detail: 'ventas del local', amount: num(d.collected) }];
  const add = (key, label, amount, detail) => {
    if (Math.abs(num(amount)) > 0.005) rows.push({ key, label, amount: Math.round(num(amount) * 100) / 100, ...(detail ? { detail } : {}) });
  };

  add('open', 'Quedó en cajas sin cerrar', -num(d.in_open_sessions), 'todavía no se retiró');
  add('outside', 'Cobrado fuera de las cajas del mes', -num(d.outside_sessions), 'ventas sin caja: no están en ningún arqueo');
  add('expenses', 'Pagado con plata de la gaveta', -num(d.drawer_expenses), 'compras y gastos en efectivo');
  add('income', 'Ingresos extra en efectivo', num(d.extra_income));

  const diffs = d.difference_items || [];
  if (diffs.length) {
    const shortage = diffs.every((x) => num(x.amount) < 0);
    const surplus = diffs.every((x) => num(x.amount) > 0);
    const label = shortage ? 'Faltantes al cerrar caja' : surplus ? 'Sobrantes al cerrar caja' : 'Faltantes y sobrantes al cerrar caja';
    add('differences', label, num(d.differences), joinList(diffs.map((x) => `${dayMonth(x.date)} ${bs(x.amount)}`)));
  }

  const change = num(d.fund_change);
  add('fund', change >= 0 ? 'Quedó de más en la gaveta como cambio' : 'Se usó cambio de la gaveta', -change,
    `${bs(d.fund_start)} al empezar → ${bs(d.fund_end)} al terminar`);

  const jumps = (d.fund_jump_items || []).filter((j) => j.in_total);
  if (jumps.length) {
    add('jumps', 'Fondos distintos a lo que dejó la caja anterior', num(d.fund_jumps),
      joinList(jumps.map((j) => `${dayMonth(j.date)} ${num(j.amount) > 0 ? '+' : ''}${bs(j.amount)}`)));
  }
  add('unexplained', 'No cuadra en cierres antiguos', num(d.unexplained), 'retirado + fondo no suma lo contado');

  const notes = [];
  const open = d.open_sessions || [];
  if (open.length) {
    const list = joinList(open.map((s) => `${dayMonth(s.date)}${s.cashier ? ` (${s.cashier})` : ''}`));
    notes.push(`${open.length === 1 ? 'La caja' : 'Las cajas'} ${list} ${open.length === 1 ? 'sigue abierta' : 'siguen abiertas'}: ciérra${open.length === 1 ? 'la' : 'las'} en Control diario.`);
  }

  return { rows, total: num(d.withdrawn), notes };
}

/**
 * QR anotado de la app del banco al cerrar caja contra el QR del sistema (plan de conciliación, G).
 * @param {object} qrBank `pnl.cash_in.drawer.qr_bank`
 * @returns {string|null}
 */
export function qrBankNote(qrBank) {
  const q = qrBank || {};
  const reported = num(q.reported_count);
  if (!reported) return null;
  const of = `${reported} de ${num(q.closed_count)} ${num(q.closed_count) === 1 ? 'cierre' : 'cierres'}`;
  const diff = num(q.difference);
  if (Math.abs(diff) < 0.005) return `QR comparado con la app del banco en ${of}: coincide.`;
  const days = joinList((q.items || []).map((i) => `${dayMonth(i.date)} ${num(i.difference) > 0 ? '+' : ''}${bs(i.difference)}`));
  return `QR comparado con la app del banco en ${of}: ${diff < 0 ? `faltan ${bs(-diff)}` : `hay ${bs(diff)} de más`} en el banco (${days}).`;
}
