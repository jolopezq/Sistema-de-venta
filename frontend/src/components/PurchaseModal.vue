<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { apiFetch } from '../services/api';
import { PAYMENT_METHODS, formatBs, localDate, errorText } from '../stores/finance';
import FinanceModal from './Finance/FinanceModal.vue';
import './Finance/finance.css';

/**
 * Formulario único de compra de insumos (Finanzas e Inventario): una compra es un gasto con una o varias
 * líneas. Cada línea con insumo suma stock y actualiza el costo promedio; las líneas sin insumo (gas,
 * limpieza…) solo cuentan como gasto. No depende del store de Finanzas: lo usan también los admin.
 *
 * - `expenseId`: edita esa compra (se revierte y se vuelve a aplicar en el servidor).
 * - `ingredientId`: abre una compra nueva con esa línea ya puesta (botón «Ingreso» de Inventario).
 * - `prefill`: abre una compra nueva con el proveedor y las líneas de la lista de compras sugeridas
 *   (`{ supplier, lines: [{ ingredient_id, quantity }] }`, cantidades en la unidad de compra). El importe
 *   pagado queda vacío: lo escribe quien registra la compra.
 */
const props = defineProps({
  expenseId: { type: Number, default: null },
  ingredientId: { type: Number, default: null },
  defaultDate: { type: String, default: '' },
  prefill: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);

const OTHER = '';
const options = reactive({ categories: [], suppliers: [], ingredients: [] });
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const lineErrors = ref({});

const form = reactive({
  date: props.defaultDate || localDate(),
  supplier: '',
  expense_category_id: '',
  payment_method: 'cash',
  receipt_number: '',
  notes: '',
});
const lines = ref([]);
let nextKey = 1;

function emptyLine(ingredientId = OTHER) {
  const line = {
    key: nextKey++, ingredient_id: ingredientId, description: '', quantity: '', purchase_unit: '', factor: 1, total: '',
  };
  if (ingredientId !== OTHER) applyIngredient(line);
  return line;
}

const ingredientById = computed(() => Object.fromEntries(options.ingredients.map((i) => [i.id, i])));
const isEditing = computed(() => !!props.expenseId);

/** Al elegir el insumo se propone su forma habitual de compra (bolsa de 2 kg, caja de 12…). */
function applyIngredient(line) {
  const ing = ingredientById.value[line.ingredient_id];
  if (!ing) { line.purchase_unit = ''; line.factor = 1; return; }
  line.purchase_unit = ing.purchase_unit || ing.unit;
  line.factor = Number(ing.purchase_factor) > 0 ? Number(ing.purchase_factor) : 1;
}

function onIngredientChange(line) {
  applyIngredient(line);
  // Si el insumo ya tiene proveedor y la compra aún no, se propone
  const ing = ingredientById.value[line.ingredient_id];
  if (ing?.supplier_id && !form.supplier) {
    const sup = options.suppliers.find((s) => s.id === ing.supplier_id);
    if (sup) form.supplier = sup.name;
  }
}

function baseUnit(line) { return ingredientById.value[line.ingredient_id]?.unit || ''; }

/** El factor solo se pide cuando la unidad de compra es distinta a la de consumo (bolsa → kg). */
function needsFactor(line) {
  const base = baseUnit(line);
  const unit = (line.purchase_unit || '').trim().toLowerCase();
  return !!base && !!unit && unit !== base.toLowerCase();
}

function stockQuantity(line) {
  const qty = Number(line.quantity) || 0;
  return qty * (needsFactor(line) ? Number(line.factor) || 0 : 1);
}

function unitCost(line) {
  const stock = stockQuantity(line);
  return stock > 0 ? (Number(line.total) || 0) / stock : 0;
}

const grandTotal = computed(() => lines.value.reduce((sum, l) => sum + (Number(l.total) || 0), 0));

function addLine() { lines.value.push(emptyLine()); }
function removeLine(line) { lines.value = lines.value.filter((l) => l.key !== line.key); }

onMounted(async () => {
  try {
    const opts = await apiFetch('/purchases/options');
    Object.assign(options, opts);
    form.expense_category_id = opts.categories[0]?.id || '';
    if (props.expenseId) {
      const e = await apiFetch(`/purchases/${props.expenseId}`);
      Object.assign(form, {
        date: String(e.date).slice(0, 10),
        supplier: e.supplier || '',
        expense_category_id: e.expense_category_id,
        payment_method: e.payment_method || 'cash',
        receipt_number: e.receipt_number || '',
        notes: e.notes || '',
      });
      // La categoría de una compra antigua puede no estar entre las activas: se conserva igual
      if (e.category && !options.categories.some((c) => c.id === e.category.id)) {
        options.categories.unshift({ id: e.category.id, name: e.category.name });
      }
      lines.value = (e.items || []).map((it) => ({
        key: nextKey++,
        ingredient_id: it.ingredient_id ?? OTHER,
        description: it.ingredient_id ? '' : (it.description || ''),
        quantity: Number(it.quantity),
        purchase_unit: it.purchase_unit || it.ingredient?.unit || '',
        factor: Number(it.factor) || 1,
        total: Number(it.total),
      }));
    } else if (props.prefill?.lines?.length) {
      lines.value = props.prefill.lines
        .filter((l) => ingredientById.value[l.ingredient_id])
        .map((l) => ({ ...emptyLine(l.ingredient_id), quantity: Number(l.quantity) }));
      if (!lines.value.length) lines.value = [emptyLine()];
      // El proveedor de la lista; si es de un insumo suelto se deja el que propone la línea
      const sup = options.suppliers.find((s) => s.name === props.prefill.supplier);
      if (sup) form.supplier = sup.name;
    } else {
      lines.value = [emptyLine(props.ingredientId ?? OTHER)];
      const ing = props.ingredientId ? ingredientById.value[props.ingredientId] : null;
      const sup = ing?.supplier_id ? options.suppliers.find((s) => s.id === ing.supplier_id) : null;
      if (sup) form.supplier = sup.name;
    }
  } catch (err) {
    error.value = errorText(err, 'No se pudo cargar el formulario de compras.');
  } finally {
    loading.value = false;
  }
});

function onSupplierBlur() {
  const key = form.supplier.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ');
  const known = options.suppliers.find((s) => s.name.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '') === key);
  if (known) form.supplier = known.name;
}

