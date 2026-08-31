<script setup>
import { ref, computed, onMounted } from 'vue';
import { useCatalogStore } from '../stores/catalog';
import { useCartStore } from '../stores/cart';
import CategoryFilter from '../components/CategoryFilter.vue';
import ProductCard from '../components/ProductCard.vue';
import CartItem from '../components/CartItem.vue';
import CheckoutModal from '../components/CheckoutModal.vue';
import ReceiptModal from '../components/ReceiptModal.vue';
import ModifierModal from '../components/ModifierModal.vue';

const catalog = useCatalogStore();
const cart = useCartStore();

const activeCategoryId = ref(null);

onMounted(async () => {
  // Offline-First: carga el catálogo local primero para mostrar la interfaz rápido
  if (catalog.categories.length === 0) {
    await catalog.loadFromLocal();
  }
  // Sincroniza en segundo plano siempre para asegurar que tenemos
  // los productos/categorías más recientes creados en el Menú.
  catalog.fetchAndCache();
});

const filteredProducts = computed(() => {
  if (activeCategoryId.value === null) return catalog.products;
  return catalog.products.filter(p => p.category_id === activeCategoryId.value);
});

const showModifierModal = ref(false);
const selectedProduct = ref(null);
/** Almacena el ítem del carrito que se está editando actualmente (null = modo creación) */
const editingCartItem = ref(null);

function handleAddToCart(product) {
  if (product.option_groups && product.option_groups.length > 0) {
    selectedProduct.value = product;
    editingCartItem.value = null; // Modo creación
    showModifierModal.value = true;
  } else {
    // Normal add
    cart.addItem({
      id: product.id,
      name: product.name,
      price: product.price,
      modifiers: []
    }, 1);
  }
}

/**
 * Abre el ModifierModal en modo edición con la configuración actual del ítem.
 * Busca el producto en el catálogo para obtener los option_groups completos.
 */
function handleEditCartItem(cartItem) {
  const product = catalog.products.find(p => p.id === cartItem.product_id);
  if (!product) return; // No debería ocurrir, pero guardamos seguridad
  selectedProduct.value = product;
  editingCartItem.value = {
    cartKey:      cartItem.cart_key,
    modifiers:    cartItem.modifiers || [],
    itemNote:     cartItem.item_note || '',
    allergenFlags: cartItem.allergen_flags || [],
    isTakeaway:   cartItem.is_takeaway || false
  };
  showModifierModal.value = true;
}

// Mapa indexado en memoria O(1) para evitar búsquedas lentas en bucle sobre CPUs modestas (AMD A10)
const ingredientStockMap = computed(() => {
  const map = new Map();
  for (let i = 0; i < catalog.ingredients.length; i++) {
    const ing = catalog.ingredients[i];
    map.set(ing.id, ing.current_stock);
  }
  return map;
});

function isProductInStock(product) {
  if (!product.is_active) return false;
  if (!product.recipes || product.recipes.length === 0) return true;

  const stockMap = ingredientStockMap.value;
  for (let i = 0; i < product.recipes.length; i++) {
    const recipe = product.recipes[i];
    if (recipe.quantity_required <= 0) continue;
    
    const currentStock = stockMap.get(recipe.ingredient_id);
    if (currentStock !== undefined && currentStock < recipe.quantity_required) {
      return false; // Out of stock
    }
  }
  return true;
}

function handleConfirmModifiers({ product, modifiers, finalPrice, itemNote, allergenFlags, isTakeaway, editingCartKey }) {
  showModifierModal.value = false;

  const updatedProduct = {
    id:          product.id,
    name:        product.name,
    price:       finalPrice,
    modifiers:   modifiers,
    base_price:  product.price,
    is_takeaway: isTakeaway || false
  };

  if (editingCartKey) {
    // MODO EDICIÓN: reemplaza el ítem en su posición preservando cantidad
    cart.updateItem(editingCartKey, updatedProduct, itemNote, allergenFlags);
  } else {
    // MODO CREACIÓN: agrega un nuevo ítem al carrito
    cart.addItem(updatedProduct, 1, itemNote, allergenFlags);
  }

  selectedProduct.value = null;
  editingCartItem.value = null;
}

