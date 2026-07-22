<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  optionGroup: { type: Object, required: true },
  categories: { type: Array, required: true },
  products: { type: Array, required: true }
});

const emit = defineEmits(['close', 'save']);

// Initialize selected products based on existing relationships
const selectedProductIds = ref([]);
const initializeSelections = () => {
  // We need to find which products already have this option_group_id
  const linkedIds = [];
  props.products.forEach(p => {
    // If the product has option_groups loaded as full objects
    if (p.optionGroups && p.optionGroups.some(og => og.id === props.optionGroup.id)) {
      linkedIds.push(p.id);
    } 
    // If it has them as IDs
    else if (p.option_groups && p.option_groups.includes(props.optionGroup.id)) {
      linkedIds.push(p.id);
    }
  });
  selectedProductIds.value = linkedIds;
};
initializeSelections();

const toggleProduct = (productId) => {
  const index = selectedProductIds.value.indexOf(productId);
  if (index > -1) {
    selectedProductIds.value.splice(index, 1);
  } else {
    selectedProductIds.value.push(productId);
  }
};

const getProductsByCategory = (categoryId) => {
  return props.products.filter(p => p.category_id === categoryId);
};

const isCategoryFullySelected = (categoryId) => {
  const catProds = getProductsByCategory(categoryId);
  if (catProds.length === 0) return false;
  return catProds.every(p => selectedProductIds.value.includes(p.id));
};

const toggleCategory = (categoryId) => {
  const catProds = getProductsByCategory(categoryId);
  if (isCategoryFullySelected(categoryId)) {
    // Deselect all
    catProds.forEach(p => {
      const idx = selectedProductIds.value.indexOf(p.id);
      if (idx > -1) selectedProductIds.value.splice(idx, 1);
    });
  } else {
    // Select all
    catProds.forEach(p => {
      if (!selectedProductIds.value.includes(p.id)) {
        selectedProductIds.value.push(p.id);
      }
    });
  }
};

const handleSave = () => {
  emit('save', selectedProductIds.value);
};
</script>

<template>
  <div class="modal-backdrop">
    <div class="modal-content options-modal">
      <div class="modal-header">
        <h3>Vincular Productos a: {{ optionGroup.name }}</h3>
        <button class="close-btn" @click="$emit('close')">&times;</button>
      </div>

      <div class="modal-body">
        <p class="text-muted">Selecciona los productos que ofrecerán este grupo de modificadores.</p>
        
        <div class="tree-view">
          <div v-for="cat in categories" :key="cat.id" class="category-block">
            <div class="category-row" @click="toggleCategory(cat.id)">
              <div class="checkbox" :class="{ checked: isCategoryFullySelected(cat.id) }"></div>
              <h4>{{ cat.name }}</h4>
            </div>
            <div class="products-list">
              <div 
                v-for="prod in getProductsByCategory(cat.id)" 
                :key="prod.id" 
                class="product-row"
                @click="toggleProduct(prod.id)"
              >
                <div class="checkbox small" :class="{ checked: selectedProductIds.includes(prod.id) }"></div>
                <span>{{ prod.name }}</span>
              </div>
              <div v-if="getProductsByCategory(cat.id).length === 0" class="empty-cat">
                Sin productos
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn-ghost" @click="$emit('close')">Cancelar</button>
        <button class="btn-primary" @click="handleSave">Guardar Vinculación</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop {
  position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;
}
.options-modal {
  background: var(--surface);
  border-radius: var(--radius-lg);
  width: 90%; max-width: 500px;
  max-height: 85vh;
  display: flex; flex-direction: column;
  box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
}
.modal-header {
  padding: 20px 24px; border-bottom: 1px solid var(--border);
  display: flex; justify-content: space-between; align-items: center;
}
.modal-header h3 { margin: 0; color: var(--ink-900); font-size: 18px; }
.close-btn { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--ink-500); }
.modal-body {
  padding: 24px; overflow-y: auto; flex: 1;
}
.text-muted { color: var(--ink-500); font-size: 14px; margin-bottom: 24px; }

.tree-view { display: flex; flex-direction: column; gap: 16px; }
.category-block { background: var(--cream-50); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.category-row { padding: 12px 16px; background: var(--cream-100); display: flex; align-items: center; gap: 12px; cursor: pointer; border-bottom: 1px solid var(--border); }
.category-row h4 { margin: 0; font-size: 14px; color: var(--ink-900); text-transform: uppercase; font-weight: 800; }
.products-list { padding: 8px 16px; }
.product-row { display: flex; align-items: center; gap: 12px; padding: 8px 0; cursor: pointer; font-size: 14px; color: var(--ink-800); }
.product-row:hover { color: var(--passion-600); }
.empty-cat { font-size: 12px; color: var(--ink-400); padding: 8px 0; font-style: italic; }

.checkbox { width: 18px; height: 18px; border: 2px solid var(--ink-300); border-radius: 4px; display: flex; align-items: center; justify-content: center; background: var(--surface); transition: 0.1s; }
.checkbox.small { width: 16px; height: 16px; border-width: 1.5px; }
.checkbox.checked { background: var(--passion-500); border-color: var(--passion-500); }
.checkbox.checked::after { content: ''; width: 4px; height: 8px; border: solid white; border-width: 0 2px 2px 0; transform: rotate(45deg); margin-bottom: 2px; }

.modal-footer {
  padding: 16px 24px; border-top: 1px solid var(--border);
  display: flex; justify-content: flex-end; gap: 12px;
}
.btn-ghost { background: transparent; border: none; font-weight: 700; color: var(--ink-700); padding: 8px 16px; border-radius: 8px; cursor: pointer; }
.btn-primary { background: var(--passion-500); color: white; border: none; border-radius: var(--radius-md); padding: 10px 20px; font-weight: 700; cursor: pointer; }
</style>
