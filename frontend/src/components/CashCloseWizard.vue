<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useTurnoStore, readCashCloseProgress, CASH_CLOSE_PROGRESS_KEY } from '../stores/turno';
import { useAuthStore } from '../stores/auth';
import { useNetworkStore } from '../stores/network';
import CashSessionReport from './CashSessionReport.vue';
import SyncIssuesPanel from './SyncIssuesPanel.vue';

const emit = defineEmits(['close', 'closed']);
const turnoStore = useTurnoStore();
const authStore = useAuthStore();
const network = useNetworkStore();

/**
 * Props opcionales para sesión específica (histórica).
 * Si no se pasan, el wizard opera sobre la sesión activa del store.
 */
const props = defineProps({
  session: { type: Object, default: null },
  stats:   { type: Object, default: null },
});

// Cifras traídas por el propio asistente (revisión previa o respuesta 409 del cierre): mandan sobre las de afuera
const freshStats = ref(null);

// Resolución de sesión y stats: usar props si existen, sino caer al store
const resolvedSession = computed(() => props.session || turnoStore.activeSession);
const resolvedStats   = computed(() => freshStats.value || props.stats || turnoStore.sessionStats);

const isAdmin = computed(() => ['admin', 'super_admin'].includes(authStore.user?.role));

// 0: Revisión previa, 1: Conteo, 2: Resumen/Arqueo, 3: Reporte
const step = ref(0);
const isSubmitting = ref(false);
const errorMessage = ref('');
const noticeMessage = ref('');

// ---------- Paso 0: revisión previa ----------
// Antes de contar: enviar las ventas del dispositivo y traer las cifras al día. Una venta sin enviar
// tiene su efectivo en la gaveta pero no en el esperado, y haría aparecer un sobrante falso.
const reviewing = ref(false);
const reviewError = ref('');
const reviewedAt = ref(null);
const forceReason = ref('');
let resumeStep = 1;

const unsyncedCount = computed(() => network.unsyncedCount);
const reviewBlocked = computed(() => {
  if (reviewing.value || reviewError.value) return true;
  if (unsyncedCount.value === 0) return false;
  return !isAdmin.value || forceReason.value.trim().length < 5;
});

async function refreshStats() {
  const sessionId = resolvedSession.value?.id;
  if (props.session) {
    const data = await turnoStore.fetchSessionShow(sessionId);
    if (!data?.stats) throw new Error('No se pudieron traer las cifras de la caja.');
    freshStats.value = data.stats;
  } else {
    await turnoStore.fetchActiveSession();
    if (turnoStore.error || !turnoStore.sessionStats) throw new Error('No se pudieron traer las cifras de la caja.');
    freshStats.value = turnoStore.sessionStats;
  }
}

async function runReview({ autoAdvance = true } = {}) {
  reviewing.value = true;
  reviewError.value = '';
  try {
    if (!network.isOnline) {
      reviewError.value = 'Necesitas conexión para cerrar la caja: el cierre se guarda en el servidor.';
      return;
    }
    await network.syncNow();
    await refreshStats();
    await network.updatePendingCount();
    reviewedAt.value = new Date();
    // Todo enviado: se pasa directo al conteo (o a donde se había quedado)
    if (autoAdvance && unsyncedCount.value === 0) step.value = resumeStep;
  } catch (err) {
    reviewError.value = err.message || 'No se pudo revisar la caja.';
  } finally {
    reviewing.value = false;
  }
}

function continueFromReview() {
  if (reviewBlocked.value) return;
  step.value = resumeStep;
}

const reviewedTime = computed(() => reviewedAt.value
  ? reviewedAt.value.toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit' })
  : '');

// ---------- Paso 1: conteo ----------
// Conteo por denominación (Bolivianos)
const billCounts = ref({
  200: 0,
  100: 0,
  50: 0,
  20: 0,
  10: 0,
  5: 0,
  2: 0,
  1: 0,
  '0.50': 0,
});

const manualTotalOverride = ref(null);
const useManualTotal = ref(false);
const diffNote = ref('');

// Distribución del efectivo al cierre
const cashLeftForNext = ref(0);  // Fondo de cambio para el siguiente turno
const cashWithdrawn = computed(() => {  // Calculado: counted - left_for_next
  const withdrawn = countedTotal.value - cashLeftForNext.value;
  return Math.max(0, Math.round(withdrawn * 100) / 100);
});

