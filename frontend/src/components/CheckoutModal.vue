<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { rebalance, fillRemainder, cashDue as calcCashDue } from '../utils/splitPayments';
import { maxDiscount, netTotal, discountPercent, formatPercent, discountProblem, toCents } from '../utils/discount';

/**
 * Cobro de una venta. Lo memorable es el vuelto: arriba el total a cobrar y, apenas se marca lo recibido,
 * el vuelto en grande. Pensado para la laptop del local (1366×768): el botón de cobro queda siempre a la vista.
 *
 * Descuento manual (docs/PLAN_DESCUENTOS_CAJA.md): solo con el permiso «Descuentos en caja», con el tope de su
 * rol y motivo obligatorio. Todo el cobro (pagos, vuelto, billetes) se calcula sobre `due`, el total con descuento.
 * Al 100 % es una cortesía: no se cobra y se registra con un pago en efectivo de Bs 0.
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
  channel: { type: String, default: 'pos' },
  /** auth.discount_policy: { allowed, max_percent, reason_min, reason_max }; null = sin permiso */
  discountPolicy: { type: Object, default: null }
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
/** Turno de edición manual: la línea menos reciente es la que se completa sola (ver utils/splitPayments.js) */
const editStamp = ref(0);
const saleNote = ref('');
const isCashManuallyEntered = ref(false);
const receivedInput = ref(null);

// ── Descuento manual ──
const discountOpen = ref(false);
const discountAmount = ref('');
const discountReason = ref('');
/** El motivo se marca en rojo recién al salir del campo o al intentar cobrar: antes es una indicación. */
const reasonTouched = ref(false);
const discountInput = ref(null);
const reasonInput = ref(null);
const canDiscount = computed(() => !isPedidosYa.value && Boolean(props.discountPolicy?.allowed));
const maxPercent = computed(() => Number(props.discountPolicy?.max_percent || 0));
const reasonMax = computed(() => Number(props.discountPolicy?.reason_max || 255));
const discountMax = computed(() => maxDiscount(props.total, maxPercent.value));
/** Lo que se descuenta del total (lo escrito, sin pasar del subtotal). Si supera el tope, Cobrar queda bloqueado. */
const appliedDiscount = computed(() => {
  if (!discountOpen.value || !canDiscount.value) return 0;
  return Math.min(toCents(discountAmount.value), toCents(props.total)) / 100;
});
/** Total a cobrar: el subtotal menos el descuento. */
const due = computed(() => netTotal(props.total, appliedDiscount.value));
const isCourtesy = computed(() => appliedDiscount.value > 0 && due.value <= 0);
const discountIssue = computed(() => {
  if (!discountOpen.value || !canDiscount.value) return null;
  return discountProblem({
    amount: discountAmount.value,
    reason: discountReason.value,
    subtotal: props.total,
    policy: props.discountPolicy,
  });
});
const discountPctText = computed(() => formatPercent(discountPercent(appliedDiscount.value, props.total)));
/** El aviso es error (rojo) si el monto supera el tope o si el motivo falta después de tocarlo. */
const discountIssueIsError = computed(() => Boolean(discountIssue.value) && (discountIssue.value.field === 'amount' || reasonTouched.value));

function openDiscount() {
  discountOpen.value = true;
  nextTick(() => discountInput.value?.focus());
}

function removeDiscount() {
  discountOpen.value = false;
  discountAmount.value = '';
  discountReason.value = '';
  reasonTouched.value = false;
  if (!isPedidosYa.value) focusReceived();
}

/** El descuento cambió el total: los pagos se acomodan solos (un pago = el total; dividido = la línea menos reciente). */
watch(due, (newDue) => {
  if (!props.show || isPedidosYa.value) return;
  if (payments.value.length <= 1) {
    const method = payments.value[0]?.method || currentMethod.value;
    payments.value = [{ method, amount: newDue, touched: 0 }];
  } else {
    payments.value = fillRemainder(payments.value, newDue);
  }
});

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
    editStamp.value = 0;
    // Cada cobro empieza sin descuento
    discountOpen.value = false;
    discountAmount.value = '';
    discountReason.value = '';
    reasonTouched.value = false;
    payments.value = [{ method: 'cash', amount: props.total, touched: 0 }];
    isCashManuallyEntered.value = false;
    cashReceived.value = props.total;
    isSubmitting.value = false;
    if (!isPedidosYa.value) focusReceived();
  }
});

const totalPaid = computed(() => payments.value.reduce((s, p) => s + Number(p.amount), 0));
const remaining = computed(() => Math.max(0, due.value - totalPaid.value));
const hasCash = computed(() => payments.value.some((p) => p.method === 'cash'));
const splitMode = computed(() => payments.value.length > 1);
/** Efectivo que de verdad hay que cobrar: la línea de efectivo sin pasar de lo que falta tras QR/tarjeta */
const cashDue = computed(() => calcCashDue(payments.value, due.value));

