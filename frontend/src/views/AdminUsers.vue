<template>
  <div class="oh-main">
    <!-- Encabezado compacto común a las dos pestañas -->
    <div class="oh-topbar">
      <div class="oh-topbar-left">
        <h1 class="oh-title-text">Usuarios y permisos</h1>
        <div class="oh-tabs" role="tablist">
          <button
            type="button"
            class="oh-tab"
            :class="{ active: activeTab === 'usuarios' }"
            @click="activeTab = 'usuarios'"
          >
            Usuarios
          </button>
          <button
            type="button"
            class="oh-tab"
            :class="{ active: activeTab === 'permisos' }"
            @click="activeTab = 'permisos'"
          >
            Roles y permisos
          </button>
        </div>
      </div>

      <!-- Acciones de la pestaña Usuarios -->
      <div class="oh-topbar-actions" v-if="activeTab === 'usuarios'">
        <div class="oh-search">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
          <input v-model="searchQuery" placeholder="Buscar por nombre o CI..." />
        </div>
        <button v-if="isSuperAdmin" class="oh-btn oh-btn-primary" @click="openModal()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14"/></svg>
          Nuevo usuario
        </button>
      </div>

      <!-- Acciones de la pestaña Permisos (en la barra superior, a la derecha) -->
      <div class="oh-topbar-actions perm-topbar-actions" v-else-if="activeTab === 'permisos' && isSuperAdmin && permPanelRef">
        <!-- Mensaje de éxito al guardar -->
        <span v-if="permPanelRef.saveSuccessMessage" class="status-saved">
          {{ permPanelRef.saveSuccessMessage }}
        </span>

        <!-- Aviso si algún rol queda sin pantallas -->
        <span v-else-if="permPanelRef.roleWithoutScreensNotice" class="status-warning">
          {{ permPanelRef.roleWithoutScreensNotice }}
        </span>

        <!-- Contador de cambios pendientes -->
        <span v-else-if="permPanelRef.hasChanges" class="status-changes">
          <span class="diff-dot"></span>
          {{ permPanelRef.totalChangesCount }} {{ permPanelRef.totalChangesCount === 1 ? 'cambio sin guardar' : 'cambios sin guardar' }}
        </span>

        <button
          type="button"
          class="btn-discard"
          :disabled="!permPanelRef.hasChanges || permPanelRef.isSaving"
          @click="permPanelRef.discardChanges()"
        >
          Descartar
        </button>

        <button
          type="button"
          class="btn-save"
          :disabled="!permPanelRef.hasChanges || permPanelRef.isSaving"
          @click="permPanelRef.saveChanges()"
        >
          <span v-if="permPanelRef.isSaving">Guardando...</span>
          <span v-else>Guardar cambios</span>
        </button>
      </div>
    </div>

    <div class="oh-content">
      <!-- ===== TAB: USUARIOS ===== -->
      <section v-show="activeTab === 'usuarios'" class="oh-panel active">
        <div class="oh-card">
          <table class="oh-table">
            <thead>
              <tr>
                <th>Nombre</th>
                <th>CI</th>
                <th>Teléfono</th>
                <th>Rol</th>
                <th>Ingreso</th>
                <th>Estado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="u in filteredUsers" :key="u.id">
                <td>
                  <div class="oh-user-cell">
                    <div class="oh-avatar" :style="{background: ROLE_META[u.role]?.color}">{{ getInitials(u.name) }}</div>
                    <div>
                      <div class="oh-user-name">{{ u.name }}</div>
                      <div class="oh-user-username oh-mono">@{{ (u.email || u.name).split('@')[0].toLowerCase() }}</div>
                    </div>
                  </div>
                </td>
                <td class="oh-mono">{{ u.ci || '-' }}</td>
                <td class="oh-mono">{{ u.phone || '-' }}</td>
                <td>
                  <span class="oh-badge" :class="ROLE_META[u.role]?.cls">
                    <span class="oh-badge-dot" style="background:currentColor"></span>
                    {{ ROLE_META[u.role]?.label }}
                  </span>
                </td>
                <td>{{ u.start_date || '-' }}</td>
                <td>
                  <span class="oh-status ok"><span class="dot"></span>Activo</span>
                </td>
                <td>
                  <div class="oh-row-actions">
                    <button v-if="isSuperAdmin" class="oh-icon-btn" title="Editar" @click="openModal(u)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/></svg>
                    </button>
                    <button v-if="canResetPassword(u)" class="oh-icon-btn" title="Resetear contraseña" @click="openResetModal(u)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2l-9.6 9.6M15.5 7.5L18 10M18.5 6.5L21 9"/></svg>
                    </button>
                    <button v-if="isSuperAdmin && authStore.user?.id !== u.id" class="oh-icon-btn" title="Desactivar/Eliminar" @click="deleteUser(u.id)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M4.9 4.9l14.2 14.2"/></svg>
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="filteredUsers.length === 0">
                <td colspan="7" class="text-center py-4 text-gray-500" style="text-align: center;">No se encontraron usuarios.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ===== TAB: ROLES Y PERMISOS ===== -->
      <section v-show="activeTab === 'permisos'" class="oh-panel active">
        <RolePermissionsPanel v-if="isSuperAdmin" ref="permPanelRef" :users="users" />
        <div v-else class="text-red-500 mt-4">
          No tienes permisos para ver o editar la matriz de roles.
        </div>
      </section>
    </div>

    <!-- ===== MODAL: NUEVO / EDITAR USUARIO ===== -->
    <div class="oh-modal-overlay" :class="{open: showUserModal}" @click.self="showUserModal = false">
      <div class="oh-modal">
        <h2>{{ form.id ? 'Editar usuario' : 'Nuevo usuario' }}</h2>
        <p class="sub" v-if="!form.id">Se pedirá cambiar la contraseña temporal en el primer inicio de sesión.</p>
        <p class="sub" v-else>Actualiza los datos del usuario.</p>

        <form @submit.prevent="saveUser">
          <div class="oh-form-row">
            <div class="oh-form-group">
              <label>Nombre completo</label>
              <input v-model="form.name" required placeholder="Ej: María Choque" :class="{'has-error': formErrors.name}" />
              <span v-if="formErrors.name" class="error-text">{{ formErrors.name[0] }}</span>
            </div>
          </div>
          <div class="oh-form-row">
            <div class="oh-form-group">
              <label>CI</label>
              <input v-model="form.ci" placeholder="Ej: 5551234 LP" :class="{'has-error': formErrors.ci}" />
              <span v-if="formErrors.ci" class="error-text">{{ formErrors.ci[0] }}</span>
            </div>
            <div class="oh-form-group">
              <label>Teléfono</label>
              <input v-model="form.phone" placeholder="Ej: 71234567" :class="{'has-error': formErrors.phone}" />
              <span v-if="formErrors.phone" class="error-text">{{ formErrors.phone[0] }}</span>
            </div>
          </div>
          <div class="oh-form-row">
            <div class="oh-form-group">
              <label>Fecha de ingreso</label>
              <input v-model="form.start_date" type="date" :class="{'has-error': formErrors.start_date}" />
              <span v-if="formErrors.start_date" class="error-text">{{ formErrors.start_date[0] }}</span>
            </div>
            <div class="oh-form-group">
              <label>Rol</label>
              <select v-model="form.role" required :class="{'has-error': formErrors.role}">
                <option value="cashier">Cajero</option>
                <option value="admin">Administrador operativo</option>
                <option value="kitchen">Cocina (KDS)</option>
                <option value="super_admin">Super Admin</option>
              </select>
              <span v-if="formErrors.role" class="error-text">{{ formErrors.role[0] }}</span>
            </div>
          </div>
          <div class="oh-form-row">
            <div class="oh-form-group">
              <label>Email / Usuario</label>
              <input v-model="form.email" required type="email" placeholder="Se sugiere desde el nombre" :class="{'has-error': formErrors.email}" />
              <span v-if="formErrors.email" class="error-text">{{ formErrors.email[0] }}</span>
            </div>
            <div class="oh-form-group" v-if="!form.id">
              <label>Contraseña temporal</label>
              <input v-model="form.password" required class="oh-mono" :class="{'has-error': formErrors.password}" />
              <span v-if="formErrors.password" class="error-text">{{ formErrors.password[0] }}</span>
            </div>
          </div>

          <div class="oh-modal-actions">
            <button type="button" class="oh-btn oh-btn-ghost" @click="showUserModal = false">Cancelar</button>
            <button type="submit" class="oh-btn oh-btn-primary">Guardar usuario</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ===== MODAL: RESET PASSWORD ===== -->
    <div class="oh-modal-overlay" :class="{open: showResetModal}" @click.self="showResetModal = false">
      <div class="oh-modal">
        <h2>Resetear Contraseña</h2>
        <p class="sub">Usuario: {{ selectedUser?.name }}</p>

        <form @submit.prevent="resetPassword">
          <div class="oh-form-row">
            <div class="oh-form-group">
              <label>Nueva Contraseña</label>
              <input v-model="resetForm.password" required minlength="6" type="password" class="oh-mono" :class="{'has-error': formErrors.password}" />
              <span v-if="formErrors.password" class="error-text">{{ formErrors.password[0] }}</span>
            </div>
          </div>
          <div class="oh-modal-actions">
            <button type="button" class="oh-btn oh-btn-ghost" @click="showResetModal = false">Cancelar</button>
            <button type="submit" class="oh-btn oh-btn-primary" style="background:var(--amber);">Actualizar</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Toast de Notificaciones -->
    <div class="oh-toast" :class="{show: toast.show}">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
      <span>{{ toast.message }}</span>
    </div>

    <!-- Modal de Confirmación Custom -->
    <div v-if="showConfirmModal" class="modal-backdrop" style="z-index: 1000;">
      <div class="modal-content" style="max-width: 400px; text-align: center;">
        <h3 style="color: var(--danger); display: flex; align-items: center; justify-content: center; gap: 8px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
          Confirmar Acción
        </h3>
        <p class="text-muted" style="margin-bottom: 24px;">{{ confirmMessage }}</p>
        <div class="oh-modal-actions" style="justify-content: center;">
          <button type="button" class="oh-btn-ghost" @click="showConfirmModal = false">Cancelar</button>
          <button type="button" class="oh-btn-primary" style="background: var(--danger);" @click="executeConfirm">Sí, Continuar</button>
        </div>
      </div>
    </div>

    <!-- Modal de Alerta Custom -->
    <div v-if="showAlertModal" class="modal-backdrop" style="z-index: 1000;">
      <div class="modal-content" style="max-width: 400px; text-align: center;">
        <h3 style="color: #f59e0b; display: flex; align-items: center; justify-content: center; gap: 8px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
          Atención
        </h3>
        <p class="text-muted" style="margin-bottom: 24px;">{{ alertMessage }}</p>
        <div class="oh-modal-actions" style="justify-content: center;">
          <button type="button" class="oh-btn-primary" @click="showAlertModal = false">Entendido</button>
        </div>
      </div>
    </div>

  </div><!-- /oh-main -->
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useAuthStore } from '../stores/auth';
import { apiFetch } from '../services/api';
import RolePermissionsPanel from '../components/permissions/RolePermissionsPanel.vue';

