<script setup>
import { ref, onMounted, computed } from 'vue';
import { apiFetch } from '../services/api.js';
import ProductForm from '../components/Menu/ProductForm.vue';
import OptionGroupForm from '../components/Menu/OptionGroupForm.vue';
import AdminProductCard from '../components/Menu/AdminProductCard.vue';
import ProductOptionsModal from '../components/Menu/ProductOptionsModal.vue';

// State
const categories = ref([]);
const products = ref([]);
const optionGroups = ref([]);
const ingredients = ref([]);

const activeTab = ref('productos'); // 'productos' o 'opcionales'
const activeCategory = ref(null);
const activeOptionGroup = ref(null);

const isLoading = ref(true);

// Modals
const showProductFormModal = ref(false);
const showProductOptionsModal = ref(false);
const activeProductForModal = ref(null);

const formErrors = ref({});
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

// Fetch Data
const fetchData = async () => {
  isLoading.value = true;
  try {
    const [cats, prods, ogs, ings] = await Promise.all([
      apiFetch('/categories'),
      apiFetch('/products'),
      apiFetch('/option-groups'),
      apiFetch('/ingredients')
    ]);
    categories.value = cats.data || cats;
    products.value = prods.data || prods;
    optionGroups.value = ogs.data || ogs;
    ingredients.value = (ings.data || ings).map(i => ({ id: i.id, name: i.name, unit: i.unit }));

    if (categories.value.length > 0 && !activeCategory.value) {
      activeCategory.value = categories.value[0];
    }
  } catch (error) {
    alertAction('Error cargando catálogo: ' + (error.message || error));
  } finally {
    isLoading.value = false;
  }
};

onMounted(fetchData);

// --- TAB PRODUCTOS ---
const activeCategoryProducts = computed(() => {
  if (!activeCategory.value) return [];
  return products.value.filter(p => p.category_id === activeCategory.value.id);
});

const formatProductForModal = (prod) => {
  const p = JSON.parse(JSON.stringify(prod));
  if (p.optionGroups) {
    p.option_groups = p.optionGroups.map(og => og.id);
  } else if (p.option_groups) {
    p.option_groups = p.option_groups.map(og => og.id || og);
  } else {
    p.option_groups = [];
  }
  
  if (p.excluded_options_relation) {
    p.excluded_options = p.excluded_options_relation.map(o => o.id);
  } else if (p.excludedOptions) {
    p.excluded_options = p.excludedOptions.map(o => o.id);
  } else if (p.excluded_options) {
    p.excluded_options = p.excluded_options.map(o => o.id || o);
  } else {
    p.excluded_options = [];
  }
  return p;
};

const newProduct = () => {
  activeProductForModal.value = {
    id: null,
    name: '',
    description: '',
    price: 0,
    vip_price: 0,
    category_id: activeCategory.value ? activeCategory.value.id : (categories.value.length ? categories.value[0].id : null),
    printer_target: 'none',
    is_active: true,
    option_groups: [],
    excluded_options: []
  };
  formErrors.value = {};
  showProductFormModal.value = true;
};

const editProduct = (prod) => {
  activeProductForModal.value = formatProductForModal(prod);
  formErrors.value = {};
  showProductFormModal.value = true;
};

const viewProductOptions = (prod) => {
  activeProductForModal.value = formatProductForModal(prod);
  showProductOptionsModal.value = true;
};

const toggleProductActive = async (prod) => {
  try {
    const updated = { ...prod, is_active: !prod.is_active };
    await apiFetch(`/products/${prod.id}`, { method: 'PUT', body: JSON.stringify(updated) });
    await fetchData();
  } catch (error) {
    alertAction('Error actualizando producto: ' + (error.message || error));
  }
};

