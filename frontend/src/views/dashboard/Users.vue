<template>
  <div>
    <h2 class="text-2xl font-bold mb-4">Active Users</h2>
    <ActiveUsersChart />
    <DataUsage />
  </div>
</template>

<script setup>
import ActiveUsersChart from '@/components/dashboard/ActiveUsersChart.vue'
import DataUsage from '@/components/dashboard/DataUsage.vue'
</script>
