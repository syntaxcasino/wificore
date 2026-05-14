<template>
  <div class="flex-shrink-0 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 shadow-sm relative z-10">
    <div class="px-4 md:px-6 py-3 md:py-5">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 sm:gap-6">
        <!-- Left: Title & Icon -->
        <div class="flex items-center gap-2.5 sm:gap-3 min-w-0">
          <div v-if="icon" class="w-9 h-9 sm:w-11 sm:h-11 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg flex-shrink-0">
            <component :is="iconComponent" class="h-5 w-5 sm:h-6 sm:w-6 text-white" stroke-width="2" />
          </div>
          <div class="min-w-0">
            <h2 class="text-lg sm:text-xl font-bold text-slate-900 truncate">{{ title }}</h2>
            <p v-if="subtitle" class="text-xs text-slate-500 mt-0.5 truncate">{{ subtitle }}</p>
          </div>
        </div>
        
        <!-- Right: Actions -->
        <div class="flex items-center gap-2 sm:gap-3 flex-shrink-0">
          <slot name="actions" />
        </div>
      </div>
      
      <!-- Breadcrumbs -->
      <div v-if="breadcrumbs && breadcrumbs.length" class="mt-3">
        <nav class="flex" aria-label="Breadcrumb">
          <ol class="flex items-center space-x-2">
            <li v-for="(crumb, index) in breadcrumbs" :key="index" class="flex items-center">
              <router-link
                v-if="crumb.to"
                :to="crumb.to"
                class="text-sm text-slate-600 hover:text-slate-900 transition-colors"
              >
                {{ crumb.label }}
              </router-link>
              <span v-else class="text-sm text-slate-900 font-medium">{{ crumb.label }}</span>
              <svg
                v-if="index < breadcrumbs.length - 1"
                class="w-4 h-4 text-slate-400 mx-2"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
              </svg>
            </li>
          </ol>
        </nav>
      </div>
      
      <!-- Additional content slot -->
      <div v-if="$slots.default" class="mt-4">
        <slot />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { resolveLucideIcon } from '@/modules/common/utils/lucideIconMap'

const props = defineProps({
  title: {
    type: String,
    required: true
  },
  subtitle: String,
  icon: String,
  breadcrumbs: Array
})

const iconComponent = computed(() => {
  return props.icon ? resolveLucideIcon(props.icon, null) : null
})
</script>
