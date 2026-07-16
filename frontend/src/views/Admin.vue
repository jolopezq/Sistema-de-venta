<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import NetworkIndicator from '../components/NetworkIndicator.vue';

const router = useRouter();

const activeProduct = ref('Hawaiian Bowl');
const products = ref([
  { id: 1, name: 'Hawaiian Bowl', active: true },
  { id: 2, name: 'Classic Bowl', active: true },
  { id: 3, name: 'Aloha Bowl', active: true },
  { id: 4, name: 'Marley Bowl', active: true },
  { id: 5, name: 'Akahai Bowl', active: true },
  { id: 6, name: 'Shaka Bowl', active: true },
  { id: 7, name: 'Patriota Bowl', active: true },
  { id: 8, name: 'Açaí por Gramo', active: true },
  { id: 9, name: 'Cappuccino', active: true },
  { id: 10, name: 'Panini Jamón y Queso', active: false },
]);
</script>

<template>
  <div class="admin-layout">
    <header class="pos-header">
      <div class="pos-brand">
        <div class="logo-chip"></div>
        <span>Administración de catálogo</span>
      </div>
      <div class="pos-header-right">
        <button class="btn btn-primary btn-sm">+ Nuevo producto</button>
        <button class="btn-sm btn-ghost" style="border:1px solid rgba(255,255,255,0.2);color:white;background:transparent;font-family:Inter;font-weight:600;margin-left:16px;" @click="router.push('/pos')">Volver al POS</button>
      </div>
    </header>
    <div class="admin-body">
      <div class="admin-side">
        <h3>Productos</h3>
        <div 
          v-for="prod in products" 
          :key="prod.id"
          class="admin-list-item" 
          :class="{ active: activeProduct === prod.name }"
          @click="activeProduct = prod.name"
        >
          <span>{{ prod.name }}</span>
          <span v-if="!prod.active" class="dot-inactive" title="Inactivo"></span>
        </div>
      </div>
      <div class="admin-main">
        <h2 style="margin:0;color:var(--acai-900);">{{ activeProduct }}</h2>
        <p style="color:var(--ink-500);font-size:13px;margin-top:4px;">Producto · Categoría: Bowls Açaí · Açaí, frutilla, kiwi, banana, leche en polvo, granola, leche condensada</p>

        <div class="form-grid">
          <div class="full">
            <label>Nombre del producto</label>
            <input :value="activeProduct">
          </div>
          <div>
            <label>Categoría</label>
            <select><option>Bowls Açaí</option></select>
          </div>
          <div>
            <label>Target de comanda</label>
            <select><option>Barra</option><option>Cocina</option><option>Ninguno</option></select>
          </div>
        </div>

        <label style="display:block;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-500);margin:20px 0 8px;">Precios por tamaño (Bs)</label>
        <div class="form-grid" style="grid-template-columns:repeat(4,1fr);">
          <div><label>Junior</label><input value="18.00"></div>
          <div><label>Mediano</label><input value="25.00"></div>
          <div><label>Grande</label><input value="35.00"></div>
          <div><label>Ohana</label><input value="50.00"></div>
        </div>
        <div class="toggle-row" style="margin-top:14px;">
          <div class="switch"></div><span style="font-size:13px;color:var(--ink-500);">Precio VIP preferencial desactivado para este producto</span>
        </div>

        <h3 style="margin-top:30px;font-size:15px;color:var(--acai-900);">Ficha de receta (consumo de insumos · tamaño Mediano)</h3>
        <table class="recipe-table">
          <thead><tr><th>Insumo</th><th>Unidad</th><th>Cantidad requerida</th><th></th></tr></thead>
          <tbody>
            <tr><td>Pulpa de Açaí</td><td>kg</td><td>0.22</td><td style="cursor:pointer">🗑️</td></tr>
            <tr><td>Frutilla</td><td>kg</td><td>0.08</td><td style="cursor:pointer">🗑️</td></tr>
            <tr><td>Kiwi</td><td>unidades</td><td>1</td><td style="cursor:pointer">🗑️</td></tr>
            <tr><td>Banana</td><td>unidades</td><td>1</td><td style="cursor:pointer">🗑️</td></tr>
            <tr><td>Granola</td><td>kg</td><td>0.05</td><td style="cursor:pointer">🗑️</td></tr>
            <tr><td>Leche condensada</td><td>litros</td><td>0.03</td><td style="cursor:pointer">🗑️</td></tr>
            <tr><td>Leche en polvo</td><td>kg</td><td>0.02</td><td style="cursor:pointer">🗑️</td></tr>
          </tbody>
        </table>
        <button class="btn btn-ghost btn-sm recipe-add">+ Agregar insumo a la receta</button>

        <div style="margin-top:28px;display:flex;gap:10px;">
          <button class="btn btn-primary">Guardar cambios</button>
          <button class="btn btn-danger-outline">Desactivar producto</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.admin-layout {
  display: flex;
  flex-direction: column;
  height: 100vh;
  width: 100vw;
  background-color: var(--cream-100);
  overflow: hidden;
}

