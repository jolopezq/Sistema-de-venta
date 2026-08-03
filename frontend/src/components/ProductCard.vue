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

const getImageUrl = computed(() => {
  if (!props.product.image_url) return null;
  if (props.product.image_url.startsWith('http') || props.product.image_url.startsWith('data:')) return props.product.image_url;
  const baseUrl = 'http://127.0.0.1:8000';
  const path = props.product.image_url.startsWith('/') ? props.product.image_url : '/storage/' + props.product.image_url;
  return baseUrl + path;
});
</script>

<template>
  <div
    class="product-card"
    :class="{ 'pulse-add': isAdding, 'disabled-card': disabled }"
    @click="handleAdd"
  >
    <div class="product-icon">
      <img v-if="getImageUrl" :src="getImageUrl" :alt="product.name" class="product-img" />
      <template v-else>
        {{ product.emoji || '🍓' }}
      </template>
    </div>
    <div class="product-name">{{ product.name }}</div>
    <div class="product-desc">
      {{ product.is_weight_based ? 'Venta por peso' : (product.description || '') }}
    </div>

    <!-- If it has modifiers, we can show a small badge or note -->
    <div v-if="product.option_groups && product.option_groups.length > 0" style="margin:4px 0;">
      <span class="size-chip active" style="font-size:10px;padding:3px 8px;background:var(--acai-700);color:white;border-color:var(--acai-700);">
        Personalizable
      </span>
    </div>

    <div class="product-row">
      <span class="product-price" :class="{ weight: product.is_weight_based }">{{ displayPrice }}</span>
      <button class="add-btn" :disabled="disabled" @click.stop="handleAdd">+</button>
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
  border-radius: inherit;
}
</style>
