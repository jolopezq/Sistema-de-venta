<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  show: Boolean,
  order: Object,
});

const emit = defineEmits(['close', 'advance', 'update-status']);

// Estado local de checklist de ítems preparados (id del ítem -> boolean)
const completedItems = ref({});

function toggleItem(key) {
  completedItems.value[key] = !completedItems.value[key];
}

function getItemKey(item, index) {
  return item.id || item.product_id || `item_${index}`;
}

const isAllCompleted = computed(() => {
  if (!props.order?.items || props.order.items.length === 0) return false;
  return props.order.items.every((item, idx) => completedItems.value[getItemKey(item, idx)]);
});

function getOrderDailySequence(order) {
  if (!order) return 1;
  if (order.daily_sequence) return order.daily_sequence;
  if (order.order_number && order.order_number.includes('-')) {
    const parts = order.order_number.split('-');
    const seq = parseInt(parts[1], 10);
    if (!isNaN(seq)) return seq;
  }
  return 1;
}

function getDisplayNumber(order) {
  if (!order) return '#001';
  if (order.delivery_order?.external_id) {
    return `#PY-${order.delivery_order.external_id}`;
  }
  if (order.source === 'pedidosya') {
    return `#PY-${order.id ? order.id.substring(0, 4).toUpperCase() : '001'}`;
  }
  const seq = getOrderDailySequence(order);
  return `#${String(seq).padStart(3, '0')}`;
}

function getFullCode(order) {
  if (!order) return '';
  if (order.order_number) return order.order_number;
  const d = order.created_at ? new Date(order.created_at) : new Date();
  const day = String(d.getDate()).padStart(2, '0');
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const year = String(d.getFullYear()).slice(-2);
  const seq = getOrderDailySequence(order);
  return `${day}${month}${year}-${String(seq).padStart(4, '0')}`;
}

function getElapsedMinutes(createdDateStr) {
  if (!createdDateStr) return 0;
  const created = new Date(createdDateStr);
  const now = new Date();
  const diffMs = now - created;
  return Math.max(0, Math.floor(diffMs / 60000));
}

function formatElapsed(createdDateStr) {
  const mins = getElapsedMinutes(createdDateStr);
  if (mins < 60) return `${mins} min`;
  const hrs = Math.floor(mins / 60);
  const remMins = mins % 60;
  if (hrs >= 24) return `> 24h`;
  return `${hrs}h ${remMins}m`;
}

function formatTime(dateStr) {
  if (!dateStr) return '--:--';
  const d = new Date(dateStr);
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleDateString('es-BO', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function getItemQuantity(item) {
  const q = Number(item.quantity);
  return isNaN(q) ? 1 : Math.round(q);
}

function getItemModifiers(item) {
  if (item.modifiers && Array.isArray(item.modifiers) && item.modifiers.length > 0) {
    return item.modifiers.map(m => ({
      group: m.group_name || 'Opción',
      name: m.option_name || m.name,
      quantity: m.quantity || 1
    }));
  }
  const options = item.sale_item_options || item.saleItemOptions;
  if (options && Array.isArray(options) && options.length > 0) {
    return options.map(o => ({
      group: o.option_group?.name || o.optionGroup?.name || 'Opción',
      name: o.option_name_snapshot || o.option?.name || 'Extra',
      quantity: o.quantity || 1
    }));
  }
  return [];
}

const allergenMap = {
  'lactose': 'Lactosa',
  'gluten': 'Gluten',
  'almond': 'Almendras',
  'fruit': 'Frutas',
  'egg': 'Huevo',
  'peanut': 'Maní'
};

function getItemAllergens(item) {
  const raw = item.allergen_flags;
  if (!raw) return [];
  if (Array.isArray(raw)) return raw;
  if (typeof raw === 'string') {
    try {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed)) return parsed;
    } catch {
      return raw.split(',').map(s => s.trim()).filter(Boolean);
    }
  }
  return [];
}

const statusLabels = {
  'received':  { label: 'Recibido',        color: '#2563EB', bg: '#EFF6FF' },
  'preparing': { label: 'En Preparación',  color: '#D97706', bg: '#FFFBEB' },
  'ready':     { label: 'Listo p/ Despacho',color: '#16A34A', bg: '#F0FDF4' },
  'delivered': { label: 'Entregado',       color: '#64748B', bg: '#F1F5F9' },
};

