<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { useOrderQueueStore } from '../stores/orderQueue';
import { useNetworkStore } from '../stores/network';
import { useAuthStore } from '../stores/auth';

const orderQueue = useOrderQueueStore();
const network = useNetworkStore();
const auth = useAuthStore();

// Control de comanda desplegada en línea (sin ventanas emergentes)
const expandedOrders = ref({});

function toggleOrderComanda(orderId) {
  expandedOrders.value[orderId] = !expandedOrders.value[orderId];
}

function isOrderExpanded(orderId) {
  return Boolean(expandedOrders.value[orderId]);
}

const columns = [
  { id: 'received',  title: 'Recibidos',        actionLabel: 'Enviar a preparar →', btnClass: 'btn-state-received',  nextStatus: 'preparing' },
  { id: 'preparing', title: 'Preparando',       actionLabel: 'Marcar listo ✓',      btnClass: 'btn-state-preparing', nextStatus: 'ready' },
  { id: 'ready',     title: 'Listo para enviar', actionLabel: 'Entregar al cliente ✓', btnClass: 'btn-state-ready',     nextStatus: 'delivered' },
  { id: 'delivered', title: 'Entregado',        actionLabel: null,                  btnClass: '',                    nextStatus: null },
];

onMounted(() => {
  orderQueue.startPolling(6000);
});

onUnmounted(() => {
  orderQueue.stopPolling();
});

const allergenMap = {
  'lactose': 'Lactosa',
  'gluten': 'Gluten',
  'almond': 'Almendras',
  'fruit': 'Frutas',
  'egg': 'Huevo',
  'peanut': 'Maní'
};

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