const distributionError = computed(() => {
  const left = Number(cashLeftForNext.value || 0);
  if (left < 0) return 'El fondo para mañana no puede ser negativo.';
  if (left > countedTotal.value) return 'El fondo para mañana no puede ser mayor al efectivo contado.';
  return '';
});

const denominations = [
  { label: 'Bs 200', value: 200, type: 'billete', color: '#1B5E20' },
  { label: 'Bs 100', value: 100, type: 'billete', color: '#B71C1C' },
  { label: 'Bs 50',  value: 50,  type: 'billete', color: '#E65100' },
  { label: 'Bs 20',  value: 20,  type: 'billete', color: '#F57F17' },
  { label: 'Bs 10',  value: 10,  type: 'billete', color: '#006064' },
  { label: 'Bs 5',   value: 5,   type: 'moneda',  color: '#4E342E' },
  { label: 'Bs 2',   value: 2,   type: 'moneda',  color: '#546E7A' },
  { label: 'Bs 1',   value: 1,   type: 'moneda',  color: '#546E7A' },
  { label: 'Bs 0.50', value: 0.5, type: 'moneda', color: '#78909C' },
];

// Cálculo automático de la suma de denominaciones
const calculatedTotal = computed(() => {
  return denominations.reduce((sum, d) => {
    const key = d.value === 0.5 ? '0.50' : String(d.value);
    const qty = Number(billCounts.value[key] || 0);
    return sum + (d.value * qty);
  }, 0);
});

// Total final contado (manual o por desglose)
const countedTotal = computed(() => {
  if (useManualTotal.value && manualTotalOverride.value !== null) {
    return Number(manualTotalOverride.value || 0);
  }
  return calculatedTotal.value;
});

// ---------- Paso 2: arqueo ----------
// Solo el efectivo entra a la gaveta: fondo + ventas en efectivo + ingresos en efectivo − egresos en efectivo.
// Los cobros y egresos con QR o tarjeta se muestran aparte, porque no mueven billetes.
const openingAmount   = computed(() => Number(resolvedSession.value?.opening_amount || 0));
const cashSales       = computed(() => Number(resolvedStats.value?.cash_sales || 0));
const cashExtraIncome = computed(() => Number(resolvedStats.value?.cash_extra_income ?? 0));
const cashExpenses    = computed(() => Number(resolvedStats.value?.cash_expenses ?? resolvedStats.value?.total_expenses ?? 0));
const nonCashExpenses = computed(() => Number(resolvedStats.value?.non_cash_expenses ?? 0));
const expectedCash    = computed(() => {
  const fromServer = resolvedStats.value?.expected_cash;
  if (fromServer !== undefined && fromServer !== null) return Number(fromServer);
  return Math.round((openingAmount.value + cashSales.value + cashExtraIncome.value - cashExpenses.value) * 100) / 100;
});
const difference      = computed(() => Number((countedTotal.value - expectedCash.value).toFixed(2)));

// Validación de notas obligatorias si hay faltante y distribución válida
const canProceedToClose = computed(() => {
  if (distributionError.value) return false;
  if (difference.value < 0) {
    return diffNote.value.trim().length >= 5;
  }
  return true;
});

// Incremento / decremento rápido
function changeQty(denomKey, delta) {
  const current = Number(billCounts.value[denomKey] || 0);
  const next = Math.max(0, current + delta);
  billCounts.value[denomKey] = next;
  saveProgress();
}

// Guardar en localStorage para protección ante recargas; el avance pertenece a esta caja
function saveProgress() {
  const sessionId = resolvedSession.value?.id;
  if (!sessionId) return;
  const data = {
    sessionId,
    step: step.value,
    billCounts: billCounts.value,
    useManualTotal: useManualTotal.value,
    manualTotalOverride: manualTotalOverride.value,
    diffNote: diffNote.value,
    cashLeftForNext: cashLeftForNext.value,
  };
  localStorage.setItem(CASH_CLOSE_PROGRESS_KEY, JSON.stringify(data));
}

// Cargar progreso previo de esta misma caja (el de otra caja se ignora) y revisar antes de contar
onMounted(() => {
  const parsed = readCashCloseProgress(resolvedSession.value?.id);
  if (parsed) {
    if (parsed.billCounts) billCounts.value = { ...billCounts.value, ...parsed.billCounts };
    if (parsed.diffNote) diffNote.value = parsed.diffNote;
    if (parsed.useManualTotal) useManualTotal.value = parsed.useManualTotal;
    if (parsed.manualTotalOverride !== undefined) manualTotalOverride.value = parsed.manualTotalOverride;
    if (parsed.cashLeftForNext !== undefined) cashLeftForNext.value = Number(parsed.cashLeftForNext) || 0;
    if (parsed.step === 2) resumeStep = 2;
  }
  runReview();
});

