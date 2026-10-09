<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import { useCatalogStore } from '../stores/catalog';
import { useCartStore } from '../stores/cart';
import { useTurnoStore } from '../stores/turno';
import { useNetworkStore } from '../stores/network';
import CategoryFilter from '../components/CategoryFilter.vue';
import ProductCard from '../components/ProductCard.vue';
import CartItem from '../components/CartItem.vue';
import CheckoutModal from '../components/CheckoutModal.vue';
import ReceiptModal from '../components/ReceiptModal.vue';
import ModifierModal from '../components/ModifierModal.vue';
import PriceOverrideModal from '../components/PriceOverrideModal.vue';
import { productPrice, missingDeliveryPrice, isAvailableOnPedidosYa, CHANNEL_PEDIDOSYA } from '../utils/channelPricing';
import { productStockRows } from '../utils/stockRecipe';

const catalog = useCatalogStore();
const cart = useCartStore();
const turno = useTurnoStore();
const network = useNetworkStore();
const router = useRouter();

const activeCategoryId = ref(null);

// Turno obligatorio para cobrar: una venta sin caja abierta no entra a ningún arqueo y descuadra el cierre.
// Se consulta al entrar, cada 5 minutos y al volver la conexión (sin conexión vale la copia de hoy).
let sessionTimer = null;
const refreshSalesSession = () => turno.fetchActiveSession();

onMounted(async () => {
  refreshSalesSession();
  sessionTimer = setInterval(() => { if (network.isOnline) refreshSalesSession(); }, 5 * 60 * 1000);
  window.addEventListener('online', refreshSalesSession);

  // Offline-First: carga el catálogo local primero para mostrar la interfaz rápido
  if (catalog.categories.length === 0) {
    await catalog.loadFromLocal();
  }
  // Sincroniza en segundo plano siempre para asegurar que tenemos
  // los productos/categorías más recientes creados en el Menú.
  catalog.fetchAndCache();
});

onUnmounted(() => {
  clearInterval(sessionTimer);
  window.removeEventListener('online', refreshSalesSession);
});

const sessionUnknownOffline = computed(() => turno.salesSessionSource === 'unknown' && !network.isOnline);

function goOpenTurno() {
  router.push({ path: '/turno', query: { abrir: '1' } });
}

