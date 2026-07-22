<script setup>
const props = defineProps({
  product: {
    type: Object,
    required: true
  }
});

const emit = defineEmits(['edit', 'toggle-active', 'view-options']);
</script>

<template>
  <div class="admin-product-card">
    <div class="card-image" :style="{ backgroundImage: 'url(' + (product.image_url || 'https://placehold.co/400x300?text=No+Image') + ')' }">
      <div class="status-badge" :class="{ inactive: !product.is_active }">
        {{ product.is_active ? 'Activo' : 'Inactivo' }}
      </div>
    </div>
    
    <div class="card-content">
      <div class="card-header">
        <h4 class="product-name">{{ product.name }}</h4>
        <div class="switch" :class="{ on: product.is_active }" @click.stop="$emit('toggle-active', product)"></div>
      </div>
      
      <p class="product-desc">{{ product.description || 'Sin descripción' }}</p>
      <div class="product-price">Bs {{ Number(product.price).toFixed(2) }}</div>
      
      <div class="card-actions">
        <button class="btn-ghost-sm" @click.stop="$emit('view-options', product)">Ver Opciones</button>
        <button class="btn-secondary-sm" @click.stop="$emit('edit', product)">Editar</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.admin-product-card {
  background: var(--surface);
  border-radius: var(--radius-lg);
  border: 1px solid var(--border);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: transform 0.2s, box-shadow 0.2s;
}
.admin-product-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
}
.card-image {
  height: 140px;
  background-size: cover;
  background-position: center;
  position: relative;
}
.status-badge {
  position: absolute;
  top: 10px;
  left: 10px;
  background: var(--lime-500);
  color: white;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}
.status-badge.inactive {
  background: var(--ink-400);
}
.card-content {
  padding: 16px;
  display: flex;
  flex-direction: column;
  flex: 1;
}
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 8px;
}
.product-name {
  margin: 0;
  font-size: 16px;
  color: var(--ink-900);
  font-weight: 700;
  line-height: 1.2;
}
.switch {
  width: 32px; height: 18px; border-radius: 999px; background: var(--border); position: relative; cursor: pointer; flex-shrink: 0;
}
.switch.on { background: var(--lime-500); }
.switch::after { content: ''; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; border-radius: 50%; background: var(--surface); transition: .15s; }
.switch.on::after { left: 16px; }
.product-desc {
  font-size: 12px;
  color: var(--ink-500);
  margin: 0 0 12px 0;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  flex: 1;
}
.product-price {
  font-size: 15px;
  font-weight: 800;
  color: var(--passion-600);
  margin-bottom: 16px;
}
.card-actions {
  display: flex;
  gap: 8px;
  justify-content: space-between;
}
.btn-ghost-sm {
  background: none;
  border: 1px solid var(--border);
  color: var(--ink-700);
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  flex: 1;
  text-align: center;
  transition: 0.15s;
}
.btn-ghost-sm:hover {
  background: var(--cream-50);
}
.btn-secondary-sm {
  background: var(--acai-100);
  border: none;
  color: var(--acai-700);
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  flex: 1;
  text-align: center;
  transition: 0.15s;
}
.btn-secondary-sm:hover {
  background: var(--acai-200);
}
</style>
