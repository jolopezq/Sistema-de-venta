<template>
  <div class="modal-overlay" @click.self="onClose">
    <div class="modal-content">
      <div class="modal-header">
        <div class="header-title-group">
          <div class="header-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>
            </svg>
          </div>
          <div>
            <h2>Descargar Respaldo de Base de Datos</h2>
            <p class="subtitle">Exportación atómica y consistente de la base de datos de producción (SQLite).</p>
          </div>
        </div>
        <button class="close-btn" :disabled="isDownloading" @click="onClose">&times;</button>
      </div>

      <div class="modal-body">
        <div class="security-notice">
          <div class="notice-icon">🛡️</div>
          <div class="notice-text">
            <strong>Confirmación de Seguridad Requerida</strong>
            <p>Este archivo contiene datos sensibles del negocio (ventas, clientes, inventario). Por seguridad, debes reingresar tu contraseña de <b>Super Admin</b> para autorizar la exportación.</p>
          </div>
        </div>

        <form @submit.prevent="handleDownload">
          <div class="form-group">
            <label for="admin-password">Tu contraseña actual</label>
            <input
              id="admin-password"
              v-model="password"
              type="password"
              class="oh-input-pass"
              placeholder="Ingresa tu contraseña de inicio de sesión"
              required
              :disabled="isDownloading"
              autofocus
            />
            <span v-if="errorMessage" class="error-msg">{{ errorMessage }}</span>
          </div>

          <div class="backup-details">
            <div class="detail-item">
              <span class="label">Mecanismo:</span>
              <span class="val"><code>VACUUM INTO</code> (Sin bloqueos en caja)</span>
            </div>
            <div class="detail-item">
              <span class="label">Formato de salida:</span>
              <span class="val"><code>.sqlite.gz</code> (Comprimido de alta tasa)</span>
            </div>
          </div>

          <div v-if="successInfo" class="success-alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="icon-check">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
            <div>
              <strong>¡Descarga completada con éxito!</strong>
              <p>Archivo: <code>{{ successInfo.filename }}</code> ({{ (successInfo.size / 1024).toFixed(1) }} KB)</p>
            </div>
          </div>

          <div class="modal-actions">
            <button
              type="button"
              class="btn-secondary"
              :disabled="isDownloading"
              @click="onClose"
            >
              Cancelar
            </button>
            <button
              type="submit"
              class="btn-primary"
              :disabled="isDownloading || !password"
            >
              <svg v-if="!isDownloading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>
              </svg>
              <span v-if="isDownloading" class="spinner"></span>
              {{ isDownloading ? 'Generando y comprimiendo...' : 'Autorizar y Descargar' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { apiDownload } from '../services/api';

const emit = defineEmits(['close', 'downloaded']);

const password = ref('');
const errorMessage = ref('');
const isDownloading = ref(false);
const successInfo = ref(null);

function onClose() {
  if (!isDownloading.value) {
    emit('close');
  }
}

async function handleDownload() {
  if (!password.value) return;

  errorMessage.value = '';
  successInfo.value = null;
  isDownloading.value = true;

  try {
    const result = await apiDownload(
      '/system/backup/download',
      { password: password.value },
      `ohana_backup_${new Date().toISOString().slice(0, 10)}.sqlite.gz`
    );

    successInfo.value = result;
    password.value = '';
    emit('downloaded');

    // Cerrar automáticamente después de 2.5 segundos de éxito visual
    setTimeout(() => {
      emit('close');
    }, 2500);

  } catch (err) {
    errorMessage.value = err.message || 'Error al autorizar la descarga.';
  } finally {
    isDownloading.value = false;
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
  background: var(--acai-50, #f5f3ff);
  color: var(--acai-600, #7c3aed);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
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
  background: #fffbeb;
  border: 1px solid #fef3c7;
  border-radius: 10px;
  padding: 14px;
  margin-bottom: 20px;
}

:global(html.dark) .security-notice {
  background: rgba(245, 158, 11, 0.12);
  border-color: rgba(245, 158, 11, 0.3);
}

.notice-icon {
  font-size: 20px;
}

.notice-text strong {
  display: block;
  font-size: 13px;
  color: #92400e;
  margin-bottom: 2px;
}

:global(html.dark) .notice-text strong {
  color: #fcd34d;
}

.notice-text p {
  margin: 0;
  font-size: 12px;
  line-height: 1.4;
  color: #78350f;
}

:global(html.dark) .notice-text p {
  color: #fef3c7;
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

.oh-input-pass {
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

.oh-input-pass:focus {
  outline: none;
  border-color: var(--acai-500, #8b5cf6);
  box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.15);
}

.error-msg {
  display: block;
  font-size: 12px;
  color: var(--danger-600, #dc2626);
  font-weight: 600;
  margin-top: 6px;
}

.backup-details {
  background: var(--cream-100, #f1f5f9);
  border-radius: 9px;
  padding: 10px 14px;
  font-size: 12px;
  margin-bottom: 20px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

:global(html.dark) .backup-details {
  background: rgba(30, 41, 59, 0.6);
}

.detail-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.detail-item .label {
  color: var(--ink-500, #64748b);
}

.detail-item .val code {
  background: rgba(0, 0, 0, 0.05);
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 11px;
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
  background: var(--acai-500, #7c3aed);
  color: white;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  box-shadow: 0 2px 4px rgba(124, 58, 237, 0.2);
}

.btn-primary:hover:not(:disabled) {
  background: var(--acai-600, #6d28d9);
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
