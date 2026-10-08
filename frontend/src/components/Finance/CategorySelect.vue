<script setup>
import { computed, nextTick, reactive, ref } from 'vue';
import { useFinanceStore, errorText, nameKey, SECTIONS } from '../../stores/finance';
import FinanceModal from './FinanceModal.vue';

/**
 * Selector de categoría contable con «+ Nueva categoría…» al final: si lo que se registra no está en la
 * lista, se crea ahí mismo (nombre + sección) y queda elegida. Avisa si ya existe una parecida para no
 * duplicarla («Gas licuado» / «gas  licuado»).
 */
const props = defineProps({
  modelValue: { type: [Number, String, null], default: '' },
  defaultSection: { type: String, default: 'operating' },
  placeholder: { type: String, default: '' },
  small: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  id: { type: String, default: undefined },
  ariaLabel: { type: String, default: undefined },
});
const emit = defineEmits(['update:modelValue', 'created']);
const finance = useFinanceStore();
const NEW = '__new__';

const select = ref(null);

// Activas por sección, más la elegida aunque esté desactivada (al editar)
const groups = computed(() => Object.entries(SECTIONS)
  .map(([key, label]) => ({
    key,
    label,
    items: finance.categories.filter((c) => c.section === key && (c.is_active || c.id === Number(props.modelValue))),
  }))
  .filter((g) => g.items.length));

function onChange(event) {
  const value = event.target.value;
  if (value === NEW) {
    // El selector vuelve a mostrar la elección anterior mientras se crea la nueva
    event.target.value = props.modelValue ?? '';
    openNew();
    return;
  }
  emit('update:modelValue', value === '' ? '' : Number(value));
}

// ---------- Nueva categoría ----------
const open = ref(false);
const saving = ref(false);
const error = ref('');
const form = reactive({ name: '', section: 'operating', group_name: '' });

function openNew() {
  Object.assign(form, { name: '', section: props.defaultSection, group_name: '' });
  error.value = '';
  open.value = true;
}

/** Categorías con nombre parecido al que se escribe (para usar la existente en vez de duplicarla). */
const similar = computed(() => {
  const key = nameKey(form.name);
  if (key.length < 3) return [];
  const words = key.split(' ').filter((w) => w.length > 3);
  // Primero la idéntica, luego las que contienen el texto y al final las que comparten una palabra
  const score = (c) => {
    const k = nameKey(c.name);
    if (k === key) return 3;
    if (k.includes(key) || key.includes(k)) return 2;
    return words.some((w) => k.split(' ').includes(w)) ? 1 : 0;
  };
  return finance.categories
    .map((c) => ({ c, s: score(c) }))
    .filter((x) => x.s > 0)
    .sort((a, b) => b.s - a.s)
    .slice(0, 3)
    .map((x) => x.c);
});
const exact = computed(() => similar.value.find((c) => nameKey(c.name) === nameKey(form.name)));

const sectionGroups = computed(() => [...new Set(finance.categories
  .filter((c) => c.section === form.section && c.group_name)
  .map((c) => c.group_name))]);

function useExisting(category) {
  if (!category.is_active) {
    error.value = `«${category.name}» está desactivada: actívala en la pestaña Categorías.`;
    return;
  }
  emit('update:modelValue', category.id);
  open.value = false;
}

async function create() {
  if (!form.name.trim()) { error.value = 'Escribe el nombre de la categoría.'; return; }
  if (exact.value) { useExisting(exact.value); return; }
  saving.value = true;
  error.value = '';
  try {
    const category = await finance.createCategory({
      name: form.name,
      section: form.section,
      group_name: form.group_name || null,
    });
    emit('update:modelValue', category.id);
    emit('created', category);
    finance.notify(`Categoría «${category.name}» creada en ${SECTIONS[category.section]}.`);
    open.value = false;
    await nextTick();
    select.value?.focus();
  } catch (err) {
    error.value = errorText(err, 'No se pudo crear la categoría.');
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <select
    :id="id"
    ref="select"
    class="fin-input"
    :class="{ 'fin-input-sm': small }"
    :value="modelValue ?? ''"
    :disabled="disabled"
    :aria-label="ariaLabel"
    @change="onChange"
  >
    <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
    <optgroup v-for="g in groups" :key="g.key" :label="g.label">
      <option v-for="c in g.items" :key="c.id" :value="c.id">{{ c.name }}{{ c.is_active ? '' : ' (desactivada)' }}</option>
    </optgroup>
    <option :value="NEW">+ Nueva categoría…</option>
  </select>

  <FinanceModal
      v-if="open"
      title="Nueva categoría"
      subtitle="Para lo que no está en la lista. Queda guardada para los próximos registros."
      :busy="saving"
      @close="open = false"
    >
      <div class="fin-field">
        <label for="nc-name">Nombre</label>
        <input id="nc-name" v-model="form.name" type="text" maxlength="100" class="fin-input" placeholder="Ej.: Gas licuado" autofocus @keyup.enter="create" />
      </div>
      <div v-if="similar.length" class="fin-note info">
        {{ exact ? 'Ya existe:' : '¿Es alguna de estas?' }}
        <span v-for="(c, i) in similar" :key="c.id">
          <button class="fin-btn-link" type="button" @click="useExisting(c)">{{ c.name }}</button>
          <span class="fin-small"> ({{ SECTIONS[c.section] }}{{ c.is_active ? '' : ', desactivada' }})</span><template v-if="i < similar.length - 1">, </template>
        </span>
      </div>
      <div class="fin-field">
        <span class="fin-label">¿Dónde va en el Estado de Resultados?</span>
        <div class="fin-section-options" role="radiogroup" aria-label="Sección">
          <label v-for="(label, key) in SECTIONS" :key="key" class="fin-section-option" :class="{ active: form.section === key }">
            <input v-model="form.section" type="radio" name="nc-section" :value="key" />
            <span>
              <strong>{{ label }}</strong>
              <span class="fin-small fin-muted">{{ {
                cost_of_sales: 'Lo que se vende: insumos, frutas, vasos, cucharas',
                operating: 'Para funcionar: sueldos, alquiler, servicios, publicidad',
                financial: 'Intereses y comisiones bancarias',
                taxes: 'Impuestos y patentes',
              }[key] }}</span>
            </span>
          </label>
        </div>
      </div>
      <div class="fin-field">
        <label for="nc-group">Grupo (opcional)</label>
        <input id="nc-group" v-model="form.group_name" type="text" maxlength="100" class="fin-input" list="nc-group-list" placeholder="Ej.: Servicios" />
        <datalist id="nc-group-list">
          <option v-for="g in sectionGroups" :key="g" :value="g" />
        </datalist>
      </div>
      <div v-if="error" class="fin-note err" role="alert">{{ error }}</div>
      <template #footer>
        <button class="fin-btn fin-btn-secondary" type="button" :disabled="saving" @click="open = false">Cancelar</button>
        <button class="fin-btn fin-btn-primary" type="button" :disabled="saving" @click="create">
          {{ saving ? 'Creando…' : exact ? 'Usar la existente' : 'Crear y usar' }}
        </button>
      </template>
  </FinanceModal>
</template>