function validate() {
  if (!form.date) return 'Indica la fecha.';
  if (!form.expense_category_id) return 'Elige una categoría.';
  if (!lines.value.length) return 'Agrega al menos una línea.';
  for (const [i, l] of lines.value.entries()) {
    const n = i + 1;
    if (l.ingredient_id === OTHER && !l.description.trim()) return `Línea ${n}: elige un insumo o escribe el concepto.`;
    if (!(Number(l.quantity) > 0)) return `Línea ${n}: la cantidad debe ser mayor a 0.`;
    if (needsFactor(l) && !(Number(l.factor) > 0)) return `Línea ${n}: indica cuántos ${baseUnit(l)} trae cada ${l.purchase_unit}.`;
    if (!(Number(l.total) > 0)) return `Línea ${n}: indica el importe pagado.`;
  }
  return '';
}

async function save() {
  error.value = validate();
  if (error.value) return;
  saving.value = true;
  try {
    const body = {
      ...form,
      supplier: form.supplier.trim() || null,
      items: lines.value.map((l) => ({
        ingredient_id: l.ingredient_id === OTHER ? null : l.ingredient_id,
        description: l.ingredient_id === OTHER ? l.description.trim() : null,
        quantity: Number(l.quantity),
        purchase_unit: l.ingredient_id !== OTHER ? (l.purchase_unit || null) : null,
        factor: needsFactor(l) ? Number(l.factor) : 1,
        total: Number(l.total),
      })),
    };
    const res = await apiFetch(isEditing.value ? `/purchases/${props.expenseId}` : '/purchases', {
      method: isEditing.value ? 'PUT' : 'POST',
      body: JSON.stringify(body),
    });
    saving.value = false;
    emit('saved', { edited: isEditing.value, warnings: res.warnings || [] });
  } catch (err) {
    error.value = errorText(err, 'No se pudo guardar la compra.');
    saving.value = false;
  }
}
</script>

