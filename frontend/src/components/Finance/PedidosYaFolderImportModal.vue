<script setup>
import { computed, reactive, ref } from 'vue';
import { apiFetch } from '../../services/api';
import { formatBs, formatDay, errorText } from '../../stores/finance';
import { classifyArchiveFiles } from '../../utils/pedidosYaArchive';
import FinanceModal from './FinanceModal.vue';

const emit = defineEmits(['close', 'imported']);

// Estados del flujo: 'select' (elegir archivos), 'preview' (revisión de semanas), 'importing' (procesando lote), 'done' (finalizado)
const step = ref('select');

const folderInput = ref(null);
const csvInput = ref(null);

const rawFiles = ref([]);
const csvFile = ref(null);
const ignoredFiles = ref([]);

const weeks = ref([]); // Lista de semanas con su resultado de read-week
const reading = ref(false);
const readingCurrent = ref(0);
const readingTotal = ref(0);
const globalError = ref('');

const createSales = ref(true);
const importing = ref(false);
const importIndex = ref(0);
const importResults = ref([]); // { id, status: 'ok'|'skipped'|'error', message }

// ---------- Selección y agrupamiento de archivos ----------
function triggerFolderSelect() {
  folderInput.value?.click();
}

function triggerCsvSelect() {
  csvInput.value?.click();
}

function handleFolderFiles(e) {
  const files = e.target.files;
  if (!files || !files.length) return;
  rawFiles.value = Array.from(files);
  processFiles();
}

function handleCsvFile(e) {
  const file = e.target.files?.[0];
  if (!file) return;
  csvFile.value = file;
  if (step.value === 'preview' || step.value === 'select') {
    processFiles();
  }
}

function onDrop(e) {
  e.preventDefault();
  const dt = e.dataTransfer;
  if (dt?.files?.length) {
    rawFiles.value = Array.from(dt.files);
    processFiles();
  }
}

async function processFiles() {
  globalError.value = '';
  const classified = classifyArchiveFiles(rawFiles.value);

  if (classified.csvFile && !csvFile.value) {
    csvFile.value = classified.csvFile;
  }
  ignoredFiles.value = classified.ignoredFiles;

  if (classified.validWeeks.length === 0) {
    globalError.value = 'No se encontraron estados de cuenta válidos (account_statement_*.pdf) en los archivos seleccionados.';
    return;
  }

  // Pasar a lectura previa de cada semana
  step.value = 'preview';
  reading.value = true;
  readingTotal.value = classified.validWeeks.length;
  readingCurrent.value = 0;
  weeks.value = [];

  for (let i = 0; i < classified.validWeeks.length; i++) {
    readingCurrent.value = i + 1;
    const w = classified.validWeeks[i];

    try {
      const data = new FormData();
      data.append('statement_file', w.statement_file);
      if (w.orders_xls) data.append('orders_xls', w.orders_xls);
      if (w.invoice_pdf) data.append('invoice_pdf', w.invoice_pdf);
      if (csvFile.value) data.append('csv_file', csvFile.value);
      data.append('statement_number', w.id);

      const previewData = await apiFetch('/finance/pedidosya-settlements/read-week', {
        method: 'POST',
        body: data,
      });

      // Se selecciona por defecto si es nueva o actualización idéntica
      const isSelectable = previewData.status === 'new' || previewData.status === 'same_dates_same_amounts';
      const isBlocked = previewData.status === 'overlaps' || (previewData.blockers && previewData.blockers.length > 0);

      weeks.value.push({
        id: w.id,
        statement_file: w.statement_file,
        orders_xls: w.orders_xls,
        invoice_pdf: w.invoice_pdf,
        selected: isSelectable && !isBlocked,
        preview: previewData,
      });
    } catch (err) {
      weeks.value.push({
        id: w.id,
        statement_file: w.statement_file,
        orders_xls: w.orders_xls,
        invoice_pdf: w.invoice_pdf,
        selected: false,
        preview: {
          statement_number: w.id,
          status: 'blocked',
          statement: null,
          invoice: null,
          blockers: [errorText(err, 'No se pudo leer la semana')],
          warnings: [],
          orders_count: 0,
        },
      });
    }
  }

  reading.value = false;
  initChoiceMappings();
}

