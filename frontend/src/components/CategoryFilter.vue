<script setup>
defineProps({
  categories: Array,
  activeCategoryId: Number,
});
defineEmits(['select']);
</script>
<template>
  <div class="category-filter">
    <button 
      class="cat-btn" 
      :class="{ active: activeCategoryId === null }"
      @click="$emit('select', null)"
    >
      Todos
    </button>
    <button 
      v-for="cat in categories" 
      :key="cat.id"
      class="cat-btn"
      :class="{ active: activeCategoryId === cat.id }"
      @click="$emit('select', cat.id)"
    >
      {{ cat.name }}
    </button>
  </div>
</template>
<style scoped>
.category-filter {
  display: flex;
  gap: 0.75rem;
  overflow-x: auto;
  padding-bottom: 0.5rem;
  margin-bottom: 1.5rem;
}
.cat-btn {
  padding: 0.75rem 1.5rem;
  border-radius: 2rem;
  border: 1px solid var(--border-color);
  background: var(--bg-secondary);
  color: var(--text-primary);
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.2s ease;
  box-shadow: var(--shadow-sm);
}
.cat-btn:hover {
  background: var(--bg-tertiary);
}
.cat-btn.active {
  background: var(--color-primary);
  color: white;
  border-color: var(--color-primary);
  box-shadow: 0 4px 10px rgba(140, 82, 255, 0.4);
}
</style>
