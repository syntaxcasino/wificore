<template>
  <div>
    <h2 class="text-2xl font-bold mb-4">Overview</h2>
    <StatsCard />
    <RetentionRate />
  </div>
</template>

<script setup>
import StatsCard from '@/modules/tenant/components/dashboard/charts/ActiveUsersChart.vue'
import RetentionRate from '@/modules/tenant/components/dashboard/charts/PaymentsChart.vue'
</script>