const authStore = useAuthStore();

// UI State
const activeTab = ref('usuarios');
const permPanelRef = ref(null);
const searchQuery = ref('');
const showUserModal = ref(false);
const showResetModal = ref(false);
const toast = ref({ show: false, message: '' });

// Custom Modals State
const showConfirmModal = ref(false);
const confirmMessage = ref('');
const confirmCallback = ref(null);
const showAlertModal = ref(false);
const alertMessage = ref('');

function confirmAction(message, callback) {
  confirmMessage.value = message;
  confirmCallback.value = callback;
  showConfirmModal.value = true;
}
function executeConfirm() {
  if (confirmCallback.value) confirmCallback.value();
  showConfirmModal.value = false;
}
function alertAction(message) {
  alertMessage.value = message;
  showAlertModal.value = true;
}

// Constants
const ROLE_META = {
  cashier: { label: 'Cajero', cls: 'role-cashier', color: '#5C7A3F' },
  admin: { label: 'Administrador operativo', cls: 'role-admin', color: '#8B5E3C' },
  super_admin: { label: 'Super Admin', cls: 'role-super', color: '#4B2E52' },
  kitchen: { label: 'Cocina', cls: 'role-kitchen', color: '#B4532A' }
};

// Data
const users = ref([]);
const selectedUser = ref(null);
const form = ref({ id: null, name: '', email: '', role: 'cashier', ci: '', phone: '', start_date: '', password: '' });
const resetForm = ref({ password: '' });
const formErrors = ref({});

