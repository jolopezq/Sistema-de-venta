<script setup>
import { ref, computed, onMounted } from 'vue';
import { useCatalogStore } from '../stores/catalog';
import { useCartStore } from '../stores/cart';
import { useAuthStore } from '../stores/auth';
import { useRouter } from 'vue-router';
import NetworkIndicator from '../components/NetworkIndicator.vue';
import CategoryFilter from '../components/CategoryFilter.vue';
import ProductCard from '../components/ProductCard.vue';
import CartItem from '../components/CartItem.vue';
import CheckoutModal from '../components/CheckoutModal.vue';
import ReceiptModal from '../components/ReceiptModal.vue';

const catalog = useCatalogStore();
const cart = useCartStore();
const auth = useAuthStore();
const router = useRouter();

const activeCategoryId = ref(null);
const globalSize = ref('Grande');

const isBowlCategory = computed(() => {
  if (!activeCategoryId.value) return true; // show size legend on "All"
  const cat = catalog.categories.find(c => c.id === activeCategoryId.value);
  return cat && cat.name.toLowerCase().includes('bowl');
});

onMounted(async () => {
  // Offline-First: carga el catálogo local primero, luego sincroniza
  if (catalog.categories.length === 0) {
    await catalog.loadFromLocal();
    if (catalog.categories.length === 0) {
      await catalog.fetchAndCache();
    }
  }
});

const filteredProducts = computed(() => {
  if (activeCategoryId.value === null) return catalog.products;
  return catalog.products.filter(p => p.category_id === activeCategoryId.value);
});

function handleAddToCart(product) {
  cart.addItem(product, 1);
}

function handleIncrease(item) {
  cart.addItem({ id: item.product_id, name: item.name, price: item.unit_price, size: item.size }, 1);
}

function handleDecrease(item) {
  const storeItem = cart.items.find(i => i.product_id === item.product_id);
  if (storeItem) {
    if (storeItem.quantity > 1) {
      storeItem.quantity -= 1;
      storeItem.subtotal = storeItem.quantity * storeItem.unit_price;
    } else {
      cart.removeItem(item.product_id);
    }
  }
}

const showCheckout = ref(false);
const showReceipt = ref(false);
const completedSale = ref(null);

function handleCheckout() { showCheckout.value = true; }

async function handleConfirmCheckout(checkoutData) {
  const sale = await cart.checkout(checkoutData);
  if (sale) {
    sale.change = checkoutData.change;
    completedSale.value = sale;
    showCheckout.value = false;
    showReceipt.value = true;
  }
}

function handleCloseReceipt() {
  showReceipt.value = false;
  completedSale.value = null;
}

async function handleLogout() {
  await auth.logout();
  router.push('/login');
}
</script>

<template>
  <div class="pos-layout">
    <!-- Header -->
    <div class="pos-header">
      <div class="pos-brand">
        <div class="logo-chip"></div>
        <span>Ohana Açaí POS</span>
      </div>
      <div class="pos-header-right">
        <button
          class="btn-sm"
          style="border:1px solid rgba(255,255,255,0.2);color:white;background:transparent;font-family:Inter;font-weight:600;border-radius:10px;padding:8px 14px;cursor:pointer;"
          @click="router.push('/delivery')"
        >Delivery</button>
        <button
          class="btn-sm"
          style="border:1px solid rgba(255,255,255,0.2);color:white;background:transparent;font-family:Inter;font-weight:600;border-radius:10px;padding:8px 14px;cursor:pointer;"
          @click="router.push('/admin')"
        >Catálogo</button>
        <button
          class="btn-sm"
          style="border:1px solid rgba(255,255,255,0.2);color:white;background:transparent;font-family:Inter;font-weight:600;border-radius:10px;padding:8px 14px;cursor:pointer;"
          @click="router.push('/turno')"
        >Turno</button>
        <div class="sync-pill">
          <NetworkIndicator />
        </div>
        <div class="cashier-chip">
          <div class="avatar">{{ auth.user?.name?.charAt(0).toUpperCase() || 'C' }}</div>
          <span>{{ auth.user?.name || 'Cajero' }}</span>
        </div>
        <button
          class="btn-sm"
          style="border:1px solid rgba(255,255,255,0.2);color:white;background:transparent;font-family:Inter;font-weight:600;border-radius:10px;padding:8px 14px;cursor:pointer;"
          @click="handleLogout"
        >Salir</button>
      </div>
    </div>

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

        <div class="size-legend" v-if="isBowlCategory">
          <span>Tamaño:</span>
          <span class="size-chip" :class="{ active: globalSize === 'Junior' }" @click="globalSize = 'Junior'">Junior · Bs 18</span>
          <span class="size-chip" :class="{ active: globalSize === 'Mediano' }" @click="globalSize = 'Mediano'">Mediano · Bs 25</span>
          <span class="size-chip" :class="{ active: globalSize === 'Grande' }" @click="globalSize = 'Grande'">Grande · Bs 35</span>
          <span class="size-chip" :class="{ active: globalSize === 'Ohana' }" @click="globalSize = 'Ohana'">Ohana · Bs 50</span>
        </div>

        <div class="product-grid">
          <ProductCard
            v-for="product in filteredProducts"
            :key="product.id"
            :product="product"
            @add="handleAddToCart"
          />
          <div v-if="filteredProducts.length === 0" style="grid-column:1/-1;text-align:center;padding:3rem;color:var(--ink-500);font-weight:600;">
            No hay productos disponibles.
          </div>
        </div>
      </div>

      <!-- Ticket -->
      <div class="pos-ticket">
        <div class="ticket-header">
          <h3>Ticket actual</h3>
        </div>

        <div class="ticket-items">
          <CartItem
            v-for="item in cart.items"
            :key="item.product_id"
            :item="item"
            @increase="handleIncrease"
            @decrease="handleDecrease"
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
      @close="showCheckout = false"
      @confirm="handleConfirmCheckout"
    />
    <ReceiptModal
      :show="showReceipt"
      :sale="completedSale"
      @close="handleCloseReceipt"
    />
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
  height: 100vh;
  width: 100vw;
  overflow: hidden;
  background: var(--cream-100);
}
</style>
