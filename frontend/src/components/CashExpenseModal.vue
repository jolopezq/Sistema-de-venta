<script setup>
import { ref, computed, watch } from 'vue';
import { useTurnoStore } from '../stores/turno';

/**
 * Modal de registro de egreso/ingreso extraordinario de efectivo.
 * Accesible para cualquier usuario con turno activo abierto.
 * Al guardar, actualiza las stats de la gaveta en tiempo real.
 */
const props = defineProps({
  visible:   { type: Boolean, default: false },
  /**
   * ID de sesión de caja específica.
   * Si se pasa, el movimiento se registra en esa sesión (histórica o no).
   * Si no, se usa la sesión activa del store.
   */
  sessionId: { type: String, default: null },
});
const emit = defineEmits(['close', 'saved']);

const turnoStore = useTurnoStore();

const isSaving   = ref(false);
const errorMsg   = ref('');
const successMsg = ref('');

const form = ref({
  type: 'expense',
  payment_method: 'cash',
  amount: '',
  description: '',
});

// Saldo actual en gaveta antes de este movimiento
const currentCash = computed(() => turnoStore.currentCashInDrawer);

// Saldo proyectado después del movimiento (solo si es efectivo)
const projectedCash = computed(() => {
  const amt = parseFloat(form.value.amount) || 0;
  if (form.value.payment_method !== 'cash') {
    return currentCash.value;
  }
  return form.value.type === 'expense'
    ? currentCash.value - amt
    : currentCash.value + amt;
});

const isOverdrawn = computed(() =>
  form.value.payment_method === 'cash' &&
  form.value.type === 'expense' &&
  projectedCash.value < 0
);

const canSubmit = computed(() => {
  const amt = parseFloat(form.value.amount);
  return (
    !isSaving.value &&
    form.value.description.trim().length >= 3 &&
    amt > 0 &&
    !isOverdrawn.value
  );
});

watch(() => props.visible, (v) => {
  if (v) resetForm();
});

function resetForm() {
  form.value = {
    type: 'expense',
    payment_method: 'cash',
    amount: '',
    description: '',
  };
  errorMsg.value   = '';
  successMsg.value = '';
}

async function handleSubmit() {
  if (!canSubmit.value) return;
  isSaving.value = true;
  errorMsg.value  = '';
  successMsg.value = '';
  try {
    await turnoStore.recordCashMovement({
      type:           form.value.type,
      payment_method: form.value.payment_method,
      amount:         parseFloat(form.value.amount),
      description:    form.value.description.trim(),
    }, props.sessionId || null);
    const methodLabel = form.value.payment_method === 'cash' ? 'en efectivo' : (form.value.payment_method === 'qr' ? 'vía QR' : 'con tarjeta');
    successMsg.value = form.value.type === 'expense'
      ? `Egreso de Bs ${parseFloat(form.value.amount).toFixed(2)} (${methodLabel}) registrado.`
      : `Ingreso extraordinario de Bs ${parseFloat(form.value.amount).toFixed(2)} (${methodLabel}) registrado.`;
    emit('saved');
    setTimeout(() => {
      if (successMsg.value) handleClose();
    }, 1200);
  } catch (err) {
    errorMsg.value = err?.message || 'Error al registrar el movimiento.';
  } finally {
    isSaving.value = false;
  }
}

