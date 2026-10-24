<script setup>
import { ref, onMounted, onBeforeUnmount, computed, watch, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { apiFetch } from '../services/api';
import { useReorderStore } from '../stores/reorder';
import { REORDER_STATUS, EXPIRY_LABEL, TYPE_LABEL, expiryText, formatQty } from '../utils/inventoryLabels';
import ReorderModal from '../components/Inventory/ReorderModal.vue';
import PurchaseModal from '../components/PurchaseModal.vue';
import WasteModal from '../components/Inventory/WasteModal.vue';
import CountModal from '../components/Inventory/CountModal.vue';
import MovementHistoryModal from '../components/Inventory/MovementHistoryModal.vue';
import WasteReportModal from '../components/Inventory/WasteReportModal.vue';
import FinanceModal from '../components/Finance/FinanceModal.vue';
import '../components/Finance/finance.css';

const ingredients = ref([]);
const categories = ref([]);
const loading = ref(true);
const error = ref(null);

const showIngredientModal = ref(false);
const savingIngredient = ref(false);
const ingredientFormEl = ref(null);
const showCategoryModal = ref(false);
const showSuppliersModal = ref(false);

// Compra de insumos (formulario compartido con Finanzas)
const purchaseOpen = ref(false);
const purchaseIngredientId = ref(null);
const purchasePrefill = ref(null);
const notice = ref('');
let noticeTimer = null;

function openPurchase(ing = null) {
  purchaseIngredientId.value = ing ? ing.id : null;
  purchasePrefill.value = null;
  purchaseOpen.value = true;
}

// Reposición (F4): estado de cada insumo y lista de compras sugeridas
const route = useRoute();
const router = useRouter();
const reorderStore = useReorderStore();
const reorderOpen = ref(false);
const reorderById = ref({});
const reorderSummary = ref(null);

async function fetchReorder() {
  try {
    const report = await apiFetch('/inventory/reorder');
    reorderById.value = Object.fromEntries(report.items.map((i) => [i.id, i]));
    reorderSummary.value = report.summary;
    reorderStore.setFromReport(report);
  } catch (e) {
    // Sin conexión la lista funciona igual; solo faltan las insignias de reposición
  }
}

function reorderOf(ing) {
  return reorderById.value[ing.id] || null;
}

/** Hay que reponer: agotado, crítico o bajo su punto de reorden. Sin informe, vale el antiguo stock mínimo. */
/** Tono de la fila (franja izquierda): solo lo que pide atención. */
function rowTone(ing) {
  const r = reorderOf(ing);
  const tone = r ? REORDER_STATUS[r.status]?.tone : (needsRestock(ing) ? 'err' : null);
  return tone === 'err' || tone === 'warn' ? tone : null;
}

/** Nivel de stock de 0 al doble del punto de reorden (la marca de la barra es el punto). */
function stockLevel(ing) {
  const r = reorderOf(ing);
  const rp = Number(r?.reorder_point);
  if (!r || !(rp > 0) || ['daily', 'nodata', 'none'].includes(r.status)) return null;
  const stock = Math.max(0, Number(ing.current_stock) || 0);
  return { pct: Math.min(100, (stock / (2 * rp)) * 100), tone: REORDER_STATUS[r.status]?.tone || 'ok' };
}

/** Nota de reposición en una línea. */
function reorderNote(ing) {
  const r = reorderOf(ing);
  if (!r) return '';
  if (['out', 'critical', 'reorder', 'ok'].includes(r.status) && r.reorder_point > 0) {
    const days = r.days_of_stock !== null && r.days_of_stock !== undefined ? ` · alcanza ${formatQty(r.days_of_stock, 1)} d` : '';
    return `reorden en ${formatQty(r.reorder_point, 2)}${days}`;
  }
  if (r.status === 'nodata') return 'completa su consumo estimado en Editar';
  return '';
}

function ingredientMeta(ing) {
  return [ing.category?.name, ing.unit, ing.supplier?.name].filter(Boolean).join(' · ');
}

// Mismo formato que Finanzas y que las cantidades de la fila (coma decimal): «Bs 9,40».
const money = (v) => (Number(v) || 0).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function needsRestock(ing) {
  const r = reorderOf(ing);
  if (r) return ['out', 'critical', 'reorder'].includes(r.status);
  return parseFloat(ing.current_stock) <= parseFloat(ing.minimum_stock);
}

function openPurchaseFromReorder(payload) {
  reorderOpen.value = false;
  purchaseIngredientId.value = null;
  purchasePrefill.value = payload;
  purchaseOpen.value = true;
}

// Mermas, conteo físico e historial (F3)
const wasteOpen = ref(false);
const wasteIngredientId = ref(null);
const countOpen = ref(false);
const countFocusId = ref(null);
const reportOpen = ref(false);
function openReport() {
  reportOpen.value = true;
}

// Menú «Más» de la cabecera (Proveedores, Categorías, Mermas del mes)
const moreOpen = ref(false);
const moreEl = ref(null);
const moreBtn = ref(null);

function openMore() {
  moreOpen.value = true;
  nextTick(() => moreEl.value?.querySelector('[role="menuitem"]')?.focus());
}

function closeMore(returnFocus = true) {
  moreOpen.value = false;
  if (returnFocus) nextTick(() => moreBtn.value?.focus());
}

function toggleMore() {
  if (moreOpen.value) closeMore(false);
  else openMore();
}

function onMoreKey(e) {
  const items = [...(moreEl.value?.querySelectorAll('[role="menuitem"]') || [])];
  const i = items.indexOf(document.activeElement);
  if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length]?.focus(); }
  else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length]?.focus(); }
  else if (e.key === 'Home') { e.preventDefault(); items[0]?.focus(); }
  else if (e.key === 'End') { e.preventDefault(); items[items.length - 1]?.focus(); }
  else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMore(); }
  else if (e.key === 'Tab') { closeMore(false); }
}

/** Toque o clic fuera del menú: se cierra sin robar el foco. */
function onDocPointer(e) {
  if (moreOpen.value && moreEl.value && !moreEl.value.contains(e.target)) closeMore(false);
}

function runMore(action) {
  closeMore(false);
  // La ventana que se abre recuerda este botón y le devuelve el foco al cerrarse (el ítem del menú desaparece).
  moreBtn.value?.focus({ preventScroll: true });
  action();
}

onMounted(() => document.addEventListener('pointerdown', onDocPointer));
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocPointer));
const historyIngredient = ref(null);

function openWaste(ing = null) {
  wasteIngredientId.value = ing ? ing.id : null;
  wasteOpen.value = true;
}

function openCount(ing = null) {
  countFocusId.value = ing ? ing.id : null;
  countOpen.value = true;
}

function openCountFromReport() {
  reportOpen.value = false;
  openCount();
}

function showNotice(message) {
  notice.value = message;
  clearTimeout(noticeTimer);
  noticeTimer = setTimeout(() => { notice.value = ''; }, 6000);
}

async function onPurchaseSaved({ warnings }) {
  purchaseOpen.value = false;
  showNotice('Compra registrada: el stock y el costo ya están al día.');
  if (warnings?.length) alertAction(`El costo promedio de ${warnings.join(', ')} no se pudo recalcular y se dejó como estaba.`, 'Compra registrada con un aviso');
  // Una compra con proveedor nuevo lo crea al guardar: se recarga también la lista de proveedores
  await Promise.all([fetchIngredients(), fetchSuppliers()]);
}

const formErrors = ref({});
const categoryErrors = ref({});
const savingCategory = ref(false);
const savingSupplier = ref(false);

const selectedIngredient = ref(null);

// Filters
const filterSearch = ref('');
const filterCategoryId = ref('');

// Forms
const ingredientForm = ref({
  name: '',
  unit: 'kg',
  ingredient_category_id: '',
  type: 'perecedero',
  minimum_stock: 0,
  unit_cost: 0,
  current_stock: 0,
  is_key: false,
  supplier_id: '',
  purchase_unit: '',
  purchase_factor: 1,
  lot_size: '',
  reorder_policy: 'reorder',
  daily_estimate: '',
  target_days: '',
  safety_days: '',
  shelf_life_days: '',
  expiration_date: '',
});