function handleIncrease(item) {
  // Pass the exact same configuration back to addItem to increment the existing cartKey
  cart.addItem({ 
    id: item.product_id, 
    name: item.name, 
    price: item.unit_price, 
    modifiers: item.modifiers,
    base_price: item.base_price,
    is_takeaway: item.is_takeaway
  }, 1, item.item_note, item.allergen_flags);
}

function handleToggleTakeaway(item) {
  cart.toggleItemTakeaway(item.cart_key);
}

function handleDecrease(item) {
  const storeItem = cart.items.find(i => i.cart_key === item.cart_key);
  if (storeItem) {
    if (storeItem.quantity > 1) {
      storeItem.quantity -= 1;
      storeItem.subtotal = storeItem.quantity * storeItem.unit_price;
    } else {
      cart.removeItem(item.cart_key);
    }
  }
}

const showCheckout = ref(false);
const showReceipt = ref(false);
const completedSale = ref(null);
const posErrorMessage = ref(null);

function handleCheckout() { showCheckout.value = true; }

async function handleConfirmCheckout(checkoutData) {
  posErrorMessage.value = null;
  try {
    const sale = await cart.checkout(checkoutData);
    if (sale) {
      sale.change = checkoutData.change;
      completedSale.value = sale;
      
      // 1. Limpiar carrito de inmediato para la próxima venta
      cart.clearCart();
      
      // 2. Cerrar modal de cobro y pasar directamente al éxito
      showCheckout.value = false;
      showReceipt.value = true;

      // 3. Disparar sincronización inmediata en segundo plano con el servidor
      try {
        const { useNetworkStore } = await import('../stores/network.js');
        useNetworkStore().triggerSync();
      } catch (err) {
        console.warn('No se pudo disparar sync:', err);
      }

      // 4. Refrescar cola de pedidos (Delivery) inmediatamente
      try {
        const { useOrderQueueStore } = await import('../stores/orderQueue.js');
        useOrderQueueStore().fetchOrders();
      } catch (qErr) {
        // no-op
      }
    } else {
      posErrorMessage.value = "Error: cart.checkout devolvió null";
    }
  } catch (err) {
    console.error(err);
    posErrorMessage.value = err.message + "\n" + err.stack;
  }
}

function handleCloseReceipt() {
  showReceipt.value = false;
  completedSale.value = null;
  // Asegurar que el carrito esté limpio al iniciar una nueva venta
  if (cart.items.length > 0) {
    cart.clearCart();
  }
}

const showClearConfirmModal = ref(false);

function handleClearCart() {
  if (cart.items.length === 0) return;
  showClearConfirmModal.value = true;
}

function confirmClearCart() {
  cart.clearCart();
  showClearConfirmModal.value = false;
}

</script>

