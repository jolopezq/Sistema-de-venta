<script setup>
import { computed } from 'vue';

const props = defineProps({
  show: Boolean,
  sale: Object
});

const emit = defineEmits(['close']);

const change = computed(() => Number(props.sale?.change || 0));

const displayOrderNum = computed(() => {
  if (!props.sale) return '#001';
  if (props.sale.daily_sequence) {
    return `#${String(props.sale.daily_sequence).padStart(3, '0')}`;
  }
  if (props.sale.order_number && props.sale.order_number.includes('-')) {
    const seq = props.sale.order_number.split('-')[1];
    return `#${String(parseInt(seq, 10) || 1).padStart(3, '0')}`;
  }
  return `#${props.sale.id ? props.sale.id.split('-')[0].toUpperCase() : '001'}`;
});

const orderFullCode = computed(() => {
  if (!props.sale) return '';
  return props.sale.order_number || '';
});

function getPaymentLabel(method) {
  const map = {
    cash: '💵 Efectivo',
    qr: '📱 QR',
    card: '💳 Tarjeta',
  };
  return map[method] || method;
}
</script>

<template>
  <Teleport to="body">
    <div class="modal-overlay" :class="{ active: show }" @click.self="emit('close')">
      <div class="modal-card receipt-card">
        <div class="modal-body text-center">
          <div class="receipt-check">✓</div>
          <h2 class="receipt-title">Venta confirmada</h2>
          <div class="receipt-sent-pill">
            <span>🍳</span> Orden enviada a preparación / Delivery
          </div>

          <div class="receipt-comanda-box">
            <span class="comanda-label">Comanda</span>
            <span class="comanda-number">{{ displayOrderNum }}</span>
            <span v-if="orderFullCode" class="comanda-code">({{ orderFullCode }})</span>
          </div>

          <div class="receipt-change-box" v-if="change > 0">
            <div class="change-label">Cambio a entregar</div>
            <div class="change-val">Bs {{ change.toFixed(2) }}</div>
          </div>

          <div class="receipt-total-box" v-else>
            <div class="total-label">Total pagado</div>
            <div class="total-val">Bs {{ Number(sale?.total_amount || 0).toFixed(2) }}</div>
          </div>

          <!-- MÉTODOS DE PAGO UTILIZADOS -->
          <div v-if="sale?.payments && sale.payments.length > 0" class="receipt-payments-box">
            <div class="receipt-payments-title">Forma de pago</div>
            <div class="receipt-payments-tags">
              <span 
                v-for="(p, idx) in sale.payments" 
                :key="idx" 
                class="receipt-pay-pill"
              >
                {{ getPaymentLabel(p.method) }}: <strong>Bs {{ Number(p.amount).toFixed(2) }}</strong>
              </span>
            </div>
          </div>
        </div>

        <!-- LISTA RESUMEN DE PRODUCTOS -->
        <div class="receipt-items-list">
          <div 
            class="receipt-item-row" 
            v-for="(item, index) in sale?.items" 
            :key="index"
          >
            <div class="item-main-row">
              <span class="item-name">{{ Math.round(Number(item.quantity || 1)) }}x {{ item.name }}</span>
              <span class="item-price">Bs {{ Number(item.subtotal || 0).toFixed(2) }}</span>
            </div>
            <div v-if="item.modifiers && item.modifiers.length > 0" class="item-mods">
              <span v-for="(mod, idx) in item.modifiers" :key="idx">
                {{ mod.quantity > 1 ? `${mod.quantity}x ` : '' }}{{ mod.option_name }}<span v-if="idx < item.modifiers.length - 1">, </span>
              </span>
            </div>
            <div v-if="item.item_note" class="item-note">
              Nota: {{ item.item_note }}
            </div>
          </div>
        </div>

        <div class="modal-foot">
          <button class="btn btn-primary btn-new-sale" @click="emit('close')">
            Nueva venta ➔
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
  backdrop-filter: blur(5px);
  -webkit-backdrop-filter: blur(5px);
  display: none;
  align-items: center;
  justify-content: center;
  padding: 16px;
  animation: fadeIn 0.15s ease-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.modal-overlay.active {
  display: flex !important;
}

