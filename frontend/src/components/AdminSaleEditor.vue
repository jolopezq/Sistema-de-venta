<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import ModifierModal from './ModifierModal.vue';
import CategoryFilter from './CategoryFilter.vue';
import { productPrice, optionPrice } from '../utils/channelPricing';
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
  inline: {
    type: Boolean,
    default: false,
  },
  semiLote: {
    type: Boolean,
    default: false,
  },
  defaultDate: {
    type: String,
    default: '',
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
// Datos de la venta (fecha, cajero, cliente, notas): plegados en venta nueva para que el catálogo quede a la vista.
const showSaleDetails = ref(false);
// Motivos frecuentes: el motivo es obligatorio en cada venta registrada desde aquí.
const quickReasons = ['Talonario manual', 'Corte de energía', 'Corrección de turno'];

// Configurador de opciones compartido con Ventas (ModifierModal)
const showModifierModal = ref(false);
const selectedProductForModal = ref(null);
const editingItemIndex = ref(null);
const modalInitialData = ref(null);

// Origen de la Venta (Plataformas, Mesa, Llevar)
const saleOrigin = ref('pos_mesa');

watch(saleOrigin, (newOrigin) => {
  if (newOrigin === 'pedidosya') {
    formData.value.is_takeaway = true;
    formData.value.items.forEach(item => {
      item.is_takeaway = true;
      if (item.priceMode !== 'weight' && item.priceMode !== 'manual') {
        recalcItemPricesAndModifiers(item);
      }
    });
    quickSetPaymentMethod('delivery_platform');
  } else {
    formData.value.is_takeaway = (newOrigin === 'pos_llevar');
    formData.value.items.forEach(item => {
      item.is_takeaway = (newOrigin === 'pos_llevar');
      if (item.priceMode !== 'weight' && item.priceMode !== 'manual') {
        recalcItemPricesAndModifiers(item);
      }
    });
    quickSetPaymentMethod('cash');
  }
  autoSyncPayments();
});

// Form state
const formData = ref({
  created_at: '',
  cashier_id: '',
  customer_id: '',
  is_takeaway: false,
  notes: '',
  edit_reason: '',
  discount_amount: 0,
  surcharge_amount: 0,
  items: [],
  payments: [
    { method: 'cash', amount: 0 }
  ]
});

function formatForDatetimeLocal(isoStr, customDate = null) {
  if (customDate) {
    const now = new Date();
    const hh = String(now.getHours()).padStart(2, '0');
    const mm = String(now.getMinutes()).padStart(2, '0');
    return `${customDate}T${hh}:${mm}`;
  }
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
  return catalogStore.products.find(p => Number(p.id) === Number(productId));
}

function getProductOptionGroups(product) {
  if (!product || !product.option_groups) return [];
  return product.option_groups
    .filter(og => og.is_active)
    .map(og => ({
      ...og,
      options: (og.options || [])
        .filter(opt => opt.is_active && !(product.excluded_options || []).includes(opt.id))
        .map(opt => ({
          ...opt,
          pedidosya_pricing: opt.pedidosya_pricing !== undefined ? opt.pedidosya_pricing : og.pedidosya_pricing
        }))
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
  return (item.selectedOptionIds[groupId] || []).some(id => Number(id) === Number(optionId));
}

function getGroupSelectedCount(item, groupId) {
  return (item.selectedOptionIds[groupId] || []).length;
}

function getActiveBasePrice(product) {
  return productPrice(product, saleOrigin.value);
}

function getActiveOptionPrice(opt) {
  return optionPrice(opt, saleOrigin.value);
}

function recalcItemPricesAndModifiers(item) {
  const p = getProduct(item.product_id);
  const basePrice = getActiveBasePrice(p);
  let extrasPrice = 0;
  const modifiers = [];

  const groups = getProductOptionGroups(p);
  groups.forEach(og => {
    const selectedIds = item.selectedOptionIds[og.id] || [];
    selectedIds.forEach(optId => {
      const opt = og.options.find(o => Number(o.id) === Number(optId));
      if (opt) {
        const optPrice = getActiveOptionPrice(opt);
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
    // Modo 'auto' (Catálogo estándar / Tarifa activa PedidosYa)
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
  const isAlreadySelected = currentList.some(id => Number(id) === Number(opt.id));
  if (isAlreadySelected) {
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
  const isSel = currentList.some(id => Number(id) === Number(opt.id));

  if (isSel) {
    item.selectedOptionIds[og.id] = currentList.filter(id => Number(id) !== Number(opt.id));
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
  // Venta nueva: catálogo abierto y datos de la venta plegados (se ven en una línea).
  showProductPicker.value = !(isEditMode.value && props.sale);
  showSaleDetails.value = Boolean(isEditMode.value && props.sale);
  closeModifierModal();
  productSearchQuery.value = '';
  selectedCategoryId.value = '';

  if (isEditMode.value && props.sale) {
    if (props.sale.source === 'pedidosya') {
      saleOrigin.value = 'pedidosya';
    } else {
      saleOrigin.value = props.sale.is_takeaway ? 'pos_llevar' : 'pos_mesa';
    }

    const rawDiscount = Number(props.sale.discount_amount || 0);
    formData.value = {
      created_at: formatForDatetimeLocal(props.sale.created_at),
      cashier_id: props.sale.cashier_id || '',
      customer_id: props.sale.customer_id || '',
      is_takeaway: Boolean(props.sale.is_takeaway),
      notes: props.sale.notes || '',
      edit_reason: '',
      discount_amount: rawDiscount > 0 ? rawDiscount : 0,
      surcharge_amount: rawDiscount < 0 ? Math.abs(rawDiscount) : 0,
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

        // Detectar si el precio fue modificado manualmente tomando en cuenta PedidosYa
        const isPya = props.sale.source === 'pedidosya';
        let autoPriceExpected = isPya && p?.delivery_price && Number(p.delivery_price) > 0
          ? Number(p.delivery_price)
          : Number(p?.price || 0);
        const baseExpected = autoPriceExpected;

        groups.forEach(og => {
          const selected = selectedOptionIds[og.id] || [];
          selected.forEach(optId => {
            const opt = og.options.find(o => Number(o.id) === Number(optId));
            if (opt) {
              const optPrice = isPya && opt.delivery_price && Number(opt.delivery_price) > 0
                ? Number(opt.delivery_price)
                : Number(opt.additional_price || 0);
              autoPriceExpected += optPrice;
            }
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
          // Gramos guardados en la línea; en ventas viejas se deducen del precio cobrado sin los extras
          weightGrams: isWeight
            ? Number(i.weight_grams) || Math.round(Math.max(0, rawUnitPrice - (autoPriceExpected - baseExpected)) / Number(p?.price_per_gram || 0.08)) || 350
            : 0,
          pricePerGram: Number(p?.price_per_gram || 0.08),
          unit_price: rawUnitPrice,
          subtotal: Number(i.subtotal || (Number(i.quantity || 1) * rawUnitPrice)),
          is_takeaway: Boolean(i.is_takeaway),
          item_note: i.item_note || '',
          priceOverrideReason: i.price_override_reason || '',
          selectedOptionIds,
          modifiers: [],
          // Fila compacta; solo se abre «Precio y nota» si el precio fue manual, para que se vea el porqué
          isExpanded: priceMode === 'manual'
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
                const optPrice = isPya && opt.delivery_price && Number(opt.delivery_price) > 0
                  ? Number(opt.delivery_price)
                  : Number(opt.additional_price || 0);
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
    saleOrigin.value = 'pos_mesa';
    formData.value = {
      created_at: formatForDatetimeLocal(null, props.defaultDate),
      cashier_id: usersList.value[0]?.id || '',
      customer_id: '',
      is_takeaway: false,
      notes: '',
      edit_reason: '',
      discount_amount: 0,
      surcharge_amount: 0,
      items: [],
      payments: [
        { method: 'cash', amount: 0 }
      ]
    };
  }
}

watch(() => props.defaultDate, (newDate) => {
  if (newDate && !isEditMode.value) {
    formData.value.created_at = formatForDatetimeLocal(null, newDate);
  }
});

watch(() => props.visible, (newVal) => {
  if (newVal) {
    initForm();
  }
});

onMounted(async () => {
  if (props.visible) {
    initForm();
  }
  await loadUsers();
  await catalogStore.fetchAndCache();
});

// Filtrado de productos disponibles en el picker
const filteredAvailableProducts = computed(() => {
  let list = catalogStore.products.filter(p => p.is_active);
  const q = productSearchQuery.value.toLowerCase().trim();
  // Igual que en Ventas: al buscar se busca en todo el menú, sin importar la pestaña.
  if (q) return list.filter(p => (p.name || '').toLowerCase().includes(q));
  if (selectedCategoryId.value) {
    list = list.filter(p => p.category_id === Number(selectedCategoryId.value));
  }
  return list;
});

/** Pestañas del catálogo: solo categorías con productos activos (una pestaña vacía no sirve). */
const pickerCategories = computed(() => {
  const withProducts = new Set(catalogStore.products.filter(p => p.is_active).map(p => p.category_id));
  return [...catalogStore.categories]
    .filter(c => withProducts.has(c.id))
    .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));
});
const pickerCategoryId = computed(() => (selectedCategoryId.value === '' ? null : Number(selectedCategoryId.value)));
function selectPickerCategory(id) {
  selectedCategoryId.value = id === null ? '' : id;
  productSearchQuery.value = '';
}

const modalChannel = computed(() => (saleOrigin.value === 'pedidosya' ? 'pedidosya' : 'pos'));
const itemCount = computed(() => formData.value.items.reduce((n, i) => n + (Number(i.quantity) || 0), 0));

/** Resumen de los datos de la venta para la fila plegada. */
const saleMetaParts = computed(() => {
  const parts = [];
  const dt = formData.value.created_at || '';
  if (dt) {
    const [d, t] = dt.split('T');
    const [y, m, dd] = d.split('-');
    parts.push(`${dd}/${m}/${y} ${(t || '').slice(0, 5)}`.trim());
  } else {
    parts.push('Sin fecha');
  }
  const cashier = usersList.value.find(u => String(u.id) === String(formData.value.cashier_id));
  parts.push(cashier ? `Registra ${cashier.name}` : 'Registra tu usuario');
  const customer = catalogStore.customers.find(c => String(c.id) === String(formData.value.customer_id));
  parts.push(customer ? customer.name : 'Cliente ocasional');
  if ((formData.value.notes || '').trim()) parts.push(formData.value.notes.trim());
  return parts;
});

// Totales calculados
const calculatedSubtotal = computed(() => {
  return formData.value.items.reduce((sum, item) => sum + (Number(item.subtotal) || 0), 0);
});

const calculatedTotal = computed({
  get() {
    const sub = calculatedSubtotal.value;
    const disc = Number(formData.value.discount_amount || 0);
    const surch = Number(formData.value.surcharge_amount || 0);
    return Math.max(0, Number((sub - disc + surch).toFixed(2)));
  },
  set(newTotal) {
    const val = Number(newTotal || 0);
    const sub = calculatedSubtotal.value;
    if (val >= sub) {
      formData.value.discount_amount = 0;
      formData.value.surcharge_amount = Number((val - sub).toFixed(2));
    } else {
      formData.value.discount_amount = Number((sub - val).toFixed(2));
      formData.value.surcharge_amount = 0;
    }
    autoSyncPayments();
  }
});

const hasProductsWithoutDeliveryPrice = computed(() => {
  if (saleOrigin.value !== 'pedidosya') return false;
  return formData.value.items.some(item => {
    const p = getProduct(item.product_id);
    return p && !p.is_weight_based && (!p.delivery_price || Number(p.delivery_price) <= 0);
  });
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
  // Con opciones o por peso: el mismo configurador que en Ventas.
  if (groups.length > 0 || product.is_weight_based) {
    selectedProductForModal.value = product;
    editingItemIndex.value = null;
    modalInitialData.value = null;
    showModifierModal.value = true;
    return;
  }
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
  const initialBasePrice = getActiveBasePrice(product);

  const newItem = {
    product_id: product.id,
    quantity: 1,
    priceMode: isWeight ? 'weight' : 'auto',
    weightGrams: isWeight ? 350 : 0,
    pricePerGram: pricePerGram,
    unit_price: initialBasePrice,
    subtotal: initialBasePrice,
    is_takeaway: formData.value.is_takeaway,
    item_note: '',
    priceOverrideReason: '',
    selectedOptionIds,
    modifiers: [],
    isExpanded: true
  };

  recalcItemPricesAndModifiers(newItem);

  // Mismo producto, mismas opciones y sin ajustes manuales: solo sube la cantidad.
  const sig = itemSignature(newItem);
  const same = !isWeight
    ? formData.value.items.find(it => itemSignature(it) === sig)
    : null;
  if (same) {
    same.quantity = Number(same.quantity || 0) + 1;
    onItemQuantityChange(same);
    flashAdded(formData.value.items.indexOf(same));
  } else {
    // Se abre solo si hay un grupo obligatorio sin elegir; si no, queda en fila compacta.
    newItem.isExpanded = groups.some(og => (og.min_selections || 0) > (selectedOptionIds[og.id] || []).length)
      || isWeight;
    formData.value.items.push(newItem);
    flashAdded(formData.value.items.length - 1);
  }
  autoSyncPayments();
  // El catálogo queda abierto para seguir apilando ítems.
}

/** Huella de un ítem: producto + opciones + modo de precio + nota + llevar. */
function itemSignature(item) {
  if (!item || item.priceMode !== 'auto' || item.item_note || item.priceOverrideReason) return null;
  const opts = Object.keys(item.selectedOptionIds || {}).sort()
    .map(k => `${k}:${[...(item.selectedOptionIds[k] || [])].sort((a, b) => a - b).join(',')}`).join('|');
  return `${item.product_id}#${opts}#${item.is_takeaway ? 1 : 0}`;
}

const lastAddedIdx = ref(-1);
const lastAddedLabel = ref('');
let flashTimer = null;
let labelTimer = null;
function flashAdded(idx) {
  lastAddedIdx.value = idx;
  const it = formData.value.items[idx];
  const name = it ? (getProduct(it.product_id)?.name || 'Producto') : '';
  lastAddedLabel.value = it ? `${name}${Number(it.quantity) > 1 ? ` (${it.quantity})` : ''}` : '';
  clearTimeout(flashTimer);
  flashTimer = setTimeout(() => { lastAddedIdx.value = -1; }, 900);
  clearTimeout(labelTimer);
  labelTimer = setTimeout(() => { lastAddedLabel.value = ''; }, 2500);
}

/** Reconstruye las opciones elegidas por grupo a partir de los modificadores del configurador. */
function selectionsFromModifiers(product, modifiers) {
  const sel = {};
  getProductOptionGroups(product).forEach(og => {
    sel[og.id] = (modifiers || [])
      .filter(m => og.options.some(o => Number(o.id) === Number(m.option_id)))
      .map(m => m.option_id);
  });
  return sel;
}

/** Confirmación del configurador: crea la fila, suma cantidad si ya existe una igual o reemplaza la que se editaba. */
function handleConfirmModal({ product, modifiers, finalPrice, itemNote, isTakeaway, weightGrams }) {
  showModifierModal.value = false;
  const items = formData.value.items;
  const editIdx = editingItemIndex.value;
  const editing = editIdx !== null ? items[editIdx] : null;
  const isWeight = Boolean(product.is_weight_based);
  const keepManual = Boolean(editing && editing.priceMode === 'manual');

  const item = {
    product_id: product.id,
    quantity: editing ? Number(editing.quantity || 1) : 1,
    priceMode: keepManual ? 'manual' : (isWeight ? 'weight' : 'auto'),
    weightGrams: isWeight ? Number(weightGrams || 0) : 0,
    pricePerGram: Number(product.price_per_gram || 0.08),
    unit_price: keepManual ? Number(editing.unit_price || 0) : Number(finalPrice || 0),
    subtotal: 0,
    // La venta manda: si es «Para llevar» o PedidosYa, el ítem también.
    is_takeaway: Boolean(formData.value.is_takeaway || isTakeaway),
    item_note: itemNote || '',
    priceOverrideReason: editing ? (editing.priceOverrideReason || '') : '',
    selectedOptionIds: selectionsFromModifiers(product, modifiers),
    modifiers: [],
    isExpanded: false,
  };
  // Una sola fuente de precio: el mismo cálculo que usa el editor al cambiar canal o cantidad.
  recalcItemPricesAndModifiers(item);
  if (import.meta.env.DEV && item.priceMode === 'auto' && Math.abs(item.unit_price - Number(finalPrice || 0)) > 0.009) {
    console.warn('[Control diario] El precio del configurador y el del editor difieren', finalPrice, item.unit_price);
  }

  if (editing) {
    items.splice(editIdx, 1, item);
    // Si al editar quedó igual a otra fila, se juntan las cantidades.
    const sig = itemSignature(item);
    const twinIdx = sig ? items.findIndex((it, i) => i !== editIdx && itemSignature(it) === sig) : -1;
    if (twinIdx !== -1) {
      items[twinIdx].quantity = Number(items[twinIdx].quantity || 0) + item.quantity;
      onItemQuantityChange(items[twinIdx]);
      items.splice(editIdx, 1);
      flashAdded(twinIdx > editIdx ? twinIdx - 1 : twinIdx);
    } else {
      flashAdded(editIdx);
    }
  } else {
    const sig = isWeight ? null : itemSignature(item);
    const same = sig ? items.find(it => itemSignature(it) === sig) : null;
    if (same) {
      same.quantity = Number(same.quantity || 0) + 1;
      onItemQuantityChange(same);
      flashAdded(items.indexOf(same));
    } else {
      items.push(item);
      flashAdded(items.length - 1);
    }
  }
  autoSyncPayments();
  closeModifierModal();
}

function openEditItemModal(idx) {
  const item = formData.value.items[idx];
  const product = getProduct(item?.product_id);
  if (!product) return;
  selectedProductForModal.value = product;
  editingItemIndex.value = idx;
  modalInitialData.value = {
    cartKey: `cd_item_${idx}`,
    modifiers: item.modifiers || [],
    itemNote: item.item_note || '',
    allergenFlags: [],
    isTakeaway: Boolean(item.is_takeaway),
    weightGrams: item.weightGrams || 350,
  };
  showModifierModal.value = true;
}

function closeModifierModal() {
  showModifierModal.value = false;
  selectedProductForModal.value = null;
  editingItemIndex.value = null;
  modalInitialData.value = null;
}

function itemHasConfig(item) {
  const p = getProduct(item.product_id);
  return Boolean(p) && (getProductOptionGroups(p).length > 0 || Boolean(p.is_weight_based));
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
  } else if (item.priceMode === 'weight') {
    item.priceMode = 'auto';
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

function syncPaymentToTotal() {
  if (formData.value.payments.length === 1) {
    formData.value.payments[0].amount = Number(calculatedTotal.value.toFixed(2));
  } else if (formData.value.payments.length > 1) {
    const otherPayments = formData.value.payments.slice(0, -1).reduce((sum, p) => sum + (Number(p.amount) || 0), 0);
    const lastIdx = formData.value.payments.length - 1;
    formData.value.payments[lastIdx].amount = Math.max(0, Number((calculatedTotal.value - otherPayments).toFixed(2)));
  }
  errorMessage.value = '';
}

function applyPaymentDifferenceAsSurcharge() {
  const diff = Number((totalPayments.value - (calculatedSubtotal.value - Number(formData.value.discount_amount || 0))).toFixed(2));
  if (diff > 0) {
    formData.value.surcharge_amount = diff;
  }
  errorMessage.value = '';
}

function scrollToError() {
  setTimeout(() => {
    const errorEl = document.querySelector('.error-banner-footer') || document.querySelector('.error-banner') || document.querySelector('.payment-difference-alert-box');
    if (errorEl) {
      errorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }, 50);
}

async function handleSave() {
  errorMessage.value = '';
  successMessage.value = '';

  if (!formData.value.created_at) {
    showSaleDetails.value = true;
    errorMessage.value = 'Debes ingresar una fecha y hora válida.';
    scrollToError();
    return;
  }
  if (!formData.value.items || formData.value.items.length === 0) {
    errorMessage.value = 'Debes agregar al menos un producto a la venta.';
    scrollToError();
    return;
  }
  if (!formData.value.edit_reason || formData.value.edit_reason.trim().length < 5) {
    errorMessage.value = 'El motivo/justificación es obligatorio para el registro de auditoría (mínimo 5 caracteres).';
    scrollToError();
    return;
  }
  if (Math.abs(paymentDifference.value) > 0.05) {
    errorMessage.value = `Los pagos ingresados (Bs ${totalPayments.value.toFixed(2)}) no coinciden con el total de la comanda (Bs ${calculatedTotal.value.toFixed(2)}). Corrige el pago o pulsa "Ajustar Pago".`;
    scrollToError();
    return;
  }

  isSaving.value = true;

  const payload = {
    created_at: formData.value.created_at
      ? (formData.value.created_at.includes('T')
          ? formData.value.created_at.replace('T', ' ') + (formData.value.created_at.length === 16 ? ':00' : '')
          : formData.value.created_at)
      : null,
    cashier_id: formData.value.cashier_id ? Number(formData.value.cashier_id) : null,
    customer_id: formData.value.customer_id ? Number(formData.value.customer_id) : null,
    subtotal: Number((calculatedSubtotal.value + Number(formData.value.surcharge_amount || 0)).toFixed(2)),
    discount_amount: Number(formData.value.discount_amount || 0),
    total_amount: calculatedTotal.value,
    source: saleOrigin.value === 'pedidosya' ? 'pedidosya' : 'pos',
    is_takeaway: formData.value.is_takeaway,
    notes: Number(formData.value.surcharge_amount || 0) > 0
      ? (formData.value.notes 
          ? `${formData.value.notes} | Recargo/Empaque: Bs ${Number(formData.value.surcharge_amount).toFixed(2)}`
          : `Recargo/Empaque: Bs ${Number(formData.value.surcharge_amount).toFixed(2)}`)
      : formData.value.notes,
    edit_reason: formData.value.edit_reason.trim(),
    items: formData.value.items.map(item => ({
      product_id: item.product_id,
      quantity: Number(item.quantity),
      // Los gramos pesados alimentan el costo por receta de los productos por peso
      weight_grams: item.priceMode === 'weight' && Number(item.weightGrams) > 0 ? Number(item.weightGrams) : null,
      unit_price: Number(item.unit_price),
      subtotal: Number(item.subtotal),
      is_takeaway: item.is_takeaway,
      item_note: item.item_note || null,
      // Motivo del precio manual en pedidos PedidosYa (queda en la línea y en auditoría)
      price_override_reason: saleOrigin.value === 'pedidosya' && item.priceMode === 'manual' && item.priceOverrideReason?.trim()
        ? item.priceOverrideReason.trim()
        : null,
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
      const updatedSale = result?.sale || result;
      successMessage.value = `Venta #${updatedSale.order_number || props.sale.id} actualizada con éxito.`;
    } else {
      result = await turnoStore.adminCreateSale(payload);
      const createdSale = result?.sale || result;
      successMessage.value = `Venta #${createdSale.order_number || 'creada'} registrada correctamente.`;
    }

    setTimeout(() => {
      emit('saved', result);
      if (!props.semiLote || isEditMode.value) {
        emit('close');
      } else {
        // Modo semi-lote: reset items and payments, but keep metadata
        formData.value.items = [];
        formData.value.payments = [{ method: 'cash', amount: 0 }];
        formData.value.discount_amount = 0;
        // El motivo se conserva: las ventas de un mismo talonario comparten justificación.
      }
    }, 1200);
  } catch (err) {
    console.error('Error guardando venta admin:', err);
    errorMessage.value = err.message || 'Error al procesar la venta en el servidor.';
  } finally {
    isSaving.value = false;
  }
}

function resetForm() {
  initForm();
}
</script>

<template>
  <div v-if="visible" :class="inline ? 'inline-editor-overlay' : 'editor-overlay'" @click.self="!inline && $emit('close')">
    <div :class="inline ? 'inline-editor-card' : 'editor-modal'">
      <!-- HEADER -->
      <div v-if="!inline" class="modal-header">
        <div class="header-titles">
          <span class="badge-super-admin">🔒 Modo Admin / Super Admin</span>
          <h3>{{ isEditMode ? `Editar Venta #${sale?.order_number}` : 'Registrar Venta Retroactiva / Manual' }}</h3>
        </div>
        <button class="close-btn" @click="$emit('close')" aria-label="Cerrar">✕</button>
      </div>

      <!-- AUDIT WARNING BANNER -->
      <div v-if="inline" class="audit-warning-banner audit-warning-banner--compact">
        Cada venta descuenta inventario y queda en auditoría con su motivo.
      </div>
      <div v-else class="audit-warning-banner">
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
        <!-- DATOS DE LA VENTA: una línea de resumen; el origen queda a la vista porque cambia los precios -->
        <section class="cd-meta" aria-label="Datos de la venta">
          <div class="cd-meta-bar">
            <button
              type="button"
              class="cd-meta-toggle"
              :aria-expanded="showSaleDetails"
              aria-controls="cd-meta-fields"
              @click="showSaleDetails = !showSaleDetails"
            >
              <span class="cd-meta-parts">
                <span v-for="(part, i) in saleMetaParts" :key="i" class="cd-meta-part">{{ part }}</span>
              </span>
              <span class="cd-meta-action">{{ showSaleDetails ? 'Ocultar' : 'Cambiar' }}</span>
            </button>
            <div class="destination-toggle" role="group" aria-label="Origen de la venta">
              <button 
                type="button" 
                class="dest-btn" 
                :class="{ active: saleOrigin === 'pos_mesa' }"
                @click="saleOrigin = 'pos_mesa'"
              >
                En mesa
              </button>
              <button 
                type="button" 
                class="dest-btn" 
                :class="{ active: saleOrigin === 'pos_llevar' }"
                @click="saleOrigin = 'pos_llevar'"
              >
                Para llevar
              </button>
              <button 
                type="button" 
                class="dest-btn" 
                :class="{ active: saleOrigin === 'pedidosya' }"
                @click="saleOrigin = 'pedidosya'"
              >
                PedidosYa
              </button>
            </div>
          </div>
          <div v-show="showSaleDetails" id="cd-meta-fields" class="cd-meta-fields">
              <div class="form-group">
                <label for="cd-f-fecha">Fecha y hora</label>
                <input 
              type="datetime-local" 
              v-model="formData.created_at" 
              id="cd-f-fecha" class="form-input"
              required
            />
              </div>
              <div class="form-group">
                <label for="cd-f-cajero">Registrada por</label>
                <select v-model="formData.cashier_id" id="cd-f-cajero" class="form-input">
              <option value="">-- Mismo Super Admin --</option>
              <option v-for="u in usersList" :key="u.id" :value="u.id">
                {{ u.name }} ({{ u.role }})
              </option>
            </select>
              </div>
              <div class="form-group">
                <label for="cd-f-cliente">Cliente</label>
                <select v-model="formData.customer_id" id="cd-f-cliente" class="form-input">
              <option value="">-- Cliente Ocasional (Sin registro) --</option>
              <option v-for="c in catalogStore.customers" :key="c.id" :value="c.id">
                {{ c.name }} {{ c.ci_or_phone ? `(${c.ci_or_phone})` : '' }}
              </option>
            </select>
              </div>
              <div class="form-group">
                <label for="cd-f-nota">Nota de la venta</label>
                <input 
              type="text" 
              v-model="formData.notes" 
              placeholder="Ej: Pedido manual / Talonario de contingencia" 
              id="cd-f-nota" class="form-input" 
            />
              </div>
          </div>
        </section>

        <!-- SECCIÓN 3: PRODUCTOS E ITEMS -->
        <div class="items-section-wrapper">
          <!-- BANNER DE CONTEXTO PEDIDOSYA -->
          <div v-if="saleOrigin === 'pedidosya'" class="pedidosya-context-banner">
            <span class="pedidosya-banner-icon">🛵</span>
            <div class="pedidosya-banner-text">
              <strong>Tarifario PedidosYa activo:</strong> Los productos aplican automáticamente su precio de delivery registrado en el sistema.
              <div v-if="hasProductsWithoutDeliveryPrice" class="pedidosya-warning-note">
                ⚠️ Hay productos en la comanda sin precio de PedidosYa definido en el catálogo (usando precio de mostrador). Puedes usar <strong>✏️ Manual Libre</strong> si necesitas ajustar el monto unitario.
              </div>
            </div>
          </div>

          <div class="items-section-header">
            <h4 class="cd-items-title">Comanda</h4>
            <button
              type="button"
              class="cd-link-btn"
              :aria-expanded="showProductPicker"
              @click="showProductPicker = !showProductPicker"
            >
              {{ showProductPicker ? 'Ocultar catálogo' : 'Mostrar catálogo' }}
            </button>
          </div>

          <!-- CATÁLOGO: pestañas de Ventas + buscador en una sola línea -->
          <div v-if="showProductPicker" class="product-picker-panel">
            <div class="picker-filters">
              <CategoryFilter
                class="cd-cat-tabs"
                :categories="pickerCategories"
                :activeCategoryId="pickerCategoryId"
                @select="selectPickerCategory"
              />
              <div class="picker-search-wrap">
                <input
                  type="search"
                  v-model="productSearchQuery"
                  placeholder="Buscar producto"
                  aria-label="Buscar producto"
                  class="picker-search-input"
                />
              </div>
            </div>

            <div class="picker-products-grid">
              <button 
                type="button" 
                v-for="p in filteredAvailableProducts" 
                :key="p.id" 
                class="product-picker-card"
                :title="getProductOptionGroups(p).length || p.is_weight_based ? `${p.name}: elegir opciones` : `Agregar ${p.name}`"
                @click="addProductToSale(p)"
              >
                <div class="card-info">
                  <strong class="card-name">{{ p.name }}</strong>
                  <span class="card-price">
                    <template v-if="p.is_weight_based">
                      Bs {{ (Number(p.price_per_gram || 0) * 100).toFixed(2) }}/100g
                    </template>
                    <template v-else-if="!Number(saleOrigin === 'pedidosya' ? (p.delivery_price ?? p.price) : p.price) && getProductOptionGroups(p).length">
                      Elige opciones
                    </template>
                    <template v-else-if="saleOrigin === 'pedidosya' && p.delivery_price">
                      🛵 Bs {{ Number(p.delivery_price).toFixed(2) }}
                    </template>
                    <template v-else>
                      Bs {{ Number(p.price).toFixed(2) }}
                    </template>
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
          <div v-if="formData.items.length === 0" class="cd-empty">
            <template v-if="showProductPicker">La comanda está vacía. Toca un producto del catálogo para agregarlo.</template>
            <template v-else>
              La comanda está vacía.
              <button type="button" class="cd-link-btn" @click="showProductPicker = true">Mostrar catálogo</button>
            </template>
          </div>

          <ul v-else class="cd-ticket" aria-label="Ítems de la comanda">
            <li
              v-for="(item, idx) in formData.items"
              :key="idx"
              class="cd-line"
              :class="{ 'cd-line--flash': lastAddedIdx === idx, 'cd-line--open': item.isExpanded }"
            >
              <div class="cd-line-main">
                <span class="cd-line-name">{{ getProduct(item.product_id)?.name || 'Producto' }}</span>
                <span v-if="item.modifiers.length || item.priceMode !== 'auto' || item.item_note || (item.is_takeaway && saleOrigin === 'pos_mesa')" class="cd-line-pills">
                  <span v-if="item.priceMode === 'manual'" class="mod-pill mod-pill--flag">Precio manual</span>
                  <span v-else-if="item.priceMode === 'weight'" class="mod-pill mod-pill--flag">{{ item.weightGrams }} g</span>
                  <span
                    v-for="(mod, mIdx) in item.modifiers"
                    :key="mIdx"
                    class="mod-pill"
                    :class="{ 'mod-pill--size': /tamaño|size/i.test(mod.group_name || '') }"
                  >{{ mod.option_name }}<template v-if="mod.price > 0"> +{{ Number(mod.price).toFixed(2) }}</template></span>
                  <span v-if="item.is_takeaway && saleOrigin === 'pos_mesa'" class="mod-pill">Para llevar</span>
                  <span v-if="item.item_note" class="mod-pill mod-pill--note">Nota: {{ item.item_note }}</span>
                </span>
              </div>

              <div class="cd-qty" role="group" :aria-label="`Cantidad de ${getProduct(item.product_id)?.name || 'producto'}`">
                <button type="button" class="cd-qty-btn" aria-label="Uno menos" @click="stepItemQuantity(item, -1)">−</button>
                <input
                  type="number"
                  min="1"
                  step="1"
                  inputmode="numeric"
                  v-model.number="item.quantity"
                  @input="onItemQuantityChange(item)"
                  class="cd-qty-input"
                  aria-label="Cantidad"
                />
                <button type="button" class="cd-qty-btn" aria-label="Uno más" @click="stepItemQuantity(item, 1)">+</button>
              </div>

              <div class="cd-line-price">
                <strong class="cd-line-subtotal">Bs {{ item.subtotal.toFixed(2) }}</strong>
                <span v-if="item.quantity > 1" class="cd-line-unit">Bs {{ item.unit_price.toFixed(2) }} c/u</span>
              </div>

              <div class="cd-line-tools">
                <button v-if="itemHasConfig(item)" type="button" class="cd-tool cd-tool--edit" title="Editar opciones" aria-label="Editar opciones" @click="openEditItemModal(idx)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </button>
                <button
                  type="button"
                  class="cd-tool cd-tool--extra"
                  :class="{ 'cd-tool--on': item.isExpanded }"
                  :aria-expanded="item.isExpanded"
                  title="Precio y nota"
                  aria-label="Precio y nota"
                  @click="item.isExpanded = !item.isExpanded"
                >
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></svg>
                </button>
                <button type="button" class="cd-tool cd-tool--danger" title="Quitar de la comanda" aria-label="Quitar de la comanda" @click="removeItem(idx)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
                </button>
              </div>

              <!-- PRECIO Y NOTA (plegado): modo de precio, precio manual, motivo y nota de cocina -->
              <div v-show="item.isExpanded" class="cd-line-extra">
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
                      placeholder="0.00"
                    />
                  </div>
                  <!-- Referencia visual de catálogo al estar en modo PedidosYa -->
                  <div v-if="saleOrigin === 'pedidosya'" class="catalog-price-hint">
                    💡 Catálogo tienda: <strong>Bs {{ (Number(getProduct(item.product_id)?.price) || 0).toFixed(2) }}</strong>
                    <span v-if="getProduct(item.product_id)?.delivery_price"> | PedidosYa: <strong>Bs {{ (Number(getProduct(item.product_id)?.delivery_price) || 0).toFixed(2) }}</strong></span>
                  </div>
                  <label v-if="saleOrigin === 'pedidosya'" class="override-reason-field">
                    <span>Motivo del ajuste:</span>
                    <input
                      v-model="item.priceOverrideReason"
                      type="text"
                      maxlength="200"
                      class="input-override-reason"
                      placeholder="Ej: Promo PedidosYa"
                    />
                  </label>
                </div>
              </div>

                <div class="item-card-body">
                <!-- AVISO INFORMATIVO SI ESTÁ EN MODO MANUAL -->
                <div v-if="item.priceMode === 'manual'" class="manual-mode-notice">
                  <span>✏️ <strong>Modo Manual Libre activo:</strong> El precio unitario está fijo en <strong>Bs {{ Number(item.unit_price || 0).toFixed(2) }}</strong>. Si deseas que los tamaños y agregados calculen el monto automáticamente, pulsa <button type="button" class="btn-link-switch-auto" @click="setItemPriceMode(item, 'auto')">🔒 Catálogo Auto</button>.</span>
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
            </li>
          </ul>
        </div>

        <!-- RESUMEN DE TOTALES -->
        <div class="totals-bar">
          <div class="total-row">
            <span>Subtotal Ítems:</span>
            <strong>Bs {{ calculatedSubtotal.toFixed(2) }}</strong>
          </div>
          <div class="total-row discount-row" v-if="saleOrigin !== 'pedidosya' || Number(formData.discount_amount) > 0">
            <span>Descuento:</span>
            <input 
              type="number" 
              min="0"
              step="0.5" 
              v-model.number="formData.discount_amount" 
              @input="autoSyncPayments"
              class="discount-input" 
              placeholder="0.00"
            />
          </div>
          <div class="total-row surcharge-row" v-if="saleOrigin === 'pedidosya' || Number(formData.surcharge_amount) > 0">
            <span title="Cargos adicionales como empaque, bolsas o recargo de envío">🛵 Recargo / Empaque:</span>
            <input 
              type="number" 
              min="0"
              step="0.5" 
              v-model.number="formData.surcharge_amount" 
              @input="autoSyncPayments"
              class="discount-input surcharge-input" 
              placeholder="0.00"
            />
          </div>
          <div class="total-row total-highlight">
            <span>Total a Cobrar:</span>
            <div style="display: flex; align-items: center; gap: 0.25rem;">
              <strong>Bs</strong>
              <input 
                type="number" 
                min="0" 
                step="0.5" 
                v-model.lazy="calculatedTotal"
                class="discount-input"
                style="font-weight: bold; width: 100px; padding: 0.25rem; font-size: 1rem;"
                title="Si modificas el total final, se ajustará automáticamente como recargo o descuento."
              />
            </div>
          </div>
        </div>

        <hr class="section-divider" />

        <!-- SECCIÓN 4: MÉTODOS DE PAGO -->
        <div class="payments-section">
          <div class="payments-header">
            <h4>💳 Métodos de Pago</h4>
            <div class="quick-method-pills">
              <span style="font-size: 11px; color: var(--tw-fg-64748b);">Llenado Rápido:</span>
              <button 
                v-if="saleOrigin === 'pedidosya'" 
                type="button" 
                class="method-pill method-pill-pya" 
                @click="quickSetPaymentMethod('delivery_platform')"
              >
                🛵 PedidosYa Total
              </button>
              <button type="button" class="method-pill" @click="quickSetPaymentMethod('cash')">💵 Efectivo Total</button>
              <button type="button" class="method-pill" @click="quickSetPaymentMethod('qr')">📱 QR Total</button>
              <button type="button" class="method-pill" @click="quickSetPaymentMethod('card')">💳 Tarjeta Total</button>
              <button type="button" class="btn btn-xs btn-outline-primary" @click="addPayment">＋ Pago Mixto</button>
            </div>
          </div>

          <div class="payment-rows">
            <div v-for="(p, pIdx) in formData.payments" :key="pIdx" class="payment-item-row">
              <select v-model="p.method" class="form-input payment-method-select">
                <option value="delivery_platform">🛵 PedidosYa / Delivery Platform</option>
                <option value="cash">💵 Efectivo (Cash / Gaveta)</option>
                <option value="qr">📱 Pago QR</option>
                <option value="card">💳 Tarjeta Débito / Crédito</option>
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

          <!-- ALERTA INTELIGENTE DE DISCREPANCIA CON ACCIONES EN 1 CLIC -->
          <div v-if="Math.abs(paymentDifference) > 0.01" class="payment-difference-alert-box">
            <div class="diff-header">
              <span class="diff-icon">⚠️</span>
              <div class="diff-text">
                <strong>Diferencia de pagos:</strong> Hay una discrepancia de Bs {{ Math.abs(paymentDifference).toFixed(2) }} (Pagos: <strong>Bs {{ totalPayments.toFixed(2) }}</strong> vs Total Comanda: <strong>Bs {{ calculatedTotal.toFixed(2) }}</strong>).
              </div>
            </div>
            <div class="diff-actions">
              <button 
                type="button" 
                class="btn-diff-action btn-diff-sync" 
                @click="syncPaymentToTotal"
                title="Ajusta el monto del método de pago para que coincida exactamente con los Bs {{ calculatedTotal.toFixed(2) }} de la comanda"
              >
                ⚡ Ajustar Pago a Bs {{ calculatedTotal.toFixed(2) }}
              </button>
              <button 
                v-if="totalPayments > calculatedTotal" 
                type="button" 
                class="btn-diff-action btn-diff-surcharge" 
                @click="applyPaymentDifferenceAsSurcharge"
                title="Suma la diferencia al Recargo/Empaque para que la comanda totalice exactamente lo pagado"
              >
                🛵 Sumar diferencia (+Bs {{ (totalPayments - calculatedTotal).toFixed(2) }}) como Recargo/Empaque
              </button>
            </div>
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
            <div class="cd-reason-chips" role="group" aria-label="Motivos frecuentes">
              <button
                v-for="r in quickReasons"
                :key="r"
                type="button"
                class="cd-reason-chip"
                :aria-pressed="formData.edit_reason === r"
                @click="formData.edit_reason = r"
              >{{ r }}</button>
            </div>
          </div>
        </div>
      </div>

      <!-- PIE: en Control diario es una barra fija con el total y Registrar; en el modal, el pie de siempre -->
      <div :class="{ 'cd-dock': inline }">
      <!-- ALERTA DE ERROR VISIBLE EN EL FOOTER SI EL GUARDADO ES BLOQUEADO -->
      <div v-if="errorMessage" class="error-banner-footer">
        <div class="error-footer-content">
          <span class="error-footer-icon">⚠️</span>
          <span class="error-footer-text">{{ errorMessage }}</span>
        </div>
        <button 
          v-if="Math.abs(paymentDifference) > 0.05" 
          type="button" 
          class="btn-autofix-footer"
          @click="syncPaymentToTotal"
        >
          ⚡ Sincronizar Pago a Bs {{ calculatedTotal.toFixed(2) }}
        </button>
      </div>

        <div v-if="inline" class="cd-bar">
          <span class="cd-bar-count">{{ itemCount }} {{ itemCount === 1 ? 'ítem' : 'ítems' }}</span>
          <strong class="cd-bar-total">Bs {{ calculatedTotal.toFixed(2) }}</strong>
          <span class="cd-bar-added" aria-live="polite">{{ successMessage || (lastAddedLabel ? `Agregado: ${lastAddedLabel}` : '') }}</span>
          <button type="button" class="cd-bar-clear" :disabled="isSaving" @click="resetForm()">Limpiar</button>
          <button type="button" class="cd-bar-save btn-save" :disabled="isSaving" @click="handleSave">
            {{ isSaving ? 'Guardando…' : 'Registrar venta' }}
          </button>
        </div>
      <!-- FOOTER ACCIONES -->
      <div v-else class="modal-footer">
        <button 
          type="button" 
          class="btn btn-ghost" 
          @click="inline ? resetForm() : $emit('close')" 
          :disabled="isSaving"
        >
          {{ inline ? 'Limpiar Campos' : 'Cancelar' }}
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

    <ModifierModal
      :show="showModifierModal"
      :product="selectedProductForModal"
      :initialData="modalInitialData"
      :channel="modalChannel"
      @close="closeModifierModal"
      @confirm="handleConfirmModal"
    />
  </div>
</template>

<style scoped>
.inline-editor-overlay {
  display: block;
  width: 100%;
  height: auto;
}

.inline-editor-card {
  background: transparent;
  border-radius: 0;
  box-shadow: none;
  width: 100%;
  max-width: 100%;
  display: flex;
  flex-direction: column;
}

.editor-overlay {
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
  background: var(--surface, var(--tw-bg-fff));
  border-radius: 20px;
  width: 100%;
  max-width: 900px;
  max-height: 94vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
  border: 1.5px solid var(--border, var(--tw-bg-e2e8f0));
  overflow: hidden;
}

.modal-header {
  padding: 16px 24px;
  border-bottom: 1px solid var(--border, var(--tw-bg-e2e8f0));
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: var(--cream-50, var(--tw-bg-faf5f0));
}

.badge-super-admin {
  display: inline-block;
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  background: var(--tw-bg-fef3c7);
  color: var(--tw-fg-b45309);
  padding: 3px 8px;
  border-radius: 6px;
  margin-bottom: 4px;
  border: 1px solid var(--tw-bg-fde68a);
}

.header-titles h3 {
  margin: 0;
  font-size: 18px;
  font-weight: 800;
  color: var(--ink-900, var(--tw-fg-0f172a));
}

.close-btn {
  background: transparent;
  border: none;
  font-size: 20px;
  cursor: pointer;
  color: var(--ink-500, var(--tw-fg-64748b));
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
  background: var(--tw-bg-eff6ff);
  border-bottom: 1px solid var(--tw-bg-bfdbfe);
  padding: 10px 24px;
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 13px;
  color: var(--tw-fg-1e40af);
}

.error-banner {
  background: var(--tw-bg-fef2f2);
  border-bottom: 1px solid var(--tw-bg-fecaca);
  color: var(--tw-fg-dc2626);
  padding: 10px 24px;
  font-size: 13px;
  font-weight: 600;
}

.success-banner {
  background: var(--tw-bg-f0fdf4);
  border-bottom: 1px solid var(--tw-bg-bbf7d0);
  color: var(--tw-fg-16a34a);
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
  color: var(--ink-700, var(--tw-fg-334155));
}

.required-label {
  color: var(--tw-fg-b45309) !important;
}

.form-input, .form-input-sm, .form-textarea {
  padding: 8px 12px;
  border: 1.5px solid var(--border, var(--tw-bg-cbd5e1));
  border-radius: 8px;
  font-size: 13px;
  background: var(--surface, var(--tw-bg-fff));
  color: var(--ink-900, var(--tw-fg-0f172a));
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
  background: var(--cream-100, var(--tw-bg-f1f5f9));
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
  color: var(--ink-600, var(--tw-fg-475569));
  transition: all 0.2s;
}

.dest-btn.active {
  background: var(--surface, var(--tw-bg-fff));
  color: var(--primary-700, var(--tw-fg-6d28d9));
  box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.section-divider {
  border: none;
  border-top: 1px dashed var(--border, var(--tw-bg-e2e8f0));
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
  color: var(--ink-900, var(--tw-fg-0f172a));
  display: inline-block;
  margin-right: 8px;
}

.items-count-tag {
  font-size: 12px;
  background: var(--cream-100, var(--tw-bg-f1f5f9));
  color: var(--ink-600, var(--tw-fg-475569));
  padding: 2px 8px;
  border-radius: 12px;
  font-weight: 600;
}

/* PRODUCT PICKER PANEL */
.product-picker-panel {
  background: var(--cream-50, var(--tw-bg-faf5f0));
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
  color: var(--tw-fg-94a3b8);
}

.picker-search-input {
  width: 100%;
  padding: 7px 30px 7px 32px;
  border: 1px solid var(--tw-bg-cbd5e1);
  border-radius: 8px;
  font-size: 13px;
  background: var(--tw-bg-fff);
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
  color: var(--tw-fg-94a3b8);
  cursor: pointer;
  font-size: 12px;
}

.picker-category-select {
  width: 200px;
  padding: 7px 10px;
  border: 1px solid var(--tw-bg-cbd5e1);
  border-radius: 8px;
  font-size: 13px;
  background: var(--tw-bg-fff);
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
  background: var(--tw-bg-fff);
  border: 1px solid var(--border, var(--tw-bg-e2e8f0));
  border-radius: 10px;
  cursor: pointer;
  text-align: left;
  transition: all 0.15s ease;
}

.product-picker-card:hover {
  border-color: var(--primary, #8b5cf6);
  background: var(--tw-bg-f5f3ff);
  transform: translateY(-1px);
}

.card-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.card-name {
  font-size: 12px;
  color: var(--ink-900, var(--tw-fg-0f172a));
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 135px;
}

.card-price {
  font-size: 11px;
  font-weight: 700;
  color: var(--primary-700, var(--tw-fg-6d28d9));
}

.card-add-icon {
  font-size: 15px;
  font-weight: 700;
  color: var(--primary, var(--tw-fg-8b5cf6));
}

.no-products-found {
  grid-column: 1 / -1;
  text-align: center;
  padding: 16px;
  color: var(--tw-fg-94a3b8);
  font-size: 12px;
}

/* EMPTY ITEMS */
.empty-items-alert {
  padding: 28px;
  text-align: center;
  background: var(--cream-50, var(--tw-bg-faf5f0));
  border: 1.5px dashed var(--border, var(--tw-bg-cbd5e1));
  border-radius: 14px;
  color: var(--ink-600, var(--tw-fg-475569));
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
  background: var(--tw-bg-fff);
  border: 1.5px solid var(--border, var(--tw-bg-e2e8f0));
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 1px 4px rgba(0,0,0,0.04);
  transition: border-color 0.2s;
}

.item-card:hover {
  border-color: var(--tw-bg-cbd5e1);
}

@keyframes item-flash { 0% { box-shadow: 0 0 0 3px var(--primary-200, #ddd6fe); } 100% { box-shadow: 0 0 0 0 transparent; } }
.item-card--flash { animation: item-flash .9s ease-out; }
.picker-live-summary {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 6px 10px;
  border-radius: 8px;
  background: var(--tw-bg-fff, #fff);
  border: 1px dashed var(--primary-200, #ddd6fe);
  font-size: 13px;
}
.item-summary-line {
  padding: 4px 14px 8px;
  font-size: 12px;
  color: var(--text-muted, #64748b);
  line-height: 1.3;
}
.item-card--compact .item-card-header { border-bottom: none; }
.item-card-header {
  padding: 10px 14px;
  background: var(--cream-50, var(--tw-bg-f8fafc));
  border-bottom: 1px solid var(--border, var(--tw-bg-f1f5f9));
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
  background: var(--tw-bg-e2e8f0);
  color: var(--tw-fg-475569);
  padding: 2px 6px;
  border-radius: 6px;
}

.item-product-select {
  flex: 1;
  max-width: 320px;
  padding: 5px 8px;
  border: 1px solid var(--tw-bg-cbd5e1);
  border-radius: 6px;
  font-size: 13px;
  font-weight: 700;
  color: var(--ink-900, var(--tw-fg-0f172a));
  background: var(--tw-bg-fff);
}

.item-header-right {
  display: flex;
  align-items: center;
  gap: 12px;
}

.qty-stepper {
  display: flex;
  align-items: center;
  border: 1px solid var(--tw-bg-cbd5e1);
  border-radius: 6px;
  background: var(--tw-bg-fff);
  overflow: hidden;
}

.qty-btn {
  background: transparent;
  border: none;
  padding: 4px 8px;
  font-weight: 800;
  cursor: pointer;
  color: var(--tw-fg-475569);
}

.qty-btn:hover {
  background: var(--tw-bg-f1f5f9);
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
  color: var(--tw-fg-64748b);
}

.item-subtotal-val {
  font-size: 14px;
  color: var(--primary-700, var(--tw-fg-6d28d9));
}

.btn-toggle-expand {
  background: transparent;
  border: 1px solid var(--tw-bg-e2e8f0);
  border-radius: 6px;
  padding: 4px 8px;
  font-size: 10px;
  cursor: pointer;
  color: var(--tw-fg-64748b);
}

.btn-toggle-expand:hover {
  background: var(--tw-bg-f1f5f9);
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
  background: var(--tw-bg-f1f5f9);
  border-bottom: 1px solid var(--border, var(--tw-bg-e2e8f0));
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
  color: var(--tw-fg-64748b);
}

.mode-pills {
  display: flex;
  gap: 4px;
}

.mode-pill-btn {
  border: 1px solid var(--tw-bg-cbd5e1);
  background: var(--tw-bg-fff);
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
  color: var(--tw-fg-475569);
  cursor: pointer;
  transition: all 0.15s;
}

.mode-pill-btn:hover {
  background: var(--tw-bg-f8fafc);
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
  color: var(--tw-fg-475569);
}

.weight-field label {
  font-weight: 700;
}

.input-weight-g {
  width: 65px;
  padding: 2px 6px;
  border: 1px solid var(--tw-bg-cbd5e1);
  border-radius: 5px;
  font-size: 12px;
  font-weight: 700;
  text-align: right;
  background: var(--tw-bg-fff);
}

.input-price-per-g {
  width: 70px;
  padding: 2px 6px;
  border: 1px solid var(--tw-bg-cbd5e1);
  border-radius: 5px;
  font-size: 12px;
  font-weight: 700;
  text-align: right;
  background: var(--tw-bg-fff);
}

.unit-tag {
  font-size: 11px;
  color: var(--tw-fg-64748b);
  font-weight: 600;
}

.weight-calc-hint {
  font-size: 11px;
  font-weight: 800;
  color: var(--primary-700, var(--tw-fg-6d28d9));
  background: #ede9fe;
  padding: 2px 6px;
  border-radius: 4px;
}

/* BANNER Y REFERENCIA PEDIDOSYA */
.pedidosya-context-banner {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--tw-bg-fff7ed);
  border: 1px solid var(--tw-bg-fdba74);
  border-left: 4px solid #ea580c;
  border-radius: 8px;
  padding: 10px 14px;
  margin-bottom: 14px;
  color: var(--tw-fg-9a3412);
  font-size: 12.5px;
  line-height: 1.45;
}

.pedidosya-banner-icon {
  font-size: 20px;
  flex-shrink: 0;
}

.pedidosya-banner-text strong {
  color: var(--tw-fg-c2410c);
  font-weight: 700;
}

.pedidosya-warning-note {
  margin-top: 4px;
  color: var(--tw-fg-b45309);
  font-size: 11.5px;
  background: var(--tw-bg-fef3c7);
  padding: 4px 8px;
  border-radius: 6px;
  border: 1px dashed var(--tw-bg-fcd34d);
}

.override-reason-field {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}
.input-override-reason {
  width: 170px;
  padding: 3px 6px;
  border: 1.5px solid var(--border);
  border-radius: 5px;
  font-family: inherit;
  font-size: 12px;
  background: var(--surface);
  color: var(--ink);
}
.catalog-price-hint {
  font-size: 11px;
  color: var(--tw-fg-c2410c);
  background: var(--tw-bg-ffedd5);
  border: 1px solid #fed7aa;
  padding: 3px 8px;
  border-radius: 6px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.catalog-price-hint strong {
  color: var(--tw-fg-9a3412);
}

.manual-mode-notice {
  background: var(--tw-bg-fef9c3);
  border: 1px solid var(--tw-bg-fde047);
  border-radius: 8px;
  padding: 8px 12px;
  font-size: 11.5px;
  color: #854d0e;
  line-height: 1.4;
  margin-bottom: 8px;
}

.btn-link-switch-auto {
  background: none;
  border: none;
  padding: 0 4px;
  font-size: 11.5px;
  font-weight: 700;
  color: var(--primary-700, var(--tw-fg-6d28d9));
  text-decoration: underline;
  cursor: pointer;
}

.btn-link-switch-auto:hover {
  color: var(--primary-900, #4c1d95);
}

.mode-manual-inputs {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px 10px;
  font-size: 11px;
  color: var(--tw-fg-475569);
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
  color: var(--tw-fg-64748b);
}

.input-manual-price {
  width: 80px;
  padding: 3px 6px;
  border: 1.5px solid var(--primary, #8b5cf6);
  border-radius: 5px;
  font-size: 12px;
  font-weight: 700;
  text-align: right;
  background: var(--tw-bg-fff);
  color: var(--primary-700, var(--tw-fg-6d28d9));
}

/* OPCIONES DEL ITEM */
.item-card-body {
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: var(--tw-bg-fff);
}

.item-option-groups {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.option-group-block {
  background: var(--cream-50, var(--tw-bg-faf5f0));
  border: 1px solid var(--border, var(--tw-bg-f1f5f9));
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
  color: var(--ink-800, var(--tw-fg-1e293b));
}

.group-limit-badge {
  font-size: 10px;
  background: var(--tw-bg-e2e8f0);
  color: var(--tw-fg-475569);
  padding: 1px 6px;
  border-radius: 4px;
  font-weight: 600;
}

.badge-required {
  background: var(--tw-bg-fef3c7);
  color: var(--tw-fg-b45309);
}

.group-counter {
  font-size: 11px;
  font-weight: 600;
  color: var(--primary-700, var(--tw-fg-6d28d9));
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
  border: 1.5px solid var(--tw-bg-cbd5e1);
  border-radius: 8px;
  background: var(--tw-bg-fff);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  color: var(--tw-fg-334155);
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
  background: var(--tw-bg-f5f3ff);
  color: var(--primary-700, var(--tw-fg-6d28d9));
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
  border: 1px solid var(--tw-bg-cbd5e1);
  border-radius: 6px;
  background: var(--tw-bg-fff);
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  color: var(--tw-fg-475569);
  transition: all 0.15s ease;
}

.chip-indicator {
  font-size: 11px;
  font-weight: 800;
  color: var(--tw-fg-94a3b8);
}

.option-chip-btn.active {
  border-color: var(--primary, #8b5cf6);
  background: var(--tw-bg-f5f3ff);
  color: var(--primary-700, var(--tw-fg-6d28d9));
}

.option-chip-btn.active .chip-indicator {
  color: var(--primary, var(--tw-fg-8b5cf6));
}

.option-chip-btn.limit-disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.opt-price-add {
  font-size: 10px;
  color: var(--tw-fg-b45309);
  background: var(--tw-bg-fef3c7);
  padding: 1px 4px;
  border-radius: 4px;
}

/* ITEM FOOTER INLINE */
.item-footer-inline {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border-top: 1px dashed var(--tw-bg-e2e8f0);
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
  border-bottom: 1px dotted var(--tw-bg-cbd5e1);
  font-size: 12px;
  padding: 3px 6px;
  background: transparent;
  color: var(--tw-fg-334155);
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
  color: var(--tw-fg-475569);
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
  background: var(--cream-50, var(--tw-bg-f8fafc));
  border-radius: 10px;
}

.total-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--ink-700, var(--tw-fg-334155));
}

.discount-input {
  width: 70px;
  padding: 4px 6px;
  border: 1px solid var(--border);
  border-radius: 6px;
  text-align: right;
  font-size: 13px;
  /* Antes heredaba fondo blanco del navegador: en modo oscuro el texto claro no se leía */
  background: var(--surface);
  color: var(--ink-900);
}

.surcharge-row {
  color: var(--tw-fg-ea580c) !important;
  font-weight: 600;
}

.surcharge-input {
  border-color: var(--tw-bg-fdba74) !important;
  background: var(--tw-bg-fff7ed) !important;
  color: var(--tw-fg-c2410c) !important;
  font-weight: 700;
}

.total-highlight {
  font-size: 15px;
  color: var(--primary-700, var(--tw-fg-6d28d9));
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
  color: var(--ink-900, var(--tw-fg-0f172a));
}

.quick-method-pills {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}

.method-pill {
  border: 1px solid var(--tw-bg-cbd5e1);
  background: var(--tw-bg-fff);
  padding: 3px 8px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 11px;
  font-weight: 600;
  transition: background 0.2s;
}

.method-pill:hover {
  background: var(--tw-bg-f1f5f9);
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
  color: var(--tw-fg-64748b);
}

.payment-amount-input {
  width: 120px;
  text-align: right;
}

.payment-difference-alert-box {
  background: var(--tw-bg-fef2f2);
  border: 1px solid var(--tw-bg-fecaca);
  border-radius: 8px;
  padding: 10px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 8px;
}

.diff-header {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.diff-icon {
  font-size: 16px;
  flex-shrink: 0;
}

.diff-text {
  font-size: 12px;
  color: var(--tw-fg-991b1b);
  line-height: 1.4;
}

.diff-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-left: 24px;
}

.btn-diff-action {
  font-size: 11px;
  font-weight: 700;
  padding: 5px 10px;
  border-radius: 6px;
  cursor: pointer;
  border: 1px solid transparent;
  transition: all 0.15s ease;
}

.btn-diff-sync {
  background: var(--tw-bg-fee2e2);
  color: var(--tw-fg-991b1b);
  border-color: var(--tw-bg-fca5a5);
}

.btn-diff-sync:hover {
  background: var(--tw-bg-fecaca);
}

.btn-diff-surcharge {
  background: var(--tw-bg-fef3c7);
  color: #92400e;
  border-color: var(--tw-bg-fcd34d);
}

.btn-diff-surcharge:hover {
  background: var(--tw-bg-fde68a);
}

.method-pill-pya {
  background: var(--tw-bg-fef2f2) !important;
  color: var(--tw-fg-dc2626) !important;
  border-color: var(--tw-bg-fca5a5) !important;
  font-weight: 700 !important;
}

.error-banner-footer {
  margin: 0 24px;
  background: var(--tw-bg-fef2f2);
  border: 1px solid #f87171;
  border-radius: 8px;
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  animation: shake 0.3s ease-in-out;
}

.error-footer-content {
  display: flex;
  align-items: center;
  gap: 8px;
}

.error-footer-icon {
  font-size: 16px;
  flex-shrink: 0;
}

.error-footer-text {
  font-size: 12px;
  color: var(--tw-fg-991b1b);
  font-weight: 600;
}

.btn-autofix-footer {
  background: #dc2626;
  color: #ffffff;
  border: none;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 700;
  padding: 5px 10px;
  cursor: pointer;
  flex-shrink: 0;
  transition: background 0.15s ease;
}

.btn-autofix-footer:hover {
  background: #b91c1c;
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  25% { transform: translateX(-4px); }
  75% { transform: translateX(4px); }
}

/* AUDIT REASON */
.audit-reason-group {
  background: var(--tw-bg-fffbeb);
  border: 1px solid var(--tw-bg-fde68a);
  padding: 12px 16px;
  border-radius: 10px;
}

.modal-footer {
  padding: 14px 24px;
  border-top: 1px solid var(--border, var(--tw-bg-e2e8f0));
  background: var(--cream-50, var(--tw-bg-faf5f0));
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
  color: var(--tw-fg-64748b);
}

.btn-ghost:hover {
  background: rgba(0,0,0,0.05);
}

.btn-outline-primary {
  background: transparent;
  border: 1.5px solid var(--primary, #8b5cf6);
  color: var(--primary-700, var(--tw-fg-6d28d9));
}

.btn-outline-primary:hover {
  background: var(--primary-50, var(--tw-bg-f5f3ff));
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

/* ════════════════════════════════════════════════════════════════════════
   Marca en todo el editor.
   El editor usaba var(--primary, #8b5cf6): ese token no existe en el sistema y siempre caía al
   violeta de Tailwind (fuera de marca, sin modo oscuro, blanco a 4,2:1). Se mapea a la paleta açaí.
   ════════════════════════════════════════════════════════════════════════ */
.inline-editor-overlay,
.editor-overlay {
  --primary: var(--acai-700);
  --primary-hover: var(--acai-800);
  --primary-50: var(--acai-50);
  --primary-200: var(--acai-300);
  --primary-700: var(--acai-text);
  --primary-900: var(--acai-text);
}

/* ── Control diario: la comanda habla el idioma del ticket de Ventas ── */
.audit-warning-banner--compact {
  padding: 8px 16px;
  font-size: 12.5px;
  background: var(--cream-100);
  color: var(--ink-700);
  border-bottom: 1px solid var(--border);
}

.cd-meta { display: flex; flex-direction: column; gap: 10px; }
.cd-meta-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.cd-meta-toggle {
  flex: 1 1 320px;
  min-width: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--cream-50);
  color: var(--ink-700);
  font: inherit;
  font-size: 13px;
  text-align: left;
  cursor: pointer;
}
.cd-meta-toggle:hover { border-color: var(--acai-300); }
.cd-meta-parts { display: flex; flex-wrap: wrap; row-gap: 2px; min-width: 0; }
.cd-meta-part { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 260px; }
.cd-meta-part + .cd-meta-part { border-left: 1px solid var(--border); margin-left: 10px; padding-left: 10px; }
.cd-meta-part:first-child { font-weight: 700; color: var(--ink-900); font-variant-numeric: tabular-nums; }
.cd-meta-action { flex-shrink: 0; font-weight: 700; color: var(--acai-text); }
.cd-meta-fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 14px;
  padding: 12px;
  border: 1px dashed var(--border);
  border-radius: var(--radius-sm);
}
.cd-meta .destination-toggle { flex: 0 0 auto; }

.items-section-header { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
.cd-items-title { margin: 0; font-family: var(--font-heading); font-size: 17px; font-weight: 700; color: var(--ink-900); }
.cd-link-btn {
  background: none;
  border: none;
  padding: 6px 4px;
  font: inherit;
  font-size: 13px;
  font-weight: 700;
  color: var(--acai-text);
  cursor: pointer;
}
.cd-link-btn:hover { text-decoration: underline; }

.product-picker-panel {
  background: var(--cream-50);
  border: 1px solid var(--border);
  box-shadow: none;
  padding: 10px 12px 12px;
  gap: 8px;
}
.picker-filters { display: flex; align-items: center; gap: 10px; }
.cd-cat-tabs { flex: 1 1 auto; min-width: 0; margin-bottom: 0; padding-bottom: 4px; }
.cd-cat-tabs :deep(.cat-tab) { padding: 7px 14px; font-size: 13px; border-radius: 10px; }
.picker-search-wrap { flex: 0 0 200px; position: static; }
.picker-search-input {
  width: 100%;
  padding: 8px 12px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  color: var(--ink-900);
  font: inherit;
  font-size: 13px;
}
.picker-search-input:focus { outline: none; border-color: var(--acai-500); }
.picker-products-grid {
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 8px;
  max-height: 108px;
}
.product-picker-card {
  background: var(--surface);
  border: 1.5px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 6px 8px 6px 10px;
  min-height: 46px;
  gap: 8px;
}
.product-picker-card:hover { border-color: var(--passion-400); background: var(--surface); transform: none; }
.product-picker-card:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 1px; }
.card-info { min-width: 0; }
.card-name {
  font-family: var(--font-heading);
  font-weight: 700;
  font-size: 13px;
  line-height: 1.2;
  color: var(--ink-900);
  max-width: none;
}
.card-price { color: var(--ink-700); font-variant-numeric: tabular-nums; }
.card-add-icon {
  flex-shrink: 0;
  width: 26px;
  height: 26px;
  border-radius: 8px;
  background: var(--passion-500);
  color: var(--on-accent);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  line-height: 0;
}

.cd-empty {
  padding: 16px 12px;
  text-align: center;
  color: var(--ink-500);
  font-size: 13px;
  border: 1px dashed var(--border);
  border-radius: var(--radius-sm);
}

/* Fila de comanda: nombre y opciones | cantidad | importe | herramientas */
.cd-ticket { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.cd-line {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto 96px 100px;
  grid-template-areas: "main qty price tools" "extra extra extra extra";
  align-items: center;
  column-gap: 14px;
  padding: 9px 4px;
  border-bottom: 1px dashed var(--border);
}
.cd-line:last-child { border-bottom: none; }
.cd-line-main { grid-area: main; display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.cd-line-name {
  font-family: var(--font-heading);
  font-weight: 700;
  font-size: 14.5px;
  line-height: 1.2;
  color: var(--ink-900);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
/* Opciones elegidas: mismas píldoras que el ticket de Ventas (CartItem), máximo dos líneas */
.cd-line-pills { display: flex; flex-wrap: wrap; gap: 4px; max-height: 44px; overflow: hidden; }
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
.mod-pill--flag { background: var(--passion-100); color: var(--passion-text); font-weight: 700; }
.mod-pill--note { max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
.cd-qty { grid-area: qty; display: flex; align-items: center; gap: 2px; }
.cd-qty-btn {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--cream-200);
  color: var(--ink-900);
  font-size: 16px;
  font-weight: 800;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.cd-qty-input {
  width: 38px;
  height: 32px;
  text-align: center;
  border: 1px solid transparent;
  border-radius: 8px;
  background: transparent;
  color: var(--ink-900);
  font: inherit;
  font-size: 14px;
  font-weight: 700;
  -moz-appearance: textfield;
  appearance: textfield;
}
.cd-qty-input::-webkit-inner-spin-button,
.cd-qty-input::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
.cd-qty-input:focus { outline: none; border-color: var(--acai-300); background: var(--surface); }
.cd-line-price { grid-area: price; display: flex; flex-direction: column; align-items: flex-end; }
.cd-line-subtotal {
  font-family: var(--font-heading);
  font-size: 15px;
  font-weight: 800;
  color: var(--ink-900);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.cd-line-unit { font-size: 11px; color: var(--ink-500); white-space: nowrap; }
.cd-line-tools { grid-area: tools; display: flex; justify-content: flex-end; gap: 2px; }
.cd-tool {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid transparent;
  background: transparent;
  color: var(--ink-500);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
.cd-tool svg { width: 17px; height: 17px; }
.cd-tool:hover,
.cd-tool--on { background: var(--acai-50); color: var(--acai-text); border-color: var(--acai-300); }
.cd-tool--danger:hover { background: var(--danger-100); color: var(--danger-text); border-color: transparent; }
.cd-line-extra {
  grid-area: extra;
  margin-top: 8px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  overflow: hidden;
}
.cd-line--flash { animation: cd-line-flash 0.9s ease-out; }
@keyframes cd-line-flash {
  from { background: var(--passion-100); }
  to { background: transparent; }
}

.cd-qty-btn:focus-visible,
.cd-tool:focus-visible,
.cd-link-btn:focus-visible,
.cd-meta-toggle:focus-visible,
.cd-reason-chip:focus-visible,
.cd-bar-clear:focus-visible,
.cd-bar-save:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; }

.cd-reason-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.cd-reason-chip {
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--ink-700);
  border-radius: 999px;
  padding: 4px 12px;
  font: inherit;
  font-size: 12.5px;
  cursor: pointer;
}
.cd-reason-chip:hover,
.cd-reason-chip[aria-pressed="true"] { border-color: var(--acai-300); color: var(--acai-text); background: var(--acai-50); }

/* Barra fija: el total y Registrar siempre a la mano (mismo tono que la píldora «Total» del configurador) */
.cd-dock { position: sticky; bottom: 0; z-index: 30; }
.cd-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 52px;
  padding: 7px 10px 7px 16px;
  background: var(--acai-900);
  color: #ffffff;
}
.cd-bar-count { font-size: 13px; color: rgba(255, 255, 255, 0.78); white-space: nowrap; }
.cd-bar-total {
  font-family: var(--font-heading);
  font-size: 22px;
  font-weight: 800;
  line-height: 1;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.cd-bar-added {
  flex: 1 1 auto;
  min-width: 0;
  font-size: 12.5px;
  color: var(--passion-300);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cd-bar-clear {
  background: transparent;
  border: 1px solid rgba(255, 255, 255, 0.3);
  color: #ffffff;
  border-radius: 10px;
  padding: 8px 12px;
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.cd-bar-clear:hover { background: rgba(255, 255, 255, 0.08); }
.cd-bar-save {
  background: var(--passion-500);
  color: var(--on-accent);
  border: none;
  border-radius: 12px;
  padding: 10px 18px;
  font-family: var(--font-heading);
  font-size: 15px;
  font-weight: 800;
  white-space: nowrap;
  cursor: pointer;
}
.cd-bar-save:hover { filter: brightness(1.05); }
.cd-bar-save:disabled,
.cd-bar-clear:disabled { opacity: 0.6; cursor: wait; }
.cd-dock .error-banner-footer { border-radius: 0; }

/* ── Portátil de caja (1366 × 611 y 125 %) ── */
@media (min-width: 761px) and (max-width: 1366px), (min-width: 761px) and (max-height: 800px) {
  .inline-editor-card .modal-body { padding: 12px 16px; gap: 12px; }
  .cd-meta-toggle { padding: 6px 10px; font-size: 12.5px; }
  .cd-meta .dest-btn { padding: 6px 10px; font-size: 12.5px; }
  .inline-editor-card .section-divider { margin: 0; }
}
@media (min-width: 761px) and (max-height: 520px) {
  .picker-products-grid { max-height: 100px; }
  .cd-line { padding: 7px 4px; }
  .cd-bar { min-height: 48px; }
}

/* ── Teléfono ── */
@media (max-width: 760px) {
  .picker-filters { flex-direction: column; align-items: stretch; }
  .picker-search-wrap { flex-basis: auto; }
  .cd-meta-fields { grid-template-columns: 1fr; }
  .cd-line {
    grid-template-columns: auto minmax(0, 1fr) auto;
    grid-template-areas: "main main main" "qty price tools" "extra extra extra";
    grid-template-rows: auto auto auto;
    row-gap: 6px;
  }
  .cd-line-price { align-items: flex-start; }
  .cd-qty-btn, .cd-tool { width: 44px; height: 44px; }
  .cd-bar { flex-wrap: wrap; }
  .cd-bar-added { display: none; }
}

@media (prefers-reduced-motion: reduce) {
  .cd-line--flash { animation: none; }
}
</style>
