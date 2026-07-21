<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { apiFetch } from '../services/api';

const ingredients = ref([]);
const categories = ref([]);
const loading = ref(true);
const error = ref(null);

const showMovementModal = ref(false);
const showIngredientModal = ref(false);
const showCategoryModal = ref(false);

const formErrors = ref({});

const selectedIngredient = ref(null);

// Filters
const filterSearch = ref('');
const filterCategoryId = ref('');

// Forms
const movementForm = ref({
  type: 'restock',
  quantity_changed: 0,
  unit_cost: 0,
  waste_category: '',
  notes: ''
});

const ingredientForm = ref({
  name: '',
  unit: 'kg',
  ingredient_category_id: '',
  type: 'perecedero',
  minimum_stock: 0,
  unit_cost: 0,
  current_stock: 0,
});

const categoryForm = ref({
  name: '',
  description: ''
});

const isEditing = ref(false);
const isEditingCategory = ref(false);
const selectedCategory = ref(null);

onMounted(async () => {
  await fetchCategories();
  await fetchIngredients();
});

// Watch filters to trigger fetch
watch([filterSearch, filterCategoryId], () => {
  fetchIngredients();
});

async function fetchCategories() {
  try {
    const res = await apiFetch('/ingredient-categories');
    categories.value = res.data || res;
  } catch (e) {
    console.error('Error fetching categories', e);
  }
}

async function fetchIngredients() {
  loading.value = true;
  try {
    const params = new URLSearchParams();
    if (filterSearch.value) params.append('search', filterSearch.value);
    if (filterCategoryId.value) params.append('category_id', filterCategoryId.value);
    
    const res = await apiFetch(`/ingredients?${params.toString()}`);
    ingredients.value = res.data || res;
  } catch (err) {
    error.value = 'Error al cargar insumos';
  } finally {
    loading.value = false;
  }
}

function openMovementModal(ing, type = 'restock') {
  selectedIngredient.value = ing;
  movementForm.value = {
    type,
    quantity_changed: 0,
    unit_cost: ing.unit_cost || 0,
    waste_category: '',
    notes: ''
  };
  showMovementModal.value = true;
}

function openIngredientModal(ing = null) {
  formErrors.value = {};
  if (ing) {
    isEditing.value = true;
    selectedIngredient.value = ing;
    ingredientForm.value = { ...ing };
  } else {
    isEditing.value = false;
    selectedIngredient.value = null;
    ingredientForm.value = {
      name: '',
      unit: 'kg',
      ingredient_category_id: '',
      type: 'perecedero',
      minimum_stock: 0,
      unit_cost: 0,
      current_stock: 0,
    };
  }
  showIngredientModal.value = true;
}

function openCategoryModal() {
  showCategoryModal.value = true;
  isEditingCategory.value = false;
  selectedCategory.value = null;
  categoryForm.value = { name: '', description: '' };
}

function editCategory(cat) {
  isEditingCategory.value = true;
  selectedCategory.value = cat;
  categoryForm.value = { ...cat };
}

async function deleteCategory(id) {
  confirmAction('¿Estás seguro de eliminar esta categoría?', async () => {
    try {
      await apiFetch(`/ingredient-categories/${id}`, {
        method: 'DELETE'
      });
      await fetchCategories();
    } catch (e) {
      alertAction(e.message || 'Error al eliminar categoría (podría tener insumos asociados).');
    }
  });
}

async function deleteIngredient(id) {
  confirmAction('¿Estás seguro de eliminar este insumo del catálogo?', async () => {
    try {
      await apiFetch(`/ingredients/${id}`, {
        method: 'DELETE'
      });
      await fetchIngredients();
    } catch (e) {
      alertAction(e.message || 'Error al eliminar insumo (verifique que no esté en recetas activas).');
    }
  });
}

// Variables para Modales de Confirmación y Alerta
const showConfirmModal = ref(false);
const confirmMessage = ref('');
const confirmCallback = ref(null);

const showAlertModal = ref(false);
const alertMessage = ref('');

