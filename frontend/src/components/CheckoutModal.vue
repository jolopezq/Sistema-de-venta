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
const saleNote = ref('');
const isCashManuallyEntered = ref(false);

watch(() => props.show, (newVal) => {
  if (newVal) {
    customerSearch.value = '';
    selectedCustomerId.value = null;
    currentMethod.value = 'cash';
    saleNote.value = '';
    payments.value = [{ method: 'cash', amount: props.total }];
    isCashManuallyEntered.value = false;
    cashReceived.value = props.total;
  }
});

const totalPaid = computed(() => payments.value.reduce((s, p) => s + Number(p.amount), 0));
const remaining = computed(() => Math.max(0, props.total - totalPaid.value));

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

const isReady = computed(() => totalPaid.value >= props.total);

function addPayment() {
  payments.value.push({ method: currentMethod.value, amount: remaining.value > 0 ? remaining.value : 0 });
}

function removePayment(index) {
  payments.value.splice(index, 1);
  if (payments.value.length === 0) addPayment();
}

function handleConfirm() {
  if (!isReady.value) return;
  emit('confirm', {
    customerId: selectedCustomerId.value,
    saleNote: saleNote.value,
    payments: payments.value.map(p => ({
      method: p.method,
      amount: Number(p.amount),
      received: p.method === 'cash' ? Number(cashReceived.value) : Number(p.amount)
    })),
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

function setSuggestedCash(amount, isExact = false) {
  cashReceived.value = isExact ? cashNeededAmount.value : amount;
  isCashManuallyEntered.value = !isExact;
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
              class="pay-amount-input"
              min="0" step="0.1"
            />
            <button @click="removePayment(index)" class="pay-remove-btn" title="Eliminar método">✕</button>
          </span>
        </div>

        <button class="add-payment-link" @click="addPayment">+ Agregar otro método de pago</button>

        <!-- Efectivo recibido -->
        <div class="received-box" v-if="payments.some(p => p.method === 'cash')">
          <label class="received-label">Monto recibido en efectivo</label>
          <!-- Sugerencias de billete -->
          <div style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap;">
            <button v-for="bill in [50, 100, 200]" :key="bill"
              class="size-chip"
              :class="{ active: cashReceived == bill }"
              @click="setSuggestedCash(bill)"
              style="cursor:pointer;"
            >Bs {{ bill }}</button>
            <button class="size-chip" :class="{ active: cashReceived == cashNeededAmount }" @click="setSuggestedCash(cashNeededAmount, true)" style="cursor:pointer;">Exacto</button>
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-weight:700;color:var(--ink-500);">Bs</span>
            <input
              class="search-input pay-cash-received-input"
              v-model="cashReceived"
              @input="isCashManuallyEntered = true"
              type="number"
            />
          </div>
        </div>
        
        <div class="payment-status">
          <div v-if="remaining > 0" class="status-missing">
            ⚠️ Falta por cubrir: Bs {{ remaining.toFixed(2) }}
          </div>
          <div v-else-if="change > 0" class="status-change">
            💰 Cambio a devolver: Bs {{ change.toFixed(2) }}
          </div>
          <div v-else class="status-covered">
            ✓ Monto cubierto exacto
          </div>
        </div>

        <!-- Nota Global -->
        <div style="margin-top:20px;">
          <label style="font-size:12px;font-weight:700;color:var(--ink-700);text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:8px;">Nota de venta (Opcional)</label>
          <textarea 
            v-model="saleNote" 
            placeholder="Ej: Cliente apurado, entregar en puerta..."
            class="search-input"
            style="width:100%;resize:vertical;min-height:60px;padding:12px;border-radius:8px;"
          ></textarea>
        </div>
      </div>

      <div class="modal-foot">
        <button class="btn btn-ghost" @click="emit('close')">Cancelar</button>
        <button class="btn btn-success" :disabled="!isReady" @click="handleConfirm">
          Confirmar venta
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.payment-status {
  margin-top: 16px;
  font-weight: 700;
  font-size: 14px;
}
.status-missing {
  color: var(--danger-600);
  background: var(--danger-50);
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid var(--danger-200);
}
.status-change {
  color: var(--warning-700);
  background: var(--warning-50);
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid var(--warning-200);
}
.status-covered {
  color: var(--success-600);
  background: var(--success-50);
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid var(--success-200);
}
:global(html.dark) .status-missing {
  background: rgba(220, 38, 38, 0.2);
  border-color: rgba(220, 38, 38, 0.4);
  color: #fca5a5;
}
:global(html.dark) .status-change {
  background: rgba(234, 179, 8, 0.2);
  border-color: rgba(234, 179, 8, 0.4);
  color: #fde047;
}
:global(html.dark) .status-covered {
  background: rgba(22, 163, 74, 0.2);
  border-color: rgba(22, 163, 74, 0.4);
  color: #86efac;
}
</style>
