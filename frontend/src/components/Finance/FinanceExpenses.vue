<script setup>
import { computed, reactive, ref, watch, onMounted } from 'vue';
import { apiFetch } from '../../services/api';
import {
  useFinanceStore, formatBs, formatDay, localDate, errorText, nameKey, SECTIONS, PAYMENT_METHODS,
} from '../../stores/finance';
import FinanceModal from './FinanceModal.vue';
import CategorySelect from './CategorySelect.vue';

/**
 * Pestaña Compras y gastos: lo pagado fuera de la caja (finance_expenses) y los egresos de caja del mes,
 * que se clasifican aquí para que el Estado de Resultados los ubique en su sección.
 */
const props = defineProps({ intent: { type: String, default: '' } });
const emit = defineEmits(['intent-done']);
const finance = useFinanceStore();

// ---------- Filtros ----------
const sectionFilter = computed({
  get: () => finance.expenseFilter.section,
  set: (v) => { finance.expenseFilter.section = v; finance.expenseFilter.category_id = ''; finance.loadExpenses(); },
});
const categoryFilter = computed({
  get: () => finance.expenseFilter.category_id,
  set: (v) => { finance.expenseFilter.category_id = v; finance.loadExpenses(); },
});
const filterCategories = computed(() => finance.categories.filter((c) => !sectionFilter.value || c.section === sectionFilter.value));
const sectionTotals = computed(() => finance.expenseTotals.by_section || {});

// ---------- Formulario de gasto ----------
const modalOpen = ref(false);
const saving = ref(false);
const formError = ref('');
const editing = ref(null);
const form = reactive({
  date: '', expense_category_id: '', supplier: '', amount: '', payment_method: 'bank_transfer', receipt_number: '', notes: '',
});

function defaultDate() {
  if (finance.isCurrentMonth) return localDate();
  const today = localDate();
  const first = `${finance.year}-${String(finance.month).padStart(2, '0')}-01`;
  if (first > today) return first;
  const last = new Date(finance.year, finance.month, 0).getDate();
  return `${finance.year}-${String(finance.month).padStart(2, '0')}-${String(last).padStart(2, '0')}`;
}

function openNew() {
  editing.value = null;
  Object.assign(form, {
    date: defaultDate(),
    expense_category_id: finance.activeCategories.find((c) => c.section === 'cost_of_sales')?.id || finance.activeCategories[0]?.id || '',
    supplier: '', amount: '', payment_method: 'bank_transfer', receipt_number: '', notes: '',
  });
  formError.value = '';
  categoryTouched.value = false;
  supplierHint.value = '';
  modalOpen.value = true;
  finance.loadSuppliers();
}

function openEdit(item) {
  editing.value = item;
  Object.assign(form, {
    date: String(item.date).slice(0, 10),
    expense_category_id: item.expense_category_id,
    supplier: item.supplier || '',
    amount: item.amount,
    payment_method: item.payment_method || 'bank_transfer',
    receipt_number: item.receipt_number || '',
    notes: item.notes || '',
  });
  formError.value = '';
  categoryTouched.value = true;
  supplierHint.value = '';
  modalOpen.value = true;
  finance.loadSuppliers();
}

// ---------- Proveedores ya usados ----------
const categoryTouched = ref(false);
const supplierHint = ref('');

function chooseCategory(id) {
  form.expense_category_id = id;
  categoryTouched.value = true;
  supplierHint.value = '';
}

/** Al escribir un proveedor conocido se propone la categoría de su última compra (si no se eligió otra). */
function onSupplierInput() {
  const key = nameKey(form.supplier);
  const known = key ? finance.suppliers.find((s) => nameKey(s.name) === key) : null;
  if (!known) {
    supplierHint.value = '';
    return;
  }
  const category = finance.categories.find((c) => c.id === known.expense_category_id && c.is_active);
  if (!categoryTouched.value && category) {
    form.expense_category_id = category.id;
    supplierHint.value = `Categoría de las compras anteriores a ${known.name}.`;
  }
}

/** Al salir del campo se usa la forma ya registrada del proveedor ("frutas del valle" → "Frutas del Valle"). */
function onSupplierBlur() {
  const key = nameKey(form.supplier);
  const known = key ? finance.suppliers.find((s) => nameKey(s.name) === key) : null;
  if (known) form.supplier = known.name;
}

