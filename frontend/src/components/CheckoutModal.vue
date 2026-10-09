<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';

/**
 * Cobro de una venta. Lo memorable es el vuelto: arriba el total a cobrar y, apenas se marca lo recibido,
 * el vuelto en grande. Pensado para la laptop del local (1366×768): el botón de cobro queda siempre a la vista.
 */
const props = defineProps({
  show: Boolean,
  total: Number,
  isTakeawayDefault: Boolean,
  destinationDefault: { type: String, default: 'dine_in' }, // 'dine_in' | 'takeaway' | 'mixed'
  takeawayCount: { type: Number, default: 0 },
  dineInCount: { type: Number, default: 0 },
  itemsCount: { type: Number, default: 0 },
  /** 'pedidosya': lo cobra la plataforma (un solo pago, sin cliente ni cambio) */
  channel: { type: String, default: 'pos' }
});

const isPedidosYa = computed(() => props.channel === 'pedidosya');
const pedidosYaOrderId = ref('');

const emit = defineEmits(['close', 'confirm']);

const METHODS = [
  { value: 'cash', label: 'Efectivo' },
  { value: 'qr', label: 'QR' },
  { value: 'card', label: 'Tarjeta' },
];
const methodLabel = (m) => METHODS.find((x) => x.value === m)?.label || m;

// El cobro todavía no busca clientes: la venta se registra sin cliente, como hasta ahora
const selectedCustomerId = ref(null);
const isSubmitting = ref(false);
const orderDestination = ref('dine_in'); // 'dine_in' | 'takeaway' | 'mixed'
const currentMethod = ref('cash');
const cashReceived = ref(0);
const payments = ref([]);
const saleNote = ref('');
const isCashManuallyEntered = ref(false);
const receivedInput = ref(null);

const hasMixedCapability = computed(() => {
  return props.destinationDefault === 'mixed' || (props.takeawayCount > 0 && props.dineInCount > 0);
});

function selectMainMethod(method) {
  currentMethod.value = method;
  if (payments.value.length === 1) {
    payments.value[0].method = method;
  }
  if (method === 'cash') focusReceived();
}

watch(() => props.show, (newVal) => {
  if (newVal) {
    selectedCustomerId.value = null;
    orderDestination.value = isPedidosYa.value
      ? 'takeaway'
      : props.destinationDefault || (props.isTakeawayDefault ? 'takeaway' : 'dine_in');
    pedidosYaOrderId.value = '';
    currentMethod.value = 'cash';
    saleNote.value = '';
    payments.value = [{ method: 'cash', amount: props.total }];
    isCashManuallyEntered.value = false;
    cashReceived.value = props.total;
    isSubmitting.value = false;
    if (!isPedidosYa.value) focusReceived();
  }
});

const totalPaid = computed(() => payments.value.reduce((s, p) => s + Number(p.amount), 0));
const remaining = computed(() => Math.max(0, props.total - totalPaid.value));
const hasCash = computed(() => payments.value.some((p) => p.method === 'cash'));
const splitMode = computed(() => payments.value.length > 1);

const change = computed(() => {
  let totalChange = 0;

  if (totalPaid.value > props.total) {
    totalChange += (totalPaid.value - props.total);
  }

  const cashPayment = payments.value.find(p => p.method === 'cash');
  if (cashPayment) {
    const cashAmount = Number(cashPayment.amount);
    const received = Number(cashReceived.value);
    if (received > cashAmount) {
      totalChange += (received - cashAmount);
    }
  }

  return totalChange;
});

/** Lo recibido en efectivo no alcanza para la parte en efectivo (p. ej. escribió 40 para un total de 44). */
const cashShort = computed(() => {
  const cashPayment = payments.value.find(p => p.method === 'cash');
  if (!cashPayment || !isCashManuallyEntered.value) return 0;
  return Math.max(0, Number(cashPayment.amount) - Number(cashReceived.value || 0));
});

const isReady = computed(() => isPedidosYa.value || (totalPaid.value >= props.total && cashShort.value === 0));

