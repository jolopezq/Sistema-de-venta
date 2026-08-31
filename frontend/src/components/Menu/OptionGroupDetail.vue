<script setup>
import { ref, computed, watch } from 'vue';
import { apiFetch } from '../../services/api';
import AssignProductsModal from './AssignProductsModal.vue';

const props = defineProps({
  group: { type: Object, required: true },
  ingredients: { type: Array, default: () => [] },
  products: { type: Array, default: () => [] },
  categories: { type: Array, default: () => [] }
});

const emit = defineEmits(['edit-group', 'update-group', 'alert', 'confirm']);

// Local reactive options copy for instant drag reordering
const localOptions = ref([]);

watch(
  () => props.group?.options,
  (newOptions) => {
    localOptions.value = newOptions ? [...newOptions] : [];
  },
  { immediate: true, deep: true }
);

// Drag & Drop State
const draggedIndex = ref(null);
const dragOverIndex = ref(null);
const isSavingOrder = ref(false);
let hasMoved = false;

const onDragStart = (e, index) => {
  draggedIndex.value = index;
  hasMoved = false;
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', index);
};

const onDragOver = (e, index) => {
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';
  if (dragOverIndex.value !== index) {
    dragOverIndex.value = index;
  }
};

const onDragLeave = (e, index) => {
  if (dragOverIndex.value === index) {
    dragOverIndex.value = null;
  }
};

const onDragEnd = () => {
  draggedIndex.value = null;
  dragOverIndex.value = null;
};

const onDrop = async (e, targetIndex) => {
  e.preventDefault();
  const sourceIndex = draggedIndex.value;
  
  if (sourceIndex === null || sourceIndex === undefined || sourceIndex === targetIndex) {
    draggedIndex.value = null;
    dragOverIndex.value = null;
    return;
  }

  hasMoved = true;

  // Reorder locally
  const reordered = [...localOptions.value];
  const [movedItem] = reordered.splice(sourceIndex, 1);
  reordered.splice(targetIndex, 0, movedItem);
  localOptions.value = reordered;

  draggedIndex.value = null;
  dragOverIndex.value = null;

  // Persist order in backend
  try {
    isSavingOrder.value = true;
    const optionIds = localOptions.value.map(o => o.id);
    await apiFetch(`/option-groups/${props.group.id}/reorder-options`, {
      method: 'POST',
      body: JSON.stringify({ option_ids: optionIds })
    });
    emit('update-group', props.group.id);
  } catch (error) {
    emit('alert', 'Error al guardar el nuevo orden: ' + (error.message || error));
    // Rollback
    localOptions.value = props.group.options ? [...props.group.options] : [];
  } finally {
    isSavingOrder.value = false;
  }
};

// Modal states
const showAssignModal = ref(false);
const showOptionModal = ref(false);
const editingOption = ref(null);

const optionForm = ref({
  name: '',
  additional_price: 0,
  delivery_price: 0,
  is_active: true
});
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

// Open modal to create option
const openAddOptionModal = () => {
  editingOption.value = null;
  optionForm.value = {
    name: '',
    additional_price: 0,
    delivery_price: 0,
    is_active: true
  };
  showOptionModal.value = true;
};

// Open modal to edit selected option
const openEditOptionModal = (opt) => {
  if (hasMoved) {
    hasMoved = false;
    return;
  }
  editingOption.value = opt;
  optionForm.value = {
    name: opt.name,
    additional_price: Number(opt.additional_price) || 0,
    delivery_price: Number(opt.delivery_price) || 0,
    is_active: opt.is_active ?? true
  };
  showOptionModal.value = true;
};