// ---------- Equivalencias de productos u opciones pendientes ----------
const choice = reactive({ products: {}, options: {} });
const savingMappings = ref(false);
const mappingError = ref('');

// Consolidar todos los productos y opciones sin equivalencia de todas las semanas
const allUnresolved = computed(() => {
  const prodMap = new Map();
  const optMap = new Map();

  for (const w of weeks.value) {
    const unres = w.preview?.sales?.unresolved;
    if (!unres) continue;

    for (const p of unres.products || []) {
      if (!prodMap.has(p.name)) {
        prodMap.set(p.name, { ...p });
      } else {
        const item = prodMap.get(p.name);
        item.orders = (item.orders || 0) + (p.orders || 0);
      }
    }

    for (const o of unres.options || []) {
      if (!optMap.has(o.name)) {
        optMap.set(o.name, { ...o });
      } else {
        const item = optMap.get(o.name);
        item.orders = (item.orders || 0) + (o.orders || 0);
      }
    }
  }

  return {
    products: Array.from(prodMap.values()),
    options: Array.from(optMap.values()),
    totalCount: prodMap.size + optMap.size,
  };
});

function initChoiceMappings() {
  for (const p of allUnresolved.value.products) {
    if (!(p.name in choice.products)) {
      choice.products[p.name] = p.suggestions?.[0]?.id ?? '';
    }
  }
  for (const o of allUnresolved.value.options) {
    if (!(o.name in choice.options)) {
      choice.options[o.name] = '';
    }
  }
}

const mappingsReady = computed(() => {
  return allUnresolved.value.products.every((p) => choice.products[p.name] !== '')
    && allUnresolved.value.options.every((o) => choice.options[o.name] !== '');
});

async function saveMappings() {
  savingMappings.value = true;
  mappingError.value = '';
  try {
    const mappings = [
      ...allUnresolved.value.products.map((p) => ({ entity_type: 'product', name: p.name, internal_id: Number(choice.products[p.name]) })),
      ...allUnresolved.value.options.map((o) => ({ entity_type: 'option', name: o.name, internal_id: Number(choice.options[o.name]) })),
    ];
    await apiFetch('/finance/pedidosya-mappings', { method: 'POST', body: JSON.stringify({ mappings }) });

    // Releer semanas que tenían nombres pendientes para actualizar el análisis
    await processFiles();
  } catch (err) {
    mappingError.value = errorText(err, 'No se pudieron guardar las equivalencias.');
  } finally {
    savingMappings.value = false;
  }
}

// ---------- Totales y validaciones ----------
const selectedWeeks = computed(() => weeks.value.filter((w) => w.selected));
const hasBlockedSelected = computed(() => selectedWeeks.value.some((w) => {
  const p = w.preview;
  return p.status === 'overlaps' || (p.blockers && p.blockers.length > 0);
}));

const hasPendingMappings = computed(() => createSales.value && allUnresolved.value.totalCount > 0);

const canImport = computed(() => {
  return selectedWeeks.value.length > 0 && !hasBlockedSelected.value && !hasPendingMappings.value && !reading.value && !importing.value;
});

// ---------- Ejecución de la importación por lote ----------
async function runBatchImport() {
  if (!canImport.value) return;

  step.value = 'importing';
  importing.value = true;
  importIndex.value = 0;
  importResults.value = [];

  for (let i = 0; i < selectedWeeks.value.length; i++) {
    importIndex.value = i + 1;
    const w = selectedWeeks.value[i];

    try {
      const data = new FormData();
      data.append('mode', 'folder');
      data.append('statement_file', w.statement_file);
      if (w.orders_xls) data.append('orders_xls', w.orders_xls);
      if (w.invoice_pdf) data.append('invoice_pdf', w.invoice_pdf);
      if (csvFile.value) data.append('csv_file', csvFile.value);
      data.append('statement_number', w.id);
      data.append('sales_mode', createSales.value ? 'create' : 'skip');

      const res = await apiFetch('/finance/pedidosya-settlements', {
        method: 'POST',
        body: data,
      });

      importResults.value.push({
        id: w.id,
        dates: `${formatDay(w.preview.start_date, { year: false })} – ${formatDay(w.preview.end_date)}`,
        status: res.settlement ? 'ok' : 'skipped',
        message: res.message || 'Importada correctamente',
        warnings: res.warnings || [],
        sales: res.sales,
      });
    } catch (err) {
      importResults.value.push({
        id: w.id,
        dates: w.preview.start_date ? `${formatDay(w.preview.start_date, { year: false })} – ${formatDay(w.preview.end_date)}` : `ID ${w.id}`,
        status: 'error',
        message: errorText(err, 'Error al importar'),
        warnings: [],
        sales: null,
      });
    }
  }

  importing.value = false;
  step.value = 'done';
}

