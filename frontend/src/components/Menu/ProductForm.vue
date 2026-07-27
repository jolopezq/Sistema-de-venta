<script setup>
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';
import AssignOptionGroupsModal from './AssignOptionGroupsModal.vue';

const props = defineProps({
  product: {
    type: Object,
    required: true
  },
  categories: {
    type: Array,
    required: true
  },
  optionGroups: {
    type: Array,
    required: true
  },
  formErrors: {
    type: Object,
    default: () => ({})
  }
});

const emit = defineEmits(['save', 'delete', 'create-option-group']);

const initLocalProduct = (prod) => {
  const lp = JSON.parse(JSON.stringify(prod));
  
  if (lp.optionGroups && lp.optionGroups.length > 0) {
    lp.option_groups = lp.optionGroups.map(og => og.id);
  } else if (lp.option_groups && lp.option_groups.length > 0) {
    if (typeof lp.option_groups[0] === 'object') {
      lp.option_groups = lp.option_groups.map(og => og.id);
    }
  } else {
    // If empty or undefined, calculate from props.optionGroups which contains the links
    const ogIds = [];
    if (props.optionGroups && props.optionGroups.length > 0) {
      props.optionGroups.forEach(og => {
        if (og.products && og.products.some(p => p.id === lp.id)) {
          ogIds.push(og.id);
        }
      });
    }
    lp.option_groups = ogIds;
  }
  return lp;
};

const localProduct = ref(initLocalProduct(props.product));
const showAssignOptionsModal = ref(false);

const selectedOptionGroups = computed(() => {
  if (!props.optionGroups || !localProduct.value.option_groups) return [];
  return props.optionGroups.filter(og => localProduct.value.option_groups.includes(og.id));
});

const resolveImageUrl = (url) => {
  if (!url) return null;
  if (url.startsWith('http') || url.startsWith('data:')) return url;
  const baseUrl = 'http://127.0.0.1:8000';
  const path = url.startsWith('/') ? url : '/storage/' + url;
  return baseUrl + path;
};

const imagePreview = ref(resolveImageUrl(localProduct.value.image_url));
const fileInput = ref(null);

// State for custom select dropdowns
const isCategoryDropdownOpen = ref(false);
const isTagDropdownOpen = ref(false);

const categoryDropdownRef = ref(null);
const tagDropdownRef = ref(null);

watch(() => props.product, (newVal) => {
  localProduct.value = initLocalProduct(newVal);
  imagePreview.value = resolveImageUrl(localProduct.value.image_url);
}, { deep: true });

const triggerFileInput = () => {
  fileInput.value?.click();
};

const handleImageUpload = (e) => {
  const file = e.target.files[0];
  if (file) {
    const reader = new FileReader();
    reader.onload = (event) => {
      imagePreview.value = event.target.result;
      localProduct.value.image = file;
    };
    reader.readAsDataURL(file);
  }
};

const save = () => {
  emit('save', localProduct.value);
};

const deleteProduct = () => {
  emit('delete', localProduct.value.id);
};

// Custom Dropdowns Logic
const activeCategoryName = computed(() => {
  const cat = props.categories.find(c => c.id === localProduct.value.category_id);
  return cat ? cat.name : 'Selecciona una categoría';
});

const activeTagName = computed(() => {
  const tags = {
    'popular': 'Popular',
    'recomendado': 'Recomendado',
    'nuevo': 'Nuevo'
  };
  return tags[localProduct.value.tag] || 'Ninguna';
});

const selectCategory = (catId) => {
  localProduct.value.category_id = catId;
  isCategoryDropdownOpen.value = false;
};

const selectTag = (tagVal) => {
  localProduct.value.tag = tagVal;
  isTagDropdownOpen.value = false;
};

