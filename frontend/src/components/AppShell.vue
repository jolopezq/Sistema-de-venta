<script setup>
import { computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useReorderStore } from '../stores/reorder';
import { useThemeStore } from '../stores/theme';
import { useTurnoStore } from '../stores/turno';
import NetworkIndicator from './NetworkIndicator.vue';
import { screensFor } from '../utils/roleScreens.js';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();
const theme = useThemeStore();
const reorder = useReorderStore();
const turno = useTurnoStore();

// Marca del menú «Inventario»: insumos por reponer (solo administradores; el servidor calcula las alertas al momento)
const canSeeReorder = computed(() => ['admin', 'super_admin'].includes(auth.user?.role));
// Indicador de turno: solo para quien ve la pantalla Caja; se consulta una vez al montar el menú
const canSeeTurno = computed(() => screensFor(auth.user?.role, (mod) => auth.effective_permissions?.[mod] ?? 'none').screens.some(s => s.route === '/turno'));
// La ventana queda fija mientras está el menú: el único que se desplaza es el contenido (ver style.css)
onMounted(() => document.documentElement.classList.add('oh-shell-active'));
onBeforeUnmount(() => document.documentElement.classList.remove('oh-shell-active'));

onMounted(() => {
  if (canSeeReorder.value) reorder.fetchSummary();
  if (canSeeTurno.value && !turno.activeSession) turno.fetchActiveSession();
});
watch(() => route.path, () => { if (canSeeReorder.value) reorder.fetchSummary(); });

const userInitials = computed(() => {
  const name = auth.user?.name || '';
  return name.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase() || '??';
});

const userRole = computed(() => {
  const map = {
    super_admin: 'Super Admin',
    admin: 'Administrador',
    cashier: 'Cajero',
    cajero: 'Cajero',
    kitchen: 'Cocina',
  };
  return map[auth.user?.role] || auth.user?.role || '';
});

/**
 * Iconos del menú (trazo de 24 px, sin librerías externas). Las claves son las que usa `navItems`:
 * si se agrega una opción, se agrega aquí su icono (antes Vender, Caja y Menú caían en un ⚙️ genérico).
 */
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

const navItems = computed(() => {
  const role = auth.user?.role;
  const isSuperAdmin = role === 'super_admin';
  const isAdmin = role === 'admin' || isSuperAdmin;

  const { screens } = screensFor(role, (mod) => auth.effective_permissions?.[mod] ?? 'none');
  const screenMap = new Map(screens.map(s => [s.route, s]));

  return [
    {
      label: 'Operación',
      items: [
        { name: 'Vender', route: '/pos', show: screenMap.has('/pos'), icon: 'cart' },
        { name: 'Caja', route: '/turno', show: screenMap.has('/turno'), icon: 'cash' },
        { name: 'Delivery', route: '/delivery', show: screenMap.has('/delivery'), icon: 'delivery' },
        { name: 'Menú', route: '/menu', show: screenMap.has('/menu'), icon: 'catalog' },
        { name: 'Inventario', route: '/inventario', show: screenMap.has('/inventario'), icon: 'inventory', badge: reorder.count },
      ],
    },
    {
      label: 'Sistema',
      show: isAdmin,
      items: [
        { name: 'Control diario', route: '/admin/ventas-retroactivas', show: screenMap.has('/admin/ventas-retroactivas'), icon: 'retroactive' },
        { name: 'Finanzas', route: '/finanzas', show: screenMap.has('/finanzas'), icon: 'finance' },
        { name: 'Usuarios', route: '/users', show: screenMap.has('/users'), icon: 'users' },
        { name: 'Auditoría', route: '/audit-logs', show: screenMap.has('/audit-logs'), icon: 'audit' },
      ],
    },
  ];
});

function isActive(itemRoute) {
  return route.path === itemRoute || route.path.startsWith(itemRoute + '/');
}

async function handleLogout() {
  await auth.logout();
  router.push('/login');
}
</script>

