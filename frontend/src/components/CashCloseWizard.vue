<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useTurnoStore } from '../stores/turno';
import { useAuthStore } from '../stores/auth';
import CashSessionReport from './CashSessionReport.vue';

const emit = defineEmits(['close', 'closed']);
const turnoStore = useTurnoStore();
const authStore = useAuthStore();

const step = ref(1); // 1: Conteo, 2: Resumen/Arqueo, 3: Reporte
const isSubmitting = ref(false);
const errorMessage = ref('');

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

// Datos de arqueo
const openingAmount = computed(() => Number(turnoStore.activeSession?.opening_amount || 0));
const cashSales = computed(() => Number(turnoStore.sessionStats?.cash_sales || 0));
const expectedCash = computed(() => Number(turnoStore.sessionStats?.expected_cash || (openingAmount.value + cashSales.value)));
const difference = computed(() => Number((countedTotal.value - expectedCash.value).toFixed(2)));

// Validación de notas obligatorias si hay faltante
const canProceedToClose = computed(() => {
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

// Guardar en localStorage para protección ante recargas
function saveProgress() {
  const data = {
    step: step.value,
    billCounts: billCounts.value,
    useManualTotal: useManualTotal.value,
    manualTotalOverride: manualTotalOverride.value,
    diffNote: diffNote.value,
  };
  localStorage.setItem('cashClose_inProgress', JSON.stringify(data));
}

// Cargar progreso previo si existe
onMounted(() => {
  const saved = localStorage.getItem('cashClose_inProgress');
  if (saved) {
    try {
      const parsed = JSON.parse(saved);
      if (parsed.billCounts) billCounts.value = { ...billCounts.value, ...parsed.billCounts };
      if (parsed.diffNote) diffNote.value = parsed.diffNote;
      if (parsed.useManualTotal) useManualTotal.value = parsed.useManualTotal;
      if (parsed.manualTotalOverride !== undefined) manualTotalOverride.value = parsed.manualTotalOverride;
      if (parsed.step && parsed.step < 3) step.value = parsed.step;
    } catch (e) {
      console.warn('Error leyendo progreso de arqueo:', e);
    }
  }
});

watch(step, () => saveProgress());

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
    errorMessage.value = 'Por favor ingresa un motivo explicativo para el faltante.';
    return;
  }

  isSubmitting.value = true;
  errorMessage.value = '';

  try {
    const payload = {
      actual_closing: countedTotal.value,
      bill_breakdown: billCounts.value,
      diff_note: diffNote.value.trim() || null,
    };

    await turnoStore.closeSession(payload);
    step.value = 3;
    emit('closed');
  } catch (err) {
    errorMessage.value = err.message || 'Error al cerrar caja.';
  } finally {
    isSubmitting.value = false;
  }
}

function handlePrintReport() {
  window.print();
}

function handleFinish() {
  localStorage.removeItem('cashClose_inProgress');
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
            Cajero: <strong>{{ authStore.user?.name || 'Cajero' }}</strong> · 
            Iniciado: <strong>{{ turnoStore.activeSession?.opened_at ? new Date(turnoStore.activeSession.opened_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '--:--' }}</strong>
          </p>
        </div>

        <!-- STEP INDICATOR -->
        <div class="steps-nav">
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

        <!-- ================= PASO 1: CONTEO FÍSICO ================= -->
        <div v-if="step === 1" class="step-content">
          <div class="step-intro">
            <h3>Paso 1: Conteo físico de dinero en gaveta</h3>
            <p>Ingresa la cantidad de billetes y monedas que tienes en la caja registradora.</p>
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
            <!-- CAJA TEÓRICA -->
            <div class="card-box">
              <h4>💰 Movimientos del Turno</h4>
              <div class="box-row">
                <span>Fondo de apertura:</span>
                <strong>Bs {{ openingAmount.toFixed(2) }}</strong>
              </div>
              <div class="box-row">
                <span>(+) Ventas en efectivo:</span>
                <strong class="text-lime">Bs {{ cashSales.toFixed(2) }}</strong>
              </div>
              <div class="box-row">
                <span>Ventas QR / Transferencia:</span>
                <span>Bs {{ Number(turnoStore.sessionStats?.qr_sales || 0).toFixed(2) }}</span>
              </div>
              <div class="box-row">
                <span>Ventas Tarjeta:</span>
                <span>Bs {{ Number(turnoStore.sessionStats?.card_sales || 0).toFixed(2) }}</span>
              </div>
              <div class="box-row divider">
                <span>Total ingresos del turno:</span>
                <strong>Bs {{ Number(turnoStore.sessionStats?.total_sales || 0).toFixed(2) }}</strong>
              </div>
              <div class="box-row">
                <span>Tickets emitidos:</span>
                <span>{{ turnoStore.sessionStats?.sales_count || 0 }} ventas</span>
              </div>
              <div class="box-row" v-if="turnoStore.sessionStats?.voided_count > 0">
                <span>Ventas anuladas:</span>
                <span class="text-danger">{{ turnoStore.sessionStats?.voided_count }} tickets</span>
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
        <template v-if="step === 1">
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
  color: var(--passion-700);
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
  color: white;
  box-shadow: 0 0 0 3px rgba(229, 72, 77, 0.25);
}

.step-dot.active label {
  color: var(--passion-600);
}

.step-dot.done span {
  background: var(--lime-500);
  color: white;
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
  color: var(--danger-700);
  padding: 12px 16px;
  border-radius: 10px;
  margin-bottom: 16px;
  font-size: 13.5px;
  font-weight: 600;
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
.text-primary { color: var(--passion-600); }

.diff-banner {
  border-radius: 12px;
  padding: 14px;
  text-align: center;
  margin: 14px 0;
}

.diff-banner.exact {
  background: var(--lime-100);
  color: var(--lime-800);
}

.diff-banner.sobrante {
  background: var(--gold-100);
  color: #8A6A00;
}

.diff-banner.faltante {
  background: var(--danger-100);
  color: var(--danger-700);
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
  color: var(--lime-800);
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
</style>
