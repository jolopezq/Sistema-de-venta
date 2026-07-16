<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  product: Object,
});
const emit = defineEmits(['add']);

const isAdding = ref(false);

const selectedVariant = ref(
  props.product.variants && props.product.variants.length > 0
    ? props.product.variants[0]
    : null
);

const handleAdd = () => {
  if (props.product.stock === 0) return;
  isAdding.value = true;
  setTimeout(() => isAdding.value = false, 300);
  emit('add', {
    ...props.product,
    price: selectedVariant.value ? selectedVariant.value.price : props.product.price,
    size: selectedVariant.value ? selectedVariant.value.size : null
  });
};

const displayPrice = computed(() => {
  if (selectedVariant.value) {
    return `Desde Bs ${Number(selectedVariant.value.price).toFixed(2)}`;
  }
  if (props.product.is_weight_based) {
    return `Bs ${Number(props.product.price_per_gram * 100).toFixed(2)} / 100g`;
  }
  return `Bs ${Number(props.product.price).toFixed(2)}`;
});
</script>

<template>
  <!-- Uses global .product-card, .product-icon, .product-name, .product-row, .product-price, .add-btn from style.css -->
  <div
    class="product-card"
    :class="{ 'pulse-add': isAdding }"
    @click="handleAdd"
  >
    <div class="product-icon">
      {{ product.emoji || '🍓' }}
    </div>
    <div class="product-name">{{ product.name }}</div>
    <div class="product-desc">
      {{ product.is_weight_based ? 'Venta por peso' : (product.description || '') }}
    </div>

    <!-- Size chips for variants -->
    <div v-if="product.variants && product.variants.length > 0" style="display:flex;gap:4px;flex-wrap:wrap;margin:2px 0;">
      <span
        v-for="v in product.variants"
        :key="v.id"
        class="size-chip"
        :class="{ active: selectedVariant && selectedVariant.id === v.id }"
        style="font-size:10px;padding:3px 8px;"
        @click.stop="selectedVariant = v"
      >{{ v.size.charAt(0) }}</span>
    </div>

    <div class="product-row">
      <span class="product-price" :class="{ weight: product.is_weight_based }">{{ displayPrice }}</span>
      <button class="add-btn" :disabled="product.stock === 0" @click.stop="handleAdd">+</button>
    </div>
  </div>
</template>

<style scoped>
@keyframes pulse {
  0% { transform: scale(1); }
  50% { transform: scale(1.03); border-color: var(--lime-500); }
  100% { transform: scale(1); }
}
.pulse-add { animation: pulse 0.3s ease; }
</style>
