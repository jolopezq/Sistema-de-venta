<script setup>
import { computed } from 'vue';

const props = defineProps({
  module: {
    type: Object,
    required: true,
  },
  role: {
    type: String,
    required: true,
  },
  currentLevel: {
    type: String,
    required: true,
  },
  savedLevel: {
    type: String,
    required: true,
  },
  allowedLevels: {
    type: Array,
    default: () => ['none'],
  },
});

const emit = defineEmits(['update:level']);

const isChanged = computed(() => props.currentLevel !== props.savedLevel);

const description = computed(() => {
  if (props.role === 'admin' && props.module.adminDesc) {
    return props.module.adminDesc;
  }
  return props.module.desc;
});

const savedLevelLabel = computed(() => {
  return props.module.levelLabels[props.savedLevel] || props.savedLevel;
});

function selectLevel(level) {
  if (props.currentLevel !== level) {
    emit('update:level', level);
  }
}
</script>

<template>
  <div class="perm-row" :data-module="module.id" :class="{ 'has-diff': isChanged }">
    <!-- Info del módulo -->
    <div class="perm-info">
      <div class="perm-name-wrap">
        <span class="perm-name">{{ module.name }}</span>
        <span v-if="isChanged" class="diff-indicator" title="Valor modificado en el borrador"></span>
      </div>
      <div v-if="isChanged" class="perm-desc changed">
        Cambiado. Antes: <strong>{{ savedLevelLabel }}</strong>
      </div>
      <div v-else class="perm-desc">
        {{ description }}
      </div>
    </div>

    <!-- Control segmentado de niveles válidos -->
    <div class="perm-control" role="group" :aria-label="module.name">
      <button
        v-for="lvl in allowedLevels"
        :key="lvl"
        type="button"
        class="seg-btn"
        :class="{
          'is-active': currentLevel === lvl,
          'is-none': lvl === 'none' && currentLevel === lvl,
          'is-access': lvl !== 'none' && currentLevel === lvl,
        }"
        :aria-pressed="currentLevel === lvl"
        :aria-label="`${module.name}: ${module.levelLabels[lvl] || lvl}`"
        @click="selectLevel(lvl)"
      >
        {{ module.levelLabels[lvl] || lvl }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.perm-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  min-height: 36px;
  padding: 2px 0;
  border-bottom: 1px solid var(--cream-200);
  transition: background-color 0.15s ease;
}

.perm-row:last-child {
  border-bottom: none;
}

.perm-info {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.perm-name-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
}

.perm-name {
  font-family: inherit;
  font-size: 14px;
  line-height: 1.25;
  font-weight: 600;
  color: var(--ink);
  white-space: nowrap;
}

.diff-indicator {
  display: inline-block;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--passion-600);
}

.perm-desc {
  font-size: 12.5px;
  line-height: 1.25;
  color: var(--ink-500);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.perm-desc.changed {
  color: var(--passion-text);
  font-weight: 500;
}

.perm-control {
  flex: 0 0 336px;
  width: 336px;
  min-width: 336px;
  display: inline-flex;
  background: var(--cream-200);
  border-radius: var(--radius-sm, 6px);
  padding: 2px;
  gap: 2px;
  box-sizing: border-box;
}

.seg-btn {
  flex: 1 1 0;
  min-width: 0;
  height: 28px;
  padding: 0 4px;
  border: none;
  border-radius: 4px;
  background: transparent;
  color: var(--ink-500);
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 500;
  line-height: 1;
  text-align: center;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  cursor: pointer;
  transition: all 0.15s ease;
  user-select: none;
}

.seg-btn:hover:not(.is-active) {
  background: rgba(0, 0, 0, 0.04);
  color: var(--ink);
}

.seg-btn:focus-visible {
  outline: 2px solid var(--acai-700);
  outline-offset: 1px;
}

/* Nivel 'none' seleccionado: superficie limpia con suave sombra */
.seg-btn.is-none {
  background: var(--surface);
  color: var(--ink);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  font-weight: 600;
}

/* Niveles con acceso ('read', 'edit') seleccionados: fondo morado açaí */
.seg-btn.is-access {
  background: var(--acai-700);
  color: #ffffff;
  font-weight: 600;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
}

/* Modo oscuro */
html.dark .perm-row {
  border-bottom-color: rgba(255, 255, 255, 0.07);
}

html.dark .perm-control {
  background: rgba(255, 255, 255, 0.06);
}

html.dark .seg-btn:hover:not(.is-active) {
  background: rgba(255, 255, 255, 0.06);
  color: var(--ink);
}

html.dark .seg-btn.is-none {
  background: var(--surface);
  color: var(--ink);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
}

html.dark .seg-btn.is-access {
  background: var(--acai-700);
  color: #ffffff;
}

/* Responsive para pantallas estrechas */
@media (max-width: 760px) {
  .perm-row {
    flex-direction: column;
    align-items: stretch;
    padding: 6px 0;
    gap: 8px;
  }
  .perm-control {
    flex: 1 1 auto;
    width: 100%;
    min-width: 0;
  }
}
</style>