watch(step, (value) => { if (value === 1 || value === 2) saveProgress(); });
watch(cashLeftForNext, () => saveProgress());

function nextStep() {
  errorMessage.value = '';
  if (step.value === 1) {
    if (countedTotal.value < 0) {
      errorMessage.value = 'El monto contado debe ser mayor o igual a 0.';
      return;
    }
    step.value = 2;
  }
}

function prevStep() {
  errorMessage.value = '';
  if (step.value === 2) {
    step.value = 1;
  }
}

async function handleConfirmClose() {
  if (!canProceedToClose.value) {
    errorMessage.value = distributionError.value ||
      'Por favor ingresa un motivo explicativo para el faltante.';
    return;
  }

  isSubmitting.value = true;
  errorMessage.value = '';
  noticeMessage.value = '';

  try {
    await network.updatePendingCount();
    const unsynced = network.unsyncedCount;
    if (unsynced > 0 && (!isAdmin.value || forceReason.value.trim().length < 5)) {
      // Entró una venta sin enviar mientras se contaba: se vuelve a la revisión
      step.value = 0;
      await runReview({ autoAdvance: false });
      return;
    }

    const payload = {
      actual_closing:     countedTotal.value,
      cash_withdrawn:     cashWithdrawn.value,
      cash_left_for_next: Number(cashLeftForNext.value || 0),
      bill_breakdown:     billCounts.value,
      diff_note:          diffNote.value.trim() || null,
      // El servidor recalcula al cerrar: si el esperado cambió, responde 409 con las cifras nuevas
      expected_seen:      expectedCash.value,
      unsynced_sales:     unsynced,
      force_reason:       unsynced > 0 ? forceReason.value.trim() : null,
    };

    // Usar el ID de la sesión resuelta (histórica o activa)
    const sessionId = resolvedSession.value?.id || null;
    const data = await turnoStore.closeSession(payload, sessionId);
    step.value = 3;
    emit('closed', data);
  } catch (err) {
    if (err.status === 409 && err.data?.stats) {
      const before = expectedCash.value;
      freshStats.value = err.data.stats;
      noticeMessage.value = `Las cifras cambiaron mientras contabas: el efectivo esperado pasó de Bs ${before.toFixed(2)} a Bs ${expectedCash.value.toFixed(2)}. Revisa la diferencia y confirma otra vez.`;
    } else {
      errorMessage.value = err.message || 'Error al cerrar caja.';
    }
  } finally {
    isSubmitting.value = false;
  }
}

function handlePrintReport() {
  window.print();
}

function handleFinish() {
  localStorage.removeItem(CASH_CLOSE_PROGRESS_KEY);
  emit('close');
}
</script>

