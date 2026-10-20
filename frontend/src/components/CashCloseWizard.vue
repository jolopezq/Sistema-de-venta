<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import { useTurnoStore, readCashCloseProgress, CASH_CLOSE_PROGRESS_KEY } from '../stores/turno';
import { useAuthStore } from '../stores/auth';
import { useNetworkStore } from '../stores/network';
import CashSessionReport from './CashSessionReport.vue';
import SyncIssuesPanel from './SyncIssuesPanel.vue';
import {
  DENOMINATIONS, sanitizeCount, sanitizeCounts, countTotal, piecesCount,
  sanitizeMoneyText, moneyFromText,
} from '../utils/cashCount';

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
  ? reviewedAt.value.toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit', hour12: false })
  : '');

// ---------- Paso 1: conteo ----------
// Conteo por denominación (Bolivianos)
const billCounts = ref(sanitizeCounts(null));

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

const denominations = DENOMINATIONS;
/** Billetes (columna izquierda) y monedas (derecha); el orden de DENOMINATIONS es el del teclado */
const billDenoms = denominations.filter((d) => d.type === 'billete');
const coinDenoms = denominations.filter((d) => d.type === 'moneda');
const denomIndex = (d) => denominations.indexOf(d);

// Cálculo automático de la suma de denominaciones
const calculatedTotal = computed(() => countTotal(billCounts.value));
const totalPieces = computed(() => piecesCount(billCounts.value));

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

// Incremento / decremento con el mouse (el flujo principal es el teclado)
function changeQty(denomKey, delta) {
  billCounts.value[denomKey] = sanitizeCount(Number(billCounts.value[denomKey] || 0) + delta);
  saveProgress();
}

// ---------- Ingreso directo con teclado (plan PLAN_CIERRE_CAJA_CONTEO_DIRECTO.md) ----------
const countInputs = [];
const setCountRef = (el, i) => { if (el) countInputs[i] = el; };
const nextBtn = ref(null);
const closeBtn = ref(null);
const noteField = ref(null);
const cashLeftField = ref(null);

function focusCount(i) {
  nextTick(() => {
    const el = countInputs[i];
    if (el) { el.focus(); el.select(); }
  });
}

/** Solo dígitos: se descartan signos, comas y letras; vacío vale 0 y se muestra el «0» tenue. */
function onCountInput(key, e) {
  billCounts.value[key] = sanitizeCount(e.target.value);
  e.target.value = billCounts.value[key] || '';
  saveProgress();
}

function onCountKeydown(e, i) {
  if (e.key === 'Enter' && e.ctrlKey) { e.preventDefault(); nextStep(); return; }
  const last = denominations.length - 1;
  if (e.key === 'Enter' || e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault();
    const back = e.key === 'ArrowUp' || (e.key === 'Enter' && e.shiftKey);
    if (back) { if (i > 0) focusCount(i - 1); return; }
    if (i === last) {
      // El último Enter solo lleva el foco al botón: uno de más no cambia de paso
      if (e.key === 'Enter') nextBtn.value?.focus();
      return;
    }
    focusCount(i + 1);
  }
}

// Fondo para el próximo turno: texto con coma o punto, máximo 2 decimales
const cashLeftText = ref('0');
watch(cashLeftText, (t) => { cashLeftForNext.value = moneyFromText(t); });
function onCashLeftInput(e) {
  cashLeftText.value = sanitizeMoneyText(e.target.value);
  e.target.value = cashLeftText.value;
}
function setCashLeft(value) {
  cashLeftText.value = String(Math.max(0, Math.round(value * 100) / 100));
}
const openingChipValue = computed(() => Math.min(openingAmount.value, countedTotal.value));