function confirmAction(message, callback) {
  confirmMessage.value = message;
  confirmCallback.value = callback;
  showConfirmModal.value = true;
}

function executeConfirm() {
  if (confirmCallback.value) confirmCallback.value();
  showConfirmModal.value = false;
}

function alertAction(message) {
  alertMessage.value = message;
  showAlertModal.value = true;
}

async function saveCategory() {
  try {
    if (isEditingCategory.value) {
      await apiFetch(`/ingredient-categories/${selectedCategory.value.id}`, {
        method: 'PUT',
        body: JSON.stringify(categoryForm.value)
      });
    } else {
      await apiFetch('/ingredient-categories', {
        method: 'POST',
        body: JSON.stringify(categoryForm.value)
      });
    }
    isEditingCategory.value = false;
    selectedCategory.value = null;
    categoryForm.value = { name: '', description: '' };
    await fetchCategories();
  } catch (e) {
    if (e.validationErrors) {
      formErrors.value = e.validationErrors;
    } else {
      alertAction(e.message || 'Error al guardar categoría');
    }
  }
}

async function saveIngredient() {
  try {
    if (isEditing.value) {
      await apiFetch(`/ingredients/${selectedIngredient.value.id}`, {
        method: 'PUT',
        body: JSON.stringify(ingredientForm.value)
      });
    } else {
      await apiFetch(`/ingredients`, {
        method: 'POST',
        body: JSON.stringify(ingredientForm.value)
      });
    }
    showIngredientModal.value = false;
    await fetchIngredients();
  } catch (e) {
    if (e.validationErrors) {
      formErrors.value = e.validationErrors;
    } else {
      alertAction(e.message || 'Error al guardar insumo');
    }
  }
}

async function saveMovement() {
  try {
    let qty = parseFloat(movementForm.value.quantity_changed);
    if (movementForm.value.type === 'waste') qty = -Math.abs(qty);

    await apiFetch(`/inventory/movements`, {
      method: 'POST',
      body: JSON.stringify({
        ingredient_id: selectedIngredient.value.id,
        quantity_changed: qty,
        type: movementForm.value.type,
        unit_cost: movementForm.value.unit_cost,
        waste_category: movementForm.value.waste_category,
        notes: movementForm.value.notes
      })
    });
    showMovementModal.value = false;
    await fetchIngredients();
  } catch (e) {
    if (e.validationErrors) {
      formErrors.value = e.validationErrors;
    } else {
      alertAction(e.message || 'Error al registrar movimiento');
    }
  }
}
</script>

