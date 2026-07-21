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
          {{ item.name }}
        </div>
        <div class="ticket-item-mods" v-if="item.modifiers && item.modifiers.length > 0">
          <span v-for="(mod, idx) in item.modifiers" :key="idx">
            {{ mod.option_name }} <span v-if="mod.price > 0">(+Bs {{ Number(mod.price).toFixed(2) }})</span>
            <span v-if="idx < item.modifiers.length - 1">, </span>
          </span>
        </div>
        <div class="ticket-item-mods" v-else-if="item.is_weight_based">
          {{ item.quantity }} g · Bs ${(item.unit_price / 100).toFixed(4)}/g
        </div>
        <div class="ticket-item-mods" v-else>
          Sin modificadores
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
