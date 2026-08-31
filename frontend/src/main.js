import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import { router } from './router'
import { registerSW } from 'virtual:pwa-register'

const updateSW = registerSW({
  onNeedRefresh() {
    console.log('Nueva versión disponible, por favor recargue la página.')
  },
  onOfflineReady() {
    console.log('La aplicación está lista para funcionar offline.')
  },
})

// Auto-limpieza de caché (IndexedDB) para eliminar datos fantasmas tras reset manual
if (!localStorage.getItem('ohana_db_cleared_v1')) {
  try {
    indexedDB.deleteDatabase('OhanaAcaiDB');
    localStorage.setItem('ohana_db_cleared_v1', 'true');
    console.log('IndexedDB limpiada automáticamente por inconsistencia.');
  } catch (e) {
    console.error('Error al limpiar IndexedDB', e);
  }
}

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
