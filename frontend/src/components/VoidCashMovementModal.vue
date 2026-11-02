<script setup>
import { ref, computed, watch } from 'vue';
import { useTurnoStore } from '../stores/turno';
import FinanceModal from './Finance/FinanceModal.vue';
import './Finance/finance.css';

/**
 * Modal de confirmación para anular un egreso o ingreso de caja.
 * Exige un motivo obligatorio de 10 a 255 caracteres.
 * El movimiento queda con soft-delete (deleted_at, voided_by, void_reason).
 */
const props = defineProps({
  visible:   { type: Boolean, default: false },
  movement:  { type: Object, default: null },
  sessionId: { type: String, default: null },
});

const emit = defineEmits(['close', 'voided']);

const turnoStore = useTurnoStore();

const reason    = ref('');
const isSaving  = ref(false);
const errorMsg  = ref('');

const canSubmit = computed(() => {
  return !isSaving.value && reason.value.trim().length >= 10;
});

const movementTypeLabel = computed(() => {
  return props.movement?.type === 'expense' ? 'el gasto' : 'el ingreso';
});

const methodLabel = computed(() => {
  const m = props.movement?.payment_method;
  if (m === 'cash') return 'Efectivo';
  if (m === 'qr') return 'QR';
  if (m === 'card') return 'Tarjeta';
  return 'Efectivo';
});

watch(() => props.visible, (v) => {
  if (v) {
    reason.value = '';
    errorMsg.value = '';
    isSaving.value = false;
  }
});

async function handleConfirmVoid() {
  if (!canSubmit.value || !props.movement?.id) return;
  isSaving.value = true;
  errorMsg.value = '';
  try {
    const sId = props.sessionId || props.movement.cash_register_session_id;
    await turnoStore.voidCashMovement(sId, props.movement.id, reason.value.trim());
    emit('voided');
    emit('close');
  } catch (err) {
    errorMsg.value = err?.message || 'No se pudo anular el movimiento. Revisa la conexión.';
  } finally {
    isSaving.value = false;
  }
}

function handleClose() {
  if (isSaving.value) return;
  reason.value = '';
  errorMsg.value = '';
  emit('close');
}
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="fin-page vm-host">
      <FinanceModal
        :title="movement?.type === 'expense' ? 'Anular gasto' : 'Anular ingreso'"
        subtitle="El movimiento queda tachado y deja de contar en la gaveta y el arqueo."
        :busy="isSaving"
        @close="handleClose"
      >
        <div v-if="movement" class="vm-summary">
          <div class="vm-summary-main">
            <span class="vm-type-pill" :class="movement.type === 'expense' ? 'expense' : 'income'">
              {{ movement.type === 'expense' ? '📤 Egreso' : '📥 Ingreso' }}
            </span>
            <strong class="vm-amount">Bs {{ Number(movement.amount || 0).toFixed(2) }}</strong>
            <span class="vm-method">{{ methodLabel }}</span>
          </div>
          <p class="vm-desc">{{ movement.description }}</p>
        </div>

        <div class="fin-field">
          <label for="vm-reason">Motivo de la anulación <span class="text-danger">*</span></label>
          <textarea
            id="vm-reason"
            v-model="reason"
            rows="3"
            maxlength="255"
            class="fin-input vm-textarea"
            :placeholder="`Explica por qué se anula ${movementTypeLabel} (ej: compra cancelada, error de carga duplicado…)`"
          ></textarea>
          <p class="fin-hint vm-count">
            {{ reason.trim().length }} de 255. Mínimo 10 letras (obligatorio para auditoría).
          </p>
        </div>

        <div v-if="errorMsg" class="fin-note err" role="alert">{{ errorMsg }}</div>

        <template #footer>
          <button class="fin-btn fin-btn-ghost fin-btn-lg" type="button" :disabled="isSaving" @click="handleClose">
            Cancelar
          </button>
          <button class="fin-btn fin-btn-danger fin-btn-lg" type="button" :disabled="!canSubmit" @click="handleConfirmVoid">
            <template v-if="isSaving">Anulando…</template>
            <template v-else>Anular movimiento</template>
          </button>
        </template>
      </FinanceModal>
    </div>
  </Teleport>
</template>

<style scoped>
.vm-host { display: contents; }
.vm-summary {
  padding: 14px 16px;
  background: var(--surface-hover);
  border: 1px solid var(--border);
  border-radius: 12px;
  margin-bottom: 16px;
}
.vm-summary-main {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.vm-type-pill {
  font-size: 11.5px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 6px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.vm-type-pill.expense { background: var(--danger-100); color: var(--danger-text); }
.vm-type-pill.income { background: #D1FAE5; color: #065F46; }
.vm-amount {
  font-family: var(--font-heading);
  font-size: 18px;
  font-weight: 800;
  color: var(--ink-900);
}
.vm-method {
  font-size: 12.5px;
  color: var(--ink-500);
}
.vm-desc {
  margin: 6px 0 0;
  font-size: 13.5px;
  color: var(--ink-700);
}
.vm-textarea { resize: vertical; min-height: 70px; }
.vm-count { margin: 0; text-align: right; }
.fin-btn-danger {
  background: var(--danger-600);
  color: #FFFFFF;
}
.fin-btn-danger:hover:not(:disabled) {
  background: var(--danger-700, #A3302F);
}
</style>
