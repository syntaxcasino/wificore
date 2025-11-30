<template>
  <div>
    <h2 class="text-2xl font-bold mb-4">Active Users</h2>
    <ActiveUsersChart />
    <DataUsage />
  </div>
</template>

<script setup>
import ActiveUsersChart from '@/modules/tenant/components/dashboard/charts/ActiveUsersChart.vue'
import DataUsage from '@/modules/tenant/components/dashboard/widgets/DataUsage.vue'
</script>
