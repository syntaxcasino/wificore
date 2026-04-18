<template>
  <DataViewContainer
    title="Payments"
    subtitle="Payment analytics and history"
    color-theme="emerald"
    :show-search="false"
    :show-add="false"
    :show-refresh="false"
  >
    <template #icon>
      <svg class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
    </template>

    <div class="space-y-4">
      <PaymentsChart />
      <PaymentsChart />
      <PaymentsChart />
      <PaymentsChart />
      <PaymentsChart />
      <PaymentsChart />
      <PaymentsChart />
      <PaymentsChart />
      <PaymentsChart />
      <PaymentsChart />
      <PaymentsChart />
      <PaymentsChart />
    </div>
  </DataViewContainer>
</template>

<script setup>
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import PaymentsChart from '@/modules/tenant/components/dashboard/charts/PaymentsChart.vue'
</script>