const saveProduct = async (productData) => {
  try {
    if (productData.id) {
      await apiFetch(`/products/${productData.id}`, { method: 'PUT', body: JSON.stringify(productData) });
    } else {
      await apiFetch('/products', { method: 'POST', body: JSON.stringify(productData) });
    }
    await fetchData();
    alertAction('Producto guardado correctamente');
    formErrors.value = {};
    showProductFormModal.value = false;
    showProductOptionsModal.value = false;
  } catch (error) {
    if (error.validationErrors) {
      formErrors.value = error.validationErrors;
    } else {
      alertAction('Error guardando producto: ' + (error.message || error));
    }
  }
};

const deleteProduct = async (id) => {
  confirmAction('¿Seguro que deseas eliminar este producto?', async () => {
    try {
      await apiFetch(`/products/${id}`, { method: 'DELETE' });
      await fetchData();
      showProductFormModal.value = false;
    } catch (error) {
      alertAction('Error eliminando producto: ' + (error.message || error));
    }
  });
};

const newCategoryName = ref('');
const categoryFormErrors = ref({});
const showCategoryFormModal = ref(false);

const newCategory = () => {
  newCategoryName.value = '';
  categoryFormErrors.value = {};
  showCategoryFormModal.value = true;
};

const saveCategory = async () => {
  if (!newCategoryName.value.trim()) {
    categoryFormErrors.value = { name: ['El nombre es obligatorio.'] };
    return;
  }
  try {
    await apiFetch('/categories', {
      method: 'POST',
      body: JSON.stringify({ name: newCategoryName.value })
    });
    await fetchData();
    showCategoryFormModal.value = false;
    alertAction('Sección agregada correctamente');
  } catch (error) {
    if (error.validationErrors) {
      categoryFormErrors.value = error.validationErrors;
    } else {
      alertAction('Error agregando sección: ' + (error.message || error));
    }
  }
};

const showEditCategoryModal = ref(false);
const editCategoryName = ref('');
const editCategoryFormErrors = ref({});

const editCategory = () => {
  if (!activeCategory.value) return;
  editCategoryName.value = activeCategory.value.name;
  editCategoryFormErrors.value = {};
  showEditCategoryModal.value = true;
};

const updateCategory = async () => {
  if (!editCategoryName.value.trim()) {
    editCategoryFormErrors.value = { name: ['El nombre es obligatorio.'] };
    return;
  }
  try {
    await apiFetch(`/categories/${activeCategory.value.id}`, {
      method: 'PUT',
      body: JSON.stringify({ name: editCategoryName.value })
    });
    await fetchData();
    // Update active category reference manually to reflect the name change
    const freshCat = categories.value.find(c => c.id === activeCategory.value.id);
    if (freshCat) activeCategory.value = freshCat;
    
    showEditCategoryModal.value = false;
    alertAction('Sección actualizada correctamente');
  } catch (error) {
    if (error.validationErrors) {
      editCategoryFormErrors.value = error.validationErrors;
    } else {
      alertAction('Error actualizando sección: ' + (error.message || error));
    }
  }
};

const deleteCategory = async () => {
  if (!activeCategory.value) return;
  confirmAction('¿Seguro que deseas eliminar esta sección?', async () => {
    try {
      await apiFetch(`/categories/${activeCategory.value.id}`, {
        method: 'DELETE'
      });
      showEditCategoryModal.value = false;
      await fetchData();
      if (categories.value.length > 0) {
        activeCategory.value = categories.value[0];
      } else {
        activeCategory.value = null;
      }
      alertAction('Sección eliminada correctamente');
    } catch (error) {
      alertAction('Error eliminando sección: ' + (error.message || error));
    }
  });
};

// --- TAB OPCIONALES ---
const newOptionGroup = () => {
  activeOptionGroup.value = {
    id: null,
    name: 'Nuevo Grupo de Opciones',
    min_selections: 0,
    max_selections: 1,
    is_active: true,
    options: []
  };
  formErrors.value = {};
};

const handleCreateOptionGroupFromModal = () => {
  showProductFormModal.value = false;
  activeTab.value = 'opcionales';
  newOptionGroup();
};

