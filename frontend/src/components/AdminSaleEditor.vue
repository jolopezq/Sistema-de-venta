<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useCatalogStore } from '../stores/catalog';
import { useTurnoStore } from '../stores/turno';
import { apiFetch } from '../services/api';

const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  sale: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['close', 'saved']);

const catalogStore = useCatalogStore();
const turnoStore = useTurnoStore();

const isEditMode = computed(() => !!props.sale && !!props.sale.id);

const usersList = ref([]);
const isSaving = ref(false);
const errorMessage = ref('');
const successMessage = ref('');

// Filtros para selector rápido de productos
const selectedCategoryId = ref('');
const productSearchQuery = ref('');
const showProductPicker = ref(false);

// Form state
const formData = ref({
  created_at: '',
  cashier_id: '',
  customer_id: '',
  is_takeaway: false,
  notes: '',
  edit_reason: '',
  discount_amount: 0,
  items: [],
  payments: [
    { method: 'cash', amount: 0 }
  ]
});

function formatForDatetimeLocal(isoStr) {
  if (!isoStr) {
    const now = new Date();
    const tzOffset = now.getTimezoneOffset() * 60000;
    return new Date(now.getTime() - tzOffset).toISOString().slice(0, 16);
  }
  const d = new Date(isoStr);
  const tzOffset = d.getTimezoneOffset() * 60000;
  return new Date(d.getTime() - tzOffset).toISOString().slice(0, 16);
}

async function loadUsers() {
  try {
    const res = await apiFetch('/users');
    usersList.value = res.data || res || [];
  } catch (err) {
    console.warn('Error cargando lista de usuarios:', err);
  }
}

// Helpers de productos y grupos de opciones
function getProduct(productId) {
  return catalogStore.products.find(p => p.id === productId);
}

function getProductOptionGroups(product) {
  if (!product || !product.option_groups) return [];
  return product.option_groups
    .filter(og => og.is_active)
    .map(og => ({
      ...og,
      options: (og.options || [])
        .filter(opt => opt.is_active && !(product.excluded_options || []).includes(opt.id))
        .sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0))
    }));
}

function getGroupIcon(name) {
  const lower = (name || '').toLowerCase();
  if (lower.includes('tamaño') || lower.includes('size')) return '🥣';
  if (lower.includes('fruta')) return '🍓';
  if (lower.includes('topping')) return '🍫';
  if (lower.includes('salsa') || lower.includes('sirope')) return '🍯';
  return '✨';
}

function isOptionSelected(item, groupId, optionId) {
  return (item.selectedOptionIds[groupId] || []).includes(optionId);
}

function getGroupSelectedCount(item, groupId) {
  return (item.selectedOptionIds[groupId] || []).length;
}

function recalcItemPricesAndModifiers(item) {
  const p = getProduct(item.product_id);
  const basePrice = Number(p?.price || 0);
  let extrasPrice = 0;
  const modifiers = [];

  const groups = getProductOptionGroups(p);
  groups.forEach(og => {
    const selectedIds = item.selectedOptionIds[og.id] || [];
    selectedIds.forEach(optId => {
      const opt = og.options.find(o => o.id === optId);
      if (opt) {
        const optPrice = Number(opt.additional_price || 0);
        extrasPrice += optPrice;
        modifiers.push({
          option_id: opt.id,
          option_name: opt.name,
          group_name: og.name,
          price: optPrice,
          quantity: 1
        });
      }
    });
  });

  // Cálculo según el modo de precio
  if (item.priceMode === 'weight') {
    const pGram = Number(item.pricePerGram || p?.price_per_gram || 0.08);
    const weightGrams = Number(item.weightGrams || 0);
    const weightCost = weightGrams * pGram;
    item.unit_price = Math.round(weightCost + extrasPrice);
  } else if (item.priceMode === 'manual') {
    // Preserva el precio que el admin ingresó directamente
    item.unit_price = Number(Number(item.unit_price || 0).toFixed(2));
  } else {
    // Modo 'auto' (Catálogo estándar)
    item.unit_price = Number((basePrice + extrasPrice).toFixed(2));
  }

  item.subtotal = Number((item.quantity * item.unit_price).toFixed(2));
  item.modifiers = modifiers;
}

function setItemPriceMode(item, mode) {
  item.priceMode = mode;
  const p = getProduct(item.product_id);
  if (mode === 'weight') {
    if (!item.pricePerGram || item.pricePerGram <= 0) {
      item.pricePerGram = Number(p?.price_per_gram || 0.08);
    }
    if (!item.weightGrams || item.weightGrams <= 0) {
      item.weightGrams = 350;
    }
  }
  recalcItemPricesAndModifiers(item);
  autoSyncPayments();
}

function onWeightGramsChange(item) {
  recalcItemPricesAndModifiers(item);
  autoSyncPayments();
}

function onManualPriceChange(item) {
  item.subtotal = Number((item.quantity * Number(item.unit_price || 0)).toFixed(2));
  autoSyncPayments();
}

function handleSingleSelect(item, og, opt) {
  const currentList = item.selectedOptionIds[og.id] || [];
  if (currentList.includes(opt.id)) {
    if ((og.min_selections || 0) === 0) {
      item.selectedOptionIds[og.id] = [];
    }
  } else {
    item.selectedOptionIds[og.id] = [opt.id];
  }
  recalcItemPricesAndModifiers(item);
  autoSyncPayments();
}

function toggleMultiSelect(item, og, opt) {
  const currentList = item.selectedOptionIds[og.id] || [];
  const isSel = currentList.includes(opt.id);

  if (isSel) {
    item.selectedOptionIds[og.id] = currentList.filter(id => id !== opt.id);
  } else {
    const maxSel = og.max_selections || 999;
    if (currentList.length < maxSel) {
      item.selectedOptionIds[og.id] = [...currentList, opt.id];
    }
  }
  recalcItemPricesAndModifiers(item);
  autoSyncPayments();
}