<template>
  <div class="inventory-layout">
    <div class="inventory-header">
      <div>
        <h2>Gestión de Inventario</h2>
        <p>Control de insumos, abastecimiento y mermas.</p>
      </div>
      <div class="header-actions">
        <button class="btn-ghost" @click="openCategoryModal()">📂 Categorías</button>
        <button class="btn-primary" @click="openIngredientModal()">+ Nuevo Insumo</button>
      </div>
    </div>

    <!-- Filtros -->
    <div class="filters-bar">
      <div class="search-box">
        <span class="search-icon">🔍</span>
        <input v-model="filterSearch" type="text" placeholder="Buscar por nombre..." />
      </div>
      <div class="filter-group">
        <select v-model="filterCategoryId">
          <option value="">Todas las categorías</option>
          <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="loading">Cargando inventario...</div>
    <div v-else-if="error" class="error">{{ error }}</div>
    <div v-else class="table-container">
      <table class="oh-table">
        <thead>
          <tr>
            <th>Insumo</th>
            <th>Categoría / Tipo</th>
            <th>Stock Actual</th>
            <th>Stock Mín.</th>
            <th>Costo Unit.</th>
            <th>CPP</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="ing in ingredients" :key="ing.id" :class="{ 'low-stock': parseFloat(ing.current_stock) <= parseFloat(ing.minimum_stock) }">
            <td class="fw-bold">
              {{ ing.name }}
              <br><small class="text-muted">Unidad: {{ ing.unit }}</small>
            </td>
            <td>
              <span class="badge cat-badge" v-if="ing.category">{{ ing.category.name }}</span>
              <span class="badge type-badge" v-if="ing.type">{{ ing.type }}</span>
            </td>
            <td class="fw-bold" :class="{'text-danger': parseFloat(ing.current_stock) <= parseFloat(ing.minimum_stock)}">
              {{ ing.current_stock }}
              <span v-if="parseFloat(ing.current_stock) <= parseFloat(ing.minimum_stock)" title="Stock bajo" style="margin-left:4px">⚠️</span>
            </td>
            <td>{{ ing.minimum_stock }}</td>
            <td>Bs {{ ing.unit_cost }}</td>
            <td>Bs {{ ing.weighted_avg_cost }}</td>
            <td class="actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
              <button class="btn-action-labeled" @click="openMovementModal(ing, 'restock')" title="Comprar / Ingresar Stock">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                Ingreso
              </button>
              <button class="btn-action-labeled text-amber" @click="openMovementModal(ing, 'waste')" title="Reportar Merma (Dañado/Vencido)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                Merma
              </button>
              <button class="btn-action-labeled text-info" @click="openMovementModal(ing, 'adjustment')" title="Ajuste Manual de Inventario">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>
                Ajuste
              </button>
              <button class="btn-action-labeled" @click="openIngredientModal(ing)" title="Editar Información del Insumo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                Editar
              </button>
              <button class="btn-action-labeled text-danger" @click="deleteIngredient(ing.id)" title="Eliminar Insumo del Catálogo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                Eliminar
              </button>
            </td>
          </tr>
          <tr v-if="ingredients.length === 0">
            <td colspan="7" class="empty-state">No se encontraron insumos.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal Categorías (CRUD) -->
    <div v-if="showCategoryModal" class="modal-backdrop">
      <div class="modal-content" style="max-width: 600px;">
        <h3>Gestión de Categorías</h3>
        
        <div class="categories-layout">
          <!-- Lista de Categorías -->
          <div class="categories-list">
            <table class="oh-table" style="margin-bottom: 16px;">
              <thead>
                <tr>
                  <th>Nombre</th>
                  <th style="width: 80px;">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="cat in categories" :key="cat.id">
                  <td>{{ cat.name }}</td>
                  <td class="actions">
                    <button class="btn-ghost" style="padding: 4px;" @click="editCategory(cat)">✏️</button>
                    <button class="btn-ghost" style="padding: 4px;" @click="deleteCategory(cat.id)">🗑️</button>
                  </td>
                </tr>
                <tr v-if="categories.length === 0">
                  <td colspan="2" class="empty-state">No hay categorías.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Formulario -->
          <div class="categories-form">
            <h4>{{ isEditingCategory ? 'Editar Categoría' : 'Nueva Categoría' }}</h4>
            <div class="form-group">
              <label>Nombre</label>
              <input v-model="categoryForm.name" type="text" :class="{'has-error': formErrors.name}" />
              <span v-if="formErrors.name" class="error-text">{{ formErrors.name[0] }}</span>
            </div>
            <div class="form-group">
              <label>Descripción</label>
              <input v-model="categoryForm.description" type="text" :class="{'has-error': formErrors.description}" />
              <span v-if="formErrors.description" class="error-text">{{ formErrors.description[0] }}</span>
            </div>
            <div style="display: flex; gap: 8px;">
              <button class="btn-primary flex-1" @click="saveCategory">{{ isEditingCategory ? 'Actualizar' : 'Crear' }}</button>
              <button v-if="isEditingCategory" class="btn-ghost" @click="openCategoryModal()">Cancelar</button>
            </div>
          </div>
        </div>

        <div class="modal-actions" style="margin-top: 16px; border-top: 1px solid var(--border); padding-top: 16px;">
          <button class="btn-ghost" @click="showCategoryModal = false">Cerrar</button>
        </div>
      </div>
    </div>

    <!-- Modal Insumo -->
    <div v-if="showIngredientModal" class="modal-backdrop">
      <div class="modal-content">
        <h3>{{ isEditing ? 'Editar Insumo' : 'Nuevo Insumo' }}</h3>
        <div class="form-row">
          <div class="form-group flex-1">
            <label>Nombre</label>
            <input v-model="ingredientForm.name" type="text" :class="{'has-error': formErrors.name}" />
            <span v-if="formErrors.name" class="error-text">{{ formErrors.name[0] }}</span>
          </div>
          <div class="form-group">
            <label>Unidad</label>
            <select v-model="ingredientForm.unit" :class="{'has-error': formErrors.unit}">
              <option value="kg">kg</option>
              <option value="litros">Litros</option>
              <option value="unidades">Unidades</option>
              <option value="sacos">Sacos</option>
            </select>
            <span v-if="formErrors.unit" class="error-text">{{ formErrors.unit[0] }}</span>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group flex-1">
            <label>Categoría</label>
            <select v-model="ingredientForm.ingredient_category_id" :class="{'has-error': formErrors.ingredient_category_id}">
              <option value="">Sin Categoría</option>
              <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
            </select>
            <span v-if="formErrors.ingredient_category_id" class="error-text">{{ formErrors.ingredient_category_id[0] }}</span>
          </div>
          <div class="form-group flex-1">
            <label>Tipo</label>
            <select v-model="ingredientForm.type" :class="{'has-error': formErrors.type}">
              <option value="perecedero">Perecedero</option>
              <option value="no_perecedero">No Perecedero</option>
              <option value="material_empaque">Empaque</option>
              <option value="otros">Otros</option>
            </select>
            <span v-if="formErrors.type" class="error-text">{{ formErrors.type[0] }}</span>
          </div>
        </div>
        <div class="form-group">
          <label>Stock Mínimo (Alertas)</label>
          <input v-model="ingredientForm.minimum_stock" type="number" step="0.01" :class="{'has-error': formErrors.minimum_stock}" />
          <span v-if="formErrors.minimum_stock" class="error-text">{{ formErrors.minimum_stock[0] }}</span>
        </div>
        <div class="form-row">
          <div class="form-group flex-1">
            <label>Stock Actual</label>
            <input v-model="ingredientForm.current_stock" type="number" step="0.01" :class="{'has-error': formErrors.current_stock}" />
            <span v-if="formErrors.current_stock" class="error-text">{{ formErrors.current_stock[0] }}</span>
          </div>
          <div class="form-group flex-1">
            <label>{{ isEditing ? 'Costo Unitario (Bs)' : 'Costo Inicial (Bs)' }}</label>
            <input v-model="ingredientForm.unit_cost" type="number" step="0.01" :class="{'has-error': formErrors.unit_cost}" />
            <span v-if="formErrors.unit_cost" class="error-text">{{ formErrors.unit_cost[0] }}</span>
          </div>
        </div>
        <div class="modal-actions">
          <button class="btn-ghost" @click="showIngredientModal = false">Cancelar</button>
          <button class="btn-primary" @click="saveIngredient">Guardar</button>
        </div>
      </div>
    </div>

    <!-- Modal Movimiento -->
    <div v-if="showMovementModal" class="modal-backdrop">
      <div class="modal-content">
        <h3>
          <span v-if="movementForm.type === 'restock'">📦 Ingreso de Compra</span>
          <span v-else-if="movementForm.type === 'waste'">🗑️ Registrar Merma</span>
          <span v-else>⚖️ Ajuste Físico</span>
        </h3>
        <p class="text-muted mb-4">{{ selectedIngredient?.name }}</p>

        <div class="form-group">
          <label>Cantidad ({{ selectedIngredient?.unit }})</label>
          <input v-model="movementForm.quantity_changed" type="number" step="0.01" :class="{'has-error': formErrors.quantity_changed}" />
          <span v-if="formErrors.quantity_changed" class="error-text">{{ formErrors.quantity_changed[0] }}</span>
        </div>

        <div v-if="movementForm.type === 'restock'" class="form-group">
          <label>Costo Unitario de Compra (Bs)</label>
          <input v-model="movementForm.unit_cost" type="number" step="0.01" :class="{'has-error': formErrors.unit_cost}" />
          <span v-if="formErrors.unit_cost" class="error-text">{{ formErrors.unit_cost[0] }}</span>
        </div>

        <div v-if="movementForm.type === 'waste'" class="form-group">
          <label>Motivo de Merma</label>
          <select v-model="movementForm.waste_category" :class="{'has-error': formErrors.waste_category}">
            <option value="expired">Vencido</option>
            <option value="damaged">Dañado / Mal Estado</option>
            <option value="spillage">Derrame / Accidente</option>
          </select>
          <span v-if="formErrors.waste_category" class="error-text">{{ formErrors.waste_category[0] }}</span>
        </div>

        <div class="form-group">
          <label>Notas (Opcional)</label>
          <textarea v-model="movementForm.notes" rows="2" :class="{'has-error': formErrors.notes}"></textarea>
          <span v-if="formErrors.notes" class="error-text">{{ formErrors.notes[0] }}</span>
        </div>

        <div class="modal-actions">
          <button class="btn-ghost" @click="showMovementModal = false">Cancelar</button>
          <button class="btn-primary" @click="saveMovement">Confirmar Movimiento</button>
        </div>
      </div>
    </div>

    <!-- Modal de Confirmación Custom -->
    <div v-if="showConfirmModal" class="modal-backdrop">
      <div class="modal-content" style="max-width: 400px; text-align: center;">
        <h3 style="color: var(--danger-600); display: flex; align-items: center; justify-content: center; gap: 8px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
          Confirmar Acción
        </h3>
        <p class="text-muted" style="margin-bottom: 24px;">{{ confirmMessage }}</p>
        <div class="modal-actions" style="justify-content: center;">
          <button class="btn-ghost" @click="showConfirmModal = false">Cancelar</button>
          <button class="btn-primary" style="background: var(--danger-600);" @click="executeConfirm">Sí, Continuar</button>
        </div>
      </div>
    </div>

    <!-- Modal de Alerta Custom -->
    <div v-if="showAlertModal" class="modal-backdrop">
      <div class="modal-content" style="max-width: 400px; text-align: center;">
        <h3 style="color: #f59e0b; display: flex; align-items: center; justify-content: center; gap: 8px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
          Atención
        </h3>
        <p class="text-muted" style="margin-bottom: 24px;">{{ alertMessage }}</p>
        <div class="modal-actions" style="justify-content: center;">
          <button class="btn-primary" @click="showAlertModal = false">Entendido</button>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
