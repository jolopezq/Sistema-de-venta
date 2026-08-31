<template>
  <div class="oh-main">
    <div class="oh-topbar">
      <div class="oh-title">
        <h1>Registro de Auditoría</h1>
        <p>Historial de cambios y accesos sensibles en el sistema.</p>
      </div>
      <div class="oh-topbar-actions">
        <button class="oh-btn oh-btn-backup-restore" @click="showRestoreModal = true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
          Restaurar BD
        </button>
        <button class="oh-btn oh-btn-backup" @click="showBackupModal = true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
          Descargar BD (.sqlite.gz)
        </button>
        <button class="oh-btn oh-btn-primary" @click="exportCSV">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
          Exportar CSV
        </button>
        <button class="oh-btn oh-btn-ghost" @click="router.push('/menu')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
          Volver a Admin
        </button>
      </div>
    </div>

    <!-- Barra de Filtros -->
    <div class="oh-filter-bar">
      <div class="filter-group">
        <label>Desde</label>
        <input type="date" v-model="filters.date_from" class="oh-input" @change="applyFilters" />
      </div>
      <div class="filter-group">
        <label>Hasta</label>
        <input type="date" v-model="filters.date_to" class="oh-input" @change="applyFilters" />
      </div>
      <div class="filter-group">
        <label>Usuario</label>
        <select v-model="filters.user_id" class="oh-input" @change="applyFilters">
          <option value="">Todos</option>
          <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
        </select>
      </div>
      <div class="filter-group">
        <label>Módulo</label>
        <input type="text" v-model="filters.module" placeholder="Ej: Sale, Product" class="oh-input" @keyup.enter="applyFilters" />
      </div>
      <div class="filter-group">
        <label>Acción</label>
        <select v-model="filters.action" class="oh-input" @change="applyFilters">
          <option value="">Todas</option>
          <option value="created">Creado</option>
          <option value="updated">Actualizado</option>
          <option value="deleted">Eliminado</option>
        </select>
      </div>
      <div class="filter-actions">
        <button class="oh-btn oh-btn-secondary" @click="clearFilters">Limpiar</button>
        <button class="oh-btn oh-btn-primary" @click="applyFilters">Buscar</button>
      </div>
    </div>

    <div class="oh-content">
      <div class="oh-card">
        <table class="oh-table">
          <thead>
            <tr>
              <th>Fecha / Hora</th>
              <th>Usuario</th>
              <th>Módulo</th>
              <th>Acción</th>
              <th>Descripción</th>
              <th style="width: 100px; text-align: center;">Detalles</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="log in logs" :key="log.id">
              <td class="oh-mono" style="font-size:11.5px">{{ formatDate(log.created_at) }}</td>
              <td>
                <div class="oh-user-cell" v-if="log.user">
                  <div class="oh-avatar" style="background:var(--acai-300)">{{ getInitials(log.user.name) }}</div>
                  <div>
                    <div class="oh-user-name">{{ log.user.name }}</div>
                  </div>
                </div>
                <div v-else class="text-gray-500">Sistema</div>
              </td>
              <td><span class="oh-badge" style="background:var(--cream-200);color:var(--ink-700)">{{ log.module }}</span></td>
              <td>
                <span class="oh-badge" :style="getActionStyle(log.action)">
                  {{ log.action.toUpperCase() }}
                </span>
              </td>
              <td style="max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size:12px; color:var(--ink-500);">
                {{ getShortDescription(log.description) }}
              </td>
              <td style="text-align: center;">
                <button class="oh-btn-sm" @click="viewDetails(log)">Ver</button>
              </td>
            </tr>
            <tr v-if="logs.length === 0">
              <td colspan="6" class="text-center py-4 text-gray-500" style="text-align: center;">No se encontraron registros.</td>
            </tr>
          </tbody>
        </table>
        
        <!-- Paginación -->
        <div class="oh-pagination" v-if="pagination.last_page > 1">
          <button class="oh-btn oh-btn-ghost" :disabled="pagination.current_page === 1" @click="changePage(pagination.current_page - 1)">Anterior</button>
          <span class="page-info">Página {{ pagination.current_page }} de {{ pagination.last_page }}</span>
          <button class="oh-btn oh-btn-ghost" :disabled="pagination.current_page === pagination.last_page" @click="changePage(pagination.current_page + 1)">Siguiente</button>
        </div>
      </div>
    </div>

    <!-- Modal Detalles -->
    <AuditLogDetailModal v-if="selectedLog" :log="selectedLog" @close="selectedLog = null" />

    <!-- Modal Descarga de Respaldo -->
    <DownloadBackupModal
      v-if="showBackupModal"
      @close="showBackupModal = false"
      @downloaded="fetchLogs(1)"
    />

    <RestoreBackupModal
      v-if="showRestoreModal"
      @close="showRestoreModal = false"
      @restored="fetchLogs(1)"
    />
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { apiFetch, API_URL } from '../services/api';
import { useRouter } from 'vue-router';
import AuditLogDetailModal from '../components/AuditLogDetailModal.vue';
import DownloadBackupModal from '../components/DownloadBackupModal.vue';
import RestoreBackupModal from '../components/RestoreBackupModal.vue';

