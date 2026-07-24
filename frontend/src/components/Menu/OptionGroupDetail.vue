<script setup>
import { ref, computed } from 'vue';
import { apiFetch } from '../../services/api';
import AssignProductsModal from './AssignProductsModal.vue';

const props = defineProps({
  group: { type: Object, required: true },
  ingredients: { type: Array, default: () => [] },
  products: { type: Array, default: () => [] },
  categories: { type: Array, default: () => [] }
});

const emit = defineEmits(['edit-group', 'update-group', 'alert', 'confirm']);

// Modal states
const showAssignModal = ref(false);
const showAddOptionModal = ref(false);

const newOptionName = ref('');
const newOptionPrice = ref(0);
const isSubmittingOption = ref(false);

// Computed linked products list
const linkedProducts = computed(() => {
  if (props.group && props.group.products && props.group.products.length > 0) {
    return props.group.products;
  }
  return [];
});

const linkedProductsText = computed(() => {
  if (linkedProducts.value.length === 0) return 'Sin productos vinculados.';
  return linkedProducts.value.map(p => p.name).join(', ') + '.';
});

// Format currency
const formatPrice = (val) => {
  const num = Number(val) || 0;
  return `+${num.toFixed(2).replace('.', ',')} BOB`;
};

// Toggle option active status directly from list
const toggleOptionActive = async (opt) => {
  try {
    const updated = {
      ...opt,
      is_active: !opt.is_active
    };
    await apiFetch(`/options/${opt.id}`, {
      method: 'PUT',
      body: JSON.stringify(updated)
    });
    emit('update-group', props.group.id);
  } catch (error) {
    emit('alert', 'Error al actualizar el estado de la opción: ' + (error.message || error));
  }
};

// Handle Assign Products Save
const handleAssignSave = async (productIds) => {
  try {
    await apiFetch(`/option-groups/${props.group.id}/attach-products`, {
      method: 'POST',
      body: JSON.stringify({ product_ids: productIds })
    });
    showAssignModal.value = false;
    emit('alert', 'Productos vinculados correctamente');
    emit('update-group', props.group.id);
  } catch (error) {
    emit('alert', 'Error vinculando productos: ' + (error.message || error));
  }
};

// Handle Create Quick Option
const openAddOptionModal = () => {
  newOptionName.value = '';
  newOptionPrice.value = 0;
  showAddOptionModal.value = true;
};

const confirmAddOption = async () => {
  if (!newOptionName.value.trim()) return;
  isSubmittingOption.value = true;
  try {
    const newOpt = {
      name: newOptionName.value.trim(),
      additional_price: Number(newOptionPrice.value) || 0,
      delivery_price: Number(newOptionPrice.value) || 0,
      is_active: true,
      is_default: false,
      option_group_id: props.group.id
    };
    await apiFetch('/options', {
      method: 'POST',
      body: JSON.stringify(newOpt)
    });
    showAddOptionModal.value = false;
    emit('update-group', props.group.id);
  } catch (error) {
    emit('alert', 'Error al crear opción: ' + (error.message || error));
  } finally {
    isSubmittingOption.value = false;
  }
};
</script>

<template>
  <div class="og-detail-card">
    
    <!-- Top section -->
    <div class="card-section top-section">
      <div class="top-left">
        <h2 class="og-title">{{ group.name }}</h2>
        <button class="btn-edit-pencil" @click="emit('edit-group', group)" title="Editar reglas del grupo">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 20h9"></path>
            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
          </svg>
        </button>
      </div>

      <div class="top-right">
        <button class="btn-add-option-pya" @click="openAddOptionModal">
          <span class="plus-sign">+</span> Crear Opcional
        </button>
      </div>
    </div>

    <!-- Middle section -->
    <div class="card-section middle-section">
      <div class="middle-left">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="link-icon">
          <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
          <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
        </svg>
        <span class="linked-text">{{ linkedProducts.length === 0 ? 'Aún no está vinculado a ningún producto.' : linkedProductsText }}</span>
      </div>
      <div class="middle-right">
        <button class="btn-link-products" @click="showAssignModal = true">
          Vincular productos
        </button>
      </div>
    </div>

    <!-- Bottom section (Options list) -->
    <div class="card-section options-section">
      <div 
        v-for="opt in group.options" 
        :key="opt.id"
        class="option-row-card"
        :class="{ inactive: !opt.is_active }"
      >
        <div class="opt-name">{{ opt.name }}</div>

        <div class="opt-right-controls">
          <label class="toggle-switch">
            <input 
              type="checkbox" 
              :checked="opt.is_active" 
              @change="toggleOptionActive(opt)" 
            />
            <span class="slider round"></span>
          </label>
          <div class="opt-price-below" v-if="opt.additional_price > 0">{{ formatPrice(opt.additional_price) }}</div>
        </div>
      </div>

      <div v-if="!group.options || group.options.length === 0" class="empty-options">
        No hay opciones registradas.
      </div>
    </div>

    <!-- MODAL VINCULAR PRODUCTOS -->
    <AssignProductsModal
      v-if="showAssignModal"
      :optionGroup="group"
      :categories="categories"
      :products="products"
      @close="showAssignModal = false"
      @save="handleAssignSave"
    />

    <!-- MODAL QUICK CREAR OPCIONAL -->
    <div v-if="showAddOptionModal" class="modal-backdrop" @click.self="showAddOptionModal = false">
      <div class="modal-content-sm">
        <div class="modal-header-sm">
          <h3>Crear opcional</h3>
          <button class="btn-close-sm" @click="showAddOptionModal = false">&times;</button>
        </div>

        <div class="modal-body-sm">
          <div class="input-group">
            <label>Nombre de la opción <span class="required">*</span></label>
            <input type="text" v-model="newOptionName" placeholder="Ej: Mediano, Extra queso" class="form-control" autofocus />
          </div>

          <div class="input-group">
            <label>Precio adicional (BOB)</label>
            <input type="number" step="0.50" min="0" v-model="newOptionPrice" placeholder="0.00" class="form-control" />
          </div>
        </div>

        <div class="modal-footer-sm">
          <button class="btn-cancel" @click="showAddOptionModal = false">Cancelar</button>
          <button class="btn-save" :disabled="!newOptionName.trim() || isSubmittingOption" @click="confirmAddOption">
            {{ isSubmittingOption ? 'Guardando...' : 'Crear' }}
          </button>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