const categoryForm = ref({
  name: '',
  description: ''
});

const isEditing = ref(false);
// Unidad antigua del insumo que se edita, si no es una de las tres de la lista cerrada
const legacyUnit = computed(() => {
  const unit = ingredientForm.value.unit;
  return isEditing.value && unit && !['kg', 'litros', 'unidades'].includes(unit) ? unit : '';
});
const isEditingCategory = ref(false);
const selectedCategory = ref(null);

const suppliers = ref([]);
const supplierForm = ref({ name: '', phone: '', delivery_days: 1, order_days: '', notes: '', is_active: true });
const editingSupplier = ref(null);
const supplierErrors = ref({});

onMounted(async () => {
  await Promise.all([fetchCategories(), fetchSuppliers()]);
  await fetchIngredients();
  // El aviso de apertura de caja y la tarjeta de Finanzas llegan aquí con ?compras=1
  if (route.query.compras) {
    reorderOpen.value = true;
    router.replace({ path: route.path });
  }
});

async function fetchSuppliers() {
  try {
    const res = await apiFetch('/suppliers');
    suppliers.value = res.data || res;
  } catch (e) {
    console.error('Error fetching suppliers', e);
  }
}

function openSuppliersModal() {
  fetchSuppliers();
  newSupplier();
  showSuppliersModal.value = true;
}

function newSupplier() {
  editingSupplier.value = null;
  supplierErrors.value = {};
  supplierForm.value = { name: '', phone: '', delivery_days: 1, order_days: '', notes: '', is_active: true };
}

function editSupplier(sup) {
  editingSupplier.value = sup;
  supplierErrors.value = {};
  supplierForm.value = {
    name: sup.name, phone: sup.phone || '', delivery_days: sup.delivery_days, order_days: sup.order_days || '',
    notes: sup.notes || '', is_active: !!sup.is_active,
  };
}

async function saveSupplier() {
  if (savingSupplier.value) return;
  savingSupplier.value = true;
  supplierErrors.value = {};
  try {
    const url = editingSupplier.value ? `/suppliers/${editingSupplier.value.id}` : '/suppliers';
    await apiFetch(url, {
      method: editingSupplier.value ? 'PUT' : 'POST',
      body: JSON.stringify({ ...supplierForm.value, delivery_days: Number(supplierForm.value.delivery_days) || 0 }),
    });
    newSupplier();
    await Promise.all([fetchSuppliers(), fetchIngredients()]);
  } catch (e) {
    if (e.validationErrors) supplierErrors.value = e.validationErrors;
    else alertAction(e.message || 'No se pudo guardar el proveedor.', 'No se pudo guardar');
  } finally {
    savingSupplier.value = false;
  }
}

function deleteSupplier(sup) {
  confirmAction(`¿Eliminar al proveedor «${sup.name}»?`, async () => {
    try {
      await apiFetch(`/suppliers/${sup.id}`, { method: 'DELETE' });
      if (editingSupplier.value?.id === sup.id) newSupplier();
      await fetchSuppliers();
    } catch (e) {
      alertAction(e.message || 'No se pudo eliminar el proveedor.', 'No se pudo eliminar');
    }
  }, { title: 'Eliminar proveedor', confirmLabel: 'Eliminar proveedor' });
}

// Watch filters to trigger fetch
watch([filterSearch, filterCategoryId], () => {
  fetchIngredients();
});

async function fetchCategories() {
  try {
    const res = await apiFetch('/ingredient-categories');
    categories.value = res.data || res;
  } catch (e) {
    console.error('Error fetching categories', e);
  }
}

async function fetchIngredients() {
  loading.value = true;
  try {
    const params = new URLSearchParams();
    if (filterSearch.value) params.append('search', filterSearch.value);
    if (filterCategoryId.value) params.append('category_id', filterCategoryId.value);
    
    const res = await apiFetch(`/ingredients?${params.toString()}`);
    ingredients.value = res.data || res;
  } catch (err) {
    error.value = 'Error al cargar insumos';
  } finally {
    loading.value = false;
  }
  fetchReorder();
}

function openIngredientModal(ing = null) {
  formErrors.value = {};
  if (ing) {
    isEditing.value = true;
    selectedIngredient.value = ing;
    ingredientForm.value = {
      ...ing,
      ingredient_category_id: ing.ingredient_category_id ?? '',
      supplier_id: ing.supplier_id ?? '',
      purchase_unit: ing.purchase_unit ?? '',
      purchase_factor: ing.purchase_factor ?? 1,
      lot_size: ing.lot_size ?? '',
      reorder_policy: ing.reorder_policy || 'reorder',
      daily_estimate: ing.daily_estimate ?? '',
      target_days: ing.target_days ?? '',
      safety_days: ing.safety_days ?? '',
      shelf_life_days: ing.shelf_life_days ?? '',
      expiration_date: ing.expiration_date ?? '',
    };
  } else {
    isEditing.value = false;
    selectedIngredient.value = null;
    ingredientForm.value = {
      name: '',
      unit: 'kg',
      ingredient_category_id: '',
      type: 'perecedero',
      minimum_stock: 0,
      unit_cost: 0,
      current_stock: 0,
      is_key: false,
      supplier_id: '',
      purchase_unit: '',
      purchase_factor: 1,
      lot_size: '',
      reorder_policy: 'reorder',
      daily_estimate: '',
      target_days: '',
      safety_days: '',
      shelf_life_days: '',
      expiration_date: '',
    };
  }
  showIngredientModal.value = true;
  // Foco en el primer campo: se puede escribir el nombre sin tocar el mouse
  nextTick(() => document.getElementById('inv-name')?.focus());
}

function closeIngredientModal() {
  if (savingIngredient.value) return;
  showIngredientModal.value = false;
  formErrors.value = {};
}

/** Lleva la vista y el foco al primer campo con error (el cuerpo del modal es el que se desplaza). */
function focusFirstError() {
  nextTick(() => {
    const el = ingredientFormEl.value?.querySelector('[aria-invalid="true"]');
    if (el) {
      el.scrollIntoView({ block: 'center' });
      el.focus({ preventScroll: true });
    }
  });
}

function openCategoryModal() {
  showCategoryModal.value = true;
  categoryErrors.value = {};
  isEditingCategory.value = false;
  selectedCategory.value = null;
  categoryForm.value = { name: '', description: '' };
}

function editCategory(cat) {
  isEditingCategory.value = true;
  selectedCategory.value = cat;
  categoryErrors.value = {};
  categoryForm.value = { ...cat };
  nextTick(() => document.getElementById('inv-catf-name')?.focus());
}

async function deleteCategory(cat) {
  confirmAction(`¿Eliminar la categoría «${cat.name}»?`, async () => {
    try {
      await apiFetch(`/ingredient-categories/${cat.id}`, {
        method: 'DELETE'
      });
      await fetchCategories();
    } catch (e) {
      alertAction(e.message || 'No se pudo eliminar la categoría. Si tiene insumos, cámbialos de categoría primero.', 'No se pudo eliminar');
    }
  }, { title: 'Eliminar categoría', confirmLabel: 'Eliminar categoría' });
}

async function deleteIngredient(ing) {
  confirmAction(`¿Eliminar «${ing.name}» del catálogo de insumos?`, async () => {
    try {
      await apiFetch(`/ingredients/${ing.id}`, {
        method: 'DELETE'
      });
      await fetchIngredients();
    } catch (e) {
      alertAction(e.message || 'No se pudo eliminar el insumo. Si está en una receta activa, quítalo de la receta primero.', 'No se pudo eliminar');
    }
  }, { title: 'Eliminar insumo', confirmLabel: 'Eliminar insumo' });
}

// Confirmación y aviso de la pantalla (FinanceModal)
const confirmState = ref(null); // { title, message, confirmLabel, busyLabel, danger, callback }
const confirmBusy = ref(false);
const alertState = ref(null);   // { title, message }

/** Pide confirmación; la ventana espera a que termine la acción antes de cerrarse. */
function confirmAction(message, callback, { title = 'Confirmar', confirmLabel = 'Confirmar', busyLabel = 'Eliminando…', danger = true } = {}) {
  confirmState.value = { title, message, confirmLabel, busyLabel, danger, callback };
  // Foco en la opción segura
  nextTick(() => document.getElementById('inv-confirm-cancel')?.focus());
}

