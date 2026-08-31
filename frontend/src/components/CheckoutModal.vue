<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
  show: Boolean,
  total: Number,
  isTakeawayDefault: Boolean,
  destinationDefault: { type: String, default: 'dine_in' }, // 'dine_in' | 'takeaway' | 'mixed'
  takeawayCount: { type: Number, default: 0 },
  dineInCount: { type: Number, default: 0 },
  itemsCount: { type: Number, default: 0 }
});

const emit = defineEmits(['close', 'confirm']);

const customerSearch = ref('');
const selectedCustomerId = ref(null);
const orderDestination = ref('dine_in'); // 'dine_in' | 'takeaway' | 'mixed'
const currentMethod = ref('cash');
const cashReceived = ref(0);
const payments = ref([]);
const saleNote = ref('');
const isCashManuallyEntered = ref(false);

const hasMixedCapability = computed(() => {
  return props.destinationDefault === 'mixed' || (props.takeawayCount > 0 && props.dineInCount > 0);
});

function selectDestination(dest) {
  orderDestination.value = dest;
}

function selectMainMethod(method) {
  currentMethod.value = method;
  if (payments.value.length === 1) {
    payments.value[0].method = method;
  }
}

watch(() => props.show, (newVal) => {
  if (newVal) {
    customerSearch.value = '';
    selectedCustomerId.value = null;
    orderDestination.value = props.destinationDefault || (props.isTakeawayDefault ? 'takeaway' : 'dine_in');
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
  const used = payments.value.map(p => p.method);
  const nextMethod = ['cash', 'qr', 'card'].find(m => !used.includes(m)) || currentMethod.value;
  payments.value.push({
    method: nextMethod,
    amount: remaining.value > 0 ? Number(remaining.value.toFixed(2)) : 0
  });
}

function removePayment(index) {
  payments.value.splice(index, 1);
  if (payments.value.length === 0) {
    payments.value.push({ method: currentMethod.value, amount: props.total });
  }
}

const isSubmitting = ref(false);

watch(() => props.show, (newVal) => {
  if (newVal) {
    isSubmitting.value = false;
  }
});

function handleConfirm() {
  if (!isReady.value || isSubmitting.value) return;
  isSubmitting.value = true;
  emit('confirm', {
    customerId: selectedCustomerId.value,
    saleNote: saleNote.value,
    destination: orderDestination.value,
    isTakeaway: orderDestination.value === 'takeaway',
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
  <Teleport to="body">
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

          <!-- Tipo de Destino del Pedido (Mesa vs Para Llevar vs Mixto) -->
          <div class="destination-section">
            <div class="dest-section-header">
              <label class="dest-section-label">Destino del pedido</label>
              <span v-if="hasMixedCapability" class="dest-badge-status">
                {{ dineInCount }} Mesa · {{ takeawayCount }} Llevar
              </span>
            </div>
            <div class="destination-grid" :class="{ 'destination-grid--3': hasMixedCapability }">
              <button 
                type="button" 
                class="dest-btn" 
                :class="{ active: orderDestination === 'dine_in' }"
                @click="selectDestination('dine_in')"
              >
                <span class="dest-icon">🍽️</span>
                <div class="dest-text-col">
                  <span class="dest-title">Consumir en Mesa</span>
                  <span class="dest-desc">Servir todo en local</span>
                </div>
                <span v-if="orderDestination === 'dine_in'" class="dest-badge">✓</span>
              </button>

              <button 
                type="button" 
                class="dest-btn" 
                :class="{ active: orderDestination === 'takeaway' }"
                @click="selectDestination('takeaway')"
              >
                <span class="dest-icon">🛍️</span>
                <div class="dest-text-col">
                  <span class="dest-title">Para Llevar</span>
                  <span class="dest-desc">Empacar todo para llevar</span>
                </div>
                <span v-if="orderDestination === 'takeaway'" class="dest-badge">✓</span>
              </button>

              <button 
                v-if="hasMixedCapability"
                type="button" 
                class="dest-btn dest-btn--mixed" 
                :class="{ active: orderDestination === 'mixed' }"
                @click="selectDestination('mixed')"
              >
                <span class="dest-icon">🔀</span>
                <div class="dest-text-col">
                  <span class="dest-title">Pedido Mixto</span>
                  <span class="dest-desc">{{ dineInCount }} Mesa + {{ takeawayCount }} Llevar</span>
                </div>
                <span v-if="orderDestination === 'mixed'" class="dest-badge">✓</span>
              </button>
            </div>
          </div>

          <!-- Métodos de pago -->
          <label style="font-size:12px;font-weight:700;color:var(--ink-700);text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:8px;">Método de pago</label>
          <div class="pay-methods">
            <button 
              type="button" 
              class="pay-method" 
              :class="{ selected: (payments.length === 1 ? payments[0].method : currentMethod) === 'cash' }" 
              @click="selectMainMethod('cash')"
            >💵 Efectivo</button>
            <button 
              type="button" 
              class="pay-method" 
              :class="{ selected: (payments.length === 1 ? payments[0].method : currentMethod) === 'card' }" 
              @click="selectMainMethod('card')"
            >💳 Tarjeta</button>
            <button 
              type="button" 
              class="pay-method" 
              :class="{ selected: (payments.length === 1 ? payments[0].method : currentMethod) === 'qr' }" 
              @click="selectMainMethod('qr')"
            >📱 QR</button>
          </div>

          <!-- Líneas de pago -->
          <div class="pay-line" v-for="(payment, index) in payments" :key="index">
            <select v-model="payment.method" class="pay-method-select">
              <option value="cash">💵 Efectivo</option>
              <option value="qr">📱 QR</option>
              <option value="card">💳 Tarjeta</option>
            </select>
            <span style="display:flex;align-items:center;gap:10px;">
              <input
                type="number"
                v-model="payment.amount"
                class="pay-amount-input"
                min="0" step="0.1"
              />
              <button @click="removePayment(index)" class="pay-remove-btn" title="Eliminar método" v-if="payments.length > 1">✕</button>
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
          <button class="btn btn-ghost" :disabled="isSubmitting" @click="emit('close')">Cancelar</button>
          <button class="btn btn-success" :disabled="!isReady || isSubmitting" @click="handleConfirm">
            {{ isSubmitting ? '⏳ Procesando...' : 'Confirmar venta' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.modal-overlay {
  position: fixed !important;
  inset: 0 !important;
  z-index: 99999 !important;
  background: rgba(18, 10, 26, 0.65) !important;
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
  display: none;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.modal-overlay.active {
  display: flex !important;
}

.destination-section {
  margin-bottom: 18px;
}

.dest-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}

.dest-section-label {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--ink-700, #334155);
  text-transform: uppercase;
  letter-spacing: .04em;
}

.dest-badge-status {
  font-size: 11px;
  font-weight: 800;
  padding: 2px 8px;
  border-radius: 99px;
  background: #f5f3ff;
  color: #7c3aed;
  border: 1px solid #ddd6fe;
}

.destination-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

.destination-grid--3 {
  grid-template-columns: repeat(3, 1fr);
}

.dest-btn {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-radius: 12px;
  border: 1.5px solid var(--border, #E2E8F0);
  background: var(--surface, #FFFFFF);
  cursor: pointer;
  text-align: left;
  transition: all 0.15s ease;
  position: relative;
}

.dest-btn:hover {
  border-color: var(--passion-300, #FDBA74);
  background: #FFF7ED;
}

.dest-btn.active {
  border-color: var(--passion-600, #EA580C);
  background: #FFF7ED;
  box-shadow: 0 2px 8px rgba(234, 88, 12, 0.15);
}

.dest-btn--mixed:hover {
  border-color: #c4b5fd;
  background: #faf5ff;
}

.dest-btn--mixed.active {
  border-color: #8b5cf6;
  background: #faf5ff;
  box-shadow: 0 2px 8px rgba(139, 92, 246, 0.18);
}

.dest-btn--mixed.active .dest-badge {
  color: #8b5cf6;
}

.dest-icon {
  font-size: 22px;
  line-height: 1;
}

.dest-text-col {
  display: flex;
  flex-direction: column;
  flex: 1;
}

.dest-title {
  font-size: 13px;
  font-weight: 800;
  color: var(--ink-900, #0F172A);
  line-height: 1.2;
}

.dest-btn.active .dest-title {
  color: var(--passion-700, #C2410C);
}

.dest-desc {
  font-size: 10.5px;
  color: var(--ink-500, #64748B);
}

.dest-badge {
  font-size: 14px;
  font-weight: 900;
  color: var(--passion-600, #EA580C);
}

:global(html.dark) .dest-btn {
  background: rgba(30, 41, 59, 0.6);
  border-color: rgba(255, 255, 255, 0.1);
}
:global(html.dark) .dest-btn:hover {
  background: rgba(234, 88, 12, 0.15);
  border-color: rgba(234, 88, 12, 0.4);
}
:global(html.dark) .dest-btn.active {
  background: rgba(234, 88, 12, 0.25);
  border-color: #F97316;
}
:global(html.dark) .dest-btn--mixed:hover {
  background: rgba(139, 92, 246, 0.18);
  border-color: rgba(139, 92, 246, 0.45);
}
:global(html.dark) .dest-btn--mixed.active {
  background: rgba(139, 92, 246, 0.3);
  border-color: #a78bfa;
}
:global(html.dark) .dest-badge-status {
  background: rgba(139, 92, 246, 0.2);
  color: #c4b5fd;
  border-color: rgba(139, 92, 246, 0.4);
}
:global(html.dark) .dest-title {
  color: #F1F5F9;
}
:global(html.dark) .dest-desc {
  color: #94A3B8;
}

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

.pay-method-select {
  padding: 6px 10px;
  border-radius: 8px;
  border: 1.5px solid var(--border);
  background: var(--surface);
  color: var(--ink-900);
  font-weight: 700;
  font-size: 13px;
  cursor: pointer;
  outline: none;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.pay-method-select:focus {
  border-color: var(--acai-500);
  box-shadow: 0 0 0 3px rgba(116, 72, 166, 0.2);
}

:global(html.dark) .pay-method-select {
  background: var(--surface, #1e293b);
  border-color: var(--border, rgba(255, 255, 255, 0.15));
  color: #f1f5f9;
}
</style>
