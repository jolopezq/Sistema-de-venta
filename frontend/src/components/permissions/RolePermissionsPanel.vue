<script setup>
import { ref, computed, onMounted } from 'vue';
import { apiFetch } from '../../services/api';
import { useAuthStore } from '../../stores/auth';
import { screensFor } from '../../utils/roleScreens.js';
import {
  PERMISSION_MODULES,
  summaryPhrase,
  roleBoundariesText,
} from '../../utils/permissionModules.js';
import PermissionRow from './PermissionRow.vue';
import RolePreview from './RolePreview.vue';

const props = defineProps({
  users: {
    type: Array,
    default: () => [],
  },
});

const authStore = useAuthStore();

// Estados de carga y red
const isLoading = ref(true);
const loadError = ref(null);
const isSaving = ref(false);
const saveSuccessMessage = ref('');
let saveSuccessTimeout = null;

// Datos cargados de la API
const levels = ref({});
const defaults = ref({});
const saved = ref({
  kitchen: {},
  cashier: {},
  admin: {},
});

// Borrador local editable (unificado para los 3 roles)
const draft = ref({
  kitchen: {},
  cashier: {},
  admin: {},
});

// Rol activo en el selector
const activeRole = ref('cashier');

const ROLES = [
  { id: 'kitchen', label: 'Cocina', color: '#B4532A' },
  { id: 'cashier', label: 'Cajero', color: '#5C7A3F' },
  { id: 'admin', label: 'Administrador', color: '#8B5E3C' },
];

// Contar usuarios asignados a cada rol
const userCounts = computed(() => {
  const counts = { kitchen: 0, cashier: 0, admin: 0, super_admin: 0 };
  props.users.forEach(u => {
    if (counts[u.role] !== undefined) {
      counts[u.role]++;
    }
  });
  return counts;
});

// Calcular filas cambiadas entre draft y saved
const changedRows = computed(() => {
  const diffs = [];
  ['kitchen', 'cashier', 'admin'].forEach(r => {
    PERMISSION_MODULES.forEach(mod => {
      const draftLvl = draft.value[r]?.[mod.id] ?? 'none';
      const savedLvl = saved.value[r]?.[mod.id] ?? 'none';
      if (draftLvl !== savedLvl) {
        diffs.push({
          role: r,
          module: mod.id,
          access_level: draftLvl,
        });
      }
    });
  });
  return diffs;
});

const totalChangesCount = computed(() => changedRows.value.length);
const hasChanges = computed(() => totalChangesCount.value > 0);

// Verificar si algún rol queda sin pantallas disponibles
const roleWithoutScreensNotice = computed(() => {
  for (const r of ['kitchen', 'cashier', 'admin']) {
    const { hasAccess } = screensFor(r, (mod) => draft.value[r]?.[mod] ?? 'none');
    if (!hasAccess) {
      const label = r === 'kitchen' ? 'cocina' : r === 'cashier' ? 'cajero' : 'administrador';
      return `La cuenta de ${label} quedará sin ninguna pantalla.`;
    }
  }
  return null;
});

// Frase resumen del rol activo
const currentSummaryPhrase = computed(() => {
  return summaryPhrase(activeRole.value, draft.value[activeRole.value]);
});

// Texto de límites fijos al pie de la lista
const currentBoundariesText = computed(() => {
  return roleBoundariesText(activeRole.value);
});

// Comprobar si el rol activo difiere de sus valores por defecto
const differsFromDefaults = computed(() => {
  const r = activeRole.value;
  const def = defaults.value[r] || {};
  return PERMISSION_MODULES.some(mod => {
    const current = draft.value[r]?.[mod.id] ?? 'none';
    const expected = def[mod.id] ?? 'none';
    return current !== expected;
  });
});

// Cargar permisos desde la API
async function loadPermissions() {
  isLoading.value = true;
  loadError.value = null;
  saveSuccessMessage.value = '';

  try {
    const res = await apiFetch('/permissions');
    levels.value = res.levels || {};
    defaults.value = res.defaults || {};

    const newSaved = { kitchen: {}, cashier: {}, admin: {} };

    // Llenar saved con lo que viene de la API o con defaults
    ['kitchen', 'cashier', 'admin'].forEach(r => {
      PERMISSION_MODULES.forEach(mod => {
        const fromDb = res.permissions?.find(p => p.role === r && p.module === mod.id);
        const val = fromDb ? fromDb.access_level : (res.defaults?.[r]?.[mod.id] ?? 'none');
        newSaved[r][mod.id] = val;
      });
    });

    saved.value = JSON.parse(JSON.stringify(newSaved));
    draft.value = JSON.parse(JSON.stringify(newSaved));
    isLoading.value = false;
  } catch (err) {
    console.error('Error al cargar permisos:', err);
    loadError.value = err.message || 'No se pudo conectar con el servidor.';
    isLoading.value = false;
  }
}

