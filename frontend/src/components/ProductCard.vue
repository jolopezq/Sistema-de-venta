<script setup>
import { ref, computed } from 'vue';
import { productPrice, missingDeliveryPrice, CHANNEL_PEDIDOSYA } from '../utils/channelPricing';

const props = defineProps({
  product: Object,
  disabled: Boolean,
  lowStock: Boolean,
  channel: { type: String, default: 'pos' },
});
const emit = defineEmits(['add']);

const isAdding = ref(false);

const handleAdd = () => {
  if (props.disabled) return;
  isAdding.value = true;
  setTimeout(() => isAdding.value = false, 300);
  emit('add', props.product);
};

const displayPrice = computed(() => {
  if (props.product.is_weight_based) {
    return `Bs ${Number(props.product.price_per_gram * 100).toFixed(2)}`;
  }
  return `Bs ${productPrice(props.product, props.channel).toFixed(2)}`;
});

/** En PedidosYa, el producto no tiene precio de plataforma y se cobra el de mostrador */
const usesLocalPrice = computed(() =>
  props.channel === CHANNEL_PEDIDOSYA && !props.product.is_weight_based && missingDeliveryPrice(props.product)
);

import { resolveImageUrl } from '../utils/imageUrl.js';

// Si la imagen no carga (archivo borrado, servidor nuevo sin las fotos) se muestra la inicial, no un ícono roto
const imageFailed = ref(false);
const getImageUrl = computed(() => {
  return imageFailed.value ? null : resolveImageUrl(props.product.image_url);
});
const initial = computed(() => (props.product.name || '?').trim().charAt(0).toUpperCase());
</script>

<template>
  <div
    class="product-card"
    :class="{ 'pulse-add': isAdding, 'disabled-card': disabled }"
    @click="handleAdd"
  >
    <div class="product-icon">
      <img
        v-if="getImageUrl"
        :src="getImageUrl"
        alt=""
        class="product-img"
        loading="lazy"
        @error="imageFailed = true"
      />
      <div v-else class="product-fallback" aria-hidden="true">
        <span class="fallback-initial">{{ initial }}</span>
      </div>

      <span v-if="disabled" class="product-img-badge paused">No disponible</span>
      <span
        v-else-if="product.option_groups && product.option_groups.length > 0"
        class="product-img-badge"
      >
        Con opciones
      </span>
    </div>

    <div class="product-name" :title="product.name">{{ product.name }}</div>
    <div class="product-desc" :title="product.description">
      {{ product.is_weight_based ? 'Venta por peso' : (product.description || 'Sin descripción disponible') }}
    </div>

    <div class="product-row">
      <span class="product-price-col">
        <span class="product-price" :class="{ weight: product.is_weight_based }">{{ displayPrice }}</span>
        <span v-if="product.is_weight_based" class="product-price-unit">por cada 100 g</span>
        <span
          v-if="lowStock && !disabled"
          class="low-stock-tag"
          title="El stock del sistema no alcanza para una unidad. Puedes vender igual; revisa si falta cargar una compra."
        >Stock bajo</span>
        <span
          v-if="usesLocalPrice"
          class="local-price-tag"
          title="Este producto no tiene precio PedidosYa: se cobra el precio de mostrador"
        >Precio local</span>
      </span>
      <button
        class="add-btn"
        type="button"
        :disabled="disabled"
        :aria-label="`Agregar ${product.name}`"
        @click.stop="handleAdd"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
      </button>
    </div>
  </div>
</template>

<style scoped>
.product-price-col {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  min-width: 0;
}
.low-stock-tag {
  font-size: 11px;
  font-weight: 700;
  line-height: 1.2;
  padding: 1px 6px;
  border-radius: 99px;
  background: #FEF3C7;
  color: #7A4A00;
  white-space: nowrap;
}
html.dark .low-stock-tag {
  background: #3A2A06;
  color: #FCD98A;
}
.local-price-tag {
  font-size: 11px;
  font-weight: 700;
  line-height: 1.2;
  padding: 1px 6px;
  border-radius: 99px;
  background: #FEF3C7;
  color: #7A4A00;
  white-space: nowrap;
}
html.dark .local-price-tag {
  background: #3A2A06;
  color: #FCD98A;
}
@keyframes pulse {
  0% { transform: scale(1); }
  50% { transform: scale(1.02); border-color: var(--lime-500); }
  100% { transform: scale(1); }
}
.pulse-add { animation: pulse 0.3s ease; }
.disabled-card {
  opacity: 0.5;
  filter: grayscale(100%);
  cursor: not-allowed;
}
.disabled-card:active {
  transform: none;
  box-shadow: none;
}
.product-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.35s cubic-bezier(0.2, 0.8, 0.2, 1);
  display: block;
}
.product-card:hover .product-img {
  transform: scale(1.06);
}
/* Sin foto: la inicial del producto en Baloo sobre el morado suave de la marca */
.product-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--acai-50);
}
.product-price-unit { font-size: 11.5px; color: var(--ink-500); line-height: 1.2; }
.fallback-initial {
  font-family: var(--font-heading);
  font-size: 36px;
  font-weight: 700;
  line-height: 1;
  color: var(--acai-text);
}
/* Celular angosto: la foto mide 44 px de alto (style.css); la inicial se achica y la etiqueta se omite */
@media (max-width: 390px) {
  .fallback-initial { font-size: 24px; }
  .product-img-badge:not(.paused) { display: none; }
}
.product-img-badge.paused { background: var(--danger-600); color: #FFFFFF; }
html.dark .product-img-badge.paused { color: var(--on-accent); }
.add-btn svg { width: 15px; height: 15px; }
.add-btn:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; }
.add-btn:disabled { background: var(--cream-200); color: var(--ink-500); cursor: not-allowed; }
.product-price { font-variant-numeric: tabular-nums; }
</style>
