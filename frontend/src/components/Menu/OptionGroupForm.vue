<script setup>
import { ref, watch, computed } from 'vue';
import { apiFetch } from '../../services/api';
import AssignProductsModal from './AssignProductsModal.vue';

const props = defineProps({
  optionGroup: { type: Object, required: true },
  ingredients: { type: Array, required: true },
  categories: { type: Array, default: () => [] },
  products: { type: Array, default: () => [] },
  formErrors: { type: Object, default: () => ({}) }
});

const emit = defineEmits(['save', 'delete', 'update-success', 'alert', 'confirm', 'close']);

const localGroup = ref(JSON.parse(JSON.stringify(props.optionGroup)));

watch(() => props.optionGroup, (newVal) => {
  localGroup.value = JSON.parse(JSON.stringify(newVal));
  if (!localGroup.value.options) localGroup.value.options = [];
}, { deep: true, immediate: true });

// --- Sub-modal Agregar/Editar Opcional ---
const showAddOptionModal = ref(false);
const editingOptionIndex = ref(null);
const newOptName = ref('');
const newOptPrice = ref(0);

const openAddOptionModal = () => {
  editingOptionIndex.value = null;
  newOptName.value = '';
  newOptPrice.value = 0;
  showAddOptionModal.value = true;
};

const openEditOptionModal = (index) => {
  editingOptionIndex.value = index;
  const opt = localGroup.value.options[index];
  newOptName.value = opt.name;
  newOptPrice.value = opt.additional_price || 0;
  showAddOptionModal.value = true;
};

const confirmAddOption = () => {
  if (!newOptName.value) return;
  if (!localGroup.value.options) localGroup.value.options = [];
  
  if (editingOptionIndex.value !== null) {
    localGroup.value.options[editingOptionIndex.value].name = newOptName.value;
    localGroup.value.options[editingOptionIndex.value].additional_price = Number(newOptPrice.value) || 0;
  } else {
    localGroup.value.options.push({
      name: newOptName.value,
      additional_price: Number(newOptPrice.value) || 0,
      delivery_price: 0,
      is_active: true,
      is_default: false
    });
  }
  
  showAddOptionModal.value = false;
};

const removeOption = (index) => {
  localGroup.value.options.splice(index, 1);
};

// --- Vincular Productos Modal ---
const showAssignModal = ref(false);

const linkedProducts = computed(() => {
  if (!localGroup.value.products || localGroup.value.products.length === 0) {
    return [];
  }
  return localGroup.value.products;
});

const handleAssignSave = async (productIds) => {
  if (localGroup.value.id) {
    try {
      await apiFetch(`/option-groups/${localGroup.value.id}/attach-products`, {
        method: 'POST',
        body: JSON.stringify({ product_ids: productIds })
      });
      
      // Update local view so changes reflect immediately
      if (props.products) {
        localGroup.value.products = props.products.filter(p => productIds.includes(p.id));
      }
      
      showAssignModal.value = false;
      emit('alert', 'Productos vinculados correctamente');
      emit('update-success', localGroup.value.id);
    } catch (error) {
      emit('alert', 'Error vinculando productos: ' + (error.message || error));
    }
  } else {
    localGroup.value.product_ids = productIds;
    if (props.products) {
      localGroup.value.products = props.products.filter(p => productIds.includes(p.id));
    }
    showAssignModal.value = false;
  }
};

const save = () => emit('save', localGroup.value);
const deleteGroup = () => emit('delete', localGroup.value.id);
</script>

