<template>
  <DataViewContainer
    title="Auto Disconnect Rules"
    subtitle="Configure automatic disconnect rules for PPPoE sessions"
    color-theme="indigo"
    :show-search="false"
    :show-add="false"
    :show-refresh="false"
  >
    <template #icon>
      <svg class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
      </svg>
    </template>

    <div class="flex flex-col items-center justify-center h-full min-h-[300px] gap-4 text-slate-400 dark:text-slate-500">
      <svg class="w-16 h-16 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
      </svg>
      <div class="text-center">
        <p class="text-base font-medium text-slate-500 dark:text-slate-400">Coming Soon</p>
        <p class="text-sm mt-1">Auto Disconnect Rules is under development</p>
      </div>
    </div>
  </DataViewContainer>
</template>

<script setup>
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
</script>
