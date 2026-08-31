<template>
  <div class="modal-overlay" @mousedown.self="onClose">
    <div class="modal-content">
      <div class="modal-header">
        <div class="header-title-group">
          <div class="header-icon warning-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
          </div>
          <div>
            <h2>Restaurar Base de Datos</h2>
            <p class="subtitle">Sube un respaldo para sobrescribir los datos actuales</p>
          </div>
        </div>
        <button class="close-btn" @click="onClose" title="Cerrar">&times;</button>
      </div>

      <div class="modal-body">
        <div class="security-notice">
          <div class="notice-icon">⚠️</div>
          <div class="notice-text">
            <strong>ADVERTENCIA DE SOBRESCRITURA DE DATOS</strong>
            <p>Al restaurar un respaldo, todos los datos actuales de esta computadora serán eliminados de manera permanente y reemplazados por los del archivo de respaldo. Asegúrate de estar subiendo el archivo correcto.</p>
          </div>
        </div>

        <form @submit.prevent="handleRestore">
          <div class="form-group">
            <label for="backupFile">Archivo de Respaldo (.gz)</label>
            <input
              id="backupFile"
              type="file"
              accept=".gz,.gzip"
              class="oh-input-file"
              @change="onFileChange"
              required
            />
          </div>

          <div class="form-group">
            <label for="superPasswordRestore">Contraseña de Super Admin</label>
            <input
              id="superPasswordRestore"
              type="password"
              v-model="password"
              class="oh-input-pass"
              placeholder="Ingresa tu contraseña para confirmar"
              required
              autocomplete="current-password"
            />
            <span v-if="errorMessage" class="error-msg">{{ errorMessage }}</span>
          </div>

          <div v-if="successMessage" class="success-alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="icon-check">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
            <div>
              <strong>¡Restauración exitosa!</strong>
              <p>{{ successMessage }}</p>
            </div>
          </div>

          <div class="modal-actions">
            <button
              type="button"
              class="btn-secondary"
              :disabled="isRestoring"
              @click="onClose"
            >
              Cancelar
            </button>
            <button
              type="submit"
              class="btn-primary danger-btn"
              :disabled="isRestoring || !password || !selectedFile"
            >
              <svg v-if="!isRestoring" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>
              </svg>
              <span v-if="isRestoring" class="spinner"></span>
              {{ isRestoring ? 'Restaurando base de datos...' : 'Autorizar y Restaurar' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { db } from '../db/database';

const emit = defineEmits(['close', 'restored']);

const password = ref('');
const selectedFile = ref(null);
const errorMessage = ref('');
const successMessage = ref('');
const isRestoring = ref(false);

function onClose() {
  if (!isRestoring.value) {
    emit('close');
  }
}

function onFileChange(event) {
  const file = event.target.files[0];
  if (file) {
    selectedFile.value = file;
  }
}

async function handleRestore() {
  if (!password.value || !selectedFile.value) return;

  errorMessage.value = '';
  successMessage.value = '';
  isRestoring.value = true;

  try {
    const formData = new FormData();
    formData.append('backup_file', selectedFile.value);
    formData.append('password', password.value);

    const token = localStorage.getItem('auth_token') || sessionStorage.getItem('auth_token');
    
    // As it is a multipart/form-data, fetch is easier than api.js wrapper
    const apiUrl = import.meta.env.VITE_API_URL || '';
    
    const response = await fetch(`${apiUrl}/api/system/backup/restore`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      },
      body: formData
    });

    const data = await response.json();

    if (!response.ok) {
      throw new Error(data.message || 'Error al restaurar la base de datos.');
    }

    // Limpiar toda la caché local (IndexedDB) para que se recargue del nuevo backend
    try {
      await Promise.all(db.tables.map(table => table.clear()));
    } catch (e) {
      console.error("Error limpiando IndexedDB:", e);
    }

    successMessage.value = data.message;
    password.value = '';
    emit('restored');

    // Cerrar automáticamente después de 2.5 segundos
    setTimeout(() => {
      emit('close');
      window.location.reload();
    }, 2500);

  } catch (err) {
    errorMessage.value = err.message || 'Error al procesar la solicitud.';
  } finally {
    isRestoring.value = false;
  }
}
</script>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.6);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 999;
  padding: 16px;
}