// Computed
const isSuperAdmin = computed(() => authStore.user?.role === 'super_admin');
const isAdmin = computed(() => authStore.user?.role === 'admin');

const filteredUsers = computed(() => {
  const q = searchQuery.value.toLowerCase();
  return users.value.filter(u => 
    !q || u.name.toLowerCase().includes(q) || (u.ci && u.ci.toLowerCase().includes(q)) || u.email.toLowerCase().includes(q)
  );
});

// Methods
function getInitials(name) {
  const parts = name.split(' ');
  return parts.length > 1 ? (parts[0][0] + parts[1][0]).toUpperCase() : name.substring(0, 2).toUpperCase();
}

function showToastMsg(msg) {
  toast.value.message = msg;
  toast.value.show = true;
  setTimeout(() => { toast.value.show = false; }, 2200);
}

const fetchUsers = async () => {
  try {
    const res = await apiFetch('/users');
    users.value = res.data || res;
  } catch (e) {
    console.error('Error al obtener usuarios:', e);
  }
};

const openModal = (user = null) => {
  if (user) {
    form.value = { ...user, password: '' };
  } else {
    form.value = { id: null, name: '', email: '', role: 'cashier', ci: '', phone: '', start_date: '', password: '' };
  }
  formErrors.value = {};
  showUserModal.value = true;
};

