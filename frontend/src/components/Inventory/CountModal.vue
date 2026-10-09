<script setup>
import { computed, onMounted, ref } from 'vue';
import { apiFetch } from '../../services/api';
import { formatBs, errorText } from '../../stores/finance';
import { formatQty, formatSigned, relativeDays, formatDateTime } from '../../utils/inventoryLabels';
import FinanceModal from '../Finance/FinanceModal.vue';
import '../Finance/finance.css';

/**
 * Conteo físico (plan de inventario, F3, decisión D5): semanal de los insumos clave y mensual completo.
 * Se escribe lo que hay en el local; el sistema muestra la diferencia contra su stock en unidades y en Bs y, al
 * confirmar, ajusta el stock y deja el conteo en el historial. Lo que no se escribe no se toca.
 */
const props = defineProps({
  focusId: { type: Number, default: null },
});
const emit = defineEmits(['close', 'saved']);

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const sheet = ref([]);
const values = ref({});
const view = ref('all');
const search = ref('');
const notes = ref('');
const tab = ref('count');
const step = ref('sheet'); // sheet → review → done
const result = ref(null);

const keyCount = computed(() => sheet.value.filter((i) => i.is_key).length);
const visible = computed(() => {
  const q = search.value.trim().toLowerCase();
  return sheet.value.filter((i) => (view.value === 'all' || i.is_key) && (!q || i.name.toLowerCase().includes(q)));
});

function filled(id) {
  const v = values.value[id];
  return v !== undefined && v !== '' && v !== null && Number.isFinite(Number(v));
}
function diff(i) {
  return filled(i.id) ? Math.round((Number(values.value[i.id]) - i.current_stock) * 10000) / 10000 : null;
}
function cost(i) {
  const d = diff(i);
  return d === null || !(i.unit_cost > 0) ? null : d * i.unit_cost;
}

const counted = computed(() => sheet.value.filter((i) => filled(i.id)));
const differing = computed(() => counted.value.filter((i) => Math.abs(diff(i)) >= 0.00005));
const net = computed(() => differing.value.reduce((s, i) => s + (cost(i) ?? 0), 0));
const shortage = computed(() => differing.value.reduce((s, i) => s + Math.min(cost(i) ?? 0, 0), 0));
const unpriced = computed(() => differing.value.filter((i) => cost(i) === null).length);

const scope = computed(() => {
  if (counted.value.length === sheet.value.length && sheet.value.length) return 'all';
  const keys = sheet.value.filter((i) => i.is_key);
  if (keys.length && keys.every((i) => filled(i.id))) return 'key';
  return 'partial';
});

onMounted(async () => {
  try {
    const data = await apiFetch('/inventory/counts/sheet');
    sheet.value = data.ingredients;
    view.value = keyCount.value && !props.focusId ? 'key' : 'all';
    const focus = props.focusId ? sheet.value.find((i) => i.id === props.focusId) : null;
    if (focus) search.value = focus.name;
  } catch (err) {
    error.value = errorText(err, 'No se pudo cargar la hoja de conteo.');
  } finally {
    loading.value = false;
  }
});

function focusNext(event) {
  const inputs = [...event.target.closest('.cm-list').querySelectorAll('.cm-input')];
  inputs[inputs.indexOf(event.target) + 1]?.focus();
}

function review() {
  error.value = '';
  if (!counted.value.length) {
    error.value = 'Escribe la cantidad contada de al menos un insumo.';
    return;
  }
  step.value = 'review';
}

async function confirm() {
  saving.value = true;
  error.value = '';
  try {
    result.value = await apiFetch('/inventory/counts', {
      method: 'POST',
      body: JSON.stringify({
        scope: scope.value,
        notes: notes.value.trim() || null,
        lines: counted.value.map((i) => ({ ingredient_id: i.id, counted: Number(values.value[i.id]) })),
      }),
    });
    step.value = 'done';
    emit('saved', result.value);
  } catch (err) {
    error.value = errorText(err, 'No se pudo guardar el conteo.');
  } finally {
    saving.value = false;
  }
}

// --- Historial ---
const history = ref([]);
const historyPage = ref(0);
const historyLast = ref(1);
const historyLoading = ref(false);
const opened = ref({});

async function loadHistory(reset = false) {
  if (historyLoading.value) return;
  if (reset) { history.value = []; historyPage.value = 0; historyLast.value = 1; }
  if (historyPage.value >= historyLast.value) return;
  historyLoading.value = true;
  try {
    const page = await apiFetch(`/inventory/counts?page=${historyPage.value + 1}&per_page=10`);
    history.value.push(...page.data);
    historyPage.value = page.current_page;
    historyLast.value = page.last_page;
  } catch (err) {
    error.value = errorText(err, 'No se pudo cargar el historial.');
  } finally {
    historyLoading.value = false;
  }
}

