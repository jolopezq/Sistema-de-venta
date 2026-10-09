<script setup>
import { computed, onMounted, ref } from 'vue';
import { apiFetch } from '../../services/api';
import { MONTHS, formatBs, errorText } from '../../stores/finance';
import { formatQty, formatSigned, formatDateTime } from '../../utils/inventoryLabels';
import FinanceModal from '../Finance/FinanceModal.vue';
import '../Finance/finance.css';

/**
 * Mermas del mes (plan de inventario, F3): lo que se anotó, por motivo, insumo y persona, y lo que el conteo
 * físico encontró de menos sin que nadie lo anotara (la «merma oculta»).
 */
const emit = defineEmits(['close', 'count']);

const now = new Date();
const year = ref(now.getFullYear());
const month = ref(now.getMonth() + 1);
const data = ref(null);
const loading = ref(true);
const error = ref('');

async function load() {
  loading.value = true;
  error.value = '';
  try {
    data.value = await apiFetch(`/inventory/waste/report?year=${year.value}&month=${month.value}`);
  } catch (err) {
    error.value = errorText(err, 'No se pudo cargar el reporte.');
  } finally {
    loading.value = false;
  }
}
onMounted(load);

function shift(delta) {
  const d = new Date(year.value, month.value - 1 + delta, 1);
  year.value = d.getFullYear();
  month.value = d.getMonth() + 1;
  load();
}

const label = computed(() => `${MONTHS[month.value - 1]} ${year.value}`);
const reg = computed(() => data.value?.registered);
const hid = computed(() => data.value?.hidden);
const maxCategory = computed(() => Math.max(1, ...(reg.value?.by_category || []).map((c) => c.cost)));
</script>