function closeConfirm() {
  if (!confirmBusy.value) confirmState.value = null;
}

async function executeConfirm() {
  const callback = confirmState.value?.callback;
  if (!callback || confirmBusy.value) return;
  confirmBusy.value = true;
  try {
    await callback();
  } finally {
    confirmBusy.value = false;
    confirmState.value = null;
  }
}

function alertAction(message, title = 'No se pudo completar') {
  alertState.value = { title, message };
  nextTick(() => document.getElementById('inv-alert-ok')?.focus());
}

async function saveCategory() {
  if (savingCategory.value) return;
  savingCategory.value = true;
  categoryErrors.value = {};
  try {
    if (isEditingCategory.value) {
      await apiFetch(`/ingredient-categories/${selectedCategory.value.id}`, {
        method: 'PUT',
        body: JSON.stringify(categoryForm.value)
      });
    } else {
      await apiFetch('/ingredient-categories', {
        method: 'POST',
        body: JSON.stringify(categoryForm.value)
      });
    }
    isEditingCategory.value = false;
    selectedCategory.value = null;
    categoryForm.value = { name: '', description: '' };
    await fetchCategories();
  } catch (e) {
    if (e.validationErrors) {
      categoryErrors.value = e.validationErrors;
    } else {
      alertAction(e.message || 'No se pudo guardar la categoría.', 'No se pudo guardar');
    }
  } finally {
    savingCategory.value = false;
  }
}

async function saveIngredient() {
  if (savingIngredient.value) return;
  savingIngredient.value = true;
  formErrors.value = {};
  try {
    if (isEditing.value) {
      await apiFetch(`/ingredients/${selectedIngredient.value.id}`, {
        method: 'PUT',
        body: JSON.stringify(ingredientForm.value)
      });
    } else {
      await apiFetch(`/ingredients`, {
        method: 'POST',
        body: JSON.stringify(ingredientForm.value)
      });
    }
    showIngredientModal.value = false;
    await fetchIngredients();
  } catch (e) {
    if (e.validationErrors) {
      formErrors.value = e.validationErrors;
      focusFirstError();
    } else {
      alertAction(e.message || 'No se pudo guardar el insumo.', 'No se pudo guardar');
    }
  } finally {
    savingIngredient.value = false;
  }
}
</script>