function initForm() {
  errorMessage.value = '';
  successMessage.value = '';
  showProductPicker.value = false;
  productSearchQuery.value = '';
  selectedCategoryId.value = '';

  if (isEditMode.value && props.sale) {
    formData.value = {
      created_at: formatForDatetimeLocal(props.sale.created_at),
      cashier_id: props.sale.cashier_id || '',
      customer_id: props.sale.customer_id || '',
      is_takeaway: Boolean(props.sale.is_takeaway),
      notes: props.sale.notes || '',
      edit_reason: '',
      discount_amount: Number(props.sale.discount_amount || 0),
      items: (props.sale.items || []).map(i => {
        const p = getProduct(i.product_id);
        const groups = getProductOptionGroups(p);
        const selectedOptionIds = {};

        // Precargar opciones existentes
        const rawOptions = i.sale_item_options || i.modifiers || [];
        groups.forEach(og => {
          const selected = [];
          rawOptions.forEach(ro => {
            const optId = ro.option_id || ro.id;
            if (og.options.some(o => o.id === optId)) {
              if (!selected.includes(optId)) selected.push(optId);
            }
          });
          selectedOptionIds[og.id] = selected;
        });

        const rawUnitPrice = Number(i.unit_price || p?.price || 0);
        const isWeight = Boolean(p?.is_weight_based);

        // Detectar si el precio fue modificado manualmente
        let autoPriceExpected = Number(p?.price || 0);
        groups.forEach(og => {
          const selected = selectedOptionIds[og.id] || [];
          selected.forEach(optId => {
            const opt = og.options.find(o => o.id === optId);
            if (opt) autoPriceExpected += Number(opt.additional_price || 0);
          });
        });

        let priceMode = 'auto';
        if (isWeight) {
          priceMode = 'weight';
        } else if (Math.abs(rawUnitPrice - autoPriceExpected) > 0.05) {
          priceMode = 'manual';
        }

        const itemObj = {
          product_id: i.product_id,
          quantity: Number(i.quantity || 1),
          priceMode,
          weightGrams: isWeight ? Number(i.quantity || 350) : 0,
          pricePerGram: Number(p?.price_per_gram || 0.08),
          unit_price: rawUnitPrice,
          subtotal: Number(i.subtotal || (Number(i.quantity || 1) * rawUnitPrice)),
          is_takeaway: Boolean(i.is_takeaway),
          item_note: i.item_note || '',
          selectedOptionIds,
          modifiers: [],
          isExpanded: true
        };

        if (priceMode !== 'manual') {
          recalcItemPricesAndModifiers(itemObj);
        } else {
          // Reconstruir modificadores para que no se pierdan
          const modifiers = [];
          groups.forEach(og => {
            const selectedIds = itemObj.selectedOptionIds[og.id] || [];
            selectedIds.forEach(optId => {
              const opt = og.options.find(o => o.id === optId);
              if (opt) {
                modifiers.push({
                  option_id: opt.id,
                  option_name: opt.name,
                  group_name: og.name,
                  price: Number(opt.additional_price || 0),
                  quantity: 1
                });
              }
            });
          });
          itemObj.modifiers = modifiers;
        }

        return itemObj;
      }),
      payments: (props.sale.payments && props.sale.payments.length > 0)
        ? props.sale.payments.map(p => ({
            method: p.method,
            amount: Number(p.amount || 0)
          }))
        : [{ method: 'cash', amount: Number(props.sale.total_amount || 0) }]
    };
  } else {
    formData.value = {
      created_at: formatForDatetimeLocal(),
      cashier_id: usersList.value[0]?.id || '',
      customer_id: '',
      is_takeaway: false,
      notes: '',
      edit_reason: '',
      discount_amount: 0,
      items: [],
      payments: [
        { method: 'cash', amount: 0 }
      ]
    };
  }
}

watch(() => props.visible, (newVal) => {
  if (newVal) {
    initForm();
  }
});

onMounted(async () => {
  await loadUsers();
  if (catalogStore.products.length === 0) {
    await catalogStore.fetchAndCache();
  }
});

// Filtrado de productos disponibles en el picker
const filteredAvailableProducts = computed(() => {
  let list = catalogStore.products.filter(p => p.is_active);
  if (selectedCategoryId.value) {
    list = list.filter(p => p.category_id === Number(selectedCategoryId.value));
  }
  if (productSearchQuery.value.trim()) {
    const q = productSearchQuery.value.toLowerCase().trim();
    list = list.filter(p => (p.name || '').toLowerCase().includes(q));
  }
  return list;
});

// Totales calculados
const calculatedSubtotal = computed(() => {
  return formData.value.items.reduce((sum, item) => sum + (Number(item.subtotal) || 0), 0);
});

const calculatedTotal = computed(() => {
  const total = calculatedSubtotal.value - Number(formData.value.discount_amount || 0);
  return Math.max(0, total);
});

const totalPayments = computed(() => {
  return formData.value.payments.reduce((sum, p) => sum + (Number(p.amount) || 0), 0);
});

const paymentDifference = computed(() => {
  return calculatedTotal.value - totalPayments.value;
});

// Manipulación de Items
function addProductToSale(product) {
  const groups = getProductOptionGroups(product);
  const selectedOptionIds = {};

  // Inicializar defaults de opciones
  groups.forEach(og => {
    const isFruitGroup = (og.name || '').toLowerCase().includes('fruta');
    const defaultOptions = !isFruitGroup
      ? og.options.filter(o => o.is_default).map(o => o.id).slice(0, og.max_selections)
      : [];
    selectedOptionIds[og.id] = defaultOptions;
  });

  const isWeight = Boolean(product.is_weight_based);
  const pricePerGram = Number(product.price_per_gram || 0.08);

  const newItem = {
    product_id: product.id,
    quantity: 1,
    priceMode: isWeight ? 'weight' : 'auto',
    weightGrams: isWeight ? 350 : 0,
    pricePerGram: pricePerGram,
    unit_price: Number(product.price || 0),
    subtotal: Number(product.price || 0),
    is_takeaway: formData.value.is_takeaway,
    item_note: '',
    selectedOptionIds,
    modifiers: [],
    isExpanded: true
  };

  recalcItemPricesAndModifiers(newItem);
  formData.value.items.push(newItem);
  autoSyncPayments();
  showProductPicker.value = false;
  productSearchQuery.value = '';
}

function onProductSelectChange(item) {
  const p = getProduct(item.product_id);
  if (!p) return;
  const groups = getProductOptionGroups(p);
  const selectedOptionIds = {};
  groups.forEach(og => {
    const isFruitGroup = (og.name || '').toLowerCase().includes('fruta');
    const defaultOptions = !isFruitGroup
      ? og.options.filter(o => o.is_default).map(o => o.id).slice(0, og.max_selections)
      : [];
    selectedOptionIds[og.id] = defaultOptions;
  });
  item.selectedOptionIds = selectedOptionIds;
  if (p.is_weight_based) {
    item.priceMode = 'weight';
    item.weightGrams = item.weightGrams || 350;
    item.pricePerGram = Number(p.price_per_gram || 0.08);
  }
  recalcItemPricesAndModifiers(item);
  autoSyncPayments();
}

