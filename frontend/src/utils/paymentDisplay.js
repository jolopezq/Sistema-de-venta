/**
 * Utilidades para formatear y visualizar métodos de pago de una venta.
 * Soporta ventas con un único método y ventas con pagos divididos/mixtos.
 */

export const METHOD_LABELS = {
  cash: '💵 Efectivo',
  qr: '📱 QR',
  card: '💳 Tarjeta',
  delivery_platform: '🛵 PedidosYa',
};

export const METHOD_CLASSES = {
  cash: 'badge-cash',
  qr: 'badge-qr',
  card: 'badge-card',
  delivery_platform: 'badge-pedidosya',
};

/**
 * Retorna la lista de pastillas (badges) a mostrar para los pagos de una venta.
 * Si la venta tiene pagos divididos (más de uno con monto positivo), incluye el monto de cada uno.
 *
 * @param {Object} sale - Objeto de venta con `payments` y opcionalmente `source`.
 * @returns {Array<{ label: string, class: string, method?: string, amount?: number }>}
 */
export function getSalePaymentBadges(sale) {
  if (!sale) {
    return [{ label: 'Sin pago', class: 'badge-gray' }];
  }

  // Caso especial PedidosYa sin pagos explícitos
  if (sale.source === 'pedidosya' && (!sale.payments || sale.payments.length === 0)) {
    return [{
      label: METHOD_LABELS.delivery_platform,
      class: METHOD_CLASSES.delivery_platform,
      method: 'delivery_platform',
    }];
  }

  // Filtrar pagos válidos con monto positivo
  const validPayments = (sale.payments || []).filter((p) => Number(p?.amount) > 0);

  if (validPayments.length === 0) {
    // Si tiene pagos registrados pero todos con 0, mostrar al menos el método del primero
    if (sale.payments && sale.payments.length > 0 && sale.payments[0]?.method) {
      const m = sale.payments[0].method;
      return [{
        label: METHOD_LABELS[m] || m,
        class: METHOD_CLASSES[m] || 'badge-gray',
        method: m,
      }];
    }
    return [{ label: 'Sin pago', class: 'badge-gray' }];
  }

  const isSplit = validPayments.length > 1;

  return validPayments.map((p) => {
    const baseLabel = METHOD_LABELS[p.method] || p.method || 'Otro';
    const badgeClass = METHOD_CLASSES[p.method] || 'badge-gray';
    const amountNum = Number(p.amount || 0);

    const label = isSplit
      ? `${baseLabel} Bs ${amountNum.toFixed(2)}`
      : baseLabel;

    return {
      label,
      class: badgeClass,
      method: p.method,
      amount: amountNum,
    };
  });
}

/**
 * Retorna una descripción en texto plano para tablas compactas (ej. Turno.vue).
 * Si hay múltiples pagos, los desglosa con su monto: "Mixto: QR (Bs 40.00) + Efectivo (Bs 5.00)".
 *
 * @param {Object} sale
 * @returns {string}
 */
export function getSalePaymentText(sale) {
  const map = { cash: 'Efectivo', qr: 'QR', card: 'Tarjeta', delivery_platform: 'PedidosYa' };

  if (!sale) return 'Otro';

  if (sale.source === 'pedidosya' && (!sale.payments || sale.payments.length === 0)) {
    return 'PedidosYa';
  }

  const valid = (sale.payments || []).filter((p) => Number(p?.amount) > 0);

  if (valid.length > 1) {
    return `Mixto: ${valid.map((p) => `${map[p.method] || p.method} (Bs ${Number(p.amount).toFixed(2)})`).join(' + ')}`;
  }

  if (valid.length === 1) {
    return map[valid[0].method] || valid[0].method || 'Otro';
  }

  if (sale.payments && sale.payments.length > 0 && sale.payments[0]?.method) {
    return map[sale.payments[0].method] || sale.payments[0].method;
  }

  return 'Efectivo';
}