<template>
  <div class="wizard-overlay" @click.self="step !== 3 ? emit('close') : null">
    <div class="wizard-modal">
      <!-- HEADER -->
      <div class="wizard-header">
        <div class="wizard-title-box">
          <div class="wizard-badge">Arqueo de Turno</div>
          <h2>Cierre de Caja</h2>
          <p class="cajero-sub">
            Cajero: <strong>{{ authStore.user?.name || resolvedSession?.cashier?.name || 'Cajero' }}</strong> · 
            Iniciado: <strong>{{ resolvedSession?.opened_at ? new Date(resolvedSession.opened_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '--:--' }}</strong>
          </p>
        </div>

        <!-- STEP INDICATOR -->
        <div class="steps-nav">
          <div class="step-dot" :class="{ active: step === 0, done: step > 0 }">
            <span>✓</span>
            <label>Revisión</label>
          </div>
          <div class="step-line" :class="{ done: step > 0 }"></div>
          <div class="step-dot" :class="{ active: step === 1, done: step > 1 }">
            <span>1</span>
            <label>Conteo</label>
          </div>
          <div class="step-line" :class="{ done: step > 1 }"></div>
          <div class="step-dot" :class="{ active: step === 2, done: step > 2 }">
            <span>2</span>
            <label>Resumen</label>
          </div>
          <div class="step-line" :class="{ done: step > 2 }"></div>
          <div class="step-dot" :class="{ active: step === 3 }">
            <span>3</span>
            <label>Reporte</label>
          </div>
        </div>

        <button v-if="step !== 3" class="close-btn" @click="emit('close')">✕</button>
      </div>

      <!-- BODY -->
      <div class="wizard-body">
        <!-- ERROR MESSAGE -->
        <div v-if="errorMessage" class="error-banner">
          ⚠️ {{ errorMessage }}
        </div>
        <div v-if="noticeMessage" class="notice-banner" role="status">
          {{ noticeMessage }}
        </div>

        <!-- ================= PASO 0: REVISIÓN PREVIA ================= -->
        <div v-if="step === 0" class="step-content">
          <div class="step-intro">
            <h3>Antes de contar</h3>
            <p>Se envían las ventas de este dispositivo y se traen las cifras de la caja al día.</p>
          </div>

          <div v-if="reviewing" class="review-row checking">
            Revisando ventas pendientes y cifras de la caja…
          </div>

          <template v-else>
            <div v-if="reviewError" class="review-row blocked">{{ reviewError }}</div>

            <template v-else>
              <div class="review-row ok">
                Cifras de la caja al día{{ reviewedTime ? ` (${reviewedTime})` : '' }}.
              </div>
              <div v-if="unsyncedCount === 0" class="review-row ok">
                Todas las ventas de este dispositivo están en el servidor.
              </div>
            </template>

            <SyncIssuesPanel />

            <template v-if="unsyncedCount > 0 && !reviewError">
              <p v-if="!isAdmin" class="review-help">
                No se puede cerrar hasta que estas ventas lleguen al servidor.
              </p>
              <div v-else class="force-box">
                <label for="force-reason"><strong>Cerrar igual (administrador)</strong></label>
                <p class="review-help">
                  El arqueo no incluirá estas ventas. El motivo queda en la nota del cierre y en la auditoría.
                </p>
                <textarea
                  id="force-reason"
                  v-model="forceReason"
                  rows="2"
                  class="diff-textarea"
                  placeholder="Ej.: venta rechazada por un producto dado de baja; se revisa mañana"
                ></textarea>
                <small v-if="forceReason.trim().length < 5" class="text-danger">Escribe al menos 5 caracteres.</small>
              </div>
            </template>
          </template>
        </div>

        <!-- ================= PASO 1: CONTEO FÍSICO ================= -->
        <div v-if="step === 1" class="step-content">
          <div class="step-intro">
            <h3>Paso 1: Conteo físico de dinero en gaveta</h3>
            <p>Ingresa la cantidad de billetes y monedas que tienes en la caja registradora.</p>
            <p v-if="reviewedTime && unsyncedCount === 0" class="review-ok-note">
              Revisado a las {{ reviewedTime }}: todas las ventas enviadas y cifras al día.
            </p>
          </div>

          <!-- TABLA DE DENOMINACIONES -->
          <div class="denoms-grid">
            <div 
              v-for="d in denominations" 
              :key="d.label" 
              class="denom-card"
            >
              <div class="denom-info">
                <span class="denom-badge" :style="{ borderColor: d.color, color: d.color }">
                  {{ d.label }}
                </span>
                <span class="denom-type">{{ d.type }}</span>
              </div>

              <div class="denom-counter">
                <button type="button" class="btn-step" @click="changeQty(d.value === 0.5 ? '0.50' : String(d.value), -1)">−</button>
                <input 
                  type="number" 
                  min="0"
                  v-model.number="billCounts[d.value === 0.5 ? '0.50' : String(d.value)]"
                  class="denom-input"
                  @input="saveProgress"
                />
                <button type="button" class="btn-step" @click="changeQty(d.value === 0.5 ? '0.50' : String(d.value), 1)">+</button>
              </div>

              <div class="denom-subtotal">
                Bs {{ ((d.value) * (billCounts[d.value === 0.5 ? '0.50' : String(d.value)] || 0)).toFixed(2) }}
              </div>
            </div>
          </div>

          <!-- TOTAL CONTADO FOOTER -->
          <div class="total-counted-bar">
            <div class="total-label">
              <span>Total físico contado</span>
              <small>{{ Object.values(billCounts).reduce((a, b) => a + Number(b || 0), 0) }} piezas contadas</small>
            </div>
            <div class="total-amount">
              Bs {{ calculatedTotal.toFixed(2) }}
            </div>
          </div>
        </div>

        <!-- ================= PASO 2: RESUMEN Y ARQUEO ================= -->
        <div v-if="step === 2" class="step-content">
          <div class="step-intro">
            <h3>Paso 2: Resumen del turno y resultado del arqueo</h3>
            <p>Revisa la conciliación entre el dinero teórico registrado y el efectivo contado.</p>
          </div>

          <div class="summary-cards-grid">
            <div class="card-box">
              <h4>💰 Efectivo en la gaveta</h4>
              <div class="box-row">
                <span>Fondo de apertura:</span>
                <strong>Bs {{ openingAmount.toFixed(2) }}</strong>
              </div>
              <div class="box-row">
                <span>(+) Ventas en efectivo:</span>
                <strong class="text-lime">Bs {{ cashSales.toFixed(2) }}</strong>
              </div>
              <div class="box-row" v-if="cashExtraIncome > 0">
                <span>(+) Ingresos extra en efectivo:</span>
                <strong class="text-lime">Bs {{ cashExtraIncome.toFixed(2) }}</strong>
              </div>
              <div class="box-row" v-if="cashExpenses > 0">
                <span>(−) Egresos en efectivo:</span>
                <strong class="text-danger">Bs {{ cashExpenses.toFixed(2) }}</strong>
              </div>
              <div class="box-row divider">
                <span>(=) Efectivo esperado:</span>
                <strong>Bs {{ expectedCash.toFixed(2) }}</strong>
              </div>

              <h4 class="sub-heading">Otros cobros y pagos (no están en la gaveta)</h4>
              <div class="box-row">
                <span>Ventas QR / Transferencia:</span>
                <span>Bs {{ Number(resolvedStats?.qr_sales || 0).toFixed(2) }}</span>
              </div>
              <div class="box-row">
                <span>Ventas Tarjeta:</span>
                <span>Bs {{ Number(resolvedStats?.card_sales || 0).toFixed(2) }}</span>
              </div>
              <div class="box-row" v-if="nonCashExpenses > 0">
                <span>Egresos pagados con QR o tarjeta:</span>
                <span>Bs {{ nonCashExpenses.toFixed(2) }}</span>
              </div>
              <div class="box-row divider">
                <span>Total ventas del local:</span>
                <strong>Bs {{ Number(resolvedStats?.total_sales || 0).toFixed(2) }}</strong>
              </div>
              <div class="box-row">
                <span>Tickets emitidos:</span>
                <span>{{ resolvedStats?.sales_count || 0 }} ventas</span>
              </div>
              <div class="box-row" v-if="resolvedStats?.voided_count > 0">
                <span>Ventas anuladas:</span>
                <span class="text-danger">{{ resolvedStats?.voided_count }} tickets</span>
              </div>
              <!-- PedidosYa: informativo, no entra al arqueo -->
              <div class="box-row divider">
                <span>🛵 PedidosYa <small class="pedidosya-note">(no se rinde en caja)</small>:</span>
                <span>{{ resolvedStats?.pedidosya_count || 0 }} pedidos · Bs {{ Number(resolvedStats?.pedidosya_total || 0).toFixed(2) }}</span>
              </div>
            </div>

            <!-- RESULTADO DEL ARQUEO -->
            <div class="card-box highlight">
              <h4>⚖️ Conciliación de Efectivo</h4>
              <div class="box-row">
                <span>Efectivo teórico esperado:</span>
                <strong>Bs {{ expectedCash.toFixed(2) }}</strong>
              </div>
              <div class="box-row">
                <span>Efectivo físico contado:</span>
                <strong class="text-primary">Bs {{ countedTotal.toFixed(2) }}</strong>
              </div>

              <!-- BADGE DIFERENCIA -->
              <div 
                class="diff-banner"
                :class="{
                  exact: difference === 0,
                  sobrante: difference > 0,
                  faltante: difference < 0
                }"
              >
                <div class="diff-title">Diferencia detectada</div>
                <div class="diff-value">
                  {{ difference > 0 ? '+' : '' }} Bs {{ Math.abs(difference).toFixed(2) }}
                  <span v-if="difference === 0">(Caja cuadrada exacta ✅)</span>
                  <span v-else-if="difference > 0">(Sobrante)</span>
                  <span v-else>(Faltante ⚠️)</span>
                </div>
              </div>

              <!-- NOTA OBLIGATORIA SI HAY FALTANTE -->
              <div v-if="difference < 0" class="diff-note-box">
                <label class="required-label">
                  <strong>* Motivo del faltante (Requerido):</strong>
                </label>
                <textarea 
                  v-model="diffNote" 
                  placeholder="Explica la causa del faltante de dinero..."
                  rows="3"
                  class="diff-textarea"
                ></textarea>
                <small v-if="diffNote.trim().length < 5" class="text-danger">
                  Ingresa al menos 5 caracteres explicando el motivo.
                </small>
              </div>

              <div v-else-if="difference > 0" class="diff-note-box">
                <label>
                  <span>Nota sobre el sobrante (Opcional):</span>
                </label>
                <input 
                  type="text" 
                  v-model="diffNote" 
                  placeholder="Ej: Propina no registrada / redondeo"
                  class="diff-input"
                />
              </div>
            </div>

            <!-- DISTRIBUCIÓN DEL EFECTIVO AL CIERRE -->
            <div class="card-box distribution-box">
              <h4>💼 Distribución del Efectivo Contado</h4>
              <p class="dist-hint">Indica cuánto dejas en caja para el siguiente turno. El resto se retira.</p>
              <div class="box-row">
                <span>Efectivo contado:</span>
                <strong>Bs {{ countedTotal.toFixed(2) }}</strong>
              </div>
              <div class="dist-input-row">
                <label for="cash-left-input">Dejar en caja para mañana:</label>
                <div class="dist-input-wrap">
                  <span class="dist-currency">Bs</span>
                  <input
                    id="cash-left-input"
                    v-model.number="cashLeftForNext"
                    type="number"
                    min="0"
                    step="0.50"
                    class="dist-input"
                    :class="{ 'input-error': distributionError }"
                  />
                </div>
              </div>
              <div class="box-row dist-result">
                <span>Monto a retirar:</span>
                <strong :class="cashWithdrawn > 0 ? 'text-lime' : ''">
                  Bs {{ cashWithdrawn.toFixed(2) }}
                </strong>
              </div>
              <p v-if="distributionError" class="dist-error">⚠️ {{ distributionError }}</p>
            </div>
          </div>
        </div>

        <!-- ================= PASO 3: REPORTE FINAL ================= -->
        <div v-if="step === 3" class="step-content">
          <div class="success-banner">
            <div class="success-icon">✅</div>
            <div>
              <h3>¡Turno cerrado exitosamente!</h3>
              <p>El arqueo de caja ha sido guardado. Puedes imprimir el comprobante térmico a continuación.</p>
            </div>
          </div>

          <!-- REPORTE TÉRMICO IMPRIMIBLE EMBEBIDO -->
          <div class="report-preview-container">
            <CashSessionReport 
              v-if="turnoStore.closingReport" 
              :report="turnoStore.closingReport" 
            />
          </div>
        </div>
      </div>

      <!-- FOOTER ACTIONS -->
      <div class="wizard-footer">
        <template v-if="step === 0">
          <button class="btn btn-ghost" @click="emit('close')">Cancelar</button>
          <button class="btn btn-ghost" :disabled="reviewing" @click="runReview({ autoAdvance: false })">
            {{ reviewing ? 'Revisando…' : 'Volver a revisar' }}
          </button>
          <button class="btn btn-primary" :disabled="reviewBlocked" @click="continueFromReview">
            Continuar al conteo →
          </button>
        </template>

        <template v-else-if="step === 1">
          <button class="btn btn-ghost" @click="emit('close')">Cancelar</button>
          <button class="btn btn-primary" @click="nextStep">Continuar a Resumen →</button>
        </template>

        <template v-else-if="step === 2">
          <button class="btn btn-ghost" @click="prevStep" :disabled="isSubmitting">← Volver al conteo</button>
          <button 
            class="btn btn-primary" 
            :disabled="!canProceedToClose || isSubmitting" 
            @click="handleConfirmClose"
          >
            <span v-if="isSubmitting">Cerrando turno...</span>
            <span v-else>Confirmar Cierre de Caja 🔒</span>
          </button>
        </template>

        <template v-else-if="step === 3">
          <button class="btn btn-ghost" @click="handlePrintReport">🖨️ Imprimir Reporte</button>
          <button class="btn btn-primary" @click="handleFinish">Finalizar y Salir</button>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wizard-overlay {
  position: fixed;
  inset: 0;
  background: rgba(26, 13, 33, 0.75);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1200;
  padding: 16px;
}

