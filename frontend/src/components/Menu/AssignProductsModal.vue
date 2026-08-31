<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  optionGroup: { type: Object, required: true },
  categories: { type: Array, required: true },
  products: { type: Array, required: true }
});

const emit = defineEmits(['close', 'save']);

const selectedProductIds = ref([]);
const searchQuery = ref('');
const expandedCategories = ref([]);

const initializeSelections = () => {
  const linkedIds = [];
  
  // 1) Load from optionGroup.products if available
  if (props.optionGroup && props.optionGroup.products) {
    props.optionGroup.products.forEach(p => {
      if (!linkedIds.includes(p.id)) linkedIds.push(p.id);
    });
  }
  
  // 2) Fallback to checking inside products array
  props.products.forEach(p => {
    if (p.optionGroups && p.optionGroups.some(og => og.id === props.optionGroup.id)) {
      if (!linkedIds.includes(p.id)) linkedIds.push(p.id);
    } else if (p.option_groups && Array.isArray(p.option_groups) && p.option_groups.some(og => og.id === props.optionGroup.id || og === props.optionGroup.id)) {
      if (!linkedIds.includes(p.id)) linkedIds.push(p.id);
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
  let catProds = props.products.filter(p => p.category_id === categoryId);
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase();
    catProds = catProds.filter(p => p.name.toLowerCase().includes(query));
  }
  return catProds;
};

const isCategoryFullySelected = (categoryId) => {
  const catProds = getProductsByCategory(categoryId);
  if (catProds.length === 0) return false;
  return catProds.every(p => selectedProductIds.value.includes(p.id));
};

const isCategoryIndeterminate = (categoryId) => {
  const catProds = getProductsByCategory(categoryId);
  if (catProds.length === 0) return false;
  const selectedCount = catProds.filter(p => selectedProductIds.value.includes(p.id)).length;
  return selectedCount > 0 && selectedCount < catProds.length;
};

const toggleCategorySelection = (categoryId, event) => {
  event.stopPropagation();
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

const toggleCategoryExpand = (categoryId) => {
  const idx = expandedCategories.value.indexOf(categoryId);
  if (idx > -1) {
    expandedCategories.value.splice(idx, 1);
  } else {
    expandedCategories.value.push(categoryId);
  }
};

const handleSave = () => {
  emit('save', selectedProductIds.value);
};

import { resolveImageUrl } from '../../utils/imageUrl.js';
</script>

<template>
  <div class="modal-backdrop-full">
    <div class="modal-content-full">
      <div class="modal-header-clean">
        <button class="back-btn" @click="$emit('close')">
          <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        </button>
        <h2>Select products ({{ selectedProductIds.length }})</h2>
      </div>

      <div class="modal-body-clean">
        <div class="search-bar-wrapper">
          <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="search-icon"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input type="text" placeholder="Buscar..." v-model="searchQuery" class="search-input" />
        </div>
        
        <div class="category-list">
          <div v-for="cat in categories" :key="cat.id" class="cat-card">
            <div class="cat-card-header" @click="toggleCategoryExpand(cat.id)">
              <div class="cat-left">
                <svg v-if="!expandedCategories.includes(cat.id)" viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="chevron"><polyline points="6 9 12 15 18 9"></polyline></svg>
                <svg v-else viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="chevron"><polyline points="18 15 12 9 6 15"></polyline></svg>
                <h3>{{ cat.name }}</h3>
              </div>
              <div class="cat-right" @click="toggleCategorySelection(cat.id, $event)">
                <div class="cat-checkbox" :class="{ 'checked': isCategoryFullySelected(cat.id), 'indeterminate': isCategoryIndeterminate(cat.id) }">
                   <svg v-if="isCategoryFullySelected(cat.id)" viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                   <svg v-else-if="isCategoryIndeterminate(cat.id)" viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                </div>
              </div>
            </div>
            <div class="cat-card-body" v-if="expandedCategories.includes(cat.id)">
              <div 
                v-for="prod in getProductsByCategory(cat.id)" 
                :key="prod.id" 
                class="prod-row"
                @click="toggleProduct(prod.id)"
              >
                <div class="prod-left">
                  <div class="prod-image-wrapper">
                    <img v-if="prod.image_url" :src="resolveImageUrl(prod.image_url)" alt="Product" />
                    <div v-else class="prod-placeholder-img"></div>
                  </div>
                  <div class="prod-info">
                    <span class="prod-name">{{ prod.name }}</span>
                    <span class="prod-price">BOB {{ prod.price }}</span>
                  </div>
                </div>
                <div class="prod-right">
                  <div class="custom-checkbox" :class="{ 'checked': selectedProductIds.includes(prod.id) }">
                    <svg v-if="selectedProductIds.includes(prod.id)" viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                  </div>
                </div>
              </div>
              <div v-if="getProductsByCategory(cat.id).length === 0" class="empty-cat">
                Sin productos
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer-clean">
        <button class="btn-confirm-full" @click="handleSave">Confirmar</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop-full {
  position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
  background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;
}
.modal-content-full {
  background: var(--surface);
  width: 100%; max-width: 500px; height: 100%; max-height: 100vh;
  display: flex; flex-direction: column;
}
@media (min-width: 500px) {
  .modal-content-full {
    height: 90vh;
    border-radius: 12px;
  }
}
.modal-header-clean {
  padding: 16px 20px;
  display: flex; align-items: center; gap: 16px;
  border-bottom: 1px solid var(--border);
}
.back-btn {
  background: none; border: none; padding: 0; cursor: pointer; color: var(--ink-900);
  display: flex; align-items: center; justify-content: center;
}
.modal-header-clean h2 { margin: 0; font-size: 20px; font-weight: 700; color: var(--ink-900); }
.modal-body-clean {
  flex: 1; overflow-y: auto; padding: 20px; background: var(--surface);
}
.search-bar-wrapper {
  position: relative; margin-bottom: 24px;
}
.search-icon {
  position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--ink-400);
}
.search-input {
  width: 100%; padding: 14px 16px 14px 44px;
  border: 1px solid var(--border); border-radius: 20px;
  font-size: 15px; outline: none; transition: border-color 0.2s;
  background-color: var(--surface); color: var(--ink-900);
}
.search-input:focus { border-color: var(--passion-500); }
.search-input::placeholder { color: var(--ink-400); }

.category-list {
  display: flex; flex-direction: column; gap: 12px;
}
.cat-card {
  border: 1px solid var(--border); border-radius: 12px; overflow: hidden; background: var(--surface);
}
.cat-card-header {
  padding: 16px 20px; display: flex; justify-content: space-between; align-items: center;
  cursor: pointer; background: var(--surface);
}
.cat-left {
  display: flex; align-items: center; gap: 16px;
}
.cat-left .chevron { color: var(--ink-600); }
.cat-left h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--ink-900); }
.cat-right {
  display: flex; align-items: center;
}
.cat-checkbox {
  width: 22px; height: 22px; border: 2px solid var(--border); border-radius: 6px;
  display: flex; align-items: center; justify-content: center; background: var(--surface); transition: all 0.2s;
}
.cat-checkbox.checked, .cat-checkbox.indeterminate {
  background: var(--passion-500); border-color: var(--passion-500); color: white;
}

