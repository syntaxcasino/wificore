<template>
  <div :class="cardClasses">
    <div v-if="$slots.header || title" class="px-6 py-4 border-b border-slate-200 dark:border-slate-700">
      <slot name="header">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ title }}</h3>
            <p v-if="subtitle" class="text-sm text-slate-600 dark:text-slate-400 mt-1">{{ subtitle }}</p>
          </div>
          <div v-if="$slots.actions">
            <slot name="actions" />
          </div>
        </div>
      </slot>
    </div>
    
    <div :class="contentClasses">
      <slot />
    </div>
    
    <div v-if="$slots.footer" class="px-6 py-4 border-t border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50">
      <slot name="footer" />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  title: String,
  subtitle: String,
  padding: {
    type: Boolean,
    default: true
  },
  hoverable: Boolean,
  noBorder: Boolean
})

const cardClasses = computed(() => {
  const base = 'bg-white dark:bg-slate-800 rounded-xl shadow-sm'
  const border = props.noBorder ? '' : 'border border-slate-200 dark:border-slate-700'
  const hover = props.hoverable ? 'hover:shadow-lg transition-shadow duration-300' : ''
  return [base, border, hover].filter(Boolean).join(' ')
})

const contentClasses = computed(() => {
  return props.padding ? 'px-6 py-4' : ''
})
</script>
