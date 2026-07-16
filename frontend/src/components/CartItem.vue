<script setup>
defineProps({ item: Object });
const emit = defineEmits(['increase', 'decrease']);
</script>

<template>
  <!-- Uses global .ticket-item, .ticket-item-top, .ticket-item-name, 
       .ticket-item-mods, .ticket-item-price, .qty-control, .qty-val from style.css -->
  <div class="ticket-item">
    <div class="ticket-item-top">
      <div>
        <div class="ticket-item-name">
          {{ item.name }}{{ item.size ? ' · ' + item.size : '' }}
        </div>
        <div class="ticket-item-mods">
          {{ item.is_weight_based ? `${item.quantity} g · Bs ${(item.unit_price / 100).toFixed(4)}/g` : 'Sin modificadores' }}
        </div>
      </div>
      <div class="ticket-item-price">Bs {{ Number(item.subtotal).toFixed(2) }}</div>
    </div>
    <div class="qty-control">
      <button @click="emit('decrease', item)">−</button>
      <span class="qty-val">{{ item.is_weight_based ? item.quantity + ' g' : item.quantity }}</span>
      <button @click="emit('increase', item)">+</button>
    </div>
  </div>
</template>
