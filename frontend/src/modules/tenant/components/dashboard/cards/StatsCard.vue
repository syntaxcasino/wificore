<template>
  <div class="bg-white dark:bg-slate-800 rounded-lg p-5 shadow-sm">
    <h3 class="text-base text-slate-500 dark:text-slate-400 mb-2.5">{{ title }}</h3>
    <div class="text-2xl font-bold text-slate-900 dark:text-slate-100 mb-1">{{ value }}</div>
    <div v-if="description" class="text-xs text-slate-400 dark:text-slate-500">{{ description }}</div>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  value: { type: String, required: true },
  description: { type: String, default: '' },
})
</script>
