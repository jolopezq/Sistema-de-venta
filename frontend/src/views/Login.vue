<script setup>
import { ref } from 'vue';
import { useAuthStore } from '../stores/auth';
import { useCatalogStore } from '../stores/catalog';
import { useRouter } from 'vue-router';

const email = ref('admin@example.com');
const password = ref('password');
const errorMsg = ref('');
const isLoading = ref(false);

const auth = useAuthStore();
const catalog = useCatalogStore();
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
    errorMsg.value = e.message || 'Credenciales incorrectas';
  } finally {
    isLoading.value = false;
  }
}
</script>

<template>
  <div class="login-screen">
    <form @submit.prevent="handleLogin" class="login-card">
      <div class="login-logo"></div>
      <h1>Ohana Açaí POS</h1>
      <p class="tagline">Sistema de Gestión y Caja</p>

      <div v-if="errorMsg" class="offline-banner" style="background:var(--danger-100);border-color:var(--danger-500);color:var(--danger-600);">
        ⚠️ {{ errorMsg }}
      </div>

      <div class="field">
        <label>Email del cajero</label>
        <input type="email" v-model="email" required placeholder="cajero@ohana.com" />
      </div>

      <div class="field">
        <label>Contraseña o PIN</label>
        <input type="password" v-model="password" required placeholder="••••••••" />
      </div>

      <button type="submit" class="btn btn-primary" :disabled="isLoading">
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
</style>