async function save() {
  if (!(Number(form.amount) > 0)) { formError.value = 'El monto debe ser mayor a 0.'; return; }
  if (!form.expense_category_id) { formError.value = 'Elige una categoría.'; return; }
  if (!form.date) { formError.value = 'Indica la fecha.'; return; }
  saving.value = true;
  formError.value = '';
  try {
    const url = editing.value ? `/finance/expenses/${editing.value.id}` : '/finance/expenses';
    await apiFetch(url, { method: editing.value ? 'PUT' : 'POST', body: JSON.stringify(form) });
    modalOpen.value = false;
    // Ya guardado: el formulario queda libre aunque las listas tarden en recargarse
    saving.value = false;
    finance.notify(editing.value ? 'Gasto actualizado.' : 'Gasto registrado.');
    await Promise.all([finance.loadExpenses(), finance.loadPnl(), finance.loadRecurring()]);
  } catch (err) {
    formError.value = errorText(err, 'No se pudo guardar el gasto.');
  } finally {
    saving.value = false;
  }
}

async function remove(item) {
  const generated = !!item.recurring_expense_id;
  const ok = await finance.confirm({
    title: 'Eliminar gasto',
    message: `${item.supplier || item.category?.name || 'Gasto'} · ${formatBs(item.amount)} del ${formatDay(item.date)}.`
      + (generated ? '\nEs un gasto fijo generado: no se volverá a proponer este mes (puedes recuperarlo en Gastos fijos).' : ''),
    confirmLabel: 'Eliminar',
    danger: true,
  });
  if (!ok) return;
  try {
    await apiFetch(`/finance/expenses/${item.id}`, { method: 'DELETE' });
    finance.notify('Gasto eliminado.');
    await Promise.all([finance.loadExpenses(), finance.loadPnl(), finance.loadRecurring()]);
  } catch (err) {
    finance.notify(errorText(err, 'No se pudo eliminar.'), 'error');
  }
}

// ---------- Egresos de caja ----------
const cashSection = ref(null);
const onlyUnclassified = ref(false);
const classifying = ref(null);
const cashRows = computed(() => (finance.cashMovements.data || [])
  .filter((m) => !onlyUnclassified.value || !m.expense_category_id));
const unclassifiedCount = computed(() => finance.counts.unclassified);

