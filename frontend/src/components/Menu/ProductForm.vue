<script setup>
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';

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

const localProduct = ref(JSON.parse(JSON.stringify(props.product)));
const imagePreview = ref(localProduct.value.image_url || null);
const fileInput = ref(null);

// State for custom select dropdowns
const isCategoryDropdownOpen = ref(false);
const isTagDropdownOpen = ref(false);

const categoryDropdownRef = ref(null);
const tagDropdownRef = ref(null);

watch(() => props.product, (newVal) => {
  localProduct.value = JSON.parse(JSON.stringify(newVal));
  imagePreview.value = localProduct.value.image_url || null;
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
        <h3 class="section-title">Grupos de opcionales</h3>
        
        <!-- Si no hay opcionales vinculados -->
        <div v-if="!localProduct.option_groups || localProduct.option_groups.length === 0" class="empty-optionals-card">
          <h4>Ningún opcional agregado</h4>
          <p>Agregar un opcional a este producto permitirá a los consumidores personalizar sus órdenes.</p>
          <button type="button" class="btn-create-option-group" @click="emit('create-option-group')">
            + Crear grupo de opcionales
          </button>
        </div>

        <!-- Lista de opcionales seleccionados / disponibles -->
        <div class="optionals-selection-list">
          <div v-for="og in optionGroups" :key="og.id" class="optional-item-checkbox">
            <label>
              <input type="checkbox" :value="og.id" v-model="localProduct.option_groups" />
              <span>{{ og.name }} (Min: {{ og.min_selections }}, Max: {{ og.max_selections }})</span>
            </label>
          </div>
        </div>
      </div>

    </div>

    <!-- BOTÓN FINAL DE GUARDAR / CONFIRMAR -->
    <div class="form-actions-footer">
      <button type="button" class="btn-confirmar-pya" @click="save">Confirmar</button>
      <button v-if="localProduct.id" type="button" class="btn-delete-pya" @click="deleteProduct">Eliminar producto</button>
    </div>

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
</style>
