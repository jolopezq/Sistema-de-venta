<template>
  <div class="oh-main">
      <div class="oh-topbar">
        <div class="oh-title">
          <h1>Usuarios y permisos</h1>
          <p>Gestiona quién entra al sistema y qué puede ver o editar cada rol.</p>
        </div>
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
      </div>

      <div class="oh-tabs">
        <div class="oh-tab" :class="{active: activeTab === 'usuarios'}" @click="activeTab = 'usuarios'">Usuarios</div>
        <div class="oh-tab" :class="{active: activeTab === 'permisos'}" @click="activeTab = 'permisos'">Roles y permisos</div>
      </div>

      <div class="oh-content">
        <!-- ===== TAB: USUARIOS ===== -->
        <section class="oh-panel" :class="{active: activeTab === 'usuarios'}">
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
                      <button v-if="isSuperAdmin && authStore.user.id !== u.id" class="oh-icon-btn" title="Desactivar/Eliminar" @click="deleteUser(u.id)">
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

        <!-- ===== TAB: PERMISOS ===== -->
        <section class="oh-panel" :class="{active: activeTab === 'permisos'}">
          <div class="oh-perm-intro">
            <p>Los permisos se definen <b>por rol</b> (no por usuario individual): todos los Administradores operativos comparten el mismo acceso entre sí, igual los Cajeros. Cada módulo puede quedar en <b>Sin acceso</b>, <b>Solo lectura</b> o <b>Edición</b>. Las celdas con borde punteado ámbar son las que aún no se han decidido — tócalas para fijar el nivel.</p>
            <div class="oh-perm-progress">
              <div class="ring" :style="{'--pct': progressPct}"><span>{{ decidedCount }}/{{ totalModules }}</span></div>
              <div class="txt"><b>{{ decidedCount }} de {{ totalModules }}</b> módulos definidos</div>
            </div>
          </div>

          <div class="oh-card" v-if="isSuperAdmin">
            <table class="oh-perm-table">
              <thead>
                <tr>
                  <th style="width:34%;">Módulo</th>
                  <th class="center">Cajero</th>
                  <th class="center">Administrador operativo</th>
                  <th class="center">Super Admin</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(m, i) in permissionModules" :key="m.id">
                  <td>
                    <div class="oh-perm-mod">{{ m.name }}<span class="sub">{{ m.sub }}</span></div>
                  </td>
                  <td class="center">
                    <div class="oh-seg" :class="{pending: m.cajero === null}">
                      <button :class="{'is-active': m.cajero === 'none'}" @click="setPerm(i, 'cajero', 'none')" title="Sin acceso">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><circle cx="12" cy="12" r="9"/><path d="M6 6l12 12"/></svg>
                      </button>
                      <button :class="{'is-active': m.cajero === 'read'}" @click="setPerm(i, 'cajero', 'read')" title="Solo lectura">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                      </button>
                      <button :class="{'is-active': m.cajero === 'edit'}" @click="setPerm(i, 'cajero', 'edit')" title="Edición">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/></svg>
                      </button>
                    </div>
                  </td>
                  <td class="center">
                    <div class="oh-seg" :class="{pending: m.admin === null}">
                      <button :class="{'is-active': m.admin === 'none'}" @click="setPerm(i, 'admin', 'none')" title="Sin acceso">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><circle cx="12" cy="12" r="9"/><path d="M6 6l12 12"/></svg>
                      </button>
                      <button :class="{'is-active': m.admin === 'read'}" @click="setPerm(i, 'admin', 'read')" title="Solo lectura">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                      </button>
                      <button :class="{'is-active': m.admin === 'edit'}" @click="setPerm(i, 'admin', 'edit')" title="Edición">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/></svg>
                      </button>
                    </div>
                  </td>
                  <td class="center">
                    <span class="oh-perm-fixed">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3 6.5 7 1-5 5 1.5 7L12 18l-6.5 3.5L7 14.5l-5-5 7-1z"/></svg>
                      Edición total
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else>
            <p class="text-red-500 mt-4">No tienes permisos para editar la matriz.</p>
          </div>

          <div class="oh-legend">
            <div class="item"><span class="swatch" style="background:var(--danger-pale);border:1px solid var(--danger);"></span> Sin acceso</div>
            <div class="item"><span class="swatch" style="background:var(--amber-pale);border:1px solid var(--amber);"></span> Solo lectura</div>
            <div class="item"><span class="swatch" style="background:var(--acai);"></span> Edición</div>
            <div class="item"><span class="swatch" style="border:2px dashed var(--amber);background:transparent;"></span> Pendiente de definir</div>
          </div>

          <div class="oh-perm-footer" v-if="isSuperAdmin">
            <button class="oh-btn oh-btn-ghost" @click="resetPerms">Restaurar borrador</button>
            <button class="oh-btn oh-btn-primary" @click="savePermissions">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
              Guardar matriz de permisos
            </button>
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
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { apiFetch } from '../services/api';

