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

const catalog = useCatalogStore();
const cart = useCartStore();
const auth = useAuthStore();
const router = useRouter();

const activeCategoryId = ref(null);

onMounted(async () => {
  // Inicializamos el catálogo si estaba vacío en memoria
  if (catalog.categories.length === 0) {
    await catalog.loadFromLocal();
    if (catalog.categories.length === 0) {
      // Si la BD local está vacía, forzamos descarga desde el servidor
      await catalog.fetchAndCache();
    }
  }
});

const filteredProducts = computed(() => {
  if (activeCategoryId.value === null) {
    return catalog.products;
  }
  return catalog.products.filter(p => p.category_id === activeCategoryId.value);
});

function handleAddToCart(product) {
  cart.addItem(product, 1);
}

function handleIncrease(item) {
  cart.addItem({ id: item.product_id, name: item.name, price: item.unit_price }, 1);
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

async function handleCheckout() {
  await cart.checkout('cash');
}

async function handleLogout() {
  await auth.logout();
  router.push('/login');
}
</script>

<template>
  <div class="pos-layout">
    <!-- Header principal -->
    <header class="pos-header">
      <div class="header-left">
        <h2>Ohana Açaí</h2>
        <NetworkIndicator />
      </div>
      <div class="header-right">
        <span class="user-name">Cajero: {{ auth.user?.name || 'Cajero' }}</span>
        <button class="btn btn-outline" @click="handleLogout">Salir</button>
      </div>
    </header>

    <div class="pos-content">
      <!-- Main Content (Catálogo) -->
      <main class="pos-main">
        <div v-if="catalog.errorMessage" style="background: red; color: white; padding: 1rem; text-align: center;">
          Error: {{ catalog.errorMessage }}
        </div>
        
        <CategoryFilter 
          :categories="catalog.categories" 
          :activeCategoryId="activeCategoryId"
          @select="activeCategoryId = $event"
        />

        <div class="products-grid">
          <ProductCard 
            v-for="product in filteredProducts" 
            :key="product.id"
            :product="product"
            @add="handleAddToCart"
          />
          <div v-if="filteredProducts.length === 0" class="empty-state">
            No hay productos disponibles.
          </div>
        </div>
      </main>

      <!-- Sidebar (Ticket/Carrito) -->
      <aside class="pos-sidebar glass-panel">
        <h2 class="sidebar-title">Ticket Actual</h2>
        
        <div class="cart-items">
          <CartItem 
            v-for="item in cart.items" 
            :key="item.product_id"
            :item="item"
            @increase="handleIncrease"
            @decrease="handleDecrease"
          />
          <div v-if="cart.items.length === 0" class="empty-cart">
            El carrito está vacío
          </div>
        </div>

        <div class="cart-summary">
          <div class="summary-row">
            <span>Subtotal</span>
            <span>${{ Number(cart.subtotal).toFixed(2) }}</span>
          </div>
          <div class="summary-row total">
            <span>Total</span>
            <span>${{ Number(cart.total).toFixed(2) }}</span>
          </div>
          
          <button 
            class="btn btn-primary checkout-btn" 
            :disabled="cart.items.length === 0"
            @click="handleCheckout"
          >
            Cobrar Venta
          </button>
        </div>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.pos-layout {
  display: flex;
  flex-direction: column;
  height: 100vh;
  width: 100vw;
  background-color: var(--bg-tertiary);
  overflow: hidden;
}

.pos-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 1.5rem;
  background-color: var(--bg-secondary);
  border-bottom: 1px solid var(--border-color);
  box-shadow: var(--shadow-sm);
  z-index: 10;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 1.5rem;
}

.header-left h2 {
  font-weight: 800;
  color: var(--color-primary);
  letter-spacing: -0.5px;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.user-name {
  font-weight: 600;
  color: var(--text-secondary);
}

.btn-outline {
  background: transparent;
  border: 1px solid var(--border-color);
  color: var(--text-primary);
  padding: 0.5rem 1rem;
}
.btn-outline:hover {
  background: var(--bg-tertiary);
}

.pos-content {
  display: flex;
  flex: 1;
  overflow: hidden;
}

.pos-main {
  flex: 1;
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
}

.products-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 1.5rem;
}

.empty-state {
  grid-column: 1 / -1;
  text-align: center;
  padding: 3rem;
  color: var(--text-secondary);
}

.pos-sidebar {
  width: 380px;
  background: var(--bg-secondary);
  border-left: 1px solid var(--border-color);
  display: flex;
  flex-direction: column;
  margin: 0;
  border-radius: 0;
  border-right: none;
  border-top: none;
  border-bottom: none;
}

.sidebar-title {
  padding: 1.5rem;
  border-bottom: 1px solid var(--border-color);
  font-size: 1.25rem;
}

.cart-items {
  flex: 1;
  overflow-y: auto;
  padding: 0 1.5rem;
}

.empty-cart {
  text-align: center;
  padding: 3rem 0;
  color: var(--text-secondary);
  font-style: italic;
}

.cart-summary {
  padding: 1.5rem;
  background: var(--bg-tertiary);
  border-top: 1px solid var(--border-color);
}

.summary-row {
  display: flex;
  justify-content: space-between;
  margin-bottom: 0.5rem;
  font-size: 1rem;
  color: var(--text-secondary);
}

.summary-row.total {
  font-size: 1.5rem;
  font-weight: 800;
  color: var(--text-primary);
  margin-top: 0.5rem;
  margin-bottom: 1.5rem;
}

.checkout-btn {
  width: 100%;
  padding: 1.25rem;
  font-size: 1.25rem;
  border-radius: 0.75rem;
  background: var(--color-success);
}
.checkout-btn:hover:not(:disabled) {
  background: #00C853;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 230, 118, 0.4);
}
.checkout-btn:disabled {
  background: #9CA3AF;
  cursor: not-allowed;
  transform: none;
  box-shadow: none;
}
</style>
