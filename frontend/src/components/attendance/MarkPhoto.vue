<script setup>
/** Foto de una marca o del rostro registrado. Se pide con la sesión y se libera al salir. */
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
import { apiBlobUrl } from '../../services/api';

const props = defineProps({
  endpoint: { type: String, required: true },
  alt: { type: String, default: 'Foto' },
  caption: { type: String, default: '' },
});

const url = ref(null);
const error = ref('');

async function load() {
  if (url.value) URL.revokeObjectURL(url.value);
  url.value = null;
  error.value = '';
  try {
    url.value = await apiBlobUrl(props.endpoint);
  } catch (err) {
    error.value = err.status === 404 ? 'Sin foto' : 'No cargó';
  }
}

onMounted(load);
watch(() => props.endpoint, load);
onBeforeUnmount(() => { if (url.value) URL.revokeObjectURL(url.value); });
</script>

<template>
  <figure class="att-photo">
    <img v-if="url" :src="url" :alt="alt" />
    <div v-else class="att-photo-empty">{{ error || 'Cargando…' }}</div>
    <figcaption v-if="caption">{{ caption }}</figcaption>
  </figure>
</template>

<style scoped>
.att-photo { margin: 0; display: flex; flex-direction: column; align-items: center; gap: 4px; }
.att-photo img, .att-photo-empty {
  width: 104px; height: 104px; border-radius: 12px; object-fit: cover; background: var(--cream-200);
}
.att-photo-empty { display: flex; align-items: center; justify-content: center; font-size: 12px; color: var(--ink-500); text-align: center; padding: 6px; box-sizing: border-box; }
.att-photo figcaption { font-size: 12px; color: var(--ink-700); }
</style>
