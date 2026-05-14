import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueJsx from '@vitejs/plugin-vue-jsx'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueJsx(),
    // Note: vueDevTools removed from production - only enable during development
    // import vueDevTools from 'vite-plugin-vue-devtools' and add to plugins[] for dev
    tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: 'auto',
      includeAssets: ['favicon.ico', 'apple-touch-icon.png'],
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
        skipWaiting: true,
        clientsClaim: true,
        cleanupOutdatedCaches: true,
        globPatterns: [
          '**/*.{ico,png,svg,woff,woff2,webmanifest}'
        ],
        navigateFallback: 'index.html',
        navigateFallbackDenylist: [/^\/api\//, /^\/broadcasting\//],
        
        // Precaching configuration for critical assets
        modifyURLPrefix: {
          '': ''
        },
        
        // Runtime caching strategies
        runtimeCaching: [
          {
            // CSS assets - Cache first for performance
            urlPattern: /\/assets\/styles\/.*\.css$/,
            handler: 'CacheFirst',
            options: {
              cacheName: 'css-assets',
              expiration: {
                maxEntries: 50,
                maxAgeSeconds: 30 * 24 * 60 * 60 // 30 days
              },
              cacheableResponse: {
                statuses: [0, 200]
              }
            }
          },
          {
            // Images - Cache first with limit
            urlPattern: /\.(?:png|jpg|jpeg|svg|gif|webp)$/,
            handler: 'CacheFirst',
            options: {
              cacheName: 'images',
              expiration: {
                maxEntries: 200,
                maxAgeSeconds: 60 * 24 * 60 * 60 // 60 days
              },
              cacheableResponse: {
                statuses: [0, 200]
              }
            }
          },
          {
            // Fonts - Cache first, long TTL
            urlPattern: /\.(?:woff2?|ttf|otf|eot)$/,
            handler: 'CacheFirst',
            options: {
              cacheName: 'fonts',
              expiration: {
                maxEntries: 30,
                maxAgeSeconds: 365 * 24 * 60 * 60 // 1 year
              },
              cacheableResponse: {
                statuses: [0, 200]
              }
            }
          },
          {
            // API calls (including SSE/stream endpoints) must never be cached by SW.
            // Caching streamed/chunked responses causes Cache.put() network errors.
            urlPattern: /\/api\//,
            handler: 'NetworkOnly'
          }
        ],
        
        // Disable precache manifest generation warnings
        disableDevLogs: true
      },
      devOptions: {
        enabled: false
      }
    })
  ],
  build: {
    outDir: 'dist',
    // Enable minification optimizations
    minify: 'terser',
    terserOptions: {
      compress: {
        drop_console: true, // Remove console.* calls in production
        drop_debugger: true, // Remove debugger statements
        pure_funcs: ['console.log', 'console.info', 'console.debug', 'console.warn'],
      },
      mangle: {
        safari10: true, // Fix for Safari 10/11
      },
    },
    // Increase chunk size warning limit (vendor bundle is large but necessary)
    chunkSizeWarningLimit: 1000,
    rollupOptions: {
      output: {
        // Improved manual chunking for better caching
        manualChunks(id) {
          if (!id.includes('node_modules')) return

          // Core Vue ecosystem (rarely changes)
          if (id.includes('/vue/') || id.includes('\u0000vue') || id.includes('/vue-router/')) {
            return 'vendor-core'
          }

          // State management (pinia)
          if (id.includes('/pinia/')) return 'vendor-state'

          // HTTP & networking (axios, echo, pusher)
          if (id.includes('/axios/') || id.includes('/laravel-echo/') || id.includes('/pusher-js/')) {
            return 'vendor-network'
          }

          if (id.includes('/@vueuse/')) return 'vendor-vueuse'

          if (id.includes('/lucide-vue-next/')) return 'vendor-icons'

          if (id.includes('/uuid/')) return 'vendor-uuid'

          if (id.includes('/workbox-') || id.includes('/vite-plugin-pwa/')) return 'vendor-pwa'

          if (id.includes('/tailwindcss/') || id.includes('/@tailwindcss/')) return 'vendor-styles'

          // UI utilities (date-fns, lodash, etc.)
          if (id.includes('/date-fns/') || id.includes('/lodash/') || id.includes('/moment/')) {
            return 'vendor-utils'
          }

          // Charts & visualization
          if (id.includes('/chart.') || id.includes('/d3/') || id.includes('/recharts/')) {
            return 'vendor-charts'
          }

          // Everything else
          return 'vendor'
        },
        // Asset file naming for better caching
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: (assetInfo) => {
          const info = assetInfo.name.split('.')
          const ext = info[info.length - 1]
          if (/\.(css)$/i.test(assetInfo.name)) {
            return 'assets/styles/[name]-[hash][extname]'
          }
          if (/\.(png|jpe?g|gif|svg|webp|ico)$/i.test(assetInfo.name)) {
            return 'assets/images/[name]-[hash][extname]'
          }
          if (/\.(woff2?|ttf|otf|eot)$/i.test(assetInfo.name)) {
            return 'assets/fonts/[name]-[hash][extname]'
          }
          return 'assets/[name]-[hash][extname]'
        },
      },
    },
    // Enable source maps only for development
    sourcemap: false,
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
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
})