.receipt-card {
  max-width: 440px;
  width: 100%;
  background: var(--surface, #FFFFFF);
  border-radius: 16px;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
  overflow: hidden;
  animation: popIn 0.2s cubic-bezier(0.2, 0.8, 0.2, 1);
}

@keyframes popIn {
  from { transform: scale(0.95); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}

.text-center {
  padding-top: 24px;
  text-align: center;
}

.receipt-check {
  width: 56px;
  height: 56px;
  margin: 0 auto 12px;
  background: #DCFCE7;
  color: #16A34A;
  border: 2px solid #86EFAC;
  border-radius: 50%;
  font-size: 28px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
}

.receipt-title {
  margin: 0 0 6px;
  font-size: 22px;
  font-weight: 800;
  color: var(--ink-900, #1E293B);
}

.receipt-sent-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #EFF6FF;
  border: 1px solid #BFDBFE;
  color: #1D4ED8;
  font-size: 11.5px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 999px;
  margin-bottom: 12px;
}

.receipt-comanda-box {
  display: flex;
  align-items: baseline;
  justify-content: center;
  gap: 6px;
  margin-bottom: 14px;
}

.comanda-label {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  color: var(--ink-500, #64748B);
  letter-spacing: 0.04em;
}

.comanda-number {
  font-family: 'Baloo 2', sans-serif;
  font-size: 24px;
  font-weight: 900;
  color: var(--passion-600, #EA580C);
  line-height: 1;
}

.comanda-code {
  font-family: 'JetBrains Mono', monospace;
  font-size: 12px;
  font-weight: 700;
  color: var(--ink-500, #64748B);
}

.receipt-change-box {
  background: #FEF9C3;
  border: 1px solid #FDE047;
  border-radius: 10px;
  padding: 10px;
  margin: 0 20px 14px;
}

.change-label {
  font-size: 11px;
  text-transform: uppercase;
  font-weight: 800;
  color: #854D0E;
}

.change-val {
  font-family: 'Baloo 2', sans-serif;
  font-size: 28px;
  font-weight: 900;
  color: #15803D;
  line-height: 1.1;
}

.receipt-total-box {
  background: var(--cream-100, #F4F1EA);
  border: 1px solid var(--border, #E2E8F0);
  border-radius: 10px;
  padding: 8px;
  margin: 0 20px 14px;
}

.receipt-payments-box {
  margin: 0 20px 14px;
  padding: 8px 12px;
  background: var(--cream-50, #FAF8F5);
  border: 1px dashed var(--border, #E2E8F0);
  border-radius: 10px;
}

.receipt-payments-title {
  font-size: 10.5px;
  text-transform: uppercase;
  font-weight: 800;
  color: var(--ink-500, #64748B);
  margin-bottom: 6px;
}

.receipt-payments-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  justify-content: center;
}

.receipt-pay-pill {
  font-size: 12px;
  font-weight: 600;
  color: var(--ink-800, #1E293B);
  background: var(--surface, #FFFFFF);
  border: 1px solid var(--border, #E2E8F0);
  padding: 3px 8px;
  border-radius: 6px;
}

:global(html.dark) .receipt-payments-box {
  background: rgba(30, 41, 59, 0.4);
  border-color: rgba(255, 255, 255, 0.1);
}

:global(html.dark) .receipt-pay-pill {
  background: rgba(15, 23, 42, 0.6);
  border-color: rgba(255, 255, 255, 0.1);
  color: #F1F5F9;
}

.total-label {
  font-size: 11px;
  text-transform: uppercase;
  font-weight: 700;
  color: var(--ink-500);
}

.total-val {
  font-family: 'Baloo 2', sans-serif;
  font-size: 22px;
  font-weight: 800;
  color: var(--ink-900);
}

.receipt-items-list {
  padding: 0 22px 10px;
  text-align: left;
  max-height: 200px;
  overflow-y: auto;
}

.receipt-item-row {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin-bottom: 8px;
  border-bottom: 1px dashed var(--border, #E2E8F0);
  padding-bottom: 6px;
}

.item-main-row {
  display: flex;
  justify-content: space-between;
  width: 100%;
  font-weight: 700;
  font-size: 13px;
  color: var(--ink-900, #1E293B);
}

.item-price {
  font-family: 'JetBrains Mono', monospace;
  font-size: 12.5px;
}

.item-mods {
  font-size: 11px;
  color: var(--ink-500, #64748B);
  padding-left: 14px;
}

.item-note {
  font-size: 11px;
  color: #D97706;
  padding-left: 14px;
  font-style: italic;
}

.modal-foot {
  padding: 14px 20px 18px;
  background: var(--cream-50, #FAF8F5);
  border-top: 1px solid var(--border, #E2E8F0);
}

.btn-new-sale {
  width: 100%;
  font-size: 14px;
  font-weight: 800;
  padding: 12px;
  background: var(--passion-600, #EA580C);
  color: #FFFFFF;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  box-shadow: 0 4px 12px rgba(234, 88, 12, 0.25);
  transition: all 0.15s;
}

.btn-new-sale:hover {
  background: var(--passion-700, #C2410C);
  transform: translateY(-1px);
}
</style>