.og-detail-card {
  background: white;
  border: 1px solid var(--border);
  border-radius: 16px;
  overflow: hidden;
  box-shadow: var(--shadow-card);
}

.card-section {
  padding: 16px 24px;
}

/* TOP SECTION */
.top-section {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.top-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.og-title {
  font-size: 24px;
  font-weight: 800;
  color: var(--ink-900);
  margin: 0;
}

.btn-edit-pencil {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: 1px solid var(--border);
  background: white;
  color: var(--ink-700);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-edit-pencil:hover {
  background: var(--cream-50);
}

.top-right {
  display: flex;
  align-items: center;
}

.btn-add-option-pya {
  background: white;
  border: 1.5px solid var(--passion-500);
  color: var(--passion-500);
  font-size: 14px;
  font-weight: 700;
  padding: 8px 18px;
  border-radius: 999px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 6px;
  transition: all 0.2s ease;
}

.btn-add-option-pya:hover {
  background: var(--passion-50);
}

.plus-sign {
  font-size: 18px;
  line-height: 1;
}

/* MIDDLE SECTION */
.middle-section {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-top: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
  background: white;
}

.middle-left {
  display: flex;
  align-items: center;
  gap: 12px;
  color: var(--ink-500);
  font-size: 14px;
}

.link-icon {
  color: var(--ink-400);
}

.linked-text {
  font-weight: 500;
}

.middle-right {
  display: flex;
  align-items: center;
}

.btn-link-products {
  background: transparent;
  border: none;
  color: var(--passion-500);
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  padding: 0;
}

.btn-link-products:hover {
  text-decoration: underline;
}

/* OPTIONS SECTION */
.options-section {
  display: flex;
  flex-direction: column;
}

.option-row-card {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding: 16px 0;
  border-bottom: 1px solid var(--border);
}

.option-row-card:last-child {
  border-bottom: none;
}

.option-row-card.inactive {
  opacity: 0.65;
}

.opt-name {
  font-size: 16px;
  font-weight: 600;
  color: var(--ink-900);
  padding-top: 4px;
}

.opt-right-controls {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8px;
}

.opt-price-below {
  font-size: 14px;
  color: var(--ink-500);
  font-weight: 500;
}

/* TOGGLE SWITCH */
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
  background-color: #ccc;
  transition: .3s;
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
}

input:checked + .slider {
  background-color: #10b981; /* Green active color */
}

input:focus + .slider {
  box-shadow: 0 0 1px #10b981;
}

input:checked + .slider:before {
  transform: translateX(20px);
}

.slider.round {
  border-radius: 24px;
}

.slider.round:before {
  border-radius: 50%;
}

.empty-options {
  padding: 24px;
  text-align: center;
  color: var(--ink-400);
  font-size: 14px;
}

/* MODAL SMALL (QUICK ADD OPTION) */
.modal-backdrop {
  position: fixed;
  top: 0; left: 0; width: 100vw; height: 100vh;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content-sm {
  background: var(--surface);
  border-radius: 16px;
  width: 90%;
  max-width: 420px;
  padding: 24px;
  box-shadow: var(--shadow-pop);
}

.modal-header-sm {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.modal-header-sm h3 {
  margin: 0;
  font-size: 20px;
  font-weight: 800;
  color: var(--ink-900);
}

.btn-close-sm {
  background: none;
  border: none;
  font-size: 24px;
  color: var(--ink-400);
  cursor: pointer;
}

.modal-body-sm {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.input-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.input-group label {
  font-size: 13.5px;
  font-weight: 700;
  color: var(--ink-700);
}

.required {
  color: var(--danger-500);
}

.form-control {
  padding: 10px 14px;
  border: 1px solid var(--border);
  border-radius: 10px;
  font-size: 14px;
  outline: none;
  transition: border-color 0.2s;
}

.form-control:focus {
  border-color: var(--passion-500);
}

.modal-footer-sm {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 24px;
}

.btn-cancel {
  background: var(--cream-100);
  border: 1px solid var(--border);
  padding: 10px 18px;
  border-radius: 10px;
  font-weight: 700;
  color: var(--ink-700);
  cursor: pointer;
}

.btn-save {
  background: var(--passion-500);
  border: none;
  padding: 10px 20px;
  border-radius: 10px;
  font-weight: 700;
  color: #fff;
  cursor: pointer;
}

.btn-save:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
