<template>
  <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 shadow-sm p-4" :class="hoverClass">
    <!-- Main Content Row -->
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0 flex-1">
        <!-- Header: Icon/Status + Title -->
        <div class="flex items-center gap-2 min-w-0">
          <span v-if="status" :class="statusDotClass" class="w-2 h-2 rounded-full flex-shrink-0"></span>
          <div class="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate">{{ title }}</div>
        </div>
        
        <!-- Subtitle -->
        <div v-if="subtitle" class="mt-1 text-xs text-slate-600 dark:text-slate-400 truncate">{{ subtitle }}</div>
        
        <!-- Meta lines -->
        <div v-if="metaLines.length" class="mt-1 space-y-0.5">
          <div v-for="(line, index) in metaLines" :key="index" class="text-xs" :class="line.class || 'text-slate-500 dark:text-slate-400'">
            {{ line.text }}
          </div>
        </div>
        
        <!-- Status Badge -->
        <div v-if="status && showStatusBadge" class="mt-2">
          <EntityStatusBadge :status="status" size="sm" />
        </div>
      </div>
      
      <!-- Right side: Value/Badge -->
      <div v-if="value || $slots.right" class="text-right flex-shrink-0">
        <div v-if="value" class="text-sm font-bold" :class="valueClass">{{ value }}</div>
        <slot name="right" />
      </div>
    </div>

    <!-- Action Buttons -->
    <div v-if="actions.length || $slots.actions" class="mt-3 flex items-center justify-end gap-2">
      <slot name="actions">
        <button 
          v-for="(action, index) in actions" 
          :key="index"
          @click="action.onClick"
          class="px-3 py-1.5 text-xs font-medium rounded transition-colors"
          :class="action.class || 'text-slate-700 bg-slate-100 hover:bg-slate-200'"
        >
          {{ action.label }}
        </button>
      </slot>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import EntityStatusBadge from './EntityStatusBadge.vue'

const props = defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  metaLines: { 
    type: Array, 
    default: () => [],
    // [{ text: 'Department name', class: 'text-slate-500' }, ...]
  },
  status: { type: String, default: '' },
  showStatusBadge: { type: Boolean, default: true },
  value: { type: String, default: '' },
  valueClass: { type: String, default: 'text-slate-900' },
  actions: { 
    type: Array, 
    default: () => [],
    // [{ label: 'Edit', onClick: fn, class: 'text-slate-700 bg-slate-100' }, ...]
  },
  hoverable: { type: Boolean, default: false }
})

const hoverClass = computed(() => props.hoverable ? 'hover:shadow-md hover:border-slate-300 transition-all' : '')

const statusDotClass = computed(() => {
  const colors = {
    active: 'bg-emerald-500',
    inactive: 'bg-slate-400',
    pending: 'bg-yellow-500',
    received: 'bg-emerald-500',
    completed: 'bg-blue-500',
    cancelled: 'bg-red-500',
    online: 'bg-emerald-500',
    offline: 'bg-red-500',
    expired: 'bg-slate-400',
    paid: 'bg-emerald-500',
    overdue: 'bg-amber-500'
  }
  return colors[props.status] || 'bg-slate-400'
})
</script>
