<template>
  <div class="dashboard-layout">
    <Sidebar :isSidebarOpen="isSidebarOpen" @toggle-sidebar="toggleSidebar" />

    <div class="main-content" :class="{ 'sidebar-closed': !isSidebarOpen }">
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

// Close sidebar when clicking outside on mobile
const closeSidebarOnClickOutside = (event) => {
  if (isMobile.value && isSidebarOpen.value) {
    const sidebar = document.querySelector('.sidebar')
    if (sidebar && !sidebar.contains(event.target)) {
      isSidebarOpen.value = false
    }
  }
}

onMounted(() => {
  window.addEventListener('resize', handleResize)
  document.addEventListener('click', closeSidebarOnClickOutside)
})

onUnmounted(() => {
  window.removeEventListener('resize', handleResize)
  document.removeEventListener('click', closeSidebarOnClickOutside)
})
</script>

<style scoped>
.dashboard-layout {
  display: flex;
  min-height: 100vh;
  background-color: #f5f7fa;
}

.main-content {
  flex: 1;
  margin-left: 256px; /* Sidebar width (w-64 = 256px) */
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  transition: margin-left 0.3s ease-in-out;
}

/* When sidebar is closed, expand content to full width */
.main-content.sidebar-closed {
  margin-left: 0;
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
    margin-left: 0;
  }

  .sidebar {
    transform: translateX(-100%);
    /* Add mobile toggle functionality */
  }

  .sidebar.open {
    transform: translateX(0);
  }
}
</style>
