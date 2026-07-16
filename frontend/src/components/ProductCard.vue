<script setup>
defineProps({
  product: Object,
});
defineEmits(['add']);
</script>
<template>
  <div class="product-card glass-panel" @click="$emit('add', product)">
    <div class="p-image-placeholder">
      <span>{{ product.name.charAt(0).toUpperCase() }}</span>
    </div>
    <div class="p-info">
      <h3>{{ product.name }}</h3>
      <p class="price">${{ Number(product.price).toFixed(2) }}</p>
    </div>
  </div>
</template>
<style scoped>
.product-card {
  display: flex;
  flex-direction: column;
  cursor: pointer;
  overflow: hidden;
  transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.2s;
  background: var(--bg-secondary);
}
.product-card:hover {
  transform: translateY(-4px);
  box-shadow: var(--shadow-lg);
}
.product-card:active {
  transform: scale(0.95);
}
.p-image-placeholder {
  height: 140px;
  background: linear-gradient(135deg, var(--color-secondary), var(--color-primary));
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 3.5rem;
  font-weight: 800;
  color: rgba(255, 255, 255, 0.9);
}
.p-info {
  padding: 1rem;
  text-align: center;
}
.p-info h3 {
  font-size: 1rem;
  margin-bottom: 0.5rem;
  color: var(--text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.p-info .price {
  font-weight: 800;
  color: var(--color-primary);
  font-size: 1.2rem;
}
</style>