const selectOptionGroup = (og) => {
  activeOptionGroup.value = JSON.parse(JSON.stringify(og));
};

const saveOptionGroup = async (groupData) => {
  try {
    let savedGroup;
    if (groupData.id) {
      savedGroup = await apiFetch(`/option-groups/${groupData.id}`, { method: 'PUT', body: JSON.stringify(groupData) });
    } else {
      savedGroup = await apiFetch('/option-groups', { method: 'POST', body: JSON.stringify(groupData) });
    }
    await fetchData();
    const freshOg = optionGroups.value.find(og => og.id === (groupData.id || savedGroup.data.id));
    if (freshOg) selectOptionGroup(freshOg);
    alertAction('Grupo guardado correctamente');
    formErrors.value = {};
  } catch (error) {
    if (error.validationErrors) {
      formErrors.value = error.validationErrors;
    } else {
      alertAction('Error guardando grupo: ' + (error.message || error));
    }
  }
};

const deleteOptionGroup = async (id) => {
  confirmAction('¿Seguro que deseas eliminar este grupo?', async () => {
    try {
      await apiFetch(`/option-groups/${id}`, { method: 'DELETE' });
      activeOptionGroup.value = null;
      await fetchData();
    } catch (error) {
      alertAction('Error eliminando grupo: ' + (error.message || error));
    }
  });
};

const handleUpdateSuccess = async (groupId) => {
  const res = await apiFetch(`/option-groups/${groupId}`);
  activeOptionGroup.value = res.data || res;
  await fetchData();
};
</script>

