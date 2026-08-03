<template>
  <div class="modal-overlay" v-if="log" @click.self="$emit('close')">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Detalles del Cambio</h2>
        <button class="close-btn" @click="$emit('close')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="info-grid">
          <div class="info-item">
            <span class="label">Fecha</span>
            <span class="value oh-mono">{{ formatDate(log.created_at) }}</span>
          </div>
          <div class="info-item">
            <span class="label">Usuario</span>
            <span class="value">{{ log.user ? log.user.name : 'Sistema' }}</span>
          </div>
          <div class="info-item">
            <span class="label">Módulo</span>
            <span class="value">{{ log.module }}</span>
          </div>
          <div class="info-item">
            <span class="label">Acción</span>
            <span class="value" style="font-weight: bold; text-transform: uppercase;">{{ log.action }}</span>
          </div>
        </div>

        <div class="changes-section">
          <h3>Datos Registrados</h3>
          <pre class="json-viewer">{{ parsedChanges }}</pre>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  log: Object
});

const emit = defineEmits(['close']);

const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleString('es-BO', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
};

const parsedChanges = computed(() => {
  if (!props.log || !props.log.description) return 'No hay detalles.';
  try {
    const desc = props.log.description;
    if (desc.includes('Cambios: {')) {
      const parts = desc.split('Cambios: ');
      const json = JSON.parse(parts[1]);
      return JSON.stringify(json, null, 2);
    }
    return desc;
  } catch (e) {
    return props.log.description;
  }
});
</script>

<style scoped>
.modal-overlay {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}
.modal-content {
  background: var(--surface);
  border-radius: 16px;
  width: 600px;
  max-width: 90vw;
  max-height: 85vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 10px 40px rgba(0,0,0,0.2);
}
.modal-header {
  padding: 20px 24px;
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.modal-header h2 { margin: 0; font-size: 18px; font-family: 'Fraunces', serif; color: var(--ink-900); }
.close-btn { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--ink-500); }
.modal-body {
  padding: 24px;
  overflow-y: auto;
}
.info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 24px;
}
.info-item { display: flex; flex-direction: column; }
.label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--ink-500); font-weight: 700; margin-bottom: 4px; }
.value { font-size: 14px; color: var(--ink-900); }
.oh-mono { font-family: 'JetBrains Mono', monospace; font-size: 13px; }
.changes-section h3 { margin: 0 0 12px 0; font-size: 15px; color: var(--ink-900); }
.json-viewer {
  background: var(--cream-100);
  border: 1px solid var(--border);
  padding: 16px;
  border-radius: 8px;
  font-family: 'JetBrains Mono', monospace;
  font-size: 12.5px;
  color: var(--ink-900);
  white-space: pre-wrap;
  overflow-x: auto;
}
</style>
