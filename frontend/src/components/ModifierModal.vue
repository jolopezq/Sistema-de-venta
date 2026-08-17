<script setup>
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';
import { useCatalogStore } from '../stores/catalog';

const catalog = useCatalogStore();

const props = defineProps({
  show: Boolean,
  product: Object
});

const emit = defineEmits(['close', 'confirm']);

// Store selected options: { optionGroupId: [optionId1, optionId2] }
const selections = ref({});
const expandedGroups = ref({});
const errorMessages = ref({});
const itemNote = ref('');
const selectedAllergens = ref([]);
const isTakeaway = ref(false);
const allergens = [
  { id: 'lactose', name: 'Lactosa', icon: '🥛' },
  { id: 'gluten', name: 'Gluten', icon: '🌾' },
  { id: 'almond', name: 'Almendras', icon: '🥜' },
  { id: 'fruit', name: 'Fruta', icon: '🍓' },
  { id: 'egg', name: 'Huevo', icon: '🥚' }
];

watch(() => props.show, (newVal) => {
  if (newVal && props.product) {
    selections.value = {};
    expandedGroups.value = {};
    errorMessages.value = {};
    itemNote.value = '';
    selectedAllergens.value = [];
    isTakeaway.value = false;
    // Initialize selections with default values
    if (filteredGroups.value) {
      filteredGroups.value.forEach(og => {
        const defaultOptions = og.options.filter(o => o.is_default).map(o => o.id);
        selections.value[og.id] = defaultOptions.slice(0, og.max_selections); // Limit defaults to max_selections
        
        if (og.max_selections === 1) {
          expandedGroups.value[og.id] = true;
        } else {
          expandedGroups.value[og.id] = false;
        }
      });
    }
  }
});

const toggleAccordion = (groupId) => {
  expandedGroups.value[groupId] = !expandedGroups.value[groupId];
};

const filteredGroups = computed(() => {
  if (!props.product?.option_groups) return [];
  return props.product.option_groups
    .filter(og => og.is_active)
    .map(og => {
      return {
        ...og,
        options: [...og.options]
          .filter(opt => opt.is_active && !(props.product?.excluded_options || []).includes(opt.id))
          .sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0))
      };
    });
});

const toggleOption = (groupId, optionId, maxSelections) => {
  const current = selections.value[groupId] || [];
  if (maxSelections === 1) {
    // Radio behavior (toggle if already selected)
    if (current.includes(optionId)) {
      selections.value[groupId] = [];
    } else {
      selections.value[groupId] = [optionId];
    }
  } else {
    // Checkbox behavior
    const idx = current.indexOf(optionId);
    if (idx > -1) {
      current.splice(idx, 1); // Deselect
    } else {
      if (current.length < maxSelections) {
        current.push(optionId); // Select
      }
    }
  }
  validateGroup(groupId);
};

const validateGroup = (groupId) => {
  const og = filteredGroups.value.find(g => g.id === groupId);
  if (!og) return;
  const selectedCount = selections.value[groupId]?.length || 0;
  if (selectedCount < og.min_selections) {
    errorMessages.value[groupId] = `Selecciona al menos ${og.min_selections} opción(es).`;
  } else {
    errorMessages.value[groupId] = null;
  }
};

const isValid = computed(() => {
  if (!filteredGroups.value || filteredGroups.value.length === 0) return true;
  for (const og of filteredGroups.value) {
    if ((selections.value[og.id]?.length || 0) < og.min_selections) {
      return false;
    }
  }
  return true;
});

const totalPrice = computed(() => {
  if (!props.product) return 0;
  let total = Number(props.product.price) || 0;
  if (filteredGroups.value) {
    filteredGroups.value.forEach(og => {
      const selectedIds = selections.value[og.id] || [];
      selectedIds.forEach(optId => {
        const opt = og.options.find(o => o.id === optId);
        if (opt) {
          total += Number(opt.additional_price) || 0;
        }
      });
    });
  }
  return total;
});