<template>
  <div class="inventory-layout">
    <header class="inventory-header">
      <div class="inv-title">
        <h2>Inventario</h2>
        <p>Insumos, compras, mermas y conteos.</p>
      </div>
      <div class="inv-toolbar">
        <!-- Lo del día a día, siempre a la vista -->
        <div class="inv-daily">
          <button type="button" class="btn-ghost btn-outline inv-hbtn"
            :aria-label="reorderSummary?.to_reorder ? `Compras sugeridas: ${reorderSummary.to_reorder} por reponer` : 'Compras sugeridas'"
            @click="reorderOpen = true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1"/><path d="M9 10h6M9 14h6M9 18h3"/></svg>
            <span class="inv-lbl-full">Compras sugeridas</span><span class="inv-lbl-short">Sugeridas</span>
            <span v-if="reorderSummary && reorderSummary.to_reorder" class="rp-count" aria-hidden="true">{{ reorderSummary.to_reorder }}</span>
          </button>
          <button type="button" class="btn-ghost btn-outline inv-hbtn" aria-label="Contar stock" @click="openCount()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/></svg>
            <span class="inv-lbl-full">Contar stock</span><span class="inv-lbl-short">Contar</span>
          </button>
          <button type="button" class="btn-ghost btn-outline inv-hbtn" aria-label="Registrar merma" @click="openWaste()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>
            <span class="inv-lbl-full">Registrar merma</span><span class="inv-lbl-short">Merma</span>
          </button>
          <button type="button" class="btn-ghost btn-outline inv-hbtn" aria-label="Registrar compra" @click="openPurchase()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7Z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/></svg>
            <span class="inv-lbl-full">Registrar compra</span><span class="inv-lbl-short">Compra</span>
          </button>
        </div>

        <!-- Administración: menú «Más» (patrón de botón de menú: flechas, Escape, clic fuera) -->
        <div ref="moreEl" class="inv-more">
          <button ref="moreBtn" type="button" class="btn-ghost btn-outline inv-hbtn inv-more-btn" aria-haspopup="menu" :aria-expanded="moreOpen ? 'true' : 'false'" aria-controls="inv-more-menu" @click="toggleMore" @keydown.down.prevent="openMore">
            Más
            <svg class="inv-more-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
          </button>
          <ul v-if="moreOpen" id="inv-more-menu" class="inv-more-menu" role="menu" aria-label="Más opciones de inventario" @keydown="onMoreKey">
            <li role="none">
              <button type="button" role="menuitem" tabindex="-1" @click="runMore(openSuppliersModal)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 4h14v12H1zM15 9h4l3 3v4h-7"/><circle cx="5.5" cy="18.5" r="2"/><circle cx="18.5" cy="18.5" r="2"/></svg>
                Proveedores
              </button>
            </li>
            <li role="none">
              <button type="button" role="menuitem" tabindex="-1" @click="runMore(openCategoryModal)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg>
                Categorías
              </button>
            </li>
            <li role="none">
              <button type="button" role="menuitem" tabindex="-1" @click="runMore(openReport)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="M8 17v-5M13 17V8M18 17v-9"/></svg>
                Mermas del mes
              </button>
            </li>
          </ul>
        </div>

        <button type="button" class="btn-primary inv-hbtn inv-new" aria-label="Nuevo insumo" @click="openIngredientModal()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
          <span class="inv-lbl-wide">Nuevo insumo</span><span class="inv-lbl-narrow">Nuevo</span>
        </button>
      </div>
    </header>

    <!-- Filtros -->
    <div class="filters-bar">
      <div class="search-box">
        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input v-model="filterSearch" type="search" placeholder="Buscar insumo" aria-label="Buscar insumo" />
      </div>
      <div class="filter-group">
        <select v-model="filterCategoryId" aria-label="Filtrar por categoría">
          <option value="">Todas las categorías</option>
          <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
        </select>
      </div>
    </div>

    <div v-if="notice" class="inv-notice" role="status">{{ notice }}</div>

    <div v-if="loading" class="loading">Cargando inventario...</div>
    <div v-else-if="error" class="error">{{ error }}</div>
    <div v-else class="table-container">
      <table class="oh-table inv-table">
        <thead>
          <tr>
            <th scope="col">Insumo</th>
            <th scope="col" class="col-cat">Categoría</th>
            <th scope="col" class="col-num">Stock</th>
            <th scope="col">Reposición</th>
            <th scope="col" class="col-num">Costo</th>
            <th scope="col" class="col-tools">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="ing in ingredients" :key="ing.id" :class="rowTone(ing) ? `tone-${rowTone(ing)}` : null">
            <th scope="row" class="inv-cell-name">
              <span class="inv-name" :title="ing.name">{{ ing.name }}</span>
              <span class="inv-meta" :title="ingredientMeta(ing)">
                <span v-if="ing.category" class="inv-meta-cat">{{ ing.category.name }} · </span>{{ ing.unit }}<template v-if="ing.supplier"> · {{ ing.supplier.name }}</template>
              </span>
            </th>
            <td class="col-cat">
              <span v-if="ing.category" class="inv-cat-chip">{{ ing.category.name }}</span>
              <span v-if="ing.type" class="inv-type">{{ TYPE_LABEL[ing.type] || ing.type }}</span>
            </td>
            <td class="col-num">
              <span class="inv-stock">{{ formatQty(ing.current_stock, 2) }} <small>{{ ing.unit }}</small></span>
              <!-- Nivel: de 0 al doble del punto de reorden; la marca es el punto. El estado se lee en la píldora de Reposición. -->
              <span v-if="stockLevel(ing)" class="inv-level" :class="`inv-level--${stockLevel(ing).tone}`" aria-hidden="true">
                <span class="inv-level-fill" :style="{ width: `${stockLevel(ing).pct}%` }"></span>
              </span>
            </td>
            <td class="rp-cell">
              <template v-if="reorderOf(ing)">
                <span class="rp-chip" :class="REORDER_STATUS[reorderOf(ing).status]?.tone" :title="REORDER_STATUS[reorderOf(ing).status]?.hint">{{ REORDER_STATUS[reorderOf(ing).status]?.label || reorderOf(ing).status }}</span>
                <span v-if="reorderOf(ing).expiry?.level" class="rp-chip" :class="reorderOf(ing).expiry.level === 'expired' ? 'err' : 'warn'" :title="expiryText(reorderOf(ing).expiry)">{{ EXPIRY_LABEL[reorderOf(ing).expiry.level] }}</span>
                <small v-if="reorderNote(ing)" class="rp-note" :title="reorderNote(ing)">{{ reorderNote(ing) }}</small>
              </template>
              <span v-else class="text-muted">—</span>
            </td>
            <td class="col-num">
              <span class="inv-cost">Bs {{ money(ing.unit_cost) }}</span>
              <span class="inv-avg" title="Costo promedio ponderado">prom. {{ money(ing.weighted_avg_cost) }}</span>
            </td>
            <td class="col-tools">
              <div class="inv-tools">
                <button type="button" class="inv-tool" :aria-label="`Registrar compra de ${ing.name}`" title="Registrar compra" @click="openPurchase(ing)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7Z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/></svg>
                </button>
                <button type="button" class="inv-tool" :aria-label="`Registrar merma de ${ing.name}`" title="Registrar merma" @click="openWaste(ing)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>
                </button>
                <button type="button" class="inv-tool" :aria-label="`Contar ${ing.name}`" title="Contar stock" @click="openCount(ing)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/></svg>
                </button>
                <button type="button" class="inv-tool" :aria-label="`Historial de ${ing.name}`" title="Historial" @click="historyIngredient = ing">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                </button>
                <button type="button" class="inv-tool" :aria-label="`Editar ${ing.name}`" title="Editar" @click="openIngredientModal(ing)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </button>
                <button type="button" class="inv-tool inv-tool--danger" :aria-label="`Eliminar ${ing.name}`" title="Eliminar" @click="deleteIngredient(ing)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="ingredients.length === 0">
            <td colspan="6" class="empty-state">No hay insumos que coincidan. Prueba otra búsqueda o crea uno con «Nuevo insumo».</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Categorías: lista y formulario lado a lado (FinanceModal, como el resto de Inventario) -->
    <div v-if="showCategoryModal" class="fin-page inv-modal-host">
      <FinanceModal
        size="wide"
        title="Categorías de insumos"
        subtitle="Agrupan los insumos en la lista y en el conteo."
        :busy="savingCategory"
        @close="showCategoryModal = false"
      >
        <div class="inv-admin">
          <ul class="inv-admin-list" aria-label="Categorías">
            <li
              v-for="cat in categories"
              :key="cat.id"
              class="inv-admin-row"
              :class="{ 'is-editing': isEditingCategory && selectedCategory?.id === cat.id }"
            >
              <div class="inv-admin-main">
                <span class="inv-admin-name">{{ cat.name }}</span>
                <span v-if="cat.description" class="inv-admin-meta">{{ cat.description }}</span>
              </div>
              <div class="inv-admin-tools">
                <button type="button" class="inv-tool" :aria-label="`Editar ${cat.name}`" title="Editar" @click="editCategory(cat)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </button>
                <button type="button" class="inv-tool inv-tool--danger" :aria-label="`Eliminar ${cat.name}`" title="Eliminar" @click="deleteCategory(cat)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
                </button>
              </div>
            </li>
            <li v-if="categories.length === 0" class="inv-admin-empty">Todavía no hay categorías. Crea la primera con el formulario.</li>
          </ul>

          <form id="inv-cat-form" class="inv-admin-form" novalidate @submit.prevent="saveCategory">
            <h3 class="inv-col-title">{{ isEditingCategory ? `Editar «${selectedCategory?.name}»` : 'Nueva categoría' }}</h3>
            <div class="fin-field">
              <label for="inv-catf-name">Nombre</label>
              <input id="inv-catf-name" v-model="categoryForm.name" class="fin-input" type="text" autocomplete="off" data-autofocus
                :aria-invalid="categoryErrors.name ? 'true' : null" :aria-describedby="categoryErrors.name ? 'inv-catf-name-err' : null" />
              <span v-if="categoryErrors.name" id="inv-catf-name-err" class="inv-err">{{ categoryErrors.name[0] }}</span>
            </div>
            <div class="fin-field">
              <label for="inv-catf-desc">Descripción <small class="inv-optional">opcional</small></label>
              <input id="inv-catf-desc" v-model="categoryForm.description" class="fin-input" type="text"
                :aria-invalid="categoryErrors.description ? 'true' : null" :aria-describedby="categoryErrors.description ? 'inv-catf-desc-err' : null" />
              <span v-if="categoryErrors.description" id="inv-catf-desc-err" class="inv-err">{{ categoryErrors.description[0] }}</span>
            </div>
          </form>
        </div>
        <template #footer>
          <button type="button" class="fin-btn fin-btn-ghost inv-foot-close" :disabled="savingCategory" @click="showCategoryModal = false">Cerrar</button>
          <button v-if="isEditingCategory" type="button" class="fin-btn fin-btn-ghost" :disabled="savingCategory" @click="openCategoryModal()">Cancelar edición</button>
          <button type="submit" form="inv-cat-form" class="fin-btn fin-btn-primary" :disabled="savingCategory">
            {{ savingCategory ? 'Guardando…' : (isEditingCategory ? 'Guardar cambios' : 'Crear categoría') }}
          </button>
        </template>
      </FinanceModal>
    </div>

    <!-- Proveedores: mismo patrón de lista y formulario -->
    <div v-if="showSuppliersModal" class="fin-page inv-modal-host">
      <FinanceModal
        size="wide"
        title="Proveedores"
        subtitle="Quién te vende y cuántos días tarda en entregar: se usa para calcular cuándo pedir."
        :busy="savingSupplier"
        @close="showSuppliersModal = false"
      >
        <div class="inv-admin">
          <ul class="inv-admin-list" aria-label="Proveedores">
            <li
              v-for="sup in suppliers"
              :key="sup.id"
              class="inv-admin-row"
              :class="{ 'is-editing': editingSupplier?.id === sup.id, 'is-inactive': !sup.is_active }"
            >
              <div class="inv-admin-main">
                <span class="inv-admin-name">{{ sup.name }}</span>
                <span class="inv-admin-meta">
                  {{ sup.delivery_days === 0 ? 'Entrega el mismo día' : `Entrega en ${sup.delivery_days ?? '—'} d` }}
                  · {{ sup.ingredients_count ?? 0 }} insumos · {{ sup.expenses_count ?? 0 }} compras<template v-if="!sup.is_active"> · inactivo</template>
                </span>
              </div>
              <div class="inv-admin-tools">
                <button type="button" class="inv-tool" :aria-label="`Editar ${sup.name}`" title="Editar" @click="editSupplier(sup)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </button>
                <button type="button" class="inv-tool inv-tool--danger" :aria-label="`Eliminar ${sup.name}`" title="Eliminar" @click="deleteSupplier(sup)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
                </button>
              </div>
            </li>
            <li v-if="suppliers.length === 0" class="inv-admin-empty">Aún no hay proveedores. Se crean solos al registrar una compra con proveedor, o aquí.</li>
          </ul>

          <form id="inv-sup-form" class="inv-admin-form" novalidate @submit.prevent="saveSupplier">
            <h3 class="inv-col-title">{{ editingSupplier ? `Editar «${editingSupplier.name}»` : 'Nuevo proveedor' }}</h3>
            <div class="fin-field">
              <label for="inv-supf-name">Nombre</label>
              <input id="inv-supf-name" v-model="supplierForm.name" class="fin-input" type="text" maxlength="150" autocomplete="off" data-autofocus
                :aria-invalid="supplierErrors.name ? 'true' : null" :aria-describedby="supplierErrors.name ? 'inv-supf-name-err' : null" />
              <span v-if="supplierErrors.name" id="inv-supf-name-err" class="inv-err">{{ supplierErrors.name[0] }}</span>
            </div>
            <div class="inv-pair">
              <div class="fin-field">
                <label for="inv-supf-phone">Teléfono</label>
                <input id="inv-supf-phone" v-model="supplierForm.phone" class="fin-input" type="tel" maxlength="30" inputmode="tel" />
              </div>
              <div class="fin-field">
                <label for="inv-supf-days">Entrega (días)</label>
                <input id="inv-supf-days" v-model="supplierForm.delivery_days" class="fin-input" type="number" min="0" max="60" inputmode="numeric"
                  :aria-invalid="supplierErrors.delivery_days ? 'true' : null" :aria-describedby="supplierErrors.delivery_days ? 'inv-supf-days-err' : null" />
                <span v-if="supplierErrors.delivery_days" id="inv-supf-days-err" class="inv-err">{{ supplierErrors.delivery_days[0] }}</span>
              </div>
            </div>
            <div class="fin-field">
              <label for="inv-supf-order">Días de pedido <small class="inv-optional">opcional</small></label>
              <input id="inv-supf-order" v-model="supplierForm.order_days" class="fin-input" type="text" maxlength="100" placeholder="Ej.: lunes y jueves" />
            </div>
            <div class="fin-field">
              <label for="inv-supf-notes">Notas <small class="inv-optional">opcional</small></label>
              <input id="inv-supf-notes" v-model="supplierForm.notes" class="fin-input" type="text" maxlength="1000" />
            </div>
            <label class="inv-check">
              <input v-model="supplierForm.is_active" type="checkbox" />
              <span>Activo <small>aparece al registrar compras</small></span>
            </label>
          </form>
        </div>
        <template #footer>
          <button type="button" class="fin-btn fin-btn-ghost inv-foot-close" :disabled="savingSupplier" @click="showSuppliersModal = false">Cerrar</button>
          <button v-if="editingSupplier" type="button" class="fin-btn fin-btn-ghost" :disabled="savingSupplier" @click="newSupplier()">Cancelar edición</button>
          <button type="submit" form="inv-sup-form" class="fin-btn fin-btn-primary" :disabled="savingSupplier">
            {{ savingSupplier ? 'Guardando…' : (editingSupplier ? 'Guardar cambios' : 'Crear proveedor') }}
          </button>
        </template>
      </FinanceModal>
    </div>

    <!-- Compra de insumos (el mismo formulario que Finanzas) -->
    <PurchaseModal
      v-if="purchaseOpen"
      :ingredient-id="purchaseIngredientId"
      :prefill="purchasePrefill"
      @close="purchaseOpen = false"
      @saved="onPurchaseSaved"
    />

    <!-- Modal Insumo -->
    <!-- Insumo nuevo / editar: el mismo FinanceModal que usan los demás modales de Inventario.
         Tres columnas por tarea (qué es, cómo se compra, cuándo reponer); cabecera y pie siempre a la vista. -->
    <div v-if="showIngredientModal" class="fin-page inv-modal-host">
      <FinanceModal
        size="xl"
        :title="isEditing ? `Editar ${selectedIngredient?.name || 'insumo'}` : 'Nuevo insumo'"
        subtitle="Lo que se usa en las recetas: cómo se compra y cuándo reponerlo."
        :busy="savingIngredient"
        @close="closeIngredientModal"
      >
        <form id="inv-ingredient-form" ref="ingredientFormEl" class="inv-form" novalidate @submit.prevent="saveIngredient">
          <section class="inv-col" aria-labelledby="inv-col-insumo">
            <h3 id="inv-col-insumo" class="inv-col-title">Insumo</h3>
            <div class="fin-field">
              <label for="inv-name">Nombre</label>
              <input id="inv-name" v-model="ingredientForm.name" class="fin-input" type="text" autocomplete="off"
                :aria-invalid="formErrors.name ? 'true' : null" :aria-describedby="formErrors.name ? 'inv-name-err' : null" />
              <span v-if="formErrors.name" id="inv-name-err" class="inv-err">{{ formErrors.name[0] }}</span>
            </div>
            <div class="inv-pair inv-pair--unit">
              <div class="fin-field">
                <label for="inv-unit">Unidad</label>
                <select id="inv-unit" v-model="ingredientForm.unit" class="fin-input"
                  :aria-invalid="formErrors.unit ? 'true' : null" :aria-describedby="formErrors.unit ? 'inv-unit-err' : null">
                  <option value="kg">kg</option>
                  <option value="litros">Litros</option>
                  <option value="unidades">Unidades</option>
                  <!-- Insumos antiguos con otra unidad (p. ej. sacos) la conservan hasta que se cambie -->
                  <option v-if="legacyUnit" :value="legacyUnit">{{ legacyUnit }} (antigua)</option>
                </select>
                <span v-if="formErrors.unit" id="inv-unit-err" class="inv-err">{{ formErrors.unit[0] }}</span>
              </div>
              <div class="fin-field">
                <label for="inv-type">Tipo</label>
                <select id="inv-type" v-model="ingredientForm.type" class="fin-input"
                  :aria-invalid="formErrors.type ? 'true' : null" :aria-describedby="formErrors.type ? 'inv-type-err' : null">
                  <option value="perecedero">Perecedero</option>
                  <option value="no_perecedero">No perecedero</option>
                  <option value="material_empaque">Empaque</option>
                  <option value="otros">Otros</option>
                </select>
                <span v-if="formErrors.type" id="inv-type-err" class="inv-err">{{ formErrors.type[0] }}</span>
              </div>
            </div>
            <div class="fin-field">
              <label for="inv-cat">Categoría</label>
              <select id="inv-cat" v-model="ingredientForm.ingredient_category_id" class="fin-input"
                :aria-invalid="formErrors.ingredient_category_id ? 'true' : null" :aria-describedby="formErrors.ingredient_category_id ? 'inv-cat-err' : null">
                <option value="">Sin categoría</option>
                <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
              </select>
              <span v-if="formErrors.ingredient_category_id" id="inv-cat-err" class="inv-err">{{ formErrors.ingredient_category_id[0] }}</span>
            </div>
            <label class="inv-check">
              <input v-model="ingredientForm.is_key" type="checkbox" />
              <span>Insumo clave <small>entra en el conteo semanal</small></span>
            </label>
            <div class="inv-pair">
              <div class="fin-field">
                <label for="inv-stock">{{ isEditing ? 'Stock actual' : 'Stock inicial' }}</label>
                <input id="inv-stock" v-model="ingredientForm.current_stock" class="fin-input" type="number" step="0.01" inputmode="decimal" :disabled="isEditing"
                  :aria-invalid="formErrors.current_stock ? 'true' : null" :aria-describedby="isEditing ? 'inv-stock-hint' : (formErrors.current_stock ? 'inv-stock-err' : null)" />
                <span v-if="formErrors.current_stock" id="inv-stock-err" class="inv-err">{{ formErrors.current_stock[0] }}</span>
              </div>
              <div class="fin-field">
                <label for="inv-cost">{{ isEditing ? 'Costo unitario (Bs)' : 'Costo inicial (Bs)' }}</label>
                <input id="inv-cost" v-model="ingredientForm.unit_cost" class="fin-input" type="number" step="0.01" inputmode="decimal"
                  :aria-invalid="formErrors.unit_cost ? 'true' : null" :aria-describedby="formErrors.unit_cost ? 'inv-cost-err' : null" />
                <span v-if="formErrors.unit_cost" id="inv-cost-err" class="inv-err">{{ formErrors.unit_cost[0] }}</span>
              </div>
            </div>
            <p v-if="isEditing" id="inv-stock-hint" class="fin-hint">El stock cambia con Ingreso, Merma o Conteo, para que quede el historial.</p>
          </section>

          <section class="inv-col" aria-labelledby="inv-col-compra">
            <h3 id="inv-col-compra" class="inv-col-title">Compra</h3>
            <div class="fin-field">
              <label for="inv-sup">Proveedor habitual</label>
              <select id="inv-sup" v-model="ingredientForm.supplier_id" class="fin-input"
                :aria-invalid="formErrors.supplier_id ? 'true' : null" :aria-describedby="formErrors.supplier_id ? 'inv-sup-err' : null">
                <option value="">Sin proveedor fijo</option>
                <option v-for="sup in suppliers.filter((x) => x.is_active || x.id === ingredientForm.supplier_id)" :key="sup.id" :value="sup.id">{{ sup.name }}</option>
              </select>
              <span v-if="formErrors.supplier_id" id="inv-sup-err" class="inv-err">{{ formErrors.supplier_id[0] }}</span>
            </div>
            <div class="inv-pair">
              <div class="fin-field">
                <label for="inv-punit">Se compra en</label>
                <input id="inv-punit" v-model="ingredientForm.purchase_unit" class="fin-input" type="text" maxlength="30" placeholder="bolsa, caja…"
                  :aria-invalid="formErrors.purchase_unit ? 'true' : null" :aria-describedby="formErrors.purchase_unit ? 'inv-punit-err' : null" />
                <span v-if="formErrors.purchase_unit" id="inv-punit-err" class="inv-err">{{ formErrors.purchase_unit[0] }}</span>
              </div>
              <div class="fin-field">
                <label for="inv-factor">{{ ingredientForm.unit }} por {{ ingredientForm.purchase_unit || 'unidad' }}</label>
                <input id="inv-factor" v-model="ingredientForm.purchase_factor" class="fin-input" type="number" step="any" min="0" inputmode="decimal"
                  :aria-invalid="formErrors.purchase_factor ? 'true' : null" :aria-describedby="formErrors.purchase_factor ? 'inv-factor-err' : null" />
                <span v-if="formErrors.purchase_factor" id="inv-factor-err" class="inv-err">{{ formErrors.purchase_factor[0] }}</span>
              </div>
            </div>
            <div class="fin-field">
              <label for="inv-lot">Pedido mínimo ({{ ingredientForm.unit }})</label>
              <input id="inv-lot" v-model="ingredientForm.lot_size" class="fin-input" type="number" step="any" min="0" inputmode="decimal" placeholder="Opcional"
                :aria-invalid="formErrors.lot_size ? 'true' : null" :aria-describedby="formErrors.lot_size ? 'inv-lot-err' : null" />
              <span v-if="formErrors.lot_size" id="inv-lot-err" class="inv-err">{{ formErrors.lot_size[0] }}</span>
            </div>
            <div class="fin-field">
              <label for="inv-exp">Vence el</label>
              <input id="inv-exp" v-model="ingredientForm.expiration_date" class="fin-input" type="date"
                :aria-invalid="formErrors.expiration_date ? 'true' : null" :aria-describedby="formErrors.expiration_date ? 'inv-exp-err' : 'inv-exp-hint'" />
              <span v-if="formErrors.expiration_date" id="inv-exp-err" class="inv-err">{{ formErrors.expiration_date[0] }}</span>
              <span id="inv-exp-hint" class="fin-hint">Del lote en el estante: avisa 2 días antes (o el 20 % de la vida útil).</span>
            </div>
          </section>

          <section class="inv-col" aria-labelledby="inv-col-repo">
            <h3 id="inv-col-repo" class="inv-col-title">Reposición</h3>
            <div class="fin-field">
              <label for="inv-policy">Cómo se repone</label>
              <select id="inv-policy" v-model="ingredientForm.reorder_policy" class="fin-input"
                :aria-invalid="formErrors.reorder_policy ? 'true' : null" :aria-describedby="formErrors.reorder_policy ? 'inv-policy-err' : null">
                <option value="reorder">Por punto de reorden</option>
                <option value="daily">Compra diaria (fruta)</option>
                <option value="none">Sin control</option>
              </select>
              <span v-if="formErrors.reorder_policy" id="inv-policy-err" class="inv-err">{{ formErrors.reorder_policy[0] }}</span>
            </div>
            <div class="fin-field">
              <label for="inv-daily">Consumo diario estimado ({{ ingredientForm.unit }})</label>
              <input id="inv-daily" v-model="ingredientForm.daily_estimate" class="fin-input" type="number" step="any" min="0" inputmode="decimal" placeholder="Hasta tener 4 semanas de ventas"
                :aria-invalid="formErrors.daily_estimate ? 'true' : null" :aria-describedby="formErrors.daily_estimate ? 'inv-daily-err' : null" />
              <span v-if="formErrors.daily_estimate" id="inv-daily-err" class="inv-err">{{ formErrors.daily_estimate[0] }}</span>
            </div>
            <div class="fin-field">
              <label for="inv-min">Punto de reorden manual ({{ ingredientForm.unit }})</label>
              <input id="inv-min" v-model="ingredientForm.minimum_stock" class="fin-input" type="number" step="0.01" min="0" inputmode="decimal"
                :aria-invalid="formErrors.minimum_stock ? 'true' : null" :aria-describedby="formErrors.minimum_stock ? 'inv-min-err' : 'inv-min-hint'" />
              <span v-if="formErrors.minimum_stock" id="inv-min-err" class="inv-err">{{ formErrors.minimum_stock[0] }}</span>
              <span id="inv-min-hint" class="fin-hint">Con 0 lo calcula el sistema; si lo pones, nunca baja del cálculo.</span>
            </div>
            <div class="inv-pair">
              <div class="fin-field">
                <label for="inv-target">Cobertura (días)</label>
                <input id="inv-target" v-model="ingredientForm.target_days" class="fin-input" type="number" step="1" min="1" max="365" inputmode="numeric" placeholder="7"
                  :aria-invalid="formErrors.target_days ? 'true' : null" :aria-describedby="formErrors.target_days ? 'inv-target-err' : null" />
                <span v-if="formErrors.target_days" id="inv-target-err" class="inv-err">{{ formErrors.target_days[0] }}</span>
              </div>
              <div class="fin-field">
                <label for="inv-safety">Seguridad (días)</label>
                <input id="inv-safety" v-model="ingredientForm.safety_days" class="fin-input" type="number" step="any" min="0" max="60" inputmode="decimal" placeholder="2"
                  :aria-invalid="formErrors.safety_days ? 'true' : null" :aria-describedby="formErrors.safety_days ? 'inv-safety-err' : null" />
                <span v-if="formErrors.safety_days" id="inv-safety-err" class="inv-err">{{ formErrors.safety_days[0] }}</span>
              </div>
            </div>
            <div class="fin-field">
              <label for="inv-shelf">Vida útil (días)</label>
              <input id="inv-shelf" v-model="ingredientForm.shelf_life_days" class="fin-input" type="number" step="1" min="1" inputmode="numeric" placeholder="Opcional"
                :aria-invalid="formErrors.shelf_life_days ? 'true' : null" :aria-describedby="formErrors.shelf_life_days ? 'inv-shelf-err' : 'inv-shelf-hint'" />
              <span v-if="formErrors.shelf_life_days" id="inv-shelf-err" class="inv-err">{{ formErrors.shelf_life_days[0] }}</span>
              <span id="inv-shelf-hint" class="fin-hint">Limita cuánto se sugiere comprar.</span>
            </div>
          </section>
        </form>

        <template #footer>
          <button type="button" class="fin-btn fin-btn-ghost" :disabled="savingIngredient" @click="closeIngredientModal">Cancelar</button>
          <button type="submit" form="inv-ingredient-form" class="fin-btn fin-btn-primary" :disabled="savingIngredient">
            {{ savingIngredient ? 'Guardando…' : 'Guardar insumo' }}
          </button>
        </template>
      </FinanceModal>
    </div>

    <!-- Compras sugeridas (F4) -->
    <ReorderModal v-if="reorderOpen" @close="reorderOpen = false" @purchase="openPurchaseFromReorder" />

    <!-- Mermas, conteo físico, historial y reporte (F3) -->
    <WasteModal v-if="wasteOpen" :ingredient-id="wasteIngredientId" @close="wasteOpen = false" @saved="fetchIngredients" />
    <CountModal v-if="countOpen" :focus-id="countFocusId" @close="countOpen = false" @saved="fetchIngredients" />
    <MovementHistoryModal v-if="historyIngredient" :ingredient="historyIngredient" @close="historyIngredient = null" />
    <WasteReportModal v-if="reportOpen" @close="reportOpen = false" @count="openCountFromReport" />

    <!-- Confirmación y aviso: FinanceModal. Van al final para quedar encima de cualquier otro modal. -->
    <div v-if="confirmState" class="fin-page inv-modal-host">
      <FinanceModal :title="confirmState.title" :busy="confirmBusy" @close="closeConfirm">
        <p class="inv-dialog-text">{{ confirmState.message }}</p>
        <template #footer>
          <button id="inv-confirm-cancel" type="button" class="fin-btn fin-btn-ghost" :disabled="confirmBusy" @click="closeConfirm">Cancelar</button>
          <button type="button" class="fin-btn" :class="confirmState.danger ? 'fin-btn-danger' : 'fin-btn-primary'" :disabled="confirmBusy" @click="executeConfirm">
            {{ confirmBusy ? confirmState.busyLabel : confirmState.confirmLabel }}
          </button>
        </template>
      </FinanceModal>
    </div>

    <div v-if="alertState" class="fin-page inv-modal-host">
      <FinanceModal :title="alertState.title" @close="alertState = null">
        <p class="inv-dialog-text">{{ alertState.message }}</p>
        <template #footer>
          <button id="inv-alert-ok" type="button" class="fin-btn fin-btn-primary" @click="alertState = null">Entendido</button>
        </template>
      </FinanceModal>
    </div>
  </div>