function finish() {
  emit('imported', {
    message: `${importResults.value.filter((r) => r.status === 'ok').length} semanas de PedidosYa importadas.`,
    results: importResults.value,
  });
  emit('close');
}

function statusBadge(status) {
  switch (status) {
    case 'new':
      return { label: 'Nueva', class: 'ok' };
    case 'already_imported':
      return { label: 'Ya importada', class: 'muted' };
    case 'same_dates_same_amounts':
      return { label: 'Cargada a mano', class: 'info' };
    case 'same_dates_diff_amounts':
      return { label: 'Montos distintos', class: 'warn' };
    case 'overlaps':
      return { label: 'Se cruza', class: 'err' };
    case 'blocked':
      return { label: 'Bloqueada', class: 'err' };
    default:
      return { label: status, class: '' };
  }
}
</script>

<template>
  <FinanceModal
    title="Importar carpeta de PedidosYa"
    subtitle="Importación por lote de estados de cuenta, facturas fiscales y planillas de pedidos."
    size="xl"
    :busy="reading || importing"
    @close="emit('close')"
  >
    <!-- PASO 1: Selección de carpeta y CSV -->
    <div v-if="step === 'select'" class="fin-folder-select-wrap">
      <input
        ref="folderInput"
        type="file"
        webkitdirectory
        directory
        multiple
        style="display: none"
        @change="handleFolderFiles"
      />
      <input
        ref="csvInput"
        type="file"
        accept=".csv"
        style="display: none"
        @change="handleCsvFile"
      />

      <div
        class="fin-dropzone"
        role="button"
        tabindex="0"
        @click="triggerFolderSelect"
        @dragover.prevent
        @drop="onDrop"
        @keydown.enter.prevent="triggerFolderSelect"
        @keydown.space.prevent="triggerFolderSelect"
      >
        <div class="fin-dropzone-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
          </svg>
        </div>
        <h4>Seleccionar la carpeta de PedidosYa</h4>
        <p>Haz clic o arrastra la carpeta descomprimida (ej. <code>payoutArchive_PY_BO_...</code>).</p>
        <span class="fin-hint">El navegador pedirá permiso para leer los archivos de la carpeta.</span>
      </div>

      <div class="fin-csv-subpanel">
        <div class="fin-csv-info">
          <strong>Archivo CSV de pedidos (opcional):</strong>
          <span v-if="csvFile" class="fin-badge ok">✓ {{ csvFile.name }}</span>
          <span v-else class="fin-muted">No seleccionado (si está fuera de la carpeta, puedes elegirlo abajo).</span>
        </div>
        <button v-if="!csvFile" class="fin-btn fin-btn-secondary fin-btn-sm" type="button" @click="triggerCsvSelect">
          Elegir orderDetails.csv
        </button>
      </div>

      <div v-if="globalError" class="fin-error" style="margin-top: 12px">
        {{ globalError }}
      </div>
    </div>

    <!-- PASO 2: Lectura de archivos en progreso -->
    <div v-else-if="reading" class="fin-folder-reading">
      <div class="fin-loading">
        Analizando archivos y liquidaciones (semana {{ readingCurrent }} de {{ readingTotal }})…
      </div>
      <div class="fin-progress-bar-wrap">
        <div class="fin-progress-bar" :style="{ width: `${(readingCurrent / readingTotal) * 100}%` }"></div>
      </div>
    </div>

    <!-- PASO 3: Previsualización de semanas encontradas («Lo que encontré») -->
    <div v-else-if="step === 'preview'" class="fin-folder-preview">
      <div class="fin-summary-bar">
        <div>
          <strong>{{ weeks.length }} semanas encontradas</strong>
          <span class="fin-muted"> · {{ selectedWeeks.length }} seleccionadas para importar</span>
          <span v-if="csvFile" class="fin-muted"> · Con CSV: <code>{{ csvFile.name }}</code></span>
        </div>
        <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="step = 'select'">
          Cambiar carpeta
        </button>
      </div>

      <!-- Tabla de semanas encontradas -->
      <div class="fin-table-wrap">
        <table class="fin-table stack">
          <thead>
            <tr>
              <th style="width: 40px; text-align: center">
                <input
                  type="checkbox"
                  :checked="selectedWeeks.length === weeks.length && weeks.length > 0"
                  aria-label="Seleccionar todas"
                  @change="weeks.forEach(w => w.selected = $event.target.checked)"
                />
              </th>
              <th>Semana</th>
              <th>Archivos</th>
              <th class="right">Liquidado</th>
              <th>Ventas POS</th>
              <th>Estado y Controles</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="w in weeks" :key="w.id" :class="{ 'row-dimmed': !w.selected }">
              <td style="text-align: center">
                <input
                  v-model="w.selected"
                  type="checkbox"
                  :aria-label="`Seleccionar semana ${w.id}`"
                  :disabled="w.preview.status === 'overlaps' || (w.preview.blockers && w.preview.blockers.length > 0)"
                />
              </td>
              <td data-label="Semana">
                <div v-if="w.preview.start_date" class="fin-cell-main fin-num">
                  {{ formatDay(w.preview.start_date, { year: false }) }} – {{ formatDay(w.preview.end_date) }}
                </div>
                <div v-else class="fin-cell-main fin-num">ID {{ w.id }}</div>
                <div class="fin-cell-sub">
                  Liquidación Nº {{ w.id }}
                  <template v-if="w.preview.payout_date"> · Pago {{ formatDay(w.preview.payout_date) }}</template>
                </div>
              </td>
              <td data-label="Archivos">
                <div class="fin-file-tags">
                  <span class="fin-tag-file" title="Estado de cuenta en PDF">PDF</span>
                  <span v-if="w.orders_xls" class="fin-tag-file" title="Excel de pedidos">XLS</span>
                  <span v-if="w.invoice_pdf" class="fin-tag-file" title="Factura fiscal">Factura</span>
                  <span v-if="w.preview.orders_source === 'csv'" class="fin-tag-file tag-csv" title="CSV vinculado">CSV</span>
                </div>
              </td>
              <td data-label="Liquidado" class="right fin-num">
                <span v-if="w.preview.statement" class="fin-cell-main">
                  {{ formatBs(w.preview.statement.total_payout) }}
                </span>
                <span v-else class="fin-muted">—</span>
              </td>
              <td data-label="Ventas POS">
                <template v-if="w.preview.sales">
                  <div class="fin-small">
                    <strong>{{ w.preview.sales.summary.create }}</strong> nuevas ·
                    <strong>{{ w.preview.sales.summary.link }}</strong> a vincular
                  </div>
                  <div class="fin-cell-sub">
                    {{ w.preview.orders_count }} pedidos entregados
                  </div>
                </template>
                <template v-else-if="w.orders_xls">
                  <span class="fin-muted fin-small">{{ w.preview.orders_count }} pedidos (sin ventas)</span>
                </template>
                <template v-else>
                  <span class="fin-muted fin-small">Solo montos</span>
                </template>
              </td>
              <td data-label="Estado y Controles">
                <div style="display: flex; flex-direction: column; gap: 4px; align-items: flex-start">
                  <span class="fin-badge" :class="statusBadge(w.preview.status).class">
                    {{ statusBadge(w.preview.status).label }}
                  </span>
                  <span v-if="w.preview.blockers?.length" class="fin-cell-sub" style="color: var(--danger-text)">
                    ❌ {{ w.preview.blockers[0] }}
                  </span>
                  <span v-else-if="w.preview.warnings?.length" class="fin-cell-sub" style="color: var(--gold-text)">
                    ⚠️ {{ w.preview.warnings[0] }}
                  </span>
                  <span v-else-if="w.preview.controls?.balance_ok" class="fin-cell-sub" style="color: var(--lime-700)">
                    ✓ Todo cuadra
                  </span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Sección de artículos sin equivalencia si existen -->
      <div v-if="allUnresolved.totalCount > 0 && createSales" class="fin-card" style="margin-top: 16px; border: 1px solid var(--gold-300)">
        <div class="fin-card-head" style="background: var(--gold-50)">
          <div>
            <h4 style="margin: 0; color: var(--gold-text)">
              Artículos de PedidosYa sin equivalencia en el POS ({{ allUnresolved.totalCount }})
            </h4>
            <p class="fin-small" style="margin: 4px 0 0">
              Se encontraron artículos en los pedidos del CSV que aún no tienen equivalencia en el POS.
              Asígnalos una sola vez para todas las semanas.
            </p>
          </div>
        </div>
        <div class="fin-card-body" style="padding: 12px">
          <div v-if="mappingError" class="fin-error" style="margin-bottom: 8px">{{ mappingError }}</div>

          <div v-for="p in allUnresolved.products" :key="p.name" class="fin-mapping-row">
            <div class="fin-mapping-label">
              <strong>{{ p.name }}</strong>
              <span class="fin-muted fin-small"> ({{ p.orders }} pedidos)</span>
            </div>
            <select v-model="choice.products[p.name]" class="fin-select">
              <option value="">Elegir producto del menú…</option>
              <option v-for="s in p.suggestions" :key="s.id" :value="s.id">
                {{ s.name }} · Bs {{ s.price }}
              </option>
            </select>
          </div>

          <div v-for="o in allUnresolved.options" :key="o.name" class="fin-mapping-row">
            <div class="fin-mapping-label">
              <strong>{{ o.name }}</strong> (opción)
              <span class="fin-muted fin-small"> ({{ o.orders }} pedidos)</span>
            </div>
            <select v-model="choice.options[o.name]" class="fin-select">
              <option value="">Elegir opción del menú…</option>
              <option v-for="c in o.choices" :key="c.id" :value="c.id">
                {{ c.group_name }} → {{ c.name }}
              </option>
            </select>
          </div>

          <div style="margin-top: 10px; display: flex; justify-content: flex-end">
            <button
              class="fin-btn fin-btn-primary fin-btn-sm"
              type="button"
              :disabled="savingMappings || !mappingsReady"
              @click="saveMappings"
            >
              {{ savingMappings ? 'Guardando equivalencias…' : 'Guardar equivalencias' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Opciones y archivos ignorados -->
      <div class="fin-row" style="margin-top: 14px; align-items: center">
        <label v-if="csvFile" class="fin-checkbox-label">
          <input v-model="createSales" type="checkbox" />
          <span>Crear o vincular las ventas en el POS para las semanas con CSV</span>
        </label>
        <span class="fin-spacer"></span>
        <span v-if="ignoredFiles.length" class="fin-muted fin-small">
          {{ ignoredFiles.length }} archivo(s) no relevantes ignorados.
        </span>
      </div>
    </div>

    <!-- PASO 4: Importación en progreso -->
    <div v-else-if="step === 'importing'" class="fin-folder-reading">
      <div class="fin-loading">
        Importando semana {{ importIndex }} de {{ selectedWeeks.length }}…
      </div>
      <div class="fin-progress-bar-wrap">
        <div class="fin-progress-bar" :style="{ width: `${(importIndex / selectedWeeks.length) * 100}%` }"></div>
      </div>
    </div>

    <!-- PASO 5: Resumen final -->
    <div v-else-if="step === 'done'" class="fin-folder-done">
      <div class="fin-summary-head">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="icon-ok">
          <path d="M20 6L9 17l-5-5" />
        </svg>
        <h3>Proceso completado</h3>
        <p>Resultados de la importación por lote:</p>
      </div>

      <div class="fin-results-list">
        <div v-for="r in importResults" :key="r.id" class="fin-result-item" :class="r.status">
          <div class="fin-result-main">
            <strong>Semana {{ r.dates }} (Liquidación {{ r.id }})</strong>
            <span class="fin-small">{{ r.message }}</span>
          </div>
          <span class="fin-badge" :class="r.status === 'ok' ? 'ok' : r.status === 'error' ? 'err' : 'muted'">
            {{ r.status === 'ok' ? 'Completada' : r.status === 'error' ? 'Error' : 'Saltada' }}
          </span>
        </div>
      </div>
    </div>

    <template #footer>
      <template v-if="step === 'preview'">
        <button class="fin-btn fin-btn-secondary" type="button" @click="emit('close')">
          Cancelar
        </button>
        <button
          class="fin-btn fin-btn-primary"
          type="button"
          :disabled="!canImport"
          @click="runBatchImport"
        >
          Importar {{ selectedWeeks.length }} semanas
        </button>
      </template>

      <template v-else-if="step === 'done'">
        <button class="fin-btn fin-btn-primary" type="button" @click="finish">
          Cerrar y ver liquidaciones
        </button>
      </template>
    </template>
  </FinanceModal>
</template>

<style scoped>
.fin-folder-select-wrap {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 12px 0;
}

.fin-dropzone {
  border: 2px dashed var(--border);
  border-radius: var(--radius-md, 8px);
  padding: 36px 20px;
  text-align: center;
  background: var(--surface);
  cursor: pointer;
  transition: all 0.2s ease;
}

.fin-dropzone:hover,
.fin-dropzone:focus {
  border-color: var(--acai-500);
  background: var(--acai-50);
  outline: none;
}

html.dark .fin-dropzone:hover,
html.dark .fin-dropzone:focus {
  background: rgba(116, 72, 166, 0.15);
}

.fin-dropzone-icon {
  width: 48px;
  height: 48px;
  margin: 0 auto 12px;
  color: var(--acai-text);
}

.fin-dropzone-icon svg {
  width: 100%;
  height: 100%;
}

.fin-csv-subpanel {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm, 6px);
  gap: 10px;
}

.fin-csv-info {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
}

.fin-folder-reading {
  padding: 40px 20px;
  text-align: center;
}

.fin-progress-bar-wrap {
  width: 100%;
  height: 8px;
  background: var(--border);
  border-radius: 999px;
  overflow: hidden;
  margin-top: 16px;
}

.fin-progress-bar {
  height: 100%;
  background: var(--acai-500);
  transition: width 0.3s ease;
}

.fin-summary-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 12px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm, 6px);
  margin-bottom: 12px;
}

