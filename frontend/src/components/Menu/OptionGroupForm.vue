<script setup>
import { ref, watch } from 'vue';
import { apiFetch } from '../../services/api';
import AssignProductsModal from './AssignProductsModal.vue';

const props = defineProps({
  optionGroup: { type: Object, required: true },
  ingredients: { type: Array, required: true },
  categories: { type: Array, default: () => [] },
  products: { type: Array, default: () => [] },
  formErrors: { type: Object, default: () => ({}) }
});

const emit = defineEmits(['save', 'delete', 'update-success', 'alert', 'confirm']);

const localGroup = ref(JSON.parse(JSON.stringify(props.optionGroup)));

watch(() => props.optionGroup, (newVal) => {
  localGroup.value = JSON.parse(JSON.stringify(newVal));
}, { deep: true });

// --- Options state ---
const newOptionName = ref('');
const newOptionPrice = ref(0);
const newOptionDeliveryPrice = ref(0);
const expandedOptionId = ref(null);
const newRecipeIngredientId = ref(null);
const newRecipeQuantity = ref(0);

const showAssignModal = ref(false);

const save = () => emit('save', localGroup.value);
const deleteGroup = () => emit('delete', localGroup.value.id);

const handleAssignSave = async (productIds) => {
  try {
    await apiFetch(`/option-groups/${localGroup.value.id}/attach-products`, {
      method: 'POST',
      body: JSON.stringify({ product_ids: productIds })
    });
    showAssignModal.value = false;
    emit('alert', 'Productos vinculados correctamente');
    emit('update-success', localGroup.value.id);
  } catch (error) {
    emit('alert', 'Error vinculando productos: ' + (error.message || error));
  }
};

// --- Options Methods ---
const addOption = async () => {
  if (!localGroup.value.id) return emit('alert', 'Guarda el grupo primero antes de añadir opciones.');
  if (!newOptionName.value) return;
  try {
    await apiFetch('/options', {
      method: 'POST',
      body: JSON.stringify({
        option_group_id: localGroup.value.id,
        name: newOptionName.value,
        additional_price: newOptionPrice.value,
        delivery_price: newOptionDeliveryPrice.value,
        is_active: true,
        is_default: false,
        sort_order: localGroup.value.options ? localGroup.value.options.length : 0
      })
    });
    newOptionName.value = '';
    newOptionPrice.value = 0;
    newOptionDeliveryPrice.value = 0;
    emit('update-success', localGroup.value.id);
  } catch (error) {
    emit('alert', 'Error añadiendo opción: ' + (error.message || error));
  }
};

const deleteOption = (id) => {
  emit('confirm', '¿Seguro que deseas eliminar esta opción?', async () => {
    try {
      await apiFetch(`/options/${id}`, { method: 'DELETE' });
      emit('update-success', localGroup.value.id);
    } catch (error) {
      emit('alert', 'Error eliminando opción: ' + (error.message || error));
    }
  });
};

const updateOption = async (opt) => {
  try {
    await apiFetch(`/options/${opt.id}`, { method: 'PUT', body: JSON.stringify(opt) });
    emit('update-success', localGroup.value.id);
  } catch (error) {
    emit('alert', 'Error actualizando opción: ' + (error.message || error));
  }
};

const toggleOptionActive = (opt) => {
  opt.is_active = !opt.is_active;
  updateOption(opt);
};

const setOptionDefault = (opt) => {
  localGroup.value.options.forEach(o => {
    if(o.id !== opt.id && o.is_default) {
      o.is_default = false;
      updateOption(o);
    }
  });
  opt.is_default = true;
  updateOption(opt);
};

const moveOptionUp = async (index) => {
  if (index === 0) return;
  const current = localGroup.value.options[index];
  const previous = localGroup.value.options[index - 1];
  const currentSort = current.sort_order || index;
  const prevSort = previous.sort_order || (index - 1);
  current.sort_order = prevSort;
  previous.sort_order = currentSort;
  await apiFetch(`/options/${current.id}`, { method: 'PUT', body: JSON.stringify(current) });
  await apiFetch(`/options/${previous.id}`, { method: 'PUT', body: JSON.stringify(previous) });
  emit('update-success', localGroup.value.id);
};

