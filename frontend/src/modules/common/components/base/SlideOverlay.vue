<template>
  <Teleport to="body">
    <Transition name="overlay-fade">
      <div
        v-if="modelValue"
        class="fixed inset-0 z-[9999] overflow-hidden"
        role="dialog"
        :aria-label="title"
        aria-modal="true"
        @keydown.esc="handleClose"
      >
        <!-- Backdrop -->
        <Transition name="backdrop">
          <div
            v-if="modelValue"
            class="absolute inset-0 bg-slate-900/40 dark:bg-slate-950/60"
            @click="handleBackdrop"
          />
        </Transition>

        <!-- Slide Panel -->
        <Transition name="slide">
          <div
            v-if="modelValue"
            ref="panelRef"
            class="absolute right-0 top-0 h-full bg-white dark:bg-slate-900 shadow-[−20px_0_60px_rgba(0,0,0,0.18)] dark:shadow-[−20px_0_60px_rgba(0,0,0,0.5)] flex flex-col ring-1 ring-slate-900/10 dark:ring-slate-700/50"
            :class="widthClass"
            tabindex="-1"
            @click.stop
          >
            <!-- Header -->
            <div
              class="flex items-center justify-between px-4 sm:px-5 py-3 border-b flex-shrink-0 transition-shadow"
              :class="[
                gradient
                  ? 'bg-gradient-to-r from-blue-600 to-indigo-600 border-transparent'
                  : 'bg-white dark:bg-slate-800 border-slate-200 dark:border-slate-700',
                headerShadow ? 'shadow-sm' : ''
              ]"
            >
              <div class="flex items-center gap-3 min-w-0">
                <!-- Icon container -->
                <div
                  v-if="icon"
                  class="p-1.5 rounded-lg flex-shrink-0"
                  :class="gradient ? 'bg-white/20' : 'bg-slate-100 dark:bg-slate-700'"
                >
                  <component
                    :is="iconComponent"
                    class="w-4 h-4"
                    :class="gradient ? 'text-white' : 'text-slate-600 dark:text-slate-300'"
                  />
                </div>
                <!-- Title + subtitle + badge -->
                <div class="min-w-0">
                  <div class="flex items-center gap-2">
                    <h2
                      class="text-sm font-semibold truncate"
                      :class="gradient ? 'text-white' : 'text-slate-900 dark:text-slate-100'"
                    >{{ title }}</h2>
                    <span
                      v-if="badge"
                      class="text-[10px] font-bold px-1.5 py-0.5 rounded-full uppercase tracking-wide flex-shrink-0"
                      :class="gradient ? 'bg-white/25 text-white' : 'bg-blue-100 text-blue-700'"
                    >{{ badge }}</span>
                  </div>
                  <p
                    v-if="subtitle"
                    class="text-xs truncate mt-0.5"
                    :class="gradient ? 'text-blue-100' : 'text-slate-500 dark:text-slate-400'"
                  >{{ subtitle }}</p>
                </div>
              </div>
              <!-- Header actions slot + close -->
              <div class="flex items-center gap-1 flex-shrink-0 ml-2">
                <slot name="header-actions" />
                <button
                  @click="handleClose"
                  class="p-1.5 rounded-lg transition-colors"
                  :class="gradient
                    ? 'hover:bg-white/20 text-white/80 hover:text-white'
                    : 'hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'"
                  type="button"
                  aria-label="Close"
                >
                  <X class="w-4 h-4" />
                </button>
              </div>
            </div>

            <!-- Optional status bar slot (e.g. progress bar) -->
            <div v-if="$slots['status-bar']" class="flex-shrink-0">
              <slot name="status-bar" />
            </div>

            <!-- Content -->
            <div
              class="flex-1 overflow-y-auto dark:bg-slate-900"
              :class="noPadding ? '' : 'p-4 sm:p-5'"
              @scroll="onContentScroll"
            >
              <slot />
            </div>

            <!-- Footer (optional) -->
            <div
              v-if="$slots.footer"
              class="px-4 sm:px-5 py-3 border-t border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 flex-shrink-0"
            >
              <slot name="footer" />
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed, ref, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { X } from 'lucide-vue-next'
import { resolveLucideIcon } from '@/modules/common/utils/lucideIconMap'

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
  badge: {
    type: String,
    default: ''
  },
  gradient: {
    type: Boolean,
    default: false
  },
  noPadding: {
    type: Boolean,
    default: false
  },
  width: {
    type: String,
    default: '50%',
    validator: (value) => ['30%', '40%', '50%', '60%', '70%', '80%', '90%', 'full', '400px', '480px', '560px', '640px', '720px', '800px'].includes(value)
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

const panelRef = ref(null)
const headerShadow = ref(false)

const iconComponent = computed(() => {
  return props.icon ? resolveLucideIcon(props.icon) : null
})

const widthClass = computed(() => {
  // Professional responsive widths
  // Mobile: 100% | Tablet: 100% or fixed max | Desktop: percentage-based with max-width
  const widthMap = {
    // Percentage-based widths - scale proportionally with max limits
    '30%':   'w-full sm:w-full md:w-1/2 lg:w-[45%] xl:w-[35%] 2xl:w-[30%] md:max-w-[600px]',
    '40%':   'w-full sm:w-full md:w-[55%] lg:w-[50%] xl:w-[45%] 2xl:w-[40%] md:max-w-[720px]',
    '50%':   'w-full sm:w-full md:w-[60%] lg:w-[55%] xl:w-[50%] 2xl:w-[50%] md:max-w-[800px]',
    '60%':   'w-full sm:w-full md:w-[65%] lg:w-[60%] xl:w-[55%] 2xl:w-[60%] md:max-w-[900px]',
    '70%':   'w-full sm:w-full md:w-[70%] lg:w-[65%] xl:w-[65%] 2xl:w-[70%] md:max-w-[1000px]',
    '80%':   'w-full sm:w-full md:w-[75%] lg:w-[75%] xl:w-[75%] 2xl:w-[80%] md:max-w-[1200px]',
    '90%':   'w-full sm:w-full md:w-[85%] lg:w-[85%] xl:w-[85%] 2xl:w-[90%] md:max-w-[1400px]',
    'full':  'w-full',
    // Fixed pixel widths - consistent across breakpoints
    '480px': 'w-full sm:max-w-[480px] sm:ml-auto',
    '560px': 'w-full sm:max-w-[560px] sm:ml-auto',
    '640px': 'w-full sm:max-w-[640px] sm:ml-auto',
    '720px': 'w-full sm:max-w-[720px] sm:ml-auto',
    '800px': 'w-full sm:max-w-[800px] sm:ml-auto',
    '400px': 'w-full sm:max-w-[400px] sm:ml-auto',
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

const handleEscape = (e) => {
  if (e.key === 'Escape' && props.modelValue && props.closeOnEscape) {
    handleClose()
  }
}

const onContentScroll = (e) => {
  headerShadow.value = e.target.scrollTop > 4
}

watch(() => props.modelValue, async (newValue) => {
  if (newValue) {
    document.body.style.overflow = 'hidden'
    await nextTick()
    panelRef.value?.focus()
  } else {
    document.body.style.overflow = ''
    headerShadow.value = false
  }
})

onMounted(() => {
  window.addEventListener('keydown', handleEscape)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleEscape)
  document.body.style.overflow = ''
})
</script>

<style scoped>
/* Vue Transition hooks — cannot be expressed as Tailwind utility classes */
.overlay-fade-enter-active,
.overlay-fade-leave-active { transition: opacity 0.25s ease; }
.overlay-fade-enter-from,
.overlay-fade-leave-to     { opacity: 0; }

.backdrop-enter-active  { transition: opacity 0.3s ease; }
.backdrop-leave-active  { transition: opacity 0.2s ease; }
.backdrop-enter-from,
.backdrop-leave-to      { opacity: 0; }

.slide-enter-active { transition: transform 0.32s cubic-bezier(0.22, 1, 0.36, 1); }
.slide-leave-active { transition: transform 0.22s cubic-bezier(0.4, 0, 1, 1); }
.slide-enter-from,
.slide-leave-to     { transform: translateX(100%); }
.slide-enter-to,
.slide-leave-from   { transform: translateX(0); }
</style>
