<script setup>
import { computed } from 'vue';
import { methodLabel, methodDetail, methodWithAmount } from '../utils/cashMovementMethod';

const props = defineProps({
  report: {
    type: Object,
    required: true,
  },
});

const reportData = computed(() => props.report || {});
const session = computed(() => reportData.value.session || {});
const summary = computed(() => reportData.value.summary || {});
// Caja cerrada que cambió después del cierre (venta editada, anulada o sincronizada tarde, egreso):
// el cierre guardado se muestra tal cual se firmó y, aparte, el teórico y la diferencia con las cifras de hoy.
const recalc = computed(() => (reportData.value.recalculated?.changed ? reportData.value.recalculated : null));

// Caja corregida: sus cierres anteriores (reabierta con motivo y vuelta a cerrar)
const closings = computed(() => reportData.value.closings || []);
// Corrección que cambió el «fondo para mañana»: la caja siguiente abrió con otro fondo
const chainWarning = computed(() => {
  const c = reportData.value.chain;
  return closings.value.length && c && !c.matches ? c : null;
});

// Descuentos manuales de la caja (docs/PLAN_DESCUENTOS_CAJA.md): quién, cuánto y por qué
const discounts = computed(() => reportData.value.discounts || []);
const discountsTotal = computed(() => discounts.value.reduce((sum, d) => sum + Number(d.discount_amount || 0), 0));

// Movimientos corregidos o anulados (docs/PLAN_CORREGIR_GASTOS_CAJA.md)
const correctedMovements = computed(() => {
  const movs = reportData.value.movements || [];
  return movs.filter(m => m.is_edited || m.is_voided);
});

const money = (n) => Number(n || 0).toFixed(2);
const pct = (n) => (Number.isInteger(Number(n)) ? String(Number(n)) : Number(n).toFixed(1));
function formatTime(dateStr) {
  if (!dateStr) return '--:--';
  return new Date(dateStr).toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit' });
}
const signed = (n) => `${Number(n) > 0 ? '+' : ''}Bs ${money(n)}`;
const diffLabel = (n) => (Number(n) === 0 ? 'Exacto' : Number(n) > 0 ? 'Sobrante' : 'Faltante');

