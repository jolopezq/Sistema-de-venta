<script setup>
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';
import { useCatalogStore } from '../stores/catalog';

const catalog = useCatalogStore();

const props = defineProps({
  show: Boolean,
  product: Object,
  /**
   * Cuando se abre en modo edición, contiene la configuración actual del
   * ítem del carrito: { cartKey, modifiers, itemNote, allergenFlags, isTakeaway }
   */
  initialData: { type: Object, default: null }
});

/** true cuando se está editando un ítem ya existente en el carrito */
const isEditMode = computed(() => !!props.initialData?.cartKey);

const emit = defineEmits(['close', 'confirm']);

// Store selected options: { [groupId]: [optionId1, optionId2, ...] }
const selections = ref({});
const errorMessages = ref({});
const itemNote = ref('');
const selectedAllergens = ref([]);
const isTakeaway = ref(false);

const isOptionSelected = (groupId, optionId) => {
  return (selections.value[groupId] || []).includes(optionId);
};

const getGroupSelectedCount = (groupId) => {
  return (selections.value[groupId] || []).length;
};

watch(() => props.show, (newVal) => {
  if (newVal && props.product) {
    errorMessages.value = {};

    if (props.initialData) {
      // --- MODO EDICIÓN: precarga la configuración existente del carrito ---
      itemNote.value = props.initialData.itemNote || '';
      selectedAllergens.value = [...(props.initialData.allergenFlags || [])];
      isTakeaway.value = props.initialData.isTakeaway || false;

      // Reconstruye el array de selecciones por groupId deduplicando option_ids guardados
      const preloaded = {};
      if (filteredGroups.value) {
        filteredGroups.value.forEach(og => {
          const selectedIds = [...new Set(
            (props.initialData.modifiers || [])
              .filter(m => og.options.some(o => o.id === m.option_id))
              .map(m => m.option_id)
          )];
          preloaded[og.id] = selectedIds;
        });
      }
      selections.value = preloaded;
    } else {
      // --- MODO CREACIÓN: estado inicial limpio con defaults ---
      const initialSelections = {};
      itemNote.value = '';
      selectedAllergens.value = [];
      isTakeaway.value = false;
      if (filteredGroups.value) {
        filteredGroups.value.forEach(og => {
          const isFruitGroup = (og.name || '').toLowerCase().includes('fruta');
          const defaultOptions = !isFruitGroup
            ? og.options.filter(o => o.is_default).map(o => o.id).slice(0, og.max_selections)
            : [];
          initialSelections[og.id] = defaultOptions;
        });
      }
      selections.value = initialSelections;
    }
  }
});

const filteredGroups = computed(() => {
  if (!props.product?.option_groups) return [];
  return props.product.option_groups
    .filter(og => og.is_active)
    .map(og => ({
      ...og,
      options: [...og.options]
        .filter(opt => opt.is_active && !(props.product?.excluded_options || []).includes(opt.id))
        .sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0))
    }));
});

const singleSelectGroups = computed(() =>
  filteredGroups.value.filter(og => og.max_selections === 1)
);
const multiSelectGroups = computed(() =>
  filteredGroups.value.filter(og => og.max_selections > 1)
);

const getGroupIcon = (name) => {
  const lower = (name || '').toLowerCase();
  if (lower.includes('tamaño') || lower.includes('size')) return '🥣';
  if (lower.includes('fruta')) return '🍓';
  if (lower.includes('topping')) return '🍫';
  if (lower.includes('salsa') || lower.includes('sirope')) return '🍯';
  return '✨';
};

const handleSingleSelect = (og, opt) => {
  if (!isOptionInStock(opt)) return;
  const currentList = selections.value[og.id] || [];
  if (currentList.includes(opt.id)) {
    if ((og.min_selections || 0) === 0) {
      selections.value = {
        ...selections.value,
        [og.id]: []
      };
    }
  } else {
    selections.value = {
      ...selections.value,
      [og.id]: [opt.id]
    };
  }
  validateGroup(og.id);
};

const toggleMultiSelect = (og, opt) => {
  if (!isOptionInStock(opt)) return;
  const currentList = selections.value[og.id] || [];
  const isSelected = currentList.includes(opt.id);

  if (isSelected) {
    // Deseleccionar
    selections.value = {
      ...selections.value,
      [og.id]: currentList.filter(id => id !== opt.id)
    };
  } else {
    // Seleccionar si no superó el límite máximo
    if (currentList.length < og.max_selections) {
      selections.value = {
        ...selections.value,
        [og.id]: [...currentList, opt.id]
      };
    }
  }
  validateGroup(og.id);
};

