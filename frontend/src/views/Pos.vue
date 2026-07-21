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

const showModifierModal = ref(false);
const selectedProduct = ref(null);

function handleAddToCart(product) {
  if (product.option_groups && product.option_groups.length > 0) {
    selectedProduct.value = product;
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

function isProductInStock(product) {
  if (!product.is_active) return false;
  if (!product.recipes || product.recipes.length === 0) return true;

  for (const recipe of product.recipes) {
    if (recipe.quantity_required <= 0) continue;
    
    const ingredient = catalog.ingredients.find(i => i.id === recipe.ingredient_id);
    if (ingredient && ingredient.current_stock < recipe.quantity_required) {
      return false; // Out of stock
    }
  }
  return true;
}

function handleConfirmModifiers({ product, modifiers, finalPrice }) {
  showModifierModal.value = false;
  selectedProduct.value = null;
  
  cart.addItem({
    id: product.id,
    name: product.name,
    price: finalPrice,
    modifiers: modifiers,
    base_price: product.price
  }, 1);
}

function handleIncrease(item) {
  // Pass the exact same configuration back to addItem to increment the existing cartKey
  cart.addItem({ 
    id: item.product_id, 
    name: item.name, 
    price: item.unit_price, 
    modifiers: item.modifiers,
    base_price: item.base_price 
  }, 1);
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
    <ModifierModal
      :show="showModifierModal"
      :product="selectedProduct"
      @close="showModifierModal = false"
      @confirm="handleConfirmModifiers"
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
  height: 100%;
  flex: 1;
  overflow: hidden;
  background: var(--cream-100);
}
</style>
