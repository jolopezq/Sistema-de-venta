<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  product: {
    type: Object,
    required: true
  },
  optionGroups: {
    type: Array,
    required: true
  }
});

const emit = defineEmits(['close', 'save']);

// Initialize excluded options locally to manage state before saving
const localExcluded = ref([...(props.product.excluded_options || [])]);

// Groups linked to this product
const linkedGroups = computed(() => {
  if (!props.product.option_groups) return [];
  
  // Si option_groups es un array de objetos (por eager loading), extraemos los IDs.
  // Si ya es un array de IDs (números), lo usamos tal cual.
  const groupIds = props.product.option_groups.map(og => typeof og === 'object' ? og.id : og);
  
  return props.optionGroups.filter(g => groupIds.includes(g.id));
});

const isExcluded = (optionId) => localExcluded.value.includes(optionId);

const toggleExclusion = (optionId) => {
  if (isExcluded(optionId)) {
    localExcluded.value = localExcluded.value.filter(id => id !== optionId);
  } else {
    localExcluded.value.push(optionId);
  }
};

const handleSave = () => {
  // Emit updated product data with new excluded options
  emit('save', {
    ...props.product,
    excluded_options: localExcluded.value
  });
};

const selectedGroup = ref(null);
</script>

<template>
  <div class="modal-backdrop">
    <div class="modal-content options-modal">
      
      <!-- VIEW 1: GROUP LIST -->
      <template v-if="!selectedGroup">
        <div class="modal-header-custom">
          <div class="header-top">
            <span class="red-subtitle">Grupos de opcionales</span>
            <button class="close-btn" @click="$emit('close')">&times;</button>
          </div>
          <h2 class="product-title">{{ product.name }}</h2>
        </div>

        <div class="modal-body-custom">
          <div v-if="linkedGroups.length === 0" class="empty-state">
            Este producto no tiene grupos de modificadores asignados.
          </div>
          
          <div 
            v-for="group in linkedGroups" 
            :key="group.id" 
            class="group-card"
            @click="selectedGroup = group"
          >
            <div class="group-card-info">
              <span class="group-card-name">{{ group.name }}</span>
              <span class="group-card-count">{{ group.options ? group.options.length : 0 }} Opcionales</span>
            </div>
            <div class="group-card-icon">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </div>
          </div>
        </div>

        <div class="modal-footer-custom">
          <button class="btn-confirmar-full" @click="handleSave">Guardar Disponibilidad</button>
        </div>
      </template>

      <!-- VIEW 2: OPTIONS LIST -->
      <template v-else>
        <div class="modal-header-custom" style="padding-bottom: 8px;">
          <div class="header-top">
            <div class="header-back-title">
              <button class="btn-back" @click="selectedGroup = null">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
              </button>
              <h2 class="product-title m-0">{{ selectedGroup.name }}</h2>
            </div>
            <button class="close-btn" @click="$emit('close')">&times;</button>
          </div>
        </div>

        <div class="modal-body-custom">
          <div 
            v-for="opt in selectedGroup.options" 
            :key="opt.id" 
            class="option-card"
            @click="toggleExclusion(opt.id)"
          >
            <span class="option-card-name" :class="{ 'text-strike': isExcluded(opt.id) }">{{ opt.name }}</span>
            <div class="toggle-switch-pya" :class="{ on: !isExcluded(opt.id) }">
              <div class="toggle-thumb-pya"></div>
            </div>
          </div>
        </div>

        <div class="modal-footer-custom">
          <button class="btn-confirmar-full" @click="handleSave">Guardar Disponibilidad</button>
        </div>
      </template>

    </div>
  </div>
</template>

<style scoped>
.modal-backdrop {
  position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;
}

.options-modal {
  background: var(--surface);
  border-radius: 16px;
  width: 90%; 
  max-width: 500px;
  max-height: 85vh;
  display: flex; flex-direction: column;
  box-shadow: var(--shadow-pop);
  overflow: hidden;
}

.modal-header-custom {
  padding: 24px 24px 16px 24px;
}

.header-top {
  display: flex; justify-content: space-between; align-items: flex-start;
  margin-bottom: 12px;
}

.red-subtitle {
  color: var(--passion-500);
  font-weight: 700;
  font-size: 14px;
}

.close-btn {
  background: none; border: none; font-size: 28px; line-height: 1; cursor: pointer; color: var(--ink-900);
}

.product-title {
  margin: 0;
  font-size: 24px;
  font-weight: 800;
  color: var(--ink-900);
}
.m-0 { margin: 0; }

.header-back-title {
  display: flex;
  align-items: center;
  gap: 12px;
}

.btn-back {
  background: none; border: none; padding: 0; cursor: pointer; color: var(--ink-900); display: flex; align-items: center; justify-content: center;
}

.modal-body-custom {
  padding: 0 24px 24px 24px;
  overflow-y: auto;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.group-card {
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 16px 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
  background: var(--surface);
  transition: border-color 0.15s, box-shadow 0.15s;
}
.group-card:hover {
  border-color: var(--ink-300);
  box-shadow: 0 4px 12px rgba(0,0,0,0.03);
}

.group-card-info {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.group-card-name {
  font-size: 16px;
  font-weight: 700;
  color: var(--ink-900);
}

.group-card-count {
  font-size: 14px;
  color: var(--ink-500);
}

.group-card-icon {
  color: var(--ink-500);
}

.option-card {
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 16px 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
  background: var(--surface);
}

.option-card-name {
  font-size: 15px;
  font-weight: 700;
  color: var(--ink-900);
}
.text-strike { text-decoration: line-through; color: var(--ink-400); }

/* Switch Style */
.toggle-switch-pya {
  width: 44px;
  height: 24px;
  background: var(--border); /* Light gray for OFF state */
  border-radius: 999px;
  position: relative;
  transition: background 0.2s, box-shadow 0.2s;
  box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
}
.toggle-switch-pya.on {
  background: var(--pine-500, #22c55e); /* fallback to green if var not exist */
}
.toggle-thumb-pya {
  width: 20px;
  height: 20px;
  background: var(--ink-900);
  border-radius: 50%;
  position: absolute;
  top: 2px;
  left: 2px;
  transition: transform 0.2s;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.toggle-switch-pya.on .toggle-thumb-pya {
  transform: translateX(20px);
}

.modal-footer-custom {
  padding: 24px;
}

.btn-confirmar-full {
  width: 100%;
  padding: 14px;
  background: var(--passion-500);
  color: white;
  font-size: 16px;
  font-weight: 700;
  border: none;
  border-radius: 12px;
  cursor: pointer;
  text-align: center;
}
</style>
