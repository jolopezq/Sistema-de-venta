<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
  show: Boolean,
  total: Number
});

const emit = defineEmits(['close', 'confirm']);

const customerSearch = ref('');
const selectedCustomerId = ref(null);
const currentMethod = ref('cash');
const cashReceived = ref(0);
const payments = ref([]);

watch(() => props.show, (newVal) => {
  if (newVal) {
    customerSearch.value = '';
    selectedCustomerId.value = null;
    currentMethod.value = 'cash';
    payments.value = [{ method: 'cash', amount: props.total }];
    cashReceived.value = props.total;
  }
});

const totalPaid = computed(() => payments.value.reduce((s, p) => s + Number(p.amount), 0));
const remaining = computed(() => Math.max(0, props.total - totalPaid.value));

const change = computed(() => {
  const cashPayments = payments.value.filter(p => p.method === 'cash');
  if (cashPayments.length === 0) return 0;
  const cashNeeded = cashPayments.reduce((s, p) => s + Number(p.amount), 0);
  return Math.max(0, Number(cashReceived.value) - cashNeeded);
});

const isReady = computed(() => totalPaid.value >= props.total);

function addPayment() {
  payments.value.push({ method: currentMethod.value, amount: remaining.value > 0 ? remaining.value : 0 });
}

function removePayment(index) {
  payments.value.splice(index, 1);
  if (payments.value.length === 0) addPayment();
}

function confirm() {
  if (!isReady.value) return;
  emit('confirm', {
    customerId: selectedCustomerId.value,
    payments: payments.value.map(p => ({
      method: p.method,
      amount: Number(p.amount),
      received: p.method === 'cash' ? Number(cashReceived.value) : Number(p.amount)
    })),
    change: change.value
  });
}

function setSuggestedCash(amount) {
  cashReceived.value = amount;
  const cashPayment = payments.value.find(p => p.method === 'cash');
  if (cashPayment) cashPayment.amount = props.total;
}
</script>

<template>
  <!-- Uses global .modal-overlay, .modal-card, .modal-head, .modal-body, .modal-foot,
       .customer-search, .customer-found, .pay-methods, .pay-method, .pay-line,
       .add-payment-link, .received-box, .change-box, .badge, .badge-vip from style.css -->
  <div class="modal-overlay" :class="{ active: show }">
    <div class="modal-card">
      <div class="modal-head">
        <h2>Cobrar venta</h2>
        <button class="modal-close" @click="emit('close')">✕</button>
      </div>

      <div class="modal-body">
        <!-- Cliente -->
        <div class="customer-search">
          <input
            class="search-input"
            v-model="customerSearch"
            placeholder="🔍 Buscar cliente por CI o celular..."
          />
          <div class="customer-found" v-if="selectedCustomerId">
            <div>
              <div class="name">María Fernanda Rojas</div>
              <div class="pts">⭐ 320 puntos acumulados · Cliente VIP</div>
            </div>
            <span class="badge badge-vip">VIP</span>
          </div>
        </div>

        <!-- Métodos de pago -->
        <label style="font-size:12px;font-weight:700;color:var(--ink-700);text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:8px;">Método de pago</label>
        <div class="pay-methods">
          <div class="pay-method" :class="{ selected: currentMethod === 'cash' }" @click="currentMethod = 'cash'">💵 Efectivo</div>
          <div class="pay-method" :class="{ selected: currentMethod === 'card' }" @click="currentMethod = 'card'">💳 Tarjeta</div>
          <div class="pay-method" :class="{ selected: currentMethod === 'qr' }" @click="currentMethod = 'qr'">📱 QR</div>
        </div>

        <!-- Líneas de pago -->
        <div class="pay-line" v-for="(payment, index) in payments" :key="index">
          <span>{{ payment.method === 'cash' ? 'Efectivo' : (payment.method === 'card' ? 'Tarjeta' : 'QR') }}</span>
          <span style="display:flex;align-items:center;gap:10px;">
            <input
              type="number"
              v-model="payment.amount"
              style="width:90px;text-align:right;border:2px solid var(--border);border-radius:8px;padding:6px 8px;font-family:'Baloo 2';font-weight:700;font-size:14px;"
              min="0" step="0.1"
            />
            <button @click="removePayment(index)" style="background:none;border:none;color:var(--danger-500);cursor:pointer;font-size:16px;">✕</button>
          </span>
        </div>

        <button class="add-payment-link" @click="addPayment">+ Agregar otro método de pago</button>

        <!-- Efectivo recibido -->
        <div class="received-box" v-if="payments.some(p => p.method === 'cash')">
          <label>Monto recibido en efectivo</label>
          <!-- Sugerencias de billete -->
          <div style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap;">
            <button v-for="bill in [50, 100, 200]" :key="bill"
              class="size-chip"
              :class="{ active: cashReceived == bill }"
              @click="setSuggestedCash(bill)"
              style="cursor:pointer;"
            >Bs {{ bill }}</button>
            <button class="size-chip" :class="{ active: cashReceived == total }" @click="setSuggestedCash(total)" style="cursor:pointer;">Exacto</button>
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-weight:700;color:var(--ink-500);">Bs</span>
            <input
              class="search-input"
              v-model="cashReceived"
              type="number"
              style="border:2px solid var(--border);border-radius:10px;padding:10px 12px;"
            />
          </div>
          <div class="change-box" v-if="change > 0">
            <div class="label">Cambio a devolver</div>
            <div class="value">Bs {{ change.toFixed(2) }}</div>
          </div>
        </div>
      </div>

      <div class="modal-foot">
        <button class="btn btn-ghost" @click="emit('close')">Cancelar</button>
        <button class="btn btn-success" :disabled="!isReady" @click="confirm">
          Confirmar venta
        </button>
      </div>
    </div>
  </div>
</template>
