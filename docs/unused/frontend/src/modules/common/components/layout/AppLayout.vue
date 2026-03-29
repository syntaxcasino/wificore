<template>
  <div class="flex flex-col h-full">
    <AppTopbar class="fixed top-0 left-0 right-0 z-50 h-16" @toggle-sidebar="toggleSidebar" />
    <div class="flex flex-1 pt-16 overflow-hidden">
      <AppSidebar
        :isSidebarOpen="isSidebarOpen"
        :isMobile="isMobile"
        @close-sidebar="toggleSidebar"
      />
      <main
        class="flex-1 overflow-y-auto bg-gray-100 transition-all duration-300 z-10 p-6"
        :class="{ 'ml-64': isSidebarOpen && !isMobile, 'ml-0': !isSidebarOpen || isMobile }"
        @click="closeSidebarOnClickOutside"
      >
        <router-view />
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import AppTopbar from './AppTopbar.vue'
import AppSidebar from './AppSidebar.vue'

// State for sidebar visibility and mobile detection
const isMobile = ref(window.innerWidth < 768)
const isSidebarOpen = ref(!isMobile.value) // Sidebar hidden on mobile by default

// Toggle sidebar function
const toggleSidebar = () => {
  isSidebarOpen.value = !isSidebarOpen.value
}

// Close sidebar when clicking outside on mobile
const closeSidebarOnClickOutside = () => {
  if (isMobile.value && isSidebarOpen.value) {
    isSidebarOpen.value = false
  }
}

// Handle window resize to update isMobile and sidebar state
const handleResize = () => {
  const wasMobile = isMobile.value
  isMobile.value = window.innerWidth < 768
  // Set sidebar state based on mobile/desktop transition
  if (isMobile.value && !wasMobile) {
    isSidebarOpen.value = false // Hide sidebar on mobile
  } else if (!isMobile.value && wasMobile) {
    isSidebarOpen.value = true // Show sidebar on desktop
  }
}

// Set up resize listener
onMounted(() => {
  window.addEventListener('resize', handleResize)
  handleResize() // Initial check
})

// Clean up resize listener
onUnmounted(() => {
  window.removeEventListener('resize', handleResize)
})
</script>