// Búsqueda por nombre o descripción, sin distinguir tildes ni mayúsculas («acai» encuentra «Açaí»)
const search = ref('');
const normalize = (text) => String(text || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
const searchTerm = computed(() => normalize(search.value));

function clearSearch() {
  search.value = '';
}

function selectCategory(id) {
  activeCategoryId.value = id;
  // Al elegir una categoría se deja de buscar: lo que se ve es esa categoría completa
  search.value = '';
}

const filteredProducts = computed(() => {
  // En modo PedidosYa solo se ofrecen los productos marcados como disponibles en la plataforma
  const channelProducts = cart.channel === CHANNEL_PEDIDOSYA
    ? catalog.products.filter(isAvailableOnPedidosYa)
    : catalog.products;
  // Al buscar se busca en todo el catálogo, no solo en la categoría elegida
  if (searchTerm.value) {
    return channelProducts.filter((p) => normalize(`${p.name} ${p.description || ''}`).includes(searchTerm.value));
  }
  if (activeCategoryId.value === null) return channelProducts;
  return channelProducts.filter(p => p.category_id === activeCategoryId.value);
});

/** Unidades en el ticket (dos capuchinos cuentan 2; un producto por peso cuenta 1). */
const ticketUnits = computed(() => cart.items.reduce((n, i) => n + (i.is_weight_based ? 1 : Number(i.quantity) || 0), 0));
const formatMoney = (n) => `Bs ${Number(n || 0).toFixed(2)}`;

const showModifierModal = ref(false);
const selectedProduct = ref(null);
/** Almacena el ítem del carrito que se está editando actualmente (null = modo creación) */
const editingCartItem = ref(null);

function handleAddToCart(product) {
  if (product.is_weight_based || (product.option_groups && product.option_groups.length > 0)) {
    selectedProduct.value = product;
    editingCartItem.value = null; // Modo creación
    showModifierModal.value = true;
  } else {
    // Normal add
    cart.addItem({
      id: product.id,
      name: product.name,
      price: productPrice(product, cart.channel),
      modifiers: [],
      is_weight_based: false
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
    isTakeaway:   cartItem.is_takeaway || false,
    weightGrams:  cartItem.weight_grams || (cartItem.is_weight_based ? cartItem.quantity : null)
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

/** Un producto inactivo (pausado desde el Menú) no se vende; es la forma de marcarlo «agotado» a mano. */
function isProductAvailable(product) {
  return Boolean(product.is_active);
}

/**
 * Aviso de stock bajo: el stock del sistema es teórico (puede faltar cargar una compra), así que solo avisa
 * y deja vender. Un producto sin receta, o con insumos que no llevan stock, nunca avisa.
 */
function hasLowStock(product) {
  const stockMap = ingredientStockMap.value;
  // Receta de stock ya expandida por el servidor: los componentes con composición llegan como sus insumos reales
  for (const row of productStockRows(product)) {
    if (row.quantity <= 0) continue;

    const currentStock = stockMap.get(row.ingredient_id);
    if (currentStock !== undefined && currentStock < row.quantity) {
      return true;
    }
  }
  return false;
}

function handleConfirmModifiers({ product, modifiers, finalPrice, itemNote, allergenFlags, isTakeaway, editingCartKey, weightGrams }) {
  showModifierModal.value = false;

  const isWeight = Boolean(product.is_weight_based);
  const updatedProduct = {
    id:              product.id,
    name:            product.name,
    price:           finalPrice,
    modifiers:       modifiers,
    base_price:      productPrice(product, cart.channel),
    is_takeaway:     isTakeaway || false,
    is_weight_based: isWeight,
    weight_grams:    weightGrams,
    price_per_gram:  product.price_per_gram
  };

  const quantity = isWeight ? 1 : 1;

  if (editingCartKey) {
    // MODO EDICIÓN: reemplaza el ítem en su posición preservando cantidad
    cart.updateItem(editingCartKey, updatedProduct, itemNote, allergenFlags);
  } else {
    // MODO CREACIÓN: agrega un nuevo ítem al carrito
    cart.addItem(updatedProduct, quantity, itemNote, allergenFlags);
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

function handleCheckout() {
  if (turno.sellBlocked || cart.items.length === 0) return;
  posErrorMessage.value = null;
  showCheckout.value = true;
}

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
      showCheckout.value = false;
      posErrorMessage.value = 'El equipo no devolvió la venta guardada.';
    }
  } catch (err) {
    // El detalle técnico queda en la consola; al cajero se le dice qué pasó y qué hacer.
    // Se cierra el cobro para que vea el aviso junto al ticket, que sigue intacto.
    console.error(err);
    showCheckout.value = false;
    posErrorMessage.value = err?.message || 'Error desconocido.';
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

// ── Canal de venta (Local / PedidosYa) ──
const isPedidosYa = computed(() => cart.channel === CHANNEL_PEDIDOSYA);
const pendingChannel = ref(null);

function requestChannel(channel) {
  if (channel === cart.channel) return;
  if (cart.items.length > 0) {
    // No se mezclan precios de mostrador y de PedidosYa en un mismo pedido
    pendingChannel.value = channel;
    return;
  }
  cart.setChannel(channel);
}

function confirmChannelChange() {
  cart.clearCart();
  cart.setChannel(pendingChannel.value);
  pendingChannel.value = null;
}

/** Ítems del carrito que se cobran a precio local por no tener tarifa PedidosYa */
const itemsWithoutDeliveryPrice = computed(() => {
  if (!isPedidosYa.value) return [];
  const names = new Set();
  for (const item of cart.items) {
    const product = catalog.products.find(p => p.id === item.product_id);
    if (!product) continue;
    if (product.is_weight_based || missingDeliveryPrice(product)) {
      names.add(product.name);
    }
    for (const mod of item.modifiers || []) {
      let opt = null;
      for (const og of (product.option_groups || [])) {
        const found = (og.options || []).find(o => o.id === mod.option_id);
        if (found) {
          opt = {
            ...found,
            pedidosya_pricing: found.pedidosya_pricing !== undefined ? found.pedidosya_pricing : og.pedidosya_pricing,
          };
          break;
        }
      }
      if (opt && Number(opt.additional_price) > 0 && missingDeliveryPrice(opt)) {
        names.add(`${product.name} · ${opt.name}`);
      }
    }
  }
  return [...names];
});

// ── Ajuste de precio de una línea (solo PedidosYa) ──
const priceOverrideItem = ref(null);

function openPriceOverride(item) {
  if (!isPedidosYa.value || item.is_weight_based) return;
  priceOverrideItem.value = item;
}

function applyPriceOverride({ price, reason }) {
  if (priceOverrideItem.value) {
    cart.overrideItemPrice(priceOverrideItem.value.cart_key, price, reason);
  }
  priceOverrideItem.value = null;
}

function clearPriceOverride() {
  if (priceOverrideItem.value) {
    cart.clearItemPriceOverride(priceOverrideItem.value.cart_key);
  }
  priceOverrideItem.value = null;
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
    <div class="pos-body">
      <!-- Catálogo -->
      <div class="pos-catalog">
        <div v-if="catalog.errorMessage" class="pos-alert" role="alert">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7.5v5.5" /><path d="M12 16.5v.5" /></svg>
          {{ catalog.errorMessage }}
        </div>

        <div class="search-row">
          <label class="pos-search">
            <span class="sr-only">Buscar producto</span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
            <input
              v-model="search"
              class="search-input"
              type="search"
              placeholder="Buscar producto"
              autocomplete="off"
              @keydown.esc="clearSearch"
            />
            <button v-if="search" class="pos-search-clear" type="button" aria-label="Borrar la búsqueda" @click="clearSearch">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
            </button>
          </label>
        </div>

        <CategoryFilter
          :categories="catalog.categories"
          :activeCategoryId="searchTerm ? undefined : activeCategoryId"
          @select="selectCategory"
        />

        <p v-if="searchTerm" class="pos-search-result" role="status">
          {{ filteredProducts.length === 1 ? '1 producto' : `${filteredProducts.length} productos` }} con «{{ search.trim() }}» en todo el menú
        </p>

        <div class="product-grid">
          <ProductCard
            v-for="p in filteredProducts"
            :key="p.id"
            :product="p"
            :disabled="!isProductAvailable(p)"
            :low-stock="hasLowStock(p)"
            :channel="cart.channel"
            @add="handleAddToCart(p)"
          />
          <div v-if="filteredProducts.length === 0" class="pos-empty-grid">
            <template v-if="searchTerm">
              Ningún producto se llama así.
              <button type="button" class="pos-link" @click="clearSearch">Ver todo el menú</button>
            </template>
            <template v-else>{{ isPedidosYa ? 'No hay productos disponibles en PedidosYa.' : 'No hay productos disponibles.' }}</template>
          </div>
        </div>
      </div>

      <!-- Ticket -->
      <div class="pos-ticket" :class="{ 'pos-ticket--pedidosya': isPedidosYa }">
        <!-- Selector de canal -->
        <div class="channel-switch" role="radiogroup" aria-label="Canal de venta">
          <button
            type="button"
            role="radio"
            class="channel-btn"
            :class="{ active: !isPedidosYa }"
            :aria-checked="!isPedidosYa"
            @click="requestChannel('pos')"
          >Local</button>
          <button
            type="button"
            role="radio"
            class="channel-btn channel-btn--pya"
            :class="{ active: isPedidosYa }"
            :aria-checked="isPedidosYa"
            @click="requestChannel('pedidosya')"
          >PedidosYa</button>
        </div>
        <div v-if="isPedidosYa" class="pya-banner" role="status">
          Pedido de PedidosYa: precios de la plataforma. No entra al arqueo de caja.
          <div v-if="itemsWithoutDeliveryPrice.length" class="pya-banner-warn">
            Sin precio PedidosYa, se cobra el precio local: {{ itemsWithoutDeliveryPrice.join(', ') }}
          </div>
        </div>

        <div class="ticket-header">
          <div class="ticket-header-title">
            <h3>Ticket</h3>
            <span v-if="ticketUnits > 0" class="ticket-count">{{ ticketUnits === 1 ? '1 producto' : `${ticketUnits} productos` }}</span>
          </div>
          <button
            v-if="cart.items.length > 0"
            type="button"
            class="btn-clear-ticket"
            @click="handleClearCart"
          >
            Vaciar
          </button>
        </div>

        <div class="ticket-items">
          <CartItem
            v-for="item in cart.items"
            :key="item.cart_key"
            :item="item"
            :can-adjust-price="isPedidosYa"
            @adjust-price="openPriceOverride"
            @increase="handleIncrease"
            @decrease="handleDecrease"
            @edit="handleEditCartItem"
            @toggle-takeaway="handleToggleTakeaway"
          />
          <p v-if="cart.items.length === 0" class="ticket-empty">
            Toca un producto para empezar el pedido.
          </p>
        </div>

        <div v-if="posErrorMessage" class="pos-sale-error" role="alert">
          <strong>No se pudo guardar la venta.</strong>
          El ticket sigue aquí: revísalo y vuelve a cobrar. Si se repite, avisa al administrador.
          <span class="pos-sale-error-detail">Detalle: {{ posErrorMessage }}</span>
        </div>

        <div class="ticket-summary">
          <div class="sum-row total">
            <span>Total</span>
            <span class="amt">{{ formatMoney(cart.total) }}</span>
          </div>
        </div>

        <div class="ticket-footer">
          <template v-if="turno.sellBlocked">
            <p class="turno-required" role="status">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
              <span>No hay turno abierto. Abre la caja para cobrar: así cada venta entra al arqueo.</span>
            </p>
            <button class="btn btn-primary" type="button" @click="goOpenTurno">
              Abrir caja
            </button>
          </template>
          <template v-else>
            <p v-if="sessionUnknownOffline" class="turno-required unknown" role="status">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v6" /><path d="M12 16.5v.5" /></svg>
              <span>Sin conexión no se pudo comprobar el turno. Puedes cobrar; si no está abierto, ábrelo apenas vuelva la conexión.</span>
            </p>
            <button
              class="btn btn-primary btn-charge"
              type="button"
              :disabled="cart.items.length === 0"
              @click="handleCheckout"
            >
              <template v-if="cart.items.length === 0">Cobrar</template>
              <template v-else-if="isPedidosYa">Registrar pedido de {{ formatMoney(cart.total) }}</template>
              <template v-else>Cobrar {{ formatMoney(cart.total) }}</template>
            </button>
          </template>
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
      :itemsCount="ticketUnits"
      :channel="cart.channel"
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
      :channel="cart.channel"
      @close="showModifierModal = false; editingCartItem = null"
      @confirm="handleConfirmModifiers"
    />

    <PriceOverrideModal
      :show="Boolean(priceOverrideItem)"
      :item="priceOverrideItem"
      @close="priceOverrideItem = null"
      @apply="applyPriceOverride"
      @clear="clearPriceOverride"
    />

    <!-- Confirmación para cambiar de canal con productos en el ticket -->
    <div
      v-if="pendingChannel"
      class="clear-modal-backdrop"
      @click.self="pendingChannel = null"
      @keydown.esc="pendingChannel = null"
    >
      <div class="clear-modal-card" role="alertdialog" aria-modal="true" aria-labelledby="pos-channel-title">
        <h3 id="pos-channel-title" class="clear-modal-title">¿Cambiar a {{ pendingChannel === 'pedidosya' ? 'PedidosYa' : 'venta local' }}?</h3>
        <p class="clear-modal-desc">
          Los precios cambian según el canal. Se vaciará el ticket
          (<strong>{{ ticketUnits === 1 ? '1 producto' : `${ticketUnits} productos` }}</strong>) para no mezclar precios.
        </p>
        <div class="clear-modal-actions">
          <button type="button" class="btn-modal-cancel" @click="pendingChannel = null">No, mantener</button>
          <button type="button" class="btn-modal-delete" @click="confirmChannelChange">Vaciar y cambiar</button>
        </div>
      </div>
    </div>

    <!-- Confirmación para vaciar el ticket -->
    <div
      v-if="showClearConfirmModal"
      class="clear-modal-backdrop"
      @click.self="showClearConfirmModal = false"
      @keydown.esc="showClearConfirmModal = false"
    >
      <div class="clear-modal-card" role="alertdialog" aria-modal="true" aria-labelledby="pos-clear-title">
        <h3 id="pos-clear-title" class="clear-modal-title">¿Vaciar el ticket?</h3>
        <p class="clear-modal-desc">
          Se quitan <strong>{{ ticketUnits === 1 ? '1 producto' : `${ticketUnits} productos` }}</strong> por un total de <strong>{{ formatMoney(cart.total) }}</strong>.
        </p>
        <div class="clear-modal-actions">
          <button type="button" class="btn-modal-cancel" @click="showClearConfirmModal = false">No, mantener</button>
          <button type="button" class="btn-modal-delete" @click="confirmClearCart">Vaciar ticket</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/*
  Las clases de la grilla y del ticket (.pos-body, .pos-catalog, .pos-ticket, .ticket-*, .product-grid,
  .search-row, .search-input, .sum-row) son globales en style.css; aquí va lo propio de la pantalla de venta.
*/
.pos-layout {
  display: flex;
  flex-direction: column;
  height: 100%;
  flex: 1;
  overflow: hidden;
  background: var(--cream-100);
}

.pos-alert {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  padding: 10px 14px;
  border-radius: 10px;
  background: var(--danger-100);
  color: var(--danger-text);
  font-size: 13.5px;
  font-weight: 600;
}
.pos-alert svg { width: 17px; height: 17px; flex-shrink: 0; }

/* Buscador con icono dibujado (no emoji) y botón para borrar */
.pos-search { position: relative; flex: 1; display: flex; align-items: center; }
.pos-search > svg {
  position: absolute;
  left: 13px;
  width: 17px;
  height: 17px;
  color: var(--ink-500);
  pointer-events: none;
}
.pos-search .search-input { padding-left: 38px; padding-right: 40px; width: 100%; color: var(--ink-900); }
.pos-search .search-input::-webkit-search-cancel-button { display: none; }
.pos-search-clear {
  position: absolute;
  right: 6px;
  width: 30px;
  height: 30px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--ink-500);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.pos-search-clear:hover { background: var(--cream-200); color: var(--ink-900); }
.pos-search-clear svg { width: 15px; height: 15px; }
.pos-search-result { margin: -4px 0 10px; font-size: 13px; color: var(--ink-500); }
.pos-empty-grid {
  grid-column: 1 / -1;
  padding: 3rem 1rem;
  text-align: center;
  color: var(--ink-500);
  font-weight: 600;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}
.pos-link {
  border: none;
  background: none;
  padding: 0;
  font: inherit;
  font-weight: 700;
  color: var(--acai-text);
  text-decoration: underline;
  text-underline-offset: 2px;
  cursor: pointer;
}

/* Canal de venta */
.channel-switch {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
  padding: 12px 18px 0;
}
.channel-btn {
  padding: 9px 10px;
  border-radius: 10px;
  border: 1.5px solid var(--border);
  background: var(--surface);
  color: var(--ink-700);
  font-family: var(--font-heading);
  font-size: 14.5px;
  font-weight: 700;
  cursor: pointer;
}
.channel-btn.active {
  border-color: var(--acai-700);
  background: var(--acai-700);
  color: #fff;
}
/* PedidosYa conserva el rojo de la plataforma para que no se confunda con una venta del local */
.channel-btn--pya.active {
  border-color: #C2003F;
  background: #C2003F;
  color: #fff;
}
.channel-btn:focus-visible {
  outline: 2px solid var(--acai-500);
  outline-offset: 2px;
}
.pya-banner {
  margin: 10px 18px 0;
  padding: 10px 12px;
  border-radius: 10px;
  background: #FFE4EC;
  color: #8A0030;
  font-size: 13px;
  line-height: 1.4;
}
.pya-banner-warn {
  margin-top: 6px;
  font-weight: 600;
}
html.dark .pya-banner {
  background: #3D1424;
  color: #FFB3C9;
}
.pos-ticket--pedidosya {
  box-shadow: inset 0 4px 0 #C2003F;
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
  align-items: baseline;
  gap: 10px;
}
.ticket-header-title h3 {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: var(--ink-900);
}
.ticket-count {
  font-size: 13px;
  font-weight: 500;
  color: var(--ink-500);
  font-variant-numeric: tabular-nums;
}
.btn-clear-ticket {
  background: transparent;
  color: var(--ink-700);
  border: 1px solid var(--border);
  padding: 6px 12px;
  border-radius: 8px;
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
}
.btn-clear-ticket:hover {
  background: var(--danger-100);
  border-color: transparent;
  color: var(--danger-text);
}
.btn-clear-ticket:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; }

.ticket-empty {
  margin: 0;
  padding: 3rem 1rem;
  text-align: center;
  color: var(--ink-500);
  font-size: 14px;
  line-height: 1.5;
}

.ticket-summary .sum-row.total { margin: 0; align-items: baseline; }
.ticket-summary .sum-row.total .amt { font-size: 22px; font-variant-numeric: tabular-nums; }

/* El botón de cobro lleva el monto: lo que dice es exactamente lo que se cobra */
.btn-charge { font-variant-numeric: tabular-nums; }
.ticket-footer .btn-charge:disabled {
  background: var(--cream-200);
  color: var(--ink-500);
  cursor: not-allowed;
}
.ticket-footer .btn:focus-visible { outline: 3px solid var(--acai-500); outline-offset: 2px; }

.pos-sale-error {
  margin: 0 14px 10px;
  padding: 10px 12px;
  border-radius: 10px;
  background: var(--danger-100);
  color: var(--danger-text);
  font-size: 13px;
  line-height: 1.45;
}
.pos-sale-error strong { display: block; }
.pos-sale-error-detail { display: block; margin-top: 4px; font-size: 12px; opacity: .9; word-break: break-word; }

/* Aviso de turno obligatorio sobre el botón de cobro: icono y texto, el color no va solo */
.turno-required {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin: 0 0 10px;
  padding: 10px 12px;
  border-radius: 10px;
  background: var(--danger-100);
  color: var(--danger-text);
  font-size: 13px;
  font-weight: 600;
  line-height: 1.4;
}
.turno-required svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 2px; }
.turno-required.unknown {
  background: var(--gold-100);
  color: var(--gold-text);
}

/* ── Confirmaciones (vaciar ticket, cambiar de canal) ── */
.clear-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(32, 17, 47, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1100;
  padding: 16px;
}
.clear-modal-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  max-width: 400px;
  width: 100%;
  padding: 22px 22px 18px;
  box-shadow: var(--shadow-pop);
}
.clear-modal-title {
  margin: 0 0 6px;
  font-size: 20px;
  font-weight: 700;
  color: var(--ink-900);
}
.clear-modal-desc {
  font-size: 14px;
  color: var(--ink-700);
  line-height: 1.5;
  margin: 0 0 20px;
}
.clear-modal-desc strong { color: var(--ink-900); font-weight: 700; }
.clear-modal-actions { display: flex; gap: 10px; }
.btn-modal-cancel,
.btn-modal-delete {
  flex: 1;
  min-height: 44px;
  padding: 0 16px;
  border-radius: 12px;
  font-family: inherit;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
.btn-modal-cancel {
  background: transparent;
  color: var(--ink-900);
  border: 1px solid var(--border);
}
.btn-modal-cancel:hover { background: var(--cream-200); }
.btn-modal-delete {
  background: var(--danger-600);
  color: #FFFFFF;
  border: none;
}
html.dark .btn-modal-delete { color: var(--on-accent); }
.btn-modal-delete:hover { filter: brightness(1.06); }
.btn-modal-cancel:focus-visible,
.btn-modal-delete:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; }
</style>