<template>
  <!-- Los estilos de Finanzas cuelgan de .fin-page; este contenedor no añade caja al diseño -->
  <div class="fin-page fin-host">
    <FinanceModal
      wide
      :title="isEditing ? 'Editar compra' : 'Registrar compra'"
      subtitle="Lo que entra al local: sube el stock, actualiza el costo del insumo y cuenta como gasto en Finanzas."
      :busy="saving"
      @close="emit('close')"
    >
      <div v-if="loading" class="fin-loading">Cargando…</div>
      <template v-else>
        <div v-if="isEditing" class="fin-note info">
          Al guardar se revierte la compra anterior y se aplica esta: el stock y el costo promedio se recalculan.
        </div>

        <div class="fin-grid-2">
          <div class="fin-field">
            <label for="pm-date">Fecha</label>
            <input id="pm-date" v-model="form.date" type="date" class="fin-input" />
          </div>
          <div class="fin-field">
            <label for="pm-supplier">Proveedor (opcional)</label>
            <input
              id="pm-supplier"
              v-model="form.supplier"
              type="text"
              maxlength="150"
              class="fin-input"
              list="pm-supplier-list"
              autocomplete="off"
              placeholder="Ej.: Mercado Rodríguez"
              @blur="onSupplierBlur"
            />
            <datalist id="pm-supplier-list">
              <option v-for="s in options.suppliers" :key="s.id" :value="s.name" />
            </datalist>
          </div>
        </div>

        <div class="pm-lines">
          <div v-for="(line, i) in lines" :key="line.key" class="pm-line">
            <div class="pm-line-head">
              <span class="pm-line-n">Línea {{ i + 1 }}</span>
              <button
                v-if="lines.length > 1"
                class="fin-icon-btn danger"
                type="button"
                :aria-label="`Quitar línea ${i + 1}`"
                @click="removeLine(line)"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6 6 18M6 6l12 12" /></svg>
              </button>
            </div>
            <div class="fin-field" :class="{ 'pm-span': line.ingredient_id !== OTHER }">
              <label :for="`pm-ing-${line.key}`">Insumo</label>
              <select :id="`pm-ing-${line.key}`" v-model="line.ingredient_id" class="fin-input" @change="onIngredientChange(line)">
                <option :value="OTHER">Otro gasto (gas, limpieza, bolsas…)</option>
                <option v-for="ing in options.ingredients" :key="ing.id" :value="ing.id">{{ ing.name }} ({{ ing.unit }})</option>
              </select>
            </div>
            <div v-if="line.ingredient_id === OTHER" class="fin-field">
              <label :for="`pm-desc-${line.key}`">Concepto</label>
              <input :id="`pm-desc-${line.key}`" v-model="line.description" type="text" maxlength="150" class="fin-input" placeholder="Ej.: Gas licuado" />
            </div>
            <div class="pm-line-grid">
              <div class="fin-field">
                <label :for="`pm-qty-${line.key}`">Cantidad</label>
                <input :id="`pm-qty-${line.key}`" v-model="line.quantity" type="number" min="0" step="any" inputmode="decimal" class="fin-input" />
              </div>
              <div v-if="line.ingredient_id !== OTHER" class="fin-field">
                <label :for="`pm-unit-${line.key}`">Se compra en</label>
                <input
                  :id="`pm-unit-${line.key}`"
                  v-model="line.purchase_unit"
                  type="text"
                  maxlength="30"
                  class="fin-input"
                  list="pm-unit-list"
                  autocomplete="off"
                />
              </div>
              <div v-if="needsFactor(line)" class="fin-field">
                <label :for="`pm-factor-${line.key}`">{{ baseUnit(line) }} por {{ line.purchase_unit }}</label>
                <input :id="`pm-factor-${line.key}`" v-model="line.factor" type="number" min="0" step="any" inputmode="decimal" class="fin-input" />
              </div>
              <div class="fin-field">
                <label :for="`pm-total-${line.key}`">Pagado (Bs)</label>
                <input :id="`pm-total-${line.key}`" v-model="line.total" type="number" min="0" step="0.01" inputmode="decimal" class="fin-input" placeholder="0,00" />
              </div>
            </div>
            <p v-if="line.ingredient_id !== OTHER && stockQuantity(line) > 0 && Number(line.total) > 0" class="fin-hint pm-calc">
              Entran <strong>{{ Number(stockQuantity(line).toFixed(4)) }} {{ baseUnit(line) }}</strong>
              a <strong>{{ formatBs(unitCost(line)) }}</strong> por {{ baseUnit(line) }}.
            </p>
          </div>
          <datalist id="pm-unit-list">
            <option value="bolsa" /><option value="caja" /><option value="paquete" /><option value="frasco" /><option value="bidón" /><option value="saco" />
          </datalist>
          <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="addLine">+ Agregar otra línea</button>
        </div>

        <div class="fin-grid-2">
          <div class="fin-field">
            <label for="pm-cat">Categoría</label>
            <select id="pm-cat" v-model="form.expense_category_id" class="fin-input">
              <option v-for="c in options.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div class="fin-field">
            <label for="pm-method">Forma de pago</label>
            <select id="pm-method" v-model="form.payment_method" class="fin-input">
              <option v-for="(label, key) in PAYMENT_METHODS" :key="key" :value="key">{{ label }}</option>
            </select>
          </div>
        </div>
        <div v-if="form.payment_method === 'cash'" class="fin-note">
          Si el dinero salió de la caja del turno, ya es un egreso de caja: no lo registres aquí o se contará dos veces.
        </div>
        <div class="fin-grid-2">
          <div class="fin-field">
            <label for="pm-receipt">Recibo o factura (opcional)</label>
            <input id="pm-receipt" v-model="form.receipt_number" type="text" maxlength="100" class="fin-input" />
          </div>
          <div class="fin-field">
            <label for="pm-notes">Nota (opcional)</label>
            <input id="pm-notes" v-model="form.notes" type="text" maxlength="1000" class="fin-input" />
          </div>
        </div>
      </template>

      <div v-if="error" class="fin-note err" role="alert">{{ error }}</div>

      <template #footer>
        <strong class="fin-num pm-total">Total {{ formatBs(grandTotal) }}</strong>
        <span class="fin-spacer pm-foot-spacer"></span>
        <button class="fin-btn fin-btn-secondary" type="button" :disabled="saving" @click="emit('close')">Cancelar</button>
        <button class="fin-btn fin-btn-primary" type="button" :disabled="saving || loading" @click="save">
          {{ saving ? 'Guardando…' : 'Guardar compra' }}
        </button>
      </template>
    </FinanceModal>
  </div>