<template>
  <div class="drawer-overlay" @click.self="$emit('close')">
    <div class="drawer-content">
      
      <!-- HEADER DRAWER -->
      <div class="drawer-header">
        <h2 class="drawer-title">{{ localGroup.id ? 'Editar grupo de opcionales' : 'Agregar grupo de opcionales' }}</h2>
        <button class="btn-close" @click="$emit('close')">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"></path></svg>
        </button>
      </div>

      <!-- BODY DRAWER -->
      <div class="drawer-body">
        
        <!-- Nombre del grupo con Floating Label -->
        <div class="input-container-floating" style="margin-bottom: 24px;">
          <input 
            type="text" 
            class="input-floating" 
            v-model="localGroup.name" 
            placeholder=" "
            :class="{'has-error': formErrors.name}"
          />
          <label class="label-floating">Nombre del grupo de opcionales <span class="text-danger">*</span></label>
          <span v-if="formErrors.name" class="error-text">{{ formErrors.name[0] }}</span>
        </div>

        <!-- REGLAS -->
        <h3 class="section-title">Reglas</h3>
        <div class="rules-card">
          <div class="rule-row">
            <div class="rule-text">¿La selección es <strong>obligatoria</strong> para los clientes?</div>
            <label class="switch">
              <input type="checkbox" :checked="localGroup.min_selections > 0" @change="e => localGroup.min_selections = e.target.checked ? 1 : 0">
              <span class="slider round"></span>
            </label>
          </div>
          <hr class="rule-divider" />
          <div class="rule-row">
            <div class="rule-text">Número <strong>máximo</strong> de opcionales que puede seleccionar el cliente</div>
            <input type="number" min="0" v-model="localGroup.max_selections" class="input-number" />
          </div>
        </div>

        <!-- OPCIONALES (X) -->
        <div class="section-header-row" style="margin-top: 28px;">
          <h3 class="section-title" style="margin:0;">Opcionales ({{ localGroup.options?.length || 0 }})</h3>
          <button class="btn-link-action" @click="openAddOptionModal">+ Agregar</button>
        </div>

        <!-- Lista de opcionales del grupo -->
        <div class="options-list-card" v-if="localGroup.options && localGroup.options.length > 0">
          <div v-for="(opt, index) in localGroup.options" :key="index" class="option-item-row clickable" @click="openEditOptionModal(index)" title="Haz clic para editar este opcional">
            <div class="opt-info">
              <div class="opt-name">{{ opt.name }}</div>
              <div class="opt-price">+{{ Number(opt.additional_price).toFixed(2) }} BOB</div>
            </div>
            <div class="opt-actions" @click.stop>
              <button class="btn-remove-opt" @click="removeOption(index)">✕</button>
            </div>
          </div>
        </div>

        <button v-else class="btn-outline-primary" style="margin-top: 12px;" @click="openAddOptionModal">
          + Crear Opcional
        </button>

        <!-- PRODUCTOS VINCULADOS -->
        <div v-if="localGroup.id" class="section-header-row" style="margin-top: 28px;">
          <h3 class="section-title" style="margin:0;">Productos vinculados ({{ linkedProducts.length }})</h3>
          <button class="btn-link-action" @click="showAssignModal = true">Vincular productos</button>
        </div>

        <div v-if="localGroup.id && linkedProducts.length > 0" class="linked-products-card">
          <ul class="linked-bullet-list">
            <li v-for="prod in linkedProducts" :key="prod.id">• {{ prod.name }}</li>
          </ul>
        </div>

        <!-- ELIMINAR GRUPO (SI EXISTE) -->
        <div v-if="localGroup.id" style="margin-top: 36px; text-align: center;">
          <button class="btn-delete-group" @click="deleteGroup">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px;"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
            Eliminar grupo de opcionales
          </button>
        </div>

      </div>

      <!-- FOOTER DRAWER -->
      <div class="drawer-footer">
        <button class="btn-confirm-pya" :disabled="!localGroup.name" @click="save">Confirmar</button>
      </div>

    </div>

    <!-- SUB-MODAL AGREGAR/EDITAR OPCIONAL (IMAGEN 2) -->
    <div v-if="showAddOptionModal" class="submodal-overlay" @click.self="showAddOptionModal = false">
      <div class="submodal-card">
        <div class="submodal-header">
          <h3 class="submodal-title">{{ editingOptionIndex !== null ? 'Editar opcional' : 'Agregar opcional' }}</h3>
          <button class="btn-close" @click="showAddOptionModal = false">✕</button>
        </div>
        <div class="submodal-body">
          <div class="input-container-floating">
            <input type="text" class="input-floating" v-model="newOptName" placeholder=" " />
            <label class="label-floating">Nombre <span class="text-danger">*</span></label>
          </div>

          <div class="price-card-field" style="margin-top:16px;">
            <label class="price-field-label">Precio</label>
            <div class="price-input-row">
              <span class="prefix-bob">+BOB</span>
              <input type="number" min="0" step="0.5" v-model="newOptPrice" class="price-input-field" />
            </div>
          </div>
        </div>
        <div class="submodal-footer">
          <button class="btn-confirm-pya" :disabled="!newOptName" @click="confirmAddOption">Confirmar</button>
        </div>
      </div>
    </div>

    <!-- MODAL VINCULAR PRODUCTOS -->
    <AssignProductsModal
      v-if="showAssignModal"
      :optionGroup="localGroup"
      :categories="categories"
      :products="products"
      @close="showAssignModal = false"
      @save="handleAssignSave"
    />
  </div>