.modal-content {
  background: var(--surface, #ffffff);
  border-radius: 16px;
  width: 100%;
  max-width: 520px;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1);
  border: 1px solid var(--border, #e2e8f0);
  overflow: hidden;
  animation: popIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes popIn {
  from { opacity: 0; transform: scale(0.96); }
  to { opacity: 1; transform: scale(1); }
}

.modal-header {
  padding: 20px 24px;
  border-bottom: 1px solid var(--border, #e2e8f0);
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.header-title-group {
  display: flex;
  gap: 14px;
  align-items: center;
}

.header-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.warning-icon {
  background: #fef2f2;
  color: #dc2626;
}

:global(html.dark) .warning-icon {
  background: rgba(220, 38, 38, 0.2);
  color: #f87171;
}

.header-icon svg {
  width: 22px;
  height: 22px;
}

.modal-header h2 {
  margin: 0;
  font-size: 17px;
  font-weight: 700;
  color: var(--ink-900, #0f172a);
}

.subtitle {
  margin: 3px 0 0 0;
  font-size: 12.5px;
  color: var(--ink-500, #64748b);
}

.close-btn {
  background: transparent;
  border: none;
  font-size: 24px;
  color: var(--ink-400, #94a3b8);
  cursor: pointer;
  line-height: 1;
  padding: 4px;
}

.close-btn:hover {
  color: var(--ink-700, #334155);
}

.modal-body {
  padding: 24px;
}

.security-notice {
  display: flex;
  gap: 12px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 10px;
  padding: 14px;
  margin-bottom: 20px;
}

:global(html.dark) .security-notice {
  background: rgba(220, 38, 38, 0.12);
  border-color: rgba(220, 38, 38, 0.3);
}

.notice-icon {
  font-size: 20px;
}

.notice-text strong {
  display: block;
  font-size: 13px;
  color: #991b1b;
  margin-bottom: 2px;
}

:global(html.dark) .notice-text strong {
  color: #f87171;
}

.notice-text p {
  margin: 0;
  font-size: 12px;
  line-height: 1.4;
  color: #7f1d1d;
}

:global(html.dark) .notice-text p {
  color: #fecaca;
}

.form-group {
  margin-bottom: 18px;
}

.form-group label {
  display: block;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--ink-700, #334155);
  margin-bottom: 6px;
}

.oh-input-pass, .oh-input-file {
  width: 100%;
  box-sizing: border-box;
  padding: 10px 14px;
  border: 1.5px solid var(--border, #cbd5e1);
  border-radius: 9px;
  background: var(--cream-50, #f8fafc);
  color: var(--ink-900, #0f172a);
  font-size: 13.5px;
  transition: border-color 0.15s, box-shadow 0.15s;
}

.oh-input-pass:focus, .oh-input-file:focus {
  outline: none;
  border-color: #dc2626;
  box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
}

.error-msg {
  display: block;
  font-size: 12px;
  color: var(--danger-600, #dc2626);
  font-weight: 600;
  margin-top: 6px;
}

.success-alert {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #166534;
  padding: 12px 14px;
  border-radius: 9px;
  margin-bottom: 18px;
  font-size: 12.5px;
}

:global(html.dark) .success-alert {
  background: rgba(22, 163, 74, 0.15);
  border-color: rgba(22, 163, 74, 0.3);
  color: #86efac;
}

.icon-check {
  width: 20px;
  height: 20px;
  flex-shrink: 0;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 8px;
}

.btn-secondary {
  padding: 10px 16px;
  border-radius: 9px;
  border: 1px solid var(--border, #cbd5e1);
  background: transparent;
  color: var(--ink-700, #334155);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}

.btn-secondary:hover:not(:disabled) {
  background: var(--cream-100, #f1f5f9);
}

.btn-primary {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  border-radius: 9px;
  border: none;
  color: white;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}

.danger-btn {
  background: #dc2626;
  box-shadow: 0 2px 4px rgba(220, 38, 38, 0.2);
}

.danger-btn:hover:not(:disabled) {
  background: #b91c1c;
}

.btn-primary:disabled, .btn-secondary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn-primary svg {
  width: 16px;
  height: 16px;
}

.spinner {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: #ffffff;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