<template>
  <div class="admin-layout">
    
    <!-- TABS PRINCIPALES (Estilo PedidosYa) -->
    <div class="admin-tabs">
      <div 
        class="tab-item" 
        :class="{ active: activeTab === 'productos' }" 
        @click="activeTab = 'productos'">
        Productos
      </div>
      <div 
        class="tab-item" 
        :class="{ active: activeTab === 'opcionales' }" 
        @click="activeTab = 'opcionales'">
        Opcionales
      </div>
    </div>

    <div class="admin-body">
      <!-- SIDEBAR DINÁMICA SEGÚN TAB -->
      <div class="admin-side">
        
        <!-- SIDEBAR PRODUCTOS (CATEGORÍAS) -->
        <template v-if="activeTab === 'productos'">
          <div class="sidebar-add-action" @click="newCategory">
            <span>+ Agregar sección</span>
          </div>
          <div class="section-list">
            <div 
              v-for="cat in categories" 
              :key="cat.id"
              class="admin-list-item" 
              :class="{ active: activeCategory?.id === cat.id }"
              @click="activeCategory = cat"
            >
              <span>{{ cat.name }}</span>
            </div>
          </div>
        </template>

        <!-- SIDEBAR OPCIONALES (GRUPOS) -->
        <template v-if="activeTab === 'opcionales'">
          <div class="sidebar-add-action" @click="newOptionGroup">
            <span>+ Crear Grupo de Opcionales</span>
          </div>
          <div class="section-list">
            <div 
              v-for="og in optionGroups" 
              :key="og.id"
              class="admin-list-item" 
              :class="{ active: activeOptionGroup?.id === og.id }"
              @click="selectOptionGroup(og)"
            >
              <span>{{ og.name }}</span>
            </div>
          </div>
        </template>

      </div>

      <!-- MAIN AREA -->
      <div class="admin-main">
        <div v-if="isLoading" class="loading-state">Cargando...</div>
        
        <template v-else>
          <!-- TAB PRODUCTOS: GRILLA DE PRODUCTOS -->
          <div v-if="activeTab === 'productos'" class="products-grid-view">
            <div v-if="activeCategory" class="category-header">
              <div class="category-title-container">
                <h2>{{ activeCategory.name }}</h2>
                <button class="btn-edit-category" @click="editCategory" title="Editar sección">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                </button>
              </div>
              <button class="btn-add-product" @click="newProduct">+ Agregar Producto</button>
            </div>
            
            <div class="grid-container" v-if="activeCategoryProducts.length > 0">
              <AdminProductCard 
                v-for="prod in activeCategoryProducts" 
                :key="prod.id"
                :product="prod"
                @edit="editProduct"
                @toggle-active="toggleProductActive"
                @view-options="viewProductOptions"
              />
            </div>
            <div v-else class="empty-state">
              <h3 style="color:var(--ink-900); font-weight: 700; margin-bottom: 8px;">Suma ahora tu primer plato</h3>
              <p class="text-muted">Esta categoría no será visible a los usuarios hasta que sumes el primer producto.</p>
            </div>
          </div>

          <!-- TAB OPCIONALES: DETALLE DE GRUPO -->
          <div v-if="activeTab === 'opcionales'" class="options-detail-view">
            <OptionGroupForm
              v-if="activeOptionGroup"
              :optionGroup="activeOptionGroup"
              :ingredients="ingredients"
              :formErrors="formErrors"
              :categories="categories"
              :products="products"
              @save="saveOptionGroup"
              @delete="deleteOptionGroup"
              @update-success="handleUpdateSuccess"
              @alert="alertAction"
              @confirm="confirmAction"
            />
            <div v-else class="empty-state">
              <h3 style="color:var(--ink-500);">Selecciona un grupo de opciones</h3>
              <p class="text-muted">Crea o edita los grupos de modificadores para tus productos.</p>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- MODAL EDITAR SECCIÓN -->
    <div v-if="showEditCategoryModal" class="modal-backdrop">
      <div class="modal-content category-modal">
        <div class="pya-modal-header border-none" style="padding: 24px 24px 0 24px;">
          <h3>Editar categoría</h3>
          <button class="btn-close-pya" @click="showEditCategoryModal = false">&times;</button>
        </div>
        <div class="modal-body-custom">
          <div class="input-container-floating">
            <span class="label-floating">Nombre de la categoría *</span>
            <input 
              type="text" 
              v-model="editCategoryName" 
              class="input-floating-field" 
              placeholder="Nombre"
              @keyup.enter="updateCategory"
            />
          </div>
          <span v-if="editCategoryFormErrors.name" class="error-msg">{{ editCategoryFormErrors.name[0] }}</span>

          <button class="btn-delete-category" @click="deleteCategory">
            <span class="trash-icon">🗑</span> Eliminar categoría
          </button>
        </div>
        <div class="modal-footer-custom">
          <button class="btn-confirmar-full" @click="updateCategory">Confirmar</button>
        </div>
      </div>
    </div>

    <!-- MODAL AGREGAR SECCIÓN -->
    <div v-if="showCategoryFormModal" class="modal-backdrop">
      <div class="modal-content category-modal">
        <div class="pya-modal-header border-none" style="padding: 24px 24px 0 24px;">
          <h3>Agregar sección</h3>
          <button class="btn-close-pya" @click="showCategoryFormModal = false">&times;</button>
        </div>
        <div class="modal-body-custom">
          <div class="input-container-floating">
            <span class="label-floating">Nombre de la categoría *</span>
            <input 
              type="text" 
              v-model="newCategoryName" 
              class="input-floating-field" 
              placeholder="Nombre"
              @keyup.enter="saveCategory"
            />
          </div>
          <span v-if="categoryFormErrors.name" class="error-msg">{{ categoryFormErrors.name[0] }}</span>
        </div>
        <div class="modal-footer-custom">
          <button class="btn-confirmar-full" @click="saveCategory">Confirmar</button>
        </div>
      </div>
    </div>

    <!-- MODAL PRODUCTO -->
    <div v-if="showProductFormModal" class="modal-backdrop">
      <div class="modal-content pya-modal-large">
        <div class="pya-modal-header">
          <h3>{{ activeProductForModal.id ? 'Editar producto' : 'Agregar producto' }}</h3>
          <button class="btn-close-pya" @click="showProductFormModal = false" aria-label="Cerrar">&times;</button>
        </div>
        <div class="pya-modal-scroll-body">
          <ProductForm
            :product="activeProductForModal"
            :categories="categories"
            :optionGroups="optionGroups"
            :formErrors="formErrors"
            @save="saveProduct"
            @delete="deleteProduct"
            @create-option-group="handleCreateOptionGroupFromModal"
          />
        </div>
      </div>
    </div>

    <!-- MODAL OPCIONES DE PRODUCTO (EXCLUSIONES) -->
    <ProductOptionsModal
      v-if="showProductOptionsModal"
      :product="activeProductForModal"
      :optionGroups="optionGroups"
      @save="saveProduct"
      @close="showProductOptionsModal = false"
    />

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
.admin-layout { display: flex; flex-direction: column; height: 100%; width: 100%; background-color: var(--cream-100); overflow: hidden; }

