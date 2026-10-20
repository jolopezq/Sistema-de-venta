<script setup>
import { computed } from 'vue';
import { screensFor } from '../../utils/roleScreens.js';
import { joinWithAnd } from '../../utils/permissionModules.js';

const props = defineProps({
  role: {
    type: String,
    required: true,
  },
  permissions: {
    type: Object,
    required: true,
  },
});

const NAV_ICONS = {
  cart: '<path d="M5.5 8h13l-1.2 12H6.7z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
  cash: '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 9.5v5"/><path d="M18 9.5v5"/>',
  delivery: '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
  catalog: '<path d="M2 4h6a4 4 0 0 1 4 4v13a3 3 0 0 0-3-3H2z"/><path d="M22 4h-6a4 4 0 0 0-4 4v13a3 3 0 0 1 3-3h7z"/>',
  inventory: '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
  retroactive: '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M17 14l-4 4-2-2"/>',
  finance: '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
  users: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  audit: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
};

const roleLabel = computed(() => {
  const map = {
    kitchen: 'cocina',
    cashier: 'el cajero',
    admin: 'el admin',
    super_admin: 'el super admin',
  };
  return map[props.role] || props.role;
});

const previewState = computed(() => {
  return screensFor(props.role, (mod) => props.permissions[mod] ?? 'none');
});

const hiddenText = computed(() => {
  const hidden = previewState.value.hiddenScreens.map(s => s.name);
  if (hidden.length === 0) return '';
  return `No verá ${joinWithAnd(hidden)}.`;
});
</script>

<template>
  <div class="role-preview-container">
    <div class="preview-header-text">
      Así lo verá {{ roleLabel }}.
    </div>

    <!-- Si tiene al menos una pantalla disponible: muestra réplica del sidebar -->
    <div v-if="previewState.hasAccess" class="sidebar-mock">
      <!-- Marca -->
      <div class="mock-brand">
        <div class="mock-logo-chip"></div>
        <span>Ohana POS</span>
      </div>

      <!-- Menú -->
      <div class="mock-nav">
        <div
          v-for="item in previewState.screens"
          :key="item.route"
          class="mock-item"
          :class="{ 'is-landing': item.isLanding }"
        >
          <!-- eslint-disable-next-line vue/no-v-html -->
          <svg class="mock-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" v-html="NAV_ICONS[item.icon] || NAV_ICONS.catalog"></svg>
          <span class="mock-name">{{ item.name }}</span>

          <div class="mock-tags">
            <span v-if="item.isLanding" class="tag-landing">Abre aquí</span>
            <span v-else-if="item.isReadOnly" class="tag-readonly">Solo ver</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Si el rol queda sin pantallas disponibles -->
    <div v-else class="no-access-card">
      <div class="no-access-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
          <circle cx="12" cy="12" r="10"></circle>
          <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
        </svg>
        Acceso no disponible
      </div>
      <p class="no-access-desc">
        Al iniciar sesión, esta cuenta verá la pantalla de acceso denegado y no podrá operar en el sistema.
      </p>
    </div>

    <!-- Lista de pantallas que no verá -->
    <div v-if="previewState.hasAccess && hiddenText" class="hidden-notice">
      {{ hiddenText }}
    </div>
  </div>
</template>

<style scoped>
.role-preview-container {
  flex: 1 1 236px;
  max-width: 280px;
  min-width: 210px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.preview-header-text {
  font-size: 13px;
  font-weight: 500;
  color: var(--ink-500);
}

.sidebar-mock {
  background: var(--acai-900, #20112F);
  border-radius: var(--radius-md, 8px);
  padding: 12px 10px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.mock-brand {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 4px 6px 4px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.mock-logo-chip {
  width: 14px;
  height: 14px;
  border-radius: 3px;
  background: linear-gradient(135deg, #F97316 0%, #E11D48 100%);
}

.mock-brand span {
  font-family: 'Baloo 2', cursive, sans-serif;
  font-size: 13.5px;
  font-weight: 700;
  color: #ffffff;
  letter-spacing: 0.3px;
}

.mock-nav {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.mock-item {
  height: 30px;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 8px;
  border-radius: 5px;
  background: transparent;
  color: rgba(255, 255, 255, 0.72);
  font-size: 12.5px;
  transition: all 0.12s ease;
}

.mock-item.is-landing {
  background: rgba(255, 255, 255, 0.1);
  color: #ffffff;
  font-weight: 600;
}

.mock-icon {
  width: 15px;
  height: 15px;
  flex-shrink: 0;
  opacity: 0.85;
}

.mock-name {
  flex: 1 1 auto;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mock-tags {
  display: flex;
  align-items: center;
  gap: 4px;
}

.tag-landing {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 3px;
  background: #D9F99D;
  color: #365314;
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: 0.2px;
  white-space: nowrap;
}

.tag-readonly {
  display: inline-block;
  padding: 1px 5px;
  border-radius: 3px;
  background: rgba(255, 255, 255, 0.15);
  color: rgba(255, 255, 255, 0.85);
  font-size: 10.5px;
  font-weight: 500;
  white-space: nowrap;
}

.no-access-card {
  background: var(--danger-100, #FEE2E2);
  border: 1px solid var(--danger-300, #FCA5A5);
  border-radius: var(--radius-md, 8px);
  padding: 12px;
  color: var(--danger-text, #991B1B);
}

.no-access-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
  font-size: 13px;
  margin-bottom: 4px;
}

.no-access-desc {
  font-size: 12px;
  line-height: 1.35;
  margin: 0;
  opacity: 0.9;
}

.hidden-notice {
  font-size: 12px;
  line-height: 1.35;
  color: var(--ink-500);
}

/* Modo oscuro */
html.dark .tag-landing {
  background: #4D7C0F;
  color: #ECFCCB;
}

html.dark .no-access-card {
  background: rgba(239, 68, 68, 0.15);
  border-color: rgba(239, 68, 68, 0.3);
  color: #FCA5A5;
}
</style>