const change = computed(() => {
  let totalChange = 0;

  if (totalPaid.value > due.value) {
    totalChange += (totalPaid.value - due.value);
  }

  if (hasCash.value) {
    const received = Number(cashReceived.value);
    if (received > cashDue.value) {
      totalChange += (received - cashDue.value);
    }
  }

  return totalChange;
});

/** Lo recibido en efectivo no alcanza para la parte en efectivo (p. ej. escribió 40 para un total de 44). */
const cashShort = computed(() => {
  if (!hasCash.value || !isCashManuallyEntered.value) return 0;
  return Math.max(0, cashDue.value - Number(cashReceived.value || 0));
});

const isReady = computed(() => {
  if (isPedidosYa.value) return true;
  if (discountIssue.value) return false;
  if (isCourtesy.value) return true;
  return toCents(totalPaid.value) >= toCents(due.value) && cashShort.value === 0;
});

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
    amount: remaining.value > 0 ? Number(remaining.value.toFixed(2)) : 0,
    touched: 0
  });
}

/** Dividir: la primera parte queda en el método elegido y la segunda arranca en 0 para escribirla. */
function startSplit() {
  const used = payments.value.map(p => p.method);
  const nextMethod = ['qr', 'cash', 'card'].find(m => !used.includes(m));
  payments.value.push({ method: nextMethod, amount: 0, touched: 0 });
}

/** El cajero escribió el monto de una línea: las demás se acomodan solas para sumar el total. */
function onAmountInput(index, raw) {
  editStamp.value += 1;
  payments.value = rebalance(payments.value, index, raw, due.value, editStamp.value);
}

function removePayment(index) {
  payments.value.splice(index, 1);
  if (payments.value.length === 0) {
    payments.value.push({ method: currentMethod.value, amount: due.value });
  }
  if (payments.value.length === 1) {
    // De vuelta a un solo método: cubre el total completo
    payments.value[0].amount = due.value;
    currentMethod.value = payments.value[0].method;
  } else {
    // Quedan 2 (o más): la línea menos reciente absorbe lo que dejó la quitada
    payments.value = fillRemainder(payments.value, due.value);
  }
}

function handleConfirm() {
  // Enter con el descuento incompleto: el foco va al campo que falta, en vez de no hacer nada
  if (discountIssue.value && !isSubmitting.value) {
    reasonTouched.value = true;
    (discountIssue.value.field === 'reason' ? reasonInput : discountInput).value?.focus();
    return;
  }
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

  const discount = {
    discountAmount: appliedDiscount.value,
    discountReason: appliedDiscount.value > 0 ? discountReason.value.trim() : null,
  };

  // Cortesía: no se cobra; se registra un pago en efectivo de Bs 0 (la venta necesita al menos un pago)
  if (isCourtesy.value) {
    emit('confirm', {
      customerId: selectedCustomerId.value,
      saleNote: saleNote.value,
      destination: orderDestination.value,
      isTakeaway: orderDestination.value === 'takeaway',
      payments: [{ method: 'cash', amount: 0, received: 0 }],
      change: 0,
      ...discount
    });
    return;
  }

  // Si se registró más de lo cobrado, el exceso es cambio: se descuenta del efectivo
  // para que el arqueo no lo cuente como ingreso.
  let excess = Math.max(0, totalPaid.value - due.value);
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
    change: change.value,
    ...discount
  });
}