</template>

<style scoped>
/* Slide-over Drawer overlay -> Centered Modal */
.drawer-overlay {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0, 0, 0, 0.45);
  z-index: 1000;
  display: flex;
  justify-content: center;
  align-items: center;
  backdrop-filter: blur(2px);
}
.drawer-content {
  background: var(--surface);
  width: 100%;
  max-width: 550px;
  max-height: 90vh;
  border-radius: 16px;
  display: flex;
  flex-direction: column;
  box-shadow: 0 10px 30px rgba(0,0,0,0.15);
  overflow: hidden;
  animation: modalFadeIn 0.2s ease-out;
}

@keyframes modalFadeIn {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Header */
.drawer-header {
  padding: 24px 24px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid var(--border);
}
.drawer-title {
  margin: 0;
  font-size: 20px;
  font-weight: 800;
  color: var(--ink-900);
}
.btn-close {
  background: transparent;
  border: none;
  cursor: pointer;
  color: var(--ink-500);
  padding: 4px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
}
.btn-close:hover {
  background: var(--surface-hover);
  color: var(--ink-900);
}

/* Body */
.drawer-body {
  padding: 24px;
  overflow-y: auto;
  flex: 1;
}

/* Floating Input */
.input-container-floating {
  position: relative;
  width: 100%;
}
.input-floating {
  width: 100%;
  padding: 22px 16px 8px 16px;
  border: 1px solid var(--border);
  border-radius: 12px;
  font-size: 15px;
  background: var(--surface);
  color: var(--ink-900);
  transition: all 0.2s ease;
}
.input-floating:focus {
  outline: none;
  border-color: var(--passion-500);
  box-shadow: 0 0 0 3px rgba(234, 30, 77, 0.1);
}
.label-floating {
  position: absolute;
  top: 50%;
  left: 16px;
  transform: translateY(-50%);
  font-size: 15px;
  color: var(--ink-500);
  transition: all 0.2s ease;
  pointer-events: none;
}
.input-floating:focus + .label-floating,
.input-floating:not(:placeholder-shown) + .label-floating {
  top: 14px;
  font-size: 12px;
  color: var(--ink-400);
}
.text-danger {
  color: var(--passion-500);
}

/* Rules Card */
.section-title {
  font-size: 16px;
  font-weight: 800;
  color: var(--ink-900);
  margin: 0 0 16px;
}
.section-header-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.btn-link-action {
  background: transparent;
  border: none;
  color: var(--passion-500);
  font-weight: 700;
  font-size: 14px;
  cursor: pointer;
}
.rules-card {
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 0 16px;
}
.rule-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 0;
}
.rule-divider {
  border: 0;
  border-top: 1px solid var(--border);
  margin: 0;
}
.rule-text {
  font-size: 14px;
  color: var(--ink-900);
}