const moveOptionDown = async (index) => {
  if (index === localGroup.value.options.length - 1) return;
  const current = localGroup.value.options[index];
  const next = localGroup.value.options[index + 1];
  const currentSort = current.sort_order || index;
  const nextSort = next.sort_order || (index + 1);
  current.sort_order = nextSort;
  next.sort_order = currentSort;
  await apiFetch(`/options/${current.id}`, { method: 'PUT', body: JSON.stringify(current) });
  await apiFetch(`/options/${next.id}`, { method: 'PUT', body: JSON.stringify(next) });
  emit('update-success', localGroup.value.id);
};

const addOptionRecipe = async (optionId) => {
  if (!newRecipeIngredientId.value) return emit('alert', 'Selecciona un insumo.');
  if (newRecipeQuantity.value === 0) return emit('alert', 'La cantidad no puede ser 0.');
  try {
    await apiFetch('/option-recipes', {
      method: 'POST',
      body: JSON.stringify({
        option_id: optionId,
        ingredient_id: newRecipeIngredientId.value,
        quantity_delta: newRecipeQuantity.value
      })
    });
    newRecipeIngredientId.value = null;
    newRecipeQuantity.value = 0;
    emit('update-success', localGroup.value.id);
  } catch (error) {
    emit('alert', 'Error añadiendo receta: ' + (error.message || error));
  }
};

const deleteOptionRecipe = (recipeId, optionGroupId) => {
  emit('confirm', '¿Eliminar este insumo de la receta?', async () => {
    try {
      await apiFetch(`/option-recipes/${recipeId}`, { method: 'DELETE' });
      emit('update-success', optionGroupId);
    } catch (error) {
      emit('alert', 'Error eliminando receta: ' + (error.message || error));
    }
  });
};
</script>

