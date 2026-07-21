<script setup>

const mockOrders = [
  {
    id: '#PY-4521',
    status: 'received',
    customer: 'Juan Pérez',
    items: '2x Aloha Bowl (G) · 1x Cappuccino',
    platform: 'PedidosYa',
  },
  {
    id: '#PY-4529',
    status: 'received',
    customer: 'Lucía Fernández',
    items: '1x Açaí por gramo (400g) · 1x Alfajor',
    platform: 'PedidosYa',
  },
  {
    id: '#PY-4517',
    status: 'preparing',
    customer: 'Marco Vidal',
    items: '1x Patriota Bowl (Ohana) · 2x Panini',
    platform: 'PedidosYa',
  },
  {
    id: '#PY-4501',
    status: 'ready',
    customer: 'Andrea Salas',
    items: '3x Classic Bowl (M)',
    platform: 'PedidosYa',
  },
  {
    id: '#PY-4488',
    status: 'delivered',
    customer: 'Rodrigo',
    items: '1x Hawaiian Bowl (J)',
    platform: 'PedidosYa',
  }
];

const columns = [
  { id: 'received', title: 'Recibidos' },
  { id: 'preparing', title: 'Preparando' },
  { id: 'ready', title: 'Listo para enviar' },
  { id: 'delivered', title: 'Entregado' },
];

function getOrdersByStatus(status) {
  return mockOrders.filter(o => o.status === status);
}

</script>

<template>
  <div class="delivery-layout">
    <div class="kanban-wrap">
      <div class="kanban-title">
        <h2>Cola de pedidos</h2>
      </div>
      <div class="kanban-cols">
        
        <div class="kcol" v-for="col in columns" :key="col.id">
          <div class="kcol-head">
            <h4>{{ col.title }}</h4>
            <span class="kcol-count">{{ getOrdersByStatus(col.id).length }}</span>
          </div>
          
          <div 
            class="order-card" 
            v-for="order in getOrdersByStatus(col.id)" 
            :key="order.id"
            :style="col.id === 'preparing' ? 'border-left-color:var(--gold-500);' : (col.id === 'ready' ? 'border-left-color:var(--lime-500);' : (col.id === 'delivered' ? 'border-left-color:var(--ink-300);opacity:0.75;' : ''))"
          >
            <span class="platform-chip">🛵 {{ order.platform }}</span>
            <div class="oid">{{ order.id }}</div>
            <div class="cust">{{ order.customer }}</div>
            <div class="items">{{ order.items }}</div>
            <div class="oactions">
              <button class="btn btn-primary btn-sm" v-if="col.id === 'received'">Enviar a preparar</button>
              <button class="btn btn-success btn-sm" v-if="col.id === 'preparing'">Marcar listo</button>
              <button class="btn btn-primary btn-sm" v-if="col.id === 'ready'">Repartidor retiró</button>
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
  height: 100vh;
  width: 100vw;
  background-color: var(--cream-100);
  overflow: hidden;
}

.pos-header {
  background: var(--acai-900);
  color: white;
  padding: 12px 24px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.pos-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  font-family: 'Baloo 2';
  font-weight: 700;
  font-size: 20px;
}
.logo-chip {
  width: 32px;
  height: 32px;
  background: var(--surface);
  border-radius: 8px;
  background-image: var(--logo-uri);
  background-size: 72%;
  background-position: center;
  background-repeat: no-repeat;
}
.pos-header-right {
  display: flex;
  align-items: center;
}
.sync-pill {
  display: flex;
  align-items: center;
  gap: 8px;
  background: rgba(255,255,255,0.1);
  padding: 6px 14px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
}
.btn-ghost {
  cursor: pointer;
  background: transparent;
  border: none;
  font-weight: 700;
  color: var(--ink-700);
  padding: 8px 16px;
  border-radius: 8px;
  transition: background 0.2s;
}
.btn-ghost:hover {
  background: rgba(255,255,255,0.1) !important;
}

.kanban-wrap {
  padding: 24px 26px;
  flex: 1;
  overflow-y: auto;
}
.kanban-title {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 18px;
}
.kanban-title h2 {
  margin: 0;
  font-size: 20px;
  color: var(--ink-900);
}
.kanban-cols {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
}
.kcol {
  background: var(--cream-50);
  border-radius: 16px;
  padding: 12px;
  min-height: 520px;
}
.kcol-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
  padding: 4px 6px;
}
.kcol-head h4 {
  margin: 0;
  font-size: 13px;
  font-family: 'Baloo 2', sans-serif;
  color: var(--ink-900);
}
.kcol-count {
  background: var(--surface);
  border-radius: 999px;
  padding: 2px 9px;
  font-size: 11px;
  font-weight: 800;
  color: var(--ink-500);
}
.order-card {
  background: var(--surface);
  border-radius: 12px;
  padding: 12px 13px;
  margin-bottom: 10px;
  box-shadow: var(--shadow-card);
  border-left: 4px solid var(--passion-500);
}
.order-card .oid {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  color: var(--ink-500);
}
.order-card .cust {
  font-weight: 700;
  font-size: 13.5px;
  margin: 4px 0 2px;
  color: var(--ink-900);
}
.order-card .items {
  font-size: 12px;
  color: var(--ink-500);
  margin-bottom: 8px;
  line-height: 1.4;
}
.order-card .oactions {
  display: flex;
  gap: 6px;
}
.order-card .oactions button {
  flex: 1;
  font-size: 11.5px;
  padding: 7px 4px;
}
.platform-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: var(--danger-100);
  color: #B23A00;
  font-size: 10px;
  font-weight: 800;
  padding: 2px 8px;
  border-radius: 999px;
  margin-bottom: 6px;
}
.btn-primary {
  background: var(--passion-500);
  color: white;
  border: none;
  border-radius: var(--radius-sm);
  font-weight: 700;
  cursor: pointer;
}
.btn-primary:hover {
  background: var(--passion-600);
}
.btn-success {
  background: var(--lime-500);
  color: white;
  border: none;
  border-radius: var(--radius-sm);
  font-weight: 700;
  cursor: pointer;
}
.btn-success:hover {
  background: var(--lime-600);
}
</style>
