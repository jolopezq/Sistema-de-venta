<script setup>
import { computed, reactive, ref, watch, onMounted } from 'vue';
import { apiFetch } from '../../services/api';
import {
  useFinanceStore, formatBs, formatDay, errorText, SECTIONS, PAYMENT_METHODS, FREQUENCIES, MONTHS,
} from '../../stores/finance';
import FinanceModal from './FinanceModal.vue';

/**
 * Pestaña Gastos fijos: los del mes (generar los pendientes) y las plantillas.
 * Un gasto fijo solo entra al Estado de Resultados cuando se genera para el mes.
 */
const props = defineProps({ intent: { type: String, default: '' } });
const emit = defineEmits(['intent-done']);
const finance = useFinanceStore();

const items = computed(() => finance.recurringPeriod.items || []);
const pending = computed(() => items.value.filter((i) => i.status === 'pending'));
const generating = ref(false);

const STATUS = {
  pending: { label: 'Pendiente', kind: 'warn' },
  generated: { label: 'Generado', kind: 'ok' },
  dismissed: { label: 'Descartado', kind: '' },
};

async function generate(ids = null) {
  generating.value = true;
  try {
    const body = { year: finance.year, month: finance.month };
    if (ids) body.ids = ids;
    const res = await apiFetch('/finance/recurring-expenses/generate', { method: 'POST', body: JSON.stringify(body) });
    finance.notify(res.message);
    await Promise.all([finance.loadRecurring(), finance.loadExpenses(), finance.loadPnl()]);
  } catch (err) {
    finance.notify(errorText(err, 'No se pudieron generar los gastos fijos.'), 'error');
  } finally {
    generating.value = false;
  }
}

async function generateAll() {
  const ok = await finance.confirm({
    title: `Generar gastos fijos de ${finance.periodLabel}`,
    message: `Se registrarán ${pending.value.length} gastos por ${formatBs(finance.recurringPeriod.pending_total)}. `
      + 'Después puedes ajustar el monto de cada uno en Compras y gastos.',
    confirmLabel: 'Generar',
  });
  if (ok) await generate();
}

// ---------- Plantillas ----------
const modalOpen = ref(false);
const saving = ref(false);
const formError = ref('');
const editing = ref(null);
const form = reactive({
  name: '', expense_category_id: '', amount: '', frequency: 'monthly', day_of_month: 1, start_month: '', payment_method: 'bank_transfer', notes: '',
});

const periodValue = () => `${finance.year}-${String(finance.month).padStart(2, '0')}`;

function openNew() {
  editing.value = null;
  Object.assign(form, {
    name: '',
    expense_category_id: finance.activeCategories.find((c) => c.section === 'operating')?.id || finance.activeCategories[0]?.id || '',
    amount: '', frequency: 'monthly', day_of_month: 1, start_month: periodValue(), payment_method: 'bank_transfer', notes: '',
  });
  formError.value = '';
  modalOpen.value = true;
}

function openEdit(t) {
  editing.value = t;
  Object.assign(form, {
    name: t.name,
    expense_category_id: t.expense_category_id,
    amount: t.amount,
    frequency: t.frequency,
    day_of_month: t.day_of_month,
    start_month: t.start_month || periodValue(),
    payment_method: t.payment_method || 'bank_transfer',
    notes: t.notes || '',
  });
  formError.value = '';
  modalOpen.value = true;
}

const groupedCategories = computed(() => Object.entries(SECTIONS)
  .map(([key, label]) => ({
    key,
    label,
    items: finance.categories.filter((c) => c.section === key && (c.is_active || c.id === form.expense_category_id)),
  }))
  .filter((g) => g.items.length));

async function save() {
  if (!form.name.trim()) { formError.value = 'Escribe el concepto.'; return; }
  if (!(Number(form.amount) > 0)) { formError.value = 'El monto debe ser mayor a 0.'; return; }
  saving.value = true;
  formError.value = '';
  try {
    const url = editing.value ? `/finance/recurring-expenses/${editing.value.id}` : '/finance/recurring-expenses';
    await apiFetch(url, { method: editing.value ? 'PUT' : 'POST', body: JSON.stringify(form) });
    modalOpen.value = false;
    finance.notify(editing.value ? 'Gasto fijo actualizado. Los meses ya generados no cambian.' : 'Gasto fijo agregado.');
    await Promise.all([finance.loadRecurring(), finance.loadPnl()]);
  } catch (err) {
    formError.value = errorText(err, 'No se pudo guardar el gasto fijo.');
  } finally {
    saving.value = false;
  }
}

