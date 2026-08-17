<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { useOrderQueueStore } from '../stores/orderQueue';
import { useNetworkStore } from '../stores/network';

const orderQueue = useOrderQueueStore();
const network = useNetworkStore();

const columns = [
  { id: 'received',  title: 'Recibidos',        actionLabel: 'Enviar a preparar',  btnClass: 'btn-primary', nextStatus: 'preparing' },
  { id: 'preparing', title: 'Preparando',       actionLabel: 'Marcar listo',       btnClass: 'btn-success', nextStatus: 'ready' },
  { id: 'ready',     title: 'Listo para enviar', actionLabel: 'Repartidor retiró', btnClass: 'btn-primary', nextStatus: 'delivered' },
  { id: 'delivered', title: 'Entregado',        actionLabel: null,                 btnClass: '',            nextStatus: null },
];

onMounted(() => {
  orderQueue.startPolling(6000);
});

onUnmounted(() => {
  orderQueue.stopPolling();
});

function getShortOrderId(order) {
  if (order.delivery_order?.external_id) {
    return `#PY-${order.delivery_order.external_id}`;
  }
  if (order.source === 'pedidosya') {
    return `#PY-${order.id ? order.id.substring(0, 6).toUpperCase() : '---'}`;
  }
  return `#POS-${order.id ? order.id.substring(0, 6).toUpperCase() : '---'}`;
}

function getCustomerName(order) {
  if (order.customer?.name) return order.customer.name;
  if (order.source === 'pedidosya') return 'Cliente PedidosYa';
  if (order.items?.some(i => i.is_takeaway)) return 'Cliente (Para Llevar)';
  return 'Cliente en Local';
}

function formatItemsSummary(order) {
  if (!order.items || order.items.length === 0) return 'Sin productos especificados';
  return order.items.map(item => {
    const qty = Number(item.quantity);
    const name = item.name || item.product?.name || 'Producto';
    return `${qty}x ${name}`;
  }).join(' · ');
}

function getElapsedMinutes(createdDateStr) {
  if (!createdDateStr) return 0;
  const created = new Date(createdDateStr);
  const now = new Date();
  const diffMs = now - created;
  return Math.max(0, Math.floor(diffMs / 60000));
}