const getMinRequired = (og) => {
  const isFruit = (og.name || '').toLowerCase().includes('fruta');
  if (isFruit) return Math.max(og.min_selections || 0, 1);
  return og.min_selections || 0;
};

const validateGroup = (groupId) => {
  const og = filteredGroups.value.find(g => g.id === groupId);
  if (!og) return;
  const minReq = getMinRequired(og);
  const selectedCount = getGroupSelectedCount(groupId);
  errorMessages.value = {
    ...errorMessages.value,
    [groupId]: selectedCount < minReq
      ? (og.name.toLowerCase().includes('fruta') ? 'Debes seleccionar al menos 1 fruta.' : `Selecciona al menos ${minReq} opción(es).`)
      : null
  };
};

const isValid = computed(() => {
  if (!filteredGroups.value || filteredGroups.value.length === 0) return true;
  return filteredGroups.value.every(og => {
    const minReq = getMinRequired(og);
    const selectedCount = getGroupSelectedCount(og.id);
    return selectedCount >= minReq;
  });
});

const confirmButtonText = computed(() => {
  if (isEditMode.value) {
    return '✅ Actualizar en Carrito';
  }
  if (!isValid.value && filteredGroups.value) {
    for (const og of filteredGroups.value) {
      const minReq = getMinRequired(og);
      const count = getGroupSelectedCount(og.id);
      if (count < minReq) {
        if ((og.name || '').toLowerCase().includes('tamaño') || (og.name || '').toLowerCase().includes('size')) {
          return '🥣 Elige un tamaño';
        }
        if ((og.name || '').toLowerCase().includes('fruta')) {
          return '🍓 Elige al menos 1 fruta';
        }
        return `⚠️ Elige ${og.name}`;
      }
    }
  }
  return 'Agregar al Carrito';
});

const extrasPrice = computed(() => {
  let extras = 0;
  if (filteredGroups.value) {
    filteredGroups.value.forEach(og => {
      const selectedIds = selections.value[og.id] || [];
      selectedIds.forEach(optId => {
        const opt = og.options.find(o => o.id === optId);
        if (opt) {
          extras += Number(opt.additional_price) || 0;
        }
      });
    });
  }
  return extras;
});

const totalPrice = computed(() => {
  if (!props.product) return 0;
  return (Number(props.product.price) || 0) + extrasPrice.value;
});

const handleConfirm = () => {
  if (filteredGroups.value) {
    filteredGroups.value.forEach(og => validateGroup(og.id));
  }
  if (!isValid.value) return;

  const selectedModifiers = [];
  if (filteredGroups.value) {
    filteredGroups.value.forEach(og => {
      const selectedIds = selections.value[og.id] || [];
      selectedIds.forEach(optId => {
        const opt = og.options.find(o => o.id === optId);
        if (opt) {
          selectedModifiers.push({
            group_name: og.name,
            option_id: opt.id,
            option_name: opt.name,
            price: Number(opt.additional_price) || 0,
            quantity: 1
          });
        }
      });
    });
  }

  emit('confirm', {
    product: props.product,
    modifiers: selectedModifiers,
    finalPrice: totalPrice.value,
    itemNote: itemNote.value,
    allergenFlags: selectedAllergens.value,
    isTakeaway: isTakeaway.value,
    editingCartKey: props.initialData?.cartKey || null
  });
};

const isOptionInStock = (opt) => {
  if (!opt.recipes || opt.recipes.length === 0) return true;
  for (const recipe of opt.recipes) {
    if (recipe.quantity_delta <= 0) continue;
    const ingredient = catalog.ingredients.find(i => i.id === recipe.ingredient_id);
    if (ingredient && ingredient.current_stock < recipe.quantity_delta) return false;
  }
  return true;
};

const getSortedOptions = (og) => {
  if (!og || !og.options) return [];
  return [...og.options].sort((a, b) => {
    return (a.name || '').localeCompare(b.name || '', 'es', { sensitivity: 'base' });
  });
};

const handleGlobalKeydown = (e) => {
  if (props.show && e.key === 'Enter') {
    if (e.target?.tagName?.toLowerCase() === 'textarea') return;
    e.preventDefault();
    if (isValid.value) handleConfirm();
  }
};

onMounted(() => window.addEventListener('keydown', handleGlobalKeydown));
onUnmounted(() => window.removeEventListener('keydown', handleGlobalKeydown));
</script>

