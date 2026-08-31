<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  product: Object,
  disabled: Boolean,
});
const emit = defineEmits(['add']);

const isAdding = ref(false);

const handleAdd = () => {
  if (props.disabled) return;
  isAdding.value = true;
  setTimeout(() => isAdding.value = false, 300);
  emit('add', props.product);
};

const displayPrice = computed(() => {
  if (props.product.is_weight_based) {
    return `Bs ${Number(props.product.price_per_gram * 100).toFixed(2)} / 100g`;
  }
  return `Bs ${Number(props.product.price).toFixed(2)}`;
});

import { resolveImageUrl } from '../utils/imageUrl.js';

const getImageUrl = computed(() => {
  return resolveImageUrl(props.product.image_url);
});
</script>

<template>
  <div
    class="product-card"
    :class="{ 'pulse-add': isAdding, 'disabled-card': disabled }"
    @click="handleAdd"
  >
    <div class="product-icon">
      <img
        v-if="getImageUrl"
        :src="getImageUrl"
        :alt="product.name"
        class="product-img"
        loading="lazy"
      />
      <div v-else class="product-fallback">
        <span class="fallback-emoji">{{ product.emoji || '🍓' }}</span>
      </div>

      <!-- Badge 'Personalizable' superpuesto elegantemente en la imagen -->
      <span
        v-if="product.option_groups && product.option_groups.length > 0"
        class="product-img-badge"
      >
        ✨ Personalizable
      </span>
    </div>

    <div class="product-name" :title="product.name">{{ product.name }}</div>
    <div class="product-desc" :title="product.description">
      {{ product.is_weight_based ? 'Venta por peso' : (product.description || 'Sin descripción disponible') }}
    </div>

    <div class="product-row">
      <span class="product-price" :class="{ weight: product.is_weight_based }">{{ displayPrice }}</span>
      <button
        class="add-btn"
        :disabled="disabled"
        aria-label="Agregar al carrito"
        @click.stop="handleAdd"
      >
        +
      </button>
    </div>
  </div>
</template>

<style scoped>
@keyframes pulse {
  0% { transform: scale(1); }
  50% { transform: scale(1.02); border-color: var(--lime-500); }
  100% { transform: scale(1); }
}
.pulse-add { animation: pulse 0.3s ease; }
.disabled-card {
  opacity: 0.5;
  filter: grayscale(100%);
  cursor: not-allowed;
}
.disabled-card:active {
  transform: none;
  box-shadow: none;
}
.product-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.35s cubic-bezier(0.2, 0.8, 0.2, 1);
  display: block;
}
.product-card:hover .product-img {
  transform: scale(1.06);
}
.product-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, var(--cream-200) 0%, var(--cream-300) 100%);
}
.fallback-emoji {
  font-size: 38px;
  filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
  transition: transform 0.3s ease;
}
.product-card:hover .fallback-emoji {
  transform: scale(1.15) rotate(5deg);
}
</style>