<template>
  <div class="fin-page wr-host">
    <FinanceModal wide title="Mermas del mes" subtitle="Lo que se anotó y lo que el conteo encontró de menos." @close="emit('close')">
      <div class="wr-period">
        <button class="fin-period-btn" type="button" aria-label="Mes anterior" @click="shift(-1)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m15 18-6-6 6-6" /></svg></button>
        <strong class="wr-month">{{ label }}</strong>
        <button class="fin-period-btn" type="button" aria-label="Mes siguiente" @click="shift(1)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m9 18 6-6-6-6" /></svg></button>
      </div>

      <div v-if="loading && !data" class="fin-loading">Cargando…</div>
      <template v-else-if="data">
        <div class="fin-kpis wr-kpis">
          <div class="fin-card fin-kpi">
            <span class="fin-kpi-label">Merma anotada</span>
            <span class="fin-kpi-value">{{ formatBs(reg.total_cost) }}</span>
            <span class="fin-kpi-sub">{{ reg.movements }} registros</span>
          </div>
          <div class="fin-card fin-kpi">
            <span class="fin-kpi-label">Merma oculta (conteos)</span>
            <span class="fin-kpi-value">{{ hid.lines ? formatBs(hid.net_loss) : '—' }}</span>
            <span class="fin-kpi-sub">{{ !data.counts.count ? 'sin conteos este mes' : !hid.lines ? 'aún sin comparar: son los primeros conteos' : hid.net_loss < 0 ? 'sobrante: se contó más de lo que decía el sistema' : `${data.counts.count} ${data.counts.count === 1 ? 'conteo' : 'conteos'}` }}</span>
          </div>
        </div>

        <div v-if="reg.unpriced" class="fin-note">{{ reg.unpriced }} merma(s) no tienen valor en Bs (el insumo no tenía costo cargado o se anotaron antes de que el sistema valorara las mermas): cuentan en cantidad pero no en Bs.</div>
        <div v-if="!data.counts.count" class="fin-note info">
          Sin conteos este mes no se puede medir la merma oculta (porciones más grandes, fruta que se pasa, faltantes sin explicar).
          <button class="fin-btn-link" type="button" @click="emit('count')">Hacer un conteo</button>
        </div>

        <section class="wr-section">
          <h3>Merma anotada por motivo</h3>
          <p v-if="!reg.by_category.length" class="fin-empty">No se anotó ninguna merma este mes.</p>
          <ul v-else class="wr-bars">
            <li v-for="c in reg.by_category" :key="c.category">
              <span class="wr-bar-label">{{ c.label }} <span class="fin-small">({{ c.movements }})</span></span>
              <span class="wr-bar"><span class="wr-bar-fill" :style="{ width: `${(c.cost / maxCategory) * 100}%` }"></span></span>
              <span class="fin-num wr-bar-value">{{ formatBs(c.cost) }}</span>
            </li>
          </ul>
        </section>

        <div class="wr-two">
          <section v-if="reg.by_ingredient.length" class="wr-section">
            <h3>Por insumo</h3>
            <div class="fin-table-wrap"><table class="fin-table">
              <tbody>
                <tr v-for="i in reg.by_ingredient.slice(0, 8)" :key="i.ingredient_id">
                  <td>{{ i.name }}</td><td class="right fin-num">{{ formatQty(i.quantity) }} {{ i.unit }}</td><td class="right fin-num">{{ formatBs(i.cost) }}</td>
                </tr>
              </tbody>
            </table></div>
          </section>
          <section v-if="reg.by_user.length" class="wr-section">
            <h3>Quién la anotó</h3>
            <div class="fin-table-wrap"><table class="fin-table">
              <tbody>
                <tr v-for="u in reg.by_user" :key="u.user_id ?? 'x'">
                  <td>{{ u.name }}</td><td class="right fin-small">{{ u.movements }} registros</td><td class="right fin-num">{{ formatBs(u.cost) }}</td>
                </tr>
              </tbody>
            </table></div>
          </section>
        </div>

        <section v-if="data.counts.count" class="wr-section">
          <h3>Lo que encontró el conteo</h3>
          <p class="fin-hint">
            Faltante {{ formatBs(hid.shortages) }} · sobrante {{ formatBs(hid.surpluses) }} · neto <strong>{{ formatBs(hid.net_loss) }}</strong>.
            El primer conteo de cada insumo ordena el stock de partida y no se suma<template v-if="hid.baseline_lines"> ({{ hid.baseline_lines }} este mes)</template>.
          </p>
          <p v-if="!hid.lines" class="fin-empty">Son los primeros conteos de esos insumos: ordenaron el stock de partida. La merma oculta se mide desde el segundo conteo de cada uno.</p>
          <div v-else-if="hid.by_ingredient.length" class="fin-table-wrap"><table class="fin-table">
            <tbody>
              <tr v-for="i in hid.by_ingredient" :key="i.ingredient_id">
                <td>{{ i.name }}</td>
                <td class="right fin-num" :class="i.difference < 0 ? 'wr-neg' : 'wr-pos'">{{ formatSigned(i.difference) }} {{ i.unit }}</td>
                <td class="right fin-num">{{ i.cost !== null ? formatBs(i.cost, { sign: true }) : '—' }}</td>
              </tr>
            </tbody>
          </table></div>
          <p v-else class="fin-empty">Los conteos coincidieron con el sistema.</p>
        </section>

        <section v-if="reg.recent.length" class="wr-section">
          <h3>Últimas mermas</h3>
          <div class="fin-table-wrap"><table class="fin-table">
            <thead><tr><th>Fecha</th><th>Insumo</th><th>Motivo</th><th class="right">Cantidad</th><th class="right">Valor</th><th>Quién</th></tr></thead>
            <tbody>
              <tr v-for="m in reg.recent.slice(0, 30)" :key="m.id">
                <td>{{ formatDateTime(m.created_at) }}</td>
                <td>{{ m.ingredient }}<div v-if="m.notes" class="fin-small">{{ m.notes }}</div></td>
                <td>{{ m.category }}</td>
                <td class="right fin-num">{{ formatQty(m.quantity) }} {{ m.unit }}</td>
                <td class="right fin-num">{{ m.cost !== null ? formatBs(m.cost) : '—' }}</td>
                <td>{{ m.user || '—' }}</td>
              </tr>
            </tbody>
          </table></div>
        </section>
      </template>

      <div v-if="error" class="fin-note err" role="alert">{{ error }}</div>

      <template #footer>
        <span class="fin-spacer"></span>
        <button class="fin-btn fin-btn-secondary" type="button" @click="emit('close')">Cerrar</button>
      </template>
    </FinanceModal>
  </div>
</template>

<style scoped>
.wr-host { display: contents; }
.wr-period { display: inline-flex; align-items: center; gap: 4px; background: var(--cream-200); border-radius: 12px; padding: 4px; align-self: flex-start; }
.wr-month { min-width: 130px; text-align: center; font-size: 14px; color: var(--ink-900); }
.wr-kpis { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin: 0; }
.wr-section h3 { margin: 0 0 6px; font-size: 13px; font-weight: 700; color: var(--ink-700); }
.wr-two { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
.wr-bars { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.wr-bars li { display: grid; grid-template-columns: minmax(110px, 1fr) minmax(60px, 2fr) auto; gap: 10px; align-items: center; font-size: 13px; color: var(--ink-900); }
.wr-bar { height: 10px; border-radius: 999px; background: var(--acai-50); border: 1px solid var(--border); overflow: hidden; }
.wr-bar-fill { display: block; height: 100%; background: var(--acai-500); border-radius: 999px; }
.wr-bar-value { min-width: 70px; text-align: right; }
.wr-neg { color: var(--danger-text); }
.wr-pos { color: var(--lime-700); }
@media (max-width: 520px) {
  .wr-bars li { grid-template-columns: 1fr auto; }
  .wr-bar { grid-column: 1 / -1; order: 3; }
}
</style>