<template>
  <div class="oh-shell">
    <!-- SIDEBAR -->
    <aside class="oh-sidebar">
      <!-- Brand -->
      <div class="pos-brand">
        <div class="logo-chip"></div>
        <span>Ohana POS</span>
      </div>

      <!-- Navigation -->
      <nav class="oh-nav">
        <template v-for="section in navItems" :key="section.label">
          <template v-if="section.show !== false">
            <div class="oh-nav-label" role="presentation">{{ section.label }}</div>
            <template v-for="item in section.items" :key="item.route">
              <a
                v-if="item.always || item.show !== false"
                :class="{ active: isActive(item.route) }"
                :aria-current="isActive(item.route) ? 'page' : undefined"
                :title="item.name"
                :href="item.route"
                @click.prevent="router.push(item.route)"
              >
                <!-- eslint-disable-next-line vue/no-v-html -- iconos fijos definidos arriba, sin datos del usuario -->
                <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="NAV_ICONS[item.icon] || NAV_ICONS.catalog"></svg>
                <span>{{ item.name }}</span>
                <b v-if="item.badge" class="nav-badge" :title="`${item.badge} por reponer en Inventario`" :aria-label="`${item.badge} por reponer`">{{ item.badge > 99 ? '99+' : item.badge }}</b>
              </a>
            </template>
          </template>
        </template>
      </nav>

      <!-- Turno: indicador con texto (el color no va solo) -->
      <a
        v-if="turno.hasActiveSession && canSeeTurno"
        class="oh-turno-chip"
        href="/turno?cerrar=1"
        title="Turno abierto: ir a cerrar caja"
        @click.prevent="router.push({ path: '/turno', query: { cerrar: '1' } })"
      >
        <i aria-hidden="true"></i><span>Turno abierto</span>
      </a>

      <!-- Footer: user info + logout -->
      <div class="oh-sidebar-foot">
        <div class="oh-avatar-sm">{{ userInitials }}</div>
        <div class="oh-sidebar-foot-info">
          <div class="name" :title="auth.user?.name">{{ auth.user?.name || 'Usuario' }}</div>
          <div class="role">{{ userRole }}</div>
        </div>
        <button class="oh-logout-btn" type="button" @click="handleLogout" title="Cerrar sesión" aria-label="Cerrar sesión">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
        </button>
      </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="oh-shell-main">
      <!-- Top bar: title + network indicator -->
      <div class="oh-shell-topbar">
        <div class="topbar-actions">
          <button
            type="button"
            class="oh-perf-btn-top"
            :class="{ active: theme.isPerfLite }"
            :aria-pressed="theme.isPerfLite"
            aria-label="Modo liviano para equipos lentos"
            @click="theme.togglePerfLite()"
            :title="theme.isPerfLite ? 'Modo liviano activo: sin desenfoques ni sombras pesadas' : 'Activar el modo liviano para equipos lentos'"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
            </svg>
            <span class="perf-tag" v-if="theme.isPerfLite">60 FPS</span>
          </button>
          <button
            type="button"
            class="oh-theme-btn-top"
            :aria-label="theme.isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'"
            :title="theme.isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'"
            @click="theme.toggleTheme()"
          >
            <svg v-if="theme.isDark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"></path>
            </svg>
            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <circle cx="12" cy="12" r="5"></circle>
              <line x1="12" y1="1" x2="12" y2="3"></line>
              <line x1="12" y1="21" x2="12" y2="23"></line>
              <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
              <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
              <line x1="1" y1="12" x2="3" y2="12"></line>
              <line x1="21" y1="12" x2="23" y2="12"></line>
              <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
              <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
            </svg>
          </button>
          <NetworkIndicator />
        </div>
      </div>
      <!-- Routed content -->
      <div class="oh-shell-content">
        <slot />
      </div>
    </main>
  </div>
</template>

<style scoped>
/* ---- Design Tokens (scoped to shell) ---- */
.oh-shell {
  --acai: var(--acai-800);
  --acai-light: var(--acai-700);
  --acai-pale: var(--acai-50);
  --cream: var(--cream-50);
  --ink: var(--ink-900);
  --ink-soft: var(--ink-500);
  --border-local: var(--border);

  font-family: var(--font-sans);
  display: flex;
  height: 100%;
  /* Sin min-height: 100vh: en celular 100vh es más alto que lo visible y el pie quedaba bajo la barra del navegador */
  min-height: 0;
  width: 100%;
  overflow: hidden;
  background: var(--cream);
  color: var(--ink);
}