<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal-content">

      <!-- ── HEADER ── -->
      <div class="modal-header" :class="{ 'header-edit-mode': isEditMode }">
        <div class="header-left">
          <h3>
            <span v-if="isEditMode" class="edit-mode-prefix">✏️ Editando: </span>{{ product?.name }}
          </h3>
          <span class="base-price-badge">Base: Bs {{ Number(product?.price || 0).toFixed(2) }}</span>
          <span v-if="extrasPrice > 0" class="extras-badge">+Extras: Bs {{ extrasPrice.toFixed(2) }}</span>
          <span v-if="isEditMode" class="edit-badge">Modo Edición</span>
        </div>
        <div class="header-right">
          <div class="total-chip">
            <span class="total-label">Total</span>
            <span class="total-value">Bs {{ totalPrice.toFixed(2) }}</span>
          </div>
          <button class="close-btn" @click="$emit('close')" aria-label="Cerrar">×</button>
        </div>
      </div>

      <!-- ── BODY ── -->
      <div class="modal-body">

        <!-- BLOQUE 1: TAMAÑOS (Selección Única) -->
        <div v-for="og in singleSelectGroups" :key="og.id" class="section-block size-block">
          <div class="section-header">
            <div class="title-with-icon">
              <span class="group-icon">{{ getGroupIcon(og.name) }}</span>
              <span class="section-title">{{ og.name }}</span>
            </div>
            <span class="section-badge" :class="{ 'badge-required': og.min_selections > 0 }">
              {{ og.min_selections > 0 ? 'Obligatorio' : 'Opcional (Elige 1)' }}
            </span>
            <span v-if="errorMessages[og.id]" class="error-inline">{{ errorMessages[og.id] }}</span>
          </div>

          <div class="size-row">
            <button
              v-for="opt in og.options"
              :key="opt.id"
              class="size-btn"
              :class="{
                selected: isOptionSelected(og.id, opt.id),
                'out-of-stock': !isOptionInStock(opt)
              }"
              :disabled="!isOptionInStock(opt)"
              @click="handleSingleSelect(og, opt)"
            >
              <div class="size-btn-content">
                <div class="size-radio-indicator">
                  <span v-if="isOptionSelected(og.id, opt.id)" class="radio-check">✓</span>
                </div>
                <span class="size-name">{{ opt.name }}</span>
              </div>
              <span class="size-price-badge" v-if="opt.additional_price > 0">+Bs {{ Number(opt.additional_price).toFixed(2) }}</span>
              <span class="size-included-badge" v-else>Incluido</span>
              <span class="size-agotado-badge" v-if="!isOptionInStock(opt)">Agotado</span>
            </button>
          </div>
        </div>

        <!-- BLOQUES MULTI-SELECT: Frutas y Toppings -->
        <div v-for="og in multiSelectGroups" :key="og.id" class="section-block">
          <div class="section-header">
            <div class="title-with-icon">
              <span class="group-icon">{{ getGroupIcon(og.name) }}</span>
              <span class="section-title">{{ og.name }}</span>
            </div>

            <!-- Regla / Instrucción destacada -->
            <span class="rule-hint" :class="{ 'rule-hint--required': getMinRequired(og) > 0 }">
              {{ getMinRequired(og) > 0 ? `Mín. ${getMinRequired(og)} • ` : '' }}Máx. {{ og.max_selections }}
            </span>

            <!-- Contador dinámico interactivo -->
            <div
              class="selection-counter-badge"
              :class="{
                'is-empty': getGroupSelectedCount(og.id) === 0 && getMinRequired(og) === 0,
                'is-required-empty': getGroupSelectedCount(og.id) === 0 && getMinRequired(og) > 0,
                'is-active': getGroupSelectedCount(og.id) > 0 && getGroupSelectedCount(og.id) < og.max_selections,
                'is-max': getGroupSelectedCount(og.id) >= og.max_selections
              }"
            >
              <span v-if="getGroupSelectedCount(og.id) >= og.max_selections" class="counter-check">✓</span>
              <span v-else-if="getMinRequired(og) > 0 && getGroupSelectedCount(og.id) < getMinRequired(og)" class="counter-alert">⚠️</span>
              <span class="counter-text">
                <template v-if="getMinRequired(og) > 0 && getGroupSelectedCount(og.id) < getMinRequired(og)">
                  Elige al menos {{ getMinRequired(og) }}
                </template>
                <template v-else>
                  {{ getGroupSelectedCount(og.id) }} de {{ og.max_selections }} seleccionados
                </template>
              </span>
              <span v-if="getGroupSelectedCount(og.id) >= og.max_selections" class="counter-max-tag">Límite alcanzado</span>
            </div>

            <span v-if="errorMessages[og.id]" class="error-inline">{{ errorMessages[og.id] }}</span>
          </div>

          <!-- Grid de chips de opciones (Alta Densidad 3 Columnas) -->
          <div class="options-grid">
            <button
              type="button"
              v-for="opt in getSortedOptions(og)"
              :key="opt.id"
              class="option-chip"
              :class="{
                selected: isOptionSelected(og.id, opt.id),
                'out-of-stock': !isOptionInStock(opt),
                'limit-reached': !isOptionSelected(og.id, opt.id) && getGroupSelectedCount(og.id) >= og.max_selections && isOptionInStock(opt)
              }"
              :disabled="!isOptionInStock(opt) || (!isOptionSelected(og.id, opt.id) && getGroupSelectedCount(og.id) >= og.max_selections)"
              @click="toggleMultiSelect(og, opt)"
            >
              <div class="option-chip-content">
                <span class="chip-indicator" :class="{ 'is-selected': isOptionSelected(og.id, opt.id) }">
                  <span v-if="isOptionSelected(og.id, opt.id)" class="indicator-check">✓</span>
                  <span v-else class="indicator-plus">+</span>
                </span>

                <span class="chip-name" :title="opt.name">{{ opt.name }}</span>
              </div>

              <!-- Precio adicional si tiene -->
              <span
                class="chip-price"
                v-if="opt.additional_price > 0"
              >
                +Bs {{ Number(opt.additional_price).toFixed(2) }}
              </span>
            </button>
          </div>
        </div>

      </div>

      <!-- ── FOOTER UNIFICADO (Nota + Para llevar + Botón en 1 sola fila) ── -->
      <div class="modal-footer">
        <div class="footer-left-group">
          <!-- Nota Especial -->
          <div class="footer-note-box">
            <span class="footer-note-icon">📝</span>
            <input
              v-model="itemNote"
              type="text"
              placeholder="Nota especial (ej. sin fresa, leche deslactosada...)"
              class="footer-note-input"
            />
          </div>

          <!-- Selector de Destino: Para Mesa vs Para Llevar -->
          <div class="destination-segmented-control" role="group" aria-label="Destino del producto">
            <button
              type="button"
              class="dest-segment-btn"
              :class="{ active: !isTakeaway }"
              @click="isTakeaway = false"
              title="Consumir en mesa / local"
            >
              <span class="segment-icon">🍽️</span>
              <span class="segment-text">Para mesa</span>
              <span class="segment-check" v-if="!isTakeaway">✓</span>
            </button>
            <button
              type="button"
              class="dest-segment-btn dest-segment-btn--takeaway"
              :class="{ active: isTakeaway }"
              @click="isTakeaway = true"
              title="Empacar para llevar"
            >
              <span class="segment-icon">🛍️</span>
              <span class="segment-text">Para llevar</span>
              <span class="segment-check" v-if="isTakeaway">✓</span>
            </button>
          </div>
        </div>

        <button class="btn-confirm" :class="{ 'btn-confirm--edit': isEditMode }" :disabled="!isValid" @click="handleConfirm">
          {{ confirmButtonText }} • Bs {{ totalPrice.toFixed(2) }}
        </button>
      </div>

    </div>
  </div>