function getDisplayOrderNumber(order) {
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

function getOrderCode(order) {
  if (!order) return '';
  if (order.order_number) return order.order_number;
  const d = order.created_at ? new Date(order.created_at) : new Date();
  const day = String(d.getDate()).padStart(2, '0');
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const year = String(d.getFullYear()).slice(-2);
  const seq = getOrderDailySequence(order);
  return `${day}${month}${year}-${String(seq).padStart(4, '0')}`;
}

function isOrderAllTakeaway(order) {
  if (order.source === 'pedidosya') return true;
  const takeawayCount = order.items ? order.items.filter(i => Boolean(i.is_takeaway)).length : 0;
  const totalCount = order.items ? order.items.length : 0;
  return Boolean(order.is_takeaway) || (totalCount > 0 && takeawayCount === totalCount);
}

function isOrderMixed(order) {
  if (order.source === 'pedidosya') return false;
  if (order.is_mixed) return true;
  const takeawayCount = order.items ? order.items.filter(i => Boolean(i.is_takeaway)).length : 0;
  const totalCount = order.items ? order.items.length : 0;
  return !isOrderAllTakeaway(order) && takeawayCount > 0;
}

function isTakeaway(order) {
  return isOrderAllTakeaway(order);
}

function getCustomerName(order) {
  if (order.customer?.name) return order.customer.name;
  if (order.customer_name) return order.customer_name;
  if (order.source === 'pedidosya') return 'Cliente PedidosYa';
  if (isOrderAllTakeaway(order)) return 'Cliente (Para Llevar)';
  if (isOrderMixed(order)) return 'Cliente (Mesa y Llevar)';
  return 'Cliente en Local';
}

function getItemQuantity(item) {
  const q = Number(item.quantity);
  return isNaN(q) ? 1 : Math.round(q);
}

function getItemToppingsList(item) {
  if (item.modifiers && Array.isArray(item.modifiers) && item.modifiers.length > 0) {
    return item.modifiers.map(m => m.option_name || m.name).filter(Boolean).join(', ');
  }
  const options = item.sale_item_options || item.saleItemOptions;
  if (options && Array.isArray(options) && options.length > 0) {
    return options.map(o => o.option_name_snapshot || o.option?.name || '').filter(Boolean).join(', ');
  }
  return '';
}

function getOrderAllergens(order) {
  const set = new Set();
  if (order.items && Array.isArray(order.items)) {
    for (const it of order.items) {
      let flags = it.allergen_flags;
      if (typeof flags === 'string') {
        try { flags = JSON.parse(flags); } catch { flags = flags.split(',').map(s => s.trim()); }
      }
      if (Array.isArray(flags)) {
        for (const f of flags) {
          if (f) set.add(allergenMap[f] || f);
        }
      }
    }
  }
  return Array.from(set);
}

function getOrderNotesList(order) {
  const notes = [];
  if (order.notes && order.notes.trim()) {
    notes.push(order.notes.trim());
  }
  if (order.items && Array.isArray(order.items)) {
    for (const it of order.items) {
      const note = it.item_note || it.note;
      if (note && note.trim()) {
        notes.push(note.trim());
      }
    }
  }
  return notes;
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

function handleAdvance(order, col) {
  if (!col.nextStatus) return;
  orderQueue.updateOrderStatus(order.id, col.nextStatus);
}

const padR = (str, len) => str.length > len ? str.substring(0, len) : str.padEnd(len, ' ');
const padL = (str, len) => str.length > len ? str.substring(0, len) : str.padStart(len, ' ');
const padC = (str, len) => {
  if (str.length >= len) return str.substring(0, len);
  const left = Math.floor((len - str.length) / 2);
  const right = len - str.length - left;
  return ' '.repeat(left) + str + ' '.repeat(right);
};

function getOrderTicketText(order) {
  if (!order) return '';
  
  const width = 48;
  const sepEq = '='.repeat(width);
  const sepDa = '-'.repeat(width);
  
  let lines = [];
  
  const takeawayCount = order.items ? order.items.filter(i => i.is_takeaway).length : 0;
  const totalCount = order.items ? order.items.length : 0;
  const isOrderTakeaway = Boolean(order.is_takeaway);
  const isAllTakeaway = isOrderTakeaway || (totalCount > 0 && takeawayCount === totalCount);

  let orderTypeStr = "*** MESA ***";
  if (order.source === 'pedidosya') {
    orderTypeStr = "*** PEDIDOSYA DELIVERY ***";
  } else if (isAllTakeaway) {
    orderTypeStr = "*** PARA LLEVAR ***";
  } else if (takeawayCount > 0) {
    orderTypeStr = "*** MIXTO (MESA Y LLEVAR) ***";
  }

  lines.push(sepEq);
  lines.push(padC("** OHANA ACAI **", width));
  lines.push(padC("--- COMANDA DE PREPARACION ---", width));
  lines.push(padC(orderTypeStr, width));
  lines.push(sepEq);
  
  const displayNum = getDisplayOrderNumber(order);
  const codeNum = getOrderCode(order);
  const comandaFull = `${displayNum} (${codeNum})`;

  const tipoStr = order.source === 'pedidosya' ? 'DELIVERY' : (isAllTakeaway ? 'LLEVAR' : (takeawayCount > 0 ? 'MIXTO' : 'MESA'));
  lines.push(`Comanda Nro: ${padR(comandaFull, 21)}Tipo: ${tipoStr}`);
  
  const cashierName = order.cashier?.name || (auth.user && auth.user.name) || 'Admin Operativo';
  const destinoStr = order.source === 'pedidosya' ? 'Delivery' : (isAllTakeaway ? 'Para Llevar' : (takeawayCount > 0 ? 'Mesa / Llevar' : 'Mesa Local'));
  lines.push(`Destino: ${padR(destinoStr, 21)}Atiende: ${padR(cashierName.substring(0, 15), 15)}`);
  
  const d = order.created_at ? new Date(order.created_at) : new Date();
  const dateStr = d.toLocaleDateString('es-BO', { day: '2-digit', month: '2-digit', year: 'numeric' });
  const timeStr = d.toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  
  lines.push(`Fecha: ${padR(dateStr, 22)}Hora: ${timeStr}`);
  lines.push(sepDa);
  
  lines.push(`CANT  ${padR('DESCRIPCION', 33)}${padL('IMPORTE', 7)}`);
  lines.push(sepDa);
  
  let totalItems = 0;
  
  if (order.items) {
    for (const item of order.items) {
      const itemQty = Number(item.quantity) || 1;
      totalItems += itemQty;
      let baseName = (item.name || item.product?.name || 'Item').toUpperCase();
      let sizeMod = null;
      let otherMods = [];

      let itemOptions = [];
      if (item.modifiers && Array.isArray(item.modifiers) && item.modifiers.length > 0) {
        itemOptions = item.modifiers;
      } else {
        const apiOpts = item.sale_item_options || item.saleItemOptions;
        if (apiOpts && Array.isArray(apiOpts) && apiOpts.length > 0) {
          itemOptions = apiOpts.map(o => ({
            group_name: o.option_group?.name || o.optionGroup?.name || '',
            option_name: o.option_name_snapshot || o.option?.name || '',
            quantity: o.quantity || 1,
            price: o.additional_price_snapshot || o.option?.additional_price || 0
          }));
        }
      }

      if (item.is_takeaway) {
        baseName += ` (LLEVAR)`;
      } else {
        baseName += ` (MESA)`;
      }

      const qtyStr = padL(`${itemQty}x`, 4);
      const nameStr = padR(baseName, 33);
      
      const itemSubtotal = Number(item.subtotal) || (Number(item.unit_price || 0) * itemQty);
      const mainSubtotalStr = padL(itemSubtotal.toFixed(2), 7);
      lines.push(`${qtyStr}  ${nameStr}${mainSubtotalStr}`);
      
      if (itemOptions.length > 0) {
        for (const mod of itemOptions) {
          const label = mod.quantity > 1 ? `${mod.quantity}x ${mod.option_name}` : mod.option_name;
          const modNameStr = padR(label, 31);
          const mPrice = (Number(mod.price) || 0) * (mod.quantity || 1);
          const modTotal = mPrice * itemQty;
          const modPriceStr = padL(modTotal.toFixed(2), 7);
          lines.push(`      * ${modNameStr}${modPriceStr}`);
        }
      }
      
      const itemNote = item.item_note || item.note;
      if (itemNote) {
        lines.push(`      * NOTA: ${itemNote}`);
      }
      
      let allergens = item.allergen_flags;
      if (typeof allergens === 'string') {
        try { allergens = JSON.parse(allergens); } catch { allergens = allergens.split(','); }
      }
      if (allergens && Array.isArray(allergens) && allergens.length > 0) {
        const allergenNames = allergens.map(f => allergenMap[f] || f).join(', ');
        lines.push(`      * ALERGIA: ${allergenNames}`);
      }
    }
  }
  
  lines.push(sepDa);
  
  if (order.notes) {
    lines.push("OBSERVACIONES GENERALES:");
    const noteStr = `- ${order.notes}`;
    for (let i = 0; i < noteStr.length; i += width) {
      lines.push(noteStr.substring(i, i + width));
    }
    lines.push(sepDa);
  }
  
  const totalAmt = order.total_amount ? Number(order.total_amount).toFixed(2) : '0.00';
  const totalStr = `TOTAL ITEMS: ${padR(String(totalItems), 16)}TOTAL BOB: ${padL(totalAmt, 8)}`;
  lines.push(totalStr);

  if (takeawayCount > 0 && takeawayCount < totalCount) {
    const dineInCount = totalCount - takeawayCount;
    lines.push(`DESGLOSE:    ${padR(`${dineInCount}x MESA · ${takeawayCount}x LLEVAR`, 33)}`);
  }
  
  lines.push(sepEq);
  
  return lines.join('\n');
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
            <span 
              class="kcol-count"
              :class="{ 'count-alert': orderQueue.ordersByStatus(col.id).length >= 3 }"
            >
              {{ orderQueue.ordersByStatus(col.id).length }}
            </span>
          </div>

            <!-- COLUMN CARDS LIST -->
          <div class="cards-scroller">
            <div 
              v-for="order in orderQueue.ordersByStatus(col.id)" 
              :key="order.id"
              class="order-card"
              :class="[
                `status-${col.id}`,
                { 'alert-urgent': col.id !== 'delivered' && getElapsedMinutes(order.created_at) >= 15 }
              ]"
              @click="openOrderDetail(order)"
            >
              <!-- TOP ROW SEGÚN PUNTO 3: [Local / Mesa]   #014 (270826-0014)   [8 min] -->
              <div class="card-header-row">
                <span 
                  class="platform-chip"
                  :class="order.source === 'pedidosya' ? 'chip-pedidosya' : (isOrderAllTakeaway(order) ? 'chip-takeaway' : (isOrderMixed(order) ? 'chip-mixed' : 'chip-pos'))"
                >
                  <template v-if="order.source === 'pedidosya'">
                    🛵 PedidosYa
                  </template>
                  <template v-else-if="isOrderAllTakeaway(order)">
                    🛍️ Para Llevar
                  </template>
                  <template v-else-if="isOrderMixed(order)">
                    🔀 Mixto
                  </template>
                  <template v-else>
                    🍽️ Local / Mesa
                  </template>
                </span>

                <div class="order-ident-wrap">
                  <span class="order-display-num">{{ getDisplayOrderNumber(order) }}</span>
                  <span class="order-full-code">({{ getOrderCode(order) }})</span>
                </div>

                <span 
                  class="time-badge" 
                  :class="{ 
                    'time-warn': getElapsedMinutes(order.created_at) >= 8 && getElapsedMinutes(order.created_at) < 15,
                    'time-urgent': getElapsedMinutes(order.created_at) >= 15
                  }"
                >
                  <template v-if="getElapsedMinutes(order.created_at) >= 15">
                    ⚠️ {{ formatElapsed(order.created_at) }}
                  </template>
                  <template v-else-if="getElapsedMinutes(order.created_at) >= 8">
                    ⏰ {{ formatElapsed(order.created_at) }}
                  </template>
                  <template v-else>
                    ⏱️ {{ formatElapsed(order.created_at) }}
                  </template>
                </span>
              </div>

              <!-- CLIENTE -->
              <div class="cust">{{ getCustomerName(order) }}</div>

              <!-- BOX ÍTEMS A PREPARAR SEGÚN PUNTO 3 -->
              <div class="items-prep-box">
                <div class="items-prep-title">Ítems a preparar</div>
                <div class="items-prep-list">
                  <div 
                    v-for="(item, iIdx) in (order.items || [])" 
                    :key="item.id || iIdx"
                    class="prep-item-row"
                  >
                    <div class="prep-item-main">
                      <span class="prep-bullet">•</span>
                      <span class="prep-qty">{{ getItemQuantity(item) }}x</span>
                      <span class="prep-name">{{ item.name || item.product?.name || 'Producto' }}</span>
                      <span v-if="item.is_takeaway" class="item-prep-takeaway-tag">🛍️ Llevar</span>
                      <span v-else-if="isOrderMixed(order)" class="item-prep-dinein-tag">🍽️ Mesa</span>
                    </div>

                    <!-- Línea └ Toppings -->
                    <div v-if="getItemToppingsList(item)" class="prep-sub-toppings">
                      <span class="prep-sub-arrow">└</span>
                      <span class="prep-sub-text">{{ getItemToppingsList(item) }}</span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- DESPLIEGUE EN LÍNEA DE LA COMANDA (DEBAJO DE ÍTEMS A PREPARAR, SIN VENTANAS EMERGENTES) -->
              <div v-if="isOrderExpanded(order.id)" class="inline-comanda-pane">
                <div class="inline-comanda-header">
                  <span class="inline-comanda-title">🧾 Detalle de Preparación</span>
                </div>
                <pre class="inline-ascii-ticket">{{ getOrderTicketText(order) }}</pre>
              </div>

              <!-- BADGES DE ALERTA: ALÉRGENOS ESPECÍFICOS Y NOTAS -->
              <div v-if="getOrderAllergens(order).length > 0 || getOrderNotesList(order).length > 0" class="card-alerts-strip">
                <span v-for="alg in getOrderAllergens(order)" :key="alg" class="alert-pill allergen">
                  ⚠️ ALERGIA: {{ alg }}
                </span>
                <span v-for="nt in getOrderNotesList(order)" :key="nt" class="alert-pill notes">
                  📝 {{ nt }}
                </span>
              </div>

              <!-- BOTONES DE ACCIÓN: [📋 Ver comanda ▾ / ▴]  [Enviar a preparar ➔] -->
              <div class="card-actions-row">
                <button 
                  type="button" 
                  class="btn-view-detail" 
                  :class="{ 'btn-active': isOrderExpanded(order.id) }"
                  :title="isOrderExpanded(order.id) ? 'Ocultar comanda' : 'Ver comanda desplegada abajo'"
                  @click.stop="toggleOrderComanda(order.id)"
                >
                  <span class="btn-icon">📋</span>
                  <span>{{ isOrderExpanded(order.id) ? 'Ocultar comanda ▴' : 'Ver comanda ▾' }}</span>
                </button>

                <button 
                  v-if="col.id !== 'delivered'"
                  class="btn-advance"
                  :class="col.btnClass"
                  @click.stop="handleAdvance(order, col)"
                >
                  <template v-if="col.id === 'ready' && order.source === 'pedidosya'">
                    Retiró ✓
                  </template>
                  <template v-else>
                    {{ col.actionLabel }}
                  </template>
                </button>
              </div>

              <!-- FOOTER PARA ENTREGADOS -->
              <div class="delivered-footer" v-if="col.id === 'delivered'">
                <span class="delivered-badge">
                  ✓ Entregado a las {{ formatTime(order.delivered_at || order.updated_at) }}
                </span>
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
  background: var(--surface);
  border: 1px solid var(--border);
  border-top: 4px solid var(--border);
  border-radius: 18px;
  padding: 14px;
  display: flex;
  flex-direction: column;
  min-height: 0;
  transition: background-color 0.2s ease, border-color 0.2s ease;
}

