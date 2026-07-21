<script setup>
import { ref, watch } from 'vue';

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

const emit = defineEmits(['save', 'delete']);

const localProduct = ref(JSON.parse(JSON.stringify(props.product)));

watch(() => props.product, (newVal) => {
  localProduct.value = JSON.parse(JSON.stringify(newVal));
}, { deep: true });

const moveOptionGroupUp = (index) => {
  if (index > 0) {
    const arr = localProduct.value.option_groups;
    const temp = arr[index];
    arr[index] = arr[index - 1];
    arr[index - 1] = temp;
  }
};

const moveOptionGroupDown = (index) => {
  if (index < localProduct.value.option_groups.length - 1) {
    const arr = localProduct.value.option_groups;
    const temp = arr[index];
    arr[index] = arr[index + 1];
    arr[index + 1] = temp;
  }
};

const save = () => emit('save', localProduct.value);
const deleteProduct = () => emit('delete', localProduct.value.id);
</script>

<template>
  <div class="product-form">
    <div class="main-header">
      <h2 style="margin:0;color:var(--ink-900);">{{ localProduct.name || 'Nuevo Producto' }}</h2>
      <div class="header-actions">
        <div class="toggle-row">
          <span style="font-size:12px;font-weight:600;color:var(--ink-500)">{{ localProduct.is_active ? 'Activo' : 'Inactivo' }}</span>
          <div class="switch" :class="{ on: localProduct.is_active }" @click="localProduct.is_active = !localProduct.is_active"></div>
        </div>
      </div>
    </div>

    <div class="form-grid">
      <div class="full">
        <label>Nombre del producto</label>
        <input v-model="localProduct.name" placeholder="Ej. Hawaiian Bowl" :class="{'has-error': formErrors.name}">
        <span v-if="formErrors.name" class="error-text">{{ formErrors.name[0] }}</span>
      </div>
      <div class="full">
        <label>Descripción</label>
        <input v-model="localProduct.description" placeholder="Ej. Açaí, frutilla, kiwi..." :class="{'has-error': formErrors.description}">
        <span v-if="formErrors.description" class="error-text">{{ formErrors.description[0] }}</span>
      </div>
      <div>
        <label>Categoría</label>
        <select v-model="localProduct.category_id" :class="{'has-error': formErrors.category_id}">
          <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
        </select>
        <span v-if="formErrors.category_id" class="error-text">{{ formErrors.category_id[0] }}</span>
      </div>
      <div>
        <label>Target de comanda</label>
        <select v-model="localProduct.printer_target" :class="{'has-error': formErrors.printer_target}">
          <option value="bar">Barra</option>
          <option value="kitchen">Cocina</option>
          <option value="none">Ninguno</option>
        </select>
        <span v-if="formErrors.printer_target" class="error-text">{{ formErrors.printer_target[0] }}</span>
      </div>
      <div>
        <label>Precio Base (Bs)</label>
        <input type="number" step="0.5" v-model="localProduct.price" :class="{'has-error': formErrors.price}">
        <span v-if="formErrors.price" class="error-text">{{ formErrors.price[0] }}</span>
      </div>
      <div>
        <label>Precio VIP (Bs)</label>
        <input type="number" step="0.5" v-model="localProduct.vip_price" :class="{'has-error': formErrors.vip_price}">
        <span v-if="formErrors.vip_price" class="error-text">{{ formErrors.vip_price[0] }}</span>
      </div>
    </div>

    <h3 style="margin-top:30px;font-size:15px;color:var(--ink-900);">Modificadores (Grupos de Opciones)</h3>
    <p style="font-size:13px;color:var(--ink-500);margin-bottom:12px;">Selecciona los grupos de opciones que aplican a este producto (ej. Tamaños, Toppings).</p>
    
    <div class="modifier-selection">
      <label v-for="og in optionGroups" :key="og.id" class="modifier-checkbox">
        <input type="checkbox" :value="og.id" v-model="localProduct.option_groups">
        <span>{{ og.name }} (Min: {{ og.min_selections }}, Max: {{ og.max_selections }})</span>
      </label>
    </div>

    <div v-if="localProduct.option_groups.length > 0" class="sort-order-container">
      <h4 style="font-size:13px; color:var(--ink-700); margin-bottom: 8px;">Orden de Visualización en Caja</h4>
      <div class="sort-list">
        <div v-for="(ogId, index) in localProduct.option_groups" :key="ogId" class="sort-item">
          <span class="sort-name">{{ optionGroups.find(o => o.id === ogId)?.name }}</span>
          <div class="sort-actions">
            <button class="btn-icon-sm" @click="moveOptionGroupUp(index)" :disabled="index === 0" title="Mover Arriba">↑</button>
            <button class="btn-icon-sm" @click="moveOptionGroupDown(index)" :disabled="index === localProduct.option_groups.length - 1" title="Mover Abajo">↓</button>
          </div>
        </div>
      </div>
    </div>

    <div style="margin-top:28px;display:flex;gap:10px;">
      <button class="btn btn-primary" @click="save">Guardar Producto</button>
      <button v-if="localProduct.id" class="btn btn-danger-outline" @click="deleteProduct">Eliminar</button>
    </div>
  </div>