const saveUser = async () => {
  try {
    const payload = { ...form.value };
    if (form.value.id && !payload.password) {
      delete payload.password;
    }

    if (form.value.id) {
      await apiFetch(`/users/${form.value.id}`, { method: 'PUT', body: JSON.stringify(payload) });
      showToastMsg('Usuario actualizado');
    } else {
      await apiFetch('/users', { method: 'POST', body: JSON.stringify(payload) });
      showToastMsg('Usuario creado');
    }
    showUserModal.value = false;
    await fetchUsers();
  } catch (e) {
    if (e.validationErrors) {
      formErrors.value = e.validationErrors;
    } else {
      alertAction(e.message || 'Error al guardar el usuario');
    }
  }
};

const openResetModal = (user) => {
  selectedUser.value = user;
  resetForm.value.password = '';
  formErrors.value = {};
  showResetModal.value = true;
};

const resetPassword = async () => {
  try {
    await apiFetch(`/users/${selectedUser.value.id}/reset-password`, {
      method: 'POST',
      body: JSON.stringify({ password: resetForm.value.password })
    });
    showToastMsg('Contraseña actualizada');
    showResetModal.value = false;
  } catch (e) {
    if (e.validationErrors) {
      formErrors.value = e.validationErrors;
    } else {
      alertAction(e.message || 'Error al actualizar contraseña');
    }
  }
};

const deleteUser = async (id) => {
  confirmAction('¿Estás seguro de que deseas desactivar/eliminar este usuario?', async () => {
    try {
      await apiFetch(`/users/${id}`, { method: 'DELETE' });
      await fetchUsers();
      showToastMsg('Usuario eliminado');
    } catch (e) {
      alertAction('Error al eliminar usuario');
    }
  });
};

const canResetPassword = (targetUser) => {
  if (isSuperAdmin.value) return true;
  if (isAdmin.value && targetUser.role === 'cashier') return true;
  return false;
};

onMounted(async () => {
  await fetchUsers();
});
</script>

<style scoped>
.oh-mono { font-family: 'JetBrains Mono', monospace; }

/* ---------- MAIN ---------- */
.oh-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
  width: 100%;
}

.oh-topbar {
  padding: 12px 24px 0 24px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}

.oh-topbar-left {
  display: flex;
  align-items: center;
  gap: 24px;
  flex-wrap: wrap;
}

.oh-title-text {
  font-family: 'Baloo 2', cursive, sans-serif;
  font-size: 22px;
  font-weight: 600;
  line-height: 1.2;
  margin: 0;
  color: var(--ink);
  letter-spacing: 0.1px;
}

.oh-tabs {
  display: flex;
  gap: 6px;
  border-bottom: none;
}

.oh-tab {
  height: 36px;
  padding: 0 12px;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--ink-500);
  border: none;
  border-bottom: 2px solid transparent;
  background: transparent;
  cursor: pointer;
  user-select: none;
  transition: all 0.15s ease;
}

.oh-tab:hover {
  color: var(--ink);
}

.oh-tab.active {
  color: var(--ink);
  border-bottom-color: var(--acai-700);
}

.oh-topbar-actions {
  display: flex;
  gap: 10px;
  align-items: center;
}

.oh-search {
  display: flex;
  align-items: center;
  gap: 7px;
  background: var(--surface);
  border: 1px solid var(--cream-200);
  border-radius: var(--radius-sm, 6px);
  padding: 6px 12px;
  min-width: 200px;
}

