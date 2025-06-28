import { createApp, defineAsyncComponent } from 'vue'
import { createPinia } from 'pinia'
import axios from 'axios'
import App from './App.vue'
import router from './router'
import './assets/main.css'
// Configure axios
axios.defaults.baseURL = import.meta.env.VITE_API_BASE_URL || '/'
axios.interceptors.response.use(
  (response) => response,
  (error) => {
    console.error('API Error:', error)
    return Promise.reject(error)
  },
)

const app = createApp(App)

// Initialize Pinia
const pinia = createPinia()
app.use(pinia)

// Register global components dynamically
const requireComponent = import.meta.glob('./components/ui/*.vue')
Object.entries(requireComponent).forEach(([path, component]) => {
  const componentName = path
    .split('/')
    .pop()
    .replace(/\.\w+$/, '')
  app.component(componentName, defineAsyncComponent(component))
})

app.use(router)

// Mount the app after router is ready
router
  .isReady()
  .then(() => {
    app.mount('#app')
  })
  .catch((error) => {
    console.error('Router initialization failed:', error)
  })
