<template>
  <div class="dashboard-layout">
    <!-- Mobile overlay -->
    <div 
      v-if="isMobile && isSidebarOpen" 
      class="mobile-overlay"
      @click="closeSidebar"
    ></div>

    <Sidebar 
      :isSidebarOpen="isSidebarOpen" 
      :isMobile="isMobile"
      @toggle-sidebar="toggleSidebar"
      @close-sidebar="closeSidebar" 
    />

    <div class="main-content" :class="{ 'sidebar-closed': !isSidebarOpen, 'mobile': isMobile }">
      <AppHeader @toggle-sidebar="toggleSidebar" />

      <div class="content-area">
        <!-- Router view for nested routes -->
        <router-view />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import Sidebar from '@/modules/common/components/layout/AppSidebar.vue'
import AppHeader from '@/modules/common/components/layout/AppTopbar.vue'

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

<style scoped>
.dashboard-layout {
  display: flex;
  min-height: 100vh;
  background-color: #f5f7fa;
  position: relative;
}

/* Mobile overlay */
.mobile-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.5);
  z-index: 90;
  animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

.main-content {
  flex: 1;
  margin-left: 256px; /* Sidebar width (w-64 = 256px) */
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  transition: margin-left 0.3s ease-in-out;
  width: calc(100% - 256px);
}

/* When sidebar is closed, expand content to full width */
.main-content.sidebar-closed {
  margin-left: 0;
  width: 100%;
}

/* Mobile: always full width */
.main-content.mobile {
  margin-left: 0 !important;
  width: 100% !important;
}

.content-area {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 20px;
  padding-top: 80px; /* Add top padding to prevent content from being hidden under topbar */
  position: relative;
}

/* Responsive adjustments */
@media (max-width: 768px) {
  .main-content {
    margin-left: 0 !important;
    width: 100% !important;
  }

  .content-area {
    padding: 12px;
    padding-top: 72px;
  }
}
</style>
