<template>
  <div class="sin-acceso-container">
    <div class="sin-acceso-card">
      <div class="icon-wrapper">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"></circle>
          <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
        </svg>
      </div>

      <h1>Acceso no disponible</h1>
      <p class="description">
        Tu usuario (<strong>{{ auth.user?.name || 'Usuario' }}</strong> — rol <em>{{ roleLabel }}</em>) no tiene módulos habilitados para operar en este momento.
      </p>
      <p class="subtext">
        Si crees que esto es un error, solicita al Super Administrador que asigne los permisos correspondientes a tu rol en la matriz de configuración.
      </p>

      <div class="actions">
        <button class="btn-logout" @click="handleLogout">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <polyline points="16 17 21 12 16 7"></polyline>
            <line x1="21" y1="12" x2="9" y2="12"></line>
          </svg>
          Cerrar sesión
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const auth = useAuthStore();

const roleLabel = computed(() => {
  const role = auth.user?.role;
  const labels = {
    super_admin: 'Super Admin',
    admin: 'Administrador operativo',
    cashier: 'Cajero',
    kitchen: 'Cocina',
  };
  return labels[role] || role || 'Desconocido';
});

async function handleLogout() {
  await auth.logout();
  router.push('/login');
}
</script>

<style scoped>
.sin-acceso-container {
  /* Va dentro del contenido del menú (o de #app sin menú): llena el alto disponible sin pasar de él */
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
  background: var(--cream, #FBF8F4);
  font-family: inherit;
}

html.dark .sin-acceso-container {
  background: var(--bg-primary, #18141D);
}

.sin-acceso-card {
  max-width: 480px;
  width: 100%;
  background: var(--surface, #FFFFFF);
  border-radius: 16px;
  padding: 2.5rem 2rem;
  text-align: center;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
  border: 1px solid rgba(0, 0, 0, 0.06);
}

html.dark .sin-acceso-card {
  background: var(--bg-surface, #231C28);
  border-color: rgba(255, 255, 255, 0.08);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
}

.icon-wrapper {
  width: 64px;
  height: 64px;
  margin: 0 auto 1.5rem;
  border-radius: 50%;
  background: var(--danger-pale, #FDE8E8);
  color: var(--danger, #DC2626);
  display: flex;
  align-items: center;
  justify-content: center;
}

.icon-wrapper svg {
  width: 32px;
  height: 32px;
}

h1 {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--text-primary, #1A1A1A);
  margin-bottom: 0.75rem;
}

html.dark h1 {
  color: var(--text-primary, #F3EFF5);
}

.description {
  font-size: 0.95rem;
  color: var(--text-secondary, #4A4A4A);
  line-height: 1.5;
  margin-bottom: 0.75rem;
}

html.dark .description {
  color: var(--text-secondary, #B8B0BD);
}

.subtext {
  font-size: 0.85rem;
  color: var(--text-muted, #7A7A7A);
  line-height: 1.4;
  margin-bottom: 2rem;
}

html.dark .subtext {
  color: var(--text-muted, #8D8494);
}

.actions {
  display: flex;
  justify-content: center;
}

.btn-logout {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  background: var(--acai, #4B2E52);
  color: #FFFFFF;
  border: none;
  padding: 0.75rem 1.5rem;
  border-radius: 10px;
  font-size: 0.95rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
}

.btn-logout:hover {
  background: var(--acai-light, #6B4574);
  transform: translateY(-1px);
}
</style>
