<script setup>
import { computed, ref } from 'vue';
import { apiFetch } from '../../services/api';
import { useFinanceStore, formatBs, formatPercent, errorText, UNIT_DISPLAY } from '../../stores/finance';
import RecipeEditor from './RecipeEditor.vue';
import BaseRecipesWizard from './BaseRecipesWizard.vue';

/**
 * Pestaña Costos y margen: cuánto cuesta cada producto según su receta y cuánto deja en el local y en PedidosYa
 * (docs/PLAN_COSTOS_PUNTO_EQUILIBRIO.md). Los componentes de costo solo calculan, no mueven stock.
 */
const finance = useFinanceStore();
const data = computed(() => finance.costs);

const withRecipe = computed(() => (data.value?.products || []).filter((p) => p.has_recipe));
const withoutRecipe = computed(() => (data.value?.products || []).filter((p) => !p.has_recipe));
const ingredientsById = computed(() => Object.fromEntries((data.value?.ingredients || []).map((i) => [i.id, i])));
const optionGroups = computed(() => (data.value?.option_groups || []).filter((g) => g.options.length));
const platformRate = computed(() => data.value?.platform_rate);

const expanded = ref(new Set());
function toggle(id) {
  const next = new Set(expanded.value);
  next.has(id) ? next.delete(id) : next.add(id);
  expanded.value = next;
}

function marginClass(value) {
  if (value === null || value === undefined) return '';
  return value < 0 ? 'neg' : '';
}
function pct(value) {
  return value === null || value === undefined ? '—' : formatPercent(value);
}

// ---------- Editor ----------
const editing = ref(null);
function editProduct(p) {
  editing.value = { kind: 'product', id: p.id, name: p.name, weightBased: p.is_weight_based, recipe: p.recipe };
}
function editOption(o) {
  editing.value = { kind: 'option', id: o.id, name: o.name, weightBased: false, recipe: o.recipe };
}
async function onSaved() {
  editing.value = null;
  await Promise.all([finance.loadCosts(), finance.loadBreakEven()]);
}

const wizardOpen = ref(false);
async function onApplied() {
  wizardOpen.value = false;
  await Promise.all([finance.loadCosts(), finance.loadBreakEven()]);
}

// ---------- Costo de componentes (edición en la tabla) ----------
const savingComponent = ref(null);
async function saveCost(component, value) {
  const cost = Number(value);
  if (Number.isNaN(cost) || cost < 0 || cost === component.unit_cost) return;
  savingComponent.value = component.id;
  try {
    const res = await apiFetch(`/finance/costs/components/${component.id}`, { method: 'PUT', body: JSON.stringify({ unit_cost: cost }) });
    finance.notify(res.message);
    await Promise.all([finance.loadCosts(), finance.loadBreakEven()]);
  } catch (err) {
    finance.notify(errorText(err), 'error');
  } finally {
    savingComponent.value = null;
  }
}

function recipeText(recipe) {
  return recipe.map((r) => {
    const i = ingredientsById.value[r.ingredient_id];
    const d = UNIT_DISPLAY[i?.unit] || UNIT_DISPLAY.unidades;
    const amount = Math.round(r.quantity * d.factor * 1000) / 1000;
    return `${amount.toLocaleString('es-BO')} ${d.short} ${i?.name || '?'}`;
  }).join(' · ');
}
</script>

