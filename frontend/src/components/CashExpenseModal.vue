<script setup>
import { ref, computed, watch } from 'vue';
import { useTurnoStore } from '../stores/turno';
import { apiFetch } from '../services/api';
import FinanceModal from './Finance/FinanceModal.vue';
import './Finance/finance.css';

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
const categories = ref([]);

const form = ref({
  type: 'expense',
  payment_method: 'cash',
  amount: '',
  description: '',
  expense_category_id: null,
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

watch(() => props.visible, async (v) => {
  if (v) {
    resetForm();
    if (categories.value.length === 0) {
      await fetchCategories();
    }
  }
});

async function fetchCategories() {
  try {
    const res = await apiFetch('/expense-categories?active_only=1');
    categories.value = Array.isArray(res) ? res : [];
  } catch (err) {
    console.warn('No se pudieron cargar las categorías contables:', err);
  }
}

function resetForm() {
  form.value = {
    type: 'expense',
    payment_method: 'cash',
    amount: '',
    description: '',
    expense_category_id: null,
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
      type:                form.value.type,
      payment_method:      form.value.payment_method,
      amount:              parseFloat(form.value.amount),
      description:         form.value.description.trim(),
      expense_category_id: form.value.type === 'expense' ? (form.value.expense_category_id || null) : null,
    }, props.sessionId || null);
    const methodLabel = form.value.payment_method === 'cash' ? 'en efectivo' : (form.value.payment_method === 'qr' ? 'por QR' : 'con tarjeta');
    successMsg.value = form.value.type === 'expense'
      ? `Gasto de Bs ${parseFloat(form.value.amount).toFixed(2)} ${methodLabel} registrado.`
      : `Ingreso de Bs ${parseFloat(form.value.amount).toFixed(2)} ${methodLabel} registrado.`;
    emit('saved');
    setTimeout(() => {
      if (successMsg.value) handleClose();
    }, 1200);
  } catch (err) {
    errorMsg.value = err?.message || 'No se pudo registrar el movimiento. Revisa la conexión y vuelve a intentar.';
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
    <!-- Mismas piezas que «Anotar merma»: las ventanas de Caja se ven y se usan igual -->
    <div v-if="visible" class="fin-page ce-host">
      <FinanceModal
        :title="form.type === 'expense' ? 'Registrar gasto' : 'Registrar ingreso'"
        subtitle="Dinero que entra o sale durante el turno, fuera de las ventas."
        :busy="isSaving"
        @close="handleClose"
      >
        <div class="ce-seg" role="radiogroup" aria-label="Tipo de movimiento">
          <button
            type="button"
            role="radio"
            class="ce-seg-btn"
            :class="{ active: form.type === 'expense' }"
            :aria-checked="form.type === 'expense'"
            @click="form.type = 'expense'"
          >Gasto: sale dinero</button>
          <button
            type="button"
            role="radio"
            class="ce-seg-btn"
            :class="{ active: form.type === 'extra_income' }"
            :aria-checked="form.type === 'extra_income'"
            @click="form.type = 'extra_income'"
          >Ingreso: entra dinero</button>
        </div>

        <div class="fin-field">
          <span id="ce-method-label" class="fin-label">{{ form.type === 'expense' ? '¿Con qué se pagó?' : '¿Cómo llegó?' }}</span>
          <div class="fin-chips" role="radiogroup" aria-labelledby="ce-method-label">
            <button
              v-for="m in [{ v: 'cash', l: 'Efectivo de la gaveta' }, { v: 'qr', l: 'QR o banco' }, { v: 'card', l: 'Tarjeta' }]"
              :key="m.v"
              type="button"
              role="radio"
              class="fin-chip ce-chip"
              :class="{ active: form.payment_method === m.v }"
              :aria-checked="form.payment_method === m.v"
              @click="form.payment_method = m.v"
            >{{ m.l }}</button>
          </div>
          <p v-if="form.payment_method !== 'cash'" class="fin-hint">
            Con {{ form.payment_method === 'qr' ? 'QR' : 'tarjeta' }} queda en el control diario, pero no cambia el efectivo de la gaveta.
          </p>
        </div>

        <div class="fin-grid-2 ce-amount-row">
          <div class="fin-field">
            <label for="ce-amount">Monto</label>
            <span class="ce-money-input" :class="{ invalid: isOverdrawn }">
              <span aria-hidden="true">Bs</span>
              <input
                id="ce-amount"
                v-model="form.amount"
                type="number"
                min="0.01"
                step="0.01"
                inputmode="decimal"
                placeholder="0.00"
                @keyup.enter="handleSubmit"
              />
            </span>
          </div>
          <dl class="ce-drawer">
            <div>
              <dt>En la gaveta ahora</dt>
              <dd>Bs {{ currentCash.toFixed(2) }}</dd>
            </div>
            <div v-if="parseFloat(form.amount) > 0 && form.payment_method === 'cash'">
              <dt>Quedaría</dt>
              <dd :class="{ 'ce-neg': isOverdrawn }">Bs {{ projectedCash.toFixed(2) }}</dd>
            </div>
          </dl>
        </div>
        <div v-if="isOverdrawn" class="fin-note err" role="alert">
          El gasto es mayor que el efectivo de la gaveta. Revisa el monto o elige otro medio de pago.
        </div>

        <div v-if="form.type === 'expense'" class="fin-field">
          <label for="ce-category">Categoría para Finanzas</label>
          <select id="ce-category" v-model="form.expense_category_id" class="fin-input">
            <option :value="null">No está en la lista (descríbelo abajo)</option>
            <option v-for="cat in categories" :key="cat.id" :value="cat.id">
              {{ cat.name }} ({{ cat.group_name || cat.section }})
            </option>
          </select>
          <p v-if="!form.expense_category_id" class="fin-hint">El dueño lo clasifica después en Finanzas: describe bien qué se compró o pagó.</p>
        </div>

        <div class="fin-field">
          <label for="ce-description">Descripción</label>
          <textarea
            id="ce-description"
            v-model="form.description"
            rows="2"
            maxlength="500"
            class="fin-input ce-textarea"
            :placeholder="form.type === 'expense' ? 'Ej: 2 kg de frutilla en el mercado' : 'Ej: el dueño dejó cambio en monedas'"
          ></textarea>
          <p class="fin-hint ce-count">{{ form.description.length }} de 500. Mínimo 3 letras.</p>
        </div>

        <div v-if="errorMsg" class="fin-note err" role="alert">{{ errorMsg }}</div>
        <div v-if="successMsg" class="fin-note info" role="status">{{ successMsg }}</div>

        <template #footer>
          <button class="fin-btn fin-btn-ghost fin-btn-lg" type="button" :disabled="isSaving" @click="handleClose">Cancelar</button>
          <button class="fin-btn fin-btn-primary fin-btn-lg" type="button" :disabled="!canSubmit" @click="handleSubmit">
            <template v-if="isSaving">Guardando…</template>
            <template v-else>{{ form.type === 'expense' ? 'Registrar gasto' : 'Registrar ingreso' }}</template>
          </button>
        </template>
      </FinanceModal>
    </div>
  </Teleport>
</template>

<style scoped>
.ce-host { display: contents; }
.ce-seg {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4px;
  padding: 4px;
  border-radius: 12px;
  background: var(--cream-200);
}
.ce-seg-btn {
  min-height: 38px;
  border: none;
  border-radius: 9px;
  background: transparent;
  color: var(--ink-700);
  font-family: inherit;
  font-size: 13.5px;
  font-weight: 500;
  cursor: pointer;
}
.ce-seg-btn.active { background: var(--acai-700); color: #FFFFFF; font-weight: 700; }
html.dark .ce-seg-btn.active { background: var(--acai-500); }
.ce-seg-btn:focus-visible,
.ce-chip:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; }
.ce-chip { min-height: 34px; padding: 0 13px; font-size: 13px; }
.ce-amount-row { align-items: end; }
.ce-money-input {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 46px;
  padding: 0 12px;
  border: 1.5px solid var(--border);
  border-radius: 11px;
  background: var(--surface);
}
.ce-money-input:focus-within { border-color: var(--acai-500); box-shadow: 0 0 0 3px var(--acai-50); }
.ce-money-input.invalid { border-color: var(--danger-500); }
.ce-money-input > span { font-weight: 600; color: var(--ink-500); }
.ce-money-input input {
  width: 100%;
  min-width: 0;
  border: none;
  outline: none;
  background: transparent;
  color: var(--ink-900);
  font-family: var(--font-heading);
  font-size: 22px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.ce-drawer { margin: 0; display: flex; gap: 18px; padding-bottom: 6px; }
.ce-drawer dt { font-size: 12px; color: var(--ink-500); }
.ce-drawer dd { margin: 2px 0 0; font-size: 16px; font-weight: 700; color: var(--ink-900); font-variant-numeric: tabular-nums; }
.ce-drawer dd.ce-neg { color: var(--danger-text); }
.ce-textarea { resize: vertical; min-height: 56px; }
.ce-count { margin: 0; text-align: right; }
@media (max-width: 520px) {
  .ce-amount-row { grid-template-columns: 1fr; }
}
</style>
