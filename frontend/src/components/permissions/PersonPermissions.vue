<script setup>
/**
 * Permisos de una persona en la ficha de Usuarios: Finanzas, ventas de días pasados y corregir cajas.
 * Solo se dan a administradores. El valor es el nivel efectivo; al guardar, si coincide con el de su rol
 * se manda null (sigue a su rol) y si no, queda como excepción propia.
 */
import { computed } from 'vue';
import { PERSON_PERMISSION_MODULES, personRoleLevel } from '../../utils/permissionModules';

const props = defineProps({
  role: { type: String, required: true },
  modelValue: { type: Object, required: true },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const canReceive = computed(() => props.role === 'admin');

function levelOf(id) {
  return props.modelValue[id] ?? personRoleLevel(props.role, id);
}

function select(id, level) {
  emit('update:modelValue', { ...props.modelValue, [id]: level });
}
</script>

<template>
  <fieldset class="pp-root">
    <legend class="pp-title">Permisos especiales</legend>

    <p v-if="role === 'super_admin'" class="pp-note">
      El Super Admin tiene todos los permisos, también usuarios, auditoría y respaldos.
    </p>
    <p v-else-if="!canReceive" class="pp-note">
      Finanzas, ventas de días pasados y corregir cajas solo se dan a administradores.
    </p>
    <p v-else-if="loading" class="pp-note">Cargando permisos…</p>

    <div v-else class="pp-list">
      <div v-for="mod in PERSON_PERMISSION_MODULES" :key="mod.id" class="pp-row" :data-module="mod.id">
        <div class="pp-info">
          <span class="pp-name">
            {{ mod.name }}
            <span v-if="levelOf(mod.id) !== personRoleLevel(role, mod.id)" class="pp-own">propio</span>
          </span>
          <span class="pp-desc">{{ mod.desc }}</span>
        </div>
        <div class="pp-control" role="group" :aria-label="mod.name">
          <button
            v-for="lvl in Object.keys(mod.levelLabels)"
            :key="lvl"
            type="button"
            class="pp-seg"
            :class="{ 'is-none': lvl === 'none' && levelOf(mod.id) === lvl, 'is-access': lvl !== 'none' && levelOf(mod.id) === lvl }"
            :aria-pressed="levelOf(mod.id) === lvl"
            :aria-label="`${mod.name}: ${mod.levelLabels[lvl]}`"
            @click="select(mod.id, lvl)"
          >{{ mod.levelLabels[lvl] }}</button>
        </div>
      </div>
    </div>
  </fieldset>
</template>

<style scoped>
.pp-root {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 8px 12px 10px;
  margin: 4px 0 0;
  min-width: 0;
}
.pp-title {
  padding: 0 4px;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--ink-700);
}
.pp-note {
  margin: 2px 0 0;
  font-size: 12.5px;
  color: var(--ink-500);
}
.pp-list { display: flex; flex-direction: column; }
.pp-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 6px 0;
  border-bottom: 1px solid var(--cream-200);
}
.pp-row:last-child { border-bottom: none; }
.pp-info { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 1px; }
.pp-name { font-size: 13.5px; font-weight: 600; color: var(--ink); }
.pp-own {
  margin-left: 4px;
  padding: 0 6px;
  border-radius: 99px;
  font-size: 11px;
  font-weight: 600;
  background: var(--passion-100);
  color: var(--passion-text);
}
.pp-desc { font-size: 12px; line-height: 1.3; color: var(--ink-500); }
.pp-control {
  flex: 0 0 auto;
  display: inline-flex;
  gap: 2px;
  padding: 2px;
  border-radius: 6px;
  background: var(--cream-200);
}
.pp-seg {
  height: 28px;
  padding: 0 10px;
  border: none;
  border-radius: 4px;
  background: transparent;
  color: var(--ink-500);
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 500;
  white-space: nowrap;
  cursor: pointer;
}
.pp-seg:hover:not(.is-none):not(.is-access) { color: var(--ink); background: rgba(0, 0, 0, 0.04); }
.pp-seg:focus-visible { outline: 2px solid var(--acai-700); outline-offset: 1px; }
.pp-seg.is-none { background: var(--surface); color: var(--ink); font-weight: 600; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); }
.pp-seg.is-access { background: var(--acai-700); color: #fff; font-weight: 600; }
html.dark .pp-row { border-bottom-color: rgba(255, 255, 255, 0.07); }
html.dark .pp-control { background: rgba(255, 255, 255, 0.06); }
html.dark .pp-seg:hover:not(.is-none):not(.is-access) { background: rgba(255, 255, 255, 0.06); }
@media (max-width: 560px) {
  .pp-row { flex-direction: column; align-items: stretch; }
}
</style>