const router = useRouter();
const authStore = useAuthStore();

// UI State
const activeTab = ref('usuarios');
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
  cashier: { label: 'Cajero', cls: 'role-cajero', color: '#5C7A3F' },
  admin: { label: 'Administrador operativo', cls: 'role-admin', color: '#8B5E3C' },
  super_admin: { label: 'Super Admin', cls: 'role-super', color: '#4B2E52' }
};

// Data
const users = ref([]);
const selectedUser = ref(null);
const form = ref({ id: null, name: '', email: '', role: 'cashier', ci: '', phone: '', start_date: '', password: '' });
const resetForm = ref({ password: '' });
const formErrors = ref({});

const permissionModules = ref([
  { id: 'pos', name: 'Ventas / POS', sub: 'Registrar ventas en el punto de venta', cajero: null, admin: null },
  { id: 'voids', name: 'Anular venta / registrar merma', sub: 'Con motivo obligatorio, sin PIN', cajero: null, admin: null },
  { id: 'register_self', name: 'Caja / Arqueo propia', sub: 'Abrir y cerrar turno propio', cajero: null, admin: null },
  { id: 'register_others', name: 'Caja / Arqueo de otros', sub: 'Ver o cerrar turnos de otros cajeros', cajero: null, admin: null },
  { id: 'inventory', name: 'Inventario / Recetas / CPP', sub: 'Costos, insumos y recetas', cajero: null, admin: null },
  { id: 'catalog', name: 'Precios y toppings', sub: 'Configurar catálogo y precios', cajero: null, admin: null },
  { id: 'crm_read', name: 'CRM — ver clientes', sub: 'Consultar historial y puntos', cajero: null, admin: null },
  { id: 'crm_config', name: 'CRM — reglas de cashback', sub: 'Configurar acumulación y redención', cajero: null, admin: null },
  { id: 'delivery_ops', name: 'Delivery — estados de pedido', sub: 'Marcar listo / retirado', cajero: null, admin: null },
  { id: 'delivery_config', name: 'Delivery — integración / catálogo', sub: 'Configurar PedidosYa', cajero: null, admin: null },
  { id: 'reports', name: 'Reportes financieros', sub: 'Ventas, cierre de caja, márgenes', cajero: null, admin: null },
  { id: 'users', name: 'Gestión de usuarios y roles', sub: 'Crear, editar, desactivar cuentas', cajero: null, admin: null },
  { id: 'audit', name: 'Log de auditoría', sub: 'Historial de acciones sensibles', cajero: null, admin: null }
]);

// Computed
const isSuperAdmin = computed(() => authStore.user?.role === 'super_admin');
const isAdmin = computed(() => authStore.user?.role === 'admin');

const userInitials = computed(() => {
  if (!authStore.user?.name) return '??';
  return getInitials(authStore.user.name);
});

