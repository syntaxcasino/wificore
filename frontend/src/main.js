import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import axios from 'axios'

// Configure axios base URL
axios.defaults.baseURL = '/'
const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
