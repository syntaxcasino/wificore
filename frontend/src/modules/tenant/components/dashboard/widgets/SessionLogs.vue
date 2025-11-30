<template>
  <div class="flex flex-col h-screen bg-white rounded-xl shadow-lg overflow-hidden border border-gray-100">
    <!-- Header -->
    <div class="sticky top-0 z-30 flex-shrink-0 px-6 py-4 bg-white border-b border-gray-100">
      <div class="flex items-center justify-between">
        <div>
          <h2 class="text-2xl font-semibold text-gray-800">System Logs</h2>
          <p class="text-sm text-gray-500 mt-1">Monitor and review system activities</p>
        </div>
        <button
          @click="fetchLogs()"
          :disabled="isLoading"
          class="flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-primary-500 rounded-lg hover:bg-primary-600 disabled:opacity-70 disabled:cursor-not-allowed transition-all duration-200 transform hover:-translate-y-0.5"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            class="w-5 h-5"
            viewBox="0 0 20 20"
            fill="currentColor"
          >
            <path
              fill-rule="evenodd"
              d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z"
              clip-rule="evenodd"
            />
          </svg>
          Refresh
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="isLoading" class="flex flex-col items-center justify-center flex-1 gap-4 p-8">
      <div
        class="w-12 h-12 border-4 border-gray-200 border-t-primary-500 rounded-full animate-spin"
      ></div>
      <p class="text-gray-500">Loading logs...</p>
    </div>

    <!-- Error -->
    <div
      v-else-if="error"
      class="flex flex-col items-center justify-center flex-1 gap-4 p-8"
    >
      <div class="p-3 bg-red-100 rounded-full">
        <svg
          xmlns="http://www.w3.org/2000/svg"
          class="w-8 h-8 text-red-500"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
          />
        </svg>
      </div>
      <p class="text-center text-red-500">{{ error }}</p>
      <button
        @click="fetchLogs()"
        class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-lg hover:bg-red-600 transition-colors"
      >
        Retry
      </button>
    </div>

    <!-- Content -->
    <div v-else class="flex-1 overflow-hidden relative">
      <!-- Slide Panel -->
      <transition name="slide">
        <div
          v-if="showDetailsOverlay"
          key="details"
          class="log-details-panel fixed inset-y-0 right-0 z-50 w-full top-16 sm:w-full lg:w-1/2 xl:w-2/5 bg-white shadow-xl border-l border-gray-200 flex flex-col"
        >
          <div class="flex items-center justify-between p-5 border-b bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-800">Log Details</h3>
            <button @click="closeDetails" class="text-gray-400 hover:text-gray-600 transition-colors">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
              </svg>
            </button>
          </div>
          <div class="p-5 overflow-auto flex-1 bg-gray-50">
            <pre
              class="p-4 bg-white rounded-lg font-mono text-sm text-gray-700 whitespace-pre-wrap border border-gray-200 shadow-sm"
            >{{ currentDetails }}</pre>
          </div>
        </div>
      </transition>

      <div v-if="logs.length" class="p-6 space-y-6 h-full flex flex-col">
        <!-- Table -->
        <div class="overflow-hidden rounded-lg border border-gray-200 shadow-sm">
          <table class="w-full">
            <thead class="bg-gray-50">
              <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                <th class="p-4 font-medium">ID</th>
                <th class="p-4 font-medium">Action</th>
                <th class="p-4 font-medium">Created At</th>
                <th class="p-4 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="log in logs" :key="log.id" class="hover:bg-gray-50/80 transition-colors">
                <td class="p-4 font-mono text-sm text-gray-600">{{ log.id }}</td>
                <td class="p-4">
                  <span
                    :class="getActionClasses(log.action)"
                    class="inline-block px-3 py-1 text-xs font-medium capitalize rounded-full"
                  >
                    {{ log.action }}
                  </span>
                </td>
                <td class="p-4 whitespace-nowrap text-sm text-gray-500">
                  {{ formatTimestamp(log.created_at) }}
                </td>
                <td class="p-4 text-right">
                  <button
                    @click="openDetails(log.details)"
                    class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
                  >
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      class="w-4 h-4"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke="currentColor"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                      />
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                      />
                    </svg>
                    View
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="flex items-center justify-between">
          <div class="text-sm text-gray-500">
            Showing page {{ currentPage }} of {{ lastPage }}
          </div>
          <div class="flex items-center gap-2">
            <button
              @click="fetchLogs(prevPageUrl)"
              :disabled="!prevPageUrl || isLoading"
              class="flex items-center gap-1 px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-4 h-4"
                viewBox="0 0 20 20"
                fill="currentColor"
              >
                <path
                  fill-rule="evenodd"
                  d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z"
                  clip-rule="evenodd"
                />
              </svg>
              Previous
            </button>
            <button
              @click="fetchLogs(nextPageUrl)"
              :disabled="!nextPageUrl || isLoading"
              class="flex items-center gap-1 px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              Next
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-4 h-4"
                viewBox="0 0 20 20"
                fill="currentColor"
              >
                <path
                  fill-rule="evenodd"
                  d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                  clip-rule="evenodd"
                />
              </svg>
            </button>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else class="flex flex-col items-center justify-center flex-1 gap-4 p-8">
        <div class="p-3 bg-gray-100 rounded-full">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            class="w-10 h-10 text-gray-400"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
            />
          </svg>
        </div>
        <p class="text-lg text-gray-500">No logs available</p>
        <p class="text-sm text-gray-400">System logs will appear here once available</p>
      </div>
    </div>

    <!-- Main Footer -->
    <div
      class="sticky bottom-0 flex-shrink-0 px-6 py-3 text-xs text-gray-500 border-t border-gray-100 bg-white"
    >
      <div class="flex items-center justify-between">
        <span>© {{ new Date().getFullYear() }} System Logs Viewer</span>
        <span
          :class="{
            'text-green-600 bg-green-100': !isLoading,
            'text-yellow-600 bg-yellow-100': isLoading,
          }"
          class="px-2 py-1 rounded-full text-xs font-medium"
        >
          {{ isLoading ? 'Loading...' : 'Ready' }}
        </span>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, watch } from 'vue'
import useLogs from '@/modules/tenant/composables/data/useLogs'

export default {
  setup() {
    const {
      logs,
      currentPage,
      lastPage,
      prevPageUrl,
      nextPageUrl,
      error,
      isLoading,
      fetchLogs,
      formatMessage,
      formatTimestamp,
      getActionClasses,
    } = useLogs()

    const showDetailsOverlay = ref(false)
    const currentDetails = ref('')

    const openDetails = (details) => {
      currentDetails.value = formatMessage(details)
      showDetailsOverlay.value = true
    }

    const closeDetails = () => {
      showDetailsOverlay.value = false
    }

    watch(showDetailsOverlay, (open) => {
      document.body.style.overflow = open ? 'hidden' : ''
    })

    return {
      logs,
      currentPage,
      lastPage,
      prevPageUrl,
      nextPageUrl,
      error,
      isLoading,
      fetchLogs,
      formatMessage,
      formatTimestamp,
      getActionClasses,
      showDetailsOverlay,
      currentDetails,
      openDetails,
      closeDetails,
    }
  },
  mounted() {
    this.fetchLogs()
  },
}
</script>
