<template>
  <div class="max-h-screen bg-gray-100">
    <header class="bg-blue-600 text-white shadow-md" v-if="showHeader">
      <div class="container mx-auto px-4 py-4 flex justify-between items-center">
        <h1 class="text-xl font-bold">Traidnet Hotspot</h1>
        <nav class="hidden sm:flex space-x-4">
          <router-link
            v-for="link in navLinks"
            :key="link.path"
            :to="link.path"
            class="hover:underline"
          >
            {{ link.name }}
          </router-link>
          <router-link v-if="isAuthenticated" to="/dashboard">Dashboard</router-link>
          <a v-if="isAuthenticated" @click.prevent="logout" href="#" class="hover:underline"
            >Logout</a
          >
          <router-link v-else to="/login">Login</router-link>
        </nav>
      </div>
    </header>

    <main class="container mx-auto px-4 py-6">
      <router-view />
    </main>

    <LazyAppFooter />
  </div>
</template>

<script setup>
import { defineAsyncComponent, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuth } from '@/composables/useAuth'

const LazyAppFooter = defineAsyncComponent(() => import('@/components/ui/AppFooter.vue'))
const { isAuthenticated, logout } = useAuth()

const route = useRoute()

const showHeader = computed(() => route.path !== '/login')

const navLinks = [
  { path: '/', name: 'Home' },
  { path: '/logs', name: 'Logs' },
]
</script>
