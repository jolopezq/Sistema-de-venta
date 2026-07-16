<script setup>
import { ref } from 'vue'

const props = defineProps({
  show: Boolean,
  title: {
    type: String,
    default: 'Autorización requerida'
  },
  message: {
    type: String,
    default: 'Ingresa tu PIN de administrador'
  }
})

const emit = defineEmits(['close', 'submit'])

const pin = ref('')

const handleSubmit = () => {
  emit('submit', pin.value)
  pin.value = ''
}

const handleClose = () => {
  emit('close')
  pin.value = ''
}
</script>

<template>
  <div class="modal-overlay" :class="{ active: show }">
    <div class="modal-card">
      <div class="modal-head">
        <h2>{{ title }}</h2>
        <button class="modal-close" @click="handleClose">✕</button>
      </div>
      <div class="modal-body">
        <p style="margin-bottom: 16px; color: var(--ink-700); font-size: 14px;">{{ message }}</p>
        <div style="display: flex; justify-content: center; margin-bottom: 20px;">
          <input 
            type="password" 
            v-model="pin" 
            maxlength="4"
            placeholder="••••"
            style="font-size: 32px; letter-spacing: 12px; text-align: center; width: 140px; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px; background: var(--cream-100);"
            @keyup.enter="handleSubmit"
          >
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-ghost" @click="handleClose">Cancelar</button>
        <button class="btn btn-primary" @click="handleSubmit">Confirmar</button>
      </div>
    </div>
  </div>
</template>
