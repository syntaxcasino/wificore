<template>
  <div v-if="show" :class="alertClasses" role="alert">
    <div class="flex items-start gap-3">
      <!-- Icon -->
      <div class="flex-shrink-0">
        <component v-if="iconComponent" :is="iconComponent" :class="iconClasses" />
      </div>
      
      <!-- Content -->
      <div class="flex-1 min-w-0">
        <h4 v-if="title" class="font-semibold mb-1" :class="titleClasses">{{ title }}</h4>
        <div :class="contentClasses">
          <slot>{{ message }}</slot>
        </div>
      </div>
      
      <!-- Close Button -->
      <button
        v-if="dismissible"
        @click="handleClose"
        class="flex-shrink-0 p-1 rounded hover:bg-black/5 transition-colors"
        type="button"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
        </svg>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { AlertCircle, CheckCircle, AlertTriangle, Info, XCircle } from 'lucide-vue-next'

const props = defineProps({
  variant: {
    type: String,
    default: 'info',
    validator: (value) => ['success', 'warning', 'danger', 'info'].includes(value)
  },
  title: String,
  message: String,
  dismissible: {
    type: Boolean,
    default: false
  },
  modelValue: {
    type: Boolean,
    default: true
  }
})

const emit = defineEmits(['update:modelValue', 'close'])

const show = ref(props.modelValue)

const alertClasses = computed(() => {
  const base = 'rounded-lg p-4 border'
  
  const variants = {
    success: 'bg-emerald-50 border-emerald-200 text-emerald-800',
    warning: 'bg-amber-50 border-amber-200 text-amber-800',
    danger: 'bg-red-50 border-red-200 text-red-800',
    info: 'bg-blue-50 border-blue-200 text-blue-800'
  }
  
  return [base, variants[props.variant]].join(' ')
})

const iconClasses = computed(() => {
  const variants = {
    success: 'text-emerald-600',
    warning: 'text-amber-600',
    danger: 'text-red-600',
    info: 'text-blue-600'
  }
  
  return ['w-5 h-5', variants[props.variant]].join(' ')
})

const titleClasses = computed(() => {
  const variants = {
    success: 'text-emerald-900',
    warning: 'text-amber-900',
    danger: 'text-red-900',
    info: 'text-blue-900'
  }
  
  return ['text-sm', variants[props.variant]].join(' ')
})

const contentClasses = computed(() => {
  return 'text-sm'
})

const iconComponent = computed(() => {
  const icons = {
    success: CheckCircle,
    warning: AlertTriangle,
    danger: XCircle,
    info: Info
  }
  
  return icons[props.variant]
})

const handleClose = () => {
  show.value = false
  emit('update:modelValue', false)
  emit('close')
}
</script>
