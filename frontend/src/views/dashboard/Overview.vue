<template>
  <div>
    <h2 class="text-2xl font-bold mb-4">Overview</h2>
    <StatsCard />
    <RetentionRate />
  </div>
</template>

<script setup>
import StatsCard from '@/components/dashboard/ActiveUsersChart.vue'
import RetentionRate from '@/components/dashboard/PaymentsChart.vue'
</script>
