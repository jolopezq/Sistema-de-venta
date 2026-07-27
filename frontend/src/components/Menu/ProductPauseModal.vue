<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  product: {
    type: Object,
    required: true
  },
  show: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['close', 'confirm']);

const step = ref(1); // 1: Main options, 2: Date selection
const selectedOption = ref(null);
const selectedDate = ref(null);

// Generate next 7 days for the date selection
const nextDays = computed(() => {
  const days = [];
  const today = new Date();
  
  for (let i = 1; i <= 7; i++) {
    const d = new Date(today);
    d.setDate(today.getDate() + i);
    
    let label = '';
    if (i === 1) {
      label = 'Mañana';
    } else {
      label = d.toLocaleDateString('es-BO', { weekday: 'long' });
      label = label.charAt(0).toUpperCase() + label.slice(1);
    }
    
    const dateStr = d.toLocaleDateString('es-BO', { day: '2-digit', month: '2-digit' });
    
    // Create ISO string for midnight of that day
    const isoDate = new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0, 0, 0).toISOString();
    
    days.push({
      label,
      dateStr: i === 1 ? '' : dateStr, // Mañana doesn't show the date in UI usually, or it can
      isoDate,
      value: i
    });
  }
  return days;
});

const infoMessage = computed(() => {
  if (selectedOption.value === 'hoy') {
    return 'Este producto se reactivará mañana.';
  } else if (selectedOption.value === 'indefinidamente') {
    return 'Este producto permanecerá inactivo hasta que lo reactives manualmente.';
  } else if (selectedOption.value === 'hasta' && selectedDate.value) {
    const day = nextDays.value.find(d => d.value === selectedDate.value);
    if (day) {
      if (day.value === 1) {
        return 'Este producto se reactivará mañana.';
      }
      return `Este producto se reactivará el ${day.label.toLowerCase()} ${day.dateStr}.`;
    }
  }
  return '';
});

const selectOption = (opt) => {
  selectedOption.value = opt;
  if (opt === 'hasta') {
    step.value = 2;
    if (!selectedDate.value) {
      selectedDate.value = 1; // Default to tomorrow
    }
  }
};

const cancel = () => {
  resetState();
  emit('close');
};

const confirm = () => {
  let reactivate_at = null;
  
  if (selectedOption.value === 'hoy') {
    // Tomorrow at midnight
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(0, 0, 0, 0);
    reactivate_at = tomorrow.toISOString();
  } else if (selectedOption.value === 'hasta') {
    const day = nextDays.value.find(d => d.value === selectedDate.value);
    if (day) {
      reactivate_at = day.isoDate;
    }
  }
  
  emit('confirm', {
    product: props.product,
    reactivate_at
  });
  resetState();
};

const resetState = () => {
  step.value = 1;
  selectedOption.value = null;
  selectedDate.value = null;
};
</script>

<template>
  <div v-if="show" class="modal-backdrop" @click="cancel">
    <div class="pause-modal-content" @click.stop>
      <!-- Header -->
      <div class="pause-modal-header">
        <h3 v-if="step === 1">{{ product?.name || 'Producto' }}</h3>
        <div v-else class="header-with-back">
          <button class="btn-back" @click="step = 1">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
          </button>
          <h3>No disponible hasta...</h3>
        </div>
        <button class="btn-close" @click="cancel">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <!-- Body Step 1 -->
      <div v-if="step === 1" class="pause-modal-body">
        <div class="option-list">
          <div 
            class="option-item" 
            :class="{ selected: selectedOption === 'hoy' }"
            @click="selectOption('hoy')"
          >
            No disponible hoy
          </div>
          <div 
            class="option-item" 
            :class="{ selected: selectedOption === 'indefinidamente' }"
            @click="selectOption('indefinidamente')"
          >
            No disponible indefinidamente
          </div>
          <div 
            class="option-item" 
            :class="{ selected: selectedOption === 'hasta' }"
            @click="selectOption('hasta')"
          >
            No disponible hasta...
          </div>
        </div>
      </div>

      <!-- Body Step 2 (Date Selection) -->
      <div v-if="step === 2" class="pause-modal-body p-0">
        <div class="date-list">
          <label 
            v-for="day in nextDays" 
            :key="day.value" 
            class="date-radio-item"
            :class="{ selected: selectedDate === day.value }"
          >
            <div class="radio-wrapper">
              <input type="radio" :value="day.value" v-model="selectedDate" />
              <div class="radio-custom">
                <div v-if="selectedDate === day.value" class="radio-inner"></div>
              </div>
            </div>
            <span class="date-label">{{ day.label }}</span>
            <span v-if="day.dateStr" class="date-str">{{ day.dateStr }}</span>
          </label>
        </div>
      </div>

      <!-- Footer Actions -->
      <div v-if="selectedOption || step === 2" class="pause-modal-footer">
        <div v-if="infoMessage" class="info-box">
          {{ infoMessage }}
        </div>
        <div class="actions-row">
          <button class="btn-outline-danger" @click="cancel">Cancelar</button>
          <button class="btn-fill-danger" @click="confirm">Confirmar</button>
        </div>
      </div>

    </div>
  </div>
