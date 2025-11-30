<template>
  <div class="role-based-dashboard">
    <!-- System Admin View -->
    <SystemAdminDashboard v-if="isSystemAdmin" />
    
    <!-- Tenant Admin View -->
    <TenantDashboard v-else-if="isTenantAdmin" />
    
    <!-- Unauthorized -->
    <div v-else class="unauthorized">
      <p>You do not have permission to view this page.</p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import SystemAdminDashboard from '@/modules/system-admin/components/SystemAdminDashboard.vue'
import TenantDashboard from '@/modules/tenant/components/TenantDashboard.vue'

const authStore = useAuthStore()

const isSystemAdmin = computed(() => authStore.user?.role === 'system_admin')
const isTenantAdmin = computed(() => authStore.user?.role === 'admin')
</script>

<style scoped>
.unauthorized {
  padding: 40px;
  text-align: center;
  color: #ef4444;
}
</style>
