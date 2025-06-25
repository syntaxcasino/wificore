import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueJsx from '@vitejs/plugin-vue-jsx'
import vueDevTools from 'vite-plugin-vue-devtools'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueJsx(),
    vueDevTools(),
    tailwindcss(),
  ],
    server: {
       allowedHosts: ['3050-41-90-172-176.ngrok-free.app', 'localhost'],
    hmr: {
      host: 'localhost',
      port: 3000,
      protocol: 'ws',
      clientPort: 80 // Match Nginx's external port (or 8080 if changed)
    },
    host: '0.0.0.0',
    port: 3000
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    },
  },
})