// Al entrar a cada paso el cursor ya está donde se escribe
watch(step, (value) => {
  if (value === 1) focusCount(0);
  if (value === 2) {
    nextTick(() => {
      const el = difference.value < 0 ? noteField.value : cashLeftField.value;
      if (el) { el.focus(); if (el.tagName === 'INPUT') el.select(); }
    });
  }
});

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
    if (parsed.billCounts) billCounts.value = sanitizeCounts({ ...billCounts.value, ...parsed.billCounts });
    if (parsed.diffNote) diffNote.value = parsed.diffNote;
    if (parsed.useManualTotal) useManualTotal.value = parsed.useManualTotal;
    if (parsed.manualTotalOverride !== undefined) manualTotalOverride.value = parsed.manualTotalOverride;
    if (parsed.cashLeftForNext !== undefined) {
      cashLeftForNext.value = moneyFromText(parsed.cashLeftForNext);
      cashLeftText.value = String(cashLeftForNext.value);
    }
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
          <h2>Cerrar caja</h2>
          <!-- El turno es de quien lo abrió (un administrador puede cerrar el de un cajero) -->
          <p class="cajero-sub">
            Turno de <strong>{{ resolvedSession?.cashier?.name || authStore.user?.name || 'Cajero' }}</strong>,
            abierto a las <strong>{{ resolvedSession?.opened_at ? new Date(resolvedSession.opened_at).toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit', hour12: false }) : '--:--' }}</strong>
          </p>
        </div>

        <!-- STEP INDICATOR -->
        <div class="steps-nav">
          <div class="step-dot" :class="{ active: step === 0, done: step > 0 }">
            <span>{{ step > 0 ? '✓' : '1' }}</span>
            <label>Revisión</label>
          </div>
          <div class="step-line" :class="{ done: step > 0 }"></div>
          <div class="step-dot" :class="{ active: step === 1, done: step > 1 }">
            <span>{{ step > 1 ? '✓' : '2' }}</span>
            <label>Conteo</label>
          </div>
          <div class="step-line" :class="{ done: step > 1 }"></div>
          <div class="step-dot" :class="{ active: step === 2, done: step > 2 }">
            <span>{{ step > 2 ? '✓' : '3' }}</span>
            <label>Resultado</label>
          </div>
          <div class="step-line" :class="{ done: step > 2 }"></div>
          <div class="step-dot" :class="{ active: step === 3 }">
            <span>4</span>
            <label>Comprobante</label>
          </div>
        </div>

        <button v-if="step !== 3" class="close-btn" type="button" aria-label="Cerrar sin terminar el arqueo" @click="emit('close')">✕</button>
      </div>

      <!-- BODY -->
      <div class="wizard-body">
        <!-- ERROR MESSAGE -->
        <div v-if="errorMessage" class="error-banner">
          {{ errorMessage }}
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
          <div class="step-intro step-intro-row">
            <div>
              <h3>Cuenta el efectivo de la gaveta</h3>
              <p>Escribe cuántas piezas hay de cada valor y pulsa Enter para pasar al siguiente.</p>
            </div>
            <p v-if="reviewedTime && unsyncedCount === 0" class="review-ok-note">
              Revisado a las {{ reviewedTime }}: ventas enviadas y cifras al día.
            </p>
          </div>

          <!-- Libro de conteo: billetes a la izquierda, monedas a la derecha; el orden del teclado es el de lectura -->
          <div class="count-board">
            <div v-for="group in [{ name: 'Billetes', list: billDenoms }, { name: 'Monedas', list: coinDenoms }]" :key="group.name" class="count-col">
              <h4 class="count-group">{{ group.name }}</h4>
              <div
                v-for="d in group.list"
                :key="d.key"
                class="count-row"
                :class="{ filled: billCounts[d.key] > 0 }"
                :style="{ '--bill': d.color }"
              >
                <span class="count-badge">{{ d.label }}</span>
                <input
                  :ref="(el) => setCountRef(el, denomIndex(d))"
                  :value="billCounts[d.key] || ''"
                  class="count-input"
                  type="text"
                  inputmode="numeric"
                  autocomplete="off"
                  maxlength="4"
                  placeholder="0"
                  :aria-label="`Cantidad de ${d.type === 'billete' ? 'billetes' : 'monedas'} de ${d.label}`"
                  @input="onCountInput(d.key, $event)"
                  @keydown="onCountKeydown($event, denomIndex(d))"
                  @focus="$event.target.select()"
                />
                <span class="count-sub">Bs {{ (d.value * (billCounts[d.key] || 0)).toFixed(2) }}</span>
                <span class="count-adj">
                  <button type="button" tabindex="-1" :aria-label="`Quitar uno de ${d.label}`" @click="changeQty(d.key, -1)">−</button>
                  <button type="button" tabindex="-1" :aria-label="`Sumar uno de ${d.label}`" @click="changeQty(d.key, 1)">+</button>
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- ================= PASO 2: RESULTADO DEL ARQUEO ================= -->
        <div v-if="step === 2" class="step-content step-compact">
          <div class="step-intro">
            <h3>Compara lo contado con lo esperado</h3>
            <p>Si falta dinero, anota el motivo antes de cerrar.</p>
          </div>

          <div class="summary-cards-grid">
            <div class="card-box">
              <h4>Efectivo en la gaveta</h4>
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

              <details class="other-pay">
                <summary>Otros cobros y pagos (no están en la gaveta)</summary>
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
                  <span>{{ resolvedStats?.sales_count || 0 }} {{ resolvedStats?.sales_count === 1 ? 'venta' : 'ventas' }}</span>
                </div>
                <div class="box-row" v-if="resolvedStats?.voided_count > 0">
                  <span>Ventas anuladas:</span>
                  <span class="text-danger">{{ resolvedStats?.voided_count }} {{ resolvedStats?.voided_count === 1 ? 'ticket' : 'tickets' }}</span>
                </div>
                <!-- PedidosYa: informativo, no entra al arqueo -->
                <div class="box-row divider">
                  <span>PedidosYa <small class="pedidosya-note">(no se rinde en caja)</small>:</span>
                  <span>{{ resolvedStats?.pedidosya_count || 0 }} pedidos, Bs {{ Number(resolvedStats?.pedidosya_total || 0).toFixed(2) }}</span>
                </div>
              </details>
            </div>

            <!-- RESULTADO DEL ARQUEO -->
            <div class="card-box highlight">
              <h4>Resultado del arqueo</h4>
              <div class="box-row">
                <span>Efectivo esperado:</span>
                <strong>Bs {{ expectedCash.toFixed(2) }}</strong>
              </div>
              <div class="box-row">
                <span>Efectivo contado:</span>
                <strong class="text-primary">Bs {{ countedTotal.toFixed(2) }}</strong>
              </div>

              <!-- DIFERENCIA -->
              <div
                class="diff-banner"
                :class="{
                  exact: difference === 0,
                  sobrante: difference > 0,
                  faltante: difference < 0
                }"
              >
                <div class="diff-text">
                  <div class="diff-title">Diferencia</div>
                  <div class="diff-verdict">
                    <template v-if="difference === 0">La caja cuadra</template>
                    <template v-else-if="difference > 0">Sobra dinero</template>
                    <template v-else>Falta dinero</template>
                  </div>
                </div>
                <div class="diff-value">{{ difference > 0 ? '+' : difference < 0 ? '−' : '' }}Bs {{ Math.abs(difference).toFixed(2) }}</div>
              </div>

              <!-- NOTA OBLIGATORIA SI HAY FALTANTE -->
              <div v-if="difference < 0" class="diff-note-box">
                <label class="required-label" for="diff-note-field">
                  <strong>Motivo del faltante (obligatorio)</strong>
                </label>
                <textarea
                  id="diff-note-field"
                  ref="noteField"
                  v-model="diffNote"
                  placeholder="Qué pasó: un vuelto mal dado, un gasto sin registrar…"
                  rows="2"
                  class="diff-textarea"
                ></textarea>
                <small v-if="diffNote.trim().length < 5" class="text-danger">
                  Escribe al menos 5 caracteres.
                </small>
              </div>

              <div v-else-if="difference > 0" class="diff-note-box">
                <label for="diff-note-field">
                  <span>Nota sobre el sobrante (opcional)</span>
                </label>
                <input
                  id="diff-note-field"
                  type="text"
                  v-model="diffNote"
                  placeholder="Ej.: propina no registrada, redondeo"
                  class="diff-input"
                />
              </div>
            </div>

            <!-- QUÉ SE HACE CON EL EFECTIVO -->
            <div class="card-box distribution-box">
              <h4>Qué se hace con el efectivo</h4>
              <div class="dist-input-row">
                <label for="cash-left-input">Queda para el próximo turno:</label>
                <div class="dist-input-wrap">
                  <span class="dist-currency">Bs</span>
                  <input
                    id="cash-left-input"
                    ref="cashLeftField"
                    :value="cashLeftText"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    class="dist-input"
                    :class="{ 'input-error': distributionError }"
                    @input="onCashLeftInput"
                    @focus="$event.target.select()"
                    @keydown.enter.prevent="closeBtn?.focus()"
                  />
                </div>
              </div>
              <div class="dist-chips" role="group" aria-label="Atajos para el fondo del próximo turno">
                <button type="button" class="dist-chip" @click="setCashLeft(openingChipValue)">Como al abrir: Bs {{ openingChipValue.toFixed(2) }}</button>
                <button type="button" class="dist-chip" @click="setCashLeft(countedTotal)">Todo</button>
                <button type="button" class="dist-chip" @click="setCashLeft(0)">Nada</button>
              </div>
              <div class="box-row dist-result">
                <span>Se retira:</span>
                <strong :class="cashWithdrawn > 0 ? 'text-lime' : ''">
                  Bs {{ cashWithdrawn.toFixed(2) }}
                </strong>
              </div>
              <p v-if="distributionError" class="dist-error" role="alert">{{ distributionError }}</p>
            </div>
          </div>
        </div>

        <!-- ================= PASO 3: REPORTE FINAL ================= -->
        <div v-if="step === 3" class="step-content">
          <div class="success-banner">
            <div class="success-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
            </div>
            <div>
              <h3>Caja cerrada</h3>
              <p>El arqueo quedó guardado. Puedes imprimir el comprobante.</p>
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
            Seguir al conteo
          </button>
        </template>

        <template v-else-if="step === 1">
          <button class="btn btn-ghost" @click="emit('close')">Cancelar</button>
          <div class="footer-total" role="status" aria-live="polite">
            <span class="ft-label">Total contado</span>
            <strong class="ft-amount">Bs {{ calculatedTotal.toFixed(2) }}</strong>
            <small class="ft-pieces">{{ totalPieces }} {{ totalPieces === 1 ? 'pieza' : 'piezas' }}</small>
          </div>
          <button ref="nextBtn" class="btn btn-primary" @click="nextStep">Ver el resultado</button>
        </template>

        <template v-else-if="step === 2">
          <button class="btn btn-ghost" @click="prevStep" :disabled="isSubmitting">Volver al conteo</button>
          <button
            ref="closeBtn"
            class="btn btn-primary"
            :disabled="!canProceedToClose || isSubmitting"
            @click="handleConfirmClose"
          >
            <span v-if="isSubmitting">Cerrando…</span>
            <span v-else>Cerrar caja</span>
          </button>
        </template>

        <template v-else-if="step === 3">
          <button class="btn btn-ghost" @click="handlePrintReport">Imprimir comprobante</button>
          <button class="btn btn-primary" @click="handleFinish">Listo</button>
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
  padding: 12px;
}