async function toggle(t) {
  try {
    await apiFetch(`/finance/recurring-expenses/${t.id}/toggle-active`, { method: 'PATCH' });
    finance.notify(t.is_active ? `«${t.name}» pausado.` : `«${t.name}» activado.`);
    await Promise.all([finance.loadRecurring(), finance.loadPnl()]);
  } catch (err) {
    finance.notify(errorText(err), 'error');
  }
}

async function remove(t) {
  const ok = await finance.confirm({
    title: 'Eliminar gasto fijo',
    message: `«${t.name}» dejará de proponerse. Los gastos ya generados en meses anteriores se conservan.`,
    confirmLabel: 'Eliminar',
    danger: true,
  });
  if (!ok) return;
  try {
    await apiFetch(`/finance/recurring-expenses/${t.id}`, { method: 'DELETE' });
    finance.notify('Gasto fijo eliminado.');
    await Promise.all([finance.loadRecurring(), finance.loadPnl()]);
  } catch (err) {
    finance.notify(errorText(err), 'error');
  }
}

function monthLabel(value) {
  if (!value) return '—';
  const [y, m] = value.split('-');
  return `${MONTHS[Number(m) - 1]?.slice(0, 3)} ${y}`;
}

const periodCard = ref(null);
function handleIntent(intent) {
  if (intent === 'period') periodCard.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  if (intent) emit('intent-done');
}
onMounted(() => handleIntent(props.intent));
watch(() => props.intent, handleIntent);
</script>