const cashNeededAmount = computed(() => cashDue.value);

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
  if (isCourtesy.value) return 'Registrar cortesía';
  return `Cobrar ${money(due.value)}`;
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
          <div class="co-total" :class="{ pya: isPedidosYa, courtesy: isCourtesy }">
            <span class="co-total-label">{{ isPedidosYa ? 'Total PedidosYa' : (isCourtesy ? 'Cortesía: no se cobra' : 'Total a cobrar') }}</span>
            <strong class="co-total-amount">{{ money(due) }}</strong>
            <span class="co-total-sub">
              {{ itemsText }}<template v-if="isPedidosYa">. Lo cobra la plataforma: no entra al efectivo ni al arqueo.</template>
              <template v-else-if="appliedDiscount > 0"> · Subtotal {{ money(total) }} · <span class="co-discount-sub">Descuento −{{ money(appliedDiscount) }} ({{ discountPctText }})</span></template>
              <template v-if="canDiscount && !discountOpen"> · <button type="button" class="co-link co-discount-open" @click="openDiscount">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z" /><circle cx="7.5" cy="7.5" r="1.5" /></svg>Aplicar descuento</button></template>
            </span>
          </div>

          <!-- Descuento manual: monto y motivo en una fila (cabe en la portátil de caja) -->
          <div v-if="canDiscount && discountOpen" class="co-row co-discount" role="group" aria-label="Descuento">
            <label class="co-row-label" for="co-discount">Descuento<small class="co-row-label-sub">máx. {{ money(discountMax) }}</small></label>
            <div class="co-row-control co-discount-control">
              <span class="co-money-input sm" :class="{ invalid: discountIssue?.field === 'amount' }">
                <span aria-hidden="true">Bs</span>
                <input
                  id="co-discount"
                  ref="discountInput"
                  v-model="discountAmount"
                  type="number"
                  min="0"
                  step="0.5"
                  inputmode="decimal"
                  :aria-invalid="discountIssue?.field === 'amount'"
                  aria-describedby="co-discount-issue"
                  @focus="$event.target.select()"
                />
              </span>
              <textarea
                id="co-discount-reason"
                ref="reasonInput"
                v-model="discountReason"
                class="co-input co-discount-reason"
                :class="{ invalid: discountIssueIsError && discountIssue?.field === 'reason' }"
                rows="2"
                :maxlength="reasonMax"
                aria-label="Motivo del descuento"
                :aria-invalid="discountIssueIsError && discountIssue?.field === 'reason'"
                aria-describedby="co-discount-issue"
                placeholder="Motivo (obligatorio): pedido de 10 bowls para un evento…"
                @blur="reasonTouched = true"
              ></textarea>
              <button type="button" class="co-icon-btn" aria-label="Quitar descuento" title="Quitar descuento" @click="removeDiscount">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
              </button>
              <p id="co-discount-issue" class="co-discount-issue" :class="{ ok: !discountIssue, hint: discountIssue && !discountIssueIsError }" role="status" aria-live="polite">
                <template v-if="discountIssue">{{ discountIssue.message }}</template>
                <template v-else>{{ discountReason.trim().length }}/{{ reasonMax }} caracteres</template>
              </p>
            </div>
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
            <!-- Cortesía: no hay pago, ni recibido, ni vuelto -->
            <div v-if="isCourtesy" class="co-result courtesy" role="status">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7ZM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7Z" /></svg>
              <span>Esta venta no se cobra: queda como cortesía, con su motivo.</span>
            </div>

            <template v-if="!isCourtesy">
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
                        :value="payment.amount"
                        type="number"
                        min="0"
                        step="0.5"
                        inputmode="decimal"
                        :aria-label="`Monto del pago ${index + 1}`"
                        @input="onAmountInput(index, $event.target.value)"
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
              <label class="co-row-label" for="co-received">Recibe<small v-if="splitMode" class="co-row-label-sub">en efectivo</small></label>
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
            </template>

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
  padding: 12px;
}
.co-card {
  width: 100%;
  max-width: 620px;
  max-height: calc(100vh - 24px);
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
  padding: 10px 18px 0;
}
.co-head h2 { margin: 0; font-size: 18px; font-weight: 700; color: var(--ink-900); }
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
  overflow-x: hidden;
  padding: 8px 18px 10px;
  display: flex;
  flex-direction: column;
  gap: 9px;
}

/* Total a cobrar */
.co-total {
  display: grid;
  grid-template-columns: 1fr auto;
  align-items: baseline;
  gap: 0 12px;
  padding: 8px 14px;
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
  font-size: 30px;
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
  grid-template-columns: 90px minmax(0, 1fr);
  align-items: start;
  gap: 10px;
}
.co-row-label {
  padding-top: 8px;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--ink-700);
}
/* Control y enlace ("Dividir el pago") comparten línea; el pago dividido ocupa la fila completa y el enlace baja */
.co-row-label-sub { display: block; font-size: 11.5px; font-weight: 500; line-height: 1.1; color: var(--ink-500); }
.co-row-control { display: flex; flex-direction: row; flex-wrap: wrap; align-items: center; gap: 4px 14px; min-width: 0; }