.wizard-modal {
  background: var(--surface);
  border-radius: 20px;
  width: 100%;
  max-width: 820px;
  max-height: 92vh;
  display: flex;
  flex-direction: column;
  box-shadow: var(--shadow-pop);
  overflow: hidden;
}

.wizard-header {
  padding: 20px 26px;
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 20px;
  background: var(--surface);
}

.wizard-badge {
  display: inline-block;
  background: var(--passion-100);
  color: var(--passion-text);
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 3px 8px;
  border-radius: 999px;
  margin-bottom: 4px;
}

.wizard-title-box h2 {
  margin: 0;
  font-size: 22px;
  color: var(--ink-900);
}

.cajero-sub {
  margin: 4px 0 0;
  font-size: 13px;
  color: var(--ink-600);
}

/* Steps Nav */
.steps-nav {
  display: flex;
  align-items: center;
  gap: 8px;
}

.step-dot {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

.step-dot span {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--cream-200);
  color: var(--ink-500);
  font-weight: 700;
  font-size: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
}

.step-dot label {
  font-size: 10.5px;
  font-weight: 700;
  color: var(--ink-500);
  text-transform: uppercase;
}

.step-dot.active span {
  background: var(--passion-500);
  color: var(--on-accent);
  box-shadow: 0 0 0 3px rgba(229, 72, 77, 0.25);
}

