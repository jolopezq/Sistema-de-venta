<script setup>
import { ref, watch, computed } from 'vue';
import { useCatalogStore } from '../stores/catalog';

const catalog = useCatalogStore();

const props = defineProps({
  show: Boolean,
  product: Object
});

const emit = defineEmits(['close', 'confirm']);

// Store selected options: { optionGroupId: [optionId1, optionId2] }
const selections = ref({});
const errorMessages = ref({});

watch(() => props.show, (newVal) => {
  if (newVal && props.product) {
    selections.value = {};
    errorMessages.value = {};
    // Initialize selections with default values
    if (filteredGroups.value) {
      filteredGroups.value.forEach(og => {
        const defaultOptions = og.options.filter(o => o.is_default).map(o => o.id);
        selections.value[og.id] = defaultOptions.slice(0, og.max_selections); // Limit defaults to max_selections
      });
    }
  }
});

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
  const current = selections.value[groupId];
  if (maxSelections === 1) {
    // Radio behavior
    selections.value[groupId] = [optionId];
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
    finalPrice: totalPrice.value
  });
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
        
        <div v-for="og in filteredGroups" :key="og.id" class="option-group">
          <div class="og-header">
            <h4>{{ og.name }}</h4>
            <span class="og-rules">
              (Mín: {{ og.min_selections }}, Máx: {{ og.max_selections }})
            </span>
          </div>
          <div v-if="errorMessages[og.id]" class="error-msg">{{ errorMessages[og.id] }}</div>
          
          <div class="options-list">
            <label 
              v-for="opt in og.options" 
              :key="opt.id" 
              class="option-item"
              :class="{ selected: selections[og.id]?.includes(opt.id), 'out-of-stock': !isOptionInStock(opt) }"
            >
              <input 
                :type="og.max_selections === 1 ? 'radio' : 'checkbox'"
                :name="'group_' + og.id"
                :checked="selections[og.id]?.includes(opt.id)"
                @change="toggleOption(og.id, opt.id, og.max_selections)"
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
  display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px;
}
.og-header h4 { margin: 0; font-size: 15px; color: var(--ink-800); }
.og-rules { font-size: 12px; color: var(--ink-500); }
.error-msg {
  color: var(--danger-600); font-size: 12px; margin-bottom: 8px; font-weight: 600;
}
.options-list {
  display: flex; flex-direction: column; gap: 8px;
}
.option-item {
  display: flex; align-items: center; padding: 12px 16px; border: 2px solid var(--border);
  border-radius: 10px; cursor: pointer; transition: 0.2s;
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
</style>