function formatTime(dateStr) {
  if (!dateStr) return '--:--';
  const d = new Date(dateStr);
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function isTakeaway(order) {
  if (order.source === 'pedidosya') return true;
  return order.items?.some(i => i.is_takeaway) || false;
}

function handleAdvance(order, col) {
  if (!col.nextStatus) return;
  orderQueue.updateOrderStatus(order.id, col.nextStatus);
}
</script>

<template>
  <div class="delivery-layout">
    <div class="kanban-wrap">
      <!-- HEADER CON TÍTULO Y FILTROS -->
      <div class="kanban-header">
        <div class="header-titles">
          <h2>Cola de pedidos</h2>
          <p class="subtitle">Control de preparación y despacho de pedidos en tiempo real (KDS)</p>
        </div>

        <div class="header-controls">
          <!-- FILTROS POR ORIGEN -->
          <div class="source-pills">
            <button 
              class="pill" 
              :class="{ active: orderQueue.filterSource === 'all' }"
              @click="orderQueue.filterSource = 'all'; orderQueue.fetchOrders()"
            >
              Todos los pedidos
            </button>
            <button 
              class="pill" 
              :class="{ active: orderQueue.filterSource === 'pedidosya' }"
              @click="orderQueue.filterSource = 'pedidosya'; orderQueue.fetchOrders()"
            >
              🛵 PedidosYa
            </button>
            <button 
              class="pill" 
              :class="{ active: orderQueue.filterSource === 'pos' }"
              @click="orderQueue.filterSource = 'pos'; orderQueue.fetchOrders()"
            >
              🍽️ POS / Local
            </button>
          </div>

          <!-- BOTÓN DE ACTUALIZACIÓN MANUAL -->
          <button 
            class="btn-refresh" 
            :class="{ spinning: orderQueue.loading }" 
            @click="orderQueue.fetchOrders"
            title="Actualizar pedidos"
          >
            🔄
          </button>
        </div>
      </div>

      <!-- KANBAN COLUMNS -->
      <div class="kanban-cols">
        <div 
          class="kcol" 
          v-for="col in columns" 
          :key="col.id"
          :class="`col-${col.id}`"
        >
          <!-- COLUMN HEADER -->
          <div class="kcol-head">
            <h4>{{ col.title }}</h4>
            <span class="kcol-count">{{ orderQueue.ordersByStatus(col.id).length }}</span>
          </div>

          <!-- COLUMN CARDS LIST -->
          <div class="cards-scroller">
            <div 
              v-for="order in orderQueue.ordersByStatus(col.id)" 
              :key="order.id"
              class="order-card"
              :class="[
                `status-${col.id}`,
                { 'alert-urgent': col.id !== 'delivered' && getElapsedMinutes(order.created_at) >= 12 }
              ]"
            >
              <!-- BADGES TOP ROW -->
              <div class="card-badges-row">
                <span 
                  class="platform-chip"
                  :class="order.source === 'pedidosya' ? 'chip-pedidosya' : (isTakeaway(order) ? 'chip-takeaway' : 'chip-pos')"
                >
                  <template v-if="order.source === 'pedidosya'">
                    🛵 PedidosYa
                  </template>
                  <template v-else-if="isTakeaway(order)">
                    🛍️ Para Llevar
                  </template>
                  <template v-else>
                    🍽️ Local / Mesa
                  </template>
                </span>

                <!-- TIEMPO TRANSCURRIDO -->
                <span 
                  class="time-badge" 
                  :class="{ 
                    'time-warn': getElapsedMinutes(order.created_at) >= 8 && getElapsedMinutes(order.created_at) < 12,
                    'time-urgent': getElapsedMinutes(order.created_at) >= 12
                  }"
                >
                  ⏱️ {{ getElapsedMinutes(order.created_at) }} min
                </span>
              </div>

              <!-- ID Y CLIENTE -->
              <div class="oid">{{ getShortOrderId(order) }}</div>
              <div class="cust">{{ getCustomerName(order) }}</div>

              <!-- ITEMS DETALLE -->
              <div class="items-box">
                <div class="items-text">{{ formatItemsSummary(order) }}</div>

                <!-- TOPPINGS O MODIFICADORES SI EXISTEN -->
                <div 
                  v-if="order.items && order.items.some(i => (i.modifiers && i.modifiers.length > 0) || (i.sale_item_options && i.sale_item_options.length > 0))" 
                  class="modifiers-chips"
                >
                  <template v-for="item in order.items" :key="item.id || item.product_id">
                    <template v-if="item.modifiers">
                      <span v-for="(m, midx) in item.modifiers" :key="midx" class="mod-pill">
                        + {{ m.option_name }}
                      </span>
                    </template>
                    <template v-else-if="item.sale_item_options">
                      <span v-for="(o, oidx) in item.sale_item_options" :key="oidx" class="mod-pill">
                        + {{ o.option?.name }}
                      </span>
                    </template>
                  </template>
                </div>

                <!-- NOTAS DE COCINA SI EXISTEN -->
                <div v-if="order.notes || order.items?.some(i => i.item_note)" class="order-notes">
                  <span v-if="order.notes">📝 {{ order.notes }}</span>
                  <template v-for="item in (order.items || [])" :key="item.id">
                    <span v-if="item.item_note">📝 {{ item.name }}: {{ item.item_note }}</span>
                  </template>
                </div>
              </div>

              <!-- BOTÓN DE ACCIÓN PARA AVANZAR ESTADO -->
              <div class="oactions" v-if="col.id !== 'delivered'">
                <button 
                  class="btn"
                  :class="col.btnClass"
                  @click="handleAdvance(order, col)"
                >
                  <template v-if="col.id === 'ready' && order.source !== 'pedidosya'">
                    Entregar al cliente ✓
                  </template>
                  <template v-else>
                    {{ col.actionLabel }}
                  </template>
                </button>
              </div>

              <!-- FOOTER PARA ENTREGADOS -->
              <div class="delivered-footer" v-else>
                <small>Entregado a las {{ formatTime(order.delivered_at || order.updated_at) }}</small>
              </div>
            </div>

            <!-- EMPTY STATE POR COLUMNA -->
            <div v-if="orderQueue.ordersByStatus(col.id).length === 0" class="empty-col">
              <span class="empty-icon">☕</span>
              <p>Sin pedidos en esta etapa</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.delivery-layout {
  display: flex;
  flex-direction: column;
  height: 100%;
  width: 100%;
  background-color: var(--cream-50);
  overflow: hidden;
}

.kanban-wrap {
  padding: 22px 26px;
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* HEADER */
.kanban-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  flex-wrap: wrap;
  gap: 14px;
}

.header-titles h2 {
  margin: 0;
  font-size: 22px;
  color: var(--ink-900);
}

.subtitle {
  margin: 2px 0 0;
  font-size: 13px;
  color: var(--ink-600);
}

.header-controls {
  display: flex;
  align-items: center;
  gap: 12px;
}

.source-pills {
  display: flex;
  background: var(--surface);
  border: 1.5px solid var(--border);
  padding: 4px;
  border-radius: 12px;
  gap: 4px;
}

.pill {
  background: transparent;
  border: none;
  padding: 6px 12px;
  border-radius: 8px;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--ink-600);
  cursor: pointer;
  transition: all 0.15s;
}

.pill:hover {
  color: var(--ink-900);
}

.pill.active {
  background: var(--passion-500);
  color: white;
  box-shadow: 0 2px 6px rgba(229, 72, 77, 0.25);
}

.btn-refresh {
  background: var(--surface);
  border: 1.5px solid var(--border);
  width: 38px;
  height: 38px;
  border-radius: 10px;
  font-size: 16px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: transform 0.2s;
}

