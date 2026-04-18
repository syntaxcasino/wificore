<template>
  <span :class="badgeClasses">
    <span v-if="showDot" class="w-1.5 h-1.5 rounded-full mr-1.5" :class="dotColorClass"></span>
    <slot>{{ label }}</slot>
  </span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: { 
    type: String, 
    default: 'unknown',
    validator: (value) => ['active', 'inactive', 'pending', 'in_progress', 'received', 'completed', 'cancelled', 'online', 'offline', 'expired', 'paid', 'overdue', 'provisioning', 'deploying', 'verifying', 'unknown'].includes(value)
  },
  size: { 
    type: String, 
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg'].includes(value)
  },
  showDot: { type: Boolean, default: true },
  label: { type: String, default: '' },
  customLabel: { type: String, default: '' }
})

const badgeClasses = computed(() => {
  const base = 'inline-flex items-center font-medium rounded-md capitalize'
  
  const statusStyles = {
    active: 'bg-emerald-100 text-emerald-800',
    inactive: 'bg-slate-100 text-slate-800',
    pending: 'bg-amber-100 text-amber-800',
    in_progress: 'bg-blue-100 text-blue-800',
    received: 'bg-emerald-100 text-emerald-800',
    completed: 'bg-emerald-100 text-emerald-800',
    cancelled: 'bg-red-100 text-red-800',
    online: 'bg-emerald-100 text-emerald-800',
    offline: 'bg-red-100 text-red-800',
    expired: 'bg-slate-100 text-slate-800',
    paid: 'bg-emerald-100 text-emerald-800',
    overdue: 'bg-red-100 text-red-800',
    provisioning: 'bg-amber-100 text-amber-800',
    deploying: 'bg-blue-100 text-blue-800',
    verifying: 'bg-blue-100 text-blue-800',
    unknown: 'bg-slate-100 text-slate-800'
  }
  
  const sizes = {
    sm: 'px-2 py-0.5 text-xs',
    md: 'px-2.5 py-1 text-sm',
    lg: 'px-3 py-1.5 text-base'
  }
  
  return [base, statusStyles[props.status] || statusStyles.unknown, sizes[props.size]].join(' ')
})

const dotColorClass = computed(() => {
  const colors = {
    active: 'bg-emerald-500',
    inactive: 'bg-slate-400',
    pending: 'bg-amber-500',
    in_progress: 'bg-blue-500',
    received: 'bg-emerald-500',
    completed: 'bg-emerald-500',
    cancelled: 'bg-red-500',
    online: 'bg-emerald-500',
    offline: 'bg-red-500',
    expired: 'bg-slate-400',
    paid: 'bg-emerald-500',
    overdue: 'bg-red-500',
    provisioning: 'bg-amber-500',
    deploying: 'bg-blue-500',
    verifying: 'bg-blue-500',
    unknown: 'bg-slate-400'
  }
  return colors[props.status] || colors.unknown
})

const label = computed(() => {
  if (props.customLabel) return props.customLabel
  return props.status
})
</script>
