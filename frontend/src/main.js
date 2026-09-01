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

// Inicialización de la aplicación Vue (IndexedDB es gestionada de forma segura por Dexie.js con control de versiones)

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