.pos-header {
  background: var(--acai-900);
  color: white;
  padding: 12px 24px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.pos-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  font-family: 'Baloo 2';
  font-weight: 700;
  font-size: 20px;
}
.logo-chip {
  width: 32px;
  height: 32px;
  background: white;
  border-radius: 8px;
  background-image: var(--logo-uri);
  background-size: 72%;
  background-position: center;
  background-repeat: no-repeat;
}
.pos-header-right {
  display: flex;
  align-items: center;
}

.admin-body {
  display: flex;
  flex: 1;
  overflow: hidden;
}
.admin-side {
  width: 280px;
  background: white;
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  overflow-y: auto;
}
.admin-side h3 {
  margin: 0;
  padding: 18px 24px;
  font-size: 14px;
  text-transform: uppercase;
  color: var(--ink-500);
  letter-spacing: .05em;
  border-bottom: 1px dashed var(--border);
}
.admin-list-item {
  padding: 14px 24px;
  font-weight: 600;
  color: var(--ink-900);
  font-size: 14px;
  border-bottom: 1px solid var(--cream-200);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.admin-list-item:hover {
  background: var(--cream-50);
}
.admin-list-item.active {
  background: var(--acai-50);
  color: var(--acai-900);
  border-left: 4px solid var(--acai-600);
  padding-left: 20px;
}
.dot-inactive {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--ink-300);
}

.admin-main {
  flex: 1;
  padding: 26px 30px;
  overflow-y: auto;
  background: white;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
  margin-top: 18px;
}
.form-grid .full {
  grid-column: 1 / -1;
}
.form-grid label {
  display: block;
  font-size: 11.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .04em;
  color: var(--ink-500);
  margin-bottom: 6px;
}
.form-grid input, .form-grid select {
  width: 100%;
  padding: 11px 13px;
  border-radius: 10px;
  border: 2px solid var(--border);
  font-size: 14px;
  background: white;
  font-family: var(--font-sans);
  color: var(--ink-900);
}
.form-grid input:focus, .form-grid select:focus {
  outline: none;
  border-color: var(--passion-500);
}

.toggle-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 4px;
}
.switch {
  width: 40px;
  height: 22px;
  border-radius: 999px;
  background: var(--border);
  position: relative;
  cursor: pointer;
}
.switch.on {
  background: var(--lime-500);
}
.switch::after {
  content: '';
  position: absolute;
  top: 2px;
  left: 2px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: white;
  transition: .15s;
}
.switch.on::after {
  left: 20px;
}

.recipe-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 10px;
}
.recipe-table th {
  text-align: left;
  font-size: 11px;
  text-transform: uppercase;
  color: var(--ink-500);
  padding: 8px 10px;
  border-bottom: 2px solid var(--border);
}
.recipe-table td {
  padding: 10px;
  border-bottom: 1px solid var(--border);
  font-size: 13.5px;
  color: var(--ink-900);
}
.recipe-add {
  margin-top: 12px;
  cursor: pointer;
  padding: 8px 16px;
  border-radius: 8px;
  font-weight: 700;
}

.btn-primary {
  background: var(--passion-500);
  color: white;
  border: none;
  border-radius: var(--radius-md);
  padding: 12px 20px;
  font-weight: 700;
  font-size: 14px;
  cursor: pointer;
}
.btn-primary:hover {
  background: var(--passion-600);
}
.btn-danger-outline {
  background: transparent;
  color: var(--danger-600);
  border: 2px solid var(--danger-600);
  border-radius: var(--radius-md);
  padding: 12px 20px;
  font-weight: 700;
  font-size: 14px;
  cursor: pointer;
}
.btn-danger-outline:hover {
  background: var(--danger-50);
}
.btn-ghost {
  cursor: pointer;
  background: transparent;
  border: none;
  font-weight: 700;
  color: var(--ink-700);
  padding: 8px 16px;
  border-radius: 8px;
  transition: background 0.2s;
}
.btn-ghost:hover {
  background: var(--cream-100);
}
</style>
