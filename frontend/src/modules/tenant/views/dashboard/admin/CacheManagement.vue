<template>
  <DataViewContainer
    title="Cache Management"
    subtitle="Clear and manage application cache layers"
    color-theme="rose"
    :show-search="false"
    :show-add="false"
    :show-refresh="false"
  >
    <template #icon>
      <svg class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
    </template>

    <div class="flex flex-col items-center justify-center h-full min-h-[300px] gap-4 text-slate-400 dark:text-slate-500">
      <svg class="w-16 h-16 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
      <div class="text-center">
        <p class="text-base font-medium text-slate-500 dark:text-slate-400">Coming Soon</p>
        <p class="text-sm mt-1">Cache Management is under development</p>
      </div>
    </div>
  </DataViewContainer>
</template>

<script setup>
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
</script>
