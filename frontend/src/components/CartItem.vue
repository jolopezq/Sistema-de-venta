<script setup>
defineProps({
  item: Object,
});
defineEmits(['increase', 'decrease', 'remove']);
</script>
<template>
  <div class="cart-item">
    <div class="item-details">
      <h4>{{ item.name }}</h4>
      <p>${{ Number(item.unit_price).toFixed(2) }} x {{ item.quantity }}</p>
    </div>
    
    <div class="item-actions">
      <button class="qty-btn" @click="$emit('decrease', item)">-</button>
      <span class="qty">{{ item.quantity }}</span>
      <button class="qty-btn" @click="$emit('increase', item)">+</button>
    </div>
    
    <div class="item-total">
      ${{ Number(item.subtotal).toFixed(2) }}
    </div>
  </div>
</template>
<style scoped>
.cart-item {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr;
  align-items: center;
  gap: 0.5rem;
  padding: 1rem 0;
  border-bottom: 1px dashed var(--border-color);
}
.item-details h4 {
  font-size: 0.95rem;
  margin-bottom: 0.2rem;
  color: var(--text-primary);
}
.item-details p {
  font-size: 0.85rem;
  color: var(--text-secondary);
}
.item-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--bg-tertiary);
  border-radius: 2rem;
  padding: 0.25rem;
}
.qty-btn {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: none;
  background: var(--bg-secondary);
  font-weight: bold;
  cursor: pointer;
  box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}
.qty-btn:active {
  background: var(--color-primary);
  color: white;
}
.qty {
  font-weight: 600;
  font-size: 0.9rem;
}
.item-total {
  font-weight: 800;
  text-align: right;
  color: var(--text-primary);
}
</style>