/** Estado que se muestra bajo lo recibido: falta, vuelto, pago justo o qué hacer con QR / tarjeta. */
const resultState = computed(() => {
  if (remaining.value > 0.004) return 'short';
  if (cashShort.value > 0.004) return 'cash-short';
  if (change.value > 0.004) return 'change';
  if (hasCash.value) return 'exact';
  return payments.value[0]?.method === 'qr' ? 'qr' : 'card';
});

function addPayment() {
  const used = payments.value.map(p => p.method);
  const nextMethod = ['cash', 'qr', 'card'].find(m => !used.includes(m)) || currentMethod.value;
  payments.value.push({
    method: nextMethod,
    amount: remaining.value > 0 ? Number(remaining.value.toFixed(2)) : 0
  });
}

/** Dividir: la primera parte queda en el método elegido y la segunda arranca en 0 para escribirla. */
function startSplit() {
  const used = payments.value.map(p => p.method);
  const nextMethod = ['qr', 'cash', 'card'].find(m => !used.includes(m));
  payments.value.push({ method: nextMethod, amount: 0 });
}

function removePayment(index) {
  payments.value.splice(index, 1);
  if (payments.value.length === 0) {
    payments.value.push({ method: currentMethod.value, amount: props.total });
  }
  if (payments.value.length === 1) {
    // De vuelta a un solo método: cubre el total completo
    payments.value[0].amount = props.total;
    currentMethod.value = payments.value[0].method;
  }
}

function handleConfirm() {
  if (!isReady.value || isSubmitting.value) return;
  isSubmitting.value = true;

  if (isPedidosYa.value) {
    // El Nº de pedido de la tablet queda en la nota para ubicarlo en cocina y despacho
    const orderId = pedidosYaOrderId.value.trim();
    const note = [orderId ? `PedidosYa #${orderId.replace(/^#/, '')}` : '', saleNote.value.trim()]
      .filter(Boolean).join(' · ');
    emit('confirm', {
      customerId: null,
      saleNote: note,
      destination: 'takeaway',
      isTakeaway: true,
      payments: [{ method: 'delivery_platform', amount: props.total, received: props.total }],
      change: 0
    });
    return;
  }

  // Si se registró más de lo cobrado, el exceso es cambio: se descuenta del efectivo
  // para que el arqueo no lo cuente como ingreso.
  let excess = Math.max(0, totalPaid.value - props.total);
  const finalPayments = payments.value.map(p => {
    let amount = Number(p.amount);
    if (p.method === 'cash' && excess > 0) {
      const deduction = Math.min(excess, amount);
      amount = Number((amount - deduction).toFixed(2));
      excess -= deduction;
    }
    return {
      method: p.method,
      amount,
      received: p.method === 'cash' ? Math.max(Number(cashReceived.value), Number(p.amount)) : amount
    };
  }).filter(p => p.amount > 0);

  emit('confirm', {
    customerId: selectedCustomerId.value,
    saleNote: saleNote.value,
    destination: orderDestination.value,
    isTakeaway: orderDestination.value === 'takeaway',
    payments: finalPayments,
    change: change.value
  });
}

const cashNeededAmount = computed(() => {
  const cashPayment = payments.value.find(p => p.method === 'cash');
  return cashPayment ? Number(cashPayment.amount) : 0;
});

watch(cashNeededAmount, (newVal) => {
  if (!isCashManuallyEntered.value || Number(cashReceived.value) < newVal) {
    cashReceived.value = newVal;
    isCashManuallyEntered.value = false;
  }
});

/** Billetes bolivianos que alcanzan para la parte en efectivo: los tres más chicos. */
const billSuggestions = computed(() => [10, 20, 50, 100, 200].filter((b) => b > cashNeededAmount.value + 0.004).slice(0, 3));

function setSuggestedCash(amount, isExact = false) {
  cashReceived.value = isExact ? cashNeededAmount.value : amount;
  isCashManuallyEntered.value = !isExact;
}

function onReceivedInput() {
  isCashManuallyEntered.value = true;
}