</template>

<style scoped>
.inventory-layout {
  padding: 30px;
  background: var(--surface);
  min-height: 100%;
  /* Con muchos insumos no se encoge al alto de la pantalla (el fondo y el margen inferior se cortaban) */
  flex-shrink: 0;
}
.inventory-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px 20px;
  margin-bottom: 20px;
}
.inv-title h2 {
  margin: 0;
  font-family: var(--font-heading);
  font-size: 24px;
  line-height: 1.1;
  color: var(--ink-900);
}
.inv-title p {
  margin: 4px 0 0;
  color: var(--ink-500);
  font-size: 14px;
}
.inv-toolbar { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 8px; }
.inv-daily { display: flex; gap: 8px; }

.filters-bar {
  display: flex;
  gap: 16px;
  margin-bottom: 24px;
  align-items: center;
}
.search-box {
  position: relative;
  flex: 1;
  max-width: 400px;
}
.search-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--ink-500);
  width: 16px;
  height: 16px;
  pointer-events: none;
}
.search-box input {
  width: 100%;
  padding: 10px 10px 10px 36px;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 14px;
  background: var(--surface-alt);
  color: var(--ink-900);
}
.filter-group select {
  padding: 10px 16px;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 14px;
  background: var(--surface-alt);
  color: var(--ink-900);
}

