<script setup>
import { ref, onMounted } from 'vue';
import { apiFetch } from '../services/api.js';
import ProductForm from '../components/Menu/ProductForm.vue';
import OptionGroupForm from '../components/Menu/OptionGroupForm.vue';

// State
const categories = ref([]);
const products = ref([]);
const optionGroups = ref([]);
const ingredients = ref([]);

const activeSection = ref('productos'); // 'productos' o 'modificadores'
const activeCategory = ref(null);
const activeProduct = ref(null);
const activeOptionGroup = ref(null);
const isLoading = ref(true);

// Error and Modal states
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
  } catch (error) {
    alertAction('Error cargando catálogo: ' + (error.message || error));
  } finally {
    isLoading.value = false;
  }
};

onMounted(fetchData);

// --- PRODUCTOS ---
const selectProduct = (prod) => {
  activeSection.value = 'productos';
  activeProduct.value = JSON.parse(JSON.stringify(prod));
  if (activeProduct.value.optionGroups) { // Map to IDs
    activeProduct.value.option_groups = activeProduct.value.optionGroups.map(og => og.id);
  } else if (activeProduct.value.option_groups) {
    activeProduct.value.option_groups = activeProduct.value.option_groups.map(og => og.id || og);
  } else {
    activeProduct.value.option_groups = [];
  }
};

const newProduct = () => {
  activeSection.value = 'productos';
  activeProduct.value = {
    id: null,
    name: 'Nuevo Producto',
    description: '',
    price: 0,
    vip_price: 0,
    category_id: categories.value.length ? categories.value[0].id : null,
    printer_target: 'none',
    is_active: true,
    option_groups: []
  };
  formErrors.value = {};
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
      activeProduct.value = null;
      await fetchData();
    } catch (error) {
      alertAction('Error eliminando producto: ' + (error.message || error));
    }
  });
};

// --- MODIFICADORES (Option Groups) ---
const selectOptionGroup = (og) => {
  activeSection.value = 'modificadores';
  activeOptionGroup.value = JSON.parse(JSON.stringify(og));
};

const newOptionGroup = () => {
  activeSection.value = 'modificadores';
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
    <div class="admin-body">
      <!-- SIDEBAR -->
      <div class="admin-side">
        <!-- SECCIÓN MODIFICADORES -->
        <div class="sidebar-section">
          <div class="section-header" @click="activeSection = 'modificadores'; activeOptionGroup = null; activeProduct = null">
            <h3>Modificadores globales</h3>
            <button class="btn-icon" @click.stop="newOptionGroup" title="Nuevo Grupo">+</button>
          </div>
          <div v-if="activeSection === 'modificadores'" class="section-list">
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
        </div>

        <!-- SECCIÓN PRODUCTOS -->
        <div class="sidebar-section" style="border-top: 1px dashed var(--border);">
          <div class="section-header" @click="activeSection = 'productos'; activeOptionGroup = null; activeProduct = null">
            <h3>Productos por Categoría</h3>
            <button class="btn-icon" @click.stop="newProduct" title="Nuevo Producto">+</button>
          </div>
          
          <div v-if="activeSection === 'productos'" class="section-list">
            <template v-for="cat in categories" :key="cat.id">
              <div class="category-title">{{ cat.name }}</div>
              <div 
                v-for="prod in products.filter(p => p.category_id === cat.id)" 
                :key="prod.id"
                class="admin-list-item" 
                :class="{ active: activeProduct?.id === prod.id }"
                @click="selectProduct(prod)"
              >
                <span>{{ prod.name }}</span>
                <span v-if="!prod.is_active" class="dot-inactive" title="Inactivo"></span>
              </div>
            </template>
          </div>
        </div>
      </div>

      <!-- MAIN AREA -->
      <div class="admin-main">
        <div v-if="isLoading" class="loading-state">Cargando...</div>
        
        <template v-else>
          <!-- VISTA PRODUCTO -->
          <ProductForm
            v-if="activeSection === 'productos' && activeProduct"
            :product="activeProduct"
            :categories="categories"
            :optionGroups="optionGroups"
            :formErrors="formErrors"
            @save="saveProduct"
            @delete="deleteProduct"
          />

          <!-- VISTA GRUPO DE OPCIONES -->
          <OptionGroupForm
            v-else-if="activeSection === 'modificadores' && activeOptionGroup"
            :optionGroup="activeOptionGroup"
            :ingredients="ingredients"
            :formErrors="formErrors"
            @save="saveOptionGroup"
            @delete="deleteOptionGroup"
            @update-success="handleUpdateSuccess"
            @alert="alertAction"
            @confirm="confirmAction"
          />

          <div v-else class="empty-state">
            <h3 style="color:var(--ink-500);">Selecciona un ítem de la barra lateral</h3>
          </div>
        </template>
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
.admin-layout { display: flex; flex-direction: column; height: 100vh; width: 100vw; background-color: var(--cream-100); overflow: hidden; }
.admin-body { display: flex; flex: 1; overflow: hidden; }
.admin-side { width: 280px; background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; overflow-y: auto; }
.sidebar-section { display: flex; flex-direction: column; }
.section-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 24px; cursor: pointer; background: var(--cream-50); }
.section-header:hover { background: var(--cream-100); }
.section-header h3 { margin: 0; font-size: 13px; text-transform: uppercase; color: var(--ink-900); letter-spacing: .05em; font-weight: 800; }
.btn-icon { background: var(--acai-100); color: var(--acai-700); border: none; width: 24px; height: 24px; border-radius: 6px; font-size: 16px; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.btn-icon:hover { background: var(--acai-200); }
.category-title { padding: 8px 24px; font-size: 11px; font-weight: 700; color: var(--ink-500); text-transform: uppercase; background: var(--cream-100); border-bottom: 1px solid var(--cream-200); }
.admin-list-item { padding: 12px 24px 12px 32px; font-weight: 600; color: var(--ink-800); font-size: 13.5px; border-bottom: 1px solid var(--cream-200); cursor: pointer; display: flex; align-items: center; justify-content: space-between; }
.admin-list-item:hover { background: var(--cream-50); }
.admin-list-item.active { background: var(--acai-50); color: var(--ink-900); border-left: 4px solid var(--acai-600); padding-left: 28px; }
.dot-inactive { width: 8px; height: 8px; border-radius: 50%; background: var(--ink-300); }
.admin-main { flex: 1; padding: 26px 30px; overflow-y: auto; background: var(--surface); }
.empty-state { height: 100%; display: flex; align-items: center; justify-content: center; }
.modal-backdrop { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--surface); padding: 30px; border-radius: var(--radius-lg); width: 90%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
.modal-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; }
.btn-ghost { cursor: pointer; background: transparent; border: none; font-weight: 700; color: var(--ink-700); padding: 8px 16px; border-radius: 8px; }
.btn-ghost:hover { background: var(--cream-100); }
.btn-primary { background: var(--passion-500); color: white; border: none; border-radius: var(--radius-md); padding: 10px 20px; font-weight: 700; font-size: 14px; cursor: pointer; }
.text-muted { color: var(--ink-500); font-size: 14px; line-height: 1.5; }
</style>
