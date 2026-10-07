<script setup>
import { ref } from 'vue';
import { apiFetch } from '../../services/api';
import { useFinanceStore, errorText } from '../../stores/finance';

/** Equivalencias guardadas de nombres PedidosYa ↔ POS (se crean al importar una semana). */
const finance = useFinanceStore();
const open = ref(false);
const mappings = ref([]);
const loading = ref(false);

async function load() {
  loading.value = true;
  try {
    mappings.value = await apiFetch('/finance/pedidosya-mappings');
  } catch (err) {
    finance.notify(errorText(err), 'error');
  } finally {
    loading.value = false;
  }
}

function toggle() {
  open.value = !open.value;
  if (open.value) load();
}

async function remove(m) {
  const ok = await finance.confirm({
    title: 'Quitar equivalencia',
    message: `«${m.name}» volverá a pedirse en la próxima importación. Las ventas ya creadas no cambian.`,
    confirmLabel: 'Quitar',
    danger: true,
  });
  if (!ok) return;
  try {
    await apiFetch(`/finance/pedidosya-mappings/${m.id}`, { method: 'DELETE' });
    finance.notify('Equivalencia quitada.');
    await load();
  } catch (err) {
    finance.notify(errorText(err), 'error');
  }
}
</script>

<template>
  <div class="fin-card">
    <div class="fin-card-head">
      <div>
        <h3>Equivalencias de nombres</h3>
        <p>Cómo se traducen los artículos de PedidosYa a productos y opciones del POS al crear las ventas.</p>
      </div>
      <button class="fin-btn fin-btn-ghost fin-btn-sm" type="button" :aria-expanded="open" @click="toggle">{{ open ? 'Ocultar' : 'Ver equivalencias' }}</button>
    </div>
    <template v-if="open">
      <div v-if="loading" class="fin-loading">Cargando…</div>
      <div v-else-if="!mappings.length" class="fin-empty">Todavía no hay equivalencias: se piden al importar una semana.</div>
      <div v-else class="fin-table-wrap">
        <table class="fin-table stack">
          <thead>
            <tr>
              <th>En PedidosYa</th>
              <th>Tipo</th>
              <th>En el POS</th>
              <th class="actions"><span class="sr-only">Acciones</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in mappings" :key="m.id">
              <td data-label="PedidosYa" class="fin-cell-main">{{ m.name }}</td>
              <td data-label="Tipo">{{ m.entity_type === 'product' ? 'Producto' : 'Opción' }}</td>
              <td data-label="POS">{{ m.target }}</td>
              <td class="actions">
                <button class="fin-icon-btn danger" type="button" :aria-label="`Quitar ${m.name}`" @click="remove(m)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" /></svg>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
