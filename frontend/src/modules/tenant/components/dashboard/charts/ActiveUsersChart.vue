<template>
  <div class="bg-white dark:bg-slate-800 rounded-lg p-5 shadow-sm">
    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100 mb-1">Active Users</h3>
    <p class="text-sm text-slate-400 dark:text-slate-500 mb-1">Average of users</p>
    <div class="mb-5">
      <p class="text-sm text-slate-500 dark:text-slate-400">
        Average ({{ userStats.averageUsers }}) users, Peak ({{ userStats.peakUsers }}) users this week
      </p>
    </div>

    <div class="flex h-[250px]">
      <div class="flex flex-col-reverse justify-between mr-2.5 text-xs text-slate-500 dark:text-slate-400">
        <div v-for="(tick, index) in yAxisTicks" :key="index">{{ tick }}</div>
      </div>
      <div class="flex flex-grow items-end gap-4">
        <div v-for="(value, index) in activeUsersData.data" :key="index" class="flex flex-col items-center flex-grow h-full">
          <div
            class="w-full bg-green-500 rounded-t transition-all duration-300"
            :style="{ height: `${(value / maxValue) * 100}%` }"
          ></div>
          <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ activeUsersData.labels[index] }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useDashboardData } from '@/composables/useDashboardData'

const { dashboardData } = useDashboardData()
const activeUsersData = computed(() => dashboardData.value.activeUsers)
const userStats = computed(() => dashboardData.value.userStats)
const maxValue = computed(() => Math.max(...activeUsersData.value.data) * 1.1)
const yAxisTicks = computed(() => {
  const ticks = []
  for (let i = 20; i >= 0; i -= 2) ticks.push(i)
  return ticks
})
</script>
