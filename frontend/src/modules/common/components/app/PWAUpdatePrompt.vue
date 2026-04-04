<template>
  <Transition name="slide-up">
    <div
      v-if="needRefresh"
      class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg bg-white p-4 shadow-2xl border border-gray-200"
    >
      <div class="flex items-start gap-3">
        <div class="flex-shrink-0">
          <svg
            class="h-6 w-6 text-blue-600"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
            />
          </svg>
        </div>
        <div class="flex-1">
          <h3 class="text-sm font-semibold text-gray-900">
            Update Available
          </h3>
          <p class="mt-1 text-sm text-gray-600">
            A new version is available. Reload to update?
          </p>
          <div class="mt-3 flex gap-2">
            <button
              @click="updateServiceWorker()"
              class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
            >
              Update Now
            </button>
            <button
              @click="close"
              class="rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200"
            >
              Later
            </button>
          </div>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref } from 'vue'
import { useRegisterSW } from 'virtual:pwa-register/vue'

const needRefresh = ref(false)
const updateServiceWorker = ref(() => {})

// Only register in production, not in dev
if (!import.meta.env.DEV) {
  const sw = useRegisterSW({
    onNeedRefresh() {
      needRefresh.value = true
    },
    onOfflineReady() {
      console.log('App ready to work offline')
    },
  })
  updateServiceWorker.value = sw.updateServiceWorker
}

const close = () => {
  needRefresh.value = false
}
</script>