</template>

<style scoped>
.main-header { display: flex; justify-content: space-between; align-items: center; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 18px; }
.form-grid .full { grid-column: 1 / -1; }
.form-grid label { display: block; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-500); margin-bottom: 6px; }
.form-grid input, .form-grid select { width: 100%; padding: 11px 13px; border-radius: 10px; border: 2px solid var(--border); font-size: 14px; background: var(--surface-alt); font-family: var(--font-sans); color: var(--ink-900); }
.form-grid input:focus, .form-grid select:focus { outline: none; border-color: var(--passion-500); }
.toggle-row { display: flex; align-items: center; gap: 10px; }
.switch { width: 40px; height: 22px; border-radius: 999px; background: var(--border); position: relative; cursor: pointer; }
.switch.on { background: var(--lime-500); }
.switch::after { content: ''; position: absolute; top: 2px; left: 2px; width: 18px; height: 18px; border-radius: 50%; background: var(--surface); transition: .15s; }
.switch.on::after { left: 20px; }
.modifier-selection { display: flex; flex-direction: column; gap: 8px; }
.modifier-checkbox { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--ink-800); font-weight: 500; cursor: pointer; }
.btn-primary { background: var(--passion-500); color: white; border: none; border-radius: var(--radius-md); padding: 12px 20px; font-weight: 700; font-size: 14px; cursor: pointer; }
.btn-primary:hover { background: var(--passion-600); }
.btn-danger-outline { background: transparent; color: var(--danger-600); border: 2px solid var(--danger-600); border-radius: var(--radius-md); padding: 12px 20px; font-weight: 700; font-size: 14px; cursor: pointer; }
.btn-danger-outline:hover { background: var(--danger-50); }
.sort-order-container { margin-top: 20px; background: var(--cream-50); padding: 16px; border-radius: 8px; border: 1px solid var(--border); }
.sort-list { display: flex; flex-direction: column; gap: 8px; }
.sort-item { display: flex; justify-content: space-between; align-items: center; background: var(--surface); padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border); box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
.sort-name { font-size: 13px; font-weight: 600; color: var(--ink-800); }
.sort-actions { display: flex; gap: 4px; }
.sort-actions .btn-icon-sm:disabled { opacity: 0.3; cursor: not-allowed; }
.btn-icon-sm { background: none; border: none; cursor: pointer; font-size: 14px; padding: 2px 6px; border-radius: 6px; transition: background 0.15s; }
.btn-icon-sm:hover { background: var(--cream-200); }
.has-error { border-color: var(--danger-600) !important; background-color: #fffafb; }
.error-text { color: var(--danger-600); font-size: 11px; margin-top: 4px; display: block; }
</style>
