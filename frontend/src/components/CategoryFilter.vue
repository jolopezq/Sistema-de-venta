<script setup>
defineProps({
  categories: Array,
  activeCategoryId: [Number, null],
});
defineEmits(['select']);
</script>

<template>
  <div class="cat-tabs">
    <div
      class="cat-tab"
      :class="{ active: activeCategoryId === null }"
      @click="$emit('select', null)"
    >
      Todos
    </div>
    <div
      v-for="cat in categories"
      :key="cat.id"
      class="cat-tab"
      :class="{ active: activeCategoryId === cat.id }"
      @click="$emit('select', cat.id)"
    >
      {{ cat.name }}
    </div>
  </div>
</template>

<!-- 
  No scoped styles: .cat-tabs and .cat-tab are defined globally
  in style.css (extracted verbatim from the prototype).
-->
