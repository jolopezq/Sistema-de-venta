<script setup>
import { computed, reactive, ref } from 'vue';
import { apiFetch } from '../../services/api';
import { useFinanceStore, errorText, SECTIONS } from '../../stores/finance';
import FinanceModal from './FinanceModal.vue';

/** Pestaña Categorías: plan de cuentas agrupado por sección del Estado de Resultados. */
const finance = useFinanceStore();

const SECTION_HELP = {
  cost_of_sales: 'Insumos que se venden: helado, açaí, frutas, toppings, vasos y cucharas.',
  operating: 'Gastos para operar: salarios, alquiler, servicios, publicidad, mantenimiento.',
  financial: 'Intereses y comisiones bancarias.',
  taxes: 'Cuota fija del régimen simplificado y otros impuestos.',
};

const groups = computed(() => Object.entries(SECTIONS).map(([key, label]) => ({
  key,
  label,
  help: SECTION_HELP[key],
  items: finance.categories.filter((c) => c.section === key),
})));

const modalOpen = ref(false);
const saving = ref(false);
const formError = ref('');
const editing = ref(null);
const form = reactive({ name: '', section: 'operating', group_name: '', sort_order: 10, cost_behavior: 'fixed' });

const sectionChanged = computed(() => editing.value && editing.value.section !== form.section);

function openNew(section = 'operating') {
  editing.value = null;
  Object.assign(form, { name: '', section, group_name: '', sort_order: 10, cost_behavior: section === 'cost_of_sales' ? 'variable' : 'fixed' });
  formError.value = '';
  modalOpen.value = true;
}

function openEdit(c) {
  editing.value = c;
  Object.assign(form, { name: c.name, section: c.section, group_name: c.group_name || '', sort_order: c.sort_order ?? 10, cost_behavior: c.cost_behavior || 'fixed' });
  formError.value = '';
  modalOpen.value = true;
}

async function save() {
  if (!form.name.trim()) { formError.value = 'Escribe el nombre.'; return; }
  if (sectionChanged.value) {
    const ok = await finance.confirm({
      title: 'Cambiar de sección',
      message: `Todos los gastos de «${editing.value.name}», también los de meses anteriores, pasarán de `
        + `${SECTIONS[editing.value.section]} a ${SECTIONS[form.section]} en el Estado de Resultados.`,
      confirmLabel: 'Cambiar sección',
    });
    if (!ok) return;
  }
  saving.value = true;
  formError.value = '';
  try {
    const url = editing.value ? `/finance/categories/${editing.value.id}` : '/finance/categories';
    await apiFetch(url, { method: editing.value ? 'PUT' : 'POST', body: JSON.stringify(form) });
    modalOpen.value = false;
    // Ya guardado: el formulario queda libre aunque las listas tarden en recargarse
    saving.value = false;
    finance.notify(editing.value ? 'Categoría actualizada.' : 'Categoría creada.');
    await Promise.all([finance.loadCategories(), finance.loadPnl()]);
  } catch (err) {
    formError.value = errorText(err, 'No se pudo guardar la categoría.');
  } finally {
    saving.value = false;
  }
}

/** Fijo o variable: decide si el gasto entra en los costos fijos del punto de equilibrio. */
const savingBehavior = ref(null);
async function setBehavior(c, value) {
  if (value === c.cost_behavior) return;
  savingBehavior.value = c.id;
  try {
    await apiFetch(`/finance/categories/${c.id}`, {
      method: 'PUT',
      body: JSON.stringify({ name: c.name, section: c.section, group_name: c.group_name, cost_behavior: value }),
    });
    finance.notify(`«${c.name}» ahora es un costo ${value === 'fixed' ? 'fijo' : 'variable'}.`);
    await Promise.all([finance.loadCategories(), finance.loadBreakEven()]);
  } catch (err) {
    finance.notify(errorText(err), 'error');
  } finally {
    savingBehavior.value = null;
  }
}

async function toggle(c) {
  try {
    await apiFetch(`/finance/categories/${c.id}/toggle-active`, { method: 'PATCH' });
    finance.notify(c.is_active ? `«${c.name}» desactivada: ya no aparece al registrar gastos.` : `«${c.name}» activada.`);
    await finance.loadCategories();
  } catch (err) {
    finance.notify(errorText(err), 'error');
  }
}
</script>

