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

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