.table-container {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  /* Desplaza en vez de recortar (en teléfono la tabla medía 741 px en 264 y se cortaba) */
  overflow-x: auto;
  position: relative;
}

.oh-table {
  width: 100%;
  border-collapse: collapse;
}
.oh-table thead th {
  background: var(--cream-50);
  text-align: left;
  padding: 10px 14px;
  font-size: 12px;
  text-transform: uppercase;
  color: var(--ink-500);
  border-bottom: 1px solid var(--border);
}
.oh-table td,
.oh-table tbody th {
  padding: 10px 14px;
  border-bottom: 1px solid var(--border);
  font-size: 14px;
  color: var(--ink-900);
  vertical-align: middle;
}
.text-muted {
  color: var(--ink-500);
}
.mb-4 { margin-bottom: 16px; }


.empty-state { text-align: center; padding: 40px !important; color: var(--ink-500); }


.btn-primary { background: var(--passion-500); color: var(--on-accent); border: none; padding: 10px 16px; border-radius: 8px; font-weight: 700; cursor: pointer; }
.btn-ghost { background: transparent; color: var(--ink-700); border: 1px solid transparent; padding: 10px 16px; border-radius: 8px; font-weight: 700; cursor: pointer; }
.btn-ghost:hover { background: var(--cream-100); }