/* Control segmentado: una sola opción marcada, con relleno morado y texto en peso mayor */
.co-seg {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 4px;
  padding: 3px;
  border-radius: 12px;
  background: var(--cream-200);
}
.co-seg-btn {
  min-height: 34px;
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
.co-seg-sm .co-seg-btn { min-height: 30px; padding: 0 13px; font-size: 13.5px; }
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
.co-split { display: flex; flex-direction: column; gap: 4px; flex: 1 1 100%; min-width: 0; }
.co-split-line { display: flex; align-items: center; gap: 6px; min-width: 0; }
/* Mayor especificidad que .co-input (que se declara después): evita que el selector ocupe toda la columna */
.co-split-line .co-split-method { flex: 0 0 120px; width: 120px; min-height: 34px; padding: 4px 10px; }
.co-split-line .co-money-input.sm { flex: 1; min-width: 0; }
.co-split-line .co-money-input.sm input { flex: 1; width: 100%; min-width: 0; }
.co-split-line .co-icon-btn { width: 30px; height: 30px; }

/* Montos en Bs */
.co-input {
  width: 100%;
  box-sizing: border-box;
  min-height: 34px;
  padding: 6px 10px;
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
  min-height: 38px;
  padding: 0 12px;
  border: 1.5px solid var(--border);
  border-radius: 12px;
  background: var(--surface);
}
.co-money-input:focus-within { border-color: var(--acai-500); box-shadow: 0 0 0 3px var(--acai-50); }
.co-money-input > span { font-weight: 600; color: var(--ink-500); }
.co-money-input input {
  width: 100px;
  border: none;
  outline: none;
  background: transparent;
  color: var(--ink-900);
  font-family: var(--font-heading);
  font-size: 20px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.co-money-input.sm { min-height: 34px; }
.co-money-input.sm input { width: 80px; font-size: 16px; }

.co-received { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.co-bills { display: flex; flex-wrap: wrap; gap: 6px; }
.co-bill {
  min-height: 34px;
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
  min-height: 38px;
  padding: 8px 12px;
  border-radius: 12px;
  font-size: 14px;
  line-height: 1.4;
  background: var(--cream-100);
  color: var(--ink-700);
}
.co-result svg { width: 17px; height: 17px; flex-shrink: 0; }
.co-result.change {
  justify-content: space-between;
  min-height: 52px;
  background: var(--lime-100);
  color: var(--lime-700);
}
.co-result-label { font-size: 16px; font-weight: 700; }
.co-result-amount {
  font-family: var(--font-heading);
  font-size: 26px;
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

/* Descuento manual (docs/PLAN_DESCUENTOS_CAJA.md) */
.co-total.courtesy { background: var(--lime-100); }
.co-total.courtesy .co-total-label { color: var(--lime-700); }
.co-discount-sub { font-weight: 700; color: var(--ink-900); white-space: nowrap; }
.co-discount-open {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12.5px;
  vertical-align: baseline;
}
.co-discount-open svg { width: 13px; height: 13px; flex-shrink: 0; }
/* Monto | motivo | quitar en una sola línea; el aviso debajo, a lo ancho */
.co-discount .co-discount-control {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: start;
  gap: 3px 8px;
}
.co-discount-control .co-money-input.sm input { width: 72px; }
.co-discount-reason {
  flex: 1 1 auto;
  min-width: 0;
  min-height: 34px;
  height: 52px;
  padding: 6px 10px;
  resize: none;
  font-size: 13.5px;
  line-height: 1.35;
}
.co-discount-control .co-icon-btn { width: 30px; height: 34px; }
.co-discount-issue {
  grid-column: 1 / -1;
  margin: 0;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.3;
  color: var(--danger-text);
}
.co-discount-issue.ok { font-weight: 500; color: var(--ink-500); }
.co-discount-issue.hint { color: var(--acai-text); }
.co-money-input.invalid,
.co-input.invalid { border-color: var(--danger-500); }
.co-result.courtesy { background: var(--lime-100); color: var(--lime-700); font-weight: 600; }

/* Pie: siempre visible */
.co-foot {
  display: flex;
  gap: 10px;
  padding: 10px 18px 12px;
  border-top: 1px solid var(--border);
  background: var(--surface);
}
.co-btn {
  min-height: 44px;
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

/* Portátil de caja (la app marca html.screen-compact en pantallas ≤ 1366): el pago dividido de 3 métodos gana unos píxeles */
html.screen-compact .co-split { gap: 3px; }
html.screen-compact .co-split-line .co-split-method,
html.screen-compact .co-split-line .co-money-input.sm { min-height: 32px; }

/* Plan B: ventanas bajas (p. ej. Windows al 125 %): el cuerpo es lo único que se desplaza si aun así no cabe */
@media (max-height: 520px) and (min-width: 521px) {
  .co-body { gap: 6px; padding: 6px 18px 8px; }
  .co-total { padding: 5px 14px; }
  .co-total-amount { font-size: 26px; }
  .co-result { min-height: 32px; padding: 5px 12px; }
  .co-result.change { min-height: 44px; }
  .co-discount-reason { height: 34px; }
  .co-foot { padding: 8px 18px 10px; }
  .co-btn { min-height: 40px; }
}

@media (max-width: 520px) {
  .co-overlay { padding: 0; align-items: flex-end; }
  .co-card { max-height: 100vh; border-radius: 18px 18px 0 0; }
  .co-row { grid-template-columns: 1fr; gap: 6px; }
  .co-row-label { padding-top: 0; }
  .co-total-amount { font-size: 28px; }
}
</style>