/* ---- SIDEBAR ---- */
.oh-sidebar {
  width: 216px;
  flex-shrink: 0;
  background: var(--acai);
  color: #F4EEF5;
  padding: 22px 14px;
  display: flex;
  flex-direction: column;
  gap: 26px;
  overflow-y: auto;
  overscroll-behavior: contain;
}

.pos-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 8px;
}

.pos-brand .logo-chip {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: #ffffff var(--logo-uri) center/86% no-repeat;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
  box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}

.pos-brand span {
  font-family: var(--font-heading);
  font-weight: 700;
  font-size: 17px;
}

/* Nav */
.oh-nav { display: flex; flex-direction: column; gap: 2px; }
.oh-nav a {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border-radius: 8px;
  color: #D9C7DB;
  font-size: 13.5px;
  font-weight: 500;
  text-decoration: none;
  cursor: pointer;
  transition: background .15s ease, color .15s ease;
}
.oh-nav a { position: relative; }
.oh-nav a svg { width: 16px; height: 16px; flex-shrink: 0; opacity: .85; }
.nav-badge {
  margin-left: auto;
  min-width: 20px;
  padding: 0 6px;
  border-radius: 999px;
  background: var(--passion-500);
  color: var(--on-accent);
  font-size: 11px;
  font-weight: 700;
  line-height: 18px;
  text-align: center;
}
.oh-nav a:hover { background: rgba(255,255,255,0.07); color: #fff; }
/* Opción activa: fondo más claro y una marca maracuyá a la izquierda (el color no va solo: también cambia el peso) */
.oh-nav a.active { background: rgba(255,255,255,0.12); color: #fff; font-weight: 600; box-shadow: inset 3px 0 0 var(--passion-500); }
.oh-nav a.active svg { opacity: 1; }
.oh-nav a:focus-visible { outline: 2px solid var(--passion-400); outline-offset: 1px; }

.oh-nav-label {
  font-size: 12px;
  color: #B79DBB;
  margin: 10px 0 2px 10px;
  font-weight: 500;
}
.oh-nav-label:first-child { margin-top: 0; }

/* Footer */
.oh-sidebar-foot {
  margin-top: auto;
  padding: 12px 10px;
  border-top: 1px solid rgba(255,255,255,0.12);
  display: flex;
  align-items: center;
  gap: 9px;
}
.oh-turno-chip {
  margin: auto 10px 0; padding: 6px 10px; border-radius: 8px;
  display: flex; align-items: center; gap: 8px;
  background: rgba(255,255,255,0.10); color: #fff; font-size: 12px; font-weight: 600; text-decoration: none;
}
.oh-turno-chip i { width: 8px; height: 8px; border-radius: 50%; background: #4ade80; flex-shrink: 0; }
.oh-turno-chip:hover { background: rgba(255,255,255,0.18); }
.oh-turno-chip:focus-visible { outline: 2px solid var(--passion-400); outline-offset: 1px; }
.oh-turno-chip + .oh-sidebar-foot { margin-top: 8px; }
@media (max-width: 760px) { .oh-turno-chip span { display: none; } .oh-turno-chip { justify-content: center; margin: auto 6px 0; } }
.oh-avatar-sm {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--acai-light);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 700;
  color: #fff;
  flex-shrink: 0;
}
.oh-sidebar-foot-info { flex: 1; min-width: 0; }
/* El nombre ocupa hasta dos líneas antes de cortarse («Super Admin Dueño» no cabe en una) */
.oh-sidebar-foot-info .name {
  font-size: 12.5px; font-weight: 600; color: #fff; line-height: 1.25;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; overflow-wrap: anywhere;
}
.oh-sidebar-foot-info .role { font-size: 11px; color: #C8AECC; }

.oh-logout-btn {
  width: 32px;
  height: 32px;
  border: none;
  background: rgba(255,255,255,0.08);
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: #C8AECC;
  flex-shrink: 0;
  transition: background .15s ease, color .15s ease;
}
.oh-logout-btn:hover { background: rgba(255,255,255,0.18); color: #fff; }
.oh-logout-btn:focus-visible { outline: 2px solid var(--passion-400); outline-offset: 1px; }
.oh-logout-btn svg { width: 15px; height: 15px; }

/* ---- MAIN ---- */
.oh-shell-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
  overflow: hidden;
}

.oh-shell-topbar {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  padding: 10px 20px;
  border-bottom: 1px solid var(--border-local);
  background: var(--surface);
  min-height: 44px;
  flex-shrink: 0;
}
.topbar-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}
.oh-theme-btn-top, .oh-perf-btn-top {
  height: 32px;
  border: none;
  background: var(--cream-200);
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 0 10px;
  cursor: pointer;
  color: var(--ink-700);
  font-size: 11.5px;
  font-weight: 700;
  transition: all .15s ease;
}
.oh-theme-btn-top { width: 32px; padding: 0; }
.oh-theme-btn-top:focus-visible, .oh-perf-btn-top:focus-visible { outline: 2px solid var(--acai-500); outline-offset: 2px; }
.oh-theme-btn-top:hover, .oh-perf-btn-top:hover {
  background: var(--border-local);
  color: var(--ink-900);
}
.oh-perf-btn-top.active {
  background: var(--passion-100);
  color: var(--passion-text);
  border: 1px solid rgba(251, 120, 16, 0.3);
}
.oh-perf-btn-top svg { width: 15px; height: 15px; }
.oh-theme-btn-top svg { width: 18px; height: 18px; }
.perf-tag { font-family: var(--font-heading); font-size: 11px; }

.oh-shell-content {
  flex: 1;
  min-height: 0;
  overflow: auto;
  display: flex;
  flex-direction: column;
  /* Bloque contenedor de los position:absolute del contenido (p. ej. .sr-only en los encabezados de tabla).
     Sin esto se ubicaban respecto de la ventana, estiraban la página (Costos: 863 px con 611 de pantalla) y al
     llegar al final la rueda desplazaba la ventana: el menú se despegaba y aparecía una franja blanca. */
  position: relative;
  /* Al llegar al final, la rueda o el gesto no pasan a la ventana (ni al «tirar para recargar» del celular) */
  overscroll-behavior: contain;
}

/* ---- RESPONSIVE: Laptop 1366x768 & Compact Displays ---- */
@media (max-width: 1366px), (max-height: 800px) {
  .oh-sidebar {
    width: 175px;
    padding: 14px 10px;
    gap: 16px;
  }
  .pos-brand span { font-size: 15px; }
  .pos-brand .logo-chip { width: 30px; height: 30px; }
  .oh-nav a {
    padding: 7px 8px;
    font-size: 12.5px;
    gap: 8px;
  }
  .oh-nav a svg { width: 14px; height: 14px; }
  .oh-nav-label { font-size: 11.5px; margin: 6px 0 2px 8px; }
  .oh-avatar-sm { width: 24px; height: 24px; font-size: 10px; }
  .oh-sidebar-foot-info .name { font-size: 11.5px; }
  .oh-sidebar-foot-info .role { font-size: 10.5px; }
  .oh-sidebar-foot { padding: 8px 6px; gap: 6px; }
  .oh-logout-btn { width: 28px; height: 28px; }
  .oh-shell-topbar { min-height: 38px; padding: 6px 14px; }
  .oh-theme-btn-top, .oh-perf-btn-top { height: 28px; }
}

@media (max-width: 760px) {
  .oh-sidebar {
    width: 64px;
    padding: 18px 8px;
    gap: 18px;
  }
  .pos-brand span,
  .oh-nav-label,
  .oh-nav a span,
  .oh-sidebar-foot-info { display: none; }
  .oh-nav a { justify-content: center; }
  .oh-sidebar-foot { justify-content: center; }
  .oh-logout-btn { margin: 0 auto; }
}

/* Con el menú de solo iconos la marca se pega a la esquina del icono */
@media (max-width: 767px) {
  .nav-badge { position: absolute; top: 2px; right: 2px; margin: 0; min-width: 16px; padding: 0 4px; font-size: 10px; line-height: 16px; }
}
</style>
