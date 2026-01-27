import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueJsx from '@vitejs/plugin-vue-jsx'
import vueDevTools from 'vite-plugin-vue-devtools'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueJsx(),
    vueDevTools(),
    tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.ico', 'apple-touch-icon.png', 'mask-icon.svg'],
      manifest: {
        name: 'TraidNet WiFi Hotspot',
        short_name: 'TraidNet',
        description: 'Multi-tenant WiFi Hotspot Management System',
        theme_color: '#3b82f6',
        background_color: '#ffffff',
        display: 'standalone',
        scope: '/',
        start_url: '/login',
        orientation: 'portrait-primary',
        icons: [
          {
            src: '/pwa-192x192.png',
            sizes: '192x192',
            type: 'image/png',
            purpose: 'any'
          },
          {
            src: '/pwa-512x512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'any'
          },
          {
            src: '/pwa-512x512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable'
          }
        ]
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,ico,png,svg,woff,woff2}'],
        runtimeCaching: [
          {
            // Real-time dashboard and system stats - NEVER cache
            urlPattern: /\/(api\/)?(dashboard|system)\/(stats|metrics|queue|health)/i,
            handler: 'NetworkOnly'
          },
          {
            // Other API calls - short cache for performance
            urlPattern: /^https:\/\/api\..*/i,
            handler: 'NetworkFirst',
            options: {
              cacheName: 'api-cache',
              expiration: {
                maxEntries: 20,
                maxAgeSeconds: 30 // 30 seconds only
              },
              networkTimeoutSeconds: 3,
              cacheableResponse: {
                statuses: [0, 200]
              }
            }
          }
        ]
      },
      devOptions: {
        enabled: true,
        type: 'module'
      }
    })
  ],
    build: {
    outDir: 'dist',
    external: [], // <-- this is default, but you can explicitly set it
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (!id.includes('node_modules')) return

          if (id.includes('/vue/') || id.includes('\u0000vue')) return 'vendor-vue'
          if (id.includes('/pinia/')) return 'vendor-pinia'
          if (id.includes('/axios/')) return 'vendor-axios'
          if (id.includes('/laravel-echo/') || id.includes('/pusher-js/')) return 'vendor-realtime'

          return 'vendor'
        },
      },
    },
  },
   base: '/', // important for Nginx SPA routing
    server: {
       allowedHosts: ['thotspot.pagekite.me', 'localhost'],
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
