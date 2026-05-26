<template>
  <component :is="dashboardComponent" />
</template>

<script setup>
import { computed, defineAsyncComponent } from 'vue'
import { useAuthStore } from '@/stores/auth'

const TenantDashboard = defineAsyncComponent(() => import('@/modules/tenant/views/DashboardClean.vue'))
const SystemDashboard = defineAsyncComponent(() => import('@/modules/system-admin/views/system/SystemDashboardNew.vue'))

const authStore = useAuthStore()

const dashboardComponent = computed(() => {
  return authStore.isSystemAdmin ? SystemDashboard : TenantDashboard
})
</script>
