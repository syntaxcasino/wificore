<template>
  <div :class="cardClasses">
    <div v-if="$slots.header || title" class="px-6 py-4 border-b border-slate-200">
      <slot name="header">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-lg font-semibold text-slate-900">{{ title }}</h3>
            <p v-if="subtitle" class="text-sm text-slate-600 mt-1">{{ subtitle }}</p>
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
    
    <div v-if="$slots.footer" class="px-6 py-4 border-t border-slate-200 bg-slate-50">
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
  const base = 'bg-white rounded-xl shadow-sm'
  const border = props.noBorder ? '' : 'border border-slate-200'
  const hover = props.hoverable ? 'hover:shadow-lg transition-shadow duration-300' : ''
  return [base, border, hover].filter(Boolean).join(' ')
})

const contentClasses = computed(() => {
  return props.padding ? 'px-6 py-4' : ''
})
</script>
