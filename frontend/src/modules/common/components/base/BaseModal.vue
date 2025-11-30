<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition-opacity duration-300"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-opacity duration-300"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="modelValue"
        class="fixed inset-0 z-50 overflow-y-auto"
        @click.self="handleBackdropClick"
      >
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm"></div>
        
        <!-- Modal Container -->
        <div class="flex min-h-full items-center justify-center p-4">
          <Transition
            enter-active-class="transition-all duration-300"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition-all duration-200"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
          >
            <div
              v-if="modelValue"
              :class="modalClasses"
              role="dialog"
              aria-modal="true"
            >
              <!-- Header -->
              <div v-if="$slots.header || title" class="flex items-center justify-between p-6 border-b border-slate-200">
                <slot name="header">
                  <h3 class="text-xl font-semibold text-slate-900">{{ title }}</h3>
                </slot>
                <button
                  v-if="closable"
                  @click="handleClose"
                  class="p-1 rounded-lg hover:bg-slate-100 transition-colors"
                  type="button"
                >
                  <svg class="w-5 h-5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                  </svg>
                </button>
              </div>
              
              <!-- Body -->
              <div :class="bodyClasses">
                <slot />
              </div>
              
              <!-- Footer -->
              <div v-if="$slots.footer" class="flex items-center justify-end gap-3 p-6 border-t border-slate-200 bg-slate-50">
                <slot name="footer" />
              </div>
            </div>
          </Transition>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed, watch, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false
  },
  title: String,
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg', 'xl', 'full'].includes(value)
  },
  closable: {
    type: Boolean,
    default: true
  },
  closeOnBackdrop: {
    type: Boolean,
    default: true
  },
  noPadding: Boolean
})

const emit = defineEmits(['update:modelValue', 'close'])

const modalClasses = computed(() => {
  const base = 'relative bg-white rounded-xl shadow-2xl'
  
  const sizes = {
    sm: 'w-full max-w-md',
    md: 'w-full max-w-2xl',
    lg: 'w-full max-w-4xl',
    xl: 'w-full max-w-6xl',
    full: 'w-full max-w-7xl mx-4'
  }
  
  return [base, sizes[props.size]].join(' ')
})

const bodyClasses = computed(() => {
  return props.noPadding ? '' : 'p-6'
})

const handleClose = () => {
  emit('update:modelValue', false)
  emit('close')
}

const handleBackdropClick = () => {
  if (props.closeOnBackdrop && props.closable) {
    handleClose()
  }
}

const handleEscape = (event) => {
  if (event.key === 'Escape' && props.modelValue && props.closable) {
    handleClose()
  }
}

// Lock body scroll when modal is open
watch(() => props.modelValue, (isOpen) => {
  if (isOpen) {
    document.body.style.overflow = 'hidden'
  } else {
    document.body.style.overflow = ''
  }
})

onMounted(() => {
  document.addEventListener('keydown', handleEscape)
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleEscape)
  document.body.style.overflow = ''
})
</script>