<template>
  <div class="pos-layout">
    <!-- Body -->
    <div class="pos-body">
      <!-- Catalog -->
      <div class="pos-catalog">
        <div v-if="catalog.errorMessage" style="background:var(--danger-100);color:var(--danger-600);padding:12px 16px;border-radius:10px;margin-bottom:14px;font-weight:600;">
          ⚠️ {{ catalog.errorMessage }}
        </div>

        <div class="search-row">
          <input class="search-input" placeholder="🔍 Buscar producto por nombre..." />
        </div>

        <CategoryFilter
          :categories="catalog.categories"
          :activeCategoryId="activeCategoryId"
          @select="activeCategoryId = $event"
        />

        <div class="product-grid">
          <ProductCard 
            v-for="p in filteredProducts" 
            :key="p.id" 
            :product="p"
            :disabled="!isProductInStock(p)"
            @add="handleAddToCart(p)" 
          />
          <div v-if="filteredProducts.length === 0" style="grid-column:1/-1;text-align:center;padding:3rem;color:var(--ink-500);font-weight:600;">
            No hay productos disponibles.
          </div>
        </div>
      </div>

      <!-- Ticket -->
      <div class="pos-ticket">
        <div v-if="posErrorMessage" style="background:#fee2e2;color:#991b1b;padding:12px;margin:10px;border-radius:6px;font-family:monospace;white-space:pre-wrap;font-size:12px;user-select:text;border:1px solid #ef4444;">
          <strong>Error de Sistema:</strong><br/>
          {{ posErrorMessage }}
        </div>

        <div class="ticket-header">
          <div class="ticket-header-title">
            <h3>Ticket actual</h3>
            <span v-if="cart.items.length > 0" class="ticket-count-badge">{{ cart.items.length }}</span>
          </div>
          <button
            v-if="cart.items.length > 0"
            type="button"
            class="btn-clear-ticket"
            title="Vaciar ticket completo"
            @click="handleClearCart"
          >
            <span class="clear-icon">✕</span>
            <span class="clear-label">Limpiar</span>
          </button>
        </div>

        <div class="ticket-items">
          <CartItem
            v-for="item in cart.items"
            :key="item.cart_key"
            :item="item"
            @increase="handleIncrease"
            @decrease="handleDecrease"
            @edit="handleEditCartItem"
            @toggle-takeaway="handleToggleTakeaway"
          />
          <div v-if="cart.items.length === 0" style="text-align:center;padding:3rem 1rem;color:var(--ink-500);font-weight:600;font-size:14px;">
            El carrito está vacío
          </div>
        </div>

        <div class="ticket-summary">
          <div class="sum-row">
            <span>Subtotal</span>
            <span>Bs {{ Number(cart.subtotal).toFixed(2) }}</span>
          </div>
          <div class="sum-row">
            <span>Descuento VIP</span>
            <span>− Bs 0.00</span>
          </div>
          <div class="sum-row total">
            <span>Total</span>
            <span class="amt">Bs {{ Number(cart.total).toFixed(2) }}</span>
          </div>
        </div>

        <div class="ticket-footer">
          <button
            class="btn btn-success"
            :disabled="cart.items.length === 0"
            @click="handleCheckout"
          >
            Cobrar venta
          </button>
        </div>
      </div>
    </div>

    <!-- Modales -->
    <CheckoutModal
      :show="showCheckout"
      :total="cart.total"
      :destinationDefault="cart.orderDestinationSummary"
      :takeawayCount="cart.takeawayItemsCount"
      :dineInCount="cart.dineInItemsCount"
      :itemsCount="cart.items.length"
      @close="showCheckout = false"
      @confirm="handleConfirmCheckout"
    />
    <ReceiptModal
      :show="showReceipt"
      :sale="completedSale"
      @close="handleCloseReceipt"
    />
    <ModifierModal
      :show="showModifierModal"
      :product="selectedProduct"
      :initialData="editingCartItem"
      @close="showModifierModal = false; editingCartItem = null"
      @confirm="handleConfirmModifiers"
    />

    <!-- Modal de Confirmación Estilizado para Vaciar Ticket -->
    <div
      v-if="showClearConfirmModal"
      class="clear-modal-backdrop"
      @click.self="showClearConfirmModal = false"
    >
      <div class="clear-modal-card">
        <div class="clear-modal-icon">
          🗑️
        </div>
        <h3 class="clear-modal-title">¿Vaciar el ticket actual?</h3>
        <p class="clear-modal-desc">
          Se eliminarán los <strong>{{ cart.items.length }} {{ cart.items.length === 1 ? 'producto' : 'productos' }}</strong> agregados al pedido por un total de <strong>Bs {{ Number(cart.total).toFixed(2) }}</strong>.
        </p>
        <div class="clear-modal-actions">
          <button
            type="button"
            class="btn-modal-cancel"
            @click="showClearConfirmModal = false"
          >
            No, mantener
          </button>
          <button
            type="button"
            class="btn-modal-delete"
            @click="confirmClearCart"
          >
            🗑️ Sí, vaciar ticket
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* 
  All classes (.pos-header, .pos-brand, .pos-body, .pos-catalog, .pos-ticket,
  .ticket-header, .ticket-items, .ticket-summary, .ticket-footer, .sum-row,
  .product-grid, .search-row, .search-input, .size-legend, .size-chip, 
  .sync-pill, .cashier-chip, .logo-chip, .avatar)
  are defined in the global style.css extracted exactly from the prototype.