<template>
  <div class="fin-card">
    <div class="fin-card-head">
      <div>
        <h2>Costos y margen</h2>
        <p>
          Lo que cuesta cada producto según su receta y lo que deja.
          <template v-if="platformRate !== null && platformRate !== undefined">El margen PedidosYa descuenta el {{ formatPercent(platformRate * 100) }} que se queda la plataforma (últimos 90 días).</template>
          <template v-else>El margen PedidosYa aún no descuenta la comisión (no hay semanas importadas).</template>
        </p>
      </div>
      <button class="fin-btn fin-btn-primary" type="button" @click="wizardOpen = true">Cargar recetas base</button>
    </div>
    <div v-if="finance.errors.costs" class="fin-error">{{ finance.errors.costs }}</div>
    <div v-else-if="!data" class="fin-loading">Cargando costos…</div>
    <div v-else-if="!withRecipe.length" class="fin-empty">
      Todavía no hay recetas. Usa «Cargar recetas base» para crear las de los bowls, el Autoservicio y el café en un paso.
    </div>
    <div v-else class="fin-table-wrap">
      <table class="fin-table stack">
        <thead>
          <tr>
            <th>Producto</th>
            <th class="right">Costo</th>
            <th class="right">Precio local</th>
            <th class="right">Margen local</th>
            <th class="right">Precio PedidosYa</th>
            <th class="right">Margen PedidosYa</th>
            <th class="actions"><span class="sr-only">Acciones</span></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="p in withRecipe" :key="p.id">
            <tr>
              <td data-label="Producto">
                <button v-if="p.variants.length" class="fin-toggle" :class="{ open: expanded.has(p.id) }" type="button" :aria-expanded="expanded.has(p.id)" @click="toggle(p.id)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m9 6 6 6-6 6" /></svg>
                  <span class="fin-cell-main">{{ p.name }}</span>
                </button>
                <span v-else class="fin-cell-main">{{ p.name }}</span>
                <span v-if="!p.complete" class="fin-badge warn" style="margin-left: 6px">Incompleta</span>
                <div class="fin-cell-sub">
                  <template v-if="p.is_weight_based">Por peso · costo y precio a {{ data.reference_grams }} g</template>
                  <template v-else-if="!p.complete">Falta el costo de: {{ p.missing.join(', ') }}</template>
                  <template v-else>{{ recipeText(p.recipe) }}</template>
                </div>
              </td>
              <td data-label="Costo" class="right fin-num fin-cell-main">{{ p.complete ? formatBs(p.cost) : '—' }}</td>
              <td data-label="Precio local" class="right fin-num">{{ formatBs(p.price_local) }}</td>
              <td data-label="Margen local" class="right fin-num" :class="marginClass(p.margin_local)">{{ p.complete ? pct(p.margin_local) : '—' }}</td>
              <td data-label="Precio PedidosYa" class="right fin-num">{{ p.price_pya !== null ? formatBs(p.price_pya) : '—' }}</td>
              <td data-label="Margen PedidosYa" class="right fin-num" :class="marginClass(p.margin_pya)">{{ p.complete && p.price_pya !== null ? pct(p.margin_pya) : '—' }}</td>
              <td class="actions">
                <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="editProduct(p)">Receta</button>
              </td>
            </tr>
            <template v-if="expanded.has(p.id)">
              <tr v-for="v in p.variants" :key="`${p.id}-${v.option_id}`" class="fin-variant">
                <td data-label="Opción"><span class="fin-variant-name">{{ v.group }}: {{ v.name }}</span></td>
                <td data-label="Costo" class="right fin-num">{{ v.complete ? formatBs(v.cost) : '—' }}</td>
                <td data-label="Precio local" class="right fin-num">{{ formatBs(v.price_local) }}</td>
                <td data-label="Margen local" class="right fin-num" :class="marginClass(v.margin_local)">{{ v.complete ? pct(v.margin_local) : '—' }}</td>
                <td data-label="Precio PedidosYa" class="right fin-num">{{ v.price_pya !== null ? formatBs(v.price_pya) : '—' }}</td>
                <td data-label="Margen PedidosYa" class="right fin-num" :class="marginClass(v.margin_pya)">{{ v.complete && v.price_pya !== null ? pct(v.margin_pya) : '—' }}</td>
                <td></td>
              </tr>
            </template>
          </template>
        </tbody>
      </table>
    </div>
  </div>

  <div v-if="withoutRecipe.length" class="fin-card">
    <div class="fin-card-head">
      <div>
        <h3>Productos sin receta</h3>
        <p>No entran en el cálculo del margen hasta tener su receta.</p>
      </div>
    </div>
    <div class="fin-card-body fin-chips">
      <button v-for="p in withoutRecipe" :key="p.id" class="fin-chip" type="button" @click="editProduct(p)">+ {{ p.name }}</button>
    </div>
  </div>

  <div v-if="optionGroups.length" class="fin-card">
    <div class="fin-card-head">
      <div>
        <h3>Ajustes por opción</h3>
        <p>Lo que suma o resta cada opción al costo: tamaños, frutas, «Sin…» y toppings extra.</p>
      </div>
    </div>
    <div class="fin-table-wrap">
      <table class="fin-table stack">
        <thead>
          <tr><th>Opción</th><th>Grupo</th><th class="right">Precio</th><th class="right">Ajuste de costo</th><th class="actions"><span class="sr-only">Acciones</span></th></tr>
        </thead>
        <tbody>
          <template v-for="g in optionGroups" :key="g.id">
            <tr v-for="o in g.options" :key="o.id">
              <td data-label="Opción">
                <span class="fin-cell-main">{{ o.name }}</span>
                <span v-if="o.recipe.length && !o.complete" class="fin-badge warn" style="margin-left: 6px">Incompleto</span>
                <div v-if="o.recipe.length" class="fin-cell-sub">{{ recipeText(o.recipe) }}</div>
              </td>
              <td data-label="Grupo">{{ g.name }}</td>
              <td data-label="Precio" class="right fin-num">{{ formatBs(o.price, { sign: true }) }}</td>
              <td data-label="Ajuste" class="right fin-num">
                <template v-if="o.recipe.length">{{ formatBs(o.cost, { sign: true }) }}</template>
                <span v-else class="fin-muted">Sin ajuste</span>
              </td>
              <td class="actions">
                <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" @click="editOption(o)">Ajuste</button>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>

  <div v-if="data?.components?.length" class="fin-card">
    <div class="fin-card-head">
      <div>
        <h3>Componentes de costo</h3>
        <p>Cambia un costo y se recalculan todas las recetas que lo usan. Los «solo costeo» no mueven el stock de Inventario.</p>
      </div>
    </div>
    <div class="fin-table-wrap">
      <table class="fin-table stack">
        <thead>
          <tr><th>Componente</th><th class="right">Usado en</th><th class="right">Costo (Bs)</th></tr>
        </thead>
        <tbody>
          <tr v-for="c in data.components" :key="c.id">
            <td data-label="Componente">
              <span class="fin-cell-main">{{ c.name }}</span>
              <span v-if="!c.tracks_stock" class="fin-badge" style="margin-left: 6px">Solo costeo</span>
              <span v-if="c.cost <= 0" class="fin-badge warn" style="margin-left: 4px">Sin costo</span>
              <div v-if="c.from_purchases" class="fin-cell-sub">Costo promedio de las compras registradas en Inventario</div>
            </td>
            <td data-label="Usado en" class="right fin-num">{{ c.used_in }} {{ c.used_in === 1 ? 'receta' : 'recetas' }}</td>
            <td data-label="Costo" class="right">
              <span class="fin-cost-input">
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  class="fin-input fin-input-sm fin-num"
                  :value="c.from_purchases ? c.cost : c.unit_cost"
                  :disabled="c.from_purchases || savingComponent === c.id"
                  :aria-label="`Costo de ${c.name}`"
                  @change="saveCost(c, $event.target.value)"
                />
                <span class="fin-small fin-muted">{{ UNIT_DISPLAY[c.unit]?.costLabel }}</span>
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <RecipeEditor v-if="editing" :target="editing" @close="editing = null" @saved="onSaved" />
  <BaseRecipesWizard v-if="wizardOpen" @close="wizardOpen = false" @applied="onApplied" />
</template>

<style scoped>
.fin-variant td { background: var(--cream-50); font-size: 12.5px; color: var(--ink-700); }
.fin-variant-name { padding-left: 22px; display: inline-block; }
.fin-cost-input { display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end; }
.fin-cost-input input { width: 96px; text-align: right; }
@media (max-width: 760px) {
  .fin-variant-name { padding-left: 0; }
  .fin-cost-input { justify-content: flex-start; }
}
</style>
