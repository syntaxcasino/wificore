<template>
  <div class="animate-pulse space-y-4" :class="containerClass">
    <div 
      v-for="i in count" 
      :key="i" 
      class="bg-white dark:bg-slate-800 rounded-lg p-4 shadow-sm border border-slate-200 dark:border-slate-700"
    >
      <div class="flex items-center space-x-4">
        <!-- Avatar/Icon skeleton -->
        <div v-if="showAvatar" class="w-12 h-12 bg-slate-200 rounded-lg flex-shrink-0"></div>
        
        <!-- Content skeleton -->
        <div class="flex-1 space-y-2">
          <div class="flex items-center gap-2">
            <div class="h-4 bg-slate-200 rounded w-1/4"></div>
            <div v-if="showBadge" class="h-4 bg-slate-200 rounded w-16"></div>
          </div>
          <div class="h-3 bg-slate-200 rounded w-1/3"></div>
          <div v-if="showMeta" class="h-3 bg-slate-200 rounded w-1/2"></div>
        </div>
        
        <!-- Actions skeleton -->
        <div v-if="showActions" class="flex gap-2 flex-shrink-0">
          <div class="w-20 h-8 bg-slate-200 rounded"></div>
          <div class="w-8 h-8 bg-slate-200 rounded"></div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  count: { type: Number, default: 5 },
  showAvatar: { type: Boolean, default: true },
  showBadge: { type: Boolean, default: false },
  showMeta: { type: Boolean, default: true },
  showActions: { type: Boolean, default: true },
  containerClass: { type: String, default: 'p-6' }
})
</script>