const handleConfirm = () => {
  // Check validation one last time
  if (filteredGroups.value) {
    filteredGroups.value.forEach(og => validateGroup(og.id));
  }
  if (!isValid.value) return;

  // Build selected modifiers array for the cart
  const selectedModifiers = [];
  if (filteredGroups.value) {
    filteredGroups.value.forEach(og => {
      const selectedIds = selections.value[og.id] || [];
      selectedIds.forEach(optId => {
        const opt = og.options.find(o => o.id === optId);
        if (opt) {
          selectedModifiers.push({
            group_name: og.name,
            option_id: opt.id,
            option_name: opt.name,
            price: Number(opt.additional_price) || 0
          });
        }
      });
    });
  }

  emit('confirm', {
    product: props.product,
    modifiers: selectedModifiers,
    finalPrice: totalPrice.value,
    itemNote: itemNote.value,
    allergenFlags: selectedAllergens.value,
    isTakeaway: isTakeaway.value
  });
};

const toggleAllergen = (allergenId) => {
  const index = selectedAllergens.value.indexOf(allergenId);
  if (index > -1) {
    selectedAllergens.value.splice(index, 1);
  } else {
    selectedAllergens.value.push(allergenId);
  }
};

const isOptionInStock = (opt) => {
  if (!opt.recipes || opt.recipes.length === 0) return true;
  for (const recipe of opt.recipes) {
    if (recipe.quantity_delta <= 0) continue;
    const ingredient = catalog.ingredients.find(i => i.id === recipe.ingredient_id);
    if (ingredient && ingredient.current_stock < recipe.quantity_delta) {
      return false; // Out of stock
    }
  }
  return true;
};

const handleOptionClick = (og, opt) => {
  if (!isOptionInStock(opt)) return;
  if (og.max_selections > 1 && !(selections.value[og.id] || []).includes(opt.id) && (selections.value[og.id] || []).length >= og.max_selections) {
    return;
  }
  toggleOption(og.id, opt.id, og.max_selections);
};

const handleGlobalKeydown = (e) => {
  if (props.show && e.key === 'Enter') {
    if (e.target && e.target.tagName && e.target.tagName.toLowerCase() === 'textarea') {
      return; // allow newline in textarea
    }
    e.preventDefault();
    if (isValid.value) {
      handleConfirm();
    }
  }
};

onMounted(() => {
  window.addEventListener('keydown', handleGlobalKeydown);
});

onUnmounted(() => {
  window.removeEventListener('keydown', handleGlobalKeydown);
});
</script>

