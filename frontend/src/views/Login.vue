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
    
    // Al loguearse exitosamente, descargar el catálogo (Caché local)
    // Esto es vital para el soporte Offline-First
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
  <div class="login-container">
    <form @submit.prevent="handleLogin" class="glass-panel login-form">
      <h1 class="login-title">Ohana Açaí POS</h1>
      <p class="login-subtitle">Sistema de Gestión y Caja</p>
      
      <div v-if="errorMsg" class="alert-danger">{{ errorMsg }}</div>
      
      <div class="form-group">
        <label>Email del Cajero</label>
        <input type="email" v-model="email" required class="form-input" placeholder="ejemplo@ohana.com"/>
      </div>
      
      <div class="form-group">
        <label>Contraseña o PIN</label>
        <input type="password" v-model="password" required class="form-input" placeholder="••••••••"/>
      </div>
      
      <button type="submit" class="btn btn-primary login-btn" :disabled="isLoading">
        {{ isLoading ? 'Conectando...' : 'Iniciar Turno' }}
      </button>
    </form>
  </div>
</template>

<style scoped>
.login-container {
  display: flex;
  justify-content: center;
  align-items: center;
  height: 100vh;
  /* Fondo vibrante y moderno */
  background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
}

.login-form {
  padding: 3rem;
  width: 100%;
  max-width: 420px;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.login-title {
  text-align: center;
  font-size: 2rem;
  font-weight: 800;
  margin-bottom: 0.2rem;
  color: var(--text-primary);
}
.login-subtitle {
  text-align: center;
  font-size: 1rem;
  color: var(--text-secondary);
  margin-bottom: 1rem;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.form-group label {
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--text-primary);
}

.form-input {
  padding: 0.85rem;
  border-radius: 0.5rem;
  border: 1px solid var(--border-color);
  font-size: 1rem;
  outline: none;
  transition: all 0.2s ease;
  background-color: var(--bg-secondary);
  color: var(--text-primary);
}
.form-input:focus {
  border-color: var(--color-primary);
  box-shadow: 0 0 0 3px rgba(140, 82, 255, 0.2);
}

.login-btn {
  margin-top: 1rem;
  font-size: 1.1rem;
  padding: 1rem;
  border-radius: 0.75rem;
}

.alert-danger {
  background-color: #FEE2E2;
  color: var(--color-danger);
  padding: 0.75rem;
  border-radius: 0.5rem;
  font-size: 0.9rem;
  text-align: center;
  font-weight: 500;
}
</style>
