<template>
  <DataViewContainer
    title="Queues & Bandwidth Control"
    subtitle="Manage bandwidth queues and speed limits for PPPoE users"
    color-theme="indigo"
    :show-search="false"
    :show-add="false"
    :show-refresh="false"
  >
    <template #icon>
      <svg class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
      </svg>
    </template>

    <div class="flex flex-col items-center justify-center h-full min-h-[300px] gap-4 text-slate-400 dark:text-slate-500">
      <svg class="w-16 h-16 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
      </svg>
      <div class="text-center">
        <p class="text-base font-medium text-slate-500 dark:text-slate-400">Coming Soon</p>
        <p class="text-sm mt-1">Queues & Bandwidth Control is under development</p>
      </div>
    </div>
  </DataViewContainer>
</template>

<script setup>
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
</script>
