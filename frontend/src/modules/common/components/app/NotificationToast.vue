<template>
  <Teleport to="body">
    <div class="fixed top-4 right-4 z-[9999] flex flex-col gap-3 max-w-sm w-[calc(100vw-2rem)] sm:w-96 pointer-events-none">
      <TransitionGroup name="notification">
        <div
          v-for="notification in notifications"
          :key="notification.id"
          class="flex items-start gap-3 p-4 bg-white dark:bg-slate-800 rounded-lg shadow-lg dark:shadow-black/40 border-l-4 pointer-events-auto cursor-pointer transition-all duration-300 hover:-translate-x-1 hover:shadow-xl"
          :class="{
            'border-l-emerald-500': notification.type === 'success',
            'border-l-red-500':     notification.type === 'error',
            'border-l-amber-500':   notification.type === 'warning',
            'border-l-blue-500':    notification.type === 'info',
          }"
          @click="remove(notification.id)"
        >
          <!-- Icon -->
          <div class="flex-shrink-0">
            <svg v-if="notification.type === 'success'" class="w-6 h-6 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <svg v-else-if="notification.type === 'error'" class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <svg v-else-if="notification.type === 'warning'" class="w-6 h-6 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <svg v-else class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>

          <!-- Content -->
          <div class="flex-1 min-w-0">
            <h4 v-if="notification.title" class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-0.5">{{ notification.title }}</h4>
            <p class="text-sm text-gray-500 dark:text-slate-400 break-words">{{ notification.message }}</p>
          </div>

          <!-- Close -->
          <button
            class="flex-shrink-0 p-1 rounded text-gray-400 hover:bg-gray-100 dark:hover:bg-slate-700 hover:text-gray-600 dark:hover:text-slate-300 transition-colors"
            @click.stop="remove(notification.id)"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<script setup>
import { storeToRefs } from 'pinia'
import { useNotificationStore } from '@/stores/notifications'

const notificationStore = useNotificationStore()
const { notifications } = storeToRefs(notificationStore)
const { remove } = notificationStore
</script>

<style scoped>
/* Vue TransitionGroup — cannot be expressed as Tailwind classes */
.notification-enter-active,
.notification-leave-active { transition: all 0.3s ease; }
.notification-enter-from   { opacity: 0; transform: translateX(100%); }
.notification-leave-to     { opacity: 0; transform: translateX(100%) scale(0.9); }
.notification-move         { transition: transform 0.3s ease; }
</style>