function handleQuickStatus(status) {
  if (!props.order) return;
  emit('update-status', { orderId: props.order.id, status });
}

function printOrder() {
  window.print();
}
</script>

<template>
  <Teleport to="body">
    <div v-if="show && order" class="kds-modal-backdrop" @click.self="emit('close')">
      <div class="kds-modal-card">
        <!-- HEADER EXACTO SEGÚN PUNTO 3 -->
        <div class="kds-head">
          <div class="kds-title-row">
            <div class="kds-ident">
              <span class="kds-big-number">COMANDA {{ getDisplayNumber(order) }}</span>
              <span class="kds-full-code">(Código: {{ getFullCode(order) }})</span>
            </div>

            <div class="kds-source-tag" :class="`src-${order.source}`">
              <template v-if="order.source === 'pedidosya'">
                🛵 PedidosYa
              </template>
              <template v-else-if="order.items?.some(i => i.is_takeaway)">
                🛍️ Para Llevar
              </template>
              <template v-else>
                🍽️ Local / Mesa
              </template>
            </div>

            <button type="button" class="btn-close" @click="emit('close')" title="Cerrar comanda">✕</button>
          </div>

          <div class="kds-meta-row">
            <span>Origen: <strong>{{ order.source === 'pedidosya' ? 'PedidosYa' : (order.items?.some(i => i.is_takeaway) ? 'Para Llevar' : 'Local / Mesa') }}</strong></span>
            <span class="meta-dot">•</span>
            <span>Cajero: <strong>{{ order.cashier?.name || 'Cajero' }}</strong></span>
            <span class="meta-dot">•</span>
            <span>Hora: <strong>{{ formatTime(order.created_at) }}</strong></span>
            <span class="meta-dot">•</span>
            <span>Espera: <strong class="wait-time">{{ formatElapsed(order.created_at) }}</strong></span>
            <span v-if="order.customer?.name" class="meta-dot">•</span>
            <span v-if="order.customer?.name">Cliente: <strong>{{ order.customer.name }}</strong></span>
          </div>

          <!-- SELECTOR DE CAMBIO RÁPIDO DE ESTADO -->
          <div class="kds-status-bar-wrap">
            <span class="status-bar-label">Estado actual:</span>
            <div class="kds-status-bar">
              <button
                v-for="(st, key) in statusLabels"
                :key="key"
                type="button"
                class="status-tab"
                :class="{ active: (order.preparation_status || 'received') === key }"
                :style="(order.preparation_status || 'received') === key ? { backgroundColor: st.color, color: '#fff' } : {}"
                @click="handleQuickStatus(key)"
              >
                {{ (order.preparation_status || 'received') === key ? '● ' : '' }}{{ st.label }}
              </button>
            </div>
          </div>
        </div>

        <!-- BODY: ÍTEMS A PREPARAR (CHECKLIST TÁCTIL) -->
        <div class="kds-body">
          <div class="kds-section-title">
            <span>📋 ÍTEMS A PREPARAR (Checklist táctil):</span>
            <span class="hint">Toca cada producto para marcarlo como listo</span>
          </div>

          <div class="kds-items-list">
            <div
              v-for="(item, idx) in (order.items || [])"
              :key="getItemKey(item, idx)"
              class="kds-item-card"
              :class="{ 'item-checked': completedItems[getItemKey(item, idx)] }"
              @click="toggleItem(getItemKey(item, idx))"
            >
              <div class="item-check-col">
                <div class="item-checkbox" :class="{ checked: completedItems[getItemKey(item, idx)] }">
                  <span v-if="completedItems[getItemKey(item, idx)]">✓</span>
                </div>
              </div>

              <div class="item-content-col">
                <div class="item-header-line">
                  <span class="item-qty">{{ getItemQuantity(item) }}x</span>
                  <span class="item-name">{{ item.name || item.product?.name || 'Producto' }}</span>
                  <span v-if="item.is_takeaway" class="item-takeaway-badge">🛍️ Para Llevar</span>
                  <span v-else class="item-dinein-badge">🍽️ Para Mesa</span>
                </div>

                <!-- OPCIONES Y TOPPINGS DESGLOSADOS -->
                <div v-if="getItemModifiers(item).length > 0" class="item-options-list">
                  <div v-for="(mod, mIdx) in getItemModifiers(item)" :key="mIdx" class="option-row">
                    <span class="opt-bullet">◆</span>
                    <span class="opt-group">{{ mod.group }}:</span>
                    <span class="opt-val">
                      <strong>{{ mod.name }}</strong>
                      <span v-if="mod.quantity > 1" class="opt-qty"> ({{ mod.quantity }}x)</span>
                    </span>
                  </div>
                </div>

                <!-- NOTAS ESPECIALES DEL ÍTEM -->
                <div v-if="item.item_note || item.note" class="item-note-box">
                  <span class="note-icon">📝</span>
                  <span class="note-text"><strong>Nota:</strong> "{{ item.item_note || item.note }}"</span>
                </div>

                <!-- ADVERTENCIAS DE ALÉRGENOS -->
                <div v-if="getItemAllergens(item).length > 0" class="item-allergen-box">
                  <span class="allergen-icon">⚠️</span>
                  <span class="allergen-title">Alérgeno:</span>
                  <span class="allergen-tags">
                    <span v-for="flag in getItemAllergens(item)" :key="flag" class="allergen-pill">
                      {{ allergenMap[flag] || flag }}
                    </span>
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- OBSERVACIONES GENERALES DE LA COMANDA -->
          <div v-if="order.notes" class="order-general-note">
            <div class="general-note-head">💬 Observaciones de Comanda:</div>
            <p class="general-note-body">"{{ order.notes }}"</p>
          </div>

          <!-- DATOS DE DELIVERY SI ES PEDIDOSYA -->
          <div v-if="order.source === 'pedidosya' && order.delivery_order" class="delivery-info-box">
            <div class="delivery-head">🛵 Datos de Despacho (PedidosYa):</div>
            <div class="delivery-grid">
              <div><strong>Cliente:</strong> {{ order.delivery_order.customer_name }}</div>
              <div><strong>Teléfono:</strong> {{ order.delivery_order.customer_phone || 'No registrado' }}</div>
              <div style="grid-column: 1 / -1;"><strong>Dirección:</strong> {{ order.delivery_order.customer_address || 'Entrega estándar' }}</div>
            </div>
          </div>
        </div>

        <!-- FOOTER ACCIONES -->
        <div class="kds-foot">
          <button type="button" class="btn-foot-ghost" @click="printOrder" title="Imprimir comanda térmica">
            🖨️ Imprimir Ticket
          </button>

          <div class="foot-actions-right">
            <button type="button" class="btn-foot-close" @click="emit('close')">
              Cerrar
            </button>

            <button
              v-if="(order.preparation_status || 'received') === 'received'"
              type="button"
              class="btn-foot-primary btn-action-prep"
              @click="emit('advance', order, { nextStatus: 'preparing' }); emit('close');"
            >
              🍳 Enviar a preparar ➔
            </button>

            <button
              v-else-if="order.preparation_status === 'preparing'"
              type="button"
              class="btn-foot-primary btn-action-ready"
              @click="emit('advance', order, { nextStatus: 'ready' }); emit('close');"
            >
              ✓ Marcar listo para entrega
            </button>

            <button
              v-else-if="order.preparation_status === 'ready'"
              type="button"
              class="btn-foot-primary btn-action-deliver"
              @click="emit('advance', order, { nextStatus: 'delivered' }); emit('close');"
            >
              📦 Despachar / Entregar
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.kds-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(18, 10, 26, 0.72);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  z-index: 99999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  animation: fadeIn 0.15s ease-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.kds-modal-card {
  background: var(--surface, #ffffff);
  width: 100%;
  max-width: 680px;
  max-height: 92vh;
  border-radius: 20px;
  box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35);
  border: 1px solid var(--border, #e2e8f0);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  animation: popIn 0.2s cubic-bezier(0.2, 0.8, 0.2, 1);
}

@keyframes popIn {
  from { transform: scale(0.96); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}

/* HEADER */
.kds-head {
  padding: 20px 24px 14px;
  border-bottom: 1px solid var(--border, #e2e8f0);
  background: var(--cream-50, #FAF8F5);
}

.kds-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.kds-ident {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
}

.kds-big-number {
  font-family: 'Baloo 2', sans-serif;
  font-size: 26px;
  font-weight: 800;
  color: var(--ink-900, #1e293b);
  line-height: 1;
}

.kds-full-code {
  font-family: 'JetBrains Mono', monospace;
  font-size: 13px;
  font-weight: 700;
  color: var(--ink-500, #64748b);
}

.kds-source-tag {
  font-size: 12px;
  font-weight: 800;
  padding: 5px 12px;
  border-radius: 999px;
  letter-spacing: 0.02em;
}

.src-pos {
  background: #EFF6FF;
  color: #1D4ED8;
  border: 1px solid #BFDBFE;
}

.src-pedidosya {
  background: #EA1D2C;
  color: #FFFFFF;
}

.btn-close {
  background: transparent;
  border: none;
  font-size: 20px;
  font-weight: 700;
  color: var(--ink-500, #64748b);
  cursor: pointer;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s;
}

.btn-close:hover {
  background: var(--cream-200, #E4E0DC);
  color: var(--ink-900, #000);
}

.kds-meta-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 10px;
  font-size: 13px;
  color: var(--ink-700, #334155);
}

.meta-dot {
  color: var(--ink-300, #cbd5e1);
}

.wait-time {
  color: var(--passion-600, #ea580c);
}

/* STATUS TABS */
.kds-status-bar-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 14px;
}

.status-bar-label {
  font-size: 12px;
  font-weight: 800;
  color: var(--ink-600, #64748b);
  white-space: nowrap;
}

.kds-status-bar {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 6px;
  flex: 1;
  background: var(--surface, #ffffff);
  padding: 4px;
  border-radius: 12px;
  border: 1px solid var(--border, #e2e8f0);
}

.status-tab {
  background: transparent;
  border: none;
  padding: 8px 4px;
  border-radius: 8px;
  font-size: 11.5px;
  font-weight: 700;
  color: var(--ink-600, #64748b);
  cursor: pointer;
  transition: all 0.15s;
  text-align: center;
}

.status-tab:hover {
  background: var(--cream-100, #f1efec);
  color: var(--ink-900, #000);
}

.status-tab.active {
  box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}

/* BODY */
.kds-body {
  padding: 20px 24px;
  overflow-y: auto;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.kds-section-title {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  font-size: 13px;
  font-weight: 800;
  color: var(--ink-800, #334155);
}

.kds-section-title .hint {
  font-size: 11.5px;
  font-weight: 600;
  color: var(--ink-500, #94a3b8);
}

.kds-items-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.kds-item-card {
  background: var(--surface, #ffffff);
  border: 1.5px solid var(--border, #e2e8f0);
  border-radius: 14px;
  padding: 14px 16px;
  display: flex;
  gap: 14px;
  cursor: pointer;
  transition: all 0.15s ease;
  user-select: none;
}

.kds-item-card:hover {
  border-color: var(--passion-400, #FB923C);
  background: var(--cream-50, #FCFBF9);
}

.kds-item-card.item-checked {
  background: #F0FDF4;
  border-color: #86EFAC;
  opacity: 0.75;
}

.kds-item-card.item-checked .item-name {
  text-decoration: line-through;
  color: #166534;
}

.item-check-col {
  display: flex;
  align-items: flex-start;
  padding-top: 2px;
}

.item-checkbox {
  width: 26px;
  height: 26px;
  border-radius: 8px;
  border: 2px solid var(--border, #cbd5e1);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 16px;
  color: #ffffff;
  background: var(--surface, #ffffff);
  transition: all 0.15s;
}

.item-checkbox.checked {
  background: #22C55E;
  border-color: #22C55E;
}

.item-content-col {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.item-header-line {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.item-qty {
  background: var(--acai-700, #20112F);
  color: #ffffff;
  font-size: 13px;
  font-weight: 800;
  padding: 2px 8px;
  border-radius: 6px;
}

.item-name {
  font-size: 16px;
  font-weight: 800;
  color: var(--ink-900, #0f172a);
}

.item-takeaway-badge {
  font-size: 11px;
  font-weight: 700;
  background: #FEF3C7;
  color: #92400E;
  padding: 2px 7px;
  border-radius: 99px;
  border: 1px solid #FDE68A;
}

.item-dinein-badge {
  font-size: 11px;
  font-weight: 700;
  background: #F1F5F9;
  color: #475569;
  padding: 2px 7px;
  border-radius: 99px;
  border: 1px solid #E2E8F0;
}

:global(html.dark) .item-dinein-badge {
  background: #251c33;
  border-color: rgba(255, 255, 255, 0.12);
  color: #CBD5E1;
}

/* OPCIONES Y TOPPINGS */
.item-options-list {
  display: flex;
  flex-direction: column;
  gap: 3px;
  margin-top: 2px;
  padding-left: 4px;
}

.option-row {
  display: flex;
  align-items: baseline;
  gap: 6px;
  font-size: 13.5px;
  color: var(--ink-700, #334155);
}

.opt-bullet {
  font-size: 9px;
  color: var(--passion-500, #F97316);
}

.opt-group {
  font-weight: 600;
  color: var(--ink-500, #64748b);
  min-width: 70px;
}

.opt-qty {
  font-size: 12px;
  color: var(--ink-500, #64748b);
}

/* NOTAS Y ALÉRGENOS */
.item-note-box {
  display: flex;
  align-items: center;
  gap: 6px;
  background: #FFFBEB;
  border: 1px solid #FDE68A;
  border-radius: 8px;
  padding: 6px 10px;
  font-size: 12.5px;
  color: #92400E;
  margin-top: 4px;
}

.item-allergen-box {
  display: flex;
  align-items: center;
  gap: 6px;
  background: #FEE2E2;
  border: 1px solid #F87171;
  border-radius: 8px;
  padding: 6px 10px;
  font-size: 12.5px;
  color: #991B1B;
  margin-top: 4px;
}

.allergen-title {
  font-weight: 800;
  letter-spacing: 0.03em;
}

.allergen-tags {
  display: flex;
  gap: 4px;
}

.allergen-pill {
  background: #DC2626;
  color: #ffffff;
  font-size: 11px;
  font-weight: 800;
  padding: 1px 7px;
  border-radius: 4px;
}

/* NOTA GENERAL */
.order-general-note {
  background: #FFF7ED;
  border: 1px solid #FFEDD5;
  border-left: 4px solid var(--passion-500, #EA580C);
  border-radius: 8px;
  padding: 10px 14px;
}

.general-note-head {
  font-size: 12px;
  font-weight: 800;
  color: #9A3412;
  margin-bottom: 2px;
}

.general-note-body {
  margin: 0;
  font-size: 13.5px;
  font-weight: 600;
  color: #7C2D12;
}

/* DELIVERY BOX */
.delivery-info-box {
  background: #F8FAFC;
  border: 1px solid #E2E8F0;
  border-radius: 8px;
  padding: 10px 14px;
}

.delivery-head {
  font-size: 12px;
  font-weight: 800;
  color: #334155;
  margin-bottom: 4px;
}

.delivery-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 6px;
  font-size: 12.5px;
  color: #475569;
}

/* FOOTER */
.kds-foot {
  padding: 16px 24px;
  border-top: 1px solid var(--border, #e2e8f0);
  background: var(--cream-50, #FAF8F5);
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.foot-actions-right {
  display: flex;
  gap: 10px;
}

.btn-foot-ghost {
  background: var(--surface, #ffffff);
  border: 1px solid var(--border, #cbd5e1);
  padding: 10px 16px;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 700;
  color: var(--ink-700, #334155);
  cursor: pointer;
  transition: all 0.15s;
}

.btn-foot-ghost:hover {
  background: var(--cream-100, #f1efec);
}

.btn-foot-close {
  background: transparent;
  border: 1px solid var(--border, #cbd5e1);
  padding: 10px 18px;
  border-radius: 10px;
  font-size: 13.5px;
  font-weight: 700;
  color: var(--ink-700, #334155);
  cursor: pointer;
  transition: all 0.15s;
}

.btn-foot-close:hover {
  background: var(--cream-200, #e2e8f0);
}

.btn-foot-primary {
  border: none;
  padding: 10px 20px;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 800;
  color: #ffffff;
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(0,0,0,0.15);
  transition: all 0.15s;
}

.btn-action-prep {
  background: #2563EB;
}
.btn-action-prep:hover {
  background: #1D4ED8;
}

.btn-action-ready {
  background: #16A34A;
}
.btn-action-ready:hover {
  background: #15803D;
}

.btn-action-deliver {
  background: #C2410C;
}
.btn-action-deliver:hover {
  background: #9A3412;
}
</style>
