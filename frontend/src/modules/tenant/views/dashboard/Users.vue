<template>
  <DataViewContainer
    title="Active Users"
    subtitle="User activity and data usage"
    color-theme="purple"
    :show-search="false"
    :show-add="false"
    :show-refresh="false"
  >
    <template #icon>
      <svg class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
      </svg>
    </template>

    <div class="space-y-4">
      <ActiveUsersChart />
      <DataUsage />
    </div>
  </DataViewContainer>
</template>

<script setup>
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import ActiveUsersChart from '@/modules/tenant/components/dashboard/charts/ActiveUsersChart.vue'
import DataUsage from '@/modules/tenant/components/dashboard/widgets/DataUsage.vue'
</script>