const handleClickOutside = (event) => {
  if (categoryDropdownRef.value && !categoryDropdownRef.value.contains(event.target)) {
    isCategoryDropdownOpen.value = false;
  }
  if (tagDropdownRef.value && !tagDropdownRef.value.contains(event.target)) {
    isTagDropdownOpen.value = false;
  }
};

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
  <div class="product-form-pya">
    
    <!-- BANNER DE SUBIDA DE FOTO -->
    <div class="photo-upload-banner">
      <div class="banner-top">
        <span class="badge-required">Requerido</span>
      </div>
      <div class="banner-content">
        <div class="banner-text">
          <h4>¡Los productos con buenas fotos reciben más pedidos!</h4>
          <ul>
            <li>• 720 x 540 píxeles mínimos</li>
            <li>• 0KB a 20.48MB de tamaño</li>
            <li>• .PNG, .JPEG, .JPG, .SVG, .TIFF, .WEBP</li>
          </ul>
        </div>
        <div class="banner-illustration">
          <img v-if="imagePreview" :src="imagePreview" class="uploaded-preview" />
          <svg v-else viewBox="0 0 100 80" class="camera-icon-svg">
            <rect x="10" y="20" width="80" height="50" rx="8" fill="var(--cream-200)" stroke="var(--border)" stroke-width="3"/>
            <circle cx="50" cy="45" r="16" fill="var(--surface-alt)" stroke="var(--ink-500)" stroke-width="3"/>
            <circle cx="50" cy="45" r="8" fill="var(--ink-500)"/>
            <path d="M35 20 L42 10 L58 10 L65 20 Z" fill="var(--surface-alt)" stroke="var(--border)" stroke-width="2"/>
            <circle cx="75" cy="30" r="4" fill="var(--passion-500)"/>
          </svg>
        </div>
      </div>
      <input type="file" ref="fileInput" accept="image/*" class="hidden-input" @change="handleImageUpload" />
      <button type="button" class="btn-upload-photo" @click="triggerFileInput">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
        Sube una foto del plato
      </button>
    </div>

    <!-- CAMPOS DE TEXTO -->
    <div class="form-fields-container">
      
      <!-- Nombre -->
      <div class="input-card" :class="{'has-error': formErrors.name}">
        <span class="input-label">Nombre *</span>
        <input type="text" v-model="localProduct.name" class="input-field" placeholder="Nombre del producto" />
        <span v-if="formErrors.name" class="error-msg">{{ formErrors.name[0] }}</span>
      </div>

      <!-- Descripción -->
      <div class="input-card" :class="{'has-error': formErrors.description}">
        <span class="input-label">Descripción *</span>
        <textarea v-model="localProduct.description" class="textarea-field" placeholder="Descripción breve del producto..." rows="2"></textarea>
        <span v-if="formErrors.description" class="error-msg">{{ formErrors.description[0] }}</span>
      </div>

      <!-- Categoría Custom Dropdown -->
      <div class="input-card relative-position" :class="{'has-error': formErrors.category_id}" ref="categoryDropdownRef">
        <span class="input-label">Categoría</span>
        <div class="custom-select-trigger" @click="isCategoryDropdownOpen = !isCategoryDropdownOpen">
          <span>{{ activeCategoryName }}</span>
          <svg class="chevron-icon" :class="{ 'open': isCategoryDropdownOpen }" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="6 9 12 15 18 9"></polyline>
          </svg>
        </div>
        <div v-if="isCategoryDropdownOpen" class="custom-options-container">
          <div 
            v-for="cat in categories" 
            :key="cat.id" 
            class="custom-option" 
            :class="{ 'selected': localProduct.category_id === cat.id }"
            @click="selectCategory(cat.id)"
          >
            {{ cat.name }}
          </div>
        </div>
        <span v-if="formErrors.category_id" class="error-msg">{{ formErrors.category_id[0] }}</span>
      </div>

      <!-- PRECIO -->
      <div class="section-block">
        <h3 class="section-title">Precio</h3>
        <div class="input-card" :class="{'has-error': formErrors.price}">
          <span class="input-label">Precio *</span>
          <div class="price-input-row">
            <input type="number" step="0.5" v-model="localProduct.price" class="input-field" placeholder="0.00" />
            <span class="currency-tag">BOB</span>
          </div>
          <span v-if="formErrors.price" class="error-msg">{{ formErrors.price[0] }}</span>
        </div>
      </div>

      <!-- ETIQUETAS DE PRODUCTO Custom Dropdown -->
      <div class="section-block">
        <h3 class="section-title">Etiquetas de producto</h3>
        <div class="input-card relative-position" ref="tagDropdownRef">
          <span class="input-label">Etiqueta</span>
          <div class="custom-select-trigger" @click="isTagDropdownOpen = !isTagDropdownOpen">
            <span>{{ activeTagName }}</span>
            <svg class="chevron-icon" :class="{ 'open': isTagDropdownOpen }" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="6 9 12 15 18 9"></polyline>
            </svg>
          </div>
          <div v-if="isTagDropdownOpen" class="custom-options-container">
            <div 
              class="custom-option" 
              :class="{ 'selected': !localProduct.tag }"
              @click="selectTag('')"
            >
              Ninguna
            </div>
            <div 
              class="custom-option" 
              :class="{ 'selected': localProduct.tag === 'popular' }"
              @click="selectTag('popular')"
            >
              Popular
            </div>
            <div 
              class="custom-option" 
              :class="{ 'selected': localProduct.tag === 'recomendado' }"
              @click="selectTag('recomendado')"
            >
              Recomendado
            </div>
            <div 
              class="custom-option" 
              :class="{ 'selected': localProduct.tag === 'nuevo' }"
              @click="selectTag('nuevo')"
            >
              Nuevo
            </div>
          </div>
        </div>
      </div>

      <!-- GRUPOS DE OPCIONALES -->
      <div class="section-block">
        <div class="section-header-row">
          <h3 class="section-title">Grupos de opcionales ({{ localProduct.option_groups ? localProduct.option_groups.length : 0 }})</h3>
          <button type="button" class="btn-text-red" @click="showAssignOptionsModal = true">+ Agregar</button>
        </div>
        
        <!-- Si no hay opcionales asignados -->
        <div v-if="!selectedOptionGroups || selectedOptionGroups.length === 0" class="empty-optionals-card">
          <h4>Ningún opcional asignado</h4>
          <p>Asigna un grupo de opcionales a este producto para que los clientes puedan personalizar su orden.</p>
        </div>

        <!-- Lista de opcionales asignados (solo lectura en esta vista) -->
        <div v-else class="option-groups-card-list">
          <div v-for="og in selectedOptionGroups" :key="og.id" class="og-custom-card readonly-card">
            <div class="og-custom-card-content">
              <div class="og-info-wrapper" style="margin-left: 0;">
                <span class="og-title">{{ og.name }}</span>
                <span class="og-options-text" v-if="og.options && og.options.length">
                  {{ og.options.map(o => o.name).join(', ') }}.
                </span>
                <span class="og-options-text" v-else>
                  Sin opciones configuradas.
                </span>
              </div>
            </div>
            <div class="og-linked-products" v-if="og.products && og.products.length">
              <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="link-icon"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
              <span>{{ og.products.map(p => p.name).join(', ') }}.</span>
            </div>
          </div>
        </div>
      </div>

    </div>

    <!-- BOTÓN FINAL DE GUARDAR / CONFIRMAR -->
    <div class="form-actions-footer">
      <button type="button" class="btn-confirmar-pya" @click="save">Confirmar</button>
      <button v-if="localProduct.id" type="button" class="btn-delete-pya" @click="deleteProduct">Eliminar producto</button>
    </div>

    <!-- MODAL AGREGAR OPCIONALES -->
    <AssignOptionGroupsModal 
      v-if="showAssignOptionsModal"
      :optionGroups="optionGroups"
      :initialSelected="localProduct.option_groups || []"
      @close="showAssignOptionsModal = false"
      @confirm="(selected) => { localProduct.option_groups = selected; showAssignOptionsModal = false; }"
    />
  </div>
