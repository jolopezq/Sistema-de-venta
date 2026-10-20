import { defineStore } from 'pinia';
import { apiFetch } from '../services/api';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: localStorage.getItem('auth_token') || null,
    permissions: [],
    effective_permissions: {},
  }),
  getters: {
    isAuthenticated: (state) => !!state.token,
    isSuperAdmin: (state) => state.user?.role === 'super_admin',
    isAdmin: (state) => state.user?.role === 'admin' || state.user?.role === 'super_admin',
    isCashier: (state) => state.user?.role === 'cashier',
    isKitchen: (state) => state.user?.role === 'kitchen',
  },
  actions: {
    hasPermission(module, level = 'read') {
      if (!this.user) return false;
      if (this.user.role === 'super_admin') return true;

      const levelValues = { none: 0, read: 1, edit: 2 };
      const reqVal = levelValues[level];
      if (reqVal === undefined) return false;

      // Lee el mapa precalculado por el backend con el modelo techo + matriz
      const userLevel = this.effective_permissions?.[module];
      const userVal = levelValues[userLevel];
      if (userVal === undefined) return false;

      return userVal >= reqVal;
    },

    async login(email, password) {
      const response = await apiFetch('/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
      });

      this.token = response.access_token;
      this.user = response.user;
      this.permissions = response.permissions || [];
      this.effective_permissions = response.effective_permissions || {};
      localStorage.setItem('auth_token', response.access_token);
      
      try {
        const { useCatalogStore } = await import('./catalog.js');
        const catalog = useCatalogStore();
        catalog.errorMessage = null;
      } catch (_) {}
    },

    async logout() {
      try {
        if (this.token) {
          await apiFetch('/logout', { method: 'POST' });
        }
      } catch (e) {
        console.error('Error al hacer logout', e);
      } finally {
        this.token = null;
        this.user = null;
        this.permissions = [];
        this.effective_permissions = {};
        localStorage.removeItem('auth_token');
      }
    },

    async fetchUser() {
      if (!this.token) return;
      try {
        // /me responde { user, permissions, effective_permissions }
        const response = await apiFetch('/me');
        this.user = response.user;
        this.permissions = response.permissions || [];
        this.effective_permissions = response.effective_permissions || {};
      } catch (e) {
        // Solo cerrar sesión si el servidor responde explícitamente 401 (no autenticado / token revocado).
        // Si hay error de red, timeout, 5xx o el equipo está offline, la sesión local se conserva intacta.
        if (e?.status === 401) {
          console.warn('Sesión no autorizada o expirada (401), cerrando sesión.');
          this.token = null;
          this.user = null;
          this.permissions = [];
          this.effective_permissions = {};
          localStorage.removeItem('auth_token');
        } else {
          console.warn('No se pudo refrescar el usuario en segundo plano (posiblemente offline o fallo de red):', e?.message || e);
        }
      }
    }
  }
});

// Listener global para refrescar permisos cuando la ventana vuelve a tener el foco
let lastFocusRefresh = 0;
if (typeof window !== 'undefined' && !window.__authFocusAttached) {
  window.__authFocusAttached = true;
  window.addEventListener('focus', () => {
    // Si el navegador no tiene conexión de red, no intentar refrescar
    if (typeof navigator !== 'undefined' && !navigator.onLine) {
      return;
    }

    // Limitar el refresco a no más de 1 vez cada 60 segundos
    const now = Date.now();
    if (now - lastFocusRefresh < 60000) {
      return;
    }
    lastFocusRefresh = now;

    const token = localStorage.getItem('auth_token');
    if (token) {
      useAuthStore().fetchUser().catch(() => {});
    }
  });
}
