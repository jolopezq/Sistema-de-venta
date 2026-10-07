<script setup>
import { computed, reactive, ref } from 'vue';
import { apiFetch } from '../../services/api';
import { formatBs, formatDay, errorText } from '../../stores/finance';
import FinanceModal from './FinanceModal.vue';
import PedidosYaSalesPanel from './PedidosYaSalesPanel.vue';

/**
 * Carga de una semana de PedidosYa: 1) el CSV de pedidos (se lee sin guardar para tomar la semana y los
 * totales) y 2) los 8 montos del estado de cuenta, con la conciliación calculada mientras se escriben.
 */
const emit = defineEmits(['close', 'imported']);

const csvFile = ref(null);
const zipFile = ref(null);
const preview = ref(null);
const previewing = ref(false);
const saving = ref(false);
const error = ref('');
const createSales = ref(true);
const links = ref({});

const form = reactive({
  start_date: '', end_date: '', payout_date: '',
  net_sales: '', delivery_fees: '', service_fees: '', operating_charges: '', advertising: '', refunds: '', taxes: '', total_payout: '',
  notes: '',
});

const AMOUNTS = [
  { key: 'net_sales', label: '1. Ventas netas', required: true },
  { key: 'delivery_fees', label: '2. Envíos a cargo del local' },
  { key: 'service_fees', label: '3. Servicios PedidosYa (comisión)', required: true },
  { key: 'operating_charges', label: '4. Cargos operativos' },
  { key: 'advertising', label: '5. Publicidad en la app' },
  { key: 'refunds', label: '6. Reintegros' },
  { key: 'taxes', label: '7. Impuestos (IVA en comisiones)' },
  { key: 'total_payout', label: '8. Total liquidado', required: true },
];

async function runPreview() {
  if (!csvFile.value) return;
  previewing.value = true;
  error.value = '';
  try {
    const data = new FormData();
    data.append('csv_file', csvFile.value);
    if (form.start_date && form.end_date) {
      data.append('start_date', form.start_date);
      data.append('end_date', form.end_date);
    }
    preview.value = await apiFetch('/finance/pedidosya-settlements/preview', { method: 'POST', body: data });
    form.start_date = preview.value.start_date;
    form.end_date = preview.value.end_date;
  } catch (err) {
    preview.value = null;
    error.value = errorText(err, 'No se pudo leer el archivo.');
  } finally {
    previewing.value = false;
  }
}

function onCsv(e) {
  csvFile.value = e.target.files?.[0] || null;
  form.start_date = '';
  form.end_date = '';
  preview.value = null;
  links.value = {};
  runPreview();
}

const unresolvedNames = computed(() => preview.value?.sales?.summary?.unresolved_names || 0);
function onZip(e) {
  zipFile.value = e.target.files?.[0] || null;
}

const num = (v) => Number(String(v ?? '').replace(',', '.')) || 0;

const calculatedPayout = computed(() => num(form.net_sales) - num(form.delivery_fees) - num(form.service_fees)
  - num(form.operating_charges) - num(form.advertising) + num(form.refunds) - num(form.taxes));

const checks = computed(() => {
  if (!preview.value) return [];
  const round = (n) => Math.round(n * 100) / 100;
  const list = [
    {
      label: 'Total liquidado según la fórmula',
      expected: calculatedPayout.value,
      actual: num(form.total_payout),
      ready: form.total_payout !== '' && form.net_sales !== '',
    },
    {
      label: 'Comisión del CSV = servicios PedidosYa',
      expected: preview.value.csv_commission,
      actual: num(form.service_fees),
      ready: form.service_fees !== '',
    },
    {
      label: 'Ventas brutas − descuentos PedidosYa = ventas netas',
      expected: preview.value.csv_gross_sales - preview.value.csv_pedidosya_discount,
      actual: num(form.net_sales),
      ready: form.net_sales !== '',
      informative: true,
    },
  ];
  return list.map((c) => {
    const difference = round(c.actual - c.expected);
    return { ...c, difference, ok: Math.abs(difference) <= 1 };
  });
});

const blocking = computed(() => preview.value?.overlapping?.length > 0);
const canSave = computed(() => preview.value && !blocking.value && !saving.value
  && form.net_sales !== '' && form.service_fees !== '' && form.total_payout !== ''
  && !(createSales.value && unresolvedNames.value > 0));