.oh-search svg {
  width: 15px;
  height: 15px;
  color: var(--ink-500);
  flex-shrink: 0;
}

.oh-search input {
  border: none;
  outline: none;
  font-size: 13px;
  background: transparent;
  width: 100%;
  font-family: inherit;
  color: var(--ink);
}

.oh-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  border: none;
  border-radius: var(--radius-sm, 6px);
  padding: 8px 14px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  transition: transform 0.12s ease, box-shadow 0.12s ease;
}

.oh-btn:active {
  transform: translateY(1px);
}

.oh-btn svg {
  width: 15px;
  height: 15px;
}

.oh-btn-primary {
  background: var(--acai-700);
  color: #fff;
  box-shadow: 0 4px 12px rgba(75, 46, 82, 0.25);
}

.oh-btn-primary:hover {
  background: var(--acai-800);
}

.oh-btn-ghost {
  background: transparent;
  color: var(--ink-500);
  border: 1px solid var(--cream-200);
}

.oh-btn-ghost:hover {
  background: var(--cream-200);
  color: var(--ink);
}

.oh-content {
  padding: 10px 24px 12px 24px;
  overflow: auto;
  flex: 1;
}

.perm-topbar-actions {
  display: inline-flex;
  align-items: center;
  gap: 10px;
}

.status-changes {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: var(--passion-text);
  white-space: nowrap;
}

.diff-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--passion-600);
}

.status-warning {
  font-size: 12.5px;
  font-weight: 600;
  color: var(--danger-text);
  white-space: nowrap;
}

.status-saved {
  font-size: 12.5px;
  font-weight: 600;
  color: #15803D;
  white-space: nowrap;
}

html.dark .status-saved {
  color: #86EFAC;
}

.btn-discard {
  height: 34px;
  padding: 0 12px;
  border: 1px solid var(--ink-300);
  border-radius: var(--radius-sm, 6px);
  background: var(--surface);
  color: var(--ink);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s ease;
}

.btn-discard:hover:not(:disabled) {
  background: var(--cream-100);
}

.btn-discard:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.btn-save {
  height: 34px;
  padding: 0 16px;
  border: none;
  border-radius: var(--radius-sm, 6px);
  background: var(--passion-500, #F97316);
  color: var(--on-accent, #ffffff);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s ease;
  box-shadow: 0 1px 3px rgba(249, 115, 22, 0.25);
}

.btn-save:hover:not(:disabled) {
  filter: brightness(1.06);
}

.btn-save:disabled {
  opacity: 0.45;
  cursor: not-allowed;
  box-shadow: none;
}

.oh-panel {
  display: none;
}

.oh-panel.active {
  display: block;
}

/* ---------- TABLE USUARIOS ---------- */
.oh-card {
  background: var(--surface);
  border: 1px solid var(--cream-200);
  border-radius: var(--radius-md, 8px);
  overflow: hidden;
  box-shadow: var(--shadow-card);
}

table.oh-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

table.oh-table thead th {
  text-align: left;
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.9px;
  color: var(--ink-500);
  font-weight: 700;
  padding: 10px 14px;
  border-bottom: 1px solid var(--cream-200);
  background: var(--cream-100);
}

table.oh-table tbody td {
  padding: 10px 14px;
  border-bottom: 1px solid var(--cream-200);
  vertical-align: middle;
}

table.oh-table tbody tr:last-child td {
  border-bottom: none;
}

table.oh-table tbody tr:hover {
  background: var(--cream-100);
}

.oh-user-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.oh-avatar {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11.5px;
  font-weight: 700;
  color: #fff;
  flex-shrink: 0;
}

.oh-user-name {
  font-weight: 600;
  font-size: 13px;
}

.oh-user-username {
  font-size: 11.5px;
  color: var(--ink-500);
}

.oh-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 8px;
  border-radius: 100px;
  font-size: 11.5px;
  font-weight: 700;
  white-space: nowrap;
}

.oh-badge-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
}

