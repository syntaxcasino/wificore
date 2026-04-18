<template>
  <div class="bg-white dark:bg-slate-800 rounded-lg p-5 shadow-sm">
    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100 mb-1">Customer retention rate (6 months)</h3>
    <p class="text-sm text-slate-400 dark:text-slate-500 mb-5">{{ retentionRate?.description }}</p>
    <div class="h-[200px] bg-slate-100 dark:bg-slate-700 rounded flex items-center justify-center text-slate-400 dark:text-slate-500 text-sm">
      Retention chart visualization would go here
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useDashboardData } from '@/composables/useDashboardData'

const { dashboardData } = useDashboardData()
const retentionRate = computed(() => dashboardData.value.retentionRate)
</script>
