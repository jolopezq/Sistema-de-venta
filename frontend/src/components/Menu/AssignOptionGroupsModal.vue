<script setup>
import { ref } from 'vue';

const props = defineProps({
  optionGroups: {
    type: Array,
    required: true
  },
  initialSelected: {
    type: Array,
    default: () => []
  }
});

const emit = defineEmits(['close', 'confirm']);

const localSelected = ref([...props.initialSelected]);

const handleConfirm = () => {
  emit('confirm', localSelected.value);
};
</script>

<template>
  <div class="modal-backdrop-full">
    <div class="modal-content-full">
      <div class="modal-header-custom">
        <button class="btn-back" @click="$emit('close')">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        </button>
        <h2 class="modal-title">Agregar opcionales</h2>
      </div>

      <div class="modal-body-scroll">
        <div class="list-container">
          <label 
            v-for="group in optionGroups" 
            :key="group.id" 
            class="og-card"
          >
            <div class="og-checkbox-col">
              <input type="checkbox" :value="group.id" v-model="localSelected" class="hidden-checkbox" />
              <div class="custom-checkbox" :class="{ 'checked': localSelected.includes(group.id) }">
                <svg v-if="localSelected.includes(group.id)" viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round" class="check-icon"><polyline points="20 6 9 17 4 12"></polyline></svg>
              </div>
            </div>
            <div class="og-info-col">
              <div class="og-name">{{ group.name }}</div>
              <div class="og-options" v-if="group.options && group.options.length">
                {{ group.options.map(o => o.name).join(', ') }}.
              </div>
              <div class="og-options" v-else>Sin opciones configuradas.</div>
              <div class="og-links" v-if="group.products && group.products.length">
                <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="link-icon"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                <span>{{ group.products.map(p => p.name).join(', ') }}.</span>
              </div>
            </div>
          </label>
        </div>
      </div>

      <div class="modal-footer-custom">
        <button class="btn-confirmar-full" @click="handleConfirm">Confirmar</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop-full {
  position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
  background: white;
  display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 1100;
}
@media (min-width: 600px) {
  .modal-backdrop-full {
    background: rgba(0,0,0,0.5);
  }
}

.modal-content-full {
  background: white;
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
}

@media (min-width: 600px) {
  .modal-content-full {
    width: 480px;
    height: 90vh;
    border-radius: 20px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.1);
  }
}

.modal-header-custom {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 20px 24px;
  border-bottom: 1px solid var(--border);
}

.btn-back {
  background: none; border: none; padding: 0; cursor: pointer; color: var(--ink-900); display: flex; align-items: center; justify-content: center;
}

.modal-title {
  margin: 0;
  font-size: 20px;
  font-weight: 800;
  color: var(--ink-900);
}

.modal-body-scroll {
  flex: 1;
  overflow-y: auto;
  padding: 24px;
}

.list-container {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.og-card {
  display: flex;
  gap: 16px;
  padding: 16px;
  border: 1px solid var(--cream-100);
  border-radius: 12px;
  background: white;
  cursor: pointer;
  transition: all 0.2s;
}

.og-card:hover {
  border-color: var(--passion-300);
}

.hidden-checkbox {
  display: none;
}

.custom-checkbox {
  width: 22px; height: 22px; border: 2px solid #9CA3AF; border-radius: 6px;
  display: flex; align-items: center; justify-content: center; background: white; transition: all 0.2s;
  flex-shrink: 0;
}
.custom-checkbox.checked {
  background: var(--passion-500); border-color: var(--passion-500); color: white;
}
.check-icon { color: white; }

.og-info-col {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.og-name {
  font-size: 16px; font-weight: 700; color: var(--ink-900);
}

.og-options {
  font-size: 14px; color: var(--ink-500); line-height: 1.4;
}

.og-links {
  display: flex; align-items: center; gap: 6px; margin-top: 4px;
  font-size: 13px; color: var(--ink-500);
}
.link-icon { color: var(--ink-400); flex-shrink: 0; }

.modal-footer-custom {
  padding: 20px 24px;
  border-top: 1px solid var(--border);
  background: white;
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
</style>