const router = useRouter();
const logs = ref([]);
const users = ref([]);
const selectedLog = ref(null);
const showBackupModal = ref(false);
const showRestoreModal = ref(false);

const filters = reactive({
  date_from: '',
  date_to: '',
  user_id: '',
  module: '',
  action: ''
});

const pagination = reactive({
  current_page: 1,
  last_page: 1,
  total: 0
});

onMounted(async () => {
  await fetchUsers();
  await fetchLogs();
});

const fetchUsers = async () => {
  try {
    const res = await apiFetch('/users');
    users.value = res.data || res;
  } catch (e) {
    console.error('Error fetching users', e);
  }
};

const fetchLogs = async (page = 1) => {
  try {
    const queryParams = new URLSearchParams();
    queryParams.append('page', page);
    if (filters.date_from) queryParams.append('date_from', filters.date_from);
    if (filters.date_to) queryParams.append('date_to', filters.date_to);
    if (filters.user_id) queryParams.append('user_id', filters.user_id);
    if (filters.module) queryParams.append('module', filters.module);
    if (filters.action) queryParams.append('action', filters.action);

    const res = await apiFetch(`/audit-logs?${queryParams.toString()}`);
    
    // Laravel paginator response structure
    logs.value = res.data || [];
    pagination.current_page = res.current_page || 1;
    pagination.last_page = res.last_page || 1;
    pagination.total = res.total || 0;
  } catch (e) {
    console.error('Error al obtener logs:', e);
  }
};

const applyFilters = () => {
  fetchLogs(1);
};

const clearFilters = () => {
  filters.date_from = '';
  filters.date_to = '';
  filters.user_id = '';
  filters.module = '';
  filters.action = '';
  fetchLogs(1);
};

const changePage = (page) => {
  if (page >= 1 && page <= pagination.last_page) {
    fetchLogs(page);
  }
};