.cat-card-body {
  border-top: 1px solid var(--border); background: var(--surface);
}
.prod-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 16px 20px; border-bottom: 1px solid var(--border); cursor: pointer;
}
.prod-row:last-child { border-bottom: none; }
.prod-left {
  display: flex; align-items: center; gap: 16px;
}
.prod-image-wrapper {
  width: 48px; height: 48px; border-radius: 8px; overflow: hidden; background: var(--surface-hover); flex-shrink: 0;
}
.prod-image-wrapper img {
  width: 100%; height: 100%; object-fit: cover;
}
.prod-placeholder-img {
  width: 100%; height: 100%; background: var(--surface-hover);
}
.prod-info {
  display: flex; flex-direction: column; gap: 4px;
}
.prod-name { font-size: 15px; font-weight: 600; color: var(--ink-900); }
.prod-price { font-size: 14px; color: var(--ink-500); }

.custom-checkbox {
  width: 22px; height: 22px; border: 2px solid var(--border); border-radius: 6px;
  display: flex; align-items: center; justify-content: center; background: var(--surface); transition: all 0.2s;
}
.custom-checkbox.checked {
  background: var(--passion-500); border-color: var(--passion-500); color: white;
}

.empty-cat { padding: 16px 20px; font-size: 14px; color: var(--ink-500); font-style: italic; text-align: center; }

.modal-footer-clean {
  padding: 20px; background: var(--surface); border-top: 1px solid var(--border);
}
.btn-confirm-full {
  width: 100%; background: var(--passion-500); color: white;
  border: none; border-radius: 8px; padding: 16px; font-size: 16px; font-weight: 600;
  cursor: pointer; transition: background 0.2s;
}
.btn-confirm-full:hover { background: var(--passion-600); }
</style>
