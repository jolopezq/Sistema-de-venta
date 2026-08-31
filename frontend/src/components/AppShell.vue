<script setup>
import { computed } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useThemeStore } from '../stores/theme';
import NetworkIndicator from './NetworkIndicator.vue';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();
const theme = useThemeStore();

const userInitials = computed(() => {
  const name = auth.user?.name || '';
  return name.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase() || '??';
});

const userRole = computed(() => {
  const map = {
    super_admin: 'Super Admin',
    admin: 'Administrador',
    cajero: 'Cajero',
  };
  return map[auth.user?.role] || auth.user?.role || '';
});

const navItems = computed(() => {
  const role = auth.user?.role;
  const isSuperAdmin = role === 'super_admin';
  const isAdmin = role === 'admin' || isSuperAdmin;

  return [
    {
      label: 'Operación',
      items: [
        { name: 'POS · Ventas', route: '/pos', always: true, icon: 'cart' },
        { name: 'Caja / Turno', route: '/turno', always: true, icon: 'cash' },
        { name: 'Delivery', route: '/delivery', always: true, icon: 'delivery' },
        { name: 'Menú', route: '/menu', show: isAdmin, icon: 'catalog' },
        { name: 'Inventario', route: '/inventario', show: isAdmin, icon: 'inventory' },
      ],
    },
    {
      label: 'Sistema',
      show: isSuperAdmin,
      items: [
        { name: 'Usuarios', route: '/users', show: isSuperAdmin, icon: 'users' },
        { name: 'Auditoría', route: '/audit-logs', show: isSuperAdmin, icon: 'audit' },
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
            <div class="oh-nav-label">{{ section.label }}</div>
            <template v-for="item in section.items" :key="item.route">
              <a
                v-if="item.always || item.show !== false"
                :class="{ active: isActive(item.route) }"
                @click.prevent="router.push(item.route)"
                href="#"
              >
                <!-- POS icon -->
                <svg v-if="item.icon === 'cart'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                  <path d="M1 1h4l2.7 13.4a2 2 0 002 1.6h9.7a2 2 0 002-1.6L23 6H6"/>
                </svg>
                <!-- Cash icon -->
                <svg v-else-if="item.icon === 'cash'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <rect x="3" y="7" width="18" height="13" rx="2"/>
                  <path d="M16 3v4M8 3v4M3 11h18"/>
                </svg>
                <!-- Delivery icon -->
                <svg v-else-if="item.icon === 'delivery'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                <!-- Catalog icon -->
                <svg v-else-if="item.icon === 'catalog'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                  <rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>
                </svg>
                <!-- Inventory icon -->
                <svg v-else-if="item.icon === 'inventory'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
                  <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                  <line x1="12" y1="22.08" x2="12" y2="12"/>
                </svg>
                <!-- Users icon -->
                <svg v-else-if="item.icon === 'users'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/>
                  <circle cx="9" cy="7" r="4"/>
                  <path d="M22 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
                </svg>
                <!-- Audit icon -->
                <svg v-else-if="item.icon === 'audit'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
                </svg>
                <span>{{ item.name }}</span>
              </a>
            </template>
          </template>
        </template>
      </nav>

      <!-- Footer: user info + logout -->
      <div class="oh-sidebar-foot">
        <div class="oh-avatar-sm">{{ userInitials }}</div>
        <div class="oh-sidebar-foot-info">
          <div class="name">{{ auth.user?.name || 'Usuario' }}</div>
          <div class="role">{{ userRole }}</div>
        </div>
        <!-- Performance Toggle Button -->
        <button 
          class="oh-perf-btn" 
          :class="{ active: theme.isPerfLite }" 
          @click="theme.togglePerfLite()" 
          :title="theme.isPerfLite ? 'Modo Alto Rendimiento Activo (Optimizado para Toshiba A10)' : 'Cambiar a Modo Alto Rendimiento'"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
          </svg>
        </button>

        <button class="oh-theme-btn" @click="theme.toggleTheme()" title="Cambiar tema">
          <svg v-if="theme.isDark" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
          <svg v-else xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
          </svg>
        </button>
        <button class="oh-logout-btn" @click="handleLogout" title="Cerrar sesión">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
            class="oh-perf-btn-top" 
            :class="{ active: theme.isPerfLite }" 
            @click="theme.togglePerfLite()" 
            :title="theme.isPerfLite ? 'Modo Rendimiento Ligero Activado (60 FPS)' : 'Activar Modo Rendimiento Ligero'"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
            </svg>
            <span class="perf-tag" v-if="theme.isPerfLite">60 FPS</span>
          </button>
          <button class="oh-theme-btn-top" @click="theme.toggleTheme()" title="Cambiar tema">
            <svg v-if="theme.isDark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"></path>
            </svg>
            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
  height: 100vh;
  width: 100vw;
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
.oh-nav a svg { width: 16px; height: 16px; flex-shrink: 0; opacity: .85; }
.oh-nav a:hover { background: rgba(255,255,255,0.07); color: #fff; }
.oh-nav a.active { background: rgba(255,255,255,0.12); color: #fff; }
.oh-nav a.active svg { opacity: 1; }

.oh-nav-label {
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 1.3px;
  color: #9E7FA2;
  margin: 8px 0 2px 10px;
  font-weight: 600;
}

/* Footer */
.oh-sidebar-foot {
  margin-top: auto;
  padding: 12px 10px;
  border-top: 1px solid rgba(255,255,255,0.12);
  display: flex;
  align-items: center;
  gap: 9px;
}
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
.oh-sidebar-foot-info .name { font-size: 12.5px; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.oh-sidebar-foot-info .role { font-size: 10.5px; color: #C8AECC; }

.oh-logout-btn, .oh-theme-btn, .oh-perf-btn {
  width: 28px;
  height: 28px;
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
.oh-logout-btn:hover, .oh-theme-btn:hover, .oh-perf-btn:hover { background: rgba(255,255,255,0.18); color: #fff; }
.oh-logout-btn svg, .oh-theme-btn svg, .oh-perf-btn svg { width: 14px; height: 14px; }
.oh-perf-btn.active { background: rgba(251, 120, 16, 0.25); color: #FF9640; }

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
.oh-theme-btn-top:hover, .oh-perf-btn-top:hover {
  background: var(--border-local);
  color: var(--ink-900);
}
.oh-perf-btn-top.active {
  background: var(--passion-100);
  color: var(--passion-700);
  border: 1px solid rgba(251, 120, 16, 0.3);
}
.oh-perf-btn-top svg { width: 15px; height: 15px; }
.oh-theme-btn-top svg { width: 18px; height: 18px; }
.perf-tag { font-family: var(--font-heading); font-size: 11px; }

.oh-shell-content {
  flex: 1;
  overflow: auto;
  display: flex;
  flex-direction: column;
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
  .oh-nav-label { font-size: 9px; margin: 4px 0 2px 6px; }
  .oh-avatar-sm { width: 24px; height: 24px; font-size: 10px; }
  .oh-sidebar-foot-info .name { font-size: 11.5px; }
  .oh-sidebar-foot-info .role { font-size: 9.5px; }
  .oh-sidebar-foot { padding: 8px 6px; gap: 6px; }
  .oh-logout-btn, .oh-theme-btn, .oh-perf-btn { width: 24px; height: 24px; }
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
</style>