// Save Option (Create or Update)
const saveOption = async () => {
  if (!optionForm.value.name.trim()) return;
  if (Number(optionForm.value.additional_price) < 0 || Number(optionForm.value.delivery_price) < 0) {
    emit('alert', 'El precio no puede ser negativo.');
    return;
  }
  isSubmittingOption.value = true;
  try {
    const payload = {
      name: optionForm.value.name.trim(),
      additional_price: Number(optionForm.value.additional_price) || 0,
      delivery_price: Number(optionForm.value.delivery_price) || 0,
      is_active: optionForm.value.is_active,
      is_default: editingOption.value ? (editingOption.value.is_default || false) : false,
      option_group_id: props.group.id
    };
    if (editingOption.value) {
      await apiFetch(`/options/${editingOption.value.id}`, {
        method: 'PUT',
        body: JSON.stringify(payload)
      });
      emit('alert', 'Opción actualizada correctamente');
    } else {
      await apiFetch('/options', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      emit('alert', 'Opción creada correctamente');
    }
    showOptionModal.value = false;
    emit('update-group', props.group.id);
  } catch (error) {
    emit('alert', 'Error al guardar la opción: ' + (error.message || error));
  } finally {
    isSubmittingOption.value = false;
  }
};

// Delete option
const deleteOption = async () => {
  if (!editingOption.value) return;
  emit('confirm', `¿Seguro que deseas eliminar la opción "${editingOption.value.name}"?`, async () => {
    isSubmittingOption.value = true;
    try {
      await apiFetch(`/options/${editingOption.value.id}`, {
        method: 'DELETE'
      });
      showOptionModal.value = false;
      emit('alert', 'Opción eliminada correctamente');
      emit('update-group', props.group.id);
    } catch (error) {
      emit('alert', 'Error al eliminar la opción: ' + (error.message || error));
    } finally {
      isSubmittingOption.value = false;
    }
  });
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
        <span v-if="isSavingOrder" class="saving-order-indicator">
          Guardando orden...
        </span>
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

    <!-- Bottom section (Options list with Drag & Drop) -->
    <div class="card-section options-section">
      <div class="drag-hint" v-if="localOptions.length > 1">
        <span>💡 Arrastra y suelta las opciones con el icono ⠿ para cambiar el orden de visualización</span>
      </div>

      <div 
        v-for="(opt, index) in localOptions" 
        :key="opt.id"
        class="option-row-card"
        :class="{ 
          inactive: !opt.is_active,
          'is-dragging': draggedIndex === index,
          'is-drag-over-top': dragOverIndex === index && draggedIndex !== null && draggedIndex > index,
          'is-drag-over-bottom': dragOverIndex === index && draggedIndex !== null && draggedIndex < index
        }"
        draggable="true"
        @dragstart="onDragStart($event, index)"
        @dragover="onDragOver($event, index)"
        @dragleave="onDragLeave($event, index)"
        @dragend="onDragEnd"
        @drop="onDrop($event, index)"
      >
        <!-- Drag Handle Icon -->
        <div class="drag-handle" title="Mantén presionado para mover de posición">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
            <circle cx="9" cy="5" r="1.6"/>
            <circle cx="15" cy="5" r="1.6"/>
            <circle cx="9" cy="12" r="1.6"/>
            <circle cx="15" cy="12" r="1.6"/>
            <circle cx="9" cy="19" r="1.6"/>
            <circle cx="15" cy="19" r="1.6"/>
          </svg>
        </div>

        <div class="opt-left clickable-area" @click="openEditOptionModal(opt)" title="Haz clic para editar este opcional">
          <div class="opt-name">{{ opt.name }}</div>
        </div>

        <div class="opt-right-controls" @click.stop>
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

      <div v-if="!localOptions || localOptions.length === 0" class="empty-options">
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

    <!-- MODAL CREAR / EDITAR OPCIONAL -->
    <div v-if="showOptionModal" class="modal-backdrop" @click.self="showOptionModal = false">
      <div class="modal-content-sm">
        <div class="modal-header-sm">
          <h3>{{ editingOption ? 'Editar opcional' : 'Crear opcional' }}</h3>
          <button class="btn-close-sm" @click="showOptionModal = false">&times;</button>
        </div>

        <div class="modal-body-sm">
          <div class="input-group">
            <label>Nombre de la opción <span class="required">*</span></label>
            <input type="text" v-model="optionForm.name" placeholder="Ej: Mediano, Grande" class="form-control" autofocus />
          </div>

          <div class="input-group">
            <label>Precio adicional en local (BOB)</label>
            <input type="number" step="0.50" min="0" v-model="optionForm.additional_price" placeholder="0.00" class="form-control" />
          </div>

          <div class="input-group">
            <label>Precio adicional en delivery (BOB)</label>
            <input type="number" step="0.50" min="0" v-model="optionForm.delivery_price" placeholder="0.00" class="form-control" />
          </div>

          <div class="input-group-row" v-if="editingOption">
            <label>Disponible</label>
            <label class="toggle-switch">
              <input type="checkbox" v-model="optionForm.is_active" />
              <span class="slider round"></span>
            </label>
          </div>
        </div>

        <div class="modal-footer-sm">
          <button v-if="editingOption" class="btn-delete-opt-sub" @click="deleteOption" :disabled="isSubmittingOption">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"></polyline>
              <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
            </svg>
            Eliminar
          </button>
          <div class="footer-right-actions">
            <button class="btn-cancel" @click="showOptionModal = false">Cancelar</button>
            <button class="btn-save" :disabled="!optionForm.name.trim() || isSubmittingOption" @click="saveOption">
              {{ isSubmittingOption ? 'Guardando...' : (editingOption ? 'Guardar' : 'Crear') }}
            </button>
          </div>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
.og-detail-card {
  background: var(--surface);
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
  background: var(--surface);
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
  gap: 12px;
}

.saving-order-indicator {
  font-size: 12px;
  font-weight: 700;
  color: var(--passion-600);
  background: #fff3e0;
  padding: 4px 10px;
  border-radius: 20px;
  animation: pulse 1.5s infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

.btn-add-option-pya {
  background: var(--surface);
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
  background: var(--surface);
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
  gap: 2px;
}

.drag-hint {
  font-size: 12px;
  color: var(--ink-500);
  padding: 0 4px 10px 4px;
  font-weight: 500;
}

.option-row-card {
  display: flex;
  align-items: center;
  padding: 12px 14px;
  border-bottom: 1px solid var(--border);
  border-radius: 10px;
  transition: background-color 0.15s ease, transform 0.15s ease, border-color 0.15s ease;
  background-color: var(--surface);
  user-select: none;
  position: relative;
}

.option-row-card:last-child {
  border-bottom: none;
}

.drag-handle {
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--ink-400);
  cursor: grab;
  padding: 6px 8px 6px 2px;
  border-radius: 6px;
  transition: color 0.15s, transform 0.15s;
}

.drag-handle:hover {
  color: var(--passion-500);
  transform: scale(1.1);
}

.drag-handle:active {
  cursor: grabbing;
}

.clickable-area {
  flex: 1;
  cursor: pointer;
  padding: 6px 10px;
  border-radius: 8px;
  transition: background-color 0.15s ease;
}

.clickable-area:hover {
  background-color: var(--surface-hover);
}

.option-row-card.inactive {
  opacity: 0.65;
}

/* Estados de Drag and Drop */
.option-row-card.is-dragging {
  opacity: 0.35;
  background-color: var(--surface-hover);
  border: 2px dashed var(--passion-400);
}

.option-row-card.is-drag-over-top {
  border-top: 3px solid var(--passion-500);
  background-color: #fff8f6;
}

.option-row-card.is-drag-over-bottom {
  border-bottom: 3px solid var(--passion-500);
  background-color: #fff8f6;
}

.opt-left {
  display: flex;
  align-items: center;
  gap: 10px;
}

.opt-name {
  font-size: 15px;
  font-weight: 600;
  color: var(--ink-900);
}

.opt-right-controls {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 6px;
}

.opt-price-below {
  font-size: 13px;
  color: var(--ink-500);
  font-weight: 600;
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
  background-color: #fff;
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
  background-color: var(--surface);
  color: var(--ink-900);
}

.form-control:focus {
  border-color: var(--passion-500);
}

.input-group-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 4px 0;
}

.input-group-row label {
  font-size: 13.5px;
  font-weight: 700;
  color: var(--ink-700);
}

.modal-footer-sm {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-top: 24px;
}

.footer-right-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-left: auto;
}

.btn-delete-opt-sub {
  background: transparent;
  border: 1px solid var(--danger-300);
  color: var(--danger-500);
  padding: 10px 14px;
  border-radius: 10px;
  font-weight: 700;
  font-size: 13.5px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 6px;
  transition: all 0.2s;
}

.btn-delete-opt-sub:hover {
  background: var(--danger-50);
  border-color: var(--danger-500);
}

.btn-cancel {
  background: var(--surface-hover);
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
