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
  return props.optionGroups.filter(g => props.product.option_groups.includes(g.id));
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
</script>

<template>
  <div class="modal-backdrop">
    <div class="modal-content options-modal">
      <div class="modal-header">
        <h3>Modificadores para: {{ product.name }}</h3>
        <button class="close-btn" @click="$emit('close')">&times;</button>
      </div>

      <div class="modal-body">
        <p class="text-muted">Desactiva los ingredientes o modificadores que no estén disponibles para este producto específico.</p>
        
        <div v-if="linkedGroups.length === 0" class="empty-state">
          Este producto no tiene grupos de modificadores asignados.
        </div>

        <div v-for="group in linkedGroups" :key="group.id" class="group-section">
          <h4 class="group-title">{{ group.name }}</h4>
          <div class="options-list">
            <div v-for="opt in group.options" :key="opt.id" class="option-item">
              <span :class="{ 'text-strike': isExcluded(opt.id) }">{{ opt.name }}</span>
              <div class="switch" :class="{ on: !isExcluded(opt.id) }" @click="toggleExclusion(opt.id)"></div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn-ghost" @click="$emit('close')">Cancelar</button>
        <button class="btn-primary" @click="handleSave">Guardar Disponibilidad</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop {
  position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;
}
.options-modal {
  background: var(--surface);
  border-radius: var(--radius-lg);
  width: 90%; max-width: 500px;
  max-height: 85vh;
  display: flex; flex-direction: column;
  box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
}
.modal-header {
  padding: 20px 24px; border-bottom: 1px solid var(--border);
  display: flex; justify-content: space-between; align-items: center;
}
.modal-header h3 { margin: 0; color: var(--ink-900); font-size: 18px; }
.close-btn { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--ink-500); }
.modal-body {
  padding: 24px; overflow-y: auto; flex: 1;
}
.text-muted { color: var(--ink-500); font-size: 14px; margin-bottom: 24px; }
.group-section { margin-bottom: 24px; }
.group-title { margin: 0 0 12px 0; color: var(--ink-800); font-size: 15px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;}
.options-list {
  background: var(--cream-50);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  overflow: hidden;
}
.option-item {
  display: flex; justify-content: space-between; align-items: center;
  padding: 12px 16px; border-bottom: 1px solid var(--border);
  font-size: 14px; color: var(--ink-800); font-weight: 600;
}
.option-item:last-child { border-bottom: none; }
.text-strike { text-decoration: line-through; color: var(--ink-400); }

.switch { width: 32px; height: 18px; border-radius: 999px; background: var(--border); position: relative; cursor: pointer; }
.switch.on { background: var(--lime-500); }
.switch::after { content: ''; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; border-radius: 50%; background: var(--surface); transition: .15s; }
.switch.on::after { left: 16px; }

.modal-footer {
  padding: 16px 24px; border-top: 1px solid var(--border);
  display: flex; justify-content: flex-end; gap: 12px;
}
.btn-ghost { background: transparent; border: none; font-weight: 700; color: var(--ink-700); padding: 8px 16px; border-radius: 8px; cursor: pointer; }
.btn-primary { background: var(--passion-500); color: white; border: none; border-radius: var(--radius-md); padding: 10px 20px; font-weight: 700; cursor: pointer; }
.empty-state { text-align: center; color: var(--ink-500); padding: 32px 0; font-style: italic; }
</style>
