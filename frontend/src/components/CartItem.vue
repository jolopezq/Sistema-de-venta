<script setup>
defineProps({
  item: Object,
  // Solo en pedidos PedidosYa: permite ajustar el precio de la línea con un motivo
  canAdjustPrice: { type: Boolean, default: false },
});
const emit = defineEmits(['increase', 'decrease', 'edit', 'toggle-takeaway', 'adjust-price']);

/**
 * Opciones elegidas como etiquetas neutras; el tamaño se distingue en morado porque cambia el precio y el vaso.
 */
function getModPillClass(groupName = '') {
  const lower = groupName.toLowerCase();
  if (lower.includes('tamaño') || lower.includes('size')) return 'mod-pill mod-pill--size';
  return 'mod-pill';
}
</script>

<template>
  <div class="ticket-item" @dblclick="emit('edit', item)">
    <div class="ticket-item-top">
      <div class="ticket-item-info">
        <div class="ticket-item-name">
          {{ item.name }}
          <button
            type="button"
            class="item-dest-chip"
            :class="item.is_takeaway ? 'item-dest-chip--takeaway' : 'item-dest-chip--dinein'"
            :title="item.is_takeaway ? 'Tocar para servirlo en mesa' : 'Tocar para que sea para llevar'"
            :aria-label="item.is_takeaway ? `${item.name}: para llevar. Cambiar a en mesa` : `${item.name}: en mesa. Cambiar a para llevar`"
            @click.stop="emit('toggle-takeaway', item)"
          >{{ item.is_takeaway ? 'Para llevar' : 'En mesa' }}</button>
        </div>

        <div v-if="item.modifiers && item.modifiers.length > 0" class="ticket-item-pills">
          <span
            v-for="(mod, idx) in item.modifiers"
            :key="idx"
            :class="getModPillClass(mod.group_name)"
          >
            <template v-if="mod.quantity > 1">{{ mod.quantity }}× </template>{{ mod.option_name }}<template v-if="mod.price > 0"> +{{ (Number(mod.price) * (mod.quantity || 1)).toFixed(2) }}</template>
          </span>
        </div>
        <div v-if="item.is_weight_based" class="ticket-item-mods ticket-item-weight">
          {{ item.weight_grams || item.quantity }} g pesados
        </div>

        <div v-if="item.item_note" class="ticket-item-mods ticket-item-note">
          Nota: {{ item.item_note }}
        </div>

        <div v-if="item.price_override_reason" class="ticket-item-mods ticket-item-override">
          Precio ajustado: {{ item.price_override_reason }}
        </div>

        <div v-if="item.allergen_flags && item.allergen_flags.length > 0" class="ticket-item-mods ticket-item-allergen">
          Alergia: {{ item.allergen_flags.join(', ') }}
        </div>
      </div>

      <div class="ticket-item-price-col">
        <div
          v-if="item.price_override_reason && item.catalog_unit_price != null"
          class="ticket-item-price-old"
          title="Precio del tarifario"
        >Bs {{ (Number(item.catalog_unit_price) * Number(item.quantity)).toFixed(2) }}</div>
        <div class="ticket-item-price">Bs {{ item.is_weight_based ? Number(item.subtotal).toFixed(0) : Number(item.subtotal).toFixed(2) }}</div>
        <button
          v-if="canAdjustPrice && !item.is_weight_based"
          type="button"
          class="btn-adjust-price"
          :title="item.price_override_reason ? 'Cambiar el ajuste de precio' : 'Ajustar precio (promo, precio desactualizado)'"
          @click.stop="emit('adjust-price', item)"
        >{{ item.price_override_reason ? 'Precio ajustado' : 'Ajustar precio' }}</button>
      </div>
    </div>

    <div class="ticket-item-actions">
      <div class="qty-control">
        <button type="button" :aria-label="`Quitar uno de ${item.name}`" @click="emit('decrease', item)">−</button>
        <span class="qty-val" aria-live="polite">{{ item.is_weight_based ? (item.weight_grams ? item.weight_grams + ' g' : item.quantity + ' g') : item.quantity }}</span>
        <button type="button" :aria-label="`Agregar otro ${item.name}`" @click="emit('increase', item)">+</button>
      </div>
      <button
        type="button"
        class="btn-edit-item"
        :aria-label="`Editar ${item.name}`"
        title="Editar opciones y nota"
        @click.stop="emit('edit', item)"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" /></svg>
        Editar
      </button>
    </div>
  </div>
</template>

<style scoped>
.ticket-item-price-old {
  font-size: 11.5px;
  color: var(--ink-500);
  text-decoration: line-through;
  font-variant-numeric: tabular-nums;
}
.ticket-item-override {
  color: #8A0030;
  font-weight: 600;
}
html.dark .ticket-item-override {
  color: #FFB3C9;
}
.btn-adjust-price {
  margin-top: 4px;
  padding: 3px 8px;
  border-radius: 99px;
  border: 1px solid #C2003F;
  background: var(--surface);
  color: #8A0030;
  font-family: inherit;
  font-size: 11.5px;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
}
html.dark .btn-adjust-price {
  color: #FFB3C9;
  border-color: #FF6B96;
}
.btn-adjust-price:focus-visible {
  outline: 2px solid var(--acai-500);
  outline-offset: 2px;
}

/* Opciones elegidas */
.ticket-item-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 5px;
}
.mod-pill {
  display: inline-flex;
  align-items: center;
  font-size: 11.5px;
  font-weight: 500;
  padding: 2px 8px;
  border-radius: 99px;
  white-space: nowrap;
  background: var(--cream-200);
  color: var(--ink-700);
}
.mod-pill--size { background: var(--acai-50); color: var(--acai-text); font-weight: 600; }

.ticket-item-info { flex: 1; min-width: 0; }

.ticket-item-price-col {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
  flex-shrink: 0;
}

/* Cantidad a la izquierda, editar a la derecha */
.ticket-item-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.btn-edit-item {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-top: 8px;
  padding: 5px 9px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: transparent;
  color: var(--ink-700);
  font-family: inherit;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.btn-edit-item svg { width: 13px; height: 13px; }
.btn-edit-item:hover { background: var(--acai-50); color: var(--acai-text); border-color: transparent; }
.btn-edit-item:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; }

/* Destino de la línea: se toca para alternar mesa ↔ para llevar */
.item-dest-chip {
  display: inline-flex;
  align-items: center;
  font-size: 11px;
  font-weight: 600;
  padding: 1px 8px;
  border-radius: 99px;
  margin-left: 6px;
  border: 1px solid transparent;
  cursor: pointer;
  vertical-align: 1px;
  font-family: inherit;
}
.item-dest-chip:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; }
.item-dest-chip--takeaway {
  background: var(--passion-100);
  color: var(--passion-text);
}
.item-dest-chip--dinein {
  background: var(--cream-200);
  color: var(--ink-700);
}
.item-dest-chip:hover { border-color: currentColor; }

.ticket-item-weight { color: var(--acai-text); font-weight: 600; }
.ticket-item-note { color: var(--passion-text); font-weight: 600; }
.ticket-item-allergen { color: var(--danger-text); font-weight: 700; }
</style>