function focusReceived() {
  nextTick(() => {
    const el = receivedInput.value;
    if (el) { el.focus(); el.select(); }
  });
}

function close() {
  if (!isSubmitting.value) emit('close');
}

function onKey(e) {
  if (props.show && e.key === 'Escape') close();
}
watch(() => props.show, (v) => {
  if (v) window.addEventListener('keydown', onKey);
  else window.removeEventListener('keydown', onKey);
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

const money = (n) => `Bs ${Number(n || 0).toFixed(2)}`;
const itemsText = computed(() => (props.itemsCount === 1 ? '1 producto' : `${props.itemsCount} productos`));
const confirmLabel = computed(() => {
  if (isSubmitting.value) return 'Guardando…';
  if (isPedidosYa.value) return 'Registrar pedido';
  return `Cobrar ${money(props.total)}`;
});
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="co-overlay" @click.self="close">
      <div class="co-card" role="dialog" aria-modal="true" aria-labelledby="co-title">
        <div class="co-head">
          <h2 id="co-title">{{ isPedidosYa ? 'Registrar pedido de PedidosYa' : 'Cobrar' }}</h2>
          <button class="co-close" type="button" aria-label="Cerrar sin cobrar" :disabled="isSubmitting" @click="close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
          </button>
        </div>

        <div class="co-body">
          <div class="co-total" :class="{ pya: isPedidosYa }">
            <span class="co-total-label">{{ isPedidosYa ? 'Total PedidosYa' : 'Total a cobrar' }}</span>
            <strong class="co-total-amount">{{ money(total) }}</strong>
            <span class="co-total-sub">
              {{ itemsText }}<template v-if="isPedidosYa">. Lo cobra la plataforma: no entra al efectivo ni al arqueo.</template>
            </span>
          </div>

          <!-- PedidosYa: lo cobra la plataforma, no pasa por la caja -->
          <template v-if="isPedidosYa">
            <div class="co-row">
              <label class="co-row-label" for="co-pya-id">Nº de pedido</label>
              <input
                id="co-pya-id"
                v-model="pedidosYaOrderId"
                class="co-input"
                placeholder="Opcional, ej. 4521"
                inputmode="numeric"
                autocomplete="off"
                @keydown.enter.prevent="handleConfirm"
              />
            </div>
          </template>

          <template v-else>
            <!-- Cómo paga -->
            <div class="co-row">
              <span id="co-method-label" class="co-row-label">Cómo paga</span>
              <div class="co-row-control">
                <div v-if="!splitMode" class="co-seg" role="radiogroup" aria-labelledby="co-method-label">
                  <button
                    v-for="m in METHODS"
                    :key="m.value"
                    type="button"
                    role="radio"
                    class="co-seg-btn"
                    :class="{ active: payments[0]?.method === m.value }"
                    :aria-checked="payments[0]?.method === m.value"
                    @click="selectMainMethod(m.value)"
                  >{{ m.label }}</button>
                </div>
                <div v-else class="co-split">
                  <div v-for="(payment, index) in payments" :key="index" class="co-split-line">
                    <select v-model="payment.method" class="co-input co-split-method" :aria-label="`Método del pago ${index + 1}`">
                      <option v-for="m in METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                    <span class="co-money-input sm">
                      <span aria-hidden="true">Bs</span>
                      <input
                        v-model="payment.amount"
                        type="number"
                        min="0"
                        step="0.5"
                        inputmode="decimal"
                        :aria-label="`Monto del pago ${index + 1}`"
                        @focus="$event.target.select()"
                      />
                    </span>
                    <button type="button" class="co-icon-btn" :aria-label="`Quitar el pago ${index + 1}`" @click="removePayment(index)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                    </button>
                  </div>
                </div>
                <button v-if="!splitMode" type="button" class="co-link" @click="startSplit">Dividir el pago</button>
                <button v-else-if="payments.length < 3" type="button" class="co-link" @click="addPayment">Agregar otro método</button>
              </div>
            </div>

            <!-- Lo que entrega el cliente -->
            <div v-if="hasCash" class="co-row">
              <label class="co-row-label" for="co-received">Recibe</label>
              <div class="co-row-control">
                <div class="co-received">
                  <span class="co-money-input">
                    <span aria-hidden="true">Bs</span>
                    <input
                      id="co-received"
                      ref="receivedInput"
                      v-model="cashReceived"
                      type="number"
                      min="0"
                      step="0.5"
                      inputmode="decimal"
                      @input="onReceivedInput"
                      @focus="$event.target.select()"
                      @keydown.enter.prevent="handleConfirm"
                    />
                  </span>
                  <div class="co-bills" role="group" aria-label="Billete recibido">
                    <button
                      v-for="bill in billSuggestions"
                      :key="bill"
                      type="button"
                      class="co-bill"
                      :class="{ active: isCashManuallyEntered && Number(cashReceived) === bill }"
                      @click="setSuggestedCash(bill)"
                    >Bs {{ bill }}</button>
                    <button
                      type="button"
                      class="co-bill"
                      :class="{ active: !isCashManuallyEntered || Number(cashReceived) === cashNeededAmount }"
                      @click="setSuggestedCash(cashNeededAmount, true)"
                    >Justo</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Resultado: vuelto, falta o qué hacer -->
            <div class="co-result" :class="resultState" role="status" aria-live="polite">
              <template v-if="resultState === 'change'">
                <span class="co-result-label">Vuelto</span>
                <strong :key="change.toFixed(2)" class="co-result-amount">{{ money(change) }}</strong>
              </template>
              <template v-else-if="resultState === 'short'">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v6" /><path d="M12 16.5v.5" /></svg>
                <span>Falta cubrir <strong>{{ money(remaining) }}</strong>: completa los montos.</span>
              </template>
              <template v-else-if="resultState === 'cash-short'">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v6" /><path d="M12 16.5v.5" /></svg>
                <span>Lo recibido no alcanza: faltan <strong>{{ money(cashShort) }}</strong> en efectivo.</span>
              </template>
              <template v-else-if="resultState === 'exact'">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
                <span>Pago justo, sin vuelto.</span>
              </template>
              <template v-else-if="resultState === 'qr'">
                <span>Muestra el QR y cobra cuando veas el pago recibido.</span>
              </template>
              <template v-else>
                <span>Pasa la tarjeta y cobra cuando salga aprobada.</span>
              </template>
            </div>

            <!-- Destino del pedido -->
            <div class="co-row">
              <span id="co-dest-label" class="co-row-label">Se sirve</span>
              <div class="co-seg co-seg-sm" role="radiogroup" aria-labelledby="co-dest-label">
                <button
                  type="button"
                  role="radio"
                  class="co-seg-btn"
                  :class="{ active: orderDestination === 'dine_in' }"
                  :aria-checked="orderDestination === 'dine_in'"
                  @click="orderDestination = 'dine_in'"
                >En mesa</button>
                <button
                  type="button"
                  role="radio"
                  class="co-seg-btn"
                  :class="{ active: orderDestination === 'takeaway' }"
                  :aria-checked="orderDestination === 'takeaway'"
                  @click="orderDestination = 'takeaway'"
                >Para llevar</button>
                <button
                  v-if="hasMixedCapability"
                  type="button"
                  role="radio"
                  class="co-seg-btn"
                  :class="{ active: orderDestination === 'mixed' }"
                  :aria-checked="orderDestination === 'mixed'"
                  :title="`${dineInCount} en mesa y ${takeawayCount} para llevar`"
                  @click="orderDestination = 'mixed'"
                >Mixto</button>
              </div>
            </div>
          </template>

          <div class="co-row">
            <label class="co-row-label" for="co-note">Nota</label>
            <input
              id="co-note"
              v-model="saleNote"
              class="co-input"
              maxlength="250"
              placeholder="Opcional: el cliente espera afuera, sin hielo…"
              autocomplete="off"
            />
          </div>
        </div>

        <div class="co-foot">
          <button class="co-btn co-btn-ghost" type="button" :disabled="isSubmitting" @click="close">Cancelar</button>
          <button class="co-btn co-btn-primary" type="button" :disabled="!isReady || isSubmitting" @click="handleConfirm">
            {{ confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.co-overlay {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background: rgba(32, 17, 47, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.co-card {
  width: 100%;
  max-width: 520px;
  max-height: calc(100vh - 32px);
  display: flex;
  flex-direction: column;
  background: var(--surface);
  border-radius: 18px;
  box-shadow: var(--shadow-pop);
  overflow: hidden;
  color: var(--ink-900);
}
.co-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px 20px 0;
}
.co-head h2 { margin: 0; font-size: 20px; font-weight: 700; color: var(--ink-900); }
.co-close,
.co-icon-btn {
  width: 34px;
  height: 34px;
  border: none;
  border-radius: 9px;
  background: transparent;
  color: var(--ink-500);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.co-close:hover,
.co-icon-btn:hover { background: var(--cream-200); color: var(--ink-900); }
.co-close svg,
.co-icon-btn svg { width: 16px; height: 16px; }

/* El cuerpo es lo único que se desplaza: el botón de cobro nunca queda fuera de la vista */
.co-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 12px 20px 16px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

/* Total a cobrar */
.co-total {
  display: grid;
  grid-template-columns: 1fr auto;
  align-items: baseline;
  gap: 2px 12px;
  padding: 14px 16px;
  border-radius: 14px;
  background: var(--acai-50);
}
.co-total.pya { background: #FFE4EC; }
html.dark .co-total.pya { background: #3D1424; }
.co-total-label { font-size: 14px; font-weight: 600; color: var(--acai-text); }
.co-total.pya .co-total-label { color: #8A0030; }
html.dark .co-total.pya .co-total-label { color: #FFB3C9; }
.co-total-amount {
  grid-row: span 2;
  font-family: var(--font-heading);
  font-size: 34px;
  font-weight: 700;
  line-height: 1;
  color: var(--ink-900);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.co-total-sub { font-size: 12.5px; color: var(--ink-700); line-height: 1.4; }

/* Filas etiqueta | control */
.co-row {
  display: grid;
  grid-template-columns: 92px minmax(0, 1fr);
  align-items: start;
  gap: 10px;
}
.co-row-label {
  padding-top: 10px;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--ink-700);
}
.co-row-control { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; min-width: 0; }

/* Control segmentado: una sola opción marcada, con relleno morado y texto en peso mayor */
.co-seg {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 4px;
  padding: 4px;
  border-radius: 12px;
  background: var(--cream-200);
}
.co-seg-btn {
  min-height: 38px;
  padding: 0 16px;
  border: none;
  border-radius: 9px;
  background: transparent;
  color: var(--ink-700);
  font-family: inherit;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
}
.co-seg-btn:hover { color: var(--ink-900); }
.co-seg-btn.active { background: var(--acai-700); color: #FFFFFF; font-weight: 700; }
html.dark .co-seg-btn.active { background: var(--acai-500); }
.co-seg-sm .co-seg-btn { min-height: 34px; padding: 0 13px; font-size: 13.5px; }
.co-seg-btn:focus-visible,
.co-bill:focus-visible,
.co-link:focus-visible,
.co-close:focus-visible,
.co-icon-btn:focus-visible,
.co-btn:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; }

.co-link {
  border: none;
  background: none;
  padding: 2px 0;
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  color: var(--acai-text);
  text-decoration: underline;
  text-underline-offset: 2px;
  cursor: pointer;
}

/* Pago dividido */
.co-split { display: flex; flex-direction: column; gap: 6px; width: 100%; }
.co-split-line { display: flex; align-items: center; gap: 6px; }
.co-split-method { width: 120px; flex-shrink: 0; }

/* Montos en Bs */
.co-input {
  width: 100%;
  box-sizing: border-box;
  min-height: 40px;
  padding: 8px 11px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  color: var(--ink-900);
  font-family: inherit;
  font-size: 14px;
}
.co-input:focus { outline: none; border-color: var(--acai-500); box-shadow: 0 0 0 3px var(--acai-50); }
.co-money-input {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 46px;
  padding: 0 12px;
  border: 1.5px solid var(--border);
  border-radius: 12px;
  background: var(--surface);
}
.co-money-input:focus-within { border-color: var(--acai-500); box-shadow: 0 0 0 3px var(--acai-50); }
.co-money-input > span { font-weight: 600; color: var(--ink-500); }
.co-money-input input {
  width: 110px;
  border: none;
  outline: none;
  background: transparent;
  color: var(--ink-900);
  font-family: var(--font-heading);
  font-size: 22px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.co-money-input.sm { min-height: 40px; }
.co-money-input.sm input { width: 80px; font-size: 17px; }

.co-received { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.co-bills { display: flex; flex-wrap: wrap; gap: 6px; }
.co-bill {
  min-height: 38px;
  padding: 0 12px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  color: var(--ink-900);
  font-family: inherit;
  font-size: 13.5px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  cursor: pointer;
}
.co-bill:hover { border-color: var(--acai-300); }
.co-bill.active { border-color: var(--acai-700); background: var(--acai-50); color: var(--acai-text); font-weight: 700; }

/* Resultado del cobro */
.co-result {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 48px;
  padding: 10px 14px;
  border-radius: 12px;
  font-size: 14px;
  line-height: 1.4;
  background: var(--cream-100);
  color: var(--ink-700);
}
.co-result svg { width: 17px; height: 17px; flex-shrink: 0; }
.co-result.change {
  justify-content: space-between;
  min-height: 62px;
  background: var(--lime-100);
  color: var(--lime-700);
}
.co-result-label { font-size: 16px; font-weight: 700; }
.co-result-amount {
  font-family: var(--font-heading);
  font-size: 32px;
  font-weight: 700;
  line-height: 1;
  color: var(--ink-900);
  font-variant-numeric: tabular-nums;
  animation: co-pop .18s ease-out;
}
.co-result.exact { background: var(--lime-100); color: var(--lime-700); font-weight: 600; }
.co-result.short,
.co-result.cash-short { background: var(--danger-100); color: var(--danger-text); font-weight: 600; }
.co-result.qr,
.co-result.card { background: var(--acai-50); color: var(--acai-text); font-weight: 600; }
@keyframes co-pop {
  from { transform: scale(.92); opacity: .6; }
  to { transform: scale(1); opacity: 1; }
}
@media (prefers-reduced-motion: reduce) {
  .co-result-amount { animation: none; }
}

/* Pie: siempre visible */
.co-foot {
  display: flex;
  gap: 10px;
  padding: 12px 20px 16px;
  border-top: 1px solid var(--border);
  background: var(--surface);
}
.co-btn {
  min-height: 50px;
  padding: 0 18px;
  border-radius: 13px;
  font-family: var(--font-heading);
  font-size: 16px;
  font-weight: 700;
  cursor: pointer;
}
.co-btn:disabled { cursor: not-allowed; }
.co-btn-ghost { flex: 0 0 auto; background: transparent; color: var(--ink-900); border: 1.5px solid var(--border); }
.co-btn-ghost:hover:not(:disabled) { background: var(--cream-200); }
.co-btn-primary {
  flex: 1;
  border: none;
  background: var(--passion-500);
  color: var(--on-accent);
  font-size: 18px;
  font-variant-numeric: tabular-nums;
}
.co-btn-primary:hover:not(:disabled) { filter: brightness(1.05); }
.co-btn-primary:disabled { background: var(--cream-200); color: var(--ink-500); }

@media (max-width: 520px) {
  .co-overlay { padding: 0; align-items: flex-end; }
  .co-card { max-height: 100vh; border-radius: 18px 18px 0 0; }
  .co-row { grid-template-columns: 1fr; gap: 6px; }
  .co-row-label { padding-top: 0; }
  .co-total-amount { font-size: 28px; }
}
</style>