/* Switch Toggle */
.switch {
  position: relative;
  display: inline-block;
  width: 44px;
  height: 24px;
}
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
  position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
  background-color: var(--border); transition: .3s;
}
.slider:before {
  position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px;
  background-color: #fff; transition: .3s;
}
input:checked + .slider { background-color: var(--passion-500); }
input:checked + .slider:before { transform: translateX(20px); }
.slider.round { border-radius: 24px; }
.slider.round:before { border-radius: 50%; }

.input-number {
  width: 60px; text-align: center; padding: 6px; border: 1px solid var(--border);
  border-radius: 8px; font-size: 14px; outline: none;
  background-color: var(--surface);
  color: var(--ink-900);
}

/* Opcionales Card List */
.options-list-card {
  margin-top: 12px;
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
}
.option-item-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 16px;
  border-bottom: 1px solid var(--border);
}
.option-item-row.clickable {
  cursor: pointer;
  transition: background-color 0.15s ease;
}
.option-item-row.clickable:hover {
  background-color: var(--surface-hover);
}
.option-item-row:last-child { border-bottom: none; }
.opt-name { font-weight: 700; font-size: 14.5px; color: var(--ink-900); }
.opt-price { font-size: 12.5px; color: var(--ink-500); margin-top: 2px; }
.btn-remove-opt { background: none; border: none; color: var(--ink-400); cursor: pointer; font-size: 14px; }
.btn-remove-opt:hover { color: var(--danger-600); }

/* Linked Products */
.linked-products-card {
  margin-top: 12px;
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 14px 16px;
}
.linked-bullet-list {
  margin: 0; padding: 0; list-style: none; font-size: 14px; color: var(--ink-800);
}
.linked-bullet-list li { margin-bottom: 4px; }

/* Buttons */
.btn-outline-primary {
  display: flex; align-items: center; justify-content: center; width: 100%; padding: 12px;
  border: 1px solid var(--passion-500); background: transparent; color: var(--passion-500);
  font-weight: 700; font-size: 15px; border-radius: 8px; cursor: pointer; transition: 0.2s;
}
.btn-outline-primary:hover { background: var(--surface-hover); }

.btn-delete-group {
  display: inline-flex; align-items: center; justify-content: center;
  background: transparent; border: 1px solid var(--border); color: var(--passion-500);
  padding: 10px 20px; border-radius: 24px; font-weight: 700; font-size: 14px; cursor: pointer;
}
.btn-delete-group:hover { background: var(--surface-hover); }

/* Footer */
.drawer-footer {
  padding: 16px 24px;
  border-top: 1px solid var(--border);
  background: var(--surface);
}
.btn-confirm-pya {
  width: 100%; padding: 14px; background: var(--passion-500); color: white;
  border: none; border-radius: 8px; font-size: 16px; font-weight: 700; cursor: pointer; transition: 0.2s;
}
.btn-confirm-pya:disabled { background: var(--ink-200); color: var(--ink-400); cursor: not-allowed; }

/* Sub-modal */
.submodal-overlay {
  position: fixed; top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.5); z-index: 1200; display: flex; align-items: center; justify-content: center;
}
.submodal-card {
  background: var(--surface); width: 90%; max-width: 440px; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.25);
}
.submodal-header { padding: 20px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); }
.submodal-title { margin: 0; font-size: 18px; font-weight: 800; color: var(--ink-900); }
.submodal-body { padding: 20px 24px; }
.submodal-footer { padding: 16px 24px; border-top: 1px solid var(--border); }

/* Price Field */
.price-card-field { border: 1px solid var(--border); border-radius: 12px; padding: 10px 16px; }
.price-card-field:focus-within { border-color: var(--passion-500); }
.price-field-label { font-size: 12px; color: var(--ink-500); }
.price-input-row { display: flex; align-items: center; gap: 6px; }
.prefix-bob { font-size: 15px; font-weight: 600; color: var(--ink-700); }
.price-input-field { border: none; outline: none; font-size: 16px; font-weight: 700; width: 100%; background: transparent; color: var(--ink-900); }
</style>