function updateLevel(moduleKey, newLevel) {
  if (!draft.value[activeRole.value]) {
    draft.value[activeRole.value] = {};
  }
  draft.value[activeRole.value][moduleKey] = newLevel;
}

function resetToDefaults() {
  const r = activeRole.value;
  const def = defaults.value[r] || {};
  PERMISSION_MODULES.forEach(mod => {
    draft.value[r][mod.id] = def[mod.id] ?? 'none';
  });
}

function discardChanges() {
  draft.value = JSON.parse(JSON.stringify(saved.value));
}

async function saveChanges() {
  if (!hasChanges.value || isSaving.value || isLoading.value) return;

  isSaving.value = true;
  saveSuccessMessage.value = '';
  if (saveSuccessTimeout) clearTimeout(saveSuccessTimeout);

  try {
    await apiFetch('/permissions', {
      method: 'PUT',
      body: JSON.stringify({ permissions: changedRows.value }),
    });

    // Actualizar estado saved con lo que se acaba de guardar
    saved.value = JSON.parse(JSON.stringify(draft.value));

    // Refrescar permisos del usuario actual en Pinia
    await authStore.fetchUser();

    saveSuccessMessage.value = 'Cambios guardados. Se aplican desde la próxima acción de cada persona.';
    saveSuccessTimeout = setTimeout(() => {
      saveSuccessMessage.value = '';
    }, 5000);
  } catch (err) {
    console.error('Error al guardar permisos:', err);
    alert(err.message || 'Ocurrió un error al guardar los cambios.');
  } finally {
    isSaving.value = false;
  }
}

defineExpose({
  hasChanges,
  totalChangesCount,
  saveSuccessMessage,
  roleWithoutScreensNotice,
  isSaving,
  discardChanges,
  saveChanges,
});

onMounted(() => {
  loadPermissions();
});
</script>

<template>
  <div class="perm-panel-root">
    <!-- Estado: Cargando -->
    <div v-if="isLoading" class="state-card loading-card">
      <div class="spinner"></div>
      <span>Cargando configuración de permisos...</span>
    </div>

    <!-- Estado: Error de carga con reintento -->
    <div v-else-if="loadError" class="state-card error-card">
      <div class="error-msg">
        <strong>Error:</strong> No se pudo cargar la configuración de permisos.
      </div>
      <button type="button" class="btn-retry" @click="loadPermissions">
        Reintentar
      </button>
    </div>

    <!-- Contenido principal una vez cargado -->
    <div v-else class="perm-panel-body">
      <!-- 1. Franja de rol: Selector segmentado a la izquierda + Frase resumen a la derecha -->
      <div class="role-strip">
        <div class="role-strip-left">
          <div class="role-selector" role="tablist" aria-label="Seleccionar rol">
            <button
              v-for="r in ROLES"
              :key="r.id"
              type="button"
              role="tab"
              class="role-tab-btn"
              :class="{ active: activeRole === r.id }"
              :aria-selected="activeRole === r.id"
              @click="activeRole = r.id"
            >
              <span class="role-dot" :style="{ background: r.color }"></span>
              <span class="role-tab-name">{{ r.label }}</span>
              <span class="role-tab-count">{{ userCounts[r.id] || 0 }}</span>
            </button>
          </div>

        </div>

        <div class="role-strip-right">
          <h2 class="summary-title">{{ currentSummaryPhrase }}</h2>
        </div>
      </div>

      <!-- 3. Contenido: Lista de módulos a la izquierda + Vista previa a la derecha -->
      <div class="perm-content-grid">
        <!-- Columna izquierda: Lista de 10 módulos -->
        <div class="modules-column">
          <div class="modules-list">
            <PermissionRow
              v-for="mod in PERMISSION_MODULES"
              :key="mod.id"
              :module="mod"
              :role="activeRole"
              :current-level="draft[activeRole]?.[mod.id] ?? 'none'"
              :saved-level="saved[activeRole]?.[mod.id] ?? 'none'"
              :allowed-levels="levels[activeRole]?.[mod.id] ?? ['none']"
              @update:level="(lvl) => updateLevel(mod.id, lvl)"
            />
          </div>

          <!-- Pie de la lista: Límites fijos y Volver a lo recomendado -->
          <div class="modules-footer">
            <div class="boundaries-text">
              {{ currentBoundariesText }}
            </div>
            <button
              v-if="differsFromDefaults"
              type="button"
              class="btn-restore-defaults"
              @click="resetToDefaults"
            >
              Volver a lo recomendado
            </button>
          </div>
        </div>

        <!-- Columna derecha: Vista previa del menú lateral -->
        <RolePreview
          :role="activeRole"
          :permissions="draft[activeRole] || {}"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.perm-panel-root {
  width: 100%;
  max-width: 1240px;
  margin: 0 auto;
  box-sizing: border-box;
}