async function save() {
  if (!canSave.value) {
    error.value = createSales.value && unresolvedNames.value
      ? 'Guarda las equivalencias de los artículos o desmarca «Crear o vincular las ventas».'
      : 'Completa las ventas netas, los servicios y el total liquidado.';
    return;
  }
  saving.value = true;
  error.value = '';
  try {
    const data = new FormData();
    data.append('csv_file', csvFile.value);
    if (zipFile.value) data.append('zip_file', zipFile.value);
    data.append('start_date', form.start_date);
    data.append('end_date', form.end_date);
    if (form.payout_date) data.append('payout_date', form.payout_date);
    for (const { key } of AMOUNTS) data.append(key, String(num(form[key])));
    if (form.notes) data.append('notes', form.notes);
    data.append('sales_mode', createSales.value ? 'create' : 'skip');
    if (createSales.value) {
      for (const [order, value] of Object.entries(links.value)) data.append(`links[${order}]`, value);
    }
    const res = await apiFetch('/finance/pedidosya-settlements', { method: 'POST', body: data });
    emit('imported', res);
  } catch (err) {
    error.value = errorText(err, 'No se pudo importar la semana.');
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <FinanceModal
    title="Cargar semana de PedidosYa"
    subtitle="Archivo CSV de pedidos del portal + montos del estado de cuenta (PDF del ZIP semanal)."
    wide
    :busy="saving"
    @close="emit('close')"
  >
    <!-- 1. CSV -->
    <div class="fin-field">
      <label for="pya-csv">1. Archivo CSV de pedidos</label>
      <input id="pya-csv" type="file" accept=".csv,text/csv" class="fin-input" @change="onCsv" />
    </div>
    <div v-if="previewing" class="fin-hint">Leyendo el archivo…</div>

    <template v-if="preview">
      <div class="fin-grid-2">
        <div class="fin-field">
          <label for="pya-start">Semana desde</label>
          <input id="pya-start" v-model="form.start_date" type="date" class="fin-input" @change="runPreview" />
        </div>
        <div class="fin-field">
          <label for="pya-end">hasta</label>
          <input id="pya-end" v-model="form.end_date" type="date" class="fin-input" @change="runPreview" />
        </div>
      </div>
      <p class="fin-hint">
        Pedidos del {{ formatDay(preview.first_order_date) }} al {{ formatDay(preview.last_order_date) }}:
        {{ preview.orders_count }} pedidos, {{ preview.delivered_count }} entregados y {{ preview.cancelled_count }} cancelados ·
        ventas brutas {{ formatBs(preview.csv_gross_sales) }} · comisión {{ formatBs(preview.csv_commission) }} ·
        descuentos PedidosYa {{ formatBs(preview.csv_pedidosya_discount) }}.
      </p>
      <div v-if="blocking" class="fin-note err">
        Se cruza con {{ preview.overlapping.join(', ') }}. Elimina esa semana o corrige las fechas.
      </div>
      <div v-else-if="preview.replaces_settlement_id" class="fin-note info">
        Esta semana ya está cargada: al guardar se reemplazan sus montos y pedidos.
      </div>
      <div v-if="preview.orders_outside_range?.length" class="fin-note">
        {{ preview.orders_outside_range.length }} pedido(s) con fecha fuera de la semana: {{ preview.orders_outside_range.slice(0, 5).join(', ') }}.
      </div>

      <!-- 2. Estado de cuenta -->
      <div class="fin-label" style="margin-top: 4px">2. Montos del estado de cuenta</div>
      <div class="fin-grid-2">
        <div v-for="a in AMOUNTS" :key="a.key" class="fin-field">
          <label :for="`pya-${a.key}`">{{ a.label }}<template v-if="a.required"> *</template></label>
          <input
            :id="`pya-${a.key}`"
            v-model="form[a.key]"
            type="number"
            min="0"
            step="0.01"
            inputmode="decimal"
            class="fin-input fin-num"
            placeholder="0,00"
          />
        </div>
      </div>

      <div class="fin-checks" aria-live="polite">
        <div v-for="c in checks" :key="c.label" class="fin-check">
          <span class="fin-check-label">
            {{ c.label }}
            <span v-if="c.ready" class="fin-muted fin-small"> · esperado {{ formatBs(c.expected) }}</span>
          </span>
          <span v-if="!c.ready" class="fin-badge">Falta el monto</span>
          <span v-else-if="c.ok" class="fin-badge ok">Cuadra</span>
          <span v-else class="fin-badge" :class="c.informative ? 'info' : 'err'">
            {{ c.informative ? 'Aviso' : 'Diferencia' }} {{ formatBs(c.difference, { sign: true }) }}
          </span>
        </div>
      </div>

      <div class="fin-grid-2">
        <div class="fin-field">
          <label for="pya-payout">Fecha de pago (banco)</label>
          <input id="pya-payout" v-model="form.payout_date" type="date" class="fin-input" />
        </div>
        <div class="fin-field">
          <label for="pya-zip">ZIP de la liquidación (opcional)</label>
          <input id="pya-zip" type="file" accept=".zip,application/zip" class="fin-input" @change="onZip" />
        </div>
      </div>
      <div class="fin-field">
        <label for="pya-notes">Nota (opcional)</label>
        <input id="pya-notes" v-model="form.notes" type="text" maxlength="1000" class="fin-input" />
      </div>

      <!-- 3. Ventas en el POS -->
      <div class="fin-row" style="margin-top: 4px">
        <div class="fin-label">3. Ventas en el POS</div>
        <span class="fin-spacer"></span>
        <label class="fin-check-inline">
          <input v-model="createSales" type="checkbox" />
          Crear o vincular las ventas
        </label>
      </div>
      <p class="fin-hint">
        Cada pedido entregado queda como venta PedidosYa con su fecha, hora, artículos y monto (descuenta inventario y no
        entra al arqueo). Si ya se registró a mano en el POS, se vincula en lugar de duplicarse.
      </p>
      <PedidosYaSalesPanel
        v-if="createSales && preview.sales"
        v-model:links="links"
        :analysis="preview.sales"
        @mappings-saved="runPreview"
      />
    </template>

    <div v-if="error" class="fin-note err" role="alert" style="white-space: pre-line">{{ error }}</div>

    <template #footer>
      <button class="fin-btn fin-btn-secondary" type="button" :disabled="saving" @click="emit('close')">Cancelar</button>
      <button class="fin-btn fin-btn-primary" type="button" :disabled="!canSave" @click="save">
        {{ saving ? 'Importando…' : 'Importar semana' }}
      </button>
    </template>
  </FinanceModal>
</template>
