import { defineStore } from 'pinia';
import { apiFetch } from '../services/api';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: localStorage.getItem('auth_token') || null,
  }),
  getters: {
    isAuthenticated: (state) => !!state.token,
  },
  actions: {
    async login(email, password) {
      const response = await apiFetch('/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
      });
      
      this.token = response.token;
      this.user = response.user;
      localStorage.setItem('auth_token', response.token);
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
        localStorage.removeItem('auth_token');
      }
    },
    
    async fetchUser() {
      if (!this.token) return;
      try {
        const user = await apiFetch('/me');
        this.user = user;
      } catch (e) {
        console.error('Sesión inválida', e);
        this.token = null;
        this.user = null;
        localStorage.removeItem('auth_token');
      }
    }
  }
});