function handleClose() {
  if (isSaving.value) return;
  resetForm();
  emit('close');
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-fade">
      <div v-if="visible" class="ce-backdrop" @click.self="handleClose">
        <div class="ce-panel" role="dialog" aria-modal="true" aria-labelledby="ce-title">

          <!-- Header -->
          <div class="ce-header">
            <div class="ce-header-icon">
              <span v-if="form.type === 'expense'">📤</span>
              <span v-else>📥</span>
            </div>
            <div>
              <h2 id="ce-title" class="ce-title">Movimiento de Caja</h2>
              <p class="ce-subtitle">Registra un egreso o ingreso de efectivo del turno</p>
            </div>
            <button class="ce-close-btn" @click="handleClose" aria-label="Cerrar">✕</button>
          </div>

          <!-- Tipo de movimiento -->
          <div class="ce-type-toggle">
            <button
              class="ce-type-btn"
              :class="{ active: form.type === 'expense' }"
              @click="form.type = 'expense'"
            >
              📤 Egreso (Gasto)
            </button>
            <button
              class="ce-type-btn"
              :class="{ active: form.type === 'extra_income' }"
              @click="form.type = 'extra_income'"
            >
              📥 Ingreso Extra
            </button>
          </div>

          <!-- Método de pago del movimiento -->
          <div class="ce-method-section">
            <label class="ce-method-title">¿Cómo se paga / recibe?</label>
            <div class="ce-method-toggle">
              <button
                type="button"
                class="ce-method-btn"
                :class="{ active: form.payment_method === 'cash' }"
                @click="form.payment_method = 'cash'"
              >
                💵 Efectivo (Gaveta)
              </button>
              <button
                type="button"
                class="ce-method-btn"
                :class="{ active: form.payment_method === 'qr' }"
                @click="form.payment_method = 'qr'"
              >
                📱 QR / Banco
              </button>
              <button
                type="button"
                class="ce-method-btn"
                :class="{ active: form.payment_method === 'card' }"
                @click="form.payment_method = 'card'"
              >
                💳 Tarjeta
              </button>
            </div>
            <p v-if="form.payment_method !== 'cash'" class="ce-method-hint">
              ℹ️ Pagado con {{ form.payment_method === 'qr' ? 'QR' : 'Tarjeta' }}: se incluye en el control diario de gastos pero <strong>no descuenta dinero físico</strong> de la gaveta.
            </p>
          </div>

          <!-- Saldo actual en gaveta -->
          <div class="ce-balance-preview">
            <div class="ce-balance-row">
              <span class="ce-balance-label">Efectivo físico en gaveta</span>
              <span class="ce-balance-value">Bs {{ currentCash.toFixed(2) }}</span>
            </div>
            <div class="ce-balance-row" v-if="parseFloat(form.amount) > 0 && form.payment_method === 'cash'">
              <span class="ce-balance-label">
                {{ form.type === 'expense' ? 'Efectivo después del egreso' : 'Efectivo después del ingreso' }}
              </span>
              <span
                class="ce-balance-value"
                :class="{ 'text-danger': isOverdrawn, 'text-success': !isOverdrawn && form.type === 'extra_income' }"
              >
                Bs {{ projectedCash.toFixed(2) }}
              </span>
            </div>
          </div>

          <!-- Formulario -->
          <div class="ce-form">
            <div class="ce-field">
              <label for="ce-amount" class="ce-label">Monto (Bs)</label>
              <input
                id="ce-amount"
                v-model="form.amount"
                type="number"
                min="0.01"
                step="0.01"
                placeholder="0.00"
                class="ce-input"
                :class="{ 'input-error': isOverdrawn }"
                @keyup.enter="handleSubmit"
              />
              <p v-if="isOverdrawn" class="ce-error-inline">
                ⚠️ El egreso supera el efectivo disponible en gaveta
              </p>
            </div>

            <div class="ce-field">
              <label for="ce-description" class="ce-label">
                Descripción <span class="ce-required">*</span>
              </label>
              <textarea
                id="ce-description"
                v-model="form.description"
                rows="3"
                placeholder="Ej: Compra de 2 kg frutilla en el mercado — Bs 45"
                class="ce-textarea"
                maxlength="500"
              ></textarea>
              <div class="ce-char-count">{{ form.description.length }}/500</div>
            </div>
          </div>

          <!-- Mensajes -->
          <div v-if="errorMsg" class="ce-alert ce-alert-error">⚠️ {{ errorMsg }}</div>
          <div v-if="successMsg" class="ce-alert ce-alert-success">✅ {{ successMsg }}</div>

          <!-- Acciones -->
          <div class="ce-footer">
            <button class="ce-btn-cancel" @click="handleClose" :disabled="isSaving">
              Cancelar
            </button>
            <button
              class="ce-btn-submit"
              :class="form.type === 'expense' ? 'btn-danger' : 'btn-success'"
              :disabled="!canSubmit"
              @click="handleSubmit"
            >
              <span v-if="isSaving">Guardando…</span>
              <span v-else>
                {{ form.type === 'expense' ? '📤 Registrar Egreso' : '📥 Registrar Ingreso' }}
              </span>
            </button>
          </div>

        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.ce-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1100;
  padding: 1rem;
}