.state-card {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 36px 20px;
  border-radius: var(--radius-md, 8px);
  background: var(--surface);
  box-shadow: var(--shadow-card);
  font-size: 14px;
}

.loading-card {
  color: var(--ink-500);
}

.error-card {
  background: var(--danger-100, #FEE2E2);
  color: var(--danger-text, #991B1B);
  flex-direction: column;
}

.spinner {
  width: 20px;
  height: 20px;
  border: 2px solid var(--ink-300);
  border-top-color: var(--acai-700);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.btn-retry {
  height: 32px;
  padding: 0 16px;
  background: var(--surface);
  border: 1px solid currentColor;
  border-radius: var(--radius-sm, 6px);
  color: inherit;
  font-weight: 600;
  cursor: pointer;
}

.perm-panel-body {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

/* 1. Franja de rol y frase resumen */
.role-strip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  min-height: 40px;
}

.role-strip-left {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1 1 auto;
  min-width: 0;
  flex-wrap: wrap;
}

.role-strip-right {
  flex: 1 1 0;
  max-width: 680px;
  min-width: 0;
}

.role-selector {
  display: inline-flex;
  background: var(--cream-200);
  border-radius: var(--radius-md, 8px);
  padding: 2px;
  gap: 2px;
}

.role-tab-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 36px;
  padding: 0 14px;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: var(--ink-500);
  font-family: inherit;
  font-size: 13.5px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s ease;
  user-select: none;
}

.role-tab-btn.active {
  background: var(--surface);
  color: var(--ink);
  font-weight: 600;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.role-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

.role-tab-count {
  font-size: 11px;
  font-weight: 700;
  color: var(--ink-500);
  background: rgba(0, 0, 0, 0.05);
  padding: 1px 5px;
  border-radius: 10px;
}

.super-admin-note {
  font-size: 12px;
  color: var(--ink-500);
  white-space: nowrap;
}

.summary-title {
  margin: 0;
  font-family: 'Baloo 2', cursive, sans-serif;
  font-size: 15px;
  line-height: 1.25;
  font-weight: 600;
  color: var(--ink);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* 3. Grid central: Módulos + Vista previa */
.perm-content-grid {
  display: flex;
  align-items: flex-start;
  gap: 20px;
}

.modules-column {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.modules-list {
  background: var(--surface);
  border-radius: var(--radius-md, 8px);
  padding: 6px 14px;
  box-shadow: var(--shadow-card);
  border: 1px solid var(--cream-200);
}

.modules-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 4px 0 4px;
}

.boundaries-text {
  font-size: 12.5px;
  line-height: 1.35;
  color: var(--ink-500);
}

.btn-restore-defaults {
  background: none;
  border: none;
  padding: 0;
  color: var(--acai-700);
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: underline;
  white-space: nowrap;
}

.btn-restore-defaults:hover {
  color: var(--acai-800);
}

/* Modo oscuro */
html.dark .role-selector {
  background: rgba(255, 255, 255, 0.06);
}

html.dark .role-tab-btn.active {
  background: var(--surface);
  color: var(--ink);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
}

html.dark .role-tab-count {
  background: rgba(255, 255, 255, 0.1);
}

html.dark .modules-list {
  border-color: rgba(255, 255, 255, 0.07);
}

html.dark .status-saved {
  color: #86EFAC;
}

html.dark .btn-restore-defaults {
  color: var(--acai-300);
}

/* Responsive: < 1100 px la vista previa y la frase pasan abajo */
@media (max-width: 1100px) {
  .role-strip {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }
  .role-strip-right {
    max-width: 100%;
    width: 100%;
  }
  .perm-content-grid {
    flex-direction: column;
  }
  .role-preview-container {
    max-width: 100%;
    width: 100%;
  }
}
</style>