.btn-outline { border-color: var(--border); }
.rp-count {
  display: inline-block;
  min-width: 20px;
  padding: 0 6px;
  margin-left: 4px;
  border-radius: 999px;
  background: var(--passion-600);
  color: var(--on-accent);
  font-size: 12px;
  font-weight: 700;
  line-height: 20px;
  text-align: center;
}
.rp-chip {
  display: inline-block;
  padding: 2px 9px;
  margin: 0 4px 2px 0;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 700;
  white-space: nowrap;
}
.rp-chip.ok { background: var(--lime-100); color: var(--lime-700); }
.rp-chip.warn { background: var(--gold-100); color: var(--gold-text); }
.rp-chip.err { background: var(--danger-100); color: var(--danger-text); }
.rp-chip.info { background: var(--teal-100); color: var(--teal-700); }
.rp-chip.acai { background: var(--acai-50); color: var(--acai-text); }
.inv-notice {
  margin-bottom: 16px;
  padding: 10px 14px;
  border-radius: 10px;
  background: var(--lime-100);
  color: var(--lime-700);
  font-size: 13.5px;
  font-weight: 600;
}

/* ── Modal de insumo (FinanceModal xl): tres columnas por tarea ── */
.inv-modal-host { display: contents; }
.inv-form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0 20px; }
.inv-col { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
.inv-col + .inv-col { border-left: 1px solid var(--border); padding-left: 20px; }
.inv-col-title { margin: 0; font-family: var(--font-heading); font-size: 15px; font-weight: 700; color: var(--ink-900); }
.inv-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
/* «Unidades» es corto y «No perecedero» no: el tipo se lleva más ancho */
.inv-pair--unit { grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.2fr); }
.inv-check { display: flex; align-items: center; gap: 8px; min-height: 32px; font-size: 13px; font-weight: 600; color: var(--ink-900); cursor: pointer; }
.inv-check input { width: 18px; height: 18px; margin: 0; accent-color: var(--acai-500); }
.inv-check small { margin-left: 4px; font-size: 12px; font-weight: 400; color: var(--ink-500); }
.inv-check input:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; }
.inv-err { font-size: 12px; font-weight: 600; color: var(--danger-text); }
.inv-modal-host .fin-input[aria-invalid="true"] { border-color: var(--danger-600); background: var(--danger-100); }
.inv-modal-host .fin-input:disabled { background: var(--cream-100); color: var(--ink-500); cursor: not-allowed; }
.inv-form .fin-hint { margin: 0; font-size: 11.5px; line-height: 1.35; }
@media (min-width: 761px) and (max-width: 1023px) {
  .inv-form { grid-template-columns: 1fr 1fr; gap: 16px 20px; }
  .inv-col:nth-child(3) { grid-column: 1 / -1; border-left: none; padding-left: 0; border-top: 1px solid var(--border); padding-top: 14px; }
}
@media (min-width: 761px) and (max-height: 520px) {
  .inv-col { gap: 8px; }
}
@media (max-width: 760px) {
  .inv-form { grid-template-columns: 1fr; gap: 14px; }
  .inv-col + .inv-col { border-left: none; padding-left: 0; border-top: 1px solid var(--border); padding-top: 14px; }
}

