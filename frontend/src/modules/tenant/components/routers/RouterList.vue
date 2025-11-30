<template>
  <div class="flex-1 overflow-hidden relative">
    <div v-if="routers.length" class="h-full flex flex-col">
      <div class="flex-1 overflow-hidden flex flex-col">
        <div class="border-b border-gray-200 bg-gray-50">
          <div class="px-6 py-3 grid grid-cols-12 gap-4">
            <div
              class="col-span-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
            >
              ID
            </div>
            <div
              class="col-span-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
            >
              Name
            </div>
            <div
              class="col-span-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
            >
              IP Address
            </div>
            <div
              class="col-span-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
            >
              Model
            </div>
            <div
              class="col-span-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
            >
              Status
            </div>
            <div
              class="col-span-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"
            >
              Actions
            </div>
          </div>
        </div>
        <div class="flex-1 overflow-y-auto">
          <div class="divide-y divide-gray-200">
            <div
              v-for="router in routers"
              :key="router.id"
              class="px-6 py-4 grid grid-cols-12 gap-4 hover:bg-gray-50 transition-colors"
            >
              <div class="col-span-2 font-mono text-sm text-gray-900">
                {{ router.id }}
              </div>
              <div class="col-span-2 text-sm text-gray-900">
                {{ router.name }}
              </div>
              <div class="col-span-2 text-sm text-gray-900">
                {{ router.ip_address || 'N/A' }}
              </div>
              <div class="col-span-2 text-sm text-gray-900">
                {{ router.model || 'N/A' }}
              </div>
              <div class="col-span-2">
                <span
                  :class="statusBadgeClass(router.status)"
                  class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                >
                  {{ router.status }}
                </span>
              </div>
              <div class="col-span-2 flex justify-end space-x-2">
                <button
                  @click="$emit('view-details', router)"
                  class="inline-flex items-center px-3 py-1 border border-gray-300 shadow-sm text-xs font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                >
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="-ml-0.5 mr-1.5 h-3 w-3"
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
                <button
                  @click="$emit('edit-router', router)"
                  class="inline-flex items-center px-3 py-1 border border-gray-300 shadow-sm text-xs font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                >
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="-ml-0.5 mr-1.5 h-3 w-3"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                    />
                  </svg>
                  Edit
                </button>
                <button
                  @click="$emit('delete-router', router)"
                  class="inline-flex items-center px-3 py-1 border border-red-300 shadow-sm text-xs font-medium rounded-md text-red-700 bg-white hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                >
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="-ml-0.5 mr-1.5 h-3 w-3"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5-4h4m-4 0h4m-7 4h10"
                    />
                  </svg>
                  Delete
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="border-t border-gray-200 px-6 py-3 flex items-center justify-between">
        <div class="text-sm text-gray-500">
          Showing <span class="font-medium">{{ routers.length }}</span> routers
        </div>
      </div>
    </div>
    <!-- Empty State - Mobile Friendly -->
    <div v-else class="flex items-center justify-center min-h-[400px] p-4 sm:p-8">
      <div class="text-center max-w-md w-full">
        <!-- Icon with gradient background -->
        <div class="mx-auto flex items-center justify-center h-20 w-20 sm:h-24 sm:w-24 rounded-full bg-gradient-to-br from-blue-100 to-indigo-100 mb-6">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            class="h-10 w-10 sm:h-12 sm:w-12 text-blue-600"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="1.5"
              d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
            />
          </svg>
        </div>

        <!-- Title and Description -->
        <h3 class="text-xl sm:text-2xl font-semibold text-gray-900 mb-2">
          No Routers Yet
        </h3>
        <p class="text-sm sm:text-base text-gray-500 mb-8 px-4">
          Get started by creating your first MikroTik router. You'll be able to manage configurations, monitor status, and provision services.
        </p>

        <!-- Action Button -->
        <button
          @click="$emit('create-router')"
          class="inline-flex items-center justify-center w-full sm:w-auto px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-lg text-white bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200 transform hover:scale-105"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            class="h-5 w-5 mr-2"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M12 4v16m8-8H4"
            />
          </svg>
          Create Your First Router
        </button>

        <!-- Optional: Quick tips -->
        <div class="mt-8 pt-6 border-t border-gray-200">
          <p class="text-xs sm:text-sm text-gray-400 mb-3">Quick Tips:</p>
          <div class="space-y-2 text-left">
            <div class="flex items-start text-xs sm:text-sm text-gray-600">
              <svg class="h-5 w-5 text-green-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
              </svg>
              <span>Auto-generated MikroTik configurations</span>
            </div>
            <div class="flex items-start text-xs sm:text-sm text-gray-600">
              <svg class="h-5 w-5 text-green-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
              </svg>
              <span>Real-time monitoring and status updates</span>
            </div>
            <div class="flex items-start text-xs sm:text-sm text-gray-600">
              <svg class="h-5 w-5 text-green-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
              </svg>
              <span>Integrated VPN and hotspot services</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    routers: Array,
    statusBadgeClass: Function,
  },
  emits: ['view-details', 'edit-router', 'create-router', 'delete-router'],
}
</script>