function showHistory() {
  tab.value = 'history';
  error.value = '';
  if (!history.value.length) loadHistory(true);
}

async function toggle(count) {
  if (opened.value[count.id]) { opened.value[count.id] = null; return; }
  try {
    opened.value[count.id] = await apiFetch(`/inventory/counts/${count.id}`);
  } catch (err) {
    error.value = errorText(err, 'No se pudo abrir el conteo.');
  }
}

const SCOPES = { key: 'Insumos clave', all: 'Todos los insumos', partial: 'Selección' };
</script>

<template>
  <div class="fin-page cm-host">
    <FinanceModal
      wide
      title="Conteo físico"
      subtitle="Escribe lo que hay en el local. El sistema calcula la diferencia y, al confirmar, deja el stock igual a lo contado."
      :busy="saving"
      @close="emit('close')"
    >
      <div class="fin-tabs cm-tabs" role="tablist">
        <button class="fin-tab" :class="{ active: tab === 'count' }" role="tab" :aria-selected="tab === 'count'" type="button" @click="tab = 'count'">Contar</button>
        <button class="fin-tab" :class="{ active: tab === 'history' }" role="tab" :aria-selected="tab === 'history'" type="button" @click="showHistory">Historial</button>
      </div>

      <div v-if="loading" class="fin-loading">Cargando…</div>

      <!-- ===== Contar ===== -->
      <template v-else-if="tab === 'count'">
        <!-- Paso 3: resultado -->
        <template v-if="step === 'done' && result">
          <div class="fin-note info" role="status">
            Conteo guardado: {{ result.lines_count }} insumos contados,
            {{ result.lines.filter((l) => l.difference !== 0).length }} con diferencia.
            <template v-if="result.lines.some((l) => l.cost !== null)">
              Diferencia neta <strong>{{ formatBs(result.difference_cost, { sign: true }) }}</strong>.
            </template>
          </div>
          <div class="cm-list" role="list">
            <div v-for="l in result.lines.filter((x) => x.difference !== 0)" :key="l.ingredient_id" class="cm-result" role="listitem">
              <span class="cm-name">{{ l.name }}</span>
              <span class="fin-small">tenía {{ formatQty(l.expected) }} · contado {{ formatQty(l.counted) }} {{ l.unit }}</span>
              <span class="fin-num" :class="l.difference < 0 ? 'cm-neg' : 'cm-pos'">{{ formatSigned(l.difference) }} {{ l.unit }}<template v-if="l.cost !== null"> · {{ formatBs(l.cost, { sign: true }) }}</template></span>
              <span v-if="l.is_baseline" class="fin-badge info">Primer conteo</span>
            </div>
            <p v-if="!result.lines.some((x) => x.difference !== 0)" class="fin-empty">Todo coincide con el sistema.</p>
          </div>
        </template>

        <!-- Paso 2: revisar -->
        <template v-else-if="step === 'review'">
          <div class="fin-note info">
            Revisa antes de confirmar. Al confirmar, el stock de estos insumos pasa a ser lo que contaste; lo que no escribiste no se toca.
          </div>
          <div class="fin-kpis cm-kpis">
            <div class="fin-card fin-kpi"><span class="fin-kpi-label">Insumos contados</span><span class="fin-kpi-value">{{ counted.length }}</span><span class="fin-kpi-sub">{{ differing.length }} con diferencia</span></div>
            <div class="fin-card fin-kpi"><span class="fin-kpi-label">Faltante</span><span class="fin-kpi-value">{{ formatBs(Math.abs(shortage)) }}</span><span class="fin-kpi-sub">lo que falta frente al sistema</span></div>
            <div class="fin-card fin-kpi"><span class="fin-kpi-label">Diferencia neta</span><span class="fin-kpi-value">{{ formatBs(net, { sign: true }) }}</span><span class="fin-kpi-sub">faltantes y sobrantes juntos</span></div>
          </div>
          <div class="cm-list" role="list">
            <div v-for="i in differing" :key="i.id" class="cm-result" role="listitem">
              <span class="cm-name">{{ i.name }}</span>
              <span class="fin-small">sistema {{ formatQty(i.current_stock) }} · contado {{ formatQty(values[i.id]) }} {{ i.unit }}</span>
              <span class="fin-num" :class="diff(i) < 0 ? 'cm-neg' : 'cm-pos'">{{ formatSigned(diff(i)) }} {{ i.unit }}<template v-if="cost(i) !== null"> · {{ formatBs(cost(i), { sign: true }) }}</template></span>
              <span v-if="!i.last_counted_at" class="fin-badge info" title="Es la primera vez que se cuenta: ordena el stock de partida y no cuenta como merma oculta">Primer conteo</span>
            </div>
            <p v-if="!differing.length" class="fin-empty">Todo coincide con el sistema.</p>
          </div>
          <p v-if="unpriced" class="fin-hint">{{ unpriced }} insumo(s) con diferencia no tienen costo cargado: se ajusta el stock pero no se valoriza.</p>
          <div class="fin-field">
            <label for="cm-notes">Nota del conteo (opcional)</label>
            <input id="cm-notes" v-model="notes" type="text" maxlength="500" class="fin-input" placeholder="Ej.: conteo del martes al cerrar" />
          </div>
        </template>

        <!-- Paso 1: hoja -->
        <template v-else>
          <div class="cm-filters">
            <div class="fin-chips">
              <button v-if="keyCount" type="button" class="fin-chip" :class="{ active: view === 'key' }" @click="view = 'key'">Claves ({{ keyCount }})</button>
              <button type="button" class="fin-chip" :class="{ active: view === 'all' }" @click="view = 'all'">Todos ({{ sheet.length }})</button>
            </div>
            <input v-model="search" type="search" class="fin-input cm-search" placeholder="Buscar insumo…" aria-label="Buscar insumo" />
          </div>
          <p v-if="!keyCount" class="fin-hint">Para el conteo semanal marca los insumos clave (pulpa, helado, granola…) con «Editar» en Inventario.</p>

          <div class="cm-list" role="list">
            <div class="cm-row cm-head" aria-hidden="true">
              <span>Insumo</span><span class="cm-col">Sistema</span><span class="cm-col">Contado</span><span class="cm-col">Diferencia</span>
            </div>
            <div v-for="i in visible" :key="i.id" class="cm-row" role="listitem">
              <span class="cm-name">
                {{ i.name }}
                <span class="fin-small cm-last">{{ i.last_counted_at ? `contado ${relativeDays(i.last_counted_at)}` : 'nunca contado' }}</span>
              </span>
              <span class="cm-col fin-num"><span class="cm-label">Sistema</span>{{ formatQty(i.current_stock) }} {{ i.unit }}</span>
              <span class="cm-col">
                <input
                  v-model="values[i.id]"
                  type="number" min="0" step="any" inputmode="decimal"
                  class="fin-input fin-input-sm cm-input"
                  :aria-label="`Contado de ${i.name} (${i.unit})`"
                  placeholder="—"
                  @keydown.enter.prevent="focusNext"
                />
              </span>
              <span class="cm-col fin-num" :class="diff(i) === null ? '' : (Math.abs(diff(i)) < 0.00005 ? 'cm-ok' : diff(i) < 0 ? 'cm-neg' : 'cm-pos')">
                <template v-if="diff(i) !== null">
                  {{ Math.abs(diff(i)) < 0.00005 ? 'Coincide' : `${formatSigned(diff(i))} ${i.unit}` }}
                  <span v-if="cost(i) !== null && Math.abs(diff(i)) >= 0.00005" class="fin-small"> {{ formatBs(cost(i), { sign: true }) }}</span>
                </template>
              </span>
            </div>
            <p v-if="!visible.length" class="fin-empty">No hay insumos con ese filtro.</p>
          </div>
        </template>
      </template>

      <!-- ===== Historial ===== -->
      <template v-else>
        <div class="cm-list" role="list">
          <div v-for="c in history" :key="c.id" class="cm-hist" role="listitem">
            <button type="button" class="cm-hist-head" :aria-expanded="!!opened[c.id]" @click="toggle(c)">
              <span><strong>{{ formatDateTime(c.counted_at) }}</strong> · {{ SCOPES[c.scope] || c.scope }}<template v-if="c.user"> · {{ c.user }}</template></span>
              <span class="fin-small">{{ c.lines_count }} insumos · {{ c.differing_lines }} con diferencia · <span class="fin-num" :class="c.difference_cost < 0 ? 'cm-neg' : ''">{{ formatBs(c.difference_cost, { sign: true }) }}</span></span>
            </button>
            <div v-if="opened[c.id]" class="cm-hist-body">
              <p v-if="opened[c.id].notes" class="fin-hint">{{ opened[c.id].notes }}</p>
              <div v-for="l in opened[c.id].lines" :key="l.ingredient_id" class="cm-result">
                <span class="cm-name">{{ l.name }}</span>
                <span class="fin-small">tenía {{ formatQty(l.expected) }} · contado {{ formatQty(l.counted) }} {{ l.unit }}</span>
                <span class="fin-num" :class="l.difference < 0 ? 'cm-neg' : l.difference > 0 ? 'cm-pos' : 'cm-ok'">
                  {{ l.difference === 0 ? 'Coincide' : `${formatSigned(l.difference)} ${l.unit}` }}<template v-if="l.cost !== null && l.difference !== 0"> · {{ formatBs(l.cost, { sign: true }) }}</template>
                </span>
                <span v-if="l.is_baseline" class="fin-badge info">Primer conteo</span>
              </div>
            </div>
          </div>
          <p v-if="!history.length && !historyLoading" class="fin-empty">Todavía no hay conteos.</p>
        </div>
        <button v-if="historyPage < historyLast" class="fin-btn fin-btn-ghost fin-btn-sm" type="button" :disabled="historyLoading" @click="loadHistory()">
          {{ historyLoading ? 'Cargando…' : 'Cargar más' }}
        </button>
      </template>

      <div v-if="error" class="fin-note err" role="alert">{{ error }}</div>

      <template #footer>
        <template v-if="tab === 'count' && !loading">
          <span v-if="step === 'sheet'" class="fin-small cm-foot-info">{{ counted.length }} contados · {{ differing.length }} con diferencia</span>
          <span class="fin-spacer"></span>
          <template v-if="step === 'sheet'">
            <button class="fin-btn fin-btn-secondary" type="button" @click="emit('close')">Cancelar</button>
            <button class="fin-btn fin-btn-primary" type="button" :disabled="!counted.length" @click="review">Revisar conteo</button>
          </template>
          <template v-else-if="step === 'review'">
            <button class="fin-btn fin-btn-secondary" type="button" :disabled="saving" @click="step = 'sheet'">Volver</button>
            <button class="fin-btn fin-btn-primary" type="button" :disabled="saving" @click="confirm">{{ saving ? 'Guardando…' : 'Confirmar y ajustar stock' }}</button>
          </template>
          <button v-else class="fin-btn fin-btn-primary" type="button" @click="emit('close')">Listo</button>
        </template>
        <template v-else>
          <span class="fin-spacer"></span>
          <button class="fin-btn fin-btn-secondary" type="button" @click="emit('close')">Cerrar</button>
        </template>
      </template>
    </FinanceModal>
  </div>
