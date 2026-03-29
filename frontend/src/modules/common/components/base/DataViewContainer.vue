<template>
  <div class="flex flex-col h-full rounded-lg shadow-lg overflow-hidden" :class="gradientClass">
    <!-- Header -->
    <div class="flex-shrink-0 bg-white border-b border-slate-200 shadow-sm relative">
      <div class="px-4 md:px-6 py-3 md:py-5">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 md:gap-6">
          <!-- Left: Title & Icon -->
          <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 md:w-11 md:h-11 rounded-xl flex items-center justify-center shadow-lg" :class="iconContainerClass">
                <slot name="icon">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                  </svg>
                </slot>
              </div>
              <div>
                <h2 class="text-lg md:text-xl font-bold text-slate-900">{{ title }}</h2>
                <p v-if="subtitle" class="text-xs text-slate-500 mt-0.5 hidden md:block">{{ subtitle }}</p>
              </div>
            </div>
          </div>
          
          <!-- Center: Search Bar (Desktop only) -->
          <div v-if="showSearch" class="hidden md:block flex-1 max-w-xl">
            <BaseSearch 
              v-model="searchValue" 
              :placeholder="searchPlaceholder"
              @clear="$emit('searchClear')"
            />
          </div>
          
          <!-- Right: Stats & Actions -->
          <div class="flex items-center justify-between md:justify-end gap-2 md:gap-3">
            <!-- Quick Stats -->
            <div v-if="stats.length" class="hidden md:flex items-center gap-2 px-3 py-2 bg-slate-50 rounded-lg border border-slate-200">
              <template v-for="(stat, index) in stats" :key="index">
                <div class="flex items-center gap-1.5">
                  <span class="w-2 h-2 rounded-full" :class="stat.color"></span>
                  <span class="text-xs font-semibold text-slate-700">{{ stat.value }}</span>
                </div>
                <span v-if="index < stats.length - 1" class="text-slate-300">|</span>
              </template>
              <span v-if="showTotal" class="text-slate-300">|</span>
              <span v-if="showTotal" class="text-xs font-semibold" :class="totalColorClass">{{ total }}</span>
            </div>
            
            <!-- Action Buttons -->
            <slot name="actions">
              <button v-if="showRefresh" @click="$emit('refresh')" :disabled="loading"
                class="inline-flex items-center gap-1.5 px-2 md:px-3 py-2 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 hover:border-slate-400 disabled:opacity-50 disabled:cursor-not-allowed transition-all">
                <svg xmlns="http://www.w3.org/2000/svg" :class="loading ? 'animate-spin' : ''" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
                </svg>
                <span class="hidden md:inline">Refresh</span>
              </button>
              <button v-if="showAdd" @click="$emit('add')"
                class="inline-flex items-center gap-1.5 px-3 md:px-4 py-2 text-xs font-semibold text-white rounded-lg hover:opacity-90 transition-all shadow-md hover:shadow-lg"
                :class="addButtonClass">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />
                </svg>
                <span class="hidden sm:inline">{{ addButtonText }}</span>
                <span class="sm:hidden">Add</span>
              </button>
            </slot>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content Slot -->
    <div class="flex-1 min-h-0 overflow-hidden flex flex-col">
      <slot />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import BaseSearch from './BaseSearch.vue'

const props = defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  colorTheme: { 
    type: String, 
    default: 'blue',
    validator: (value) => ['blue', 'emerald', 'purple', 'cyan', 'violet', 'indigo', 'rose', 'amber'].includes(value)
  },
  // Search
  showSearch: { type: Boolean, default: true },
  searchPlaceholder: { type: String, default: 'Search...' },
  searchModel: { type: String, default: '' },
  // Stats
  stats: { 
    type: Array, 
    default: () => [],
    // Expected format: [{ color: 'bg-emerald-500', value: 12 }, ...]
  },
  showTotal: { type: Boolean, default: true },
  total: { type: [Number, String], default: 0 },
  // Actions
  showRefresh: { type: Boolean, default: true },
  showAdd: { type: Boolean, default: true },
  addButtonText: { type: String, default: 'Add New' },
  loading: { type: Boolean, default: false }
})

const emit = defineEmits(['refresh', 'add', 'searchClear', 'update:searchModel'])

const searchValue = computed({
  get: () => props.searchModel,
  set: (val) => emit('update:searchModel', val)
})

const gradientThemes = {
  blue: 'from-slate-50 via-gray-50 to-blue-50/30',
  emerald: 'from-slate-50 via-gray-50 to-emerald-50/30',
  purple: 'from-slate-50 via-gray-50 to-purple-50/30',
  cyan: 'from-slate-50 via-gray-50 to-cyan-50/30',
  violet: 'from-slate-50 via-gray-50 to-violet-50/30',
  indigo: 'from-slate-50 via-gray-50 to-indigo-50/30',
  rose: 'from-slate-50 via-gray-50 to-rose-50/30',
  amber: 'from-slate-50 via-gray-50 to-amber-50/30'
}

const gradientClass = computed(() => `bg-gradient-to-br ${gradientThemes[props.colorTheme]}`)

const iconContainerThemes = {
  blue: 'bg-gradient-to-br from-blue-600 to-indigo-600',
  emerald: 'bg-gradient-to-br from-emerald-600 to-teal-600',
  purple: 'bg-gradient-to-br from-purple-600 to-violet-600',
  cyan: 'bg-gradient-to-br from-cyan-600 to-blue-600',
  violet: 'bg-gradient-to-br from-violet-600 to-purple-600',
  indigo: 'bg-gradient-to-br from-indigo-600 to-blue-600',
  rose: 'bg-gradient-to-br from-rose-600 to-pink-600',
  amber: 'bg-gradient-to-br from-amber-600 to-orange-600'
}

const iconContainerClass = computed(() => iconContainerThemes[props.colorTheme])

const addButtonThemes = {
  blue: 'from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700',
  emerald: 'from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700',
  purple: 'from-purple-600 to-violet-600 hover:from-purple-700 hover:to-violet-700',
  cyan: 'from-cyan-600 to-blue-600 hover:from-cyan-700 hover:to-blue-700',
  violet: 'from-violet-600 to-purple-600 hover:from-violet-700 hover:to-purple-700',
  indigo: 'from-indigo-600 to-blue-600 hover:from-indigo-700 hover:to-blue-700',
  rose: 'from-rose-600 to-pink-600 hover:from-rose-700 hover:to-pink-700',
  amber: 'from-amber-600 to-orange-600 hover:from-amber-700 hover:to-orange-700'
}

const addButtonClass = computed(() => `bg-gradient-to-r ${addButtonThemes[props.colorTheme]}`)

const totalColorThemes = {
  blue: 'text-blue-600',
  emerald: 'text-emerald-600',
  purple: 'text-purple-600',
  cyan: 'text-cyan-600',
  violet: 'text-violet-600',
  indigo: 'text-indigo-600',
  rose: 'text-rose-600',
  amber: 'text-amber-600'
}

const totalColorClass = computed(() => totalColorThemes[props.colorTheme])
</script>

<style scoped>
::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}
::-webkit-scrollbar-track {
  background: #f1f5f9;
  border-radius: 4px;
}
::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 4px;
}
::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}
</style>
