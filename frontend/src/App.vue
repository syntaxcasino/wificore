<template>
  <div class="min-h-screen bg-gray-100">
    <!-- Header -->
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
          <router-link to="/" class="hover:underline">Home</router-link>
          <router-link to="/logs" class="hover:underline">Logs</router-link>
        </nav>
      </div>
      <!-- Mobile Menu -->
      <nav v-if="menuOpen" class="sm:hidden bg-blue-700 px-4 py-2" aria-label="Mobile navigation">
        <router-link to="/" class="block py-2 hover:underline" @click="toggleMenu"
          >Home</router-link
        >
        <router-link to="/logs" class="block py-2 hover:underline" @click="toggleMenu"
          >Logs</router-link
        >
      </nav>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
      <router-view />
    </main>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white py-4">
      <div class="container mx-auto px-4 sm:px-6 lg:px-8 text-center text-sm sm:text-base">
        &copy; {{ new Date().getFullYear() }} WiFi Hotspot. All rights reserved.
      </div>
    </footer>
  </div>
</template>

<script>
export default {
  data() {
    return {
      menuOpen: false,
    }
  },
  methods: {
    toggleMenu() {
      this.menuOpen = !this.menuOpen
    },
  },
}
</script>