/* Fondos semánticos por columna */
.kcol.col-received {
  background: var(--state-received-bg);
  border-top-color: var(--state-received-border);
}

.kcol.col-preparing {
  background: var(--state-preparing-bg);
  border-top-color: var(--state-preparing-border);
}

.kcol.col-ready {
  background: var(--state-ready-bg);
  border-top-color: var(--state-ready-border);
}

.kcol.col-delivered {
  background: var(--state-delivered-bg);
  border-top-color: var(--state-delivered-border);
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
  transition: all 0.2s ease;
}

.kcol-count.count-alert {
  background: var(--passion-500);
  color: white;
  border-color: var(--passion-600);
  box-shadow: 0 2px 6px rgba(251, 120, 16, 0.35);
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
  border-left: 6px solid var(--passion-500);
  display: flex;
  flex-direction: column;
  gap: 6px;
  transition: transform 0.15s, box-shadow 0.15s, border-color 0.2s;
}

.order-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(0,0,0,0.08);
}

/* Status borders */
.order-card.status-received {
  border-left-color: var(--state-received-border, #3B82F6) !important;
}

.order-card.status-preparing {
  border-left-color: var(--state-preparing-border, #F59E0B) !important;
}

.order-card.status-ready {
  border-left-color: var(--state-ready-border, #22C55E) !important;
}

.order-card.status-delivered {
  border-left-color: var(--state-delivered-border, #CBD5E1) !important;
  opacity: 0.8;
}

.order-card.alert-urgent {
  border-top-color: var(--danger-500, #E5484D);
  border-right-color: var(--danger-500, #E5484D);
  border-bottom-color: var(--danger-500, #E5484D);
  animation: pulseUrgent 2s infinite ease-in-out;
}

@keyframes pulseUrgent {
  0%, 100% { box-shadow: 0 0 0 0 rgba(229, 72, 77, 0.4); }
  50% { box-shadow: 0 0 0 5px rgba(229, 72, 77, 0); }
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
  padding: 3px 9px;
  border-radius: 999px;
  letter-spacing: 0.02em;
}

.chip-pedidosya {
  background: #EA1D2C !important;
  color: #FFFFFF !important;
  box-shadow: 0 1px 4px rgba(234, 29, 44, 0.25);
}

.chip-takeaway {
  background: var(--gold-100, #FBF1D2) !important;
  color: #8A6A00 !important;
  border: 1px solid rgba(217, 164, 4, 0.3);
}

.chip-mixed {
  background: #F5F3FF !important;
  color: #7C3AED !important;
  border: 1px solid #DDD6FE !important;
}

:global(html.dark) .chip-mixed {
  background: rgba(139, 92, 246, 0.25) !important;
  color: #C4B5FD !important;
  border-color: rgba(139, 92, 246, 0.45) !important;
}

.chip-pos {
  background: #EFF6FF !important;
  color: #1D4ED8 !important;
  border: 1px solid #BFDBFE;
}

.time-badge {
  font-size: 11px;
  font-weight: 700;
  color: var(--ink-500);
  padding: 2px 8px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  background: var(--cream-200, #F1EFEC);
}

.time-warn {
  background: var(--time-warn-bg, #FEF3C7) !important;
  color: var(--time-warn-text, #B45309) !important;
  font-weight: 800;
  border: 1px solid rgba(245, 158, 11, 0.3);
}

.time-urgent {
  background: var(--time-urgent-bg, #FEE2E2) !important;
  color: var(--time-urgent-text, #DC2626) !important;
  font-weight: 800;
  border: 1px solid var(--danger-500, #E5484D);
}

.card-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 4px;
}

.order-ident-wrap {
  display: flex;
  align-items: baseline;
  gap: 5px;
}

.order-display-num {
  font-family: 'Baloo 2', sans-serif;
  font-size: 20px;
  font-weight: 800;
  color: var(--ink-900);
  line-height: 1;
}

.order-full-code {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  font-weight: 700;
  color: var(--ink-500);
}

.cust {
  font-weight: 700;
  font-size: 13.5px;
  color: var(--ink-700);
  margin-bottom: 6px;
}

/* BOX ÍTEMS A PREPARAR SEGÚN PUNTO 3 */
.items-prep-box {
  background: var(--cream-50, #FAF8F5);
  border: 1px solid var(--border, #E2E8F0);
  border-radius: 10px;
  padding: 8px 10px;
  margin: 2px 0 6px;
}

.items-prep-title {
  font-size: 10.5px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--ink-500);
  margin-bottom: 6px;
}

.items-prep-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.prep-item-row {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.prep-item-main {
  display: flex;
  align-items: baseline;
  gap: 6px;
  font-size: 13px;
  font-weight: 700;
  color: var(--ink-900);
}

.prep-bullet {
  color: var(--passion-500, #F97316);
  font-size: 13px;
  line-height: 1;
}

.item-prep-takeaway-tag {
  font-size: 10px;
  font-weight: 800;
  color: #C2410C;
  background: #FFEDD5;
  border: 1px solid #FDBA74;
  padding: 1px 6px;
  border-radius: 6px;
  margin-left: 6px;
  display: inline-flex;
  align-items: center;
  line-height: 1.2;
}

:global(html.dark) .item-prep-takeaway-tag {
  background: rgba(234, 88, 12, 0.2);
  border-color: rgba(234, 88, 12, 0.4);
  color: #FB923C;
}

.item-prep-dinein-tag {
  font-size: 10px;
  font-weight: 800;
  color: #475569;
  background: #F1F5F9;
  border: 1px solid #CBD5E1;
  padding: 1px 6px;
  border-radius: 6px;
  margin-left: 6px;
  display: inline-flex;
  align-items: center;
  line-height: 1.2;
}

:global(html.dark) .item-prep-dinein-tag {
  background: #251c33;
  border-color: rgba(255, 255, 255, 0.15);
  color: #CBD5E1;
}

.prep-qty {
  background: var(--acai-700, #20112F);
  color: #ffffff;
  font-size: 10.5px;
  font-weight: 800;
  padding: 1px 5px;
  border-radius: 4px;
  line-height: 1.2;
}

.prep-name {
  color: var(--ink-900);
}

.prep-sub-toppings {
  display: flex;
  align-items: baseline;
  gap: 6px;
  padding-left: 14px;
  font-size: 11.5px;
  color: var(--ink-600);
}

.prep-sub-arrow {
  color: var(--ink-400, #94A3B8);
  font-weight: 800;
}

.prep-sub-text {
  font-style: italic;
}

/* BADGES DE ALERTA */
.card-alerts-strip {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin: 4px 0 6px;
}

.alert-pill {
  font-size: 10.5px;
  font-weight: 800;
  padding: 3px 8px;
  border-radius: 6px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.alert-pill.allergen {
  background: #FEE2E2;
  color: #DC2626;
  border: 1px solid #FCA5A5;
}

.alert-pill.notes {
  background: #FEF3C7;
  color: #B45309;
  border: 1px solid #FDE68A;
}

/* BOTONES DE ACCIÓN */
.card-actions-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-top: 4px;
}

.btn-view-detail {
  background: var(--surface);
  border: 1.5px solid var(--border);
  color: var(--ink-800);
  font-family: inherit;
  font-size: 11.5px;
  font-weight: 700;
  padding: 8px 6px;
  border-radius: 10px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  transition: all 0.15s ease;
  white-space: nowrap;
}

.btn-view-detail:hover {
  background: var(--cream-200);
  border-color: var(--ink-300);
  color: var(--ink-900);
  transform: translateY(-1px);
}

.btn-view-detail.btn-active {
  background: #FEF3C7;
  border-color: #F59E0B;
  color: #92400E;
  font-weight: 800;
}

/* DESPLIEGUE EN LÍNEA DE LA COMANDA (SIN VENTANA EMERGENTE) */
.inline-comanda-pane {
  margin: 8px 0 8px;
  background: #FFFFFF;
  border: 1.5px dashed var(--ink-300, #CBD5E1);
  border-radius: 10px;
  overflow: hidden;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
  animation: expandIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes expandIn {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}

.inline-comanda-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 6px 10px;
  background: var(--cream-100, #F4F1EA);
  border-bottom: 1px solid var(--border, #E2E8F0);
}

.inline-comanda-title {
  font-size: 10.5px;
  font-weight: 800;
  color: var(--ink-800, #1E293B);
  letter-spacing: 0.3px;
  text-transform: uppercase;
}

.inline-ascii-ticket {
  font-family: 'Courier New', Courier, monospace;
  font-size: 10px;
  line-height: 1.3;
  color: #1E293B;
  background: #FAF8F5;
  padding: 8px;
  margin: 0;
  overflow-x: auto;
  white-space: pre;
}

:global(html.dark) .inline-comanda-pane {
  background: #1E1B2E;
  border-color: #4C1D95;
}

:global(html.dark) .inline-comanda-header {
  background: #2A1B3D;
  border-color: #3B2054;
}

:global(html.dark) .inline-ascii-ticket {
  background: #0F0A17;
  color: #E9D5FF;
}

:global(html.dark) .btn-view-detail.btn-active {
  background: #78350F;
  border-color: #B45309;
  color: #FEF3C7;
}

.btn-advance {
  font-family: inherit;
  font-weight: 800;
  font-size: 12px;
  padding: 8px 8px;
  border-radius: 10px;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  transition: all 0.15s ease;
  box-shadow: 0 2px 6px rgba(0,0,0,0.12);
  white-space: nowrap;
}

.btn-advance:active {
  transform: scale(0.98);
}

/* BOTONES SEMÁNTICOS KDS */
.btn-state-received {
  background: var(--state-received-btn, #2563EB) !important;
  color: #FFFFFF !important;
}
.btn-state-received:hover {
  background: var(--state-received-btn-h, #1D4ED8) !important;
  filter: brightness(1.06);
}

.btn-state-preparing {
  background: var(--state-preparing-btn, #D97706) !important;
  color: #FFFFFF !important;
}
.btn-state-preparing:hover {
  background: var(--state-preparing-btn-h, #B45309) !important;
  filter: brightness(1.06);
}

.btn-state-ready {
  background: var(--state-ready-btn, #16A34A) !important;
  color: #FFFFFF !important;
}
.btn-state-ready:hover {
  background: var(--state-ready-btn-h, #15803D) !important;
  filter: brightness(1.06);
}

.delivered-footer {
  text-align: center;
  margin-top: 4px;
}

.delivered-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: var(--lime-100, #E3F5E5);
  color: var(--lime-700, #217A2E);
  font-size: 11.5px;
  font-weight: 700;
  padding: 4px 12px;
  border-radius: 999px;
  border: 1px solid rgba(60, 174, 73, 0.25);
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