.ce-panel {
  background: var(--color-surface-2, #1e2130);
  border: 1px solid var(--color-border, rgba(255,255,255,0.08));
  border-radius: 16px;
  width: 100%;
  max-width: 460px;
  padding: 1.75rem;
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  box-shadow: 0 24px 64px rgba(0,0,0,0.5);
}

.ce-header {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}
.ce-header-icon {
  font-size: 1.5rem;
  line-height: 1;
  margin-top: 2px;
}
.ce-title {
  font-size: 1.125rem;
  font-weight: 700;
  color: var(--color-text-primary, #f0f0f0);
  margin: 0;
}
.ce-subtitle {
  font-size: 0.8rem;
  color: var(--color-text-muted, #888);
  margin: 2px 0 0;
}
.ce-close-btn {
  margin-left: auto;
  background: none;
  border: none;
  color: var(--color-text-muted, #888);
  font-size: 1.1rem;
  cursor: pointer;
  padding: 0.25rem;
  transition: color 0.15s;
}
.ce-close-btn:hover { color: var(--color-text-primary, #f0f0f0); }

/* Type toggle */
.ce-type-toggle {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
}
.ce-type-btn {
  padding: 0.6rem 0.5rem;
  border-radius: 10px;
  border: 1.5px solid var(--color-border, rgba(255,255,255,0.1));
  background: var(--color-surface-3, #252838);
  color: var(--color-text-secondary, #aaa);
  font-size: 0.85rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s;
}
.ce-type-btn.active {
  border-color: var(--color-accent, #6366f1);
  background: rgba(99, 102, 241, 0.12);
  color: var(--color-accent, #6366f1);
}

/* Method section */
.ce-method-section {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.ce-method-title {
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--color-text-secondary, #aaa);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.ce-method-toggle {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 0.4rem;
}
.ce-method-btn {
  padding: 0.5rem 0.4rem;
  border-radius: 8px;
  border: 1.5px solid var(--color-border, rgba(255,255,255,0.1));
  background: var(--color-surface-3, #252838);
  color: var(--color-text-secondary, #aaa);
  font-size: 0.78rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
  text-align: center;
}
.ce-method-btn.active {
  border-color: #10b981;
  background: rgba(16, 185, 129, 0.15);
  color: #34d399;
}
.ce-method-hint {
  font-size: 0.75rem;
  color: #94a3b8;
  margin: 0.2rem 0 0 0;
  line-height: 1.3;
}

/* Balance preview */
.ce-balance-preview {
  background: var(--color-surface-3, #252838);
  border-radius: 10px;
  padding: 0.875rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.ce-balance-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.ce-balance-label {
  font-size: 0.8rem;
  color: var(--color-text-muted, #888);
}
.ce-balance-value {
  font-size: 1rem;
  font-weight: 700;
  color: var(--color-text-primary, #f0f0f0);
}
.text-danger { color: #f87171 !important; }
.text-success { color: #4ade80 !important; }

/* Form */
.ce-form { display: flex; flex-direction: column; gap: 1rem; }
.ce-field { display: flex; flex-direction: column; gap: 0.35rem; }
.ce-label {
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--color-text-secondary, #bbb);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.ce-required { color: #f87171; }
.ce-input, .ce-textarea {
  background: var(--color-surface-3, #252838);
  border: 1.5px solid var(--color-border, rgba(255,255,255,0.1));
  border-radius: 10px;
  color: var(--color-text-primary, #f0f0f0);
  font-size: 0.95rem;
  padding: 0.6rem 0.875rem;
  transition: border-color 0.15s;
  resize: none;
  font-family: inherit;
}
.ce-input:focus, .ce-textarea:focus {
  outline: none;
  border-color: var(--color-accent, #6366f1);
}
.ce-input.input-error {
  border-color: #f87171;
}
.ce-char-count {
  font-size: 0.72rem;
  color: var(--color-text-muted, #666);
  text-align: right;
}
.ce-error-inline {
  font-size: 0.78rem;
  color: #f87171;
  margin: 0;
}

/* Alerts */
.ce-alert {
  border-radius: 8px;
  padding: 0.6rem 0.875rem;
  font-size: 0.85rem;
}
.ce-alert-error { background: rgba(248,113,113,0.12); color: #f87171; }
.ce-alert-success { background: rgba(74,222,128,0.12); color: #4ade80; }

/* Footer */
.ce-footer {
  display: flex;
  gap: 0.75rem;
  justify-content: flex-end;
}
.ce-btn-cancel {
  padding: 0.6rem 1.25rem;
  border-radius: 10px;
  border: 1.5px solid var(--color-border, rgba(255,255,255,0.1));
  background: none;
  color: var(--color-text-secondary, #aaa);
  font-size: 0.9rem;
  cursor: pointer;
  transition: all 0.15s;
}
.ce-btn-cancel:hover:not(:disabled) {
  background: rgba(255,255,255,0.05);
  color: var(--color-text-primary, #f0f0f0);
}
.ce-btn-submit {
  padding: 0.6rem 1.5rem;
  border-radius: 10px;
  border: none;
  font-size: 0.9rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
}
.ce-btn-submit.btn-danger {
  background: rgba(248,113,113,0.2);
  color: #f87171;
  border: 1.5px solid rgba(248,113,113,0.3);
}
.ce-btn-submit.btn-danger:hover:not(:disabled) {
  background: rgba(248,113,113,0.3);
}
.ce-btn-submit.btn-success {
  background: rgba(74,222,128,0.2);
  color: #4ade80;
  border: 1.5px solid rgba(74,222,128,0.3);
}
.ce-btn-submit.btn-success:hover:not(:disabled) {
  background: rgba(74,222,128,0.3);
}
.ce-btn-submit:disabled, .ce-btn-cancel:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

/* Transition */
.modal-fade-enter-active, .modal-fade-leave-active {
  transition: opacity 0.2s ease;
}
.modal-fade-enter-from, .modal-fade-leave-to {
  opacity: 0;
}
</style>
