<script setup>
import { computed } from 'vue';

const props = defineProps({
  product: {
    type: Object,
    required: true
  }
});

const emit = defineEmits(['edit', 'toggle-active', 'view-options']);

const formatPrice = (val) => {
  if (val === undefined || val === null) return '0,00';
  return Number(val).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const getImageUrl = computed(() => {
  if (!props.product.image_url) return 'https://placehold.co/120x120?text=Plato';
  if (props.product.image_url.startsWith('http') || props.product.image_url.startsWith('data:')) return props.product.image_url;
  const baseUrl = 'http://127.0.0.1:8000';
  const path = props.product.image_url.startsWith('/') ? props.product.image_url : '/storage/' + props.product.image_url;
  return baseUrl + path;
});
</script>

<template>
  <div class="product-row-card" @click="$emit('edit', product)">
    <!-- Foto del producto -->
    <div class="product-thumb">
      <img 
        :src="getImageUrl" 
        :alt="product.name"
        class="thumb-img"
      />
    </div>

    <!-- Info del producto (Centro) -->
    <div class="product-info">
      <h4 class="product-title">{{ product.name }}</h4>
      <p class="product-desc">{{ product.description || 'Sin descripción disponible.' }}</p>
      
      <div class="product-pills">
        <button type="button" class="btn-pill-ghost" @click.stop="$emit('view-options', product)">
          Ver opciones
        </button>
      </div>
    </div>

    <!-- Columna Derecha (Switch + Precio + Acciones) -->
    <div class="product-actions-column">
      <!-- Switch Toggle Activo/Inactivo -->
      <div class="switch-container" @click.stop="$emit('toggle-active', product)">
        <div class="toggle-switch" :class="{ 'on': product.is_active }">
          <div class="toggle-thumb"></div>
        </div>
      </div>

      <!-- Fila Inferior: Botón Editar / Promoción + Precio BOB -->
      <div class="bottom-actions-row">
        <button type="button" class="btn-pill-outline" @click.stop="$emit('edit', product)">
          Editar
        </button>
        <span class="product-price">{{ formatPrice(product.price) }} BOB</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.product-row-card {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 16px 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  cursor: pointer;
  transition: background 0.15s ease;
}

.product-row-card:last-child {
  border-bottom: none;
}

.product-row-card:hover {
  background: var(--cream-100);
}

/* THUMBNAIL FOTO */
.product-thumb {
  width: 68px;
  height: 68px;
  flex-shrink: 0;
  border-radius: 12px;
  overflow: hidden;
  background: var(--surface-alt);
  border: 1px solid var(--border);
}

.thumb-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

/* DETALLES */
.product-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  min-width: 0;
}

.product-title {
  margin: 0 0 4px 0;
  font-size: 16px;
  font-weight: 700;
  color: var(--ink-900);
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.product-desc {
  margin: 0 0 8px 0;
  font-size: 13px;
  color: var(--ink-500);
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.product-pills {
  display: flex;
  gap: 8px;
  align-items: center;
}

.btn-pill-ghost {
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--ink-700);
  border-radius: 999px;
  padding: 4px 14px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
}

.btn-pill-ghost:hover {
  background: var(--cream-200);
  border-color: var(--ink-500);
  color: var(--ink-900);
}

/* COLUMNA DERECHA ACCIONES */
.product-actions-column {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  align-items: flex-end;
  height: 68px;
  flex-shrink: 0;
}

/* SWITCH TOGGLE */
.switch-container {
  padding: 2px;
}

.toggle-switch {
  width: 42px;
  height: 22px;
  border-radius: 999px;
  background: var(--border);
  position: relative;
  cursor: pointer;
  transition: background-color 0.2s ease;
}

.toggle-switch.on {
  background: var(--lime-500);
}

.toggle-thumb {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: white;
  position: absolute;
  top: 2px;
  left: 2px;
  transition: transform 0.2s ease;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

.toggle-switch.on .toggle-thumb {
  transform: translateX(20px);
}

/* FILA INFERIOR */
.bottom-actions-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.btn-pill-outline {
  background: var(--surface);
  border: 1px solid var(--passion-500);
  color: var(--passion-500);
  border-radius: 999px;
  padding: 4px 14px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s ease;
}

.btn-pill-outline:hover {
  background: var(--cream-100);
  border-color: var(--passion-600);
}

.product-price {
  font-size: 15px;
  font-weight: 700;
  color: var(--ink-700);
  white-space: nowrap;
}
</style>