.inventory-layout {
  padding: 30px;
  background: var(--surface);
  min-height: 100%;
}
.inventory-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
}
.inventory-header h2 {
  margin: 0;
  color: var(--ink-900);
}
.inventory-header p {
  margin: 4px 0 0;
  color: var(--ink-500);
  font-size: 14px;
}
.header-actions {
  display: flex;
  gap: 12px;
}

.filters-bar {
  display: flex;
  gap: 16px;
  margin-bottom: 24px;
  align-items: center;
}
.search-box {
  position: relative;
  flex: 1;
  max-width: 400px;
}
.search-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--ink-400);
}
.search-box input {
  width: 100%;
  padding: 10px 10px 10px 36px;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 14px;
}
.filter-group select {
  padding: 10px 16px;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 14px;
  background: var(--surface-alt);
  color: var(--ink-900);
}

.table-container {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
}

.oh-table {
  width: 100%;
  border-collapse: collapse;
}
.oh-table th {
  background: var(--cream-50);
  text-align: left;
  padding: 12px 16px;
  font-size: 12px;
  text-transform: uppercase;
  color: var(--ink-500);
  border-bottom: 1px solid var(--border);
}
.oh-table td {
  padding: 14px 16px;
  border-bottom: 1px solid var(--cream-100);
  font-size: 14px;
  color: var(--ink-900);
  vertical-align: middle;
}
.oh-table tr.low-stock {
  background: var(--danger-100);
}
.text-danger {
  color: var(--danger-600);
}
.text-muted {
  color: var(--ink-400);
}
.mb-4 { margin-bottom: 16px; }