.step-dot.active label {
  color: var(--passion-text);
}

.step-dot.done span {
  background: var(--lime-500);
  color: var(--on-accent);
}

.step-line {
  width: 24px;
  height: 2px;
  background: var(--border);
  margin-bottom: 14px;
}

.step-line.done {
  background: var(--lime-500);
}

.close-btn {
  background: none;
  border: none;
  font-size: 20px;
  color: var(--ink-400);
  cursor: pointer;
  padding: 4px;
}

.wizard-body {
  padding: 24px 26px;
  overflow-y: auto;
  flex: 1;
}

.step-intro {
  margin-bottom: 18px;
}

.step-intro h3 {
  margin: 0 0 4px;
  font-size: 17px;
  color: var(--ink-900);
}

.step-intro p {
  margin: 0;
  font-size: 13px;
  color: var(--ink-600);
}

.error-banner {
  background: var(--danger-100);
  color: var(--danger-text);
  padding: 12px 16px;
  border-radius: 10px;
  margin-bottom: 16px;
  font-size: 13.5px;
  font-weight: 600;
}

/* Cifras que cambiaron mientras se contaba (respuesta 409 del cierre) */
.notice-banner {
  background: var(--gold-100);
  color: var(--gold-text);
  padding: 12px 16px;
  border-radius: 10px;
  margin-bottom: 16px;
  font-size: 13.5px;
  font-weight: 600;
}

