<script setup>
defineProps({
  categories: Array,
  /** null = «Todos»; undefined = ninguna marcada (mientras se busca en todo el menú) */
  activeCategoryId: { type: [Number, null], default: null },
});
defineEmits(['select']);
</script>

<template>
  <div class="cat-tabs" role="group" aria-label="Categorías del menú">
    <button
      type="button"
      class="cat-tab"
      :class="{ active: activeCategoryId === null }"
      :aria-pressed="activeCategoryId === null"
      @click="$emit('select', null)"
    >
      Todos
    </button>
    <button
      v-for="cat in categories"
      :key="cat.id"
      type="button"
      class="cat-tab"
      :class="{ active: activeCategoryId === cat.id }"
      :aria-pressed="activeCategoryId === cat.id"
      @click="$emit('select', cat.id)"
    >
      {{ cat.name }}
    </button>
  </div>
</template>

<!--
  Sin estilos scoped: .cat-tabs y .cat-tab están en style.css.
-->
