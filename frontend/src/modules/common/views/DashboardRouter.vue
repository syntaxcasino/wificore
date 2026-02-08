<template>
  <component :is="dashboardComponent" />
</template>

<script setup>
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import TenantDashboard from '@/modules/tenant/views/DashboardClean.vue'
import SystemDashboard from '@/modules/system-admin/views/system/SystemDashboardNew.vue'

const authStore = useAuthStore()

const dashboardComponent = computed(() => {
  return authStore.isSystemAdmin ? SystemDashboard : TenantDashboard
})
</script>