function onItemQuantityChange(item) {
  if (item.quantity < 0.01) item.quantity = 1;
  item.subtotal = Number((item.quantity * item.unit_price).toFixed(2));
  autoSyncPayments();
}

function stepItemQuantity(item, delta) {
  const next = Number((item.quantity + delta).toFixed(2));
  if (next >= 1) {
    item.quantity = next;
    onItemQuantityChange(item);
  }
}

function removeItem(index) {
  formData.value.items.splice(index, 1);
  autoSyncPayments();
}

// Manipulación de Pagos
function addPayment() {
  formData.value.payments.push({ method: 'qr', amount: 0 });
}

function removePayment(index) {
  if (formData.value.payments.length > 1) {
    formData.value.payments.splice(index, 1);
  }
}

function autoSyncPayments() {
  if (formData.value.payments.length === 1) {
    formData.value.payments[0].amount = Number(calculatedTotal.value.toFixed(2));
  }
}

function quickSetPaymentMethod(method) {
  formData.value.payments = [{ method, amount: Number(calculatedTotal.value.toFixed(2)) }];
}

async function handleSave() {
  errorMessage.value = '';
  successMessage.value = '';

  if (!formData.value.created_at) {
    errorMessage.value = 'Debes ingresar una fecha y hora válida.';
    return;
  }
  if (!formData.value.items || formData.value.items.length === 0) {
    errorMessage.value = 'Debes agregar al menos un producto a la venta.';
    return;
  }
  if (!formData.value.edit_reason || formData.value.edit_reason.trim().length < 5) {
    errorMessage.value = 'El motivo/justificación es obligatorio para el registro de auditoría (mínimo 5 caracteres).';
    return;
  }
  if (Math.abs(paymentDifference.value) > 0.05) {
    errorMessage.value = `Los pagos (Bs ${totalPayments.value.toFixed(2)}) no coinciden con el total (Bs ${calculatedTotal.value.toFixed(2)}).`;
    return;
  }

  isSaving.value = true;

  const payload = {
    created_at: new Date(formData.value.created_at).toISOString(),
    cashier_id: formData.value.cashier_id ? Number(formData.value.cashier_id) : null,
    customer_id: formData.value.customer_id ? Number(formData.value.customer_id) : null,
    subtotal: calculatedSubtotal.value,
    discount_amount: Number(formData.value.discount_amount || 0),
    total_amount: calculatedTotal.value,
    is_takeaway: formData.value.is_takeaway,
    notes: formData.value.notes,
    edit_reason: formData.value.edit_reason.trim(),
    items: formData.value.items.map(item => ({
      product_id: item.product_id,
      quantity: Number(item.quantity),
      unit_price: Number(item.unit_price),
      subtotal: Number(item.subtotal),
      is_takeaway: item.is_takeaway,
      item_note: item.item_note || null,
      modifiers: item.modifiers || []
    })),
    payments: formData.value.payments.map(p => ({
      method: p.method,
      amount: Number(p.amount)
    }))
  };

  try {
    let result;
    if (isEditMode.value) {
      result = await turnoStore.adminUpdateSale(props.sale.id, payload);
      successMessage.value = `Venta #${result.order_number || props.sale.id} actualizada con éxito.`;
    } else {
      result = await turnoStore.adminCreateSale(payload);
      successMessage.value = `Venta #${result.order_number || 'creada'} registrada correctamente.`;
    }

    setTimeout(() => {
      emit('saved', result);
      emit('close');
    }, 1200);
  } catch (err) {
    console.error('Error guardando venta admin:', err);
    errorMessage.value = err.message || 'Error al procesar la venta en el servidor.';
  } finally {
    isSaving.value = false;
  }
}
</script>

