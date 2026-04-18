<template>
  <Teleport to="body">
    <Transition name="dialog-fade">
      <div
        v-if="isOpen"
        class="fixed inset-0 z-[99999] flex items-center justify-center p-4"
        role="dialog"
        aria-modal="true"
      >
        <!-- Backdrop — clear, non-blurred -->
        <div class="absolute inset-0 bg-slate-900/20" @click="cancel" />

        <!-- Dialog card -->
        <Transition name="dialog-pop">
          <div
            v-if="isOpen"
            class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl ring-1 ring-slate-900/10 dark:ring-slate-700/50 w-full max-w-md overflow-hidden"
          >
            <!-- Coloured top bar -->
            <div class="h-1 w-full" :class="accentBarClass" />

            <!-- Body -->
            <div class="px-6 pt-5 pb-4">
              <!-- Icon + title row -->
              <div class="flex items-start gap-4 mb-3">
                <div class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center" :class="iconBgClass">
                  <component :is="iconComponent" class="w-5 h-5" :class="iconColorClass" />
                </div>
                <div class="flex-1 min-w-0 pt-1">
                  <h3 class="text-base font-bold text-slate-900 dark:text-slate-100 leading-snug">{{ title }}</h3>
                  <p v-if="message" class="mt-1 text-sm text-slate-500 dark:text-slate-400 leading-relaxed">{{ message }}</p>
                </div>
              </div>
            </div>

            <!-- Footer -->
            <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/50 border-t border-slate-100 dark:border-slate-700 flex items-center justify-end gap-3">
              <button
                @click="cancel"
                class="px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600 transition-colors focus:outline-none focus:ring-2 focus:ring-slate-300"
              >
                {{ cancelText }}
              </button>
              <button
                @click="confirm"
                class="px-4 py-2 text-sm font-semibold rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1"
                :class="confirmBtnClass"
              >
                {{ confirmText }}
              </button>
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { AlertTriangle, Trash2, Info, CheckCircle } from 'lucide-vue-next'
import { useConfirmStore } from '@/stores/confirm'

const confirmStore = useConfirmStore()
const { isOpen, title, message, confirmText, cancelText, variant } = storeToRefs(confirmStore)
const { confirm, cancel } = confirmStore

const iconComponent = computed(() => {
  const map = { danger: Trash2, warning: AlertTriangle, info: Info, success: CheckCircle }
  return map[variant.value] || AlertTriangle
})

const accentBarClass = computed(() => {
  const map = { danger: 'bg-red-500', warning: 'bg-amber-400', info: 'bg-blue-500', success: 'bg-emerald-500' }
  return map[variant.value] || 'bg-slate-400'
})

const iconBgClass = computed(() => {
  const map = { danger: 'bg-red-100', warning: 'bg-amber-100', info: 'bg-blue-100', success: 'bg-emerald-100' }
  return map[variant.value] || 'bg-slate-100'
})

const iconColorClass = computed(() => {
  const map = { danger: 'text-red-600', warning: 'text-amber-600', info: 'text-blue-600', success: 'text-emerald-600' }
  return map[variant.value] || 'text-slate-600'
})

const confirmBtnClass = computed(() => {
  const map = {
    danger:  'bg-red-600 hover:bg-red-700 text-white focus:ring-red-500',
    warning: 'bg-amber-500 hover:bg-amber-600 text-white focus:ring-amber-400',
    info:    'bg-blue-600 hover:bg-blue-700 text-white focus:ring-blue-500',
    success: 'bg-emerald-600 hover:bg-emerald-700 text-white focus:ring-emerald-500',
  }
  return map[variant.value] || 'bg-slate-700 hover:bg-slate-800 text-white focus:ring-slate-500'
})
</script>

<style scoped>
.dialog-fade-enter-active,
.dialog-fade-leave-active { transition: opacity 0.2s ease; }
.dialog-fade-enter-from,
.dialog-fade-leave-to { opacity: 0; }

.dialog-pop-enter-active { transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.2s ease; }
.dialog-pop-leave-active { transition: transform 0.18s ease, opacity 0.18s ease; }
.dialog-pop-enter-from  { transform: scale(0.92) translateY(8px); opacity: 0; }
.dialog-pop-leave-to    { transform: scale(0.96) translateY(4px); opacity: 0; }
</style>