</template>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(5px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 16px;
}

.modal-content {
  background: var(--surface);
  border-radius: 20px;
  width: 100%;
  max-width: 1060px;
  max-height: 94vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 24px 64px rgba(0, 0, 0, 0.3);
  overflow: hidden;
  border: 1px solid var(--border);
}

/* ── HEADER ── */
.modal-header {
  padding: 14px 22px;
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: var(--surface);
  flex-shrink: 0;
}
.header-left {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.modal-header h3 {
  margin: 0;
  font-size: 18px;
  font-weight: 800;
  color: var(--ink-900);
}
.base-price-badge {
  background: var(--surface-hover);
  color: var(--ink-700);
  font-size: 12px;
  font-weight: 700;
  padding: 4px 10px;
  border-radius: 20px;
  border: 1px solid var(--border);
}
.extras-badge {
  background: #fff3e0;
  color: #e65100;
  font-size: 12px;
  font-weight: 800;
  padding: 4px 10px;
  border-radius: 20px;
  border: 1px solid #ffe0b2;
}
.header-right {
  display: flex;
  align-items: center;
  gap: 10px;
}
.total-chip {
  display: flex;
  align-items: center;
  gap: 6px;
  background: var(--acai-900, #20112F);
  border-radius: 20px;
  padding: 6px 14px;
  box-shadow: 0 2px 8px rgba(32, 17, 47, 0.35);
  transition: transform 0.2s;
}
.total-label { font-size: 11px; color: rgba(255,255,255,0.75); font-weight: 700; text-transform: uppercase; }
.total-value { font-size: 15px; font-weight: 900; color: #ffffff; }
.close-btn {
  background: var(--surface-hover);
  border: 1px solid var(--border);
  width: 32px; height: 32px;
  border-radius: 50%;
  font-size: 20px;
  cursor: pointer;
  color: var(--ink-500);
  display: flex; align-items: center; justify-content: center;
  transition: all 0.15s;
}
.close-btn:hover { background: var(--danger-100); color: var(--danger-600); border-color: var(--danger-300); }

/* ── BODY ── */
.modal-body {
  padding: 14px 22px;
  overflow-y: auto;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

/* ── SECCIÓN BLOQUE ── */
.section-block {
  background: var(--surface-hover);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 12px 14px;
  transition: border-color 0.2s;
}
.section-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}
.title-with-icon {
  display: flex;
  align-items: center;
  gap: 6px;
}
.group-icon {
  font-size: 15px;
}
.section-title {
  font-size: 13px;
  font-weight: 800;
  color: var(--ink-900);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.rule-hint {
  font-size: 11px;
  font-weight: 700;
  color: var(--ink-500);
  background: var(--surface);
  border: 1px solid var(--border);
  padding: 2px 8px;
  border-radius: 12px;
}

/* ── CONTADOR DE SELECCIÓN RESALTADO ── */
.selection-counter-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-left: auto;
  font-size: 11px;
  font-weight: 800;
  padding: 3px 10px;
  border-radius: 20px;
  transition: all 0.25s ease;
}
.selection-counter-badge.is-empty {
  background: var(--surface);
  color: var(--ink-500);
  border: 1px solid var(--border);
}
.selection-counter-badge.is-active {
  background: #fff3e0;
  color: #e65100;
  border: 1px solid #ffb74d;
  box-shadow: 0 2px 6px rgba(230, 81, 0, 0.15);
}
.selection-counter-badge.is-max {
  background: var(--lime-100, #E3F5E5);
  color: var(--lime-700, #217A2E);
  border: 1px solid var(--lime-500, #3CAE49);
  box-shadow: 0 2px 8px rgba(33, 122, 46, 0.15);
}
.counter-check {
  font-size: 11px;
  font-weight: 900;
}
.counter-max-tag {
  background: var(--lime-600, #2E8E3B);
  color: white;
  padding: 1px 6px;
  border-radius: 8px;
  font-size: 9px;
  text-transform: uppercase;
  margin-left: 4px;
}

.section-badge {
  font-size: 10px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 20px;
  background: var(--ink-100);
  color: var(--ink-600);
  border: 1px solid var(--border);
}
.section-badge.badge-required {
  background: var(--warning-100);
  color: var(--warning-800);
  border-color: var(--warning-400);
}
.error-inline {
  font-size: 11px;
  color: var(--danger-600);
  font-weight: 600;
}

/* ── TAMAÑOS (Chips Horizontales Inline) ── */
.size-row {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  padding: 2px 0;
}

.size-btn {
  flex: 1;
  min-width: 140px;
  min-height: 42px;
  display: inline-flex;
  flex-direction: row;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 7px 14px;
  border: 1.5px solid var(--border, #e2e8f0);
  border-radius: 999px;
  background: var(--surface, #ffffff);
  cursor: pointer;
  transition: transform 0.15s ease,
              border-color 0.15s ease,
              background-color 0.15s ease,
              box-shadow 0.15s ease;
  font-family: inherit;
  position: relative;
  outline: none;
  -webkit-tap-highlight-color: transparent;
  user-select: none;
  box-sizing: border-box;
}

.size-btn:focus {
  outline: none;
}

.size-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 2px var(--passion-400, #FF9640);
}

.size-btn-content {
  display: flex;
  align-items: center;
  gap: 8px;
}

.size-radio-indicator {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  border: 2px solid var(--border, #E4E0DC);
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--surface, #ffffff);
  transition: all 0.15s ease;
  flex-shrink: 0;
  box-sizing: border-box;
}

.size-btn.selected .size-radio-indicator {
  border-color: var(--passion-500, #FB7810);
  background: var(--passion-500, #FB7810);
}

.radio-check {
  font-size: 11px;
  font-weight: 900;
  color: #ffffff;
  line-height: 1;
}

.size-name {
  font-size: 13.5px;
  font-weight: 700;
  color: var(--ink-900);
  white-space: nowrap;
}

.size-price-badge {
  font-size: 11.5px;
  font-weight: 800;
  padding: 2px 8px;
  border-radius: 999px;
  background: rgba(251, 120, 16, 0.12);
  color: var(--passion-600);
  white-space: nowrap;
  transition: all 0.15s ease;
}

.size-included-badge {
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--cream-200, #f1efec);
  color: var(--ink-500);
  white-space: nowrap;
}

.size-agotado-badge {
  font-size: 10px;
  color: var(--danger-600);
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 6px;
  background: var(--danger-100);
}

/* Hover no seleccionado */
.size-btn:hover:not(:disabled):not(.selected) {
  border-color: var(--passion-400, #FF9640);
  background: #fffaf8;
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(251, 120, 16, 0.08);
}

/* Seleccionado */
.size-btn.selected {
  border: 2px solid var(--passion-500, #FB7810);
  background: #fff7f5;
  box-shadow: 0 0 0 1px var(--passion-500, #FB7810), 0 2px 8px rgba(251, 120, 16, 0.15);
}

.size-btn.selected .size-price-badge {
  background: var(--passion-500, #FB7810);
  color: #ffffff;
}

.size-btn.selected .size-name {
  color: var(--passion-700, #C24D00);
}

.size-btn.selected:hover:not(:disabled) {
  border-color: var(--passion-600, #E56506);
  background: #ffede8;
}

.size-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
  filter: grayscale(1);
  transform: none !important;
  box-shadow: none !important;
}

:global(html.dark) .size-btn {
  background: #251c33;
  border-color: rgba(255, 255, 255, 0.12);
}
:global(html.dark) .size-btn:hover:not(:disabled):not(.selected) {
  background: #322545;
  border-color: var(--passion-400);
}
:global(html.dark) .size-btn.selected {
  background: rgba(255, 150, 64, 0.15);
  border-color: var(--passion-500);
}
:global(html.dark) .size-btn.selected .size-name {
  color: #ffffff;
}
:global(html.dark) .size-included-badge {
  background: rgba(255, 255, 255, 0.08);
  color: var(--ink-500);
}

/* ── CHIPS MULTI-SELECT (Frutas / Toppings - Grid 3 Columnas) ── */
.options-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px 10px;
}

.option-chip {
  width: 100%;
  min-height: 40px;
  display: flex;
  flex-direction: row;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  padding: 6px 10px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  color: var(--ink-900);
  cursor: pointer;
  font-family: inherit;
  transition: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
  user-select: none;
  box-sizing: border-box;
  outline: none;
}

.option-chip:focus {
  outline: none;
}

.option-chip:focus-visible {
  outline: none;
  box-shadow: 0 0 0 2px var(--acai-500, #7448A6);
}

.option-chip-content {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  flex: 1;
}

.chip-indicator {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 900;
  background: var(--surface-hover, #f1efec);
  color: var(--ink-600);
  transition: all 0.15s ease;
  flex-shrink: 0;
}

.indicator-plus {
  font-size: 13px;
  line-height: 1;
}

.indicator-check {
  font-size: 11px;
  line-height: 1;
  font-weight: 900;
}

.chip-name {
  font-size: 12.5px;
  font-weight: 600;
  color: var(--ink-900);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-align: left;
}

.chip-price {
  font-size: 10.5px;
  font-weight: 800;
  color: var(--passion-600);
  background: rgba(251, 120, 16, 0.1);
  padding: 2px 5px;
  border-radius: 6px;
  white-space: nowrap;
  flex-shrink: 0;
}

/* Hover en chips NO seleccionados */
.option-chip:hover:not(:disabled):not(.selected) {
  border-color: var(--acai-300, #A886C6);
  background: var(--acai-50, #F4F0F9);
  color: var(--ink-900);
  transform: translateY(-1px);
  box-shadow: 0 2px 6px rgba(32, 17, 47, 0.08);
}
.option-chip:hover:not(:disabled):not(.selected) .chip-name {
  color: var(--ink-900);
}
.option-chip:hover:not(:disabled):not(.selected) .chip-price {
  color: var(--passion-600, #E56506);
  background: rgba(251, 120, 16, 0.12);
}

/* Estado Seleccionado Remarcado (Açaí Profundo #20112F con Acento Naranja) */
.option-chip.selected {
  border-color: var(--acai-700, #522B80);
  background: var(--acai-900, #20112F);
  color: #ffffff !important;
  transform: translateY(-1px);
  box-shadow: 0 3px 10px rgba(32, 17, 47, 0.35);
}
.option-chip.selected .chip-indicator {
  background: #ffffff;
  color: var(--acai-900, #20112F);
}
.option-chip.selected .chip-name {
  color: #ffffff !important;
  font-weight: 700;
}
.option-chip.selected .chip-price {
  color: #ffffff !important;
  background: var(--passion-500, #FB7810);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
}

/* Hover en chips YA seleccionados */
.option-chip.selected:hover:not(:disabled) {
  border-color: var(--acai-500, #7448A6);
  background: var(--acai-800, #2C1841) !important;
  color: #ffffff !important;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(32, 17, 47, 0.45);
}
.option-chip.selected:hover:not(:disabled) .chip-name {
  color: #ffffff !important;
}

/* Deshabilitados por límite o sin stock */
.option-chip:disabled {
  cursor: not-allowed;
  transform: none !important;
  box-shadow: none !important;
}
.option-chip.out-of-stock {
  opacity: 0.35;
  filter: grayscale(1);
  background: var(--ink-100);
}
.option-chip.limit-reached {
  opacity: 0.4;
  border-style: dashed;
}

:global(html.dark) .option-chip {
  background: #251c33;
  border-color: rgba(255, 255, 255, 0.12);
  color: var(--ink-900);
}
:global(html.dark) .option-chip:hover:not(:disabled):not(.selected) {
  background: #322545;
  border-color: var(--acai-300, #A886C6);
}
:global(html.dark) .option-chip.selected {
  background: var(--acai-700, #522B80);
  border-color: var(--acai-500, #7448A6);
}
:global(html.dark) .option-chip.selected .chip-indicator {
  background: #ffffff;
  color: var(--acai-900, #20112F);
}
:global(html.dark) .option-chip.selected:hover:not(:disabled) {
  background: var(--acai-800, #2C1841) !important;
  border-color: var(--acai-500, #7448A6);
}
:global(html.dark) .option-chip-content .chip-name {
  color: #ffffff;
}
:global(html.dark) .chip-indicator {
  background: rgba(255, 255, 255, 0.08);
  color: #cbd5e1;
}

/* ── FOOTER UNIFICADO EN UNA SOLA FILA ── */
.modal-footer {
  padding: 10px 18px;
  border-top: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  background: var(--surface);
  border-radius: 0 0 20px 20px;
  flex-shrink: 0;
}

.footer-left-group {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 1;
  min-width: 0;
}

.footer-note-box {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  min-width: 160px;
  height: 42px;
  background: var(--surface-hover, #f8fafc);
  border: 1.5px solid var(--border);
  border-radius: 12px;
  padding: 0 12px;
  transition: all 0.15s ease;
  box-sizing: border-box;
}

.footer-note-box:focus-within {
  border-color: var(--passion-500);
  background: #ffffff;
  box-shadow: 0 0 0 2px rgba(251, 120, 16, 0.12);
}

.footer-note-icon {
  font-size: 14px;
  flex-shrink: 0;
}

.footer-note-input {
  border: none;
  background: transparent;
  outline: none;
  width: 100%;
  font-size: 12.5px;
  font-family: inherit;
  color: var(--ink-900);
}

.footer-note-input::placeholder {
  color: var(--ink-400, #94a3b8);
}

.destination-segmented-control {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  height: 42px;
  padding: 3px;
  border-radius: 12px;
  border: 1.5px solid var(--border);
  background: var(--surface-hover, #f1f5f9);
  box-sizing: border-box;
  flex-shrink: 0;
}

.dest-segment-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 100%;
  padding: 0 12px;
  border-radius: 9px;
  border: 1px solid transparent;
  background: transparent;
  cursor: pointer;
  font-family: inherit;
  font-size: 12px;
  font-weight: 700;
  color: var(--ink-600, #64748b);
  white-space: nowrap;
  transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
  user-select: none;
}

.dest-segment-btn:hover:not(.active) {
  color: var(--ink-900, #0f172a);
  background: rgba(0, 0, 0, 0.04);
}

.dest-segment-btn.active {
  background: #ffffff;
  border-color: rgba(0, 0, 0, 0.08);
  color: var(--acai-800, #2C1841);
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
}

.dest-segment-btn--takeaway.active {
  background: #ffffff;
  border-color: rgba(249, 115, 22, 0.3);
  color: #ea580c;
  box-shadow: 0 2px 6px rgba(234, 88, 12, 0.15);
}

.segment-icon {
  font-size: 13px;
  line-height: 1;
}

.segment-check {
  font-size: 10px;
  font-weight: 900;
  background: var(--acai-700, #522B80);
  color: #ffffff;
  border-radius: 50%;
  width: 15px;
  height: 15px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
}

.dest-segment-btn--takeaway.active .segment-check {
  background: #ea580c;
}

.btn-confirm {
  height: 42px;
  padding: 0 24px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: var(--passion-500);
  color: white;
  border: none;
  border-radius: 12px;
  font-weight: 800;
  font-size: 14px;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.18s ease;
  box-shadow: 0 3px 10px rgba(230, 78, 57, 0.25);
  white-space: nowrap;
  flex-shrink: 0;
}

:global(html.dark) .footer-note-box {
  background: #251c33;
  border-color: rgba(255, 255, 255, 0.12);
}
:global(html.dark) .footer-note-box:focus-within {
  background: #2d223d;
  border-color: var(--passion-500);
}
:global(html.dark) .footer-note-input {
  color: #ffffff;
}
:global(html.dark) .destination-segmented-control {
  background: #1f172b;
  border-color: rgba(255, 255, 255, 0.12);
}
:global(html.dark) .dest-segment-btn {
  color: #94a3b8;
}
:global(html.dark) .dest-segment-btn:hover:not(.active) {
  color: #ffffff;
  background: rgba(255, 255, 255, 0.06);
}
:global(html.dark) .dest-segment-btn.active {
  background: #2f2342;
  border-color: rgba(255, 255, 255, 0.18);
  color: #ffffff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
}
:global(html.dark) .dest-segment-btn--takeaway.active {
  background: rgba(234, 88, 12, 0.22);
  border-color: rgba(249, 115, 22, 0.4);
  color: #fb923c;
}
.btn-confirm:hover:not(:disabled) {
  filter: brightness(1.08);
  transform: translateY(-1px);
  box-shadow: 0 6px 20px rgba(230, 78, 57, 0.4);
}
.btn-confirm:active:not(:disabled) { transform: translateY(0); }
.btn-confirm:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; filter: grayscale(0.5); }

.is-required-empty {
  background: #fff1f2 !important;
  color: #e11d48 !important;
  border-color: #fecdd3 !important;
  font-weight: 700;
}
.rule-hint--required {
  color: #e11d48;
  font-weight: 700;
}
.counter-alert {
  font-size: 11px;
}

/* ── RESPONSIVE COMPACT (1366x768 & Laptops) ── */
@media (max-width: 1366px), (max-height: 800px) {
  .modal-content {
    max-width: 960px;
    max-height: 92vh;
    border-radius: 16px;
  }
  .modal-header {
    padding: 10px 16px;
  }
  .modal-header h3 {
    font-size: 16px;
  }
  .modal-body {
    padding: 10px 16px;
    gap: 8px;
  }
  .section-block {
    padding: 8px 12px;
    border-radius: 10px;
  }
  .section-header {
    margin-bottom: 6px;
    gap: 6px;
  }
  .section-title {
    font-size: 12px;
  }
  .size-btn {
    min-height: 38px;
    padding: 5px 12px;
    gap: 8px;
  }
  .size-name {
    font-size: 12.5px;
  }
  .size-price-badge {
    font-size: 10.5px;
    padding: 1px 6px;
  }
  .options-grid {
    grid-template-columns: repeat(3, 1fr);
    gap: 6px 8px;
  }
  .option-chip {
    min-height: 36px;
    padding: 4px 7px;
    gap: 5px;
    border-radius: 8px;
  }
  .chip-name {
    font-size: 12px;
  }
  .chip-price {
    font-size: 10px;
    padding: 1px 5px;
  }
  .modal-footer {
    padding: 8px 16px;
    border-radius: 0 0 16px 16px;
  }
  .footer-note-box,
  .destination-segmented-control,
  .btn-confirm {
    height: 38px;
  }
  .btn-confirm {
    padding: 0 20px;
    font-size: 13.5px;
  }
}

@media (max-width: 768px) {
  .options-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .modal-footer {
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
  }
  .footer-left-group {
    flex-direction: column;
    align-items: stretch;
  }
  .btn-confirm {
    width: 100%;
  }
}

@media (max-width: 640px) {
  .modal-overlay { padding: 0; }
  .modal-content { max-width: 100%; max-height: 100vh; border-radius: 0; }
  .size-row { flex-wrap: wrap; }
  .modal-header, .modal-body, .modal-footer { padding: 12px 14px; }
}

/* ── EDIT MODE ── */
.header-edit-mode {
  border-bottom: 2px solid #3b82f6;
  background: linear-gradient(to right, #eff6ff, transparent);
}
.edit-mode-prefix {
  color: #2563eb;
  font-size: 0.9em;
}
.edit-badge {
  display: inline-block;
  background: #dbeafe;
  color: #1e40af;
  font-size: 10px;
  font-weight: 800;
  padding: 2px 8px;
  border-radius: 99px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-left: 8px;
  vertical-align: middle;
  border: 1px solid #93c5fd;
}
.btn-confirm--edit {
  background: linear-gradient(135deg, #16a34a, #15803d) !important;
  box-shadow: 0 4px 14px rgba(22, 163, 74, 0.35) !important;
}
.btn-confirm--edit:hover:not(:disabled) {
  box-shadow: 0 6px 20px rgba(22, 163, 74, 0.45) !important;
}
</style>