</template>

<style scoped>
/* El contenedor solo sirve para que apliquen los estilos de .fin-page; no debe ocupar espacio */
.fin-host { display: contents; }

.pm-lines { display: flex; flex-direction: column; gap: 10px; }
.pm-line {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: var(--cream-50);
}
.pm-line-head { display: flex; justify-content: space-between; align-items: center; min-height: 20px; }
.pm-line-n { font-size: 12px; font-weight: 700; color: var(--ink-500); }
.pm-line-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; }
.pm-calc { margin: 0; }
.pm-calc strong { color: var(--ink-900); }
.pm-total { color: var(--ink-900); font-size: 15px; }
/* Portátil de caja: cada línea usa dos columnas (insumo / concepto, luego cantidades) para que entren más líneas.
   Mismo criterio que finance.css. */
@media (min-width: 761px) and (max-width: 1366px), (min-width: 761px) and (max-height: 800px) {
  .pm-lines { gap: 8px; }
  .pm-line { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px 10px; padding: 8px 12px; }
  .pm-line-head, .pm-line-grid, .pm-calc, .pm-span { grid-column: 1 / -1; }
  .pm-line-head { min-height: 16px; }
  .pm-line-grid { gap: 8px; }
}
/* En móvil el total ocupa su propia fila y los botones quedan juntos debajo */
@media (max-width: 520px) {
  .pm-total { flex: 1 0 100%; }
  .pm-foot-spacer { display: none; }
}
</style>
