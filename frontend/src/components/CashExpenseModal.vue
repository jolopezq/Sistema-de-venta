<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { useTurnoStore } from '../stores/turno';
import { apiFetch } from '../services/api';
import FinanceModal from './Finance/FinanceModal.vue';
import { closingPreview, signedBs } from '../utils/closedCorrection';
import { cashPart, methodDetail } from '../utils/cashMovementMethod';
import './Finance/finance.css';

/**
 * Modal de registro o corrección de egreso/ingreso extraordinario de efectivo.
 * Accesible para cualquier usuario con turno activo abierto (o administradores).
 * Al guardar, actualiza las stats de la gaveta en tiempo real.
 */
const props = defineProps({
  visible:      { type: Boolean, default: false },
  /**
   * ID de sesión de caja específica.
   * Si se pasa, el movimiento se registra o corrige en esa sesión.
   * Si no, se usa la sesión activa del store.
   */
  sessionId:    { type: [String, Number], default: null },
  /**
   * Efectivo esperado en gaveta para la sesión específica (p. ej. desde Control Diario).
   * Si se pasa, tiene prioridad sobre el turno activo del store.
   */
  expectedCash: { type: Number, default: null },
  /**
   * Movimiento a corregir (modo edición). Si es null, opera en modo registro nuevo.
   */
  movement:     { type: Object, default: null },
  /**
   * Caja cerrada a la que pertenece el movimiento (Control diario, con «Corregir cajas cerradas»).
   * Al guardar, el servidor rehace el cierre con el mismo conteo (docs/PLAN_CORREGIR_GASTOS_CAJA.md §3.8).
   */
  closedSession: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);

const turnoStore = useTurnoStore();

const isSaving   = ref(false);
const errorMsg   = ref('');
const successMsg = ref('');
const categories = ref([]);
// Adelanto de sueldo: a quién se le dio (lo resta la planilla; docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.6)
const people = ref([]);

const isEditing = computed(() => Boolean(props.movement?.id));
const isClosedCorrection = computed(() => isEditing.value && Boolean(props.closedSession));

const form = ref({
  type: 'expense',
  payment_method: 'cash',
  amount: '',
  // Gasto dividido (payment_method 'mixed'): lo que salió de la gaveta; el resto, por QR o banco
  cash_amount: '',
  description: '',
  expense_category_id: null,
  employee_id: null,
  reason: '',
});
const cashAmountInput = ref(null);
/** ¿La categoría es de sueldos? Entonces se puede indicar a quién se le dio el adelanto. */
const isSalaryCategory = computed(() => {
  const cat = categories.value.find((c) => c.id === form.value.expense_category_id);
  return form.value.type === 'expense' && Boolean(cat) && (cat.group_name === 'Personal' || /salario|sueldo/i.test(cat.name || ''));
});

// ── Gasto dividido entre efectivo y QR (docs/PLAN_GASTO_PAGO_DIVIDIDO.md) ──
const isSplit = computed(() => form.value.payment_method === 'mixed');
const canSplit = computed(() => form.value.type === 'expense');
/** El movimiento tal como quedaría con lo escrito en el formulario. */
const draft = computed(() => ({
  type: form.value.type,
  payment_method: form.value.payment_method,
  amount: parseFloat(form.value.amount) || 0,
  cash_amount: isSplit.value ? (parseFloat(form.value.cash_amount) || 0) : null,
}));
const qrPart = computed(() => Math.max(0, Math.round(((parseFloat(form.value.amount) || 0) - (parseFloat(form.value.cash_amount) || 0)) * 100) / 100));
const splitIssue = computed(() => {
  if (!isSplit.value) return '';
  const total = parseFloat(form.value.amount) || 0;
  const cash = parseFloat(form.value.cash_amount);
  if (total <= 0) return 'Escribe primero el total del gasto.';
  if (!(cash > 0) || cash > Math.round((total - 0.01) * 100) / 100) {
    return 'La parte en efectivo tiene que ser mayor que 0 y menor que el total.';
  }
  return '';
});

function startSplit() {
  form.value.payment_method = 'mixed';
  form.value.cash_amount = '';
  nextTick(() => cashAmountInput.value?.focus());
}

function stopSplit() {
  form.value.payment_method = 'cash';
  form.value.cash_amount = '';
}

// Un ingreso no se divide
watch(() => form.value.type, (t) => {
  if (t !== 'expense' && isSplit.value) stopSplit();
});

// Saldo actual en gaveta antes de este movimiento
const currentCash = computed(() => {
  if (props.expectedCash !== null && props.expectedCash !== undefined) {
    return Number(props.expectedCash);
  }
  return turnoStore.currentCashInDrawer;
});

// Saldo base para el cálculo disponible: si estamos corrigiendo un movimiento, devolvemos a la gaveta su parte en
// efectivo (todo si era en efectivo, la parte en efectivo si estaba dividido) para no generar falsos sobregiros.
const baseCash = computed(() => {
  let cash = currentCash.value;
  if (isEditing.value && props.movement) {
    const orig = cashPart(props.movement);
    cash += props.movement.type === 'extra_income' ? -orig : orig;
  }
  return cash;
});

/** Lo que el movimiento saca de la gaveta (o mete, si es ingreso): solo su parte en efectivo. */
const draftCash = computed(() => cashPart(draft.value));

// Saldo proyectado después del movimiento
const projectedCash = computed(() => {
  const base = isEditing.value ? baseCash.value : currentCash.value;
  return form.value.type === 'expense' ? base - draftCash.value : base + draftCash.value;
});

// En una caja cerrada el efectivo ya se contó: no hay gaveta que pueda quedar en negativo.
// En un gasto dividido solo cuenta la parte en efectivo.
const isOverdrawn = computed(() =>
  !isClosedCorrection.value &&
  !splitIssue.value &&
  form.value.type === 'expense' &&
  draftCash.value > 0 &&
  projectedCash.value < 0
);

// Caja cerrada: cómo quedan el teórico y la diferencia del cierre con el mismo efectivo contado
const closing = computed(() => {
  if (!isClosedCorrection.value) return null;
  return closingPreview(props.closedSession, props.movement, draft.value);
});

// Verifica si hubo algún cambio real respecto al original
const hasChanged = computed(() => {
  if (!isEditing.value || !props.movement) return true;
  const m = props.movement;
  const amt = parseFloat(form.value.amount) || 0;
  const origAmt = parseFloat(m.amount) || 0;
  const amountDiff = Math.abs(amt - origAmt) > 0.004;
  const methodDiff = (m.payment_method || 'cash') !== form.value.payment_method
    || (isSplit.value && Math.abs((parseFloat(m.cash_amount) || 0) - (parseFloat(form.value.cash_amount) || 0)) > 0.004);
  const descDiff   = (m.description || '').trim() !== form.value.description.trim();
  const catDiff    = (m.expense_category_id || null) !== (form.value.expense_category_id || null);
  const personDiff = (m.employee_id || null) !== (isSalaryCategory.value ? (form.value.employee_id || null) : null);
  return amountDiff || methodDiff || descDiff || catDiff || personDiff;
});

const canSubmit = computed(() => {
  const amt = parseFloat(form.value.amount);
  if (isSaving.value || isOverdrawn.value || isNaN(amt) || amt <= 0 || splitIssue.value) return false;
  if (form.value.description.trim().length < 3) return false;
  if (isEditing.value) {
    return hasChanged.value && form.value.reason.trim().length >= 10;
  }
  return true;
});

watch(() => props.visible, async (v) => {
  if (v) {
    resetForm();
    if (categories.value.length === 0) {
      await fetchCategories();
    }
    if (people.value.length === 0) fetchPeople();
  }
});

watch(() => props.movement, () => {
  if (props.visible) {
    resetForm();
  }
});

async function fetchPeople() {
  try {
    people.value = (await apiFetch('/attendance/people'))?.data ?? [];
  } catch {
    people.value = [];
  }
}

async function fetchCategories() {
  try {
    const res = await apiFetch('/expense-categories?active_only=1');
    categories.value = Array.isArray(res) ? res : [];
  } catch (err) {
    console.warn('No se pudieron cargar las categorías contables:', err);
  }
}

function resetForm() {
  if (props.movement) {
    form.value = {
      type: props.movement.type || 'expense',
      payment_method: props.movement.payment_method || 'cash',
      amount: String(props.movement.amount ?? ''),
      cash_amount: props.movement.payment_method === 'mixed' ? String(Number(props.movement.cash_amount ?? 0)) : '',
      description: props.movement.description || '',
      expense_category_id: props.movement.expense_category_id || null,
      employee_id: props.movement.employee_id || null,
      reason: '',
    };
  } else {
    form.value = {
      type: 'expense',
      payment_method: 'cash',
      amount: '',
      cash_amount: '',
      description: '',
      expense_category_id: null,
      employee_id: null,
      reason: '',
    };
  }
  errorMsg.value   = '';
  successMsg.value = '';
}

async function handleSubmit() {
  if (!canSubmit.value) return;
  isSaving.value = true;
  errorMsg.value  = '';
  successMsg.value = '';
  try {
    const sId = props.sessionId || props.movement?.cash_register_session_id || null;
    const amtNumber = parseFloat(form.value.amount);
    const methodLabel = isSplit.value
      ? `dividido (${methodDetail(draft.value)})`
      : (form.value.payment_method === 'cash' ? 'en efectivo' : (form.value.payment_method === 'qr' ? 'por QR' : 'con tarjeta'));
    // Gasto dividido: la parte en efectivo; en otro método no se envía
    const split = isSplit.value ? { cash_amount: parseFloat(form.value.cash_amount) } : {};

    if (isEditing.value && props.movement?.id) {
      await turnoStore.updateCashMovement(sId, props.movement.id, {
        reason:              form.value.reason.trim(),
        payment_method:      form.value.payment_method,
        ...split,
        amount:              amtNumber,
        description:         form.value.description.trim(),
        expense_category_id: form.value.type === 'expense' ? (form.value.expense_category_id || null) : null,
        employee_id:         isSalaryCategory.value ? (form.value.employee_id || null) : null,
      });
      successMsg.value = form.value.type === 'expense'
        ? `Gasto corregido a Bs ${amtNumber.toFixed(2)} ${methodLabel}.`
        : `Ingreso corregido a Bs ${amtNumber.toFixed(2)} ${methodLabel}.`;
    } else {
      await turnoStore.recordCashMovement({
        type:                form.value.type,
        payment_method:      form.value.payment_method,
        ...split,
        amount:              amtNumber,
        description:         form.value.description.trim(),
        expense_category_id: form.value.type === 'expense' ? (form.value.expense_category_id || null) : null,
        employee_id:         isSalaryCategory.value ? (form.value.employee_id || null) : null,
      }, sId);
      successMsg.value = form.value.type === 'expense'
        ? `Gasto de Bs ${amtNumber.toFixed(2)} ${methodLabel} registrado.`
        : `Ingreso de Bs ${amtNumber.toFixed(2)} ${methodLabel} registrado.`;
    }

    emit('saved');
    setTimeout(() => {
      if (successMsg.value) handleClose();
    }, 1200);
  } catch (err) {
    errorMsg.value = err?.message || 'No se pudo guardar el movimiento. Revisa la conexión y vuelve a intentar.';
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
        :title="isEditing ? (form.type === 'expense' ? 'Corregir gasto' : 'Corregir ingreso') : (form.type === 'expense' ? 'Registrar gasto' : 'Registrar ingreso')"
        :subtitle="isClosedCorrection ? 'La caja ya está cerrada: al guardar, el cierre se rehace con el mismo conteo.' : (isEditing ? 'Modifica el método de pago, monto o detalle.' : 'Dinero que entra o sale durante el turno, fuera de las ventas.')"
        :busy="isSaving"
        @close="handleClose"
      >
        <!-- Tipo de movimiento: bloqueado en modo edición (D6) -->
        <div v-if="isEditing" class="ce-locked-banner" role="status">
          <span class="ce-locked-pill" :class="form.type === 'expense' ? 'expense' : 'income'">
            {{ form.type === 'expense' ? 'Egreso de dinero' : 'Ingreso de dinero' }}
          </span>
          <span class="ce-locked-text">El tipo no se puede cambiar. Si era un {{ form.type === 'expense' ? 'ingreso' : 'gasto' }}, anúlalo y regístralo de nuevo.</span>
        </div>
        <div v-else class="ce-seg" role="radiogroup" aria-label="Tipo de movimiento">
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
          <!-- Gasto dividido: un solo gasto, una parte con efectivo de la gaveta y el resto por QR o banco -->
          <div v-if="isSplit" class="ce-split-method">
            <span class="ce-split-pill">Efectivo de la gaveta + QR o banco</span>
            <button type="button" class="ce-link" @click="stopSplit">Quitar división</button>
          </div>
          <div v-else class="fin-chips" role="radiogroup" aria-labelledby="ce-method-label">
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
          <p v-if="!isSplit && form.payment_method !== 'cash'" class="fin-hint">
            Con {{ form.payment_method === 'qr' ? 'QR' : 'tarjeta' }} queda en el control diario, pero no cambia el efectivo de la gaveta.
          </p>
          <!-- Vista previa de gaveta al cambiar de método en modo corrección (plan 3.5) -->
          <div v-if="isEditing && !isClosedCorrection && (movement?.payment_method || 'cash') !== form.payment_method" class="ce-preview-note">
            <span class="ce-preview-icon" aria-hidden="true">💡</span>
            <span>La gaveta pasa de <strong>Bs {{ currentCash.toFixed(2) }}</strong> a <strong>Bs {{ projectedCash.toFixed(2) }}</strong>.</span>
          </div>
        </div>

        <div class="fin-grid-2 ce-amount-row">
          <div class="fin-field">
            <label for="ce-amount">{{ isSplit ? 'Total del gasto' : 'Monto' }}</label>
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
          <dl v-if="!isClosedCorrection" class="ce-drawer">
            <div>
              <dt>En la gaveta ahora</dt>
              <dd>Bs {{ currentCash.toFixed(2) }}</dd>
            </div>
            <div v-if="draftCash > 0 && !splitIssue">
              <dt>Quedaría</dt>
              <dd :class="{ 'ce-neg': isOverdrawn }">Bs {{ projectedCash.toFixed(2) }}</dd>
            </div>
          </dl>
        </div>
        <button v-if="canSplit && !isSplit" type="button" class="ce-link ce-split-link" @click="startSplit">
          Dividir pago (efectivo + QR)
        </button>
        <div v-if="isSplit" class="ce-split">
          <div class="fin-grid-2 ce-amount-row">
            <div class="fin-field">
              <label for="ce-cash-amount">De eso, salió de la gaveta</label>
              <span class="ce-money-input ce-money-sm" :class="{ invalid: splitIssue && form.cash_amount !== '' }">
                <span aria-hidden="true">Bs</span>
                <input
                  id="ce-cash-amount"
                  ref="cashAmountInput"
                  v-model="form.cash_amount"
                  type="number"
                  min="0.01"
                  step="0.01"
                  inputmode="decimal"
                  placeholder="0.00"
                  aria-describedby="ce-split-help"
                  @keyup.enter="handleSubmit"
                />
              </span>
            </div>
            <dl class="ce-drawer ce-split-rest">
              <div>
                <dt>Por QR o banco</dt>
                <dd data-testid="ce-split-qr">Bs {{ qrPart.toFixed(2) }}</dd>
              </div>
            </dl>
          </div>
          <p
            id="ce-split-help"
            class="fin-hint ce-split-help"
            :class="{ 'ce-split-err': splitIssue && form.cash_amount !== '' }"
            :role="splitIssue && form.cash_amount !== '' ? 'alert' : undefined"
          >{{ splitIssue && form.cash_amount !== '' ? splitIssue : 'Solo la parte en efectivo sale de la gaveta; el resto queda como pagado por QR o banco.' }}</p>
        </div>
        <!-- Caja cerrada: el cierre se rehace con el mismo efectivo contado -->
        <div v-if="closing" class="ce-closed-note" role="status" aria-live="polite">
          <p class="ce-closed-title">Esta caja ya está cerrada.</p>
          <p class="ce-closed-text">
            Al guardar, el cierre se rehace con el mismo efectivo contado (Bs {{ closing.actual.toFixed(2) }}).
            El cierre anterior queda en el historial de la caja.
          </p>
          <dl class="ce-closed-grid">
            <div>
              <dt>Teórico</dt>
              <dd>Bs {{ closing.expectedBefore.toFixed(2) }} → <strong>Bs {{ closing.expectedAfter.toFixed(2) }}</strong></dd>
            </div>
            <div>
              <dt>Diferencia</dt>
              <dd>{{ signedBs(closing.diffBefore) }} → <strong>{{ signedBs(closing.diffAfter) }}</strong></dd>
            </div>
          </dl>
        </div>

        <div v-if="isOverdrawn" class="fin-note err" role="alert">
          <template v-if="isSplit">La parte en efectivo es mayor que lo que hay en la gaveta. Revisa el desglose.</template>
          <template v-else>El gasto es mayor que el efectivo de la gaveta. Revisa el monto o elige otro medio de pago.</template>
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

        <div v-if="isSalaryCategory && people.length" class="fin-field">
          <label for="ce-person">¿Es un adelanto de sueldo? ¿Para quién?</label>
          <select id="ce-person" v-model="form.employee_id" class="fin-input">
            <option :value="null">No es un adelanto</option>
            <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
          <p v-if="form.employee_id" class="fin-hint">Se resta de su sueldo en la planilla del mes.</p>
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

        <!-- Campo obligatorio de motivo de corrección (plan 3.5) -->
        <div v-if="isEditing" class="fin-field">
          <label for="ce-reason">Motivo de la corrección <span class="text-danger">*</span></label>
          <input
            id="ce-reason"
            v-model="form.reason"
            type="text"
            maxlength="255"
            class="fin-input"
            placeholder="Ej: Se pagó en efectivo de la gaveta, error de dedo al registrar"
          />
          <p class="fin-hint ce-count">
            {{ form.reason.trim().length }} de 255. Mínimo 10 letras (obligatorio para auditoría).
          </p>
        </div>

        <div v-if="isEditing && !hasChanged" class="fin-note info" role="status">
          Modifica algún campo (método, desglose, monto o descripción) para poder guardar la corrección.
        </div>

        <div v-if="errorMsg" class="fin-note err" role="alert">{{ errorMsg }}</div>
        <div v-if="successMsg" class="fin-note info" role="status">{{ successMsg }}</div>

        <template #footer>
          <button class="fin-btn fin-btn-ghost fin-btn-lg" type="button" :disabled="isSaving" @click="handleClose">Cancelar</button>
          <button class="fin-btn fin-btn-primary fin-btn-lg" type="button" :disabled="!canSubmit" @click="handleSubmit">
            <template v-if="isSaving">Guardando…</template>
            <template v-else-if="isEditing">Guardar corrección</template>
            <template v-else>{{ form.type === 'expense' ? 'Registrar gasto' : 'Registrar ingreso' }}</template>
          </button>
        </template>
      </FinanceModal>
    </div>
  </Teleport>
</template>

<style scoped>
.ce-host { display: contents; }
/* Corrección en caja cerrada (docs/PLAN_CORREGIR_GASTOS_CAJA.md §3.8) */
.ce-closed-note {
  padding: 10px 12px;
  border-radius: 11px;
  background: var(--acai-50);
  color: var(--ink-700);
  font-size: 13px;
  line-height: 1.4;
}
.ce-closed-title { margin: 0 0 2px; font-weight: 700; color: var(--acai-text); }
.ce-closed-text { margin: 0; }
.ce-closed-grid { display: flex; flex-wrap: wrap; gap: 4px 22px; margin: 6px 0 0; }
.ce-closed-grid div { display: flex; flex-direction: column; }
.ce-closed-grid dt { font-size: 12px; color: var(--ink-500); }
.ce-closed-grid dd { margin: 0; font-variant-numeric: tabular-nums; color: var(--ink-900); }
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

/* Modo edición: banner con tipo bloqueado */
.ce-locked-banner {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: var(--surface-hover);
  border: 1px solid var(--border);
  border-radius: 10px;
}
.ce-locked-pill {
  font-size: 12px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 6px;
  white-space: nowrap;
}
.ce-locked-pill.expense { background: var(--danger-100); color: var(--danger-text); }
.ce-locked-pill.income { background: #D1FAE5; color: #065F46; }
.ce-locked-text {
  font-size: 12px;
  color: var(--ink-500);
  line-height: 1.35;
}
.ce-preview-note {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 8px;
  padding: 7px 11px;
  background: var(--acai-50);
  border: 1px solid var(--acai-300);
  border-radius: 8px;
  font-size: 13px;
  color: var(--acai-text);
}
html.dark .ce-preview-note {
  background: rgba(116, 72, 166, 0.15);
  border-color: var(--acai-500);
  color: var(--acai-300);
}
.ce-preview-icon { font-size: 14px; }

/* Gasto dividido (docs/PLAN_GASTO_PAGO_DIVIDIDO.md §3.4) */
.ce-link {
  align-self: flex-start;
  padding: 2px 0;
  border: none;
  background: none;
  color: var(--acai-text);
  font-family: inherit;
  font-size: 13px;
  font-weight: 600;
  text-decoration: underline;
  text-underline-offset: 3px;
  cursor: pointer;
}
html.dark .ce-link { color: var(--acai-300); }
.ce-link:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; border-radius: 4px; }
.ce-split-link { margin-top: -6px; }
.ce-split-method { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px; }
.ce-split-pill {
  display: inline-flex;
  align-items: center;
  min-height: 34px;
  padding: 0 13px;
  border-radius: 999px;
  background: var(--acai-700);
  color: #FFFFFF;
  font-size: 13px;
  font-weight: 700;
}
html.dark .ce-split-pill { background: var(--acai-500); }
.ce-split { display: flex; flex-direction: column; gap: 4px; }
.ce-money-sm { min-height: 42px; }
.ce-money-sm input { font-size: 19px; }
.ce-split-help { margin: 0; }
.ce-split-err { color: var(--danger-text); font-weight: 600; }

@media (max-width: 520px) {
  .ce-amount-row { grid-template-columns: 1fr; }
}
</style>