<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal-content">
      <div class="modal-header">
        <h3>{{ product?.name }}</h3>
        <button class="close-btn" @click="$emit('close')">×</button>
      </div>
      
      <div class="modal-body">
        <p class="base-price">Precio Base: Bs {{ Number(product?.price || 0).toFixed(2) }}</p>
        
        <div v-for="og in filteredGroups" :key="og.id" class="option-group" :class="{ 'is-single': og.max_selections === 1 }">
          <div class="og-header" :class="{ 'cursor-pointer': og.max_selections > 1 }" @click="og.max_selections > 1 ? toggleAccordion(og.id) : null">
            <div class="og-header-title">
              <h4>{{ og.name }}</h4>
              <span class="og-rules">
                (Mín: {{ og.min_selections }}, Máx: {{ og.max_selections }})
              </span>
            </div>
            <div v-if="og.max_selections > 1" class="accordion-icon">
              <span v-if="expandedGroups[og.id]">▲</span>
              <span v-else>▼</span>
            </div>
          </div>
          <div v-if="errorMessages[og.id]" class="error-msg">{{ errorMessages[og.id] }}</div>
          
          <div class="options-list" :class="{ 'grid-list': og.max_selections === 1 }" v-show="expandedGroups[og.id]">
            <label 
              v-for="opt in og.options" 
              :key="opt.id" 
              class="option-item"
              :class="{ 
                selected: selections[og.id]?.includes(opt.id), 
                'out-of-stock': !isOptionInStock(opt),
                'single-option': og.max_selections === 1
              }"
              @click.prevent="handleOptionClick(og, opt)"
            >
              <input 
                v-if="og.max_selections > 1"
                type="checkbox"
                :name="'group_' + og.id"
                :checked="selections[og.id]?.includes(opt.id)"
                readonly
                :disabled="!isOptionInStock(opt) || (og.max_selections > 1 && !selections[og.id]?.includes(opt.id) && selections[og.id]?.length >= og.max_selections)"
              >
              <span class="opt-name">
                {{ opt.name }}
                <span v-if="!isOptionInStock(opt)" style="font-size: 11px; color: var(--danger-600); margin-left: 6px;">(Agotado)</span>
              </span>
              <span class="opt-price" v-if="opt.additional_price > 0">+Bs {{ Number(opt.additional_price).toFixed(2) }}</span>
            </label>
          </div>
        </div>

        <div class="takeaway-section" :class="{ 'is-active': isTakeaway }">
          <label class="switch-label">
            <span class="switch-text">
              <strong>Para llevar</strong>
              <small>Empaquetar este ítem para llevar</small>
            </span>
            <div class="toggle-switch">
              <input type="checkbox" v-model="isTakeaway">
              <span class="slider"></span>
            </div>
          </label>
        </div>

        <div class="note-section">
          <h4>Nota especial del ítem (Opcional)</h4>
          <textarea 
            v-model="itemNote" 
            placeholder="Ej: Sin fresa, leche deslactosada..."
            class="note-textarea"
          ></textarea>
        </div>

        <div class="allergen-section">
          <h4>Alertas de Alérgenos</h4>
          <div class="allergen-chips">
            <button 
              v-for="allergen in allergens" 
              :key="allergen.id"
              class="allergen-chip"
              :class="{ active: selectedAllergens.includes(allergen.id) }"
              @click="toggleAllergen(allergen.id)"
            >
              {{ allergen.icon }} {{ allergen.name }}
            </button>
          </div>
        </div>
      </div>
      
      <div class="modal-footer">
        <div class="total-preview">Total: Bs {{ totalPrice.toFixed(2) }}</div>
        <button class="btn btn-primary" :disabled="!isValid" @click="handleConfirm">
          Agregar al Carrito
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-overlay {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}
.modal-content {
  background: var(--surface);
  border-radius: 16px;
  width: 90%;
  max-width: 500px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-shadow: var(--shadow-pop);
}
.modal-header {
  padding: 20px 24px;
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.modal-header h3 { margin: 0; color: var(--ink-900); }
.close-btn {
  background: none; border: none; font-size: 24px; cursor: pointer; color: var(--ink-500);
}
.modal-body {
  padding: 24px;
  overflow-y: auto;
  flex: 1;
}
.base-price {
  font-weight: 700; color: var(--ink-900); margin-top: 0; margin-bottom: 20px;
}
.option-group {
  margin-bottom: 24px;
}
.og-header {
  display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;
  padding: 8px 0;
}
.og-header.cursor-pointer {
  cursor: pointer;
  user-select: none;
}
.og-header.cursor-pointer:hover h4 {
  color: var(--passion-500);
}
.og-header-title {
  display: flex; align-items: baseline; gap: 8px;
}
.accordion-icon {
  font-size: 12px; color: var(--ink-500);
}
.og-header h4 { margin: 0; font-size: 15px; color: var(--ink-800); }
.og-rules { font-size: 12px; color: var(--ink-500); }
.error-msg {
  color: var(--danger-600); font-size: 12px; margin-bottom: 8px; font-weight: 600;
}
.options-list {
  display: flex; flex-direction: column; gap: 8px;
}
.options-list.grid-list {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
}
.option-item {
  display: flex; align-items: center; padding: 12px 16px; border: 2px solid var(--border);
  border-radius: 10px; cursor: pointer; transition: all 0.2s ease;
}
.option-item.single-option {
  flex-direction: column;
  justify-content: center;
  align-items: center;
  padding: 16px;
  text-align: center;
  border-radius: 12px;
  height: 100%;
}
.option-item.single-option .opt-name {
  margin-bottom: 4px;
  text-align: center;
  width: 100%;
}
.option-item.single-option .opt-price {
  font-size: 13px;
}
.option-item:hover { background: var(--surface-hover); }
.option-item.selected { border-color: var(--passion-500); background: var(--surface-hover); }
.option-item input { margin-right: 12px; accent-color: var(--passion-500); width: 16px; height: 16px; }
.opt-name { flex: 1; font-weight: 600; color: var(--ink-900); }
.opt-price { font-weight: 700; color: var(--passion-600); }
.modal-footer {
  padding: 20px 24px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--surface-hover); border-radius: 0 0 16px 16px;
}
.total-preview { font-size: 18px; font-weight: 800; color: var(--ink-900); }
.btn-primary { background: var(--passion-500); color: white; border: none; padding: 12px 24px; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }

