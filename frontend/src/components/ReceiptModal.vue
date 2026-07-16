<script setup>
import { computed } from 'vue';

const props = defineProps({
  show: Boolean,
  sale: Object
});

const emit = defineEmits(['close']);

const change = computed(() => props.sale?.change || 0);
</script>

<template>
  <!-- Uses global .modal-overlay, .modal-card, .modal-head, .modal-body, .modal-foot,
       .receipt-check, .receipt-id, .receipt-change, .receipt-line from style.css -->
  <div class="modal-overlay" :class="{ active: show }">
    <div class="modal-card">
      <div class="modal-body" style="padding-top:26px;text-align:center;">
        <div class="receipt-check">✓</div>
        <h2 style="margin:0 0 4px;font-size:22px;color:var(--acai-900);">Venta registrada</h2>
        <div class="receipt-id">
          ID venta · <span class="mono">{{ sale?.id?.split('-')[0].toUpperCase() || '...' }}</span>
        </div>

        <div class="receipt-change" v-if="change > 0">
          <div style="font-size:11px;text-transform:uppercase;font-weight:700;color:var(--ink-500);">Cambio entregado</div>
          <div style="font-family:'Baloo 2';font-size:26px;font-weight:800;color:var(--acai-900);">Bs {{ change.toFixed(2) }}</div>
        </div>
      </div>

      <div style="padding:0 24px 4px;text-align:left;">
        <div class="receipt-line" v-for="(item, index) in sale?.items" :key="index">
          <span>{{ item.quantity }}x {{ item.name }}{{ item.size ? ' · ' + item.size : '' }}</span>
          <span class="mono">Bs {{ Number(item.subtotal || 0).toFixed(2) }}</span>
        </div>
      </div>

      <div class="modal-foot">
        <button class="btn btn-primary" style="width:100%;" @click="emit('close')">
          Nueva venta
        </button>
      </div>
    </div>
  </div>
</template>
