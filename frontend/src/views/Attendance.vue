<script setup>
/**
 * Asistencia (administradores con el permiso «Asistencia y sueldos»): hoy, bandeja «A revisar», todas las marcas y
 * equipos y redes (docs/PLAN_ASISTENCIA_Y_SUELDOS.md). Los trabajadores no entran aquí: solo ven sus horas en el reloj.
 */
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import AttendanceToday from '../components/attendance/AttendanceToday.vue';
import AttendanceReview from '../components/attendance/AttendanceReview.vue';
import AttendanceMarks from '../components/attendance/AttendanceMarks.vue';
import AttendanceSetup from '../components/attendance/AttendanceSetup.vue';
import ManualMarkModal from '../components/attendance/ManualMarkModal.vue';
import '../components/Finance/finance.css';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const TABS = [
  { key: 'today', label: 'Hoy' },
  { key: 'review', label: 'A revisar' },
  { key: 'marks', label: 'Todas las marcas' },
  { key: 'setup', label: 'Equipos y redes' },
];
const activeTab = ref(TABS.some((t) => t.key === route.query?.tab) ? route.query.tab : 'today');
const canEdit = computed(() => auth.hasPermission('attendance', 'edit'));
const reviewCount = ref(null);
const people = ref([]);
const manual = ref({ open: false, preset: {} });
const toast = ref({ show: false, message: '', kind: 'ok' });
const todayRef = ref(null);
const marksRef = ref(null);
let toastTimer = null;

watch(() => route.query?.tab, (tab) => {
  if (tab && TABS.some((t) => t.key === tab)) activeTab.value = tab;
});

function navigate(tab) {
  activeTab.value = tab;
  router.replace({ query: { ...route.query, tab } }).catch(() => {});
}

function showToast(message, kind = 'ok') {
  toast.value = { show: true, message, kind };
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { toast.value.show = false; }, 4500);
}

function openManual(preset) {
  manual.value = { open: true, preset };
}

function onSaved() {
  manual.value.open = false;
  showToast('Marca guardada.');
  todayRef.value?.load?.();
  marksRef.value?.load?.();
}
</script>

<template>
  <div class="fin-page">
    <div class="fin-nav">
      <header class="fin-head">
        <div>
          <h1>Asistencia <span v-if="!canEdit" class="fin-readonly-chip">Solo lectura</span></h1>
          <p>Entradas y salidas en el local, marcas por revisar y equipos autorizados</p>
        </div>
        <router-link v-if="canEdit" to="/asistencia/reloj" class="fin-btn fin-btn-secondary fin-btn-lg att-open-clock">Abrir el reloj</router-link>
      </header>
      <nav class="fin-tabs" role="tablist" aria-label="Secciones de Asistencia">
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
          <span v-if="t.key === 'review' && reviewCount" class="fin-count alert">{{ reviewCount }}</span>
        </button>
      </nav>
    </div>

    <section class="fin-body" role="tabpanel">
      <!-- «Hoy» se monta siempre (oculta) para tener la lista de personas en las otras pestañas -->
      <AttendanceToday v-show="activeTab === 'today'" ref="todayRef" :can-edit="canEdit" @add-mark="openManual" @people="people = $event" />
      <AttendanceReview v-if="activeTab === 'review'" :can-edit="canEdit" @changed="reviewCount = $event" @toast="showToast" />
      <AttendanceMarks v-else-if="activeTab === 'marks'" ref="marksRef" :can-edit="canEdit" :people="people" @add-mark="openManual" />
      <AttendanceSetup v-else-if="activeTab === 'setup'" :can-edit="canEdit" @toast="showToast" />
    </section>

    <ManualMarkModal :open="manual.open" :people="people" :preset="manual.preset" @close="manual.open = false" @saved="onSaved" />

    <div class="fin-toast" :class="{ show: toast.show, error: toast.kind === 'error' }" role="status" aria-live="polite">
      {{ toast.message }}
    </div>
  </div>
</template>

<style scoped>
.att-open-clock { text-decoration: none; display: inline-flex; align-items: center; }
</style>
