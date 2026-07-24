<script setup>
import { ref } from 'vue';
import { useAuthStore } from '../stores/auth';
import { useCatalogStore } from '../stores/catalog';
import { useThemeStore } from '../stores/theme';
import { useRouter } from 'vue-router';

const email = ref('admin@example.com');
const password = ref('password');
const errorMsg = ref('');
const formErrors = ref({});
const isLoading = ref(false);

const auth = useAuthStore();
const catalog = useCatalogStore();
const theme = useThemeStore();
const router = useRouter();

async function handleLogin() {
  errorMsg.value = '';
  isLoading.value = true;
  try {
    await auth.login(email.value, password.value);
    // Offline-First: descarga el catálogo al iniciar sesión
    await catalog.fetchAndCache();
    router.push('/pos');
  } catch (e) {
    if (e.validationErrors) {
      formErrors.value = e.validationErrors;
    } else {
      errorMsg.value = e.message || 'Credenciales incorrectas';
    }
  } finally {
    isLoading.value = false;
  }
}
</script>

<template>
  <div class="login-screen">
    <!-- Botón Toggle de Tema -->
    <button type="button" class="login-theme-toggle" @click="theme.toggleTheme()" :title="theme.isDark ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro'">
      <svg v-if="theme.isDark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
      <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
      </svg>
    </button>

    <form @submit.prevent="handleLogin" class="login-card">
      <div class="login-logo"></div>
      <h1>Ohana Açaí POS</h1>
      <p class="tagline">Sistema de Gestión y Caja</p>

      <div v-if="errorMsg" class="error-banner">
        <span>⚠️</span>
        <span>{{ errorMsg }}</span>
      </div>

      <div class="field">
        <label>Email del cajero</label>
        <input type="email" v-model="email" required placeholder="cajero@ohana.com" :class="{'has-error': formErrors.email}" />
        <span v-if="formErrors.email" class="error-text">{{ formErrors.email[0] }}</span>
      </div>

      <div class="field">
        <label>Contraseña o PIN</label>
        <input type="password" v-model="password" required placeholder="••••••••" :class="{'has-error': formErrors.password}" />
        <span v-if="formErrors.password" class="error-text">{{ formErrors.password[0] }}</span>
      </div>

      <button type="submit" class="btn btn-primary" :disabled="isLoading">
        <svg v-if="isLoading" class="spinner" viewBox="0 0 50 50">
          <circle class="path" cx="25" cy="25" r="20" fill="none" stroke-width="5"></circle>
        </svg>
        {{ isLoading ? 'Conectando...' : 'Iniciar Turno' }}
      </button>

      <div class="offline-banner">
        <span>📶</span>
        <span>Sin conexión: el sistema funciona offline y sincronizará al restaurar la red.</span>
      </div>
    </form>
  </div>
</template>

<style scoped>
.login-screen {
  min-height: 100vh;
  position: relative;
  background:
    radial-gradient(circle at 15% 20%, rgba(140,63,136,0.55) 0, transparent 45%),
    radial-gradient(circle at 85% 80%, rgba(255,107,69,0.35) 0, transparent 45%),
    var(--acai-900);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px;
}

/* All other classes (.login-card, .login-logo, .field, .btn, .offline-banner)
   come from the global style.css extracted from the prototype */

.has-error {
  border-color: var(--danger-500) !important;
  background-color: var(--danger-100) !important;
}
.error-text {
  color: var(--danger-600);
  font-size: 11px;
  margin-top: 4px;
  display: block;
}
.spinner {
  animation: rotate 2s linear infinite;
  width: 18px;
  height: 18px;
  margin-right: 8px;
  vertical-align: middle;
}
.spinner .path {
  stroke: white;
  stroke-linecap: round;
  animation: dash 1.5s ease-in-out infinite;
}
@keyframes rotate {
  100% { transform: rotate(360deg); }
}
@keyframes dash {
  0% { stroke-dasharray: 1, 150; stroke-dashoffset: 0; }
  50% { stroke-dasharray: 90, 150; stroke-dashoffset: -35; }
  100% { stroke-dasharray: 90, 150; stroke-dashoffset: -124; }
}
</style>