const filteredUsers = computed(() => {
  const q = searchQuery.value.toLowerCase();
  return users.value.filter(u => 
    !q || u.name.toLowerCase().includes(q) || (u.ci && u.ci.toLowerCase().includes(q)) || u.email.toLowerCase().includes(q)
  );
});

const totalModules = computed(() => permissionModules.value.length);
const decidedCount = computed(() => permissionModules.value.filter(m => m.admin !== null).length);
const progressPct = computed(() => Math.round((decidedCount.value / totalModules.value) * 100) || 0);

// Methods
function goTo(path) {
  router.push(path);
}

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

// Permissions Logic
const fetchPermissions = async () => {
  try {
    const res = await apiFetch('/permissions');
    const perms = res; // array de { role, module, access_level }
    perms.forEach(p => {
      const moduleObj = permissionModules.value.find(m => m.id === p.module);
      if (moduleObj) {
        if (p.role === 'admin') moduleObj.admin = p.access_level;
        if (p.role === 'cashier') moduleObj.cajero = p.access_level;
      }
    });
  } catch (e) {
    console.error('Error al obtener permisos:', e);
  }
};

const setPerm = (index, roleName, level) => {
  permissionModules.value[index][roleName] = level;
};

const resetPerms = () => {
  permissionModules.value.forEach(m => {
    m.admin = null;
    m.cajero = null;
  });
  showToastMsg('Borrador restaurado');
};

const savePermissions = async () => {
  try {
    const payload = [];
    permissionModules.value.forEach(mod => {
      if (mod.admin !== null) payload.push({ role: 'admin', module: mod.id, access_level: mod.admin });
      if (mod.cajero !== null) payload.push({ role: 'cashier', module: mod.id, access_level: mod.cajero });
    });

    await apiFetch('/permissions', {
      method: 'PUT',
      body: JSON.stringify({ permissions: payload })
    });
    // Sincronizar Pinia (si el usuario actual es afectado por estos roles)
    await authStore.fetchUser();
    
    showToastMsg('Matriz de permisos guardada');
  } catch (e) {
    if (e.validationErrors) {
      alertAction('Se encontraron errores en la matriz: ' + Object.values(e.validationErrors).flat().join(', '));
    } else {
      alertAction('Error al guardar permisos');
    }
  }
};

onMounted(async () => {
  await fetchUsers();
  if (isSuperAdmin.value) {
    await fetchPermissions();
  }
});
</script>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap');

.oh-root{
  --acai:#4B2E52;
  --acai-light:#6B4574;
  --acai-pale:#EFE7F0;
  --cream:#FBF8F4;
  --surface:#FFFFFF;
  --coffee:#8B5E3C;
  --coffee-pale:#F3E9DE;
  --leaf:#5C7A3F;
  --leaf-pale:#EAF0E2;
  --ink:#241C29;
  --ink-soft:#6B6070;
  --border:#E6DFE0;
  --amber:#B8791F;
  --amber-pale:#FBF0DE;
  --danger:#B5453D;
  --danger-pale:#F8E7E5;
  --danger-600:#D32F2F;

  font-family:'Manrope',sans-serif;
  background:var(--cream);
  color:var(--ink);
  min-height:100vh;
  display:flex;
  overflow:hidden;
}

html.dark .oh-root {
  --acai-pale:#2C1841;
  --cream:#121212;
  --surface:#1E1E1E;
  --coffee-pale:#3e2a19;
  --leaf-pale:#243618;
  --ink:#E0E0E0;
  --ink-soft:#B0B0B0;
  --border:#333333;
  --amber-pale:#38270f;
  --danger-pale:#381614;
}

.oh-mono{font-family:'JetBrains Mono',monospace;}
.oh-display{font-family:'Fraunces',serif;}

