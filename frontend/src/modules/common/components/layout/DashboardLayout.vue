<template>
  <div class="flex min-h-screen bg-[#f5f7fa] dark:bg-slate-950 relative transition-colors duration-200">
    <!-- Mobile overlay -->
    <div
      v-if="isMobile && isSidebarOpen"
      class="fixed inset-0 bg-black/50 z-[90] animate-[fadeIn_0.3s_ease-in-out]"
      @click="closeSidebar"
    ></div>

    <component
      :is="isSystemAdmin ? AdminSidebar : AppSidebar"
      :isSidebarOpen="isSidebarOpen"
      :isMobile="isMobile"
      @toggle-sidebar="toggleSidebar"
      @close-sidebar="closeSidebar"
    />

    <div
      class="flex flex-col min-h-screen transition-[margin-left,width] duration-300 ease-in-out"
      :class="[
        isMobile || !isSidebarOpen
          ? 'ml-0 w-full'
          : 'ml-64 w-[calc(100%-16rem)]'
      ]"
    >
      <AppHeader @toggle-sidebar="toggleSidebar" />

      <div
        class="flex-1 flex flex-col min-h-0 overflow-y-auto overflow-x-hidden relative transition-colors duration-200 dark:bg-slate-950"
        :class="isMobile ? 'pt-[66px] sm:pt-[62px]' : 'pt-20'"
      >
        <router-view />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import AppSidebar from '@/modules/common/components/layout/AppSidebar.vue'
import AdminSidebar from '@/modules/common/components/layout/AdminSidebar.vue'
import AppHeader from '@/modules/common/components/layout/AppTopbar.vue'

const authStore = useAuthStore()
const isSystemAdmin = computed(() => authStore.isSystemAdmin)

// Mobile detection
const isMobile = ref(window.innerWidth < 768)

// Sidebar state - auto-hide on mobile, open on desktop
const isSidebarOpen = ref(!isMobile.value)

const toggleSidebar = () => {
  isSidebarOpen.value = !isSidebarOpen.value
}

const closeSidebar = () => {
  isSidebarOpen.value = false
}

// Handle window resize
const handleResize = () => {
  const wasMobile = isMobile.value
  isMobile.value = window.innerWidth < 768
  
  // Auto-adjust sidebar on screen size change
  if (isMobile.value && !wasMobile) {
    isSidebarOpen.value = false // Hide sidebar when switching to mobile
  } else if (!isMobile.value && wasMobile) {
    isSidebarOpen.value = true // Show sidebar when switching to desktop
  }
}

onMounted(() => {
  window.addEventListener('resize', handleResize)
})

onUnmounted(() => {
  window.removeEventListener('resize', handleResize)
})
</script>

