<template>
  <div class="flex flex-col items-center justify-center" :class="containerClasses">
    <!-- Icon -->
    <div class="relative mb-6">
      <div v-if="!noBackground" 
           class="absolute inset-0 rounded-full blur-3xl opacity-30"
           :class="backgroundColorClass"></div>
      <div class="relative" :class="iconContainerClasses">
        <slot name="icon">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-16 h-16" :class="iconColorClass" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path v-if="icon === 'box'" stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
            <path v-else-if="icon === 'search'" stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            <path v-else-if="icon === 'users'" stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
            <path v-else-if="icon === 'wallet'" stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
            <path v-else-if="icon === 'checklist'" stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
            <path v-else-if="icon === 'building'" stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            <path v-else-if="icon === 'briefcase'" stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            <path v-else-if="icon === 'currency'" stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            <path v-else stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
          </svg>
        </slot>
      </div>
    </div>
    
    <!-- Content -->
    <div class="space-y-2 text-center max-w-md">
      <h3 class="text-2xl font-bold text-slate-900">{{ title }}</h3>
      <p class="text-slate-600">{{ description }}</p>
    </div>
    
    <!-- Action Buttons -->
    <div v-if="showActions" class="mt-6 flex gap-3">
      <button v-if="showClear && hasFilters"
        @click="$emit('clear')"
        class="px-5 py-2.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-all shadow-sm">
        {{ clearText }}
      </button>
      <button v-if="showAdd"
        @click="$emit('add')"
        class="px-5 py-2.5 text-sm font-medium text-white rounded-lg hover:opacity-90 transition-all shadow-md hover:shadow-lg flex items-center gap-2"
        :class="addButtonClass">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />
        </svg>
        {{ addText }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  title: { type: String, default: 'No Data Found' },
  description: { type: String, default: 'There is no data to display at the moment.' },
  icon: { 
    type: String, 
    default: 'box',
    validator: (value) => ['box', 'search', 'users', 'wallet', 'checklist', 'building', 'briefcase', 'currency'].includes(value)
  },
  colorTheme: { 
    type: String, 
    default: 'blue',
    validator: (value) => ['blue', 'emerald', 'purple', 'cyan', 'violet', 'indigo', 'rose', 'amber'].includes(value)
  },
  size: { 
    type: String, 
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg'].includes(value)
  },
  noBackground: { type: Boolean, default: false },
  // Actions
  showActions: { type: Boolean, default: true },
  showClear: { type: Boolean, default: false },
  hasFilters: { type: Boolean, default: false },
  clearText: { type: String, default: 'Clear Filters' },
  showAdd: { type: Boolean, default: true },
  addText: { type: String, default: 'Add New' }
})

const emit = defineEmits(['add', 'clear'])

const containerClasses = computed(() => {
  const padding = { sm: 'p-8', md: 'p-12', lg: 'p-16' }
  return padding[props.size]
})

const backgroundColors = {
  blue: 'bg-blue-100',
  emerald: 'bg-emerald-100',
  purple: 'bg-purple-100',
  cyan: 'bg-cyan-100',
  violet: 'bg-violet-100',
  indigo: 'bg-indigo-100',
  rose: 'bg-rose-100',
  amber: 'bg-amber-100'
}

const backgroundColorClass = computed(() => backgroundColors[props.colorTheme])

const iconContainerClasses = computed(() => {
  if (props.noBackground) return ''
  const base = 'w-32 h-32 rounded-2xl flex items-center justify-center shadow-xl'
  const gradients = {
    blue: 'bg-gradient-to-br from-blue-600 to-indigo-600',
    emerald: 'bg-gradient-to-br from-emerald-600 to-teal-600',
    purple: 'bg-gradient-to-br from-purple-600 to-violet-600',
    cyan: 'bg-gradient-to-br from-cyan-600 to-blue-600',
    violet: 'bg-gradient-to-br from-violet-600 to-purple-600',
    indigo: 'bg-gradient-to-br from-indigo-600 to-blue-600',
    rose: 'bg-gradient-to-br from-rose-600 to-pink-600',
    amber: 'bg-gradient-to-br from-amber-600 to-orange-600'
  }
  return [base, gradients[props.colorTheme]].join(' ')
})

const iconColorClass = computed(() => {
  if (props.noBackground) return 'text-slate-400'
  return 'text-white'
})

const addButtonThemes = {
  blue: 'from-blue-600 to-indigo-600',
  emerald: 'from-emerald-600 to-teal-600',
  purple: 'from-purple-600 to-violet-600',
  cyan: 'from-cyan-600 to-blue-600',
  violet: 'from-violet-600 to-purple-600',
  indigo: 'from-indigo-600 to-blue-600',
  rose: 'from-rose-600 to-pink-600',
  amber: 'from-amber-600 to-orange-600'
}

const addButtonClass = computed(() => `bg-gradient-to-r ${addButtonThemes[props.colorTheme]}`)
</script>
