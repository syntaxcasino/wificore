<template>
  <div class="min-h-screen bg-gray-100">
    <!-- Header with lazy-loaded navigation -->
    <header class="bg-blue-600 text-white shadow-md">
      <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
        <h1 class="text-xl sm:text-2xl font-bold">WiFi Hotspot</h1>
        <button
          class="sm:hidden focus:outline-none"
          @click="toggleMenu"
          aria-label="Toggle navigation menu"
        >
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              v-if="!menuOpen"
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 6h16M4 12h16M4 18h16"
            />
            <path
              v-else
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M6 18L18 6M6 6l12 12"
            />
          </svg>
        </button>
        <nav class="hidden sm:flex space-x-4" aria-label="Main navigation">
          <router-link
            v-for="link in navLinks"
            :key="link.path"
            :to="link.path"
            class="hover:underline transition-colors duration-200"
            active-class="font-bold"
          >
            {{ link.name }}
          </router-link>
        </nav>
      </div>

      <!-- Lazy-loaded mobile menu -->
      <LazyMobileMenu v-if="menuOpen" :links="navLinks" @close="toggleMenu" />
    </header>

    <!-- Main Content with Suspense -->
    <main class="container mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
      <router-view />
    </main>

    <!-- Lazy-loaded footer -->
    <LazyAppFooter />
  </div>
</template>

<script setup>
import { ref, defineAsyncComponent } from 'vue'
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue'

const LazyAppFooter = defineAsyncComponent(() => import('@/components/ui/AppFooter.vue'))
const LazyMobileMenu = defineAsyncComponent(() => import('@/components/ui/MobileMenu.vue'))

const menuOpen = ref(false)
const navLinks = [
  { path: '/', name: 'Home' },
  { path: '/logs', name: 'Logs' },
]

const toggleMenu = () => {
  menuOpen.value = !menuOpen.value
}
</script>
