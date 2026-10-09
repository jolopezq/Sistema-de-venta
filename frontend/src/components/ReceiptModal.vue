<script setup>
import { computed, ref, watch, nextTick, onBeforeUnmount } from 'vue';

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
    cash: 'Efectivo',
    qr: 'QR',
    card: 'Tarjeta',
    delivery_platform: 'PedidosYa',
  };
  return map[method] || method;
}

const money = (n) => `Bs ${Number(n || 0).toFixed(2)}`;
const isPedidosYa = computed(() => props.sale?.source === 'pedidosya');

// «Nueva venta» queda enfocado: Enter o espacio cierran y el POS vuelve a estar listo
const newSaleBtn = ref(null);
watch(() => props.show, (v) => { if (v) nextTick(() => newSaleBtn.value?.focus()); });
function onKey(e) { if (props.show && e.key === 'Escape') emit('close'); }
watch(() => props.show, (v) => {
  if (v) window.addEventListener('keydown', onKey);
  else window.removeEventListener('keydown', onKey);
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="rc-overlay" @click.self="emit('close')">
      <div class="rc-card" role="dialog" aria-modal="true" aria-labelledby="rc-title">
        <div class="rc-head">
          <span class="rc-check" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
          </span>
          <div>
            <h2 id="rc-title">{{ isPedidosYa ? 'Pedido registrado' : 'Venta cobrada' }}</h2>
            <p>Comanda <strong class="rc-order">{{ displayOrderNum }}</strong> enviada a preparación<span v-if="orderFullCode" class="rc-code">, código {{ orderFullCode }}</span>.</p>
          </div>
        </div>

        <!-- Lo que el cajero tiene que hacer ahora: entregar el vuelto -->
        <div v-if="change > 0" class="rc-change" role="status">
          <span>Entrega de vuelto</span>
          <strong>{{ money(change) }}</strong>
        </div>

        <dl class="rc-facts">
          <div>
            <dt>Total</dt>
            <dd>{{ money(sale?.total_amount) }}</dd>
          </div>
          <div v-for="(p, idx) in sale?.payments || []" :key="idx">
            <dt>{{ getPaymentLabel(p.method) }}</dt>
            <dd>{{ money(p.amount) }}</dd>
          </div>
        </dl>

        <ul class="rc-items">
          <li v-for="(item, index) in sale?.items" :key="index">
            <div class="rc-item-main">
              <span>{{ Math.round(Number(item.quantity || 1)) }}× {{ item.name }}</span>
              <span class="rc-num">{{ money(item.subtotal) }}</span>
            </div>
            <div v-if="item.modifiers && item.modifiers.length > 0" class="rc-item-sub">
              {{ item.modifiers.map((m) => `${m.quantity > 1 ? `${m.quantity}× ` : ''}${m.option_name}`).join(', ') }}
            </div>
            <div v-if="item.item_note" class="rc-item-sub">Nota: {{ item.item_note }}</div>
            <div v-if="item.price_override_reason" class="rc-item-sub">Precio ajustado: {{ item.price_override_reason }}</div>
          </li>
        </ul>

        <div class="rc-foot">
          <button ref="newSaleBtn" class="rc-btn" type="button" @click="emit('close')">Nueva venta</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.rc-overlay {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background: rgba(32, 17, 47, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.rc-card {
  width: 100%;
  max-width: 440px;
  max-height: calc(100vh - 32px);
  display: flex;
  flex-direction: column;
  background: var(--surface);
  border-radius: 18px;
  box-shadow: var(--shadow-pop);
  overflow: hidden;
  color: var(--ink-900);
}
.rc-head { display: flex; align-items: flex-start; gap: 12px; padding: 20px 20px 12px; }
.rc-check {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: var(--lime-100);
  color: var(--lime-700);
}
.rc-check svg { width: 20px; height: 20px; }
.rc-head h2 { margin: 0; font-size: 21px; font-weight: 700; color: var(--ink-900); }
.rc-head p { margin: 2px 0 0; font-size: 13.5px; line-height: 1.45; color: var(--ink-700); }
.rc-order { font-family: var(--font-heading); font-size: 16px; color: var(--ink-900); }
.rc-code { color: var(--ink-500); }

.rc-change {
  margin: 4px 20px 12px;
  padding: 14px 16px;
  border-radius: 14px;
  background: var(--lime-100);
  color: var(--lime-700);
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
}
.rc-change span { font-size: 16px; font-weight: 700; }
.rc-change strong {
  font-family: var(--font-heading);
  font-size: 34px;
  line-height: 1;
  color: var(--ink-900);
  font-variant-numeric: tabular-nums;
}

.rc-facts { margin: 0 20px; padding: 10px 0; display: flex; flex-wrap: wrap; gap: 6px 22px; border-top: 1px solid var(--border); }
.rc-facts div { display: flex; flex-direction: column; }
.rc-facts dt { font-size: 12.5px; color: var(--ink-500); }
.rc-facts dd { margin: 0; font-size: 16px; font-weight: 700; color: var(--ink-900); font-variant-numeric: tabular-nums; }

.rc-items {
  list-style: none;
  margin: 0;
  padding: 4px 20px 12px;
  overflow-y: auto;
  flex: 1;
  min-height: 0;
  border-top: 1px solid var(--border);
}
.rc-items li { padding: 8px 0; border-bottom: 1px dashed var(--border); }
.rc-items li:last-child { border-bottom: none; }
.rc-item-main { display: flex; justify-content: space-between; gap: 12px; font-size: 14px; font-weight: 600; }
.rc-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.rc-item-sub { margin-top: 2px; font-size: 12.5px; color: var(--ink-500); line-height: 1.4; }

.rc-foot { padding: 12px 20px 18px; border-top: 1px solid var(--border); }
.rc-btn {
  width: 100%;
  min-height: 50px;
  border: none;
  border-radius: 13px;
  background: var(--passion-500);
  color: var(--on-accent);
  font-family: var(--font-heading);
  font-size: 18px;
  font-weight: 700;
  cursor: pointer;
}
.rc-btn:hover { filter: brightness(1.05); }
.rc-btn:focus-visible { outline: 3px solid var(--acai-500); outline-offset: 2px; }
</style>
