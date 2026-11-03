<script setup>
import { ref } from 'vue';
import { useAuthStore } from '../stores/auth';
import { useCatalogStore } from '../stores/catalog';
import { useThemeStore } from '../stores/theme';
import { useRoute, useRouter } from 'vue-router';
import { hasAttendanceDevice, deviceInfo } from '../services/attendanceKiosk';

const route = useRoute();
// Cuentas de ejemplo solo en desarrollo: en producción el formulario empieza vacío
const email = ref(typeof route.query.email === 'string' ? route.query.email : (import.meta.env.DEV ? 'admin@example.com' : ''));
const password = ref(import.meta.env.DEV && !route.query.email ? 'password' : '');
// Al volver de «Recuperar contraseña»
const passwordChanged = route.query.restablecida === '1';
const errorMsg = ref('');
const formErrors = ref({});
const isLoading = ref(false);

const auth = useAuthStore();
const catalog = useCatalogStore();
const theme = useThemeStore();
const router = useRouter();
// Reloj de asistencia: solo en el equipo autorizado (docs/PLAN_ASISTENCIA_Y_SUELDOS.md)
const attendanceDevice = hasAttendanceDevice() ? deviceInfo() : null;

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

      <div v-if="passwordChanged && !errorMsg" class="login-ok" role="status">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
        <span>Listo: tu contraseña se cambió. Inicia sesión con la nueva.</span>
      </div>

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
        <input type="password" v-model="password" required placeholder="••••••••" autocomplete="current-password" :class="{'has-error': formErrors.password}" />
        <span v-if="formErrors.password" class="error-text">{{ formErrors.password[0] }}</span>
        <router-link class="login-forgot" :to="{ path: '/recuperar-contrasena', query: email ? { email } : {} }">¿Olvidaste tu contraseña?</router-link>
      </div>

      <button type="submit" class="btn btn-primary" :disabled="isLoading">
        <svg v-if="isLoading" class="spinner" viewBox="0 0 50 50">
          <circle class="path" cx="25" cy="25" r="20" fill="none" stroke-width="5"></circle>
        </svg>
        {{ isLoading ? 'Conectando...' : 'Iniciar Turno' }}
      </button>

      <template v-if="attendanceDevice">
        <div class="login-or" aria-hidden="true"><span></span>o<span></span></div>
        <router-link to="/asistencia/reloj" class="login-clock-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
          Marcar asistencia
        </router-link>
        <p class="login-clock-hint">Entrada y salida del personal, sin iniciar sesión.</p>
      </template>

      <div class="offline-banner">
        <span>📶</span>
        <span>Sin conexión: el sistema funciona offline y sincronizará al restaurar la red.</span>
      </div>
    </form>
  </div>
</template>

<style scoped>
.login-or { display: flex; align-items: center; gap: 10px; margin: 14px 0 10px; color: var(--ink-500); font-size: 12px; }
.login-or span { flex: 1; height: 1px; background: var(--border); }
.login-clock-btn {
  display: flex; align-items: center; justify-content: center; gap: 10px; height: 46px; border-radius: 12px;
  border: 2px solid var(--acai-700); background: var(--acai-50); color: var(--acai-text); font-weight: 700; font-size: 15px; text-decoration: none;
}
.login-clock-btn svg { width: 20px; height: 20px; }
.login-clock-btn:hover { filter: brightness(0.97); }
.login-clock-btn:focus-visible { outline: 2px solid var(--passion-400); outline-offset: 2px; }
.login-clock-hint { margin: 6px 0 0; text-align: center; font-size: 12px; color: var(--ink-500); }
html.dark .login-clock-btn { background: rgba(116, 72, 166, 0.22); border-color: var(--acai-300); }

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

.login-forgot {
  display: inline-block;
  margin-top: 8px;
  font-size: 13px;
  font-weight: 600;
  color: var(--acai-text);
  text-decoration: underline;
  text-underline-offset: 3px;
}
.login-forgot:focus-visible { outline: 3px solid var(--acai-500); outline-offset: 2px; border-radius: 4px; }
.login-ok {
  margin: 0 0 16px;
  padding: 12px 14px;
  border-radius: 12px;
  background: var(--lime-100);
  color: var(--lime-700);
  font-size: 13px;
  font-weight: 600;
  display: flex;
  gap: 8px;
  align-items: flex-start;
}
.login-ok svg { width: 18px; height: 18px; flex-shrink: 0; }
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