/* ── Categorías y Proveedores: lista con herramientas y formulario al lado ── */
.inv-admin { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); gap: 20px; align-items: start; }
.inv-admin-list { list-style: none; margin: 0; padding: 0; border: 1px solid var(--border); border-radius: var(--radius-sm); max-height: min(52vh, 360px); overflow-y: auto; }
.inv-admin-row { display: flex; align-items: center; gap: 10px; padding: 7px 8px 7px 12px; border-bottom: 1px solid var(--border); }
.inv-admin-row:last-child { border-bottom: none; }
.inv-admin-row.is-editing { background: var(--acai-50); }
.inv-admin-row.is-inactive .inv-admin-name { color: var(--ink-500); }
.inv-admin-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.inv-admin-name { font-size: 13.5px; font-weight: 600; color: var(--ink-900); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.inv-admin-meta { font-size: 12px; color: var(--ink-500); }
.inv-admin-tools { display: flex; gap: 2px; flex-shrink: 0; }
.inv-admin-empty { padding: 16px 12px; font-size: 13px; color: var(--ink-500); text-align: center; }
.inv-admin-form { display: flex; flex-direction: column; gap: 10px; padding: 14px; border-radius: var(--radius-sm); background: var(--cream-100); }
.inv-foot-close { margin-right: auto; }
.inv-optional { font-weight: 400; color: var(--ink-500); }
.inv-tool { width: 32px; height: 32px; border-radius: 8px; border: 1px solid transparent; background: transparent; color: var(--ink-500); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
.inv-tool svg { width: 16px; height: 16px; }
.inv-tool:hover { background: var(--acai-50); color: var(--acai-text); border-color: var(--acai-300); }
.inv-tool--danger:hover { background: var(--danger-100); color: var(--danger-text); border-color: transparent; }
.inv-tool:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; }
.inv-dialog-text { margin: 0; font-size: 14px; line-height: 1.5; color: var(--ink-700); white-space: pre-line; }
@media (max-width: 760px) {
  .inv-admin { grid-template-columns: 1fr; }
  .inv-admin-list { max-height: none; }
  .inv-tool { width: 44px; height: 44px; }
}

/* ── Cabecera: acciones del día a la vista, administración en «Más», «Nuevo insumo» al extremo ── */
.inv-hbtn { display: inline-flex; align-items: center; gap: 6px; min-height: 38px; padding: 0 12px; font-size: 13.5px; white-space: nowrap; }
.inv-hbtn svg { width: 16px; height: 16px; flex-shrink: 0; }
.inv-hbtn:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; }
.inv-hbtn .rp-count { margin-left: 2px; }
.inv-lbl-short,
.inv-lbl-narrow { display: none; }
.inv-more { position: relative; }
.inv-more-chev { width: 14px !important; height: 14px !important; transition: transform 0.15s ease; }
.inv-more-btn[aria-expanded="true"] { background: var(--acai-50); color: var(--acai-text); border-color: var(--acai-300); }
.inv-more-btn[aria-expanded="true"] .inv-more-chev { transform: rotate(180deg); }
.inv-more-menu {
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  z-index: 60;
  min-width: 220px;
  margin: 0;
  padding: 6px;
  list-style: none;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  box-shadow: var(--shadow-card);
}
.inv-more-menu button {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 38px;
  padding: 0 10px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--ink-900);
  font: inherit;
  font-size: 13.5px;
  text-align: left;
  cursor: pointer;
}
.inv-more-menu button:hover,
.inv-more-menu button:focus-visible { background: var(--acai-50); color: var(--acai-text); outline: none; }
.inv-more-menu svg { width: 16px; height: 16px; color: var(--ink-500); flex-shrink: 0; }
.inv-more-menu button:hover svg,
.inv-more-menu button:focus-visible svg { color: var(--acai-text); }
@media (prefers-reduced-motion: reduce) { .inv-more-chev { transition: none; } }

/* Portátil: sin descripción y con menos aire para que la tabla empiece arriba */
@media (min-width: 761px) and (max-width: 1366px), (min-width: 761px) and (max-height: 800px) {
  .inventory-layout { padding: 20px 24px; }
  .inv-title h2 { font-size: 22px; }
  .inv-title p { display: none; }
  .inventory-header { margin-bottom: 14px; }
  .filters-bar { margin-bottom: 14px; }
  .search-box input,
  .filter-group select { padding-top: 8px; padding-bottom: 8px; }
}
/* 125 % y pantallas medianas: etiquetas cortas (el nombre completo queda para lectores de pantalla) */
@media (min-width: 761px) and (max-width: 1250px) {
  .inv-lbl-full { display: none; }
  .inv-lbl-short { display: inline; }
}
/* Teléfono: título, «Más» y «Nuevo» arriba; las acciones del día en una fila que se desliza */
@media (max-width: 760px) {
  .inventory-layout { padding: 16px; }
  .inventory-header {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    grid-template-areas: "title more new" "daily daily daily";
    align-items: center;
    gap: 10px 8px;
  }
  .inv-title { grid-area: title; }
  .inv-title p { display: none; }
  .inv-toolbar { display: contents; }
  .inv-daily { grid-area: daily; overflow-x: auto; padding-bottom: 4px; scrollbar-width: thin; }
  .inv-more { grid-area: more; }
  .inv-new { grid-area: new; }
  .inv-hbtn { min-height: 44px; }
  .inv-lbl-full,
  .inv-lbl-wide { display: none; }
  .inv-lbl-short,
  .inv-lbl-narrow { display: inline; }
  .filters-bar { flex-wrap: wrap; gap: 10px; }
  .search-box { max-width: none; flex-basis: 100%; }
}

/* ── Tabla de insumos (Fase 4): filas de ≈ 56 px, herramientas de 32 px y nivel de stock ── */
.oh-table tbody tr:last-child > * { border-bottom: none; }
.inv-cell-name { text-align: left; font-weight: 400; min-width: 0; max-width: 280px; }
.inv-name { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; font-weight: 700; font-size: 14px; color: var(--ink-900); line-height: 1.25; }
.inv-meta { display: block; margin-top: 2px; font-size: 12px; color: var(--ink-500); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.inv-meta-cat { display: none; }
.inv-cat-chip { display: inline-block; padding: 2px 9px; border-radius: 999px; background: var(--teal-100); color: var(--teal-700); font-size: 11.5px; font-weight: 700; white-space: nowrap; }
.inv-type { display: block; margin-top: 3px; font-size: 12px; color: var(--ink-500); }
.col-num { text-align: right; white-space: nowrap; }
.oh-table thead th.col-num { text-align: right; }
.inv-stock { font-weight: 700; font-variant-numeric: tabular-nums; }
.inv-stock small { font-weight: 500; color: var(--ink-500); }
.inv-level { position: relative; display: block; width: 72px; height: 4px; margin: 6px 0 0 auto; border-radius: 2px; background: var(--cream-200); }
.inv-level::after { content: ''; position: absolute; left: 50%; top: -2px; width: 2px; height: 8px; border-radius: 1px; background: var(--ink-500); }
.inv-level-fill { display: block; height: 100%; border-radius: 2px; background: var(--lime-500); }
.inv-level--warn .inv-level-fill { background: var(--gold-500); }
.inv-level--err .inv-level-fill { background: var(--danger-500); }
.inv-cost { display: block; font-weight: 600; font-variant-numeric: tabular-nums; }
.inv-avg { display: block; margin-top: 2px; font-size: 12px; color: var(--ink-500); font-variant-numeric: tabular-nums; }
.rp-cell { max-width: 230px; }
.rp-note { display: block; margin-top: 2px; font-size: 12px; color: var(--ink-500); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.col-tools { width: 1%; }
.inv-tools { display: flex; justify-content: flex-end; gap: 2px; }
/* Franja de estado: solo lo que pide atención (el texto de la píldora dice cuál) */
.oh-table tbody tr.tone-err > :first-child { box-shadow: inset 3px 0 0 var(--danger-500); }
.oh-table tbody tr.tone-warn > :first-child { box-shadow: inset 3px 0 0 var(--gold-500); }

/* 125 % y ventanas medianas: la categoría pasa a la línea del insumo y se gana una columna */
@media (max-width: 1200px) {
  .col-cat { display: none; }
  .inv-meta-cat { display: inline; }
  /* Los textos de una línea empujan la columna hasta su tope: a este ancho los topes bajan (se leen enteros en el title) */
  .inv-cell-name { max-width: 220px; }
  .rp-cell { max-width: 190px; }
  .oh-table thead th, .oh-table td, .oh-table tbody th { padding-left: 10px; padding-right: 10px; }
}
@media (min-width: 761px) and (max-height: 520px) {
  .oh-table td, .oh-table tbody th { padding-top: 8px; padding-bottom: 8px; }
}
/* Teléfono: la tabla se desliza y el nombre queda fijo a la izquierda */
@media (max-width: 760px) {
  .inv-table { min-width: 640px; }
  .inv-cell-name, .oh-table thead th:first-child { position: sticky; left: 0; z-index: 1; background: var(--surface); max-width: 150px; }
  .oh-table thead th:first-child { background: var(--cream-50); }
}
</style>
