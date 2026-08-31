<script setup>
defineProps({ item: Object });
const emit = defineEmits(['increase', 'decrease', 'edit', 'toggle-takeaway']);

/**
 * Agrupa los modificadores por grupo para mostrarlos como pills categorizadas.
 * Los grupos de tamaño van en azul, frutas en rosa, toppings en ámbar.
 */
function getModPillClass(groupName = '') {
  const lower = groupName.toLowerCase();
  if (lower.includes('tamaño') || lower.includes('size')) return 'mod-pill mod-pill--size';
  if (lower.includes('fruta')) return 'mod-pill mod-pill--fruit';
  if (lower.includes('topping')) return 'mod-pill mod-pill--topping';
  return 'mod-pill mod-pill--default';
}
</script>

<template>
  <div class="ticket-item" @dblclick="emit('edit', item)">
    <!-- Top row: name + price -->
    <div class="ticket-item-top">
      <div class="ticket-item-info">
        <div class="ticket-item-name">
          {{ item.name }}
          <button
            type="button"
            class="item-dest-chip"
            :class="item.is_takeaway ? 'item-dest-chip--takeaway' : 'item-dest-chip--dinein'"
            @click.stop="emit('toggle-takeaway', item)"
            :title="item.is_takeaway ? 'Clic para cambiar a: Para Mesa' : 'Clic para cambiar a: Para Llevar'"
          >
            <span class="dest-chip-icon">{{ item.is_takeaway ? '🛍️' : '🍽️' }}</span>
            <span class="dest-chip-text">{{ item.is_takeaway ? 'Para Llevar' : 'En Mesa' }}</span>
          </button>
        </div>

        <!-- Modifier pills -->
        <div v-if="item.modifiers && item.modifiers.length > 0" class="ticket-item-pills">
          <span
            v-for="(mod, idx) in item.modifiers"
            :key="idx"
            :class="getModPillClass(mod.group_name)"
          >
            <span v-if="mod.quantity > 1" style="font-weight:800;">{{ mod.quantity }}x </span>{{ mod.option_name }}<span v-if="mod.price > 0"> +{{ (Number(mod.price) * (mod.quantity || 1)).toFixed(2) }}</span>
          </span>
        </div>
        <div v-else-if="item.is_weight_based" class="ticket-item-mods">
          {{ item.quantity }} g · Bs ${{ (item.unit_price / 100).toFixed(4) }}/g
        </div>
        <div v-else-if="!item.item_note && (!item.allergen_flags || item.allergen_flags.length === 0)" class="ticket-item-mods">
          Sin modificadores
        </div>

        <!-- Nota especial -->
        <div v-if="item.item_note" class="ticket-item-mods ticket-item-note">
          📝 {{ item.item_note }}
        </div>

        <!-- Alérgenos -->
        <div v-if="item.allergen_flags && item.allergen_flags.length > 0" class="ticket-item-mods ticket-item-allergen">
          ⚠️ {{ item.allergen_flags.join(', ') }}
        </div>
      </div>

      <div class="ticket-item-price-col">
        <div class="ticket-item-price">Bs {{ Number(item.subtotal).toFixed(2) }}</div>
        <!-- Botón editar — siempre visible en tablet, hover en desktop -->
        <button
          class="btn-edit-item"
          title="Editar este ítem"
          @click.stop="emit('edit', item)"
        >✏️</button>
      </div>
    </div>

    <!-- Cantidad -->
    <div class="qty-control">
      <button @click="emit('decrease', item)">−</button>
      <span class="qty-val">{{ item.is_weight_based ? item.quantity + ' g' : item.quantity }}</span>
      <button @click="emit('increase', item)">+</button>
    </div>
  </div>
</template>

<style scoped>
/* Pill de modificador — base */
.mod-pill {
  display: inline-flex;
  align-items: center;
  font-size: 11px;
  font-weight: 600;
  padding: 2px 7px;
  border-radius: 99px;
  margin: 2px 3px 2px 0;
  white-space: nowrap;
  letter-spacing: 0.01em;
}
.mod-pill--size    { background: #dbeafe; color: #1e40af; }
.mod-pill--fruit   { background: #fce7f3; color: #9d174d; }
.mod-pill--topping { background: #fef3c7; color: #92400e; }
.mod-pill--default { background: #f3f4f6; color: #374151; }

/* Pills container */
.ticket-item-pills {
  display: flex;
  flex-wrap: wrap;
  margin-top: 4px;
}

/* Info column */
.ticket-item-info {
  flex: 1;
  min-width: 0;
}

/* Price + edit column */
.ticket-item-price-col {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
  flex-shrink: 0;
}

/* Edit button */
.btn-edit-item {
  font-size: 13px;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  color: #2563eb;
  border-radius: 6px;
  padding: 2px 6px;
  cursor: pointer;
  line-height: 1.4;
  transition: background 0.15s, transform 0.1s;
  opacity: 0.75;
}
.btn-edit-item:hover {
  background: #dbeafe;
  transform: scale(1.08);
  opacity: 1;
}
.ticket-item:hover .btn-edit-item {
  opacity: 1;
}

/* Badges inline / Interactive Destination Chip */
.item-dest-chip {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 10.5px;
  font-weight: 700;
  padding: 1px 7px;
  border-radius: 99px;
  margin-left: 6px;
  border: 1px solid transparent;
  cursor: pointer;
  vertical-align: middle;
  transition: all 0.15s ease;
  font-family: inherit;
}

.item-dest-chip:hover {
  transform: translateY(-1px);
  filter: brightness(0.95);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
}

.item-dest-chip--takeaway {
  background: #ffedd5;
  color: #c2410c;
  border-color: #fed7aa;
}

.item-dest-chip--dinein {
  background: #f1f5f9;
  color: #475569;
  border-color: #e2e8f0;
}

.dest-chip-icon {
  font-size: 11px;
  line-height: 1;
}

:global(html.dark) .item-dest-chip--takeaway {
  background: rgba(234, 88, 12, 0.2);
  color: #fb923c;
  border-color: rgba(249, 115, 22, 0.35);
}

:global(html.dark) .item-dest-chip--dinein {
  background: #231b30;
  color: #cbd5e1;
  border-color: rgba(255, 255, 255, 0.12);
}

.ticket-item-note    { color: var(--passion-600); font-weight: 600; }
.ticket-item-allergen { color: var(--warning-600, #b45309); font-weight: 600; }
</style>