.oh-badge.role-cashier { background: #EAF0E2; color: #5C7A3F; }
.oh-badge.role-admin { background: #F3E9DE; color: #8B5E3C; }
.oh-badge.role-super { background: #EFE7F0; color: #4B2E52; }
.oh-badge.role-kitchen { background: #FBF0DE; color: #B4532A; }

html.dark .oh-badge.role-super { background: #3A2560; color: #D9C6F5; }
html.dark .oh-badge.role-kitchen { color: #F2C27B; background: rgba(180, 83, 42, 0.2); }
html.dark .oh-badge.role-admin { color: #E0B48E; background: rgba(139, 94, 60, 0.2); }
html.dark .oh-badge.role-cashier { color: #A9D18A; background: rgba(92, 122, 63, 0.2); }

.oh-status {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 600;
}

.oh-status .dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
}

.oh-status.ok .dot { background: #15803D; }
.oh-status.ok { color: #15803D; }
.oh-status.blocked .dot { background: #DC2626; }
.oh-status.blocked { color: #DC2626; }

.oh-row-actions {
  display: flex;
  gap: 4px;
  justify-content: flex-end;
}

.oh-icon-btn {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  border: 1px solid var(--cream-200);
  background: var(--surface);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: var(--ink-500);
}

.oh-icon-btn:hover {
  background: var(--cream-200);
  color: var(--ink);
}

.oh-icon-btn svg {
  width: 14px;
  height: 14px;
}

/* ---------- MODAL ---------- */
.oh-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(36, 28, 41, 0.45);
  display: none;
  align-items: center;
  justify-content: center;
  padding: 20px;
  z-index: 20;
}

.oh-modal-overlay.open {
  display: flex;
}

.oh-modal {
  background: var(--surface);
  border-radius: 12px;
  width: 100%;
  max-width: 480px;
  padding: 24px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
}

.oh-modal h2 {
  font-family: 'Baloo 2', cursive, sans-serif;
  font-size: 19px;
  font-weight: 600;
  margin: 0 0 4px 0;
}

.oh-modal .sub {
  font-size: 12.5px;
  color: var(--ink-500);
  margin: 0 0 16px 0;
}

.oh-form-row {
  display: flex;
  gap: 12px;
  margin-bottom: 12px;
}

.oh-form-group {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.oh-form-group label {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--ink-500);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.oh-form-group input,
.oh-form-group select {
  border: 1px solid var(--cream-200);
  border-radius: 6px;
  padding: 8px 10px;
  font-size: 13px;
  font-family: inherit;
  outline: none;
  background: var(--surface);
  color: var(--ink);
}

.oh-form-group input:focus,
.oh-form-group select:focus {
  border-color: var(--acai-700);
}

.oh-modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 16px;
}

.oh-toast {
  position: fixed;
  bottom: 20px;
  left: 50%;
  transform: translateX(-50%) translateY(10px);
  background: var(--ink);
  color: #fff;
  padding: 9px 16px;
  border-radius: 8px;
  font-size: 12.5px;
  font-weight: 600;
  opacity: 0;
  pointer-events: none;
  transition: all 0.25s ease;
  z-index: 30;
  display: flex;
  align-items: center;
  gap: 8px;
}

.oh-toast.show {
  opacity: 1;
  transform: translateX(-50%) translateY(0);
}

.oh-toast svg {
  width: 14px;
  height: 14px;
  color: #86EFAC;
}

.has-error {
  border-color: #DC2626 !important;
  background-color: rgba(220, 38, 38, 0.05);
}

.error-text {
  color: #DC2626;
  font-size: 11px;
  margin-top: 4px;
  display: block;
}

/* Modal confirm backdrop */
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}

.modal-content {
  background: var(--surface);
  border-radius: 12px;
  padding: 24px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
}

/* Modo oscuro */
html.dark .oh-tab.active {
  border-bottom-color: var(--acai-300);
}

html.dark table.oh-table thead th {
  background: rgba(255, 255, 255, 0.04);
}

html.dark table.oh-table tbody tr:hover {
  background: rgba(255, 255, 255, 0.03);
}

html.dark .oh-search {
  background: rgba(255, 255, 255, 0.04);
  border-color: rgba(255, 255, 255, 0.08);
}

html.dark .oh-icon-btn {
  background: rgba(255, 255, 255, 0.04);
  border-color: rgba(255, 255, 255, 0.08);
}

html.dark .oh-icon-btn:hover {
  background: rgba(255, 255, 255, 0.08);
}

/* Responsive */
@media (max-width: 760px) {
  .oh-topbar {
    padding-left: 16px;
    padding-right: 16px;
    flex-direction: column;
    align-items: flex-start;
  }
  .oh-content {
    padding-left: 16px;
    padding-right: 16px;
  }
  .oh-topbar-actions {
    width: 100%;
    justify-content: space-between;
  }
  .oh-search {
    flex: 1;
    min-width: 0;
  }
}
</style>