/* ---------- SIDEBAR ---------- */
.oh-sidebar{
  width:216px;
  flex-shrink:0;
  background:var(--acai);
  color:#F4EEF5;
  padding:22px 14px;
  display:flex;
  flex-direction:column;
  gap:26px;
}
.oh-brand{display:flex;align-items:center;gap:10px;padding:0 8px;}
.oh-brand-mark{
  width:34px;height:34px;border-radius:50%;
  background:radial-gradient(circle at 32% 30%, #8A5F92 0%, #4B2E52 65%);
  border:2px solid #7A4F82;
  position:relative;flex-shrink:0;
}
.oh-brand-mark:after{
  content:"";position:absolute;top:8px;left:9px;width:5px;height:12px;
  background:#F4EEF5;border-radius:3px;transform:rotate(20deg);opacity:.85;
}
.oh-brand-text{line-height:1.1;}
.oh-brand-text .t1{font-family:'Fraunces',serif;font-size:16px;font-weight:600;letter-spacing:.2px;}
.oh-brand-text .t2{font-size:10.5px;color:#C8AECC;text-transform:uppercase;letter-spacing:1.2px;}

.oh-nav{display:flex;flex-direction:column;gap:2px;}
.oh-nav a{
  display:flex;align-items:center;gap:10px;
  padding:9px 10px;border-radius:8px;
  color:#D9C7DB;font-size:13.5px;font-weight:500;
  text-decoration:none;
}
.oh-nav a:hover{
  background:rgba(255,255,255,0.05);
}
.oh-nav a svg{width:16px;height:16px;flex-shrink:0;opacity:.85;}
.oh-nav a.active{background:rgba(255,255,255,0.12);color:#fff; cursor:default;}
.oh-nav a.active svg{opacity:1;}
.oh-nav-label{
  font-size:10px;text-transform:uppercase;letter-spacing:1.3px;
  color:#9E7FA2;margin:8px 0 2px 10px;font-weight:600;
}
.oh-sidebar-foot{
  margin-top:auto;padding:12px 10px;border-top:1px solid rgba(255,255,255,0.12);
  display:flex;align-items:center;gap:9px;
}
.oh-avatar-sm{
  width:28px;height:28px;border-radius:50%;background:var(--acai-light);
  display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;flex-shrink:0;
}
.oh-sidebar-foot .name{font-size:12.5px;font-weight:600;color:#fff;}
.oh-sidebar-foot .role{font-size:10.5px;color:#C8AECC;}

/* ---------- MAIN ---------- */
.oh-main{flex:1;display:flex;flex-direction:column;min-width:0;}
.oh-topbar{
  padding:24px 30px 0 30px;display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap;
}
.oh-title h1{font-family:'Fraunces',serif;font-size:24px;font-weight:600;margin:0 0 4px 0;letter-spacing:.1px;}
.oh-title p{margin:0;font-size:13px;color:var(--ink-soft);}
.oh-topbar-actions{display:flex;gap:10px;align-items:center;}
.oh-search{
  display:flex;align-items:center;gap:7px;background:var(--surface);
  border:1px solid var(--border);border-radius:9px;padding:8px 12px;min-width:200px;
}
.oh-search svg{width:15px;height:15px;color:var(--ink-soft);flex-shrink:0;}
.oh-search input{border:none;outline:none;font-size:13px;background:transparent;width:100%;font-family:inherit;color:var(--ink);}
.oh-btn{
  display:inline-flex;align-items:center;gap:7px;border:none;border-radius:9px;
  padding:9px 15px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;
  transition:transform .12s ease, box-shadow .12s ease;
}
.oh-btn:active{transform:translateY(1px);}
.oh-btn svg{width:15px;height:15px;}
.oh-btn-primary{background:var(--acai);color:#fff;box-shadow:0 8px 18px -8px rgba(75,46,82,0.55);}
.oh-btn-primary:hover{background:var(--acai-light);}
.oh-btn-ghost{background:transparent;color:var(--ink-soft);border:1px solid var(--border);}
.oh-btn-ghost:hover{background:var(--acai-pale);color:var(--acai);}

.oh-tabs{display:flex;gap:4px;padding:20px 30px 0 30px;border-bottom:1px solid var(--border);}
.oh-tab{
  padding:10px 4px;margin-right:22px;font-size:13.5px;font-weight:600;color:var(--ink-soft);
  border-bottom:2px solid transparent;cursor:pointer;user-select:none;
}
.oh-tab.active{color:var(--ink);border-bottom-color:var(--ink);}

.oh-content{padding:22px 30px 30px 30px;overflow:auto;flex:1;}
.oh-panel{display:none;}
.oh-panel.active{display:block;}

/* ---------- TABLE ---------- */
.oh-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;}
table.oh-table{width:100%;border-collapse:collapse;font-size:13px;}
table.oh-table thead th{
  text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.9px;
  color:var(--ink-soft);font-weight:700;padding:12px 16px;border-bottom:1px solid var(--border);background:var(--cream-200);
}
table.oh-table tbody td{padding:12px 16px;border-bottom:1px solid var(--border);vertical-align:middle;}
table.oh-table tbody tr:last-child td{border-bottom:none;}
table.oh-table tbody tr:hover{background:var(--cream-100);}
.oh-user-cell{display:flex;align-items:center;gap:10px;}
.oh-avatar{
  width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:11.5px;font-weight:700;color:#fff;flex-shrink:0;
}
.oh-user-name{font-weight:600;font-size:13px;}
.oh-user-username{font-size:11.5px;color:var(--ink-soft);}

.oh-badge{
  display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:100px;
  font-size:11.5px;font-weight:700;white-space:nowrap;
}
.oh-badge-dot{width:6px;height:6px;border-radius:50%;}
.oh-badge.role-cashier{background:var(--leaf-pale);color:var(--leaf);}
.oh-badge.role-admin{background:var(--coffee-pale);color:var(--coffee);}
.oh-badge.role-super{background:var(--acai-pale);color:var(--acai);}

.oh-status{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;}
.oh-status .dot{width:7px;height:7px;border-radius:50%;}
.oh-status.ok .dot{background:var(--leaf);}
.oh-status.ok{color:var(--leaf);}
.oh-status.blocked .dot{background:var(--danger);}
.oh-status.blocked{color:var(--danger);}

.oh-row-actions{display:flex;gap:4px;justify-content:flex-end;}
.oh-icon-btn{
  width:29px;height:29px;border-radius:8px;border:1px solid var(--border);background:var(--surface);
  display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--ink-soft);
}
.oh-icon-btn:hover{background:var(--acai-pale);color:var(--acai);border-color:var(--acai-pale);}
.oh-icon-btn svg{width:14px;height:14px;}

/* ---------- PERMISSIONS ---------- */
.oh-perm-intro{
  display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:16px;
}
.oh-perm-intro p{margin:0;font-size:13px;color:var(--ink-soft);max-width:520px;line-height:1.55;}
.oh-perm-progress{
  display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--border);
  border-radius:10px;padding:9px 14px;
}
.oh-perm-progress .ring{
  width:34px;height:34px;border-radius:50%;
  background:conic-gradient(var(--acai) calc(var(--pct,0) * 1%), var(--border) 0);
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.oh-perm-progress .ring span{
  width:26px;height:26px;border-radius:50%;background:var(--surface);
  display:flex;align-items:center;justify-content:center;font-size:9.5px;font-weight:700;color:var(--acai);
}
.oh-perm-progress .txt{font-size:12px;color:var(--ink-soft);line-height:1.3;}
.oh-perm-progress .txt b{color:var(--ink);font-size:13px;}

table.oh-perm-table{width:100%;border-collapse:collapse;font-size:13px;}
table.oh-perm-table thead th{
  text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--ink-soft);
  font-weight:700;padding:12px 16px;border-bottom:1px solid var(--border);background:var(--cream-200);
}
table.oh-perm-table thead th.center{text-align:center;}
table.oh-perm-table tbody td{padding:10px 16px;border-bottom:1px solid var(--border);vertical-align:middle;}
table.oh-perm-table tbody tr:last-child td{border-bottom:none;}
.oh-perm-mod{font-weight:600;font-size:12.5px;}
.oh-perm-mod .sub{display:block;font-size:11px;font-weight:400;color:var(--ink-soft);margin-top:4px;}

.oh-seg{
  display:inline-flex;border:1px solid var(--border);border-radius:100px;padding:2px;background:var(--cream-200);gap:2px;
  position:relative;
}
.oh-seg.pending{outline:2px dashed var(--amber);outline-offset:3px;}
.oh-seg button{
  width:26px;height:22px;border:none;border-radius:100px;background:transparent;cursor:pointer;
  display:flex;align-items:center;justify-content:center;color:#B7ADB9;
}
.oh-seg button svg{width:12px;height:12px;}
.oh-seg button.is-active[title="Sin acceso"]{background:var(--danger-pale);color:var(--danger);}
.oh-seg button.is-active[title="Solo lectura"]{background:var(--amber-pale);color:var(--amber);}
.oh-seg button.is-active[title="Edición"]{background:var(--acai);color:#fff;}
.oh-perm-fixed{font-size:11.5px;color:var(--ink-soft);display:flex;align-items:center;gap:5px;justify-content:center;}
.oh-perm-fixed svg{width:12px;height:12px;}

.oh-legend{display:flex;gap:18px;margin-top:16px;flex-wrap:wrap;}
.oh-legend .item{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink-soft);}
.oh-legend .swatch{width:14px;height:14px;border-radius:5px;}

.oh-perm-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:18px;}

/* ---------- MODAL ---------- */
.oh-modal-overlay{
  position:fixed;inset:0;background:rgba(36,28,41,0.45);display:none;align-items:center;justify-content:center;
  padding:20px;z-index:20;
}
.oh-modal-overlay.open{display:flex;}
.oh-modal{
  background:var(--surface);border-radius:14px;width:100%;max-width:480px;padding:26px 26px 22px 26px;
  box-shadow:0 30px 60px -20px rgba(36,28,41,0.4);
}
.oh-modal h2{font-family:'Fraunces',serif;font-size:19px;font-weight:600;margin:0 0 4px 0;}
.oh-modal .sub{font-size:12.5px;color:var(--ink-soft);margin:0 0 18px 0;}
.oh-form-row{display:flex;gap:12px;margin-bottom:12px;}
.oh-form-group{flex:1;display:flex;flex-direction:column;gap:5px;}
.oh-form-group label{font-size:11.5px;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.5px;}
.oh-form-group input,.oh-form-group select{
  border:1px solid var(--border);border-radius:8px;padding:9px 11px;font-size:13px;font-family:inherit;
  outline:none;background:var(--cream);color:var(--ink);
}
.oh-form-group input:focus,.oh-form-group select:focus{border-color:var(--acai-light);background:var(--surface);}
.oh-modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:12px;}

.oh-toast{
  position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(10px);
  background:var(--ink);color:#fff;padding:10px 18px;border-radius:9px;font-size:12.5px;font-weight:600;
  opacity:0;pointer-events:none;transition:all .25s ease;z-index:30;display:flex;align-items:center;gap:8px;
}
.oh-toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
.oh-toast svg{width:14px;height:14px;color:var(--leaf);}

@media (max-width:760px){
  .oh-sidebar{width:66px;padding:18px 8px;}
  .oh-brand-text,.oh-nav-label,.oh-nav a span,.oh-sidebar-foot .name,.oh-sidebar-foot .role{display:none;}
  .oh-nav a{justify-content:center;}
  .oh-topbar,.oh-tabs,.oh-content{padding-left:16px;padding-right:16px;}
}

.has-error {
  border-color: var(--danger-600) !important;
  background-color: var(--danger-pale);
}
.error-text {
  color: var(--danger-600);
  font-size: 11px;
  margin-top: 4px;
  display: block;
}
</style>