</template>

<style scoped>
.cm-host { display: contents; }
.cm-tabs { padding: 0; margin: -4px 0 4px; }
.cm-filters { display: flex; gap: 10px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
.cm-search { max-width: 240px; }
.cm-list { display: flex; flex-direction: column; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; background: var(--surface); }
.cm-row { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(90px, 1fr) minmax(0, 1.2fr); gap: 10px; align-items: center; padding: 8px 14px; border-bottom: 1px solid var(--border); font-size: 13px; color: var(--ink-900); }
.cm-row:last-child { border-bottom: none; }
.cm-head { background: var(--cream-100); font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: var(--ink-500); padding-top: 7px; padding-bottom: 7px; }
.cm-name { display: flex; flex-direction: column; gap: 1px; font-weight: 600; min-width: 0; }
.cm-last { font-weight: 400; }
.cm-col { text-align: right; }
.cm-label { display: none; }
.cm-input { width: 100%; text-align: right; }
.cm-neg { color: var(--danger-text); }
.cm-pos { color: var(--lime-700); }
.cm-ok { color: var(--ink-500); }
.cm-result { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; padding: 9px 14px; border-bottom: 1px solid var(--border); font-size: 13px; color: var(--ink-900); }
.cm-result:last-child { border-bottom: none; }
.cm-result .cm-name { flex: 1 1 160px; }
.cm-kpis { margin: 0; }
.cm-hist { border-bottom: 1px solid var(--border); }
.cm-hist:last-child { border-bottom: none; }
.cm-hist-head { width: 100%; display: flex; flex-direction: column; gap: 2px; align-items: flex-start; padding: 10px 14px; background: transparent; border: none; font: inherit; font-size: 13px; color: var(--ink-900); cursor: pointer; text-align: left; }
.cm-hist-head:hover { background: var(--cream-100); }
.cm-hist-body { background: var(--cream-50); border-top: 1px solid var(--border); }
.cm-foot-info { margin-right: 8px; }
@media (max-width: 640px) {
  .cm-head { display: none; }
  .cm-row { grid-template-columns: 1fr 1fr; row-gap: 6px; }
  .cm-row .cm-name { grid-column: 1 / -1; }
  .cm-col { text-align: left; }
  .cm-label { display: inline; font-size: 11.5px; color: var(--ink-500); margin-right: 4px; }
  .cm-search { max-width: none; flex: 1 1 100%; }
}
</style>