.wizard-modal {
  background: var(--surface);
  border-radius: 20px;
  width: 100%;
  max-width: 960px;
  max-height: calc(100vh - 24px);
  display: flex;
  flex-direction: column;
  box-shadow: var(--shadow-pop);
  overflow: hidden;
}

.wizard-header {
  padding: 12px 22px;
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
  font-size: 12px;
  font-weight: 700;
  padding: 3px 8px;
  border-radius: 999px;
  margin-bottom: 4px;
}

.wizard-title-box h2 {
  margin: 0;
  font-size: 20px;
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
  width: 24px;
  height: 24px;
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
  font-size: 11.5px;
  font-weight: 600;
  color: var(--ink-500);
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
  padding: 12px 22px 10px;
  overflow-y: auto;
  flex: 1;
}

.step-intro {
  margin-bottom: 10px;
}
.step-intro-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 16px;
}
.step-intro-row .review-ok-note { margin: 0 !important; font-size: 12.5px; white-space: nowrap; }

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

/* Libro de conteo: dos columnas (billetes | monedas), una fila por valor */
.count-board {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px 28px;
  align-items: start;
}
.count-col { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.count-group {
  margin: 0 0 2px;
  font-size: 13px;
  font-weight: 600;
  color: var(--ink-600);
}
.count-row {
  --bill: var(--ink-400);
  display: grid;
  grid-template-columns: 96px 96px minmax(0, 1fr) auto;
  align-items: center;
  gap: 12px;
  min-height: 40px;
  padding: 0 8px 0 0;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  box-shadow: inset 5px 0 0 var(--bill);
  transition: border-color .12s, background-color .12s;
}
.count-row:focus-within {
  border-color: var(--acai-500);
  background: var(--acai-50);
  box-shadow: inset 5px 0 0 var(--bill), 0 0 0 3px var(--acai-50);
}
.count-badge {
  padding-left: 16px;
  font-family: 'Baloo 2', sans-serif;
  font-weight: 700;
  font-size: 16px;
  color: var(--ink-900);
}
.count-input {
  width: 100%;
  box-sizing: border-box;
  height: 30px;
  text-align: center;
  font-family: 'Baloo 2', sans-serif;
  font-weight: 800;
  font-size: 18px;
  font-variant-numeric: tabular-nums;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  background: var(--surface);
  color: var(--ink-900);
}
.count-input::placeholder { color: var(--ink-300); font-weight: 600; }
.count-input:focus { outline: none; border-color: var(--acai-500); }
.count-sub {
  text-align: right;
  font-family: 'Baloo 2', sans-serif;
  font-size: 15px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  color: var(--ink-400);
}
.count-row.filled .count-sub { color: var(--ink-900); font-weight: 700; }
/* − / + solo para el mouse: fuera del recorrido con Tab y discretos hasta pasar por la fila */
.count-adj { display: inline-flex; gap: 4px; opacity: 0; transition: opacity .12s; }
.count-row:hover .count-adj,
.count-row:focus-within .count-adj { opacity: 1; }
.count-adj button {
  width: 24px;
  height: 24px;
  border-radius: 6px;
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--ink-700);
  font-weight: 800;
  line-height: 1;
  cursor: pointer;
}
.count-adj button:hover { background: var(--cream-200); }