/* Paso 0: revisión previa */
.review-row {
  padding: 10px 14px;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 10px;
}
.review-row.ok {
  background: var(--lime-100);
  color: var(--lime-700);
}
.review-row.checking {
  background: var(--surface-alt);
  color: var(--ink-700);
}
.review-row.blocked {
  background: var(--danger-100);
  color: var(--danger-text);
}
.review-help {
  font-size: 13px;
  color: var(--ink-700);
  margin: 12px 0 0;
  line-height: 1.4;
}
.force-box {
  margin-top: 14px;
  padding: 14px;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: var(--surface-alt);
  color: var(--ink-900);
}
.force-box .review-help {
  margin: 4px 0 10px;
}
.review-ok-note {
  margin-top: 6px !important;
  color: var(--lime-700) !important;
  font-weight: 600;
}
.card-box h4.sub-heading {
  margin: 18px 0 10px;
  font-size: 13px;
  color: var(--ink-700);
}
.wizard-footer {
  flex-wrap: wrap;
}
/* Un botón deshabilitado debe verse deshabilitado (Continuar, Confirmar cierre) */
.wizard-footer .btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
  filter: none;
}

/* Conteo Denominaciones */
.denoms-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 12px;
  margin-bottom: 20px;
}

.denom-card {
  background: var(--surface-alt);
  border: 1.5px solid var(--border);
  border-radius: 12px;
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.denom-info {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.denom-badge {
  font-family: 'Baloo 2', sans-serif;
  font-weight: 800;
  font-size: 15px;
  padding: 2px 8px;
  border-radius: 6px;
  border: 1.5px solid;
}

.denom-type {
  font-size: 10.5px;
  text-transform: uppercase;
  color: var(--ink-400);
  font-weight: 700;
}

.denom-counter {
  display: flex;
  align-items: center;
  gap: 6px;
}

.btn-step {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--surface);
  font-weight: 800;
  font-size: 16px;
  color: var(--ink-800);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}

.btn-step:hover {
  background: var(--cream-200);
}

.denom-input {
  flex: 1;
  width: 100%;
  text-align: center;
  font-family: 'Baloo 2', sans-serif;
  font-weight: 800;
  font-size: 17px;
  padding: 6px;
  border-radius: 8px;
  border: 1.5px solid var(--border);
  background: var(--surface);
  color: var(--ink-900);
}

.denom-subtotal {
  font-size: 12px;
  font-weight: 700;
  color: var(--ink-600);
  text-align: right;
}

.total-counted-bar {
  background: var(--acai-900);
  color: white;
  padding: 16px 20px;
  border-radius: 14px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.total-label span {
  font-size: 14px;
  font-weight: 700;
  display: block;
}

.total-label small {
  font-size: 11.5px;
  opacity: 0.8;
}

.total-amount {
  font-family: 'Baloo 2', sans-serif;
  font-size: 26px;
  font-weight: 800;
}

/* Paso 2: Resumen Grid */
.summary-cards-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}

.card-box {
  background: var(--surface-alt);
  border: 1.5px solid var(--border);
  border-radius: 14px;
  padding: 18px 20px;
}

.card-box.highlight {
  border-color: var(--passion-300);
  background: var(--cream-50);
}

.card-box h4 {
  margin: 0 0 14px;
  font-size: 15px;
  color: var(--ink-900);
}

.box-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 13.5px;
  margin-bottom: 8px;
  color: var(--ink-700);
}