.fin-file-tags {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
}

.fin-tag-file {
  font-size: 11px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 4px;
  background: var(--cream-200);
  color: var(--ink-700);
  border: 1px solid var(--border);
}

html.dark .fin-tag-file {
  background: rgba(255, 255, 255, 0.08);
  color: var(--ink-300);
}

.fin-tag-file.tag-csv {
  background: var(--lime-100);
  color: var(--lime-700);
  border-color: rgba(34, 197, 94, 0.3);
}

.row-dimmed {
  opacity: 0.55;
}

.fin-mapping-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 0;
  border-bottom: 1px solid var(--border);
  gap: 12px;
}

.fin-mapping-row:last-of-type {
  border-bottom: none;
}

.fin-mapping-label {
  font-size: 13px;
}

.fin-checkbox-label {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  cursor: pointer;
  color: var(--ink-700);
}

.fin-folder-done {
  padding: 16px 0;
}

.fin-summary-head {
  text-align: center;
  margin-bottom: 20px;
}

.icon-ok {
  width: 44px;
  height: 44px;
  color: var(--lime-700);
  margin-bottom: 8px;
}

.fin-results-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-height: 280px;
  overflow-y: auto;
}

.fin-result-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 14px;
  border-radius: var(--radius-sm, 6px);
  border: 1px solid var(--border);
  background: var(--surface);
}

.fin-result-item.ok {
  border-left: 4px solid var(--lime-500);
}

.fin-result-item.error {
  border-left: 4px solid var(--danger-500);
}

.fin-result-item.skipped {
  border-left: 4px solid var(--gold-500);
}

.fin-result-main {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
</style>