/* Total contado: siempre a la vista, en el pie */
.footer-total {
  flex: 1 1 auto;
  display: flex;
  align-items: baseline;
  justify-content: center;
  gap: 10px;
  min-width: 0;
  padding: 6px 18px;
  border-radius: 12px;
  background: var(--acai-900);
  color: #fff;
}
.ft-label { font-size: 13px; font-weight: 600; opacity: .85; }
.ft-amount {
  font-family: 'Baloo 2', sans-serif;
  font-size: 24px;
  font-weight: 800;
  line-height: 1.2;
  font-variant-numeric: tabular-nums;
}
.ft-pieces { font-size: 12px; opacity: .8; }

/* Paso 2: Resumen Grid */
.summary-cards-grid {
  display: grid;
  grid-template-columns: 1.15fr 1fr 1fr;
  gap: 12px;
  align-items: start;
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
  font-size: 13px;
  font-weight: 700;
}

.diff-value {
  font-family: 'Baloo 2', sans-serif;
  font-size: 26px;
  font-weight: 700;
  margin-top: 2px;
  font-variant-numeric: tabular-nums;
}
.diff-verdict { font-size: 14px; font-weight: 700; }

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
  width: 44px;
  height: 44px;
  flex-shrink: 0;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: var(--lime-100);
  color: var(--lime-700);
}
.success-icon svg { width: 22px; height: 22px; }

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
  padding: 10px 22px;
  border-top: 1px solid var(--border);
  background: var(--surface-hover);
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 12px;
}
.wizard-footer .btn { min-height: 42px; }

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
/* ===== Paso 2 compacto (portátil de caja) ===== */
.step-compact .card-box { padding: 10px 14px; border-radius: 12px; }
.step-compact .card-box h4 { margin: 0 0 6px; font-size: 14px; }
.step-compact .box-row { font-size: 13px; margin-bottom: 3px; }
.step-compact .box-row.divider { padding-top: 5px; margin-top: 5px; }
.other-pay { margin-top: 8px; font-size: 13px; }
.other-pay summary {
  cursor: pointer;
  font-weight: 600;
  color: var(--ink-700);
  padding: 4px 0;
}
.other-pay summary:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; border-radius: 4px; }
.other-pay[open] summary { margin-bottom: 4px; }
.step-compact .diff-banner {
  display: flex;
  justify-content: space-between;
  align-items: center;
  text-align: left;
  padding: 8px 12px;
  margin: 8px 0;
}
.step-compact .diff-value { font-size: 22px; margin: 0; }
.step-compact .diff-note-box { margin-top: 6px; }
.step-compact .required-label { margin-bottom: 4px; }
.step-compact .diff-textarea { padding: 6px 10px; resize: none; }
.step-compact .diff-input { padding: 6px 10px; }
.dist-chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 6px 0 8px; }
.dist-chip {
  min-height: 30px;
  padding: 0 10px;
  border: 1.5px solid var(--border);
  border-radius: 999px;
  background: var(--surface);
  color: var(--ink-900);
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
}
.dist-chip:hover { border-color: var(--acai-300); }
.dist-chip:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; }

