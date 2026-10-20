<script setup>
import { computed, ref, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useFinanceStore } from '../stores/finance';
import FinanceSummary from '../components/Finance/FinanceSummary.vue';
import FinanceExpenses from '../components/Finance/FinanceExpenses.vue';
import FinanceRecurring from '../components/Finance/FinanceRecurring.vue';
import FinanceCategories from '../components/Finance/FinanceCategories.vue';
import PedidosYaSettlements from '../components/Finance/PedidosYaSettlements.vue';
import FinanceCosts from '../components/Finance/FinanceCosts.vue';
import FinanceConfirm from '../components/Finance/FinanceConfirm.vue';
import '../components/Finance/finance.css';

/**
 * Módulo de Finanzas (solo dueño). La vista solo arma el encabezado, el selector de mes y las pestañas;
 * cada pestaña es un componente y los datos viven en el store `finance`.
 */
const route = useRoute();
const router = useRouter();
const finance = useFinanceStore();

const TABS = [
  { key: 'summary', label: 'Resumen' },
  { key: 'pedidosya', label: 'PedidosYa' },
  { key: 'expenses', label: 'Compras y gastos' },
  { key: 'recurring', label: 'Gastos fijos' },
  { key: 'costs', label: 'Costos y margen' },
  { key: 'categories', label: 'Categorías' },
];
const activeTab = ref(route.query?.tab && TABS.some(t => t.key === route.query.tab) ? route.query.tab : 'summary');
const intent = ref('');

watch(() => route.query?.tab, (newTab) => {
  if (newTab && TABS.some(t => t.key === newTab) && newTab !== activeTab.value) {
    activeTab.value = newTab;
  }
});

const tabCounter = computed(() => ({
  pedidosya: { value: finance.counts.pedidosya },
  expenses: finance.counts.unclassified
    ? { value: `${finance.counts.unclassified} sin clasificar`, alert: true }
    : { value: finance.counts.expenses },
  recurring: finance.counts.recurringPending
    ? { value: `${finance.counts.recurringPending} por generar`, alert: true }
    : { value: finance.templates.length },
  costs: finance.counts.incompleteRecipes
    ? { value: `${finance.counts.incompleteRecipes} incompletas`, alert: true }
    : null,
  categories: { value: finance.counts.categories },
}));

function navigate(tab, action = '') {
  activeTab.value = tab;
  intent.value = action;
  if (route.query?.tab !== tab) {
    router.replace({ query: { ...route.query, tab } }).catch(() => {});
  }
}

onMounted(() => finance.loadAll());
</script>

<template>
  <div class="fin-page">
    <div class="fin-nav">
    <header class="fin-head">
      <div>
        <h1>Finanzas</h1>
        <p>Estado de resultados, compras, gastos fijos y PedidosYa</p>
      </div>
      <div class="fin-period" role="group" aria-label="Mes del reporte">
        <button class="fin-period-btn" type="button" aria-label="Mes anterior" @click="finance.shiftMonth(-1)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="m15 18-6-6 6-6" /></svg>
        </button>
        <span class="fin-period-label" aria-live="polite">{{ finance.periodLabel }}</span>
        <button class="fin-period-btn" type="button" aria-label="Mes siguiente" @click="finance.shiftMonth(1)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="m9 18 6-6-6-6" /></svg>
        </button>
        <button
          v-if="!finance.isCurrentMonth"
          class="fin-period-today"
          type="button"
          @click="finance.setPeriod(new Date().getFullYear(), new Date().getMonth() + 1)"
        >
          Mes actual
        </button>
      </div>
    </header>

    <nav class="fin-tabs" role="tablist" aria-label="Secciones de Finanzas">
      <button
        v-for="t in TABS"
        :key="t.key"
        class="fin-tab"
        :class="{ active: activeTab === t.key }"
        role="tab"
        :aria-selected="activeTab === t.key"
        type="button"
        @click="navigate(t.key)"
      >
        {{ t.label }}
        <span v-if="tabCounter[t.key]" class="fin-count" :class="{ alert: tabCounter[t.key].alert }">{{ tabCounter[t.key].value }}</span>
      </button>
    </nav>
    </div>

    <section class="fin-body" role="tabpanel">
      <FinanceSummary v-if="activeTab === 'summary'" @navigate="navigate" />
      <PedidosYaSettlements v-else-if="activeTab === 'pedidosya'" :intent="intent" @intent-done="intent = ''" />
      <FinanceExpenses v-else-if="activeTab === 'expenses'" :intent="intent" @intent-done="intent = ''" />
      <FinanceRecurring v-else-if="activeTab === 'recurring'" :intent="intent" @intent-done="intent = ''" />
      <FinanceCosts v-else-if="activeTab === 'costs'" />
      <FinanceCategories v-else-if="activeTab === 'categories'" />
    </section>

    <FinanceConfirm />
    <div class="fin-toast" :class="{ show: finance.toast.show, error: finance.toast.kind === 'error' }" role="status" aria-live="polite">
      {{ finance.toast.message }}
    </div>
  </div>
</template>
