<script setup>
import { ref, computed, watch } from 'vue';

/**
 * Ajuste del precio de una línea en un pedido PedidosYa (promo de la plataforma,
 * precio desactualizado...). El motivo es obligatorio y queda en auditoría.
 */
const props = defineProps({
  show: Boolean,
  item: { type: Object, default: null },
});
const emit = defineEmits(['close', 'apply', 'clear']);

const REASONS = ['Promo PedidosYa', 'Precio desactualizado', 'Otro'];

const price = ref('');
const reasonChoice = ref(REASONS[0]);
const otherReason = ref('');
const error = ref('');

const tariffPrice = computed(() => {
  if (!props.item) return 0;
  return Number(props.item.catalog_unit_price ?? props.item.unit_price) || 0;
});
const hasOverride = computed(() => Boolean(props.item?.price_override_reason));
const newPrice = computed(() => Math.round(Number(price.value) * 100) / 100);
const isTariffPrice = computed(() => Math.abs(newPrice.value - tariffPrice.value) < 0.005);
const finalReason = computed(() =>
  reasonChoice.value === 'Otro' ? otherReason.value.trim() : reasonChoice.value
);

watch(() => [props.show, props.item], () => {
  if (!props.show || !props.item) return;
  price.value = Number(props.item.unit_price).toFixed(2);
  error.value = '';
  const current = props.item.price_override_reason;
  if (!current) {
    reasonChoice.value = REASONS[0];
    otherReason.value = '';
  } else if (REASONS.includes(current)) {
    reasonChoice.value = current;
    otherReason.value = '';
  } else {
    reasonChoice.value = 'Otro';
    otherReason.value = current;
  }
}, { immediate: true });

function apply() {
  if (price.value === '' || !Number.isFinite(newPrice.value) || newPrice.value < 0) {
    error.value = 'Ingresa un precio válido.';
    return;
  }
  if (!isTariffPrice.value && !finalReason.value) {
    error.value = 'Escribe el motivo del ajuste.';
    return;
  }
  emit('apply', { price: newPrice.value, reason: finalReason.value });
}
</script>

<template>
  <div v-if="show && item" class="po-backdrop" @click.self="emit('close')">
    <div class="po-card" role="dialog" aria-modal="true" aria-labelledby="po-title">
      <h3 id="po-title" class="po-title">Ajustar precio</h3>
      <p class="po-subtitle">{{ item.name }}</p>

      <div class="po-tariff">
        Precio del tarifario PedidosYa: <strong>Bs {{ tariffPrice.toFixed(2) }}</strong> c/u
      </div>

      <label class="po-label" for="po-price">Precio unitario cobrado (Bs)</label>
      <input
        id="po-price"
        v-model="price"
        type="number"
        min="0"
        step="0.5"
        inputmode="decimal"
        class="po-input"
        @keyup.enter="apply"
      />

      <fieldset class="po-reasons" :disabled="isTariffPrice">
        <legend class="po-label">Motivo</legend>
        <label v-for="r in REASONS" :key="r" class="po-reason">
          <input v-model="reasonChoice" type="radio" name="po-reason" :value="r" />
          <span>{{ r }}</span>
        </label>
        <input
          v-if="reasonChoice === 'Otro'"
          v-model="otherReason"
          type="text"
          maxlength="200"
          class="po-input"
          placeholder="Describe el motivo"
          aria-label="Motivo del ajuste"
        />
      </fieldset>

      <p class="po-hint">
        {{ isTariffPrice
          ? 'Es el precio del tarifario: no hace falta motivo.'
          : 'El ajuste queda registrado en auditoría con tu usuario.' }}
      </p>
      <p v-if="error" class="po-error" role="alert">{{ error }}</p>

      <div class="po-actions">
        <button v-if="hasOverride" type="button" class="po-btn po-btn--ghost" @click="emit('clear')">
          Volver al tarifario
        </button>
        <button type="button" class="po-btn po-btn--ghost" @click="emit('close')">Cancelar</button>
        <button type="button" class="po-btn po-btn--primary" @click="apply">Aplicar</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.po-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1100;
  padding: 16px;
}
.po-card {
  background: var(--surface);
  color: var(--ink);
  border: 1px solid var(--border);
  border-radius: 18px;
  width: 100%;
  max-width: 380px;
  padding: 22px 20px 18px;
  box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.3);
}
.po-title {
  margin: 0;
  font-size: 18px;
  font-weight: 800;
}
.po-subtitle {
  margin: 2px 0 12px;
  font-size: 14px;
  color: var(--ink-700);
}
.po-tariff {
  font-size: 13px;
  padding: 8px 10px;
  border-radius: 10px;
  background: #FFE4EC;
  color: #8A0030;
  margin-bottom: 12px;
}
html.dark .po-tariff {
  background: #3D1424;
  color: #FFB3C9;
}
.po-label {
  display: block;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--ink-700);
  margin-bottom: 4px;
  padding: 0;
}
.po-input {
  width: 100%;
  box-sizing: border-box;
  padding: 9px 11px;
  border-radius: 10px;
  border: 1.5px solid var(--border);
  background: var(--surface);
  color: var(--ink);
  font-family: inherit;
  font-size: 15px;
  margin-bottom: 12px;
}
.po-input:focus-visible {
  outline: 2px solid var(--acai-700);
  outline-offset: 1px;
}
.po-reasons {
  border: none;
  margin: 0;
  padding: 0;
}
.po-reasons:disabled {
  opacity: 0.55;
}
.po-reason {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  padding: 4px 0;
  cursor: pointer;
}
.po-reasons .po-input {
  margin-top: 6px;
}
.po-hint {
  margin: 4px 0 0;
  font-size: 12px;
  color: var(--ink-500);
}
.po-error {
  margin: 8px 0 0;
  font-size: 13px;
  font-weight: 700;
  color: var(--tw-fg-dc2626);
}
.po-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 16px;
}
.po-btn {
  padding: 10px 14px;
  border-radius: 11px;
  font-family: inherit;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
.po-btn--ghost {
  background: var(--surface-hover);
  color: var(--ink-700);
  border: 1px solid var(--border);
}
.po-btn--primary {
  background: #C2003F;
  color: #fff;
  border: 1px solid #C2003F;
}
.po-btn:focus-visible {
  outline: 2px solid var(--acai-700);
  outline-offset: 2px;
}
</style>