<template>
  <div class="cat-grid">
  <div v-for="g in groups" :key="g.key" class="fin-card">
    <div class="fin-card-head">
      <div>
        <h2>{{ g.label }}</h2>
        <p>{{ g.help }}</p>
      </div>
      <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="openNew(g.key)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="M12 5v14M5 12h14" /></svg>
        Agregar
      </button>
    </div>
    <div v-if="!g.items.length" class="fin-empty">Sin categorías.</div>
    <div v-else class="fin-table-wrap">
      <table class="fin-table stack">
        <thead>
          <tr>
            <th>Categoría</th>
            <th class="cat-col-group">Grupo</th>
            <th>Tipo de costo</th>
            <th>Estado</th>
            <th class="actions"><span class="sr-only">Acciones</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in g.items" :key="c.id" :class="{ inactive: !c.is_active }">
            <td data-label="Categoría" class="fin-cell-main">{{ c.name }}<small v-if="c.group_name" class="cat-group">{{ c.group_name }}</small></td>
            <td data-label="Grupo" class="cat-col-group">{{ c.group_name || '—' }}</td>
            <td data-label="Tipo de costo">
              <select
                class="fin-input fin-input-sm fin-behavior"
                :value="c.cost_behavior || 'fixed'"
                :disabled="savingBehavior === c.id"
                :aria-label="`Tipo de costo de ${c.name}`"
                @change="setBehavior(c, $event.target.value)"
              >
                <option value="fixed">Fijo</option>
                <option value="variable">Variable</option>
              </select>
            </td>
            <td data-label="Estado"><span class="fin-badge" :class="c.is_active ? 'ok' : ''">{{ c.is_active ? 'Activa' : 'Desactivada' }}</span></td>
            <td class="actions">
              <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="toggle(c)">{{ c.is_active ? 'Desactivar' : 'Activar' }}</button>
              <button class="fin-icon-btn" type="button" :aria-label="`Editar ${c.name}`" @click="openEdit(c)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" /></svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
  </div>

  <FinanceModal v-if="modalOpen" :title="editing ? 'Editar categoría' : 'Nueva categoría'" :busy="saving" @close="modalOpen = false">
    <div class="fin-field">
      <label for="cx-name">Nombre</label>
      <input id="cx-name" v-model="form.name" type="text" maxlength="100" class="fin-input" placeholder="Ej.: Empaques de delivery" />
    </div>
    <div class="fin-grid-2">
      <div class="fin-field">
        <label for="cx-section">Sección</label>
        <select id="cx-section" v-model="form.section" class="fin-input">
          <option v-for="(label, key) in SECTIONS" :key="key" :value="key">{{ label }}</option>
        </select>
      </div>
      <div class="fin-field">
        <label for="cx-group">Grupo (opcional)</label>
        <input id="cx-group" v-model="form.group_name" type="text" maxlength="100" class="fin-input" placeholder="Ej.: Insumos" />
      </div>
    </div>
    <div class="fin-field">
      <label for="cx-behavior">Tipo de costo (punto de equilibrio)</label>
      <select id="cx-behavior" v-model="form.cost_behavior" class="fin-input">
        <option value="fixed">Fijo: se paga aunque no se venda (alquiler, sueldos, servicios)</option>
        <option value="variable">Variable: sube con lo que se vende (insumos, comisiones por venta)</option>
      </select>
    </div>
    <div v-if="sectionChanged" class="fin-note">
      Cambiar la sección reclasifica todos los gastos de esta categoría, también los de meses anteriores.
    </div>
    <div v-if="formError" class="fin-note err" role="alert">{{ formError }}</div>
    <template #footer>
      <button class="fin-btn fin-btn-secondary" type="button" :disabled="saving" @click="modalOpen = false">Cancelar</button>
      <button class="fin-btn fin-btn-primary" type="button" :disabled="saving" @click="save">{{ saving ? 'Guardando…' : 'Guardar' }}</button>
    </template>
  </FinanceModal>
</template>

<style scoped>

.cat-grid { display: contents; }
.cat-group { display: none; }

/* Portátil de caja: las 4 secciones en 2 columnas (el grupo pasa debajo del nombre). Ver finance.css */
@media (min-width: 761px) and (max-width: 1366px), (min-width: 761px) and (max-height: 800px) {
  .cat-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; align-items: start; }
  .cat-col-group { display: none; }
  .cat-grid .fin-input-sm { height: 28px; padding-top: 2px; padding-bottom: 2px; }
  .cat-grid .fin-btn-sm { padding-top: 3px; padding-bottom: 3px; }
  .cat-grid .fin-table td { padding-top: 4px; padding-bottom: 4px; }
  .cat-group { display: block; font-size: 11.5px; font-weight: 400; color: var(--ink-500); }
  .cat-grid .fin-card-head p { display: none; }
  .cat-grid .fin-table th, .cat-grid .fin-table td { padding-left: 10px; padding-right: 10px; }
}
</style>
