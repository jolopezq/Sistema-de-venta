/** Filas y textos de los indicadores de inventario (F5), compartidos por la tarjeta, el informe y su impresión. */
import { formatBs, formatPercent } from '../stores/finance';
import { formatQty, REORDER_STATUS } from './inventoryLabels';

export const INDICATOR_STATUS = {
  ok:   { label: 'En meta',          tone: 'ok' },
  warn: { label: 'Cerca de la meta', tone: 'warn' },
  err:  { label: 'Fuera de meta',    tone: 'err' },
  none: { label: 'Sin medir',        tone: '' },
  info: { label: 'Informativo',      tone: 'info' },
};

export const statusOf = (status) => INDICATOR_STATUS[status] || INDICATOR_STATUS.none;

/** Insignia de una fila: el valor del inventario no tiene meta, es informativo. */
export const rowBadge = (row) => (row.informative ? INDICATOR_STATUS.info : statusOf(row.status));

const plural = (n, one, many) => (Number(n) === 1 ? one : many);
const daysText = (n) => `${formatQty(n, 1)} ${plural(n, 'día', 'días')}`;

/** Meta sin decimales sobrantes: «5 %» y no «5,0 %». */
const goalPercent = (n) => formatPercent(n).replace(',0 %', ' %');

/** «+2,5 pts» / «−19,4 pts» */
export function formatPoints(value) {
  const n = Number(value) || 0;
  const text = Math.abs(n).toLocaleString('es-BO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  return `${n > 0 ? '+' : n < 0 ? '−' : ''}${text} pts`;
}

/** «03/09» a partir de «2026-09-03» */
const shortDay = (iso) => String(iso).slice(8, 10) + '/' + String(iso).slice(5, 7);

/**
 * Los seis indicadores como filas: valor grande, estado frente a la meta, la meta, un detalle, el mes anterior y,
 * cuando no está en meta, una pista de qué revisar.
 */
export function indicatorRows(d) {
  if (!d) return [];
  const t = d.targets;
  const prev = d.previous || {};
  const { value, coverage, stockouts, waste, cost_gap: gap, accuracy } = d;

  const rows = [];

  rows.push({
    key: 'value', label: 'Valor del inventario', scope: 'hoy', status: null, informative: true,
    value: formatBs(value.total),
    goal: 'Stock por costo promedio, a hoy',
    detail: `${value.items} ${plural(value.items, 'insumo', 'insumos')} con control de stock`
      + (value.unpriced ? ` · ${value.unpriced} sin costo` : '') + (value.negative ? ` · ${value.negative} en negativo` : ''),
    previous: '',
    hint: value.unpriced || value.negative ? 'Los insumos sin costo o con stock negativo no suman: corrígelos para que el valor sea real.' : '',
  });

  const lowest = coverage.items[0];
  rows.push({
    key: 'coverage', label: 'Días de cobertura', scope: 'hoy', status: coverage.status,
    value: coverage.min_days === null ? '—' : daysText(coverage.min_days),
    goal: coverage.key_only ? 'El insumo clave que primero se acaba' : 'El insumo que primero se acaba',
    detail: lowest
      ? `${lowest.name}${coverage.low ? ` · ${coverage.low} ${plural(coverage.low, 'insumo bajo', 'insumos bajos')} su punto de reorden` : ' · todos sobre su punto de reorden'}`
      : 'Falta el consumo diario de los insumos',
    previous: '',
    hint: !coverage.key_only && coverage.items.length ? 'Marca los insumos clave en Inventario para medir solo los que no pueden faltar.' : '',
  });

  const out = stockouts.operating_days === 0 ? null : stockouts.status;
  rows.push({
    key: 'stockouts', label: 'Quiebres de stock', scope: 'mes', status: out,
    value: `${stockouts.days} ${plural(stockouts.days, 'día', 'días')}`,
    goal: t.stockout_days === 0 ? 'Meta: ningún día sin un insumo clave' : `Meta: hasta ${t.stockout_days} días`,
    detail: stockouts.operating_days === 0 ? 'Sin ventas en el mes'
      : stockouts.events.length ? stockouts.events.slice(0, 3).map((e) => `${e.name} (${e.days})`).join(', ') : 'Ningún insumo clave se agotó en los días de venta',
    previous: prev.stockout_days !== null && prev.stockout_days !== undefined ? `${prev.label}: ${prev.stockout_days} ${plural(prev.stockout_days, 'día', 'días')}` : '',
    hint: stockouts.days ? 'Un día cuenta cuando un insumo clave llegó a cero en un día de venta. No incluye productos marcados agotados a mano.' : '',
  });

  rows.push({
    key: 'waste', label: 'Merma sobre el consumo', scope: 'mes', status: waste.status,
    value: waste.percent === null ? '—' : formatPercent(waste.percent),
    goal: `Meta: hasta ${goalPercent(t.waste_percent)} (perecederos ${goalPercent(t.waste_percent_perishable)})`,
    detail: waste.percent === null
      ? (waste.registered_cost > 0 ? `${formatBs(waste.registered_cost)} anotados, sin ventas con qué compararlos` : 'Sin merma anotada ni ventas en el mes')
      : `${formatBs(waste.registered_cost)} anotados de ${formatBs(waste.consumption_cost)} consumidos`,
    previous: prev.waste_percent !== null && prev.waste_percent !== undefined ? `${prev.label}: ${formatPercent(prev.waste_percent)}` : '',
    hint: waste.unpriced ? `${waste.unpriced} ${plural(waste.unpriced, 'merma sin costo no suma', 'mermas sin costo no suman')}: carga el costo del insumo.` : '',
  });

  rows.push({
    key: 'gap', label: 'Diferencia de costo', scope: 'mes', status: gap ? gap.status : null,
    value: gap ? formatPoints(gap.points) : '—',
    goal: `Meta: dentro de ±${goalPercent(t.cost_gap_points)} de las ventas`,
    detail: gap ? `Compras ${formatPercent(gap.real_percent)} de las ventas · recetas ${formatPercent(gap.recipes_percent)}` : 'Se calcula con recetas y ventas del mes',
    previous: '',
    hint: !gap || gap.status === 'ok' ? ''
      : gap.points > 0 ? 'Se compró más de lo que explican las recetas: merma, porciones más grandes o stock acumulado.'
        : 'Se compró menos de lo que consumieron las recetas: puede faltar cargar compras o se usó stock anterior.',
  });

  rows.push({
    key: 'accuracy', label: 'Exactitud del conteo', scope: 'mes', status: accuracy.status,
    value: accuracy.percent === null ? '—' : formatPercent(accuracy.percent),
    goal: `Meta: ${goalPercent(t.accuracy_percent)} o más`,
    detail: accuracy.percent !== null ? `${accuracy.exact} de ${accuracy.compared} insumos dentro de ±${goalPercent(t.accuracy_tolerance)}`
      : accuracy.baseline_lines ? `${accuracy.baseline_lines} ${plural(accuracy.baseline_lines, 'primer conteo', 'primeros conteos')}: aún no se comparan` : 'Sin conteos en el mes',
    previous: prev.accuracy_percent !== null && prev.accuracy_percent !== undefined ? `${prev.label}: ${formatPercent(prev.accuracy_percent)}` : '',
    hint: '',
  });

  return rows;
}

/** Resumen para la insignia de la tarjeta. */
export function indicatorsSummary(rows) {
  const measured = rows.filter((r) => r.status);
  const err = measured.filter((r) => r.status === 'err').length;
  const warn = measured.filter((r) => r.status === 'warn').length;
  if (!measured.length) return { tone: '', label: 'Sin medir' };
  if (err) return { tone: 'err', label: `${err} fuera de meta` };
  if (warn) return { tone: 'warn', label: `${warn} cerca de la meta` };
  return { tone: 'ok', label: 'Todo en meta' };
}

/**
 * Tablas del informe: cobertura, quiebres, merma por insumo, conteos y valor. Cada sección trae encabezados, filas ya
 * formateadas (texto) y un aviso para cuando no hay nada que mostrar.
 */
export function reportSections(d) {
  if (!d) return [];
  const sections = [];

  sections.push({
    key: 'coverage', title: d.coverage.key_only ? 'Cobertura de los insumos clave' : 'Cobertura de los insumos con consumo',
    head: ['Insumo', 'Hay', 'Gasta por día', 'Cobertura', 'Estado'],
    rows: d.coverage.items.map((i) => [i.name, `${formatQty(i.stock, 2)} ${i.unit}`, `${formatQty(i.daily_use, 2)} ${i.unit}`, daysText(i.days), REORDER_STATUS[i.status]?.label || '—']),
    empty: 'Ningún insumo tiene aún consumo diario para calcular su cobertura.',
    note: d.coverage.without_data ? `${d.coverage.without_data} ${plural(d.coverage.without_data, 'insumo sin datos', 'insumos sin datos')} para calcular su cobertura.` : '',
  });

  sections.push({
    key: 'stockouts', title: 'Quiebres de stock',
    head: ['Insumo', 'Días sin stock', 'Fechas'],
    rows: d.stockouts.events.map((e) => [e.name, String(e.days), e.dates.map(shortDay).join(', ') + (e.days > e.dates.length ? '…' : '')]),
    empty: 'Ningún insumo clave se agotó en un día de venta.',
    note: `Se revisaron ${d.stockouts.ingredients_checked} ${plural(d.stockouts.ingredients_checked, 'insumo', 'insumos')}${d.stockouts.key_only ? ' clave' : ''} en ${d.stockouts.operating_days} ${plural(d.stockouts.operating_days, 'día de venta', 'días de venta')}. No incluye productos marcados agotados a mano.`,
  });

  const hidden = d.hidden;
  sections.push({
    key: 'waste', title: 'Merma por insumo',
    head: ['Insumo', 'Merma', '% del consumo', 'Meta', 'Estado'],
    rows: d.waste.by_ingredient.map((w) => [w.name, w.cost > 0 ? formatBs(w.cost) : 'Sin costo', w.percent === null ? 'Sin ventas valoradas' : formatPercent(w.percent), goalPercent(w.target), statusOf(w.status).label]),
    empty: 'No se anotó merma en el mes.',
    note: hidden.lines
      ? `Merma oculta según los conteos: ${hidden.net_loss >= 0 ? 'faltante' : 'sobrante'} neto de ${formatBs(Math.abs(hidden.net_loss))} en ${hidden.counts} ${plural(hidden.counts, 'conteo', 'conteos')}.`
      : 'Aún no hay conteos comparables para medir la merma oculta.',
  });

  sections.push({
    key: 'accuracy', title: 'Conteos con más diferencia',
    head: ['Insumo', 'Esperado', 'Contado', 'Diferencia'],
    rows: d.accuracy.worst.map((a) => [a.name, `${formatQty(a.expected, 2)} ${a.unit}`, `${formatQty(a.counted, 2)} ${a.unit}`, formatPercent(a.gap_percent)]),
    empty: 'No hubo conteos con diferencia en el mes.',
    note: '',
  });

  sections.push({
    key: 'value', title: 'Dónde está el valor del inventario',
    head: ['Insumo', 'Stock', 'Costo promedio', 'Valor', '% del total'],
    rows: d.value.top.map((v) => [v.name, `${formatQty(v.stock, 2)} ${v.unit}`, formatBs(v.unit_cost), formatBs(v.value), formatPercent(v.share)]),
    empty: 'No hay stock valorizado.',
    note: '',
  });

  return sections;
}