.option-item.out-of-stock {
  opacity: 0.5;
  filter: grayscale(100%);
  cursor: not-allowed;
  background: var(--surface-hover);
}
.option-item.out-of-stock:hover {
  background: var(--surface-hover);
}

.note-section {
  margin-bottom: 20px;
}
.note-section h4 {
  margin: 0 0 8px;
  font-size: 14px;
  color: var(--ink-800);
}
.note-textarea {
  width: 100%;
  padding: 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-family: inherit;
  resize: vertical;
  min-height: 60px;
  background: var(--surface);
  color: var(--ink-900);
}
.note-textarea:focus {
  outline: none;
  border-color: var(--passion-500);
}

.takeaway-section {
  margin-bottom: 20px;
  background: var(--surface);
  border: 2px solid var(--border);
  border-radius: 10px;
  padding: 12px 16px;
  transition: all 0.2s ease;
}
.takeaway-section.is-active {
  border-color: var(--passion-500);
  background: var(--surface-hover);
}
.switch-label {
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
}
.switch-text {
  display: flex;
  flex-direction: column;
}
.switch-text strong {
  font-size: 15px;
  color: var(--ink-900);
}
.switch-text small {
  font-size: 12px;
  color: var(--ink-500);
}
.toggle-switch {
  position: relative;
  display: inline-block;
  width: 44px;
  height: 24px;
}
.toggle-switch input {
  opacity: 0;
  width: 0;
  height: 0;
}
.slider {
  position: absolute;
  cursor: pointer;
  top: 0; left: 0; right: 0; bottom: 0;
  background-color: var(--ink-300);
  transition: .3s;
  border-radius: 24px;
  box-shadow: inset 0 1px 3px rgba(0,0,0,0.15);
}
.slider:before {
  position: absolute;
  content: "";
  height: 18px;
  width: 18px;
  left: 3px;
  bottom: 3px;
  background-color: white;
  transition: .3s;
  border-radius: 50%;
  box-shadow: 0 2px 4px rgba(0,0,0,0.25);
}
input:checked + .slider {
  background-color: var(--passion-500);
}
input:checked + .slider:before {
  transform: translateX(20px);
}

.allergen-section {
  margin-bottom: 10px;
}
.allergen-section h4 {
  margin: 0 0 8px;
  font-size: 14px;
  color: var(--ink-800);
}
.allergen-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.allergen-chip {
  padding: 6px 12px;
  border: 1px solid var(--border);
  border-radius: 20px;
  background: var(--surface);
  color: var(--ink-700);
  cursor: pointer;
  font-size: 13px;
  transition: all 0.2s;
  font-weight: 600;
}
.allergen-chip:hover {
  background: var(--surface-hover);
}
.allergen-chip.active {
  background: var(--warning-100);
  border-color: var(--warning-500);
  color: var(--warning-800);
}
</style>