<template>
  <div class="option-group-form">
    <div class="main-header">
      <h2 style="margin:0;color:var(--ink-900);">{{ localGroup.name || 'Nuevo Grupo' }}</h2>
      <button 
        v-if="localGroup.id" 
        class="btn-secondary-sm" 
        style="padding: 8px 16px; border-radius: 8px;"
        @click="showAssignModal = true">
        Vincular Productos
      </button>
    </div>

    <div class="form-grid">
      <div class="full">
        <label>Nombre del Grupo</label>
        <input v-model="localGroup.name" placeholder="Ej. Elige tu Tamaño" :class="{'has-error': formErrors.name}">
        <span v-if="formErrors.name" class="error-text">{{ formErrors.name[0] }}</span>
      </div>
      <div>
        <label>Selección Mínima</label>
        <input type="number" min="0" v-model="localGroup.min_selections" placeholder="Ej. 1 (Obligatorio)" :class="{'has-error': formErrors.min_selections}">
        <span v-if="formErrors.min_selections" class="error-text">{{ formErrors.min_selections[0] }}</span>
      </div>
      <div>
        <label>Selección Máxima</label>
        <input type="number" min="1" v-model="localGroup.max_selections" placeholder="Ej. 1 (Excluyente) o 5" :class="{'has-error': formErrors.max_selections}">
        <span v-if="formErrors.max_selections" class="error-text">{{ formErrors.max_selections[0] }}</span>
      </div>
      <div style="display:flex;align-items:center;padding-top:24px;gap:8px;">
        <input type="checkbox" v-model="localGroup.is_active" id="og-active" style="width:auto;margin:0;">
        <label for="og-active" style="margin:0;">Grupo Activo (Visible)</label>
      </div>
    </div>

    <div style="margin-top:16px;display:flex;gap:10px;">
      <button class="btn btn-primary" @click="save">Guardar Grupo</button>
      <button v-if="localGroup.id" class="btn btn-danger-outline" @click="deleteGroup">Eliminar Grupo</button>
    </div>

    <hr style="margin: 30px 0; border: 0; border-top: 1px dashed var(--border);">

    <h3 style="font-size:15px;color:var(--ink-900);">Opciones del Grupo</h3>
    <p v-if="!localGroup.id" style="font-size:13px;color:var(--danger-500);">Debes guardar el grupo primero para añadir opciones.</p>
    
    <div v-else>
      <table class="recipe-table">
        <thead>
          <tr>
            <th>Opción</th>
            <th>Precio (+Bs)</th>
            <th>Delivery (+Bs)</th>
            <th style="text-align:center">Estado / Por Defecto</th>
            <th style="text-align:center">Receta</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="(opt, index) in localGroup.options" :key="opt.id">
            <tr class="opt-row" :class="{ 'opt-row--expanded': expandedOptionId === opt.id }">
              <td style="font-weight:700;">{{ opt.name }}</td>
              <td>+Bs {{ Number(opt.additional_price).toFixed(2) }}</td>
              <td>
                <input type="number" step="0.5" v-model="opt.delivery_price" @change="updateOption(opt)" style="width: 70px; padding: 4px; font-size: 12px; border-radius: 4px; border: 1px solid var(--border);" placeholder="Opcional">
              </td>
              <td style="text-align:center;">
                <input type="checkbox" :checked="opt.is_active" @change="toggleOptionActive(opt)" title="Activar/Desactivar" style="width:auto;margin:0;">
                <input type="radio" :name="'default_' + localGroup.id" :checked="opt.is_default" @change="setOptionDefault(opt)" title="Marcar por defecto" style="margin-left:12px;width:auto;">
              </td>
              <td style="text-align:center;">
                <span v-if="opt.recipes && opt.recipes.length > 0" class="recipe-badge">{{ opt.recipes.length }} insumo(s)</span>
                <span v-else class="recipe-badge recipe-badge--empty">Sin receta</span>
                <button class="btn-icon-sm" :title="expandedOptionId === opt.id ? 'Cerrar receta' : 'Configurar receta de inventario'" @click="expandedOptionId = (expandedOptionId === opt.id ? null : opt.id); newRecipeIngredientId = null; newRecipeQuantity = 0;">
                  {{ expandedOptionId === opt.id ? '▲' : '⚙️' }}
                </button>
              </td>
              <td style="text-align:right;">
                <button class="btn-icon-sm" @click="moveOptionUp(index)" :disabled="index === 0" title="Mover Arriba">↑</button>
                <button class="btn-icon-sm" @click="moveOptionDown(index)" :disabled="index === localGroup.options.length - 1" title="Mover Abajo">↓</button>
                <span style="cursor:pointer;margin-left:8px;" @click="deleteOption(opt.id)" title="Eliminar">🗑️</span>
              </td>
            </tr>

            <tr v-if="expandedOptionId === opt.id" class="recipe-expand-row">
              <td colspan="5" style="padding:0;">
                <div class="recipe-expand-body">
                  <p class="recipe-hint">
                    💡 Configura cuánto insumo se descuenta al elegir esta opción.<br>
                    Usa cantidades <strong>negativas</strong> para opciones tipo <em>"Sin Granola"</em> (devuelve ese insumo de la receta base).
                  </p>
                  <div v-if="opt.recipes && opt.recipes.length > 0" class="recipe-list">
                    <div v-for="r in opt.recipes" :key="r.id" class="recipe-row">
                      <span class="recipe-ingredient">{{ r.ingredient?.name }}</span>
                      <span :class="[Number(r.quantity_delta) < 0 ? 'qty-negative' : 'qty-positive']">
                        {{ Number(r.quantity_delta) > 0 ? '+' : '' }}{{ r.quantity_delta }} {{ r.ingredient?.unit }}
                      </span>
                      <button class="btn-del-recipe" @click="deleteOptionRecipe(r.id, localGroup.id)">✕</button>
                    </div>
                  </div>
                  <p v-else style="color:var(--ink-400);font-size:12px;margin:0 0 10px;">Sin insumos configurados.</p>

                  <div class="recipe-add-row">
                    <select v-model="newRecipeIngredientId" class="recipe-select">
                      <option :value="null" disabled>Seleccionar insumo...</option>
                      <option v-for="ing in ingredients" :key="ing.id" :value="ing.id">{{ ing.name }} ({{ ing.unit }})</option>
                    </select>
                    <input type="number" step="0.01" v-model="newRecipeQuantity" placeholder="Cantidad (+/-)" class="recipe-qty-input" :class="{ 'input-negative': newRecipeQuantity < 0 }">
                    <button class="btn btn-ghost" @click="addOptionRecipe(opt.id)">+ Añadir</button>
                  </div>
                </div>
              </td>
            </tr>
          </template>
          <tr v-if="!localGroup.options?.length">
            <td colspan="5" style="text-align:center; color:var(--ink-500);">No hay opciones agregadas.</td>
          </tr>
        </tbody>
      </table>

      <div class="add-option-row">
        <input v-model="newOptionName" placeholder="Nombre (Ej. Junior)" style="flex:2">
        <input type="number" step="0.5" v-model="newOptionPrice" placeholder="Precio (+Bs)" style="flex:1">
        <input type="number" step="0.5" v-model="newOptionDeliveryPrice" placeholder="Delivery (+Bs)" style="flex:1">
        <button class="btn btn-ghost" @click="addOption" style="flex:1">+ Añadir</button>
      </div>
    </div>

    <!-- Modal Vincular Productos -->
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
.main-header { display: flex; justify-content: space-between; align-items: center; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 18px; }
.form-grid .full { grid-column: 1 / -1; }
.form-grid label { display: block; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-500); margin-bottom: 6px; }
.form-grid input, .form-grid select { width: 100%; padding: 11px 13px; border-radius: 10px; border: 2px solid var(--border); font-size: 14px; background: var(--surface-alt); font-family: var(--font-sans); color: var(--ink-900); }
.form-grid input:focus, .form-grid select:focus { outline: none; border-color: var(--passion-500); }
.recipe-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
.recipe-table th { text-align: left; font-size: 11px; text-transform: uppercase; color: var(--ink-500); padding: 8px 10px; border-bottom: 2px solid var(--border); }
.recipe-table td { padding: 10px; border-bottom: 1px solid var(--border); font-size: 13.5px; color: var(--ink-900); }
.add-option-row { display: flex; gap: 10px; margin-top: 12px; }
.add-option-row input { padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); font-family: inherit; }
.btn-primary { background: var(--passion-500); color: white; border: none; border-radius: var(--radius-md); padding: 12px 20px; font-weight: 700; font-size: 14px; cursor: pointer; }
.btn-primary:hover { background: var(--passion-600); }
.btn-danger-outline { background: transparent; color: var(--danger-600); border: 2px solid var(--danger-600); border-radius: var(--radius-md); padding: 12px 20px; font-weight: 700; font-size: 14px; cursor: pointer; }
.btn-danger-outline:hover { background: var(--danger-50); }
.btn-ghost { cursor: pointer; background: transparent; border: none; font-weight: 700; color: var(--ink-700); padding: 8px 16px; border-radius: 8px; transition: background 0.2s; }
.btn-ghost:hover { background: var(--cream-100); }
.opt-row { transition: background 0.15s; }
.opt-row--expanded { background: var(--acai-50); }
.recipe-badge { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: var(--lime-100); color: var(--lime-700); margin-right: 6px; }
.recipe-badge--empty { background: var(--cream-200); color: var(--ink-400); }
.btn-icon-sm { background: none; border: none; cursor: pointer; font-size: 14px; padding: 2px 6px; border-radius: 6px; transition: background 0.15s; }
.btn-icon-sm:hover { background: var(--cream-200); }
.recipe-expand-row td { padding: 0 !important; border-bottom: 2px solid var(--acai-200) !important; }
.recipe-expand-body { background: var(--acai-50); padding: 16px 20px; border-left: 4px solid var(--acai-400); }
.recipe-hint { font-size: 12px; color: var(--ink-600); margin: 0 0 14px; line-height: 1.6; }
.recipe-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.recipe-row { display: flex; align-items: center; gap: 10px; background: var(--surface); padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); }
.recipe-ingredient { flex: 1; font-weight: 600; color: var(--ink-900); font-size: 13px; }
.qty-positive { font-weight: 700; color: var(--lime-700); font-size: 13px; }
.qty-negative { font-weight: 700; color: var(--passion-600); font-size: 13px; }
.btn-del-recipe { background: none; border: none; cursor: pointer; color: var(--ink-400); font-size: 13px; padding: 2px 6px; border-radius: 4px; }
.btn-del-recipe:hover { background: var(--danger-50); color: var(--danger-600); }
.recipe-add-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.recipe-select { flex: 2; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); font-family: inherit; font-size: 13px; background: var(--surface-alt); }
.recipe-qty-input { flex: 1; max-width: 140px; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); font-family: inherit; font-size: 13px; }
.recipe-qty-input.input-negative { border-color: var(--passion-400); color: var(--passion-700); background: var(--passion-50); }
.has-error { border-color: var(--danger-600) !important; background-color: #fffafb; }
.error-text { color: var(--danger-600); font-size: 11px; margin-top: 4px; display: block; }
.btn-secondary-sm {
  background: var(--acai-100);
  border: none;
  color: var(--acai-700);
  font-weight: 700;
  cursor: pointer;
  transition: 0.15s;
}
.btn-secondary-sm:hover {
  background: var(--acai-200);
}
</style>
