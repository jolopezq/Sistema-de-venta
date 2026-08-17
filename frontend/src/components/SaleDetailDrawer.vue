<script setup>
import { ref, computed } from 'vue';
import { useTurnoStore } from '../stores/turno';
import { useAuthStore } from '../stores/auth';

const emit = defineEmits(['close', 'voided']);
const turnoStore = useTurnoStore();
const authStore = useAuthStore();

const sale = computed(() => turnoStore.selectedSale);

const showVoidModal = ref(false);
const voidReason = ref('');
const isVoiding = ref(false);
const voidError = ref('');

function formatDate(dateStr) {
  if (!dateStr) return '--';
  const d = new Date(dateStr);
  return d.toLocaleString('es-BO', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function getPaymentLabel(method) {
  const map = {
    cash: '💵 Efectivo',
    qr: '📱 QR / Transferencia',
    card: '💳 Tarjeta de Débito/Crédito',
  };
  return map[method] || method;
}

async function handleConfirmVoid() {
  if (!voidReason.value.trim()) {
    voidError.value = 'El motivo de anulación es obligatorio.';
    return;
  }

  isVoiding.value = true;
  voidError.value = '';

  try {
    await turnoStore.voidSale(sale.value.id, voidReason.value.trim());
    showVoidModal.value = false;
    voidReason.value = '';
    emit('voided', sale.value.id);
  } catch (err) {
    voidError.value = err.message || 'Error al anular la venta.';
  } finally {
    isVoiding.value = false;
  }
}
</script>

<template>
  <div v-if="turnoStore.isDrawerOpen && sale" class="drawer-overlay" @click.self="emit('close')">
    <div class="drawer-panel">
      <!-- DRAWER HEADER -->
      <div class="drawer-header">
        <div>
          <div class="sale-status-badge" :class="sale.status">
            {{ sale.status === 'completed' ? '✅ Completada' : '❌ Anulada' }}
          </div>
          <h3>Ticket #{{ sale.id ? sale.id.substring(0, 8).toUpperCase() : '---' }}</h3>
          <p class="drawer-subtitle">{{ formatDate(sale.created_at) }} · Atendido por: <strong>{{ sale.cashier?.name || 'Cajero' }}</strong></p>
        </div>
        <button class="btn-close" @click="emit('close')">✕</button>
      </div>

      <!-- DRAWER BODY -->
      <div class="drawer-body">
        <!-- ANULACIÓN ALERTA SI APLICA -->
        <div v-if="sale.status === 'voided'" class="voided-alert">
          <div class="void-title">⚠️ Venta Anulada</div>
          <div class="void-info">
            <span>Motivo: <strong>"{{ sale.void_reason || 'Sin motivo' }}"</strong></span>
            <span v-if="sale.voided_by_user">Anulada por: <strong>{{ sale.voided_by_user.name }}</strong></span>
          </div>
        </div>

        <!-- DATOS DEL CLIENTE -->
        <div class="info-card" v-if="sale.customer">
          <h4>👤 Cliente</h4>
          <div class="row">
            <span>Nombre:</span>
            <strong>{{ sale.customer.name }}</strong>
          </div>
          <div class="row" v-if="sale.customer.ci_or_phone">
            <span>CI / Teléfono:</span>
            <span>{{ sale.customer.ci_or_phone }}</span>
          </div>
        </div>

        <!-- ITEMS DETALLADOS -->
        <div class="info-card">
          <h4>🛍️ Productos Comprados ({{ sale.items?.length || 0 }})</h4>
          <div class="items-list">
            <div 
              v-for="item in (sale.items || [])" 
              :key="item.id || item.product_id" 
              class="item-card"
            >
              <div class="item-top">
                <div class="item-name-box">
                  <span class="item-qty">{{ Number(item.quantity) }}x</span>
                  <strong class="item-name">{{ item.name || item.product?.name }}</strong>
                  <span v-if="item.is_takeaway" class="takeaway-tag">Para llevar</span>
                </div>
                <div class="item-price">Bs {{ Number(item.subtotal).toFixed(2) }}</div>
              </div>

              <!-- MODIFICADORES / TOPPINGS -->
              <div v-if="item.modifiers && item.modifiers.length > 0" class="item-mods">
                <span v-for="(m, idx) in item.modifiers" :key="idx" class="mod-chip">
                  + {{ m.option_name }} <small v-if="m.price > 0">(+Bs {{ m.price }})</small>
                </span>
              </div>

              <div v-else-if="item.sale_item_options && item.sale_item_options.length > 0" class="item-mods">
                <span v-for="(o, idx) in item.sale_item_options" :key="idx" class="mod-chip">
                  + {{ o.option?.name }}
                </span>
              </div>

              <!-- NOTA DEL ITEM -->
              <div v-if="item.item_note" class="item-note">
                📝 <em>{{ item.item_note }}</em>
              </div>

              <!-- ALÉRGENOS -->
              <div v-if="item.allergen_flags && item.allergen_flags.length > 0" class="item-allergens">
                <span v-for="a in item.allergen_flags" :key="a" class="allergen-pill">
                  ⚠️ {{ a }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- METODOS DE PAGO -->
        <div class="info-card">
          <h4>💳 Formas de Pago</h4>
          <div v-if="sale.payments && sale.payments.length > 0" class="payments-list">
            <div v-for="p in sale.payments" :key="p.id" class="payment-row">
              <span>{{ getPaymentLabel(p.method) }}</span>
              <strong>Bs {{ Number(p.amount).toFixed(2) }}</strong>
            </div>
          </div>
          <div v-else class="payment-row">
            <span>💵 Efectivo</span>
            <strong>Bs {{ Number(sale.total_amount).toFixed(2) }}</strong>
          </div>
        </div>

        <!-- TOTALES -->
        <div class="totals-card">
          <div class="row">
            <span>Subtotal:</span>
            <span>Bs {{ Number(sale.subtotal || sale.total_amount).toFixed(2) }}</span>
          </div>
          <div class="row" v-if="sale.discount_amount > 0">
            <span>Descuento aplicado:</span>
            <span class="text-danger">− Bs {{ Number(sale.discount_amount).toFixed(2) }}</span>
          </div>
          <div class="row total-main">
            <span>TOTAL:</span>
            <strong class="total-number">Bs {{ Number(sale.total_amount).toFixed(2) }}</strong>
          </div>
        </div>
      </div>

      <!-- DRAWER FOOTER -->
      <div class="drawer-footer">
        <button 
          v-if="sale.status === 'completed'" 
          class="btn btn-danger-outline" 
          @click="showVoidModal = true"
        >
          🗑️ Anular Venta
        </button>
        <button class="btn btn-ghost" @click="emit('close')">Cerrar</button>
      </div>
    </div>

    <!-- MODAL DE CONFIRMACIÓN DE ANULACIÓN -->
    <div v-if="showVoidModal" class="void-modal-overlay" @click.self="showVoidModal = false">
      <div class="void-modal">
        <h3>Anular Venta #{{ sale.id ? sale.id.substring(0, 8).toUpperCase() : '' }}</h3>
        <p class="void-warning">
          Esta acción revertirá los ingredientes al stock y restará los ingresos de la caja. 
          Esta operación no se puede deshacer.
        </p>

        <div v-if="voidError" class="void-error">
          ⚠️ {{ voidError }}
        </div>

        <div class="field">
          <label><strong>* Motivo de la anulación (Requerido):</strong></label>
          <textarea 
            v-model="voidReason" 
            placeholder="Ej: Error de cobro del cajero / Producto no elaborado..."
            rows="3"
            class="void-input"
          ></textarea>
        </div>

        <div class="void-actions">
          <button class="btn btn-ghost" @click="showVoidModal = false" :disabled="isVoiding">Cancelar</button>
          <button 
            class="btn btn-danger" 
            :disabled="!voidReason.trim() || isVoiding"
            @click="handleConfirmVoid"
          >
            <span v-if="isVoiding">Anulando...</span>
            <span v-else>Confirmar Anulación</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.drawer-overlay {
  position: fixed;
  inset: 0;
  background: rgba(26, 13, 33, 0.5);
  backdrop-filter: blur(2px);
  display: flex;
  justify-content: flex-end;
  z-index: 1100;
}

.drawer-panel {
  background: var(--surface);
  width: 100%;
  max-width: 480px;
  height: 100%;
  display: flex;
  flex-direction: column;
  box-shadow: var(--shadow-pop);
  animation: slideIn 0.2s ease-out;
}

@keyframes slideIn {
  from { transform: translateX(100%); }
  to { transform: translateX(0); }
}

.drawer-header {
  padding: 20px 24px;
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.sale-status-badge {
  display: inline-block;
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  padding: 3px 8px;
  border-radius: 999px;
  margin-bottom: 6px;
}

.sale-status-badge.completed {
  background: var(--lime-100);
  color: var(--lime-800);
}

.sale-status-badge.voided {
  background: var(--danger-100);
  color: var(--danger-700);
}

.drawer-header h3 {
  margin: 0;
  font-size: 20px;
  color: var(--ink-900);
}

.drawer-subtitle {
  margin: 4px 0 0;
  font-size: 12px;
  color: var(--ink-500);
}

.btn-close {
  background: none;
  border: none;
  font-size: 20px;
  color: var(--ink-400);
  cursor: pointer;
  padding: 4px;
}

.drawer-body {
  padding: 20px 24px;
  overflow-y: auto;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.voided-alert {
  background: var(--danger-100);
  border: 1.5px solid var(--danger-300);
  border-radius: 12px;
  padding: 12px 16px;
  color: var(--danger-800);
}

.void-title {
  font-weight: 800;
  font-size: 13.5px;
  margin-bottom: 4px;
}

.void-info {
  font-size: 12px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.info-card {
  background: var(--surface-alt);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 14px 16px;
}

.info-card h4 {
  margin: 0 0 10px;
  font-size: 13.5px;
  color: var(--ink-800);
}

.row {
  display: flex;
  justify-content: space-between;
  font-size: 13px;
  color: var(--ink-700);
  margin-bottom: 4px;
}

.items-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.item-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 10px 12px;
}

.item-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.item-name-box {
  display: flex;
  align-items: center;
  gap: 8px;
}

.item-qty {
  font-weight: 800;
  color: var(--passion-600);
  font-size: 13px;
}

.item-name {
  font-size: 13.5px;
  color: var(--ink-900);
}

.takeaway-tag {
  background: var(--gold-100);
  color: #8A6A00;
  font-size: 10px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 6px;
}

.item-price {
  font-family: 'Baloo 2', sans-serif;
  font-weight: 700;
  font-size: 14px;
  color: var(--ink-900);
}

.item-mods {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 6px;
}

.mod-chip {
  background: var(--cream-200);
  color: var(--ink-700);
  font-size: 11px;
  padding: 2px 6px;
  border-radius: 4px;
}

.item-note {
  font-size: 11.5px;
  color: var(--ink-600);
  margin-top: 4px;
}

.item-allergens {
  display: flex;
  gap: 4px;
  margin-top: 4px;
}

.allergen-pill {
  background: var(--warning-100);
  color: var(--warning-800);
  font-size: 10px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 4px;
}

.payments-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.payment-row {
  display: flex;
  justify-content: space-between;
  font-size: 13px;
  color: var(--ink-800);
}

.totals-card {
  background: var(--acai-900);
  color: white;
  border-radius: 14px;
  padding: 16px 18px;
}

.totals-card .row {
  color: rgba(255,255,255,0.85);
}

.total-main {
  border-top: 1px dashed rgba(255,255,255,0.2);
  padding-top: 8px;
  margin-top: 8px;
  font-size: 16px;
}

.total-number {
  font-family: 'Baloo 2', sans-serif;
  font-size: 22px;
  color: var(--gold-400);
}

.drawer-footer {
  padding: 16px 24px;
  border-top: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: var(--surface-alt);
}

.btn-danger-outline {
  background: transparent;
  color: var(--danger-600);
  border: 1.5px solid var(--danger-300);
  padding: 10px 16px;
  border-radius: 10px;
  font-weight: 700;
  font-size: 13px;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-danger-outline:hover {
  background: var(--danger-100);
  border-color: var(--danger-500);
}

.btn-danger {
  background: var(--danger-600);
  color: white;
  border: none;
  padding: 10px 16px;
  border-radius: 10px;
  font-weight: 700;
  cursor: pointer;
}

/* Modal de Anulación */
.void-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1250;
  padding: 16px;
}

.void-modal {
  background: var(--surface);
  border-radius: 16px;
  width: 100%;
  max-width: 440px;
  padding: 22px 24px;
  box-shadow: var(--shadow-pop);
}

.void-modal h3 {
  margin: 0 0 8px;
  font-size: 18px;
  color: var(--ink-900);
}

.void-warning {
  margin: 0 0 14px;
  font-size: 12.5px;
  color: var(--ink-600);
  line-height: 1.4;
}

.void-error {
  background: var(--danger-100);
  color: var(--danger-700);
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 12px;
  margin-bottom: 12px;
}

.field label {
  font-size: 12px;
  display: block;
  margin-bottom: 6px;
  color: var(--ink-900);
}

.void-input {
  width: 100%;
  padding: 10px 12px;
  border-radius: 8px;
  border: 1.5px solid var(--border);
  font-family: inherit;
  font-size: 13px;
  background: var(--surface-alt);
}

.void-input:focus {
  outline: none;
  border-color: var(--danger-500);
}

.void-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 16px;
}
</style>
