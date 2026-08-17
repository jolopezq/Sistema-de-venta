<script setup>
import { computed } from 'vue';

const props = defineProps({
  report: {
    type: Object,
    required: true,
  },
});

const reportData = computed(() => props.report || {});
const session = computed(() => reportData.value.session || {});
const summary = computed(() => reportData.value.summary || {});

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
    <div class="section-title">VENTAS DEL TURNO</div>
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
        <span>TOTAL FACTURADO:</span>
        <strong class="total-amt">Bs {{ Number(summary.total_sales || 0).toFixed(2) }}</strong>
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
      <div class="row highlight-row">
        <span>(=) Efectivo Teórico:</span>
        <strong>Bs {{ Number(reportData.expected_closing || 0).toFixed(2) }}</strong>
      </div>
      <div class="row highlight-row">
        <span>Efectivo Contado:</span>
        <strong>Bs {{ Number(reportData.actual_closing || 0).toFixed(2) }}</strong>
      </div>
      <div class="row diff-row">
        <span>DIFERENCIA:</span>
        <strong :class="Number(reportData.difference) < 0 ? 'text-danger' : 'text-success'">
          {{ Number(reportData.difference) > 0 ? '+' : '' }} Bs {{ Number(reportData.difference || 0).toFixed(2) }}
          ({{ Number(reportData.difference) === 0 ? 'Exacto' : Number(reportData.difference) > 0 ? 'Sobrante' : 'Faltante' }})
        </strong>
      </div>
    </div>

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