function formatDateTime(value) {
  if (!value) return '—';
  return new Date(value).toLocaleString('es-BO', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

async function classify(movement, categoryId) {
  if (!categoryId || categoryId === movement.expense_category_id) return;
  classifying.value = movement.id;
  try {
    await apiFetch(`/finance/cash-movements/${movement.id}/category`, {
      method: 'PATCH',
      body: JSON.stringify({ expense_category_id: Number(categoryId) }),
    });
    finance.notify('Egreso clasificado.');
    await Promise.all([finance.loadCashMovements(), finance.loadPnl()]);
  } catch (err) {
    finance.notify(errorText(err, 'No se pudo clasificar.'), 'error');
  } finally {
    classifying.value = null;
  }
}

// ---------- Acciones que llegan desde el Resumen ----------
function handleIntent(intent) {
  if (intent === 'new-expense') openNew();
  if (intent === 'cash') {
    onlyUnclassified.value = unclassifiedCount.value > 0;
    cashSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  if (intent) emit('intent-done');
}
onMounted(() => handleIntent(props.intent));
watch(() => props.intent, handleIntent);
</script>

<template>
  <!-- Compras y gastos fuera de caja -->
  <div class="fin-card">
    <div class="fin-card-head">
      <div>
        <h2>Compras y gastos pagados fuera de caja</h2>
        <p>Lo que se pagó desde la caja del turno ya está abajo, en Egresos de caja: no lo registres dos veces.</p>
      </div>
      <button class="fin-btn fin-btn-primary" type="button" @click="openNew">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="M12 5v14M5 12h14" /></svg>
        Registrar compra o gasto
      </button>
    </div>

    <div class="fin-card-body fin-row">
      <div class="fin-chips" role="group" aria-label="Sección">
        <button class="fin-chip" :class="{ active: !sectionFilter }" type="button" @click="sectionFilter = ''">Todas</button>
        <button
          v-for="(label, key) in SECTIONS"
          :key="key"
          class="fin-chip"
          :class="{ active: sectionFilter === key }"
          type="button"
          @click="sectionFilter = key"
        >
          {{ label }}<template v-if="sectionTotals[key]"> · {{ formatBs(sectionTotals[key].amount) }}</template>
        </button>
      </div>
      <span class="fin-spacer"></span>
      <select v-model="categoryFilter" class="fin-input fin-input-sm fin-filter-select" style="width: auto; min-width: 180px" aria-label="Categoría">
        <option value="">Todas las categorías</option>
        <option v-for="c in filterCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
    </div>

    <div v-if="finance.errors.expenses" class="fin-error">{{ finance.errors.expenses }}</div>
    <div v-else-if="finance.loading.expenses && !finance.expenses.length" class="fin-loading">Cargando gastos…</div>
    <div v-else-if="!finance.expenses.length" class="fin-empty">No hay compras ni gastos registrados con este filtro en {{ finance.periodLabel }}.</div>
    <div v-else class="fin-table-wrap">
      <table class="fin-table stack">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Concepto</th>
            <th>Categoría</th>
            <th>Pago</th>
            <th class="right">Monto</th>
            <th class="actions"><span class="sr-only">Acciones</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in finance.expenses" :key="item.id">
            <td data-label="Fecha" class="fin-num">{{ formatDay(item.date) }}</td>
            <td data-label="Concepto">
              <span class="fin-cell-main">{{ item.supplier || 'Sin proveedor' }}</span>
              <span v-if="item.recurring_expense_id" class="fin-badge acai" style="margin-left: 6px">Gasto fijo</span>
              <div v-if="item.notes || item.receipt_number" class="fin-cell-sub">
                {{ item.notes }}<template v-if="item.receipt_number"> · Recibo {{ item.receipt_number }}</template>
              </div>
            </td>
            <td data-label="Categoría">
              {{ item.category?.name || '—' }}
              <div class="fin-cell-sub">{{ SECTIONS[item.category?.section] || '' }}</div>
            </td>
            <td data-label="Pago">{{ PAYMENT_METHODS[item.payment_method] || item.payment_method }}</td>
            <td data-label="Monto" class="right fin-num fin-cell-main">{{ formatBs(item.amount) }}</td>
            <td class="actions">
              <button class="fin-icon-btn" type="button" :aria-label="`Editar ${item.supplier || 'gasto'}`" @click="openEdit(item)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" /></svg>
              </button>
              <button class="fin-icon-btn danger" type="button" :aria-label="`Eliminar ${item.supplier || 'gasto'}`" @click="remove(item)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" /></svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="finance.expenses.length" class="fin-card-foot fin-row">
      <span>{{ finance.expenseTotals.count }} {{ finance.expenseTotals.count === 1 ? 'registro' : 'registros' }}</span>
      <span class="fin-spacer"></span>
      <strong class="fin-num" style="color: var(--ink-900)">Total {{ formatBs(finance.expenseTotals.amount) }}</strong>
    </div>
  </div>

  <!-- Egresos de caja -->
  <div ref="cashSection" class="fin-card">
    <div class="fin-card-head">
      <div>
        <h2>Egresos de caja del mes</h2>
        <p>Salidas de dinero registradas en los turnos. Elige la categoría de las que quedaron sin clasificar.</p>
      </div>
      <div class="fin-chips">
        <button class="fin-chip" :class="{ active: !onlyUnclassified }" type="button" @click="onlyUnclassified = false">Todos ({{ finance.cashMovements.count }})</button>
        <button class="fin-chip" :class="{ active: onlyUnclassified }" type="button" @click="onlyUnclassified = true">Sin clasificar ({{ unclassifiedCount }})</button>
      </div>
    </div>

    <div v-if="finance.errors.cash" class="fin-error">{{ finance.errors.cash }}</div>
    <div v-else-if="!cashRows.length" class="fin-empty">
      {{ onlyUnclassified ? 'Todos los egresos de caja del mes tienen categoría.' : `No hay egresos de caja en ${finance.periodLabel}.` }}
    </div>
    <div v-else class="fin-table-wrap">
      <table class="fin-table stack">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Descripción</th>
            <th class="right">Monto</th>
            <th>Categoría</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="m in cashRows" :key="m.id">
            <td data-label="Fecha" class="fin-num">{{ formatDateTime(m.created_at) }}</td>
            <td data-label="Descripción">
              <span class="fin-cell-main">{{ m.description || 'Sin descripción' }}</span>
              <div class="fin-cell-sub">{{ m.user?.name || '' }}</div>
            </td>
            <td data-label="Monto" class="right fin-num fin-cell-main">{{ formatBs(m.amount) }}</td>
            <td data-label="Categoría">
              <CategorySelect
                small
                :model-value="m.expense_category_id || ''"
                placeholder="Sin clasificar — elegir…"
                default-section="operating"
                :disabled="classifying === m.id"
                :aria-label="`Categoría de ${m.description || 'egreso'}`"
                @update:model-value="(id) => classify(m, id)"
              />
              <span v-if="!m.expense_category_id" class="fin-badge warn" style="margin-top: 4px">Sin clasificar</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="finance.cashMovements.count" class="fin-card-foot fin-row">
      <span>Los egresos sin clasificar se cuentan como gastos operativos.</span>
      <span class="fin-spacer"></span>
      <strong class="fin-num" style="color: var(--ink-900)">Total {{ formatBs(finance.cashMovements.total) }}</strong>
    </div>
  </div>

  <!-- Modal de gasto -->
  <FinanceModal
    v-if="modalOpen"
    :title="editing ? 'Editar compra o gasto' : 'Registrar compra o gasto'"
    subtitle="Pagos hechos fuera de la caja del turno (transferencia, QR, tarjeta o efectivo propio)."
    :busy="saving"
    @close="modalOpen = false"
  >
    <div v-if="editing?.recurring_expense_id" class="fin-note info">
      Generado desde el gasto fijo «{{ editing.recurring_expense?.name || editing.supplier }}». Los cambios solo afectan a este mes.
    </div>
    <div class="fin-grid-2">
      <div class="fin-field">
        <label for="fx-date">Fecha</label>
        <input id="fx-date" v-model="form.date" type="date" class="fin-input" />
      </div>
      <div class="fin-field">
        <label for="fx-amount">Monto (Bs)</label>
        <input id="fx-amount" v-model="form.amount" type="number" min="0.01" step="0.01" inputmode="decimal" class="fin-input" placeholder="0,00" />
      </div>
    </div>
    <div class="fin-grid-2">
      <div class="fin-field">
        <label for="fx-supplier">Proveedor o concepto</label>
        <input
          id="fx-supplier"
          v-model="form.supplier"
          type="text"
          maxlength="150"
          class="fin-input"
          list="fx-supplier-list"
          autocomplete="off"
          placeholder="Ej.: Frutas del Valle"
          @input="onSupplierInput"
          @change="onSupplierInput"
          @blur="onSupplierBlur"
        />
        <datalist id="fx-supplier-list">
          <option v-for="sup in finance.suppliers" :key="sup.name" :value="sup.name" />
        </datalist>
      </div>
      <div class="fin-field">
        <label for="fx-method">Forma de pago</label>
        <select id="fx-method" v-model="form.payment_method" class="fin-input">
          <option v-for="(label, key) in PAYMENT_METHODS" :key="key" :value="key">{{ label }}</option>
        </select>
      </div>
    </div>
    <div class="fin-field">
      <label for="fx-cat">Categoría</label>
      <CategorySelect
        id="fx-cat"
        :model-value="form.expense_category_id"
        default-section="cost_of_sales"
        @update:model-value="chooseCategory"
      />
      <span v-if="supplierHint" class="fin-hint">{{ supplierHint }}</span>
      <span v-else class="fin-hint">¿No está en la lista? Elige «+ Nueva categoría…» al final.</span>
    </div>
    <div v-if="form.payment_method === 'cash'" class="fin-note">
      Si el dinero salió de la caja del turno, ya es un egreso de caja: no lo registres aquí o se contará dos veces.
    </div>
    <div class="fin-grid-2">
      <div class="fin-field">
        <label for="fx-receipt">Recibo o factura (opcional)</label>
        <input id="fx-receipt" v-model="form.receipt_number" type="text" maxlength="100" class="fin-input" />
      </div>
      <div class="fin-field">
        <label for="fx-notes">Nota (opcional)</label>
        <input id="fx-notes" v-model="form.notes" type="text" maxlength="1000" class="fin-input" />
      </div>
    </div>
    <div v-if="formError" class="fin-note err" role="alert">{{ formError }}</div>
    <template #footer>
      <button class="fin-btn fin-btn-secondary" type="button" :disabled="saving" @click="modalOpen = false">Cancelar</button>
      <button class="fin-btn fin-btn-primary" type="button" :disabled="saving" @click="save">{{ saving ? 'Guardando…' : 'Guardar' }}</button>
    </template>
  </FinanceModal>
</template>