<template>
  <!-- Gastos fijos del mes -->
  <div ref="periodCard" class="fin-card">
    <div class="fin-card-head">
      <div>
        <h2>Gastos fijos de {{ finance.periodLabel }}</h2>
        <p>Entran al Estado de Resultados cuando se generan. Bimestrales, trimestrales y anuales aparecen solo en su mes.</p>
      </div>
      <button
        class="fin-btn fin-btn-primary"
        type="button"
        :disabled="!pending.length || generating"
        @click="generateAll"
      >
        {{ generating ? 'Generando…' : pending.length ? `Generar ${pending.length} pendientes · ${formatBs(finance.recurringPeriod.pending_total)}` : 'Nada pendiente' }}
      </button>
    </div>

    <div v-if="finance.errors.recurring" class="fin-error">{{ finance.errors.recurring }}</div>
    <div v-else-if="!items.length" class="fin-empty">Ningún gasto fijo corresponde a {{ finance.periodLabel }}.</div>
    <div v-else class="fin-table-wrap">
      <table class="fin-table stack">
        <thead>
          <tr>
            <th>Concepto</th>
            <th>Frecuencia</th>
            <th class="right">Monto</th>
            <th>Estado</th>
            <th class="actions"><span class="sr-only">Acciones</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="i in items" :key="i.id">
            <td data-label="Concepto">
              <span class="fin-cell-main">{{ i.name }}</span>
              <div class="fin-cell-sub">{{ i.category?.name }}</div>
            </td>
            <td data-label="Frecuencia">{{ FREQUENCIES[i.frequency] }} · día {{ i.day_of_month }}</td>
            <td data-label="Monto" class="right fin-num fin-cell-main">
              {{ formatBs(i.expense ? i.expense.amount : i.amount) }}
              <div v-if="i.expense && Number(i.expense.amount) !== Number(i.amount)" class="fin-cell-sub">plantilla {{ formatBs(i.amount) }}</div>
            </td>
            <td data-label="Estado">
              <span class="fin-badge" :class="STATUS[i.status].kind">{{ STATUS[i.status].label }}</span>
              <div v-if="i.expense && i.status === 'generated'" class="fin-cell-sub">{{ formatDay(i.expense.date) }}</div>
            </td>
            <td class="actions">
              <button v-if="i.status === 'pending'" class="fin-btn fin-btn-ghost fin-btn-sm" type="button" :disabled="generating" @click="generate([i.id])">Generar</button>
              <button v-else-if="i.status === 'dismissed'" class="fin-btn fin-btn-ghost fin-btn-sm" type="button" :disabled="generating" @click="generate([i.id])">Recuperar</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Plantillas -->
  <div class="fin-card">
    <div class="fin-card-head">
      <div>
        <h2>Lista de gastos fijos</h2>
        <p>Salarios, alquiler, internet, servicios, cuota del régimen simplificado… Cambiar un monto no modifica los meses ya generados.</p>
      </div>
      <button class="fin-btn fin-btn-secondary" type="button" @click="openNew">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="M12 5v14M5 12h14" /></svg>
        Agregar gasto fijo
      </button>
    </div>

    <div v-if="!finance.templates.length" class="fin-empty">Todavía no hay gastos fijos.</div>
    <div v-else class="fin-table-wrap">
      <table class="fin-table stack">
        <thead>
          <tr>
            <th>Concepto</th>
            <th>Categoría</th>
            <th>Frecuencia</th>
            <th>Desde</th>
            <th>Pago</th>
            <th class="right">Monto</th>
            <th class="actions"><span class="sr-only">Acciones</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in finance.templates" :key="t.id" :class="{ inactive: !t.is_active }">
            <td data-label="Concepto">
              <span class="fin-cell-main">{{ t.name }}</span>
              <span v-if="!t.is_active" class="fin-badge" style="margin-left: 6px">Pausado</span>
            </td>
            <td data-label="Categoría">{{ t.category?.name || '—' }}</td>
            <td data-label="Frecuencia">{{ FREQUENCIES[t.frequency] }} · día {{ t.day_of_month }}</td>
            <td data-label="Desde">{{ monthLabel(t.start_month) }}</td>
            <td data-label="Pago">{{ PAYMENT_METHODS[t.payment_method] || '—' }}</td>
            <td data-label="Monto" class="right fin-num fin-cell-main">{{ formatBs(t.amount) }}</td>
            <td class="actions">
              <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="toggle(t)">{{ t.is_active ? 'Pausar' : 'Activar' }}</button>
              <button class="fin-icon-btn" type="button" :aria-label="`Editar ${t.name}`" @click="openEdit(t)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" /></svg>
              </button>
              <button class="fin-icon-btn danger" type="button" :aria-label="`Eliminar ${t.name}`" @click="remove(t)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" /></svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <FinanceModal
    v-if="modalOpen"
    :title="editing ? 'Editar gasto fijo' : 'Agregar gasto fijo'"
    subtitle="Se propone cada mes que corresponde; lo confirmas con «Generar»."
    :busy="saving"
    @close="modalOpen = false"
  >
    <div class="fin-field">
      <label for="rx-name">Concepto</label>
      <input id="rx-name" v-model="form.name" type="text" maxlength="150" class="fin-input" placeholder="Ej.: Alquiler del local" />
    </div>
    <div class="fin-grid-2">
      <div class="fin-field">
        <label for="rx-amount">Monto (Bs)</label>
        <input id="rx-amount" v-model="form.amount" type="number" min="0.01" step="0.01" inputmode="decimal" class="fin-input" />
      </div>
      <div class="fin-field">
        <label for="rx-cat">Categoría</label>
        <select id="rx-cat" v-model="form.expense_category_id" class="fin-input">
          <optgroup v-for="g in groupedCategories" :key="g.key" :label="g.label">
            <option v-for="c in g.items" :key="c.id" :value="c.id">{{ c.name }}</option>
          </optgroup>
        </select>
      </div>
    </div>
    <div class="fin-grid-3">
      <div class="fin-field">
        <label for="rx-freq">Frecuencia</label>
        <select id="rx-freq" v-model="form.frequency" class="fin-input">
          <option v-for="(label, key) in FREQUENCIES" :key="key" :value="key">{{ label }}</option>
        </select>
      </div>
      <div class="fin-field">
        <label for="rx-start">Primer mes</label>
        <input id="rx-start" v-model="form.start_month" type="month" class="fin-input" />
      </div>
      <div class="fin-field">
        <label for="rx-day">Día de pago</label>
        <input id="rx-day" v-model.number="form.day_of_month" type="number" min="1" max="31" class="fin-input" />
      </div>
    </div>
    <p v-if="form.frequency !== 'monthly'" class="fin-hint">
      Se propone completo cada {{ { bimonthly: '2', quarterly: '3', annual: '12' }[form.frequency] }} meses a partir del primer mes (no se divide entre meses).
    </p>
    <div class="fin-grid-2">
      <div class="fin-field">
        <label for="rx-method">Forma de pago habitual</label>
        <select id="rx-method" v-model="form.payment_method" class="fin-input">
          <option v-for="(label, key) in PAYMENT_METHODS" :key="key" :value="key">{{ label }}</option>
        </select>
      </div>
      <div class="fin-field">
        <label for="rx-notes">Nota (opcional)</label>
        <input id="rx-notes" v-model="form.notes" type="text" maxlength="1000" class="fin-input" />
      </div>
    </div>
    <div v-if="formError" class="fin-note err" role="alert">{{ formError }}</div>
    <template #footer>
      <button class="fin-btn fin-btn-secondary" type="button" :disabled="saving" @click="modalOpen = false">Cancelar</button>
      <button class="fin-btn fin-btn-primary" type="button" :disabled="saving" @click="save">{{ saving ? 'Guardando…' : 'Guardar' }}</button>
    </template>
  </FinanceModal>
</template>
