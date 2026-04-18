<template>
  <div class="bg-white dark:bg-slate-800 rounded-lg p-5 shadow-sm">
    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100 mb-1">Payments</h3>
    <p class="text-sm text-slate-400 dark:text-slate-500 mb-5">Payments and expenses trend.</p>

    <div class="flex h-[250px]">
      <div class="flex flex-col-reverse justify-between mr-2.5 text-xs text-slate-500 dark:text-slate-400">
        <div v-for="(tick, index) in yAxisTicks" :key="index">{{ tick }}</div>
      </div>
      <div class="flex flex-grow items-end gap-4">
        <div v-for="(value, index) in paymentsData.data" :key="index" class="flex flex-col items-center flex-grow h-full">
          <div
            class="w-full bg-blue-500 rounded-t transition-all duration-300"
            :style="{ height: `${(value / maxValue) * 100}%` }"
          ></div>
          <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ paymentsData.labels[index] }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useDashboardData } from '@/composables/useDashboardData'

const { dashboardData } = useDashboardData()
const paymentsData = computed(() => dashboardData.value.payments)
const maxValue = computed(() => Math.max(...paymentsData.value.data) * 1.1)
const yAxisTicks = computed(() => {
  const ticks = []
  for (let i = 4000; i >= 0; i -= 500) ticks.push(i)
  return ticks
})
</script>