.step-compact .diff-note-box label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; color: var(--ink-700); }
/* Fondo para el próximo turno */
.dist-input-row { display: flex; flex-direction: column; gap: 4px; }
.dist-input-row label { font-size: 13px; font-weight: 600; color: var(--ink-700); }
.dist-input-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 12px;
  height: 40px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
}
.dist-input-wrap:focus-within { border-color: var(--acai-500); box-shadow: 0 0 0 3px var(--acai-50); }
.dist-currency { font-weight: 600; color: var(--ink-500); }
.dist-input {
  flex: 1;
  min-width: 0;
  border: none;
  outline: none;
  background: transparent;
  color: var(--ink-900);
  font-family: 'Baloo 2', sans-serif;
  font-size: 20px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.dist-input.input-error { color: var(--danger-text); }
.dist-error { margin: 6px 0 0; font-size: 12.5px; font-weight: 600; color: var(--danger-text); }
.dist-result { margin-top: 4px; }

/* Plan B: ventanas bajas (p. ej. Windows al 125 %, ≈ 1093 × 489) */
@media (max-height: 520px) and (min-width: 641px) {
  .wizard-header { padding: 8px 22px; }
  .wizard-body { padding: 8px 22px 8px; }
  .step-intro { margin-bottom: 6px; }
  .step-intro p { font-size: 12.5px; }
  .count-col { gap: 4px; }
  .count-row { min-height: 36px; }
  .count-input { height: 28px; }
  .wizard-footer { padding: 8px 22px; }
  .wizard-footer .btn { min-height: 38px; }
  .step-compact .card-box { padding: 8px 12px; }
  .step-compact .box-row { margin-bottom: 2px; }
  .step-compact .diff-banner { padding: 6px 12px; margin: 6px 0; }
}

@media (min-width: 769px) and (max-width: 900px) {
  .summary-cards-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 640px) {
  .count-board { grid-template-columns: 1fr; }
  .count-row { grid-template-columns: 84px 84px minmax(0, 1fr) auto; gap: 8px; }
  .step-intro-row { flex-direction: column; align-items: flex-start; gap: 4px; }
  .wizard-footer { flex-wrap: wrap; }
  .footer-total { order: -1; flex: 1 1 100%; }
}
</style>
