<template>
  <div class="oh-main">
    <div class="oh-topbar">
      <div class="oh-title">
        <h1>Registro de Auditoría</h1>
        <p>Historial de cambios y accesos sensibles en el sistema.</p>
      </div>
      <div class="oh-topbar-actions">
        <button class="oh-btn oh-btn-ghost" @click="router.push('/menu')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
          Volver a Admin
        </button>
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
              <td style="max-width: 350px; white-space: pre-wrap; font-size:12px; color:var(--ink-500); line-height: 1.4;">
                {{ formatDescription(log.description) }}
              </td>
            </tr>
            <tr v-if="logs.length === 0">
              <td colspan="5" class="text-center py-4 text-gray-500" style="text-align: center;">No hay registros de auditoría.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { apiFetch } from '../services/api';
import { useRouter } from 'vue-router';

const router = useRouter();
const logs = ref([]);

onMounted(async () => {
  await fetchLogs();
});

const fetchLogs = async () => {
  try {
    const res = await apiFetch('/audit-logs');
    logs.value = res.data || res;
  } catch (e) {
    console.error('Error al obtener logs:', e);
  }
};

const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleString('es-BO', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const getInitials = (name) => {
  const parts = name.split(' ');
  return parts.length > 1 ? (parts[0][0] + parts[1][0]).toUpperCase() : name.substring(0, 2).toUpperCase();
};

const getActionStyle = (action) => {
  if (action === 'created') return 'background:var(--lime-100);color:var(--lime-700)';
  if (action === 'updated') return 'background:var(--gold-100);color:var(--gold-500)';
  if (action === 'deleted') return 'background:var(--danger-100);color:var(--danger-600)';
  return 'background:var(--acai-50);color:var(--acai-500)';
};

const formatDescription = (desc) => {
  if (!desc) return '-';
  try {
    // Attempt to pretty-print JSON if it contains 'Cambios: {...}'
    if (desc.includes('Cambios: {')) {
      const parts = desc.split('Cambios: ');
      const json = JSON.parse(parts[1]);
      let prettyChanges = parts[0].trim() + '\nCambios:\n';
      for (const [key, value] of Object.entries(json)) {
        prettyChanges += `  • ${key}: ${value}\n`;
      }
      return prettyChanges.trim();
    }
  } catch (e) {
    // ignore
  }
  return desc;
};
</script>

<style scoped>
/* Utiliza la estructura global cargada en style.css */
.oh-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  background: var(--cream-50);
  min-height: 100vh;
}
.oh-topbar { padding: 24px 30px 20px 30px; display: flex; justify-content: space-between; border-bottom: 1px solid var(--border); }
.oh-content { padding: 30px; overflow: auto; flex: 1; }
.oh-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
table.oh-table { width: 100%; border-collapse: collapse; font-size: 13px; }
table.oh-table thead th {
  text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: .9px;
  color: var(--ink-500); font-weight: 700; padding: 12px 16px; border-bottom: 1px solid var(--border); background: var(--cream-200);
}
table.oh-table tbody td { padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: top; }
table.oh-table tbody tr:hover { background: var(--cream-100); }
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
.oh-btn-ghost { background: transparent; color: var(--ink-500); border: 1px solid var(--border); }
.oh-btn-ghost:hover { background: var(--acai-50); color: var(--acai-500); }
.oh-btn svg { width: 15px; height: 15px; }
.oh-mono { font-family: 'JetBrains Mono', monospace; }
</style>