</template>

<style scoped>
.product-form-pya {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

/* BANNER FOTO */
.photo-upload-banner {
  background: var(--surface-alt);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.badge-required {
  background: var(--cream-200);
  color: var(--ink-700);
  font-size: 11px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 6px;
}

.banner-content {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.banner-text h4 {
  margin: 0 0 6px 0;
  font-size: 15px;
  font-weight: 700;
  color: var(--ink-900);
  line-height: 1.3;
}

.banner-text ul {
  list-style: none;
  padding: 0;
  margin: 0;
  font-size: 11px;
  color: var(--ink-500);
  line-height: 1.5;
}

.banner-illustration {
  width: 90px;
  height: 70px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.uploaded-preview {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 8px;
}

.camera-icon-svg {
  width: 100%;
  height: 100%;
}

.hidden-input {
  display: none;
}

.btn-upload-photo {
  width: 100%;
  background: var(--surface);
  border: 1px solid var(--passion-500);
  color: var(--passion-500);
  border-radius: 12px;
  padding: 10px;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: all 0.2s;
}

.btn-upload-photo:hover {
  background: var(--cream-100);
  border-color: var(--passion-600);
}

/* FORM FIELDS CONTAINER */
.form-fields-container {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.input-card {
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 8px 14px;
  background: var(--surface);
  display: flex;
  flex-direction: column;
  transition: border-color 0.2s;
}

.input-card:focus-within {
  border-color: var(--passion-500);
}

.input-card.has-error {
  border-color: var(--danger-500) !important;
  background-color: var(--danger-100);
}

.input-label {
  font-size: 11px;
  color: var(--ink-500);
  margin-bottom: 2px;
}

.input-field, .textarea-field {
  border: none;
  outline: none;
  font-size: 15px;
  font-weight: 500;
  color: var(--ink-900);
  background: transparent;
  width: 100%;
  font-family: inherit;
}

.textarea-field {
  resize: vertical;
}

.price-input-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.currency-tag {
  font-size: 13px;
  font-weight: 600;
  color: var(--ink-500);
}

.error-msg {
  color: var(--danger-500);
  font-size: 11px;
  margin-top: 2px;
}

/* SECTIONS */
.section-block {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 8px;
}

.section-title {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: var(--ink-900);
}

.empty-optionals-card {
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 16px;
  text-align: center;
  background: var(--surface);
}

.empty-optionals-card h4 {
  margin: 0 0 4px 0;
  font-size: 14px;
  font-weight: 700;
  color: var(--ink-900);
}

.empty-optionals-card p {
  margin: 0;
  font-size: 12px;
  color: var(--ink-500);
}

.btn-create-option-group {
  margin-top: 12px;
  background: var(--surface);
  border: 1px solid var(--passion-500);
  color: var(--passion-500);
  border-radius: 12px;
  padding: 10px 16px;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: all 0.2s;
}

.btn-create-option-group:hover {
  background: var(--cream-100);
  border-color: var(--passion-600);
}

.optionals-selection-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 8px;
}

.optional-item-checkbox label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  color: var(--ink-700);
  cursor: pointer;
}

/* FOOTER BUTTONS */
.form-actions-footer {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 10px;
}

.btn-confirmar-pya {
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

.btn-confirmar-pya:hover {
  background: var(--passion-600);
}

.btn-delete-pya {
  width: 100%;
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--danger-500);
  border-radius: 12px;
  padding: 12px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.2s, border-color 0.2s;
}

.btn-delete-pya:hover {
  background: var(--danger-100);
  border-color: var(--danger-500);
}

/* CUSTOM DROPDOWNS CSS */
.relative-position {
  position: relative;
}

.custom-select-trigger {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 15px;
  font-weight: 500;
  color: var(--ink-900);
  cursor: pointer;
  padding: 4px 0;
  min-height: 28px;
  margin-top: 2px;
}

.chevron-icon {
  color: var(--ink-500);
  transition: transform 0.2s ease;
}

.chevron-icon.open {
  transform: rotate(180deg);
}

.custom-options-container {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  margin-top: 6px;
  box-shadow: var(--shadow-pop);
  z-index: 100;
  max-height: 200px;
  overflow-y: auto;
  padding: 6px 0;
}

.custom-option {
  padding: 10px 16px;
  font-size: 14.5px;
  font-weight: 500;
  color: var(--ink-900);
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
  text-align: left;
}

.custom-option:hover {
  background: var(--cream-100);
}

.custom-option.selected {
  background: var(--passion-100);
  color: var(--passion-500);
  font-weight: 600;
}
.section-header-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}
.section-header-row .section-title {
  margin-bottom: 0;
}
.btn-text-red {
  background: none;
  border: none;
  color: var(--passion-500);
  font-weight: 600;
  cursor: pointer;
  padding: 0;
  font-size: 15px;
}
.option-groups-card-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.og-custom-card {
  display: block;
  border: 1px solid var(--cream-100);
  border-radius: 12px;
  background: white;
  cursor: pointer;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.og-custom-card:hover {
  border-color: var(--cream-200);
  box-shadow: 0 4px 12px rgba(0,0,0,0.02);
}
.og-custom-card-content {
  display: flex;
  gap: 16px;
  padding: 16px;
}
.og-checkbox-wrapper {
  display: flex;
  align-items: flex-start;
  padding-top: 2px;
}
.hidden-checkbox {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
}
.custom-checkbox {
  width: 22px;
  height: 22px;
  border: 2px solid var(--cream-200);
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
  background: white;
}
.custom-checkbox.checked {
  background: var(--passion-500);
  border-color: var(--passion-500);
  color: white;
}
.og-info-wrapper {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.og-title {
  font-weight: 700;
  color: var(--ink-900);
  font-size: 15px;
}
.og-options-text {
  font-size: 14px;
  color: var(--ink-500);
  line-height: 1.4;
}
.og-linked-products {
  border-top: 1px solid var(--cream-100);
  padding: 12px 16px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--ink-500);
}
.link-icon {
  color: var(--ink-400);
}
</style>