.badge {
  display: inline-block;
  background: var(--cream-200);
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
  color: var(--ink-700);
  margin-right: 4px;
  margin-bottom: 4px;
}
.cat-badge { background: #e3f2fd; color: #1565c0; }
.type-badge { background: #f3e5f5; color: #6a1b9a; }

.fw-bold { font-weight: 700; }
.actions { display: flex; gap: 4px; }
.empty-state { text-align: center; padding: 40px !important; color: var(--ink-500); }

/* Modal */
.modal-backdrop {
  position: fixed; top: 0; left: 0; width: 100%; height: 100%;
  background: rgba(0,0,0,0.4);
  display: flex; align-items: center; justify-content: center;
  z-index: 100;
}
.modal-content {
  background: var(--surface);
  width: 100%; max-width: 480px;
  border-radius: 16px;
  padding: 24px;
  box-shadow: 0 10px 25px rgba(0,0,0,0.1);
}
.modal-content h3 { margin: 0 0 16px; color: var(--ink-900); }
.form-row { display: flex; gap: 12px; }
.flex-1 { flex: 1; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 12px; font-weight: 700; color: var(--ink-500); margin-bottom: 6px; }
.form-group input, .form-group select, .form-group textarea {
  width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px;
  background: var(--surface-alt); color: var(--ink-900);
}
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; }

.btn-primary { background: var(--passion-500); color: white; border: none; padding: 10px 16px; border-radius: 8px; font-weight: 700; cursor: pointer; }
.btn-ghost { background: transparent; color: var(--ink-700); border: 1px solid transparent; padding: 10px 16px; border-radius: 8px; font-weight: 700; cursor: pointer; }
.btn-ghost:hover { background: var(--cream-100); }

.icon-action-btn {
  background: transparent;
  color: var(--ink-500);
  border: 1px solid transparent;
  padding: 6px;
  border-radius: 6px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
}
.icon-action-btn:hover {
  background: var(--cream-100);
  color: var(--ink-900);
  border-color: var(--border);
}
.icon-action-btn.text-amber { color: #f59e0b; }
.icon-action-btn.text-amber:hover { background: #fef3c7; color: #d97706; border-color: #fcd34d; }
.icon-action-btn.text-info { color: #3b82f6; }
.icon-action-btn.text-info:hover { background: #dbeafe; color: #1d4ed8; border-color: #bfdbfe; }
.icon-action-btn.text-danger { color: var(--danger-600); }
.icon-action-btn.text-danger:hover { background: var(--danger-pale, #fee2e2); color: #b91c1c; border-color: #fca5a5; }

.categories-layout {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
}
.categories-list {
  max-height: 300px;
  overflow-y: auto;
  border: 1px solid var(--border);
  border-radius: 8px;
}
.categories-form {
  background: var(--cream-50);
  padding: 16px;
  border-radius: 8px;
}
.categories-form h4 {
  margin-top: 0;
  margin-bottom: 16px;
  font-size: 14px;
  color: var(--ink-900);
}

.has-error {
  border-color: var(--danger-600) !important;
  background-color: var(--danger-100);
}
.error-text {
  color: var(--danger-600);
  font-size: 11px;
  margin-top: 4px;
  display: block;
}
.btn-action-labeled {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: var(--surface-alt);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 4px 8px;
  font-size: 11px;
  font-weight: 600;
  color: var(--ink-700);
  cursor: pointer;
  transition: all 0.2s;
}
.btn-action-labeled:hover {
  background: var(--cream-50);
  border-color: var(--ink-300);
}
.btn-action-labeled.text-danger:hover {
  color: var(--danger-600);
  border-color: var(--danger-300);
  background: var(--danger-50);
}
.btn-action-labeled.text-amber:hover {
  color: #D97706; /* Amber-600 */
  border-color: #FCD34D;
  background: #FEF3C7;
}
.btn-action-labeled.text-info:hover {
  color: #0284C7; /* Sky-600 */
  border-color: #BAE6FD;
  background: #E0F2FE;
}
</style>