/* TABS */
.admin-tabs {
  display: flex;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 0 24px;
}
.tab-item {
  padding: 16px 24px;
  font-size: 15px;
  font-weight: 700;
  color: var(--ink-500);
  cursor: pointer;
  border-bottom: 3px solid transparent;
  transition: all 0.2s;
}
.tab-item:hover { color: var(--ink-700); }
.tab-item.active {
  color: var(--passion-600);
  border-bottom-color: var(--passion-600);
}

.admin-body { display: flex; flex: 1; overflow: hidden; }
.admin-side { width: 280px; background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; overflow-y: auto; }
.section-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 24px; background: var(--cream-50); border-bottom: 1px solid var(--cream-200); }
.section-header h3 { margin: 0; font-size: 13px; text-transform: uppercase; color: var(--ink-900); letter-spacing: .05em; font-weight: 800; }
.btn-icon { background: var(--acai-100); color: var(--acai-700); border: none; width: 24px; height: 24px; border-radius: 6px; font-size: 16px; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.15s; }
.btn-icon:hover { background: var(--acai-200); }

.admin-list-item { padding: 14px 24px; font-weight: 600; color: var(--ink-800); font-size: 14px; border-bottom: 1px solid var(--cream-200); cursor: pointer; display: flex; align-items: center; justify-content: space-between; transition: 0.15s; }
.admin-list-item:hover { background: var(--cream-50); }
.admin-list-item.active { background: var(--acai-50); color: var(--ink-900); border-left: 4px solid var(--acai-600); }

.admin-main { flex: 1; padding: 32px; overflow-y: auto; background: var(--cream-100); }

/* SIDEBAR ACTIONS */
.sidebar-add-action {
  padding: 16px 24px;
  font-weight: 700;
  color: var(--passion-600);
  background: var(--surface);
  border-bottom: 1px solid var(--cream-200);
  cursor: pointer;
  display: flex;
  align-items: center;
  transition: background-color 0.2s;
}
.sidebar-add-action:hover {
  background: var(--cream-50);
}

/* GRID PRODUCTOS */
.products-grid-view { height: 100%; display: flex; flex-direction: column; }
.category-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.category-header h2 { margin: 0; font-size: 24px; font-weight: 800; color: var(--ink-900); }
.grid-container {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 20px;
}

.options-detail-view { height: 100%; }

.empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }

/* MODALES */
.modal-backdrop { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--surface); padding: 30px; border-radius: var(--radius-lg); width: 90%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }

/* PYA MODAL PRODUCTO */
.pya-modal-large {
  max-width: 520px;
  width: 90%;
  border-radius: 24px;
  background: var(--surface);
  padding: 0;
  display: flex;
  flex-direction: column;
  max-height: 90vh;
  overflow: hidden;
  box-shadow: var(--shadow-pop);
}

.pya-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 24px 28px 16px 28px;
  background: var(--surface);
  flex-shrink: 0;
}

