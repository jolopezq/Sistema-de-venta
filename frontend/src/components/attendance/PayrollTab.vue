<script setup>
/**
 * Asistencia → Sueldos (permiso «Asistencia y sueldos»; docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.6).
 * Planilla del mes o de una quincena con las marcas válidas; al aprobar se crea un gasto en Finanzas por
 * bruto − adelantos + bonos (los adelantos ya están en Finanzas como egresos de caja).
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { apiFetch } from '../../services/api';
import { formatMinutes, payrollRange, payModeText } from '../../utils/attendance';

const props = defineProps({ canEdit: { type: Boolean, default: false } });
const emit = defineEmits(['toast', 'navigate']);

const MONTHS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const PARTS = [
  { id: 'month', label: 'Mes' },
  { id: 'first', label: '1.ª quincena' },
  { id: 'second', label: '2.ª quincena' },
];
const METHODS = [
  { id: 'cash', label: 'Efectivo' },
  { id: 'qr', label: 'QR' },
  { id: 'bank_transfer', label: 'Transferencia' },
];

// Por defecto, el mes anterior (el que se paga)
const now = new Date();
const start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
const year = ref(start.getFullYear());
const month = ref(start.getMonth() + 1);
const part = ref('month');
const data = ref(null);
const loading = ref(false);
const error = ref('');
const selected = ref(null);
const others = ref({});
const paymentMethod = ref('cash');
const busy = ref(false);
const voiding = ref(false);
const voidReason = ref('');
const approvedDetail = ref(null);
const printing = ref(false);

const range = computed(() => payrollRange(year.value, month.value, part.value));
const periodLabel = computed(() => {
  const base = `${MONTHS[month.value - 1]} ${year.value}`;
  return part.value === 'month' ? base : `${PARTS.find((p) => p.id === part.value).label} de ${base.toLowerCase()}`;
});
const approved = computed(() => data.value?.approved);
const lines = computed(() => {
  if (approved.value && approvedDetail.value) return approvedDetail.value.lines;
  return (data.value?.lines ?? []).map((l) => {
    const o = others.value[l.user_id] ?? { amount: '', reason: '' };
    const extra = Number(o.amount) || 0;
    return { ...l, others: extra, net: Math.round((l.gross - l.advances + extra) * 100) / 100 };
  });
});
const totals = computed(() => {
  const sum = (k) => Math.round(lines.value.reduce((acc, l) => acc + Number(l[k] || 0), 0) * 100) / 100;
  return { gross: sum('gross'), advances: sum('advances'), others: sum('others'), net: sum('net') };
});
const expenseAmount = computed(() => Math.round((totals.value.gross - totals.value.advances + totals.value.others) * 100) / 100);
const blocked = computed(() => (data.value?.blockers?.total ?? 0) > 0);
const periodEnded = computed(() => range.value.to <= isoToday());
const othersInvalid = computed(() => Object.values(others.value).some((o) => Number(o.amount) && (o.reason || '').trim().length < 5));
const canApprove = computed(() => props.canEdit && !approved.value && !blocked.value && periodEnded.value && lines.value.length > 0 && !othersInvalid.value && !busy.value);
const selectedLine = computed(() => lines.value.find((l) => l.user_id === selected.value) ?? lines.value[0] ?? null);

function isoToday() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
const money = (n) => Number(n || 0).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const dateText = (iso) => (iso ? iso.split('-').reverse().join('/') : '');

async function load() {
  loading.value = true;
  error.value = '';
  approvedDetail.value = null;
  try {
    const q = new URLSearchParams(range.value);
    data.value = await apiFetch(`/attendance/payroll?${q}`);
    if (data.value.approved) {
      approvedDetail.value = await apiFetch(`/attendance/payroll/periods/${data.value.approved.id}`);
    }
    others.value = Object.fromEntries((data.value.lines || []).map((l) => [l.user_id, { amount: '', reason: '' }]));
    if (!lines.value.some((l) => l.user_id === selected.value)) selected.value = lines.value[0]?.user_id ?? null;
  } catch (err) {
    error.value = err.message;
  } finally {
    loading.value = false;
  }
}

function shiftMonth(delta) {
  const d = new Date(year.value, month.value - 1 + delta, 1);
  year.value = d.getFullYear();
  month.value = d.getMonth() + 1;
  load();
}

function setPart(p) {
  part.value = p;
  load();
}

async function approve() {
  busy.value = true;
  try {
    await apiFetch('/attendance/payroll/approve', {
      method: 'POST',
      body: JSON.stringify({
        ...range.value,
        payment_method: paymentMethod.value,
        others: Object.entries(others.value)
          .filter(([, o]) => Number(o.amount))
          .map(([userId, o]) => ({ user_id: Number(userId), amount: Number(o.amount), reason: o.reason })),
      }),
    });
    emit('toast', `Planilla aprobada. El gasto de Bs ${money(expenseAmount.value)} ya está en Finanzas.`);
    await load();
  } catch (err) {
    emit('toast', err.validationErrors ? Object.values(err.validationErrors).flat()[0] : err.message, 'error');
  } finally {
    busy.value = false;
  }
}

async function voidPeriod() {
  busy.value = true;
  try {
    await apiFetch(`/attendance/payroll/periods/${approved.value.id}/void`, { method: 'POST', body: JSON.stringify({ reason: voidReason.value }) });
    emit('toast', 'Planilla anulada. El gasto de Finanzas se quitó y se puede volver a aprobar.');
    voiding.value = false;
    voidReason.value = '';
    await load();
  } catch (err) {
    emit('toast', err.message, 'error');
  } finally {
    busy.value = false;
  }
}

function printReceipts() {
  printing.value = true;
  document.documentElement.classList.add('printing-receipts');
  setTimeout(() => window.print(), 50);
}
function afterPrint() {
  printing.value = false;
  document.documentElement.classList.remove('printing-receipts');
}

onMounted(() => {
  load();
  window.addEventListener('afterprint', afterPrint);
});
onBeforeUnmount(() => {
  window.removeEventListener('afterprint', afterPrint);
  afterPrint();
});
</script>

<template>
  <div class="pay">
    <div class="pay-bar">
      <div class="fin-period" role="group" aria-label="Período de la planilla">
        <button class="fin-period-btn" type="button" aria-label="Mes anterior" @click="shiftMonth(-1)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="m15 18-6-6 6-6" /></svg>
        </button>
        <span class="fin-period-label" aria-live="polite">{{ periodLabel }}</span>
        <button class="fin-period-btn" type="button" aria-label="Mes siguiente" @click="shiftMonth(1)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="m9 18 6-6-6-6" /></svg>
        </button>
      </div>
      <div class="fin-chips" role="group" aria-label="Parte del mes">
        <button v-for="p in PARTS" :key="p.id" type="button" class="fin-chip" :class="{ active: part === p.id }" :aria-pressed="part === p.id" @click="setPart(p.id)">{{ p.label }}</button>
      </div>
      <span class="fin-badge" :class="approved ? 'ok' : ''">{{ approved ? 'Aprobada' : 'Borrador' }}</span>
      <span class="fin-muted fin-small">{{ dateText(range.from) }} al {{ dateText(range.to) }}</span>
    </div>

    <p v-if="loading" class="fin-muted">Cargando…</p>
    <p v-else-if="error" class="fin-error">{{ error }}</p>
    <template v-else-if="data">
      <div v-if="approved" class="fin-note pay-ok" role="status">
        <span>
          <strong>Planilla aprobada</strong> por {{ approved.approved_by }} el {{ approved.approved_at }}.
          Neto Bs {{ money(approved.net_total) }}<template v-if="approved.expense_id">; el gasto ya está en Finanzas → Compras y gastos, «Salarios y personal»</template>.
        </span>
        <span v-if="canEdit && !voiding" class="pay-row">
          <button type="button" class="fin-btn fin-btn-ghost fin-btn-sm" @click="voiding = true">Anular planilla</button>
        </span>
      </div>
      <div v-if="voiding" class="fin-card fin-card-body pay-void">
        <label for="pay-void" class="fin-label">Motivo para anular (el gasto de Finanzas se quita y la planilla se puede rehacer)</label>
        <input id="pay-void" v-model="voidReason" class="fin-input" maxlength="255" placeholder="Ej.: faltó un bono; había una marca mal corregida" />
        <div class="pay-row">
          <button type="button" class="fin-btn fin-btn-danger" :disabled="busy || voidReason.trim().length < 10" @click="voidPeriod">Anular</button>
          <button type="button" class="fin-btn fin-btn-ghost" @click="voiding = false">Cancelar</button>
        </div>
      </div>

      <div v-if="!approved && blocked" class="fin-note err pay-row">
        <span>
          <strong>Quedan {{ data.blockers.total }} marcas por revisar en el período</strong>
          ({{ data.blockers.pending }} pendientes y {{ data.blockers.without_exit }} sin salida). Revísalas para poder aprobar.
        </span>
        <button type="button" class="fin-btn fin-btn-primary fin-btn-sm" @click="emit('navigate', 'review')">Ir a «A revisar»</button>
      </div>
      <p v-for="w in data.warnings" :key="w.code + w.message" class="fin-note">{{ w.message }}</p>

      <div class="fin-card">
        <div class="fin-table-wrap">
          <table class="fin-table pay-table">
            <thead>
              <tr>
                <th scope="col">Persona</th>
                <th scope="col" class="right">Días</th>
                <th scope="col" class="right">Horas</th>
                <th scope="col" class="right">Extra</th>
                <th scope="col" class="right">Atrasos</th>
                <th scope="col" class="right">Faltas</th>
                <th scope="col" class="right">Bruto (Bs)</th>
                <th scope="col" class="right">Adelantos (Bs)</th>
                <th scope="col">Bono o descuento</th>
                <th scope="col" class="right">Neto (Bs)</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in lines" :key="l.user_id" :class="{ 'pay-selected': selectedLine?.user_id === l.user_id }">
                <td>
                  <button type="button" class="pay-person" :aria-expanded="selectedLine?.user_id === l.user_id" @click="selected = l.user_id">
                    {{ l.name }} <span class="pay-see">{{ selectedLine?.user_id === l.user_id ? '▾' : 'ver días' }}</span>
                  </button>
                  <div class="fin-small fin-muted">{{ payModeText(l.pay_mode, l.rate) }}</div>
                </td>
                <td class="right pay-mono">{{ l.days_worked }}</td>
                <td class="right pay-mono">{{ formatMinutes(l.normal_minutes) }}</td>
                <td class="right pay-mono">{{ formatMinutes(l.overtime_minutes) }}</td>
                <td class="right pay-mono">{{ formatMinutes(l.late_minutes) }}</td>
                <td class="right pay-mono">{{ l.absences }}</td>
                <td class="right pay-mono">{{ money(l.gross) }}</td>
                <td class="right pay-mono">{{ money(l.advances) }}</td>
                <td>
                  <template v-if="!approved && canEdit">
                    <div class="pay-other">
                      <label class="sr-only" :for="`pay-o-${l.user_id}`">Bono (+) o descuento (−) de {{ l.name }}</label>
                      <input :id="`pay-o-${l.user_id}`" v-model="others[l.user_id].amount" type="number" step="0.01" class="fin-input fin-input-sm pay-mono" placeholder="0,00" />
                      <label class="sr-only" :for="`pay-r-${l.user_id}`">Motivo</label>
                      <input v-if="Number(others[l.user_id].amount)" :id="`pay-r-${l.user_id}`" v-model="others[l.user_id].reason" class="fin-input fin-input-sm" placeholder="Motivo" maxlength="255" />
                    </div>
                  </template>
                  <span v-else class="pay-mono">{{ l.others ? money(l.others) : '—' }}<span v-if="l.others_reason" class="fin-muted fin-small"> · {{ l.others_reason }}</span></span>
                </td>
                <td class="right pay-mono pay-net">{{ money(l.net) }}</td>
              </tr>
              <tr v-if="lines.length === 0"><td colspan="10" class="fin-muted">Nadie marca asistencia en este período.</td></tr>
            </tbody>
            <tfoot v-if="lines.length">
              <tr>
                <th scope="row" colspan="6">Total</th>
                <td class="right pay-mono"><strong>{{ money(totals.gross) }}</strong></td>
                <td class="right pay-mono"><strong>{{ money(totals.advances) }}</strong></td>
                <td class="pay-mono"><strong>{{ totals.others ? money(totals.others) : '—' }}</strong></td>
                <td class="right pay-mono pay-net"><strong>{{ money(totals.net) }}</strong></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <div v-if="selectedLine" class="fin-card">
        <div class="fin-card-head">
          <div>
            <h2>{{ selectedLine.name }} · días del período</h2>
            <p v-if="selectedLine.breakdown">
              Base Bs {{ money(selectedLine.breakdown.base) }}
              <template v-if="selectedLine.breakdown.overtime"> + extra Bs {{ money(selectedLine.breakdown.overtime) }}</template>
              <template v-if="selectedLine.breakdown.sunday"> + domingo Bs {{ money(selectedLine.breakdown.sunday) }}</template>
              <template v-if="selectedLine.breakdown.late_deduction"> − atrasos Bs {{ money(selectedLine.breakdown.late_deduction) }}</template>
              <template v-if="selectedLine.breakdown.absence_deduction"> − faltas Bs {{ money(selectedLine.breakdown.absence_deduction) }}</template>
              · valor de la hora Bs {{ money(selectedLine.breakdown.hour_value) }}
            </p>
          </div>
        </div>
        <div class="fin-card-body">
          <div v-if="selectedLine.days?.length" class="pay-days">
            <div v-for="d in selectedLine.days" :key="d.date" class="pay-day" :class="{ absent: d.notes?.some(n => n.startsWith('Falta')) }">
              <span class="pay-day-label">{{ d.label }}</span>
              <span v-for="r in d.ranges" :key="r" class="pay-mono">{{ r }}</span>
              <span class="fin-small fin-muted">{{ d.minutes ? formatMinutes(d.minutes) : '' }}</span>
              <span v-for="n in d.notes" :key="n" class="fin-small pay-note">{{ n }}</span>
            </div>
          </div>
          <p v-else class="fin-muted">Sin marcas en el período.</p>
        </div>
      </div>

      <div class="pay-foot">
        <p class="fin-hint pay-explain">
          Al aprobar se crea <strong>un gasto de Bs {{ money(expenseAmount) }}</strong> en «Salarios y personal»: el bruto de Bs {{ money(totals.gross) }}
          menos Bs {{ money(totals.advances) }} de adelantos, que ya están en Finanzas como egresos de caja<template v-if="totals.others">, más Bs {{ money(totals.others) }} de bonos y descuentos</template>.
          La planilla no calcula aportes, aguinaldo ni finiquito.
        </p>
        <div v-if="!approved && canEdit" class="fin-field pay-method">
          <label for="pay-method">Se paga con</label>
          <select id="pay-method" v-model="paymentMethod" class="fin-input">
            <option v-for="m in METHODS" :key="m.id" :value="m.id">{{ m.label }}</option>
          </select>
        </div>
        <button type="button" class="fin-btn fin-btn-ghost fin-btn-lg" :disabled="!lines.length" @click="printReceipts">Imprimir recibos</button>
        <button v-if="!approved && canEdit" type="button" class="fin-btn fin-btn-primary fin-btn-lg" :disabled="!canApprove" @click="approve">
          {{ busy ? 'Aprobando…' : 'Aprobar planilla' }}
        </button>
      </div>
      <p v-if="!approved && canEdit && !periodEnded" class="fin-hint">El período todavía no terminó: se puede revisar, pero se aprueba desde el día siguiente al último.</p>
      <p v-if="othersInvalid" class="fin-field-err">Cada bono o descuento necesita un motivo.</p>
    </template>

    <!-- Recibos para imprimir (solo se ven al imprimir) -->
    <section v-if="printing" class="payroll-receipts" aria-hidden="true">
      <article v-for="l in lines" :key="`r-${l.user_id}`" class="receipt">
        <h2>Ohana Açaí · Recibo de sueldo</h2>
        <p><strong>{{ l.name }}</strong> · {{ periodLabel }} ({{ dateText(range.from) }} al {{ dateText(range.to) }})</p>
        <p>{{ payModeText(l.pay_mode, l.rate) }} · {{ l.days_worked }} días · {{ formatMinutes(l.normal_minutes) }} normales · {{ formatMinutes(l.overtime_minutes) }} extra</p>
        <table>
          <tbody>
            <tr><td>Bruto</td><td>Bs {{ money(l.gross) }}</td></tr>
            <tr><td>Adelantos</td><td>− Bs {{ money(l.advances) }}</td></tr>
            <tr v-if="l.others"><td>Bonos o descuentos{{ l.others_reason ? ` (${l.others_reason})` : '' }}</td><td>Bs {{ money(l.others) }}</td></tr>
            <tr class="net"><td>Neto a pagar</td><td>Bs {{ money(l.net) }}</td></tr>
          </tbody>
        </table>
        <div class="sign"><span>Firma de quien recibe</span><span>Firma de quien paga</span></div>
      </article>
    </section>
  </div>
</template>

<style scoped>
.pay { display: flex; flex-direction: column; gap: 14px; }
.pay-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.pay-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.pay-ok { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; background: var(--lime-100); color: var(--lime-700); }
.pay-void { display: flex; flex-direction: column; gap: 8px; }
.pay-table td, .pay-table th { white-space: nowrap; padding-left: 10px; padding-right: 10px; }
.pay-table td:first-child, .pay-table th:first-child { padding-left: 14px; }
.pay-mono { font-family: var(--font-mono); }
.pay-net { font-weight: 700; color: var(--passion-text); }
.pay-person { border: none; background: transparent; padding: 4px 0; font: inherit; font-weight: 700; color: var(--ink-900); cursor: pointer; display: inline-flex; gap: 8px; align-items: center; min-height: 32px; }
.pay-see { color: var(--passion-text); font-size: 12px; font-weight: 600; }
.pay-selected td { background: var(--passion-50); }
.pay-other { display: flex; flex-direction: column; gap: 4px; }
.pay-other input { width: 120px; }
.pay-days { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; }
.pay-day { display: flex; flex-direction: column; gap: 2px; padding: 8px 10px; border-radius: 10px; background: var(--cream-100); border: 1px solid var(--border); font-size: 13px; }
.pay-day.absent { background: var(--danger-100); border-color: transparent; }
.pay-day-label { font-weight: 700; color: var(--ink-700); }
.pay-note { color: var(--acai-text); font-weight: 600; }
.pay-foot { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.pay-explain { flex: 1 1 380px; margin: 0; line-height: 1.5; font-size: 13px; }
.pay-method { width: 170px; }
.payroll-receipts { display: none; }
html.dark .pay-selected td { background: rgba(251, 120, 16, 0.08); }
html.dark .pay-ok { background: rgba(60, 174, 73, 0.16); color: #9BE3A4; }
</style>

<style>
/* Imprimir recibos: solo se imprime la sección de recibos */
@media print {
  html.printing-receipts body * { visibility: hidden !important; }
  html.printing-receipts .payroll-receipts,
  html.printing-receipts .payroll-receipts * { visibility: visible !important; }
  html.printing-receipts .payroll-receipts { display: block !important; position: absolute; left: 0; top: 0; width: 100%; color: #000; background: #fff; font-family: Inter, system-ui, sans-serif; }
  html.printing-receipts .receipt { break-inside: avoid; border: 1px solid #000; border-radius: 6px; padding: 14px 18px; margin: 0 0 16px; font-size: 12pt; }
  html.printing-receipts .receipt h2 { margin: 0 0 6px; font-size: 14pt; }
  html.printing-receipts .receipt p { margin: 2px 0; }
  html.printing-receipts .receipt table { width: 100%; border-collapse: collapse; margin: 8px 0; }
  html.printing-receipts .receipt td { padding: 4px 0; border-bottom: 1px dashed #999; }
  html.printing-receipts .receipt td:last-child { text-align: right; }
  html.printing-receipts .receipt tr.net td { font-weight: 700; border-bottom: none; }
  html.printing-receipts .receipt .sign { display: flex; justify-content: space-between; gap: 40px; margin-top: 36px; }
  html.printing-receipts .receipt .sign span { flex: 1; border-top: 1px solid #000; padding-top: 4px; font-size: 10pt; text-align: center; }
}
</style>