*/
.pos-layout {
  display: flex;
  flex-direction: column;
  height: 100%;
  flex: 1;
  overflow: hidden;
  background: var(--cream-100);
}

.ticket-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  border-bottom: 1px solid var(--border);
}
.ticket-header-title {
  display: flex;
  align-items: center;
  gap: 8px;
}
.ticket-header-title h3 {
  margin: 0;
  font-size: 17px;
  font-weight: 800;
  color: var(--ink-900);
}
.ticket-count-badge {
  background: #fee2e2;
  color: #dc2626;
  font-size: 11px;
  font-weight: 800;
  padding: 2px 7px;
  border-radius: 99px;
}
.btn-clear-ticket {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #fef2f2;
  color: #dc2626;
  border: 1px solid #fecaca;
  padding: 5px 10px;
  border-radius: 8px;
  font-family: inherit;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s ease;
  line-height: 1;
}
.btn-clear-ticket:hover {
  background: #fee2e2;
  border-color: #f87171;
  color: #b91c1c;
  transform: translateY(-1px);
  box-shadow: 0 2px 6px rgba(220, 38, 38, 0.15);
}
.btn-clear-ticket:active {
  transform: translateY(0);
}
.clear-icon {
  font-size: 12px;
  font-weight: 900;
}
.clear-label {
  font-size: 12px;
}

/* ── MODAL DE CONFIRMACIÓN CUSTOM (VACIAR TICKET) ── */
.clear-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1100;
  padding: 16px;
  animation: modal-fade-in 0.2s ease-out;
}

.clear-modal-card {
  background: var(--surface, #ffffff);
  border: 1px solid var(--border, #e2e8f0);
  border-radius: 20px;
  max-width: 400px;
  width: 100%;
  padding: 28px 24px 22px;
  text-align: center;
  box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.1);
  animation: card-pop-in 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

.clear-modal-icon {
  width: 58px;
  height: 58px;
  background: #fee2e2;
  border: 1px solid #fecaca;
  color: #dc2626;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  margin: 0 auto 16px;
  box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15);
}

.clear-modal-title {
  margin: 0 0 8px;
  font-size: 20px;
  font-weight: 800;
  color: var(--ink-900, #0f172a);
  font-family: 'Baloo 2', sans-serif;
  letter-spacing: -0.01em;
}

.clear-modal-desc {
  font-size: 13.5px;
  color: var(--ink-600, #475569);
  line-height: 1.5;
  margin: 0 0 24px;
}

.clear-modal-desc strong {
  color: var(--ink-900, #0f172a);
  font-weight: 700;
}

.clear-modal-actions {
  display: flex;
  gap: 10px;
}

.btn-modal-cancel {
  flex: 1;
  background: var(--surface-hover, #f1f5f9);
  color: var(--ink-700, #334155);
  border: 1px solid var(--border, #cbd5e1);
  padding: 11px 16px;
  border-radius: 12px;
  font-family: inherit;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s ease;
}

.btn-modal-cancel:hover {
  background: #e2e8f0;
  color: var(--ink-900, #0f172a);
  transform: translateY(-1px);
}

.btn-modal-delete {
  flex: 1.2;
  background: linear-gradient(135deg, #ef4444, #dc2626);
  color: #ffffff;
  border: none;
  padding: 11px 18px;
  border-radius: 12px;
  font-family: inherit;
  font-size: 14px;
  font-weight: 800;
  cursor: pointer;
  transition: all 0.15s ease;
  box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}

.btn-modal-delete:hover {
  filter: brightness(1.08);
  transform: translateY(-1px);
  box-shadow: 0 6px 18px rgba(220, 38, 38, 0.45);
}

.btn-modal-delete:active,
.btn-modal-cancel:active {
  transform: translateY(0);
}

@keyframes modal-fade-in {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes card-pop-in {
  from { opacity: 0; transform: scale(0.92) translateY(8px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}
</style>
