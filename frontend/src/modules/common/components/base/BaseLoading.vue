<template>
  <div v-if="type === 'spinner'" class="flex items-center justify-center" :class="containerClasses">
    <div :class="spinnerClasses">
      <svg class="animate-spin" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
      </svg>
    </div>
    <span v-if="text" class="ml-3 text-sm text-slate-600">{{ text }}</span>
  </div>
  
  <div v-else-if="type === 'skeleton'" class="animate-pulse space-y-4">
    <div v-for="i in rows" :key="i" class="bg-slate-200 rounded" :class="skeletonClasses"></div>
  </div>
  
  <div v-else-if="type === 'table'" class="animate-pulse space-y-4">
    <div v-for="i in rows" :key="i" class="bg-white rounded-lg p-4 shadow-sm border border-gray-200">
      <div class="flex items-center space-x-4">
        <div class="w-12 h-12 bg-slate-200 rounded-lg"></div>
        <div class="flex-1 space-y-2">
          <div class="h-4 bg-slate-200 rounded w-1/4"></div>
          <div class="h-3 bg-slate-200 rounded w-1/3"></div>
        </div>
        <div class="flex gap-2">
          <div class="w-20 h-8 bg-slate-200 rounded"></div>
          <div class="w-8 h-8 bg-slate-200 rounded"></div>
        </div>
      </div>
    </div>
  </div>
  
  <div v-else-if="type === 'card'" class="animate-pulse">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
      <div class="space-y-3">
        <div class="h-4 bg-slate-200 rounded w-3/4"></div>
        <div class="h-4 bg-slate-200 rounded w-1/2"></div>
        <div class="h-4 bg-slate-200 rounded w-5/6"></div>
      </div>
    </div>
  </div>
  
  <div v-else-if="type === 'dots'" class="flex items-center justify-center space-x-2">
    <div v-for="i in 3" :key="i" 
      class="w-2 h-2 bg-blue-600 rounded-full animate-bounce"
      :style="{ animationDelay: `${i * 0.1}s` }"
    ></div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  type: {
    type: String,
    default: 'spinner',
    validator: (value) => ['spinner', 'skeleton', 'table', 'card', 'dots'].includes(value)
  },
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg', 'xl'].includes(value)
  },
  text: String,
  rows: {
    type: Number,
    default: 5
  }
})

const containerClasses = computed(() => {
  const padding = {
    sm: 'p-2',
    md: 'p-4',
    lg: 'p-8',
    xl: 'p-12'
  }
  return padding[props.size]
})

const spinnerClasses = computed(() => {
  const sizes = {
    sm: 'w-4 h-4',
    md: 'w-8 h-8',
    lg: 'w-12 h-12',
    xl: 'w-16 h-16'
  }
  return [sizes[props.size], 'text-blue-600'].join(' ')
})

const skeletonClasses = computed(() => {
  const heights = {
    sm: 'h-3',
    md: 'h-4',
    lg: 'h-6',
    xl: 'h-8'
  }
  return heights[props.size]
})
</script>