function formatDate(dateStr) {
  if (!dateStr) return '--:--';
  const d = new Date(dateStr);
  return d.toLocaleString('es-BO', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}
</script>

<template>
  <div class="report-ticket">
    <!-- CABECERA -->
    <div class="ticket-center header">
      <h2 class="brand-title">OHANA ACAÍ</h2>
      <div class="report-type">REPORTE DE CIERRE DE CAJA</div>
      <div class="divider">================================</div>
    </div>

    <!-- METADATOS DEL TURNO -->
    <div class="meta-section">
      <div class="row">
        <span>Cajero:</span>
        <strong>{{ reportData.cashier_name || session.cashier?.name || 'Cajero' }}</strong>
      </div>
      <div class="row">
        <span>Apertura:</span>
        <span>{{ formatDate(reportData.opened_at || session.opened_at) }}</span>
      </div>
      <div class="row">
        <span>Cierre:</span>
        <span>{{ formatDate(reportData.closed_at || session.closed_at) }}</span>
      </div>
      <div class="row" v-if="reportData.duration">
        <span>Duración:</span>
        <span>{{ reportData.duration }}</span>
      </div>
      <div class="row">
        <span>ID Turno:</span>
        <span class="mono">#{{ session.id || '---' }}</span>
      </div>
    </div>

    <div class="divider">--------------------------------</div>

    <!-- RESUMEN DE VENTAS -->
    <div class="section-title">VENTAS DEL LOCAL</div>
    <div class="sales-section">
      <div class="row">
        <span>Tickets completados:</span>
        <strong>{{ summary.sales_count || 0 }}</strong>
      </div>
      <div class="row" v-if="summary.voided_count > 0">
        <span>Tickets anulados:</span>
        <span class="text-danger">{{ summary.voided_count }}</span>
      </div>
      <div class="row">
        <span>Ventas en Efectivo:</span>
        <span>Bs {{ Number(summary.cash_sales || 0).toFixed(2) }}</span>
      </div>
      <div class="row">
        <span>Ventas QR / Transf:</span>
        <span>Bs {{ Number(summary.qr_sales || 0).toFixed(2) }}</span>
      </div>
      <div class="row">
        <span>Ventas Tarjeta / POS:</span>
        <span>Bs {{ Number(summary.card_sales || 0).toFixed(2) }}</span>
      </div>
      <div class="row total-row">
        <span>TOTAL DEL LOCAL:</span>
        <strong class="total-amt">Bs {{ Number(summary.total_sales || 0).toFixed(2) }}</strong>
      </div>
    </div>

    <div class="divider">--------------------------------</div>

    <!-- PEDIDOSYA: se informa aparte, no forma parte del arqueo -->
    <div class="section-title">PEDIDOSYA</div>
    <div class="row mini"><span>(Informativo, no se rinde en caja)</span></div>
    <div class="sales-section">
      <div class="row">
        <span>Pedidos:</span>
        <strong>{{ summary.pedidosya_count || 0 }}</strong>
      </div>
      <div class="row">
        <span>Total PedidosYa:</span>
        <strong>Bs {{ Number(summary.pedidosya_total || 0).toFixed(2) }}</strong>
      </div>
    </div>

    <div class="divider">================================</div>

    <!-- ARQUEO DE EFECTIVO -->
    <div class="section-title">ARQUEO DE EFECTIVO (GAVETA)</div>
    <div class="arqueo-section">
      <div class="row">
        <span>Fondo Inicial:</span>
        <span>Bs {{ Number(reportData.opening_amount || session.opening_amount || 0).toFixed(2) }}</span>
      </div>
      <div class="row">
        <span>(+) Ventas Efectivo:</span>
        <span>Bs {{ Number(summary.cash_sales || 0).toFixed(2) }}</span>
      </div>
      <!-- Solo efectivo: así las filas suman el teórico (los egresos con QR o tarjeta van aparte) -->
      <div class="row" v-if="Number(summary.cash_extra_income || 0) > 0">
        <span>(+) Ingresos Efectivo:</span>
        <span>Bs {{ Number(summary.cash_extra_income || 0).toFixed(2) }}</span>
      </div>
      <div class="row" v-if="Number(summary.cash_expenses ?? summary.total_expenses ?? 0) > 0">
        <span>(-) Egresos Efectivo:</span>
        <span class="text-danger">Bs {{ Number(summary.cash_expenses ?? summary.total_expenses ?? 0).toFixed(2) }}</span>
      </div>
      <!-- Las filas de arriba son las cifras de hoy: si la caja cambió después del cierre, suman el teórico de hoy -->
      <div class="row highlight-row">
        <span>(=) Efectivo Teórico{{ recalc ? ' hoy' : '' }}:</span>
        <strong>Bs {{ money(recalc ? recalc.expected_closing : reportData.expected_closing) }}</strong>
      </div>
      <div v-if="recalc" class="row">
        <span>Teórico al cierre:</span>
        <span>Bs {{ money(reportData.expected_closing) }}</span>
      </div>
      <div class="row highlight-row">
        <span>Efectivo Contado:</span>
        <strong>Bs {{ money(reportData.actual_closing) }}</strong>
      </div>
      <div class="row diff-row">
        <span>{{ recalc ? 'DIF. AL CIERRE' : 'DIFERENCIA' }}:</span>
        <strong :class="Number(reportData.difference) < 0 ? 'text-danger' : 'text-success'">
          {{ signed(reportData.difference) }} ({{ diffLabel(reportData.difference) }})
        </strong>
      </div>
      <div v-if="recalc" class="row diff-row diff-row--today">
        <span>Diferencia hoy:</span>
        <strong :class="Number(recalc.difference) < 0 ? 'text-danger' : 'text-success'">
          {{ signed(recalc.difference) }} ({{ diffLabel(recalc.difference) }})
        </strong>
      </div>
      <div class="row" v-if="Number(summary.non_cash_expenses || 0) > 0">
        <span>Egresos QR/Tarjeta (no salen de gaveta):</span>
        <span>Bs {{ Number(summary.non_cash_expenses || 0).toFixed(2) }}</span>
      </div>
    </div>

    <!-- DESCUENTOS DEL TURNO (ya restados de las ventas) -->
    <div v-if="discounts.length" class="discounts-section">
      <div class="divider">--------------------------------</div>
      <div class="section-title">DESCUENTOS ({{ discounts.length }})</div>
      <div v-for="d in discounts" :key="d.id" class="discount-item">
        <div class="row mini">
          <span>#{{ d.order_number }} · {{ formatTime(d.created_at) }}</span>
          <strong class="nowrap">-Bs {{ money(d.discount_amount) }}</strong>
        </div>
        <div class="row mini">
          <span>{{ d.is_courtesy ? 'Cortesía (100 %)' : `${pct(d.percent)} % de Bs ${money(d.subtotal)}` }}{{ d.cashier ? ` · ${d.cashier}` : '' }}</span>
          <span class="nowrap">Cobró Bs {{ money(d.total_amount) }}</span>
        </div>
        <div class="correction-text">«{{ d.discount_reason || 'sin motivo' }}»</div>
      </div>
      <div class="row">
        <span>Total descuentos:</span>
        <strong>Bs {{ money(discountsTotal) }}</strong>
      </div>
    </div>

    <!-- GASTOS Y MOVIMIENTOS CORREGIDOS O ANULADOS (docs/PLAN_CORREGIR_GASTOS_CAJA.md) -->
    <div v-if="correctedMovements.length" class="corrections" role="note">
      <div class="divider">--------------------------------</div>
      <div class="section-title">MOVIMIENTOS CORREGIDOS / ANULADOS ({{ correctedMovements.length }})</div>
      <div v-for="m in correctedMovements" :key="m.id" class="correction-item">
        <div class="row mini">
          <span>{{ formatTime(m.occurred_at) }} · {{ m.type === 'expense' ? 'Egreso' : 'Ingreso' }}</span>
          <strong :class="m.is_voided ? 'text-danger' : ''">
            {{ m.is_voided ? 'ANULADO' : `Bs ${money(m.amount)} (${methodLabel(m)})` }}
          </strong>
        </div>
        <div class="row mini">
          <span>{{ m.description }}</span>
          <span v-if="m.is_edited && !m.is_voided">
            Antes: {{ methodWithAmount(m.edit_previous) }}
          </span>
        </div>
        <div v-if="!m.is_voided && methodDetail(m)" class="correction-text">{{ methodDetail(m) }}</div>
        <div v-if="m.is_edited && !m.is_voided" class="correction-text">
          Corregido{{ m.edited_by ? ` por ${m.edited_by}` : '' }}: «{{ m.edit_reason }}»
        </div>
        <div v-if="m.is_voided" class="correction-text">
          Anulado{{ m.voided_by ? ` por ${m.voided_by}` : '' }}: «{{ m.void_reason }}»
        </div>
      </div>
    </div>

    <!-- CAJA RECTIFICADA: cierres anteriores -->
    <div v-if="closings.length" class="corrections" role="note">
      <div class="divider">--------------------------------</div>
      <div class="section-title">CAJA RECTIFICADA {{ closings.length === 1 ? '1 VEZ' : `${closings.length} VECES` }}</div>
      <div v-for="(c, i) in closings" :key="i" class="correction-item">
        <div class="row mini">
          <span>Cierre {{ formatDate(c.closed_at) }}</span>
          <span>{{ signed(c.difference) }}</span>
        </div>
        <div class="row mini"><span>Contado / teórico:</span><span>Bs {{ money(c.actual_closing) }} / {{ money(c.expected_closing) }}</span></div>
        <div class="correction-text">Reabierta {{ formatDate(c.reopened_at) }}{{ c.reopened_by ? ` por ${c.reopened_by}` : '' }}: «{{ c.reopen_reason }}»</div>
        <div v-if="c.reclosed_at" class="correction-text">Cerrada otra vez{{ c.reclosed_by ? ` por ${c.reclosed_by}` : '' }}: «{{ c.reclose_reason }}»</div>
      </div>
      <p v-if="chainWarning" class="changed-text">
        La caja siguiente (#{{ chainWarning.next_id }}) abrió con Bs {{ money(chainWarning.next_opening_amount) }};
        este cierre dejó Bs {{ money(chainWarning.left_for_next) }} para mañana.
      </p>
    </div>

    <!-- CAMBIOS DESPUÉS DEL CIERRE -->
    <div v-if="recalc" class="changed-after-close" role="note">
      <div class="divider">--------------------------------</div>
      <div class="section-title">CAMBIOS DESPUÉS DEL CIERRE</div>
      <p class="changed-text">
        Las ventas o egresos de esta caja cambiaron después de cerrarla. El cierre guardado no se modifica;
        la diferencia de hoy es la que daría con las cifras actuales.
      </p>
      <div v-for="(c, i) in recalc.changes" :key="i" class="changed-item">
        <span>{{ formatDate(c.at) }}{{ c.user ? ` · ${c.user}` : '' }}</span>
        <span>{{ c.description }}</span>
      </div>
    </div>

    <!-- DISTRIBUCIÓN DEL EFECTIVO (SI APLICA) -->
    <template v-if="reportData.cash_withdrawn !== undefined || session.cash_withdrawn !== undefined">
      <div class="divider">--------------------------------</div>
      <div class="section-title">DISTRIBUCIÓN DEL EFECTIVO</div>
      <div class="dist-section">
        <div class="row">
          <span>Dejado para mañana:</span>
          <strong>Bs {{ Number(reportData.cash_left_for_next || session.cash_left_for_next || 0).toFixed(2) }}</strong>
        </div>
        <div class="row">
          <span>Efectivo retirado:</span>
          <strong>Bs {{ Number(reportData.cash_withdrawn || session.cash_withdrawn || 0).toFixed(2) }}</strong>
        </div>
      </div>
    </template>

    <!-- DESGLOSE DE BILLETES SI EXISTE -->
    <template v-if="reportData.bill_breakdown || session.bill_breakdown">
      <div class="divider">--------------------------------</div>
      <div class="section-title">DESGLOSE DE EFECTIVO</div>
      <div class="breakdown-grid">
        <template v-for="(qty, denom) in (reportData.bill_breakdown || session.bill_breakdown)" :key="denom">
          <div class="row mini" v-if="Number(qty) > 0">
            <span>Bs {{ denom }} x {{ qty }}:</span>
            <span>Bs {{ (Number(denom) * Number(qty)).toFixed(2) }}</span>
          </div>
        </template>
      </div>
    </template>

    <!-- NOTA EXPLICATIVA SI EXISTE -->
    <div class="diff-note" v-if="reportData.diff_note || session.diff_note">
      <div class="divider">--------------------------------</div>
      <div class="note-title">Nota del Cajero:</div>
      <p class="note-body">"{{ reportData.diff_note || session.diff_note }}"</p>
    </div>

    <!-- FIRMAS -->
    <div class="signatures">
      <div class="divider">================================</div>
      <div class="sig-box">
        <div class="sig-line">________________________________</div>
        <div class="sig-label">Firma del Cajero</div>
      </div>
      <div class="sig-box">
        <div class="sig-line">________________________________</div>
        <div class="sig-label">Firma del Supervisor</div>
      </div>
      <div class="ticket-center footer-msg">
        <small>Ohana Acai POS · Sistema de Gestión</small>
      </div>
    </div>
  </div>
</template>

<style scoped>
.report-ticket {
  width: 76mm;
  max-width: 100%;
  background: white;
  color: black;
  font-family: 'JetBrains Mono', 'Courier New', Courier, monospace;
  font-size: 11.5px;
  line-height: 1.35;
  padding: 16px 12px;
  border-radius: 8px;
  box-shadow: 0 4px 14px rgba(0,0,0,0.12);
  margin: 0 auto;
}

.ticket-center {
  text-align: center;
}

.brand-title {
  margin: 0;
  font-size: 18px;
  font-weight: 800;
  letter-spacing: 0.06em;
}

.report-type {
  font-size: 11px;
  font-weight: 700;
  margin-top: 2px;
}

.divider {
  margin: 6px 0;
  text-align: center;
  font-size: 10px;
  overflow: hidden;
  white-space: nowrap;
}

.section-title {
  font-weight: 800;
  font-size: 11px;
  text-align: center;
  margin-bottom: 4px;
}

.row {
  display: flex;
  justify-content: space-between;
  margin-bottom: 3px;
}

.row.mini {
  font-size: 10.5px;
}

.row.total-row {
  margin-top: 4px;
  padding-top: 4px;
  border-top: 1px dashed black;
  font-size: 13px;
}

.row.highlight-row {
  font-size: 12px;
}

.row.diff-row {
  margin-top: 4px;
  padding-top: 4px;
  border-top: 1px solid black;
  font-size: 12.5px;
}

.text-danger {
  color: #b71c1c;
  font-weight: 800;
}

.text-success {
  color: #1b5e20;
  font-weight: 800;
}

.mono {
  font-family: monospace;
  font-size: 10px;
}

.diff-note {
  margin-top: 6px;
}

.row.diff-row--today {
  border-top: 1px dashed black;
}

.changed-text {
  margin: 0 0 4px;
  font-size: 10.5px;
}

.correction-item { margin-bottom: 6px; }
.discount-item { margin-bottom: 5px; }
.discount-item .nowrap { white-space: nowrap; padding-left: 6px; }
.discount-item .correction-text { overflow-wrap: anywhere; }
.correction-text { font-size: 10px; margin-top: 1px; }

.changed-item {
  display: flex;
  flex-direction: column;
  font-size: 10px;
  margin-bottom: 3px;
}

.note-title {
  font-weight: 700;
  font-size: 11px;
}

.note-body {
  margin: 2px 0 0;
  font-style: italic;
  font-size: 10.5px;
}

.signatures {
  margin-top: 12px;
}

.sig-box {
  margin-top: 16px;
  text-align: center;
}

.sig-line {
  font-size: 10px;
}

.sig-label {
  font-size: 10px;
  font-weight: 700;
  margin-top: 2px;
}

.footer-msg {
  margin-top: 16px;
  font-size: 9.5px;
  color: #666;
}

/* ================= PRINT MEDIA ================= */
@media print {
  body * {
    visibility: hidden;
  }
  .report-ticket, .report-ticket * {
    visibility: visible;
  }
  .report-ticket {
    position: absolute;
    left: 0;
    top: 0;
    width: 80mm;
    box-shadow: none;
    padding: 4px 0;
    margin: 0;
  }
}
</style>
