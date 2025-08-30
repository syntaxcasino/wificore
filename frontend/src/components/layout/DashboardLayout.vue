<template>
  <div class="dashboard-layout">
    <Sidebar @menu-click="handleMenuClick" />

    <div class="main-content">
      <AppHeader @logout="handleLogout" />

      <div class="content-area">
        <!-- Dynamic component rendering -->
        <component :is="currentView" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, shallowRef } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import AppHeader from '@/components/AppHeader.vue'

// Import view components
import DashboardHome from '@/views/DashboardView.vue'
import ClientsView from '@/views/protected/hotspot/ClientsView.vue'
import PaymentsView from '@/views/protected/hotspot/PaymentsView.vue'
import ReportsView from '@/views/protected/hotspot/ReportsView.vue'
import SettingsView from '@/views/protected/hotspot/SettingsView.vue'
import HotspotConfig from '@/views/protected/hotspot/HotspotConfig.vue'
import HotspotUsers from '@/views/protected/hotspot/HotspotUsers.vue'
import HotspotVouchers from '@/views/protected/hotspot/HotspotVouchers.vue'
import HotspotBandwidth from '@/views/protected/hotspot/HotspotBandwidth.vue'

const currentView = shallowRef(DashboardHome)

const handleMenuClick = (view) => {
  switch (view) {
    case 'dashboard':
      currentView.value = DashboardHome
      break
    case 'clients':
      currentView.value = ClientsView
      break
    case 'payments':
      currentView.value = PaymentsView
      break
    case 'reports':
      currentView.value = ReportsView
      break
    case 'settings':
      currentView.value = SettingsView
      break
    case 'hotspot-config':
      currentView.value = HotspotConfig
      break
    case 'hotspot-users':
      currentView.value = HotspotUsers
      break
    case 'hotspot-vouchers':
      currentView.value = HotspotVouchers
      break
    case 'hotspot-bandwidth':
      currentView.value = HotspotBandwidth
      break
    default:
      currentView.value = DashboardHome
  }
}

const handleLogout = () => {
  // Implement your logout logic here
  // Example: authStore.logout();
}
</script>

<style scoped>
.dashboard-layout {
  display: flex;
  min-height: 100vh;
  background-color: #f5f7fa;
}

.main-content {
  flex: 1;
  margin-left: 250px; /* Sidebar width */
  display: flex;
  flex-direction: column;
  height: 100vh;
}

.content-area {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
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