<template>
  <div v-if="visible" class="modal-overlay" @click.self="$emit('close')">
    <div class="editor-modal">
      <!-- HEADER -->
      <div class="modal-header">
        <div class="header-titles">
          <span class="badge-super-admin">🔒 Modo Super Admin</span>
          <h3>{{ isEditMode ? `Editar Venta #${sale?.order_number}` : 'Registrar Venta Retroactiva / Manual' }}</h3>
        </div>
        <button class="close-btn" @click="$emit('close')" aria-label="Cerrar">✕</button>
      </div>

      <!-- AUDIT WARNING BANNER -->
      <div class="audit-warning-banner">
        <span>⚠️</span>
        <div>
          <strong>Registro con Trazabilidad:</strong> Toda modificación o creación manual recalculará stock en base a recetas y registrará un evento inmutable en los registros de auditoría.
        </div>
      </div>

      <div v-if="errorMessage" class="error-banner">
        {{ errorMessage }}
      </div>

      <div v-if="successMessage" class="success-banner">
        ✓ {{ successMessage }}
      </div>

      <!-- FORM BODY -->
      <div class="modal-body">
        <!-- SECCIÓN 1: METADATOS PRINCIPALES -->
        <div class="form-grid-3">
          <div class="form-group">
            <label><strong>📅 Fecha y Hora de Venta:</strong></label>
            <input 
              type="datetime-local" 
              v-model="formData.created_at" 
              class="form-input"
              required
            />
          </div>

          <div class="form-group">
            <label><strong>👤 Cajero que Registró:</strong></label>
            <select v-model="formData.cashier_id" class="form-input">
              <option value="">-- Mismo Super Admin --</option>
              <option v-for="u in usersList" :key="u.id" :value="u.id">
                {{ u.name }} ({{ u.role }})
              </option>
            </select>
          </div>

          <div class="form-group">
            <label><strong>🏷️ Destino General:</strong></label>
            <div class="destination-toggle">
              <button 
                type="button" 
                class="dest-btn" 
                :class="{ active: !formData.is_takeaway }"
                @click="formData.is_takeaway = false"
              >
                🍽️ En Mesa
              </button>
              <button 
                type="button" 
                class="dest-btn" 
                :class="{ active: formData.is_takeaway }"
                @click="formData.is_takeaway = true"
              >
                🛍️ Para Llevar
              </button>
            </div>
          </div>
        </div>

        <!-- SECCIÓN 2: CLIENTE Y NOTAS -->
        <div class="form-grid-2">
          <div class="form-group">
            <label>Cliente (Opcional):</label>
            <select v-model="formData.customer_id" class="form-input">
              <option value="">-- Cliente Ocasional (Sin registro) --</option>
              <option v-for="c in catalogStore.customers" :key="c.id" :value="c.id">
                {{ c.name }} {{ c.ci_or_phone ? `(${c.ci_or_phone})` : '' }}
              </option>
            </select>
          </div>

          <div class="form-group">
            <label>Notas de la Venta (Opcional):</label>
            <input 
              type="text" 
              v-model="formData.notes" 
              placeholder="Ej: Pedido manual / Talonario de contingencia" 
              class="form-input" 
            />
          </div>
        </div>

        <hr class="section-divider" />

        <!-- SECCIÓN 3: PRODUCTOS E ITEMS -->
        <div class="items-section-wrapper">
          <div class="items-section-header">
            <div>
              <h4>🛒 Productos e Ítems de la Venta</h4>
              <span class="items-count-tag">{{ formData.items.length }} {{ formData.items.length === 1 ? 'producto' : 'productos' }}</span>
            </div>
            <button 
              type="button" 
              class="btn btn-sm btn-outline-primary" 
              @click="showProductPicker = !showProductPicker"
            >
              {{ showProductPicker ? '✕ Cerrar Catálogo' : '＋ Añadir Producto' }}
            </button>
          </div>

          <!-- PANEL PICKER DE PRODUCTOS RÁPIDO -->
          <div v-if="showProductPicker" class="product-picker-panel">
            <div class="picker-filters">
              <div class="picker-search-wrap">
                <span class="search-icon">🔍</span>
                <input 
                  type="text" 
                  v-model="productSearchQuery" 
                  placeholder="Buscar producto (ej: Bowl, Jugo, Waffle)..." 
                  class="picker-search-input"
                  autofocus
                />
                <button v-if="productSearchQuery" class="clear-search-btn" @click="productSearchQuery = ''">✕</button>
              </div>

              <select v-model="selectedCategoryId" class="picker-category-select">
                <option value="">Todas las Categorías</option>
                <option v-for="cat in catalogStore.categories" :key="cat.id" :value="cat.id">
                  {{ cat.name }}
                </option>
              </select>
            </div>

            <div class="picker-products-grid">
              <button 
                type="button" 
                v-for="p in filteredAvailableProducts" 
                :key="p.id" 
                class="product-picker-card"
                @click="addProductToSale(p)"
              >
                <div class="card-info">
                  <strong class="card-name">{{ p.name }}</strong>
                  <span class="card-price">
                    {{ p.is_weight_based ? `Bs ${(Number(p.price_per_gram || 0) * 100).toFixed(2)}/100g` : `Bs ${Number(p.price).toFixed(2)}` }}
                  </span>
                </div>
                <div class="card-add-icon">＋</div>
              </button>

              <div v-if="filteredAvailableProducts.length === 0" class="no-products-found">
                No se encontraron productos coincidentes.
              </div>
            </div>
          </div>

          <!-- LISTADO DE ITEMS SELECCIONADOS -->
          <div v-if="formData.items.length === 0" class="empty-items-alert">
            <span class="empty-icon">🥣</span>
            <p>No hay productos agregados a la venta todavía.</p>
            <button type="button" class="btn btn-sm btn-primary" @click="showProductPicker = true">
              ＋ Añadir Primer Producto
            </button>
          </div>

          <div v-else class="items-cards-list">
            <div 
              v-for="(item, idx) in formData.items" 
              :key="idx" 
              class="item-card"
            >
              <!-- HEADER DE LA TARJETA DEL PRODUCTO -->
              <div class="item-card-header">
                <div class="item-header-left">
                  <span class="item-index-badge">#{{ idx + 1 }}</span>
                  <select 
                    v-model="item.product_id" 
                    @change="onProductSelectChange(item)" 
                    class="item-product-select"
                  >
                    <option v-for="p in catalogStore.products" :key="p.id" :value="p.id">
                      {{ p.name }} ({{ p.is_weight_based ? `Bs ${(Number(p.price_per_gram || 0) * 100).toFixed(2)}/100g` : `Base: Bs ${Number(p.price).toFixed(2)}` }})
                    </option>
                  </select>
                </div>

                <!-- CONTROLES DE CANTIDAD Y PRECIO -->
                <div class="item-header-right">
                  <div class="qty-stepper">
                    <button type="button" class="qty-btn" @click="stepItemQuantity(item, -1)">−</button>
                    <input 
                      type="number" 
                      min="1" 
                      step="1" 
                      v-model.number="item.quantity" 
                      @input="onItemQuantityChange(item)" 
                      class="qty-input" 
                    />
                    <button type="button" class="qty-btn" @click="stepItemQuantity(item, 1)">+</button>
                  </div>

                  <div class="item-price-tag">
                    <span class="unit-price-hint">Bs {{ item.unit_price.toFixed(2) }} c/u</span>
                    <strong class="item-subtotal-val">Bs {{ item.subtotal.toFixed(2) }}</strong>
                  </div>

                  <button 
                    type="button" 
                    class="btn-toggle-expand" 
                    @click="item.isExpanded = !item.isExpanded"
                    :title="item.isExpanded ? 'Colapsar opciones' : 'Expandir opciones'"
                  >
                    {{ item.isExpanded ? '▲' : '▼' }}
                  </button>

                  <button 
                    type="button" 
                    class="btn-remove-item" 
                    @click="removeItem(idx)" 
                    title="Eliminar este ítem"
                  >
                    🗑️
                  </button>
                </div>
              </div>

              <!-- BARRA DE MODALIDAD DE PRECIO (AUTO / PESO / MANUAL) -->
              <div class="item-price-modes-bar">
                <div class="price-mode-pills-wrap">
                  <span class="mode-bar-label">Modo Precio:</span>
                  <div class="mode-pills">
                    <button 
                      type="button" 
                      class="mode-pill-btn" 
                      :class="{ active: item.priceMode === 'auto' }"
                      @click="setItemPriceMode(item, 'auto')"
                      title="Calcula automáticamente según precio del catálogo + opciones"
                    >
                      🔒 Catálogo Auto
                    </button>
                    <button 
                      type="button" 
                      class="mode-pill-btn" 
                      :class="{ active: item.priceMode === 'weight' }"
                      @click="setItemPriceMode(item, 'weight')"
                      title="Calcula multiplicando gramos por precio/gramo"
                    >
                      ⚖️ Por Peso (g)
                    </button>
                    <button 
                      type="button" 
                      class="mode-pill-btn" 
                      :class="{ active: item.priceMode === 'manual' }"
                      @click="setItemPriceMode(item, 'manual')"
                      title="Permite escribir cualquier precio directo"
                    >
                      ✏️ Manual Libre
                    </button>
                  </div>
                </div>

                <!-- INPUTS SEGÚN EL MODO -->
                <div v-if="item.priceMode === 'weight'" class="mode-weight-inputs">
                  <div class="weight-field">
                    <label>Peso:</label>
                    <input 
                      type="number" 
                      min="1" 
                      step="10" 
                      v-model.number="item.weightGrams" 
                      @input="onWeightGramsChange(item)"
                      class="input-weight-g"
                      placeholder="Gramos"
                    />
                    <span class="unit-tag">g</span>
                  </div>
                  <div class="weight-field">
                    <label>Precio/g:</label>
                    <input 
                      type="number" 
                      min="0.001" 
                      step="0.005" 
                      v-model.number="item.pricePerGram" 
                      @input="onWeightGramsChange(item)"
                      class="input-price-per-g"
                    />
                    <span class="unit-tag">Bs/g</span>
                  </div>
                  <div class="weight-calc-hint">
                    = Bs {{ Math.round((Number(item.weightGrams) || 0) * (Number(item.pricePerGram) || 0)) }}
                  </div>
                </div>

                <div v-else-if="item.priceMode === 'manual'" class="mode-manual-inputs">
                  <label>Precio Unitario Directo:</label>
                  <div class="manual-price-field">
                    <span class="currency-sym">Bs</span>
                    <input 
                      type="number" 
                      min="0" 
                      step="0.5" 
                      v-model.number="item.unit_price" 
                      @input="onManualPriceChange(item)"
                      class="input-manual-price"
                    />
                  </div>
                </div>
              </div>

              <!-- CUERPO DE OPCIONES / MODIFICADORES (EXPANDIBLE) -->
              <div v-show="item.isExpanded" class="item-card-body">
                <!-- GRUPOS DE OPCIONES (TAMAÑO, FRUTAS, TOPPINGS, ETC.) -->
                <div 
                  v-if="getProductOptionGroups(getProduct(item.product_id)).length > 0" 
                  class="item-option-groups"
                >
                  <div 
                    v-for="og in getProductOptionGroups(getProduct(item.product_id))" 
                    :key="og.id" 
                    class="option-group-block"
                  >
                    <div class="group-header">
                      <div class="group-title-wrap">
                        <span class="group-icon">{{ getGroupIcon(og.name) }}</span>
                        <strong class="group-name">{{ og.name }}</strong>
                        <span class="group-limit-badge" :class="{ 'badge-required': og.min_selections > 0 }">
                          {{ (!og.max_selections || Number(og.max_selections) <= 1) ? 'Elige 1' : `Máx. ${og.max_selections}` }}
                        </span>
                      </div>

                      <!-- Contador para multiselect -->
                      <span v-if="Number(og.max_selections) > 1" class="group-counter">
                        {{ getGroupSelectedCount(item, og.id) }} / {{ og.max_selections }} seleccionados
                      </span>
                    </div>

                    <!-- CASO 1: SINGLE-SELECT (TAMAÑOS / RADIOS) -->
                    <div v-if="!og.max_selections || Number(og.max_selections) <= 1" class="single-select-row">
                      <button 
                        type="button" 
                        v-for="opt in og.options" 
                        :key="opt.id" 
                        class="option-radio-btn"
                        :class="{ active: isOptionSelected(item, og.id, opt.id) }"
                        @click="handleSingleSelect(item, og, opt)"
                      >
                        <span class="radio-dot"></span>
                        <span class="opt-name">{{ opt.name }}</span>
                        <span v-if="opt.additional_price > 0" class="opt-price-add">
                          +Bs {{ Number(opt.additional_price).toFixed(2) }}
                        </span>
                      </button>
                    </div>

                    <!-- CASO 2: MULTI-SELECT (FRUTAS, TOPPINGS / CHIPS) -->
                    <div v-else class="multi-select-chips">
                      <button 
                        type="button" 
                        v-for="opt in og.options" 
                        :key="opt.id" 
                        class="option-chip-btn"
                        :class="{
                          active: isOptionSelected(item, og.id, opt.id),
                          'limit-disabled': !isOptionSelected(item, og.id, opt.id) && getGroupSelectedCount(item, og.id) >= og.max_selections
                        }"
                        :disabled="!isOptionSelected(item, og.id, opt.id) && getGroupSelectedCount(item, og.id) >= og.max_selections"
                        @click="toggleMultiSelect(item, og, opt)"
                      >
                        <span class="chip-indicator">{{ isOptionSelected(item, og.id, opt.id) ? '✓' : '+' }}</span>
                        <span class="opt-name">{{ opt.name }}</span>
                        <span v-if="opt.additional_price > 0" class="opt-price-add">
                          +Bs {{ Number(opt.additional_price).toFixed(2) }}
                        </span>
                      </button>
                    </div>
                  </div>
                </div>

                <!-- NOTA DE COCINA Y DESTINO POR ITEM -->
                <div class="item-footer-inline">
                  <div class="item-note-wrap">
                    <span class="note-icon">📝</span>
                    <input 
                      type="text" 
                      v-model="item.item_note" 
                      placeholder="Nota de cocina (ej: Sin granola, leche deslactosada...)" 
                      class="item-inline-note-input"
                    />
                  </div>

                  <label class="item-takeaway-checkbox">
                    <input type="checkbox" v-model="item.is_takeaway" />
                    <span>🛍️ Para llevar este ítem</span>
                  </label>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- RESUMEN DE TOTALES -->
        <div class="totals-bar">
          <div class="total-row">
            <span>Subtotal:</span>
            <strong>Bs {{ calculatedSubtotal.toFixed(2) }}</strong>
          </div>
          <div class="total-row discount-row">
            <span>Descuento (Bs):</span>
            <input 
              type="number" 
              min="0" 
              step="0.5" 
              v-model.number="formData.discount_amount" 
              @input="autoSyncPayments"
              class="discount-input" 
            />
          </div>
          <div class="total-row total-highlight">
            <span>Total a Cobrar:</span>
            <strong>Bs {{ calculatedTotal.toFixed(2) }}</strong>
          </div>
        </div>

        <hr class="section-divider" />

        <!-- SECCIÓN 4: MÉTODOS DE PAGO -->
        <div class="payments-section">
          <div class="payments-header">
            <h4>💳 Métodos de Pago</h4>
            <div class="quick-method-pills">
              <span style="font-size: 11px; color: #64748b;">Llenado Rápido:</span>
              <button type="button" class="method-pill" @click="quickSetPaymentMethod('cash')">💵 Efectivo Total</button>
              <button type="button" class="method-pill" @click="quickSetPaymentMethod('qr')">📱 QR Total</button>
              <button type="button" class="method-pill" @click="quickSetPaymentMethod('card')">💳 Tarjeta Total</button>
              <button type="button" class="btn btn-xs btn-outline-primary" @click="addPayment">＋ Pago Mixto</button>
            </div>
          </div>

          <div class="payment-rows">
            <div v-for="(p, pIdx) in formData.payments" :key="pIdx" class="payment-item-row">
              <select v-model="p.method" class="form-input payment-method-select">
                <option value="cash">💵 Efectivo (Cash)</option>
                <option value="qr">📱 Pago QR</option>
                <option value="card">💳 Tarjeta Débito / Crédito</option>
                <option value="transfer">🏦 Transferencia Bancaria</option>
              </select>

              <div class="payment-amount-wrap">
                <span class="currency-prefix">Bs</span>
                <input 
                  type="number" 
                  min="0" 
                  step="0.5" 
                  v-model.number="p.amount" 
                  class="form-input payment-amount-input" 
                />
              </div>

              <button 
                v-if="formData.payments.length > 1" 
                type="button" 
                class="btn-remove-item" 
                @click="removePayment(pIdx)" 
                title="Quitar método"
              >
                ✕
              </button>
            </div>
          </div>

          <div v-if="Math.abs(paymentDifference) > 0.01" class="payment-difference-alert">
            ⚠️ Diferencia de pagos: Hay una discrepancia de Bs {{ Math.abs(paymentDifference).toFixed(2) }} (Total Pagos: Bs {{ totalPayments.toFixed(2) }} vs Venta: Bs {{ calculatedTotal.toFixed(2) }}).
          </div>
        </div>

        <hr class="section-divider" />

        <!-- SECCIÓN 5: AUDITORÍA OBLIGATORIA -->
        <div class="audit-reason-group">
          <div class="form-group">
            <label class="required-label">
              <strong>📝 Justificación del Super Admin (Obligatorio para Auditoría):</strong>
            </label>
            <textarea 
              v-model="formData.edit_reason" 
              rows="2" 
              class="form-textarea" 
              placeholder="Explica el motivo de este registro o cambio (ej: Venta offline por corte de energía registrada en talonario físico #045)..."
              required
            ></textarea>
          </div>
        </div>
      </div>

      <!-- FOOTER ACCIONES -->
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" @click="$emit('close')" :disabled="isSaving">
          Cancelar
        </button>
        <button 
          type="button" 
          class="btn btn-primary btn-save" 
          @click="handleSave" 
          :disabled="isSaving"
        >
          <span v-if="isSaving">Guardando...</span>
          <span v-else>{{ isEditMode ? '✓ Guardar Cambios de la Venta' : '✓ Registrar Venta Retroactiva' }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.7);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 16px;
}

