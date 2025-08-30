<template>
  <AppLayout>
    <h2 class="text-2xl font-semibold mb-4">Dashboard Overview</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
      <StatsCard />
      <PaymentsChart />
      <ActiveUsersChart />
      <RetentionRate />
      <DataUsage />
    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/components/layout/AppLayout.vue'
import StatsCard from '@/components/dashboard/StatsCard.vue'
import PaymentsChart from '@/components/dashboard/PaymentsChart.vue'
import ActiveUsersChart from '@/components/dashboard/ActiveUsersChart.vue'
import RetentionRate from '@/components/dashboard/RetentionRate.vue'
import DataUsage from '@/components/dashboard/DataUsage.vue'
</script>