</template>

<style scoped>
.modal-backdrop {
  position: fixed;
  top: 0;
  left: 0;
  width: 100vw;
  height: 100vh;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 2000;
}

.pause-modal-content {
  background: #ffffff;
  border-radius: 16px;
  width: 90%;
  max-width: 420px;
  box-shadow: 0 10px 25px rgba(0,0,0,0.1);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.pause-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px 24px;
}

.pause-modal-header h3 {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: var(--ink-900);
}

.header-with-back {
  display: flex;
  align-items: center;
  gap: 12px;
}

.btn-back, .btn-close {
  background: none;
  border: none;
  cursor: pointer;
  color: var(--ink-900);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
}

.pause-modal-body {
  padding: 0 24px 24px 24px;
}
.pause-modal-body.p-0 {
  padding: 0;
}

.option-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.option-item {
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 16px;
  font-size: 16px;
  font-weight: 600;
  color: var(--ink-900);
  cursor: pointer;
  transition: all 0.2s ease;
}
.option-item:hover {
  background: var(--cream-100);
}
.option-item.selected {
  border-color: var(--passion-500);
  background: var(--passion-50);
  color: var(--passion-600);
}

.date-list {
  display: flex;
  flex-direction: column;
  border-top: 1px solid var(--border);
}

.date-radio-item {
  display: flex;
  align-items: center;
  padding: 16px 24px;
  border-bottom: 1px solid var(--border);
  cursor: pointer;
}

.radio-wrapper {
  position: relative;
  width: 20px;
  height: 20px;
  margin-right: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.radio-wrapper input {
  opacity: 0;
  width: 0;
  height: 0;
  position: absolute;
}
.radio-custom {
  position: absolute;
  top: 0;
  left: 0;
  width: 20px;
  height: 20px;
  border: 2px solid var(--ink-300);
  border-radius: 50%;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  justify-content: center;
}
.date-radio-item.selected .radio-custom {
  border-color: var(--passion-500);
}
.radio-inner {
  width: 10px;
  height: 10px;
  background-color: var(--passion-500);
  border-radius: 50%;
}

.date-label {
  flex: 1;
  font-size: 16px;
  font-weight: 600;
  color: var(--ink-900);
}
.date-str {
  font-size: 15px;
  color: var(--ink-500);
}

.pause-modal-footer {
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.info-box {
  background: var(--cream-100);
  padding: 16px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  color: var(--ink-900);
  text-align: center;
}

.actions-row {
  display: flex;
  gap: 12px;
}
.actions-row button {
  flex: 1;
  padding: 14px;
  border-radius: 8px;
  font-size: 16px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s;
}
.btn-outline-danger {
  background: white;
  border: 1px solid var(--passion-500);
  color: var(--passion-500);
}
.btn-outline-danger:hover {
  background: var(--passion-50);
}
.btn-fill-danger {
  background: var(--passion-500);
  border: 1px solid var(--passion-500);
  color: white;
}
.btn-fill-danger:hover {
  background: var(--passion-600);
  border-color: var(--passion-600);
}
</style>