.editor-modal {
  background: var(--surface, #ffffff);
  border-radius: 20px;
  width: 100%;
  max-width: 900px;
  max-height: 94vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
  border: 1.5px solid var(--border, #e2e8f0);
  overflow: hidden;
}

.modal-header {
  padding: 16px 24px;
  border-bottom: 1px solid var(--border, #e2e8f0);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: var(--cream-50, #faf5f0);
}

.badge-super-admin {
  display: inline-block;
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  background: #fef3c7;
  color: #b45309;
  padding: 3px 8px;
  border-radius: 6px;
  margin-bottom: 4px;
  border: 1px solid #fde68a;
}

.header-titles h3 {
  margin: 0;
  font-size: 18px;
  font-weight: 800;
  color: var(--ink-900, #0f172a);
}

.close-btn {
  background: transparent;
  border: none;
  font-size: 20px;
  cursor: pointer;
  color: var(--ink-500, #64748b);
  border-radius: 8px;
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.2s;
}

.close-btn:hover {
  background: rgba(0,0,0,0.06);
}

.audit-warning-banner {
  background: #eff6ff;
  border-bottom: 1px solid #bfdbfe;
  padding: 10px 24px;
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 13px;
  color: #1e40af;
}

.error-banner {
  background: #fef2f2;
  border-bottom: 1px solid #fecaca;
  color: #dc2626;
  padding: 10px 24px;
  font-size: 13px;
  font-weight: 600;
}

.success-banner {
  background: #f0fdf4;
  border-bottom: 1px solid #bbf7d0;
  color: #16a34a;
  padding: 10px 24px;
  font-size: 13px;
  font-weight: 600;
}

.modal-body {
  padding: 18px 24px;
  overflow-y: auto;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.form-grid-3 {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 14px;
}

.form-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-group label {
  font-size: 13px;
  color: var(--ink-700, #334155);
}

.required-label {
  color: #b45309 !important;
}

.form-input, .form-input-sm, .form-textarea {
  padding: 8px 12px;
  border: 1.5px solid var(--border, #cbd5e1);
  border-radius: 8px;
  font-size: 13px;
  background: var(--surface, #ffffff);
  color: var(--ink-900, #0f172a);
  transition: border-color 0.2s;
  width: 100%;
}

.form-input:focus, .form-input-sm:focus, .form-textarea:focus {
  border-color: var(--primary, #8b5cf6);
  outline: none;
}

.form-textarea {
  resize: vertical;
  font-family: inherit;
}

.destination-toggle {
  display: flex;
  gap: 6px;
  background: var(--cream-100, #f1f5f9);
  padding: 3px;
  border-radius: 9px;
}

.dest-btn {
  flex: 1;
  border: none;
  background: transparent;
  padding: 6px;
  border-radius: 7px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  color: var(--ink-600, #475569);
  transition: all 0.2s;
}

.dest-btn.active {
  background: var(--surface, #ffffff);
  color: var(--primary-700, #6d28d9);
  box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.section-divider {
  border: none;
  border-top: 1px dashed var(--border, #e2e8f0);
  margin: 4px 0;
}

/* SECCIÓN PRODUCTOS */
.items-section-wrapper {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.items-section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.items-section-header h4 {
  margin: 0;
  font-size: 15px;
  color: var(--ink-900, #0f172a);
  display: inline-block;
  margin-right: 8px;
}

.items-count-tag {
  font-size: 12px;
  background: var(--cream-100, #f1f5f9);
  color: var(--ink-600, #475569);
  padding: 2px 8px;
  border-radius: 12px;
  font-weight: 600;
}

/* PRODUCT PICKER PANEL */
.product-picker-panel {
  background: var(--cream-50, #faf5f0);
  border: 1.5px solid var(--primary-200, #ddd6fe);
  border-radius: 14px;
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  box-shadow: 0 4px 12px rgba(139, 92, 246, 0.08);
}

.picker-filters {
  display: flex;
  gap: 10px;
}

.picker-search-wrap {
  flex: 1;
  position: relative;
  display: flex;
  align-items: center;
}

.search-icon {
  position: absolute;
  left: 10px;
  font-size: 13px;
  color: #94a3b8;
}

.picker-search-input {
  width: 100%;
  padding: 7px 30px 7px 32px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font-size: 13px;
  background: #ffffff;
}

.picker-search-input:focus {
  border-color: #8b5cf6;
  outline: none;
}

.clear-search-btn {
  position: absolute;
  right: 8px;
  background: transparent;
  border: none;
  color: #94a3b8;
  cursor: pointer;
  font-size: 12px;
}

.picker-category-select {
  width: 200px;
  padding: 7px 10px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font-size: 13px;
  background: #ffffff;
}

.picker-products-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 8px;
  max-height: 180px;
  overflow-y: auto;
  padding-right: 4px;
}

.product-picker-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  background: #ffffff;
  border: 1px solid var(--border, #e2e8f0);
  border-radius: 10px;
  cursor: pointer;
  text-align: left;
  transition: all 0.15s ease;
}

.product-picker-card:hover {
  border-color: var(--primary, #8b5cf6);
  background: #f5f3ff;
  transform: translateY(-1px);
}

.card-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.card-name {
  font-size: 12px;
  color: var(--ink-900, #0f172a);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 135px;
}

.card-price {
  font-size: 11px;
  font-weight: 700;
  color: var(--primary-700, #6d28d9);
}

.card-add-icon {
  font-size: 15px;
  font-weight: 700;
  color: var(--primary, #8b5cf6);
}

.no-products-found {
  grid-column: 1 / -1;
  text-align: center;
  padding: 16px;
  color: #94a3b8;
  font-size: 12px;
}

/* EMPTY ITEMS */
.empty-items-alert {
  padding: 28px;
  text-align: center;
  background: var(--cream-50, #faf5f0);
  border: 1.5px dashed var(--border, #cbd5e1);
  border-radius: 14px;
  color: var(--ink-600, #475569);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

.empty-icon {
  font-size: 32px;
}

.empty-items-alert p {
  margin: 0;
  font-size: 13px;
}

/* ITEMS CARDS */
.items-cards-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.item-card {
  background: #ffffff;
  border: 1.5px solid var(--border, #e2e8f0);
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 1px 4px rgba(0,0,0,0.04);
  transition: border-color 0.2s;
}

.item-card:hover {
  border-color: #cbd5e1;
}

.item-card-header {
  padding: 10px 14px;
  background: var(--cream-50, #f8fafc);
  border-bottom: 1px solid var(--border, #f1f5f9);
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.item-header-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
}

.item-index-badge {
  font-size: 11px;
  font-weight: 800;
  background: #e2e8f0;
  color: #475569;
  padding: 2px 6px;
  border-radius: 6px;
}

.item-product-select {
  flex: 1;
  max-width: 320px;
  padding: 5px 8px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 700;
  color: var(--ink-900, #0f172a);
  background: #ffffff;
}

.item-header-right {
  display: flex;
  align-items: center;
  gap: 12px;
}

.qty-stepper {
  display: flex;
  align-items: center;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #ffffff;
  overflow: hidden;
}

.qty-btn {
  background: transparent;
  border: none;
  padding: 4px 8px;
  font-weight: 800;
  cursor: pointer;
  color: #475569;
}

.qty-btn:hover {
  background: #f1f5f9;
}

.qty-input {
  width: 44px;
  border: none;
  text-align: center;
  font-size: 13px;
  font-weight: 700;
  padding: 2px 0;
}

.qty-input:focus {
  outline: none;
}

.item-price-tag {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  min-width: 90px;
}

.unit-price-hint {
  font-size: 10px;
  color: #64748b;
}

.item-subtotal-val {
  font-size: 14px;
  color: var(--primary-700, #6d28d9);
}

.btn-toggle-expand {
  background: transparent;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 4px 8px;
  font-size: 10px;
  cursor: pointer;
  color: #64748b;
}

.btn-toggle-expand:hover {
  background: #f1f5f9;
}

.btn-remove-item {
  background: transparent;
  border: none;
  cursor: pointer;
  font-size: 14px;
  opacity: 0.7;
  transition: opacity 0.2s;
}

.btn-remove-item:hover {
  opacity: 1;
}

/* BARRA DE MODOS DE PRECIO */
.item-price-modes-bar {
  background: #f1f5f9;
  border-bottom: 1px solid var(--border, #e2e8f0);
  padding: 6px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.price-mode-pills-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
}

.mode-bar-label {
  font-size: 11px;
  font-weight: 700;
  color: #64748b;
}

.mode-pills {
  display: flex;
  gap: 4px;
}

.mode-pill-btn {
  border: 1px solid #cbd5e1;
  background: #ffffff;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
  color: #475569;
  cursor: pointer;
  transition: all 0.15s;
}

.mode-pill-btn:hover {
  background: #f8fafc;
}

.mode-pill-btn.active {
  background: var(--primary, #8b5cf6);
  border-color: var(--primary, #8b5cf6);
  color: #ffffff;
}

.mode-weight-inputs {
  display: flex;
  align-items: center;
  gap: 8px;
}

.weight-field {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  color: #475569;
}

.weight-field label {
  font-weight: 700;
}

.input-weight-g {
  width: 65px;
  padding: 2px 6px;
  border: 1px solid #cbd5e1;
  border-radius: 5px;
  font-size: 12px;
  font-weight: 700;
  text-align: right;
  background: #ffffff;
}

.input-price-per-g {
  width: 70px;
  padding: 2px 6px;
  border: 1px solid #cbd5e1;
  border-radius: 5px;
  font-size: 12px;
  font-weight: 700;
  text-align: right;
  background: #ffffff;
}

.unit-tag {
  font-size: 11px;
  color: #64748b;
  font-weight: 600;
}

.weight-calc-hint {
  font-size: 11px;
  font-weight: 800;
  color: var(--primary-700, #6d28d9);
  background: #ede9fe;
  padding: 2px 6px;
  border-radius: 4px;
}

.mode-manual-inputs {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: #475569;
}

.mode-manual-inputs label {
  font-weight: 700;
}

.manual-price-field {
  display: flex;
  align-items: center;
  gap: 3px;
}

.currency-sym {
  font-size: 11px;
  font-weight: 700;
  color: #64748b;
}

.input-manual-price {
  width: 80px;
  padding: 3px 6px;
  border: 1.5px solid var(--primary, #8b5cf6);
  border-radius: 5px;
  font-size: 12px;
  font-weight: 700;
  text-align: right;
  background: #ffffff;
  color: var(--primary-700, #6d28d9);
}

/* OPCIONES DEL ITEM */
.item-card-body {
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: #ffffff;
}

.item-option-groups {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.option-group-block {
  background: var(--cream-50, #faf5f0);
  border: 1px solid var(--border, #f1f5f9);
  border-radius: 10px;
  padding: 8px 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.group-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.group-title-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
}

.group-icon {
  font-size: 13px;
}

.group-name {
  font-size: 12px;
  color: var(--ink-800, #1e293b);
}

.group-limit-badge {
  font-size: 10px;
  background: #e2e8f0;
  color: #475569;
  padding: 1px 6px;
  border-radius: 4px;
  font-weight: 600;
}

.badge-required {
  background: #fef3c7;
  color: #b45309;
}

.group-counter {
  font-size: 11px;
  font-weight: 600;
  color: var(--primary-700, #6d28d9);
}

/* SINGLE SELECT (RADIOS) */
.single-select-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.option-radio-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 5px 10px;
  border: 1.5px solid #cbd5e1;
  border-radius: 8px;
  background: #ffffff;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  color: #334155;
  transition: all 0.15s ease;
}

.option-radio-btn .radio-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  border: 1.5px solid #94a3b8;
  background: transparent;
  display: inline-block;
}

.option-radio-btn.active {
  border-color: var(--primary, #8b5cf6);
  background: #f5f3ff;
  color: var(--primary-700, #6d28d9);
}

.option-radio-btn.active .radio-dot {
  border-color: var(--primary, #8b5cf6);
  background: var(--primary, #8b5cf6);
}

/* MULTI SELECT (CHIPS) */
.multi-select-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}

.option-chip-btn {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #ffffff;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  color: #475569;
  transition: all 0.15s ease;
}

.chip-indicator {
  font-size: 11px;
  font-weight: 800;
  color: #94a3b8;
}

.option-chip-btn.active {
  border-color: var(--primary, #8b5cf6);
  background: #f5f3ff;
  color: var(--primary-700, #6d28d9);
}

.option-chip-btn.active .chip-indicator {
  color: var(--primary, #8b5cf6);
}

.option-chip-btn.limit-disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.opt-price-add {
  font-size: 10px;
  color: #b45309;
  background: #fef3c7;
  padding: 1px 4px;
  border-radius: 4px;
}

/* ITEM FOOTER INLINE */
.item-footer-inline {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border-top: 1px dashed #e2e8f0;
  padding-top: 8px;
}

.item-note-wrap {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 6px;
}

.note-icon {
  font-size: 12px;
}

.item-inline-note-input {
  width: 100%;
  border: none;
  border-bottom: 1px dotted #cbd5e1;
  font-size: 12px;
  padding: 3px 6px;
  background: transparent;
  color: #334155;
}

.item-inline-note-input:focus {
  outline: none;
  border-bottom-color: #8b5cf6;
}

.item-takeaway-checkbox {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #475569;
  cursor: pointer;
  white-space: nowrap;
}

/* TOTALS BAR */
.totals-bar {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 20px;
  padding: 10px 16px;
  background: var(--cream-50, #f8fafc);
  border-radius: 10px;
}

.total-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--ink-700, #334155);
}

.discount-input {
  width: 70px;
  padding: 4px 6px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  text-align: right;
  font-size: 13px;
}

.total-highlight {
  font-size: 15px;
  color: var(--primary-700, #6d28d9);
}

.total-highlight strong {
  font-size: 17px;
}

/* PAYMENTS SECTION */
.payments-section {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.payments-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.payments-header h4 {
  margin: 0;
  font-size: 14px;
  color: var(--ink-900, #0f172a);
}

.quick-method-pills {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}

.method-pill {
  border: 1px solid #cbd5e1;
  background: #ffffff;
  padding: 3px 8px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 11px;
  font-weight: 600;
  transition: background 0.2s;
}

.method-pill:hover {
  background: #f1f5f9;
}

.payment-rows {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.payment-item-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.payment-method-select {
  width: 200px;
}

.payment-amount-wrap {
  display: flex;
  align-items: center;
  gap: 4px;
}

.currency-prefix {
  font-size: 13px;
  color: #64748b;
}

.payment-amount-input {
  width: 120px;
  text-align: right;
}

.payment-difference-alert {
  font-size: 12px;
  color: #dc2626;
  font-weight: 600;
}

/* AUDIT REASON */
.audit-reason-group {
  background: #fffbeb;
  border: 1px solid #fde68a;
  padding: 12px 16px;
  border-radius: 10px;
}

.modal-footer {
  padding: 14px 24px;
  border-top: 1px solid var(--border, #e2e8f0);
  background: var(--cream-50, #faf5f0);
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

.btn {
  padding: 8px 16px;
  border-radius: 9px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-sm {
  padding: 6px 12px;
  font-size: 12px;
}

.btn-xs {
  padding: 3px 8px;
  font-size: 11px;
}

.btn-ghost {
  background: transparent;
  border: 1px solid transparent;
  color: #64748b;
}

.btn-ghost:hover {
  background: rgba(0,0,0,0.05);
}

.btn-outline-primary {
  background: transparent;
  border: 1.5px solid var(--primary, #8b5cf6);
  color: var(--primary-700, #6d28d9);
}

.btn-outline-primary:hover {
  background: var(--primary-50, #f5f3ff);
}

.btn-primary {
  background: var(--primary, #8b5cf6);
  color: #ffffff;
  border: none;
}

.btn-primary:hover {
  background: var(--primary-hover, #7c3aed);
}

.btn-save {
  min-width: 220px;
}
</style>