.box-row.divider {
  border-top: 1px dashed var(--border);
  padding-top: 8px;
  margin-top: 8px;
}

.text-lime { color: var(--lime-700); }
.text-danger { color: var(--danger-600); }
.text-primary { color: var(--passion-text); }

.diff-banner {
  border-radius: 12px;
  padding: 14px;
  text-align: center;
  margin: 14px 0;
}

.diff-banner.exact {
  background: var(--lime-100);
  color: var(--lime-700);
}

.diff-banner.sobrante {
  background: var(--gold-100);
  color: var(--gold-text);
}

.diff-banner.faltante {
  background: var(--danger-100);
  color: var(--danger-text);
}

.diff-title {
  font-size: 11px;
  text-transform: uppercase;
  font-weight: 700;
  letter-spacing: 0.04em;
}

.diff-value {
  font-family: 'Baloo 2', sans-serif;
  font-size: 22px;
  font-weight: 800;
  margin-top: 2px;
}

.diff-note-box {
  margin-top: 12px;
}

.required-label {
  font-size: 12px;
  color: var(--danger-600);
  margin-bottom: 6px;
  display: block;
}

.diff-textarea, .diff-input {
  width: 100%;
  padding: 10px 12px;
  border-radius: 8px;
  border: 1.5px solid var(--border);
  font-family: inherit;
  font-size: 13.5px;
  background: var(--surface);
  color: var(--ink-900);
}

.diff-textarea:focus, .diff-input:focus {
  outline: none;
  border-color: var(--passion-500);
}

/* Paso 3 */
.success-banner {
  background: var(--lime-100);
  border: 1.5px solid var(--lime-300);
  border-radius: 14px;
  padding: 16px 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 20px;
}

.success-icon {
  font-size: 32px;
}

.success-banner h3 {
  margin: 0;
  font-size: 17px;
  color: var(--lime-700);
}

.success-banner p {
  margin: 4px 0 0;
  font-size: 13px;
  color: var(--lime-700);
}

.report-preview-container {
  display: flex;
  justify-content: center;
  padding: 10px 0;
}

/* Footer */
.wizard-footer {
  padding: 18px 26px;
  border-top: 1px solid var(--border);
  background: var(--surface-hover);
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

@media (max-width: 768px) {
  .summary-cards-grid {
    grid-template-columns: 1fr;
  }
  .steps-nav label {
    display: none;
  }
}
/* Celular: los pasos bajan a su propia fila para que el título no se parta palabra por palabra */
@media (max-width: 600px) {
  .wizard-header {
    flex-wrap: wrap;
    padding: 16px 18px;
    gap: 12px;
  }
  .wizard-title-box {
    flex: 1 1 0;
    min-width: 0;
  }
  .steps-nav {
    order: 3;
    width: 100%;
    justify-content: center;
  }
}
.pedidosya-note {
  color: var(--ink-500);
  font-weight: 500;
  font-size: 0.85em;
}
</style>
