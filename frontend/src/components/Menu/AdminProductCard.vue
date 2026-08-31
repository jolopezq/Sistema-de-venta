<script setup>
import { ref, computed } from 'vue';
import { resolveImageUrl } from '../../utils/imageUrl.js';

const props = defineProps({
  product: {
    type: Object,
    required: true
  },
  optionGroups: {
    type: Array,
    default: () => []
  }
});

const emit = defineEmits(['edit', 'toggle-active', 'pause-product', 'save-options', 'view-options']);

const formatPrice = (val) => {
  if (val === undefined || val === null) return '0,00';
  return Number(val).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const getImageUrl = computed(() => {
  return resolveImageUrl(props.product.image_url) || 'https://placehold.co/120x120?text=Plato';
});

const assignedGroupsCount = computed(() => {
  if (props.product.option_groups && Array.isArray(props.product.option_groups)) {
    return props.product.option_groups.length;
  }
  if (props.product.optionGroups && Array.isArray(props.product.optionGroups)) {
    return props.product.optionGroups.length;
  }
  // Alternativa: buscar en props.optionGroups
  if (props.optionGroups && props.optionGroups.length > 0) {
    return props.optionGroups.filter(og => og.products && og.products.some(p => p.id === props.product.id)).length;
  }
  return 0;
});

const pauseText = computed(() => {
  if (props.product.is_active || !props.product.reactivate_at) return null;
  const d = new Date(props.product.reactivate_at);
  const now = new Date();
  const isToday = d.toDateString() === now.toDateString();
  const timeStr = d.toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit' });
  if (isToday) {
    return `Pausado hasta las ${timeStr}`;
  }
  const dateStr = d.toLocaleDateString('es-BO', { day: '2-digit', month: '2-digit' });
  return `Pausado hasta ${dateStr} ${timeStr}`;
});

const tagLabel = computed(() => {
  const map = {
    'popular': '🔥 Popular',
    'recomendado': '⭐ Recomendado',
    'nuevo': '✨ Nuevo'
  };
  return map[props.product.tag] || null;
});
</script>

<template>
  <div class="product-row-card-container">
    <div class="product-row-card" @click="$emit('edit', product)">
      <!-- Foto del producto -->
      <div class="product-thumb">
        <img 
          :src="getImageUrl" 
          :alt="product.name"
          class="thumb-img"
        />
        <span v-if="!product.is_active" class="thumb-paused-overlay">Pausado</span>
      </div>

      <!-- Info del producto (Centro) -->
      <div class="product-info">
        <div class="product-header-line">
          <h4 class="product-title">{{ product.name }}</h4>
          <span v-if="tagLabel" class="badge-tag" :class="product.tag">{{ tagLabel }}</span>
        </div>

        <p class="product-desc">{{ product.description || 'Sin descripción disponible.' }}</p>
        
        <div class="product-pills">
          <button type="button" class="btn-pill-ghost" @click.stop="$emit('view-options', product)">
            Opciones <span class="badge-count" v-if="assignedGroupsCount > 0">{{ assignedGroupsCount }}</span>
          </button>

          <!-- Aviso de pausa programada -->
          <span v-if="pauseText" class="pill-pause-info">
            ⏱️ {{ pauseText }}
          </span>
        </div>
      </div>

      <!-- Columna Derecha (Switch + Precio + Acciones) -->
      <div class="product-actions-column">
        <!-- Switch Toggle Activo/Inactivo -->
        <div class="switch-container" :title="product.is_active ? 'Pausar producto' : 'Activar producto'" @click.stop="product.is_active ? $emit('pause-product', product) : $emit('toggle-active', product)">
          <div class="toggle-switch" :class="{ 'on': product.is_active }">
            <div class="toggle-thumb"></div>
          </div>
        </div>

        <!-- Fila Inferior: Botón Editar + Precio BOB -->
        <div class="bottom-actions-row">
          <button type="button" class="btn-pill-outline" @click.stop="$emit('edit', product)">
            Editar
          </button>
          <div class="price-container">
            <span class="product-price">{{ formatPrice(product.price) }} BOB</span>
            <span v-if="product.is_weight_based" class="weight-tag">/ kg</span>
          </div>
        </div>
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
  background: var(--surface-hover);
}

/* THUMBNAIL FOTO */
.product-thumb {
  width: 72px;
  height: 72px;
  flex-shrink: 0;
  border-radius: 12px;
  overflow: hidden;
  background: var(--surface-alt);
  border: 1px solid var(--border);
  position: relative;
}

.thumb-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.thumb-paused-overlay {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  background: rgba(0, 0, 0, 0.7);
  color: white;
  font-size: 9.5px;
  font-weight: 700;
  text-align: center;
  padding: 2px 0;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

/* DETALLES */
.product-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  min-width: 0;
}

.product-header-line {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 4px;
  flex-wrap: wrap;
}

.product-title {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: var(--ink-900);
  line-height: 1.2;
}

.badge-tag {
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 999px;
  line-height: 1.3;
}
.badge-tag.popular {
  background: #fef2f2;
  color: #dc2626;
  border: 1px solid #fecaca;
}
.badge-tag.recomendado {
  background: #fefce8;
  color: #ca8a04;
  border: 1px solid #fef08a;
}
.badge-tag.nuevo {
  background: #f0fdf4;
  color: #16a34a;
  border: 1px solid #bbf7d0;
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
  flex-wrap: wrap;
}

.btn-pill-ghost {
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--ink-700);
  border-radius: 999px;
  padding: 3px 12px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all 0.2s ease;
}

.btn-pill-ghost:hover {
  background: var(--surface-hover);
  border-color: var(--ink-500);
  color: var(--ink-900);
}

.badge-count {
  background: var(--passion-500);
  color: white;
  font-size: 10px;
  font-weight: 800;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.pill-pause-info {
  font-size: 11.5px;
  font-weight: 600;
  color: #b45309;
  background: #fffbeb;
  padding: 2px 8px;
  border-radius: 6px;
  border: 1px solid #fef3c7;
}

/* COLUMNA DERECHA ACCIONES */
.product-actions-column {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  align-items: flex-end;
  min-height: 72px;
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
  background: var(--surface-hover);
  border-color: var(--passion-600);
}

.price-container {
  display: flex;
  align-items: baseline;
  gap: 3px;
}

.product-price {
  font-size: 15px;
  font-weight: 700;
  color: var(--ink-700);
  white-space: nowrap;
}

.weight-tag {
  font-size: 11px;
  color: var(--ink-500);
  font-weight: 600;
}
</style>

