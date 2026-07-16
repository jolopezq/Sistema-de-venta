<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import NetworkIndicator from '../components/NetworkIndicator.vue';

const router = useRouter();
const auth = useAuthStore();

// Mock data for initial turn stats
const initialCash = ref(200);
const cashSales = ref(450);
const qrSales = ref(320);
const cardSales = ref(150);

const countedCash = ref(645);

const theoreticalCash = computed(() => initialCash.value + cashSales.value);
const cashDifference = computed(() => countedCash.value - theoreticalCash.value);

function handleCloseTurn() {
  // Mock close turn
  alert('Caja cerrada con éxito.');
  router.push('/pos');
}
</script>

<template>
  <div class="turno-layout">
    <header class="pos-header">
      <div class="pos-brand">
        <div class="logo-chip"></div>
        <span>Gestión de turno</span>
      </div>
      <div class="pos-header-right">
        <div class="sync-pill">
          <NetworkIndicator />
        </div>
        <button class="btn-sm btn-ghost" style="border:1px solid rgba(255,255,255,0.2);color:white;background:transparent;font-family:Inter;font-weight:600;" @click="router.push('/pos')">Volver al POS</button>
      </div>
    </header>
    <div class="turno-wrap">
      <div class="turno-card">
        <h2>Cierre de caja — Arqueo</h2>
        <p class="hint">Turno de {{ auth.user?.name || 'Cajero' }} · Iniciado 08:02 hrs · 16 jul 2026</p>
        <div class="turno-grid">
          <div class="turno-stat">
            <div class="k">Efectivo inicial</div>
            <div class="v">Bs {{ initialCash.toFixed(2) }}</div>
          </div>
          <div class="turno-stat">
            <div class="k">Ventas en efectivo</div>
            <div class="v">Bs {{ cashSales.toFixed(2) }}</div>
          </div>
          <div class="turno-stat">
            <div class="k">Ventas QR</div>
            <div class="v">Bs {{ qrSales.toFixed(2) }}</div>
          </div>
          <div class="turno-stat">
            <div class="k">Ventas tarjeta</div>
            <div class="v">Bs {{ cardSales.toFixed(2) }}</div>
          </div>
        </div>
        <div class="turno-stat" style="margin-bottom:16px;">
          <div class="k">Total teórico en caja (efectivo)</div>
          <div class="v">Bs {{ theoreticalCash.toFixed(2) }}</div>
        </div>
        <div class="field">
          <label>Efectivo físico contado</label>
          <input type="number" class="search-input" v-model="countedCash">
        </div>
        
        <div v-if="cashDifference !== 0" class="turno-diff" :class="cashDifference < 0 ? 'faltante' : 'sobrante'">
          <div style="font-size:11px;text-transform:uppercase;font-weight:700;">Diferencia detectada</div>
          <div class="amt">
            {{ cashDifference < 0 ? '−' : '+' }} Bs {{ Math.abs(cashDifference).toFixed(2) }} 
            ({{ cashDifference < 0 ? 'faltante' : 'sobrante' }})
          </div>
        </div>
        <div v-else class="turno-diff sobrante">
          <div style="font-size:11px;text-transform:uppercase;font-weight:700;">Diferencia detectada</div>
          <div class="amt">Caja cuadrada exacta</div>
        </div>
        
        <button class="btn btn-primary" style="width:100%;margin-top:16px;" @click="handleCloseTurn">Cerrar caja e imprimir reporte</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.turno-layout {
  display: flex;
  flex-direction: column;
  height: 100vh;
  width: 100vw;
  background-color: var(--cream-100);
  overflow-y: auto;
}

.pos-header {
  background: var(--acai-900);
  color: white;
  padding: 12px 24px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.pos-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  font-family: 'Baloo 2';
  font-weight: 700;
  font-size: 20px;
}
.logo-chip {
  width: 32px;
  height: 32px;
  background: white;
  border-radius: 8px;
  background-image: var(--logo-uri);
  background-size: 72%;
  background-position: center;
  background-repeat: no-repeat;
}
.pos-header-right {
  display: flex;
  align-items: center;
  gap: 16px;
}
.sync-pill {
  display: flex;
  align-items: center;
  gap: 8px;
  background: rgba(255,255,255,0.1);
  padding: 6px 14px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
}
.btn-ghost {
  cursor: pointer;
  padding: 6px 14px;
  border-radius: 8px;
  transition: background 0.2s;
}
.btn-ghost:hover {
  background: rgba(255,255,255,0.1) !important;
}

.turno-wrap {
  padding: 32px;
  max-width: 640px;
  margin: 0 auto;
  width: 100%;
}
.turno-card {
  background: white;
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-card);
  padding: 26px 28px;
  margin-bottom: 18px;
}
.turno-card h2 {
  margin: 0 0 4px;
  font-size: 19px;
  color: var(--acai-900);
}
.turno-card .hint {
  font-size: 13px;
  color: var(--ink-500);
  margin-bottom: 18px;
}
.turno-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 16px;
}
.turno-stat {
  background: var(--cream-50);
  border-radius: 12px;
  padding: 14px 16px;
}
.turno-stat .k {
  font-size: 11px;
  text-transform: uppercase;
  font-weight: 700;
  color: var(--ink-500);
  letter-spacing: .04em;
}
.turno-stat .v {
  font-family: 'Baloo 2', sans-serif;
  font-size: 20px;
  font-weight: 700;
  color: var(--acai-900);
  margin-top: 2px;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 16px;
}
.field label {
  font-size: 13px;
  font-weight: 700;
  color: var(--ink-900);
}
.search-input {
  padding: 12px 16px;
  border-radius: 12px;
  border: 2px solid var(--border);
  font-size: 16px;
  font-family: 'Baloo 2', sans-serif;
  font-weight: 700;
  color: var(--acai-900);
}
.search-input:focus {
  outline: none;
  border-color: var(--passion-500);
}
.turno-diff {
  border-radius: 12px;
  padding: 16px;
  text-align: center;
  margin: 14px 0;
}
.turno-diff.faltante {
  background: var(--danger-100);
  color: var(--danger-600);
}
.turno-diff.sobrante {
  background: var(--lime-100);
  color: var(--lime-700);
}
.turno-diff .amt {
  font-family: 'Baloo 2', sans-serif;
  font-size: 24px;
  font-weight: 800;
}

.btn-primary {
  background: var(--passion-500);
  color: white;
  border: none;
  border-radius: var(--radius-md);
  padding: 16px;
  font-weight: 700;
  font-size: 16px;
  cursor: pointer;
  transition: transform 0.1s;
}
.btn-primary:hover {
  background: var(--passion-600);
}
.btn-primary:active {
  transform: scale(0.98);
}
</style>
