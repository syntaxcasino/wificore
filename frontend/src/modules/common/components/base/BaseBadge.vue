<template>
  <span :class="badgeClasses">
    <span v-if="dot" class="w-1.5 h-1.5 rounded-full mr-1.5" :class="dotColor"></span>
    <slot />
  </span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  variant: {
    type: String,
    default: 'default',
    validator: (value) => ['default', 'success', 'warning', 'danger', 'info', 'purple', 'pink'].includes(value)
  },
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg'].includes(value)
  },
  dot: Boolean,
  pulse: Boolean
})

const badgeClasses = computed(() => {
  const base = 'inline-flex items-center font-medium rounded-md'
  
  const variants = {
    default: 'bg-slate-100 text-slate-700',
    success: 'bg-emerald-100 text-emerald-700',
    warning: 'bg-amber-100 text-amber-700',
    danger: 'bg-red-100 text-red-700',
    info: 'bg-blue-100 text-blue-700',
    purple: 'bg-purple-100 text-purple-700',
    pink: 'bg-pink-100 text-pink-700'
  }
  
  const sizes = {
    sm: 'px-2 py-0.5 text-xs',
    md: 'px-2.5 py-1 text-sm',
    lg: 'px-3 py-1.5 text-base'
  }
  
  return [base, variants[props.variant], sizes[props.size]].join(' ')
})

const dotColor = computed(() => {
  const colors = {
    default: 'bg-slate-500',
    success: 'bg-emerald-500',
    warning: 'bg-amber-500',
    danger: 'bg-red-500',
    info: 'bg-blue-500',
    purple: 'bg-purple-500',
    pink: 'bg-pink-500'
  }
  
  const pulse = props.pulse ? 'animate-pulse' : ''
  return [colors[props.variant], pulse].filter(Boolean).join(' ')
})
</script>