const exportCSV = () => {
  const queryParams = new URLSearchParams();
  if (filters.date_from) queryParams.append('date_from', filters.date_from);
  if (filters.date_to) queryParams.append('date_to', filters.date_to);
  if (filters.user_id) queryParams.append('user_id', filters.user_id);
  if (filters.module) queryParams.append('module', filters.module);
  if (filters.action) queryParams.append('action', filters.action);
  
  const token = localStorage.getItem('auth_token');
  const exportUrl = `${API_URL}/audit-logs/export?${queryParams.toString()}`;
  
  // Realizar fetch para poder mandar el header de Auth y disparar descarga
  fetch(exportUrl, {
    headers: { 'Authorization': `Bearer ${token}` }
  })
  .then(res => res.blob())
  .then(blob => {
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `audit_logs_${new Date().toISOString().slice(0,10)}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
  })
  .catch(e => console.error("Error exportando CSV", e));
};

const viewDetails = (log) => {
  selectedLog.value = log;
};

const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleString('es-BO', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const getInitials = (name) => {
  if (!name) return 'U';
  const parts = name.split(' ');
  return parts.length > 1 ? (parts[0][0] + parts[1][0]).toUpperCase() : name.substring(0, 2).toUpperCase();
};

const getActionStyle = (action) => {
  if (action === 'created') return 'background:var(--lime-100);color:var(--lime-700)';
  if (action === 'updated') return 'background:var(--gold-100);color:var(--gold-500)';
  if (action === 'deleted') return 'background:var(--danger-100);color:var(--danger-600)';
  return 'background:var(--acai-50);color:var(--acai-500)';
};

const getShortDescription = (desc) => {
  if (!desc) return '-';
  if (desc.includes('Cambios: {')) {
    return desc.split('Cambios: ')[0]; // Return only the action part without the JSON
  }
  return desc.substring(0, 80) + (desc.length > 80 ? '...' : '');
};
</script>

<style scoped>
.oh-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  background: var(--cream-50);
  min-height: 100vh;
}
.oh-topbar { padding: 24px 30px 20px 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); }
.oh-topbar-actions { display: flex; gap: 12px; }

.oh-filter-bar {
  padding: 16px 30px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  align-items: flex-end;
}
.filter-group { display: flex; flex-direction: column; gap: 6px; }
.filter-group label { font-size: 11px; font-weight: 600; color: var(--ink-500); text-transform: uppercase; }
.oh-input {
  padding: 8px 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--cream-50);
  color: var(--ink-900);
  font-family: inherit;
  font-size: 13px;
  min-width: 140px;
}
.oh-input:focus { outline: none; border-color: var(--acai-400); }
.filter-actions { display: flex; gap: 8px; margin-bottom: 1px; }

.oh-content { padding: 30px; overflow: auto; flex: 1; }
.oh-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; }
table.oh-table { width: 100%; border-collapse: collapse; font-size: 13px; }
table.oh-table thead th {
  text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: .9px;
  color: var(--ink-500); font-weight: 700; padding: 12px 16px; border-bottom: 1px solid var(--border); background: var(--cream-200);
}
table.oh-table tbody td { padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
table.oh-table tbody tr:hover { background: var(--cream-100); }

.oh-pagination {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 20px;
  background: var(--surface);
  border-top: 1px solid var(--border);
}
.page-info { font-size: 12.5px; color: var(--ink-500); font-weight: 500; }

.oh-user-cell { display: flex; align-items: center; gap: 10px; }
.oh-avatar { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11.5px; font-weight: 700; color: #fff; flex-shrink: 0; }
.oh-user-name { font-weight: 600; font-size: 13px; color: var(--ink-900); }
.oh-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 100px; font-size: 10.5px; font-weight: 700; white-space: nowrap; }
.oh-title h1 { font-family: 'Fraunces', serif; font-size: 24px; font-weight: 600; margin: 0 0 4px 0; color: var(--ink-900); }
.oh-title p { margin: 0; font-size: 13px; color: var(--ink-500); }
.oh-btn {
  display: inline-flex; align-items: center; gap: 7px; border: none; border-radius: 9px;
  padding: 9px 15px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit;
  transition: transform .12s ease, box-shadow .12s ease;
}
.oh-btn-primary { background: var(--acai-500); color: white; }
.oh-btn-primary:hover { background: var(--acai-600); }
.oh-btn-backup { background: #0f766e; color: #ffffff; box-shadow: 0 2px 4px rgba(15, 118, 110, 0.2); }
.oh-btn-backup:hover { background: #115e59; }
.oh-btn-backup-restore { background: #be123c; color: #ffffff; box-shadow: 0 2px 4px rgba(190, 18, 60, 0.2); }
.oh-btn-backup-restore:hover { background: #9f1239; }
.oh-btn-secondary { background: var(--cream-200); color: var(--ink-700); }
.oh-btn-secondary:hover { background: var(--cream-300); }
.oh-btn-ghost { background: transparent; color: var(--ink-500); border: 1px solid var(--border); }
.oh-btn-ghost:hover { background: var(--acai-50); color: var(--acai-500); }
.oh-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.oh-btn-sm {
  background: var(--cream-200); border: none; border-radius: 6px; color: var(--ink-700);
  padding: 5px 12px; font-size: 11.5px; font-weight: 600; cursor: pointer;
  transition: all 0.15s;
}
.oh-btn-sm:hover { background: var(--cream-300); }
.oh-btn svg { width: 15px; height: 15px; }
.oh-mono { font-family: 'JetBrains Mono', monospace; }
</style>