.pya-modal-header h3 {
  margin: 0;
  font-size: 22px;
  font-weight: 800;
  color: var(--ink-900);
  line-height: 1.2;
}

.btn-close-pya {
  background: none;
  border: none;
  font-size: 24px;
  color: var(--ink-500);
  cursor: pointer;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s, color 0.15s;
}

.btn-close-pya:hover {
  background: var(--cream-100);
  color: var(--ink-900);
}

.pya-modal-scroll-body {
  padding: 0 28px 28px 28px;
  overflow-y: auto;
  flex: 1;
}

.modal-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; }
.btn-ghost { cursor: pointer; background: transparent; border: none; font-weight: 700; color: var(--ink-700); padding: 8px 16px; border-radius: 8px; }
.btn-ghost:hover { background: var(--cream-100); }
.btn-primary { background: var(--passion-500); color: white; border: none; border-radius: var(--radius-md); padding: 10px 20px; font-weight: 700; font-size: 14px; cursor: pointer; }
.btn-primary:hover { background: var(--passion-600); }
.text-muted { color: var(--ink-500); font-size: 14px; line-height: 1.5; }

/* CATEGORY MODAL */
.category-modal {
  max-width: 420px !important;
  border-radius: 24px !important;
  padding: 24px !important;
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 20px;
  background: var(--surface) !important;
}
.border-none {
  border: none !important;
  padding: 0 !important;
}
.modal-body-custom {
  margin-top: 10px;
}
.input-container-floating {
  position: relative;
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 10px 16px;
  display: flex;
  flex-direction: column;
  background: var(--surface);
}
.input-container-floating:focus-within {
  border-color: var(--passion-500);
}
.label-floating {
  font-size: 11px;
  color: var(--ink-500);
  margin-bottom: 2px;
}
.input-floating-field {
  border: none;
  outline: none;
  font-size: 16px;
  color: var(--ink-900);
  background: transparent;
  padding: 4px 0;
  width: 100%;
  font-weight: 500;
}
.error-msg {
  color: var(--danger-500);
  font-size: 12px;
  margin-top: 4px;
  display: block;
}
.modal-footer-custom {
  margin-top: 10px;
}
.btn-confirmar-full {
  width: 100%;
  background: var(--passion-500);
  color: white;
  border: none;
  border-radius: 12px;
  padding: 14px;
  font-size: 16px;
  font-weight: 700;
  cursor: pointer;
  transition: opacity 0.2s, background-color 0.2s;
}
.btn-confirmar-full:hover {
  background: var(--passion-600);
}

/* EDIT CATEGORY BUTTON & TITLE */
.category-title-container {
  display: flex;
  align-items: center;
  gap: 12px;
}
.btn-edit-category {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 50%;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--ink-700);
  cursor: pointer;
  box-shadow: 0 2px 4px rgba(0,0,0,0.05);
  transition: all 0.2s;
}
.btn-edit-category:hover {
  background: var(--cream-100);
  border-color: var(--ink-500);
  color: var(--ink-900);
}

/* DELETE CATEGORY BUTTON */
.btn-delete-category {
  margin-top: 24px;
  width: 100%;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 12px;
  font-size: 15px;
  font-weight: 600;
  color: var(--danger-500);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  cursor: pointer;
  transition: background-color 0.2s, border-color 0.2s;
}
.btn-delete-category:hover {
  background: var(--danger-100);
  border-color: var(--danger-500);
}

/* ADD PRODUCT BUTTON */
.btn-add-product {
  background: transparent;
  color: var(--passion-500);
  border: 1px solid var(--passion-500);
  border-radius: 12px;
  padding: 8px 16px;
  font-weight: 700;
  font-size: 14px;
  cursor: pointer;
  transition: all 0.2s;
}
.btn-add-product:hover {
  background: var(--cream-100);
  border-color: var(--passion-600);
}
</style>