.btn-refresh:hover {
  background: var(--cream-200);
}

.btn-refresh.spinning {
  animation: spin 1s infinite linear;
}

@keyframes spin {
  100% { transform: rotate(360deg); }
}

/* KANBAN GRID */
.kanban-cols {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  flex: 1;
  min-height: 0;
}

.kcol {
  background: var(--surface-alt);
  border: 1px solid var(--border);
  border-radius: 18px;
  padding: 14px;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.kcol-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  padding: 4px 6px;
}

.kcol-head h4 {
  margin: 0;
  font-size: 14px;
  font-family: 'Baloo 2', sans-serif;
  font-weight: 800;
  color: var(--ink-900);
}

.kcol-count {
  background: var(--surface);
  border-radius: 999px;
  padding: 2px 10px;
  font-size: 12px;
  font-weight: 800;
  color: var(--ink-600);
  border: 1px solid var(--border);
}

.cards-scroller {
  flex: 1;
  overflow-y: auto;
  padding-right: 4px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

/* ORDER CARD */
.order-card {
  background: var(--surface);
  border-radius: 14px;
  padding: 14px;
  box-shadow: var(--shadow-card);
  border: 1px solid var(--border);
  border-left: 5px solid var(--passion-500);
  display: flex;
  flex-direction: column;
  gap: 6px;
  transition: transform 0.15s, box-shadow 0.15s;
}

.order-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(0,0,0,0.08);
}

/* Status borders */
.order-card.status-received {
  border-left-color: var(--passion-500);
}

.order-card.status-preparing {
  border-left-color: var(--gold-500);
}

.order-card.status-ready {
  border-left-color: var(--lime-500);
}

.order-card.status-delivered {
  border-left-color: var(--ink-300);
  opacity: 0.8;
}

.order-card.alert-urgent {
  animation: pulseUrgent 2s infinite ease-in-out;
}

@keyframes pulseUrgent {
  0%, 100% { box-shadow: 0 0 0 0 rgba(229, 72, 77, 0.4); }
  50% { box-shadow: 0 0 0 6px rgba(229, 72, 77, 0); }
}

.card-badges-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 2px;
}

.platform-chip {
  display: inline-flex;
  align-items: center;
  font-size: 11px;
  font-weight: 800;
  padding: 2px 8px;
  border-radius: 999px;
}

.chip-pedidosya {
  background: var(--danger-100);
  color: #B23A00;
}

.chip-takeaway {
  background: var(--gold-100);
  color: #8A6A00;
}

.chip-pos {
  background: var(--passion-100);
  color: var(--passion-800);
}

.time-badge {
  font-size: 11px;
  font-weight: 700;
  color: var(--ink-500);
}

.time-warn {
  color: var(--gold-600);
  font-weight: 800;
}

.time-urgent {
  color: var(--danger-600);
  font-weight: 800;
}

.oid {
  font-family: 'JetBrains Mono', monospace;
  font-size: 12px;
  font-weight: 700;
  color: var(--ink-500);
}

.cust {
  font-weight: 800;
  font-size: 14.5px;
  color: var(--ink-900);
}

.items-box {
  background: var(--surface-alt);
  border-radius: 8px;
  padding: 8px 10px;
  margin: 2px 0 6px;
}

.items-text {
  font-size: 12.5px;
  font-weight: 600;
  color: var(--ink-800);
  line-height: 1.4;
}

.modifiers-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 6px;
}

.mod-pill {
  background: var(--cream-200);
  color: var(--ink-700);
  font-size: 10.5px;
  font-weight: 600;
  padding: 1px 6px;
  border-radius: 4px;
}

.order-notes {
  margin-top: 6px;
  font-size: 11px;
  color: var(--passion-700);
  font-style: italic;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.oactions {
  display: flex;
  margin-top: 4px;
}

.oactions button {
  width: 100%;
  font-family: inherit;
  font-weight: 800;
  font-size: 13px;
  padding: 10px;
  border-radius: 10px;
  border: none;
  cursor: pointer;
  transition: all 0.15s;
}

.btn-primary {
  background: var(--passion-500);
  color: white;
}

.btn-primary:hover {
  background: var(--passion-600);
}

.btn-success {
  background: var(--lime-500);
  color: white;
}

.btn-success:hover {
  background: var(--lime-600);
}

.delivered-footer {
  text-align: center;
  color: var(--ink-400);
  font-size: 11px;
  margin-top: 2px;
}

.empty-col {
  text-align: center;
  padding: 40px 10px;
  color: var(--ink-400);
}

.empty-icon {
  font-size: 28px;
  opacity: 0.5;
  display: block;
  margin-bottom: 6px;
}

.empty-col p {
  margin: 0;
  font-size: 12px;
  font-weight: 600;
}

@media (max-width: 1200px) {
  .kanban-cols {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 768px) {
  .kanban-cols {
    grid-template-columns: 1fr;
  }
  .kanban-wrap {
    padding: 14px;
  }
}
</style>
