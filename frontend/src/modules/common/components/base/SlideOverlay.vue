<template>
  <Teleport to="body">
    <Transition name="overlay">
      <div
        v-if="modelValue"
        class="fixed inset-0 z-50 overflow-hidden"
        @click.self="handleBackdrop"
      >
        <!-- Backdrop -->
        <Transition name="backdrop">
          <div
            v-if="modelValue"
            class="absolute inset-0 bg-black/[0.03]"
            @click="handleBackdrop"
          />
        </Transition>

        <!-- Slide Panel -->
        <Transition name="slide">
          <div
            v-if="modelValue"
            class="absolute right-0 top-0 h-full bg-white shadow-2xl flex flex-col"
            :class="widthClass"
            @click.stop
          >
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 bg-slate-50">
              <div class="flex items-center gap-3">
                <component
                  v-if="icon"
                  :is="iconComponent"
                  class="w-5 h-5 text-slate-600"
                />
                <div>
                  <h2 class="text-lg font-semibold text-slate-900">{{ title }}</h2>
                  <p v-if="subtitle" class="text-sm text-slate-500">{{ subtitle }}</p>
                </div>
              </div>
              <button
                @click="handleClose"
                class="p-2 hover:bg-slate-200 rounded-lg transition-colors"
                type="button"
              >
                <X class="w-5 h-5 text-slate-600" />
              </button>
            </div>

            <!-- Content -->
            <div class="flex-1 overflow-y-auto p-6">
              <slot />
            </div>

            <!-- Footer (optional) -->
            <div v-if="$slots.footer" class="px-6 py-4 border-t border-slate-200 bg-slate-50">
              <slot name="footer" />
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed, watch, onMounted, onUnmounted } from 'vue'
import { X } from 'lucide-vue-next'
import * as LucideIcons from 'lucide-vue-next'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false
  },
  title: {
    type: String,
    required: true
  },
  subtitle: {
    type: String,
    default: ''
  },
  icon: {
    type: String,
    default: ''
  },
  width: {
    type: String,
    default: '50%',
    validator: (value) => ['30%', '40%', '50%', '60%', '70%', '80%', '90%', 'full'].includes(value)
  },
  closeOnEscape: {
    type: Boolean,
    default: true
  },
  closeOnBackdrop: {
    type: Boolean,
    default: true
  }
})

const emit = defineEmits(['update:modelValue', 'close'])

const iconComponent = computed(() => {
  return props.icon ? LucideIcons[props.icon] || LucideIcons.Circle : null
})

const widthClass = computed(() => {
  const widthMap = {
    '30%': 'w-full sm:w-[30%] sm:min-w-[400px]',
    '40%': 'w-full sm:w-[40%] sm:min-w-[500px]',
    '50%': 'w-full sm:w-[50%] sm:min-w-[600px]',
    '60%': 'w-full sm:w-[60%] sm:min-w-[700px]',
    '70%': 'w-full sm:w-[70%] sm:min-w-[800px]',
    '80%': 'w-full sm:w-[80%] sm:min-w-[900px]',
    '90%': 'w-full sm:w-[90%] sm:min-w-[1000px]',
    'full': 'w-full'
  }
  return widthMap[props.width] || widthMap['50%']
})

const handleClose = () => {
  emit('update:modelValue', false)
  emit('close')
}

const handleBackdrop = () => {
  if (!props.closeOnBackdrop) return
  handleClose()
}

// Handle escape key
const handleEscape = (e) => {
  if (e.key === 'Escape' && props.modelValue && props.closeOnEscape) {
    handleClose()
  }
}

onMounted(() => {
  if (props.closeOnEscape) {
    window.addEventListener('keydown', handleEscape)
  }
})

onUnmounted(() => {
  if (props.closeOnEscape) {
    window.removeEventListener('keydown', handleEscape)
  }
})

// Prevent body scroll when overlay is open
watch(() => props.modelValue, (newValue) => {
  if (newValue) {
    document.body.style.overflow = 'hidden'
  } else {
    document.body.style.overflow = ''
  }
})
</script>

<style scoped>
/* Overlay transitions */
.overlay-enter-active,
.overlay-leave-active {
  transition: opacity 0.3s ease;
}

.overlay-enter-from,
.overlay-leave-to {
  opacity: 0;
}

/* Backdrop transitions */
.backdrop-enter-active,
.backdrop-leave-active {
  transition: opacity 0.3s ease;
}

.backdrop-enter-from,
.backdrop-leave-to {
  opacity: 0;
}

/* Slide transitions */
.slide-enter-active,
.slide-leave-active {
  transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.slide-enter-from,
.slide-leave-to {
  transform: translateX(100%);
}

.slide-enter-to,
.slide-leave-from {
  transform: translateX(0);
}
</style>
