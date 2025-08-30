<template>
  <div class="flex-1 overflow-hidden relative">
    <div v-if="routers.length" class="h-full flex flex-col">
      <div class="flex-1 overflow-hidden flex flex-col">
        <div class="border-b border-gray-200 bg-gray-50">
          <div class="px-6 py-3 grid grid-cols-12 gap-4">
            <div class="col-span-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              ID
            </div>
            <div class="col-span-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Name
            </div>
            <div class="col-span-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              IP Address
            </div>
            <div class="col-span-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Model
            </div>
            <div class="col-span-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Status
            </div>
            <div class="col-span-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
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
                <span :class="statusBadgeClass(router.status)" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                  {{ router.status }}
                </span>
              </div>
              <div class="col-span-2 flex justify-end space-x-2">
                <button
                  @click="$emit('view-details', router)"
                  class="inline-flex items-center px-3 py-1 border border-gray-300 shadow-sm text-xs font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="-ml-0.5 mr-1.5 h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                  </svg>
                  View
                </button>
                <button
                  @click="$emit('edit-router', router)"
                  class="inline-flex items-center px-3 py-1 border border-gray-300 shadow-sm text-xs font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="-ml-0.5 mr-1.5 h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                  </svg>
                  Edit
                </button>
                <button
                  @click="$emit('delete-router', router)"
                  class="inline-flex items-center px-3 py-1 border border-red-300 shadow-sm text-xs font-medium rounded-md text-red-700 bg-white hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="-ml-0.5 mr-1.5 h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5-4h4m-4 0h4m-7 4h10" />
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
    <div v-else class="flex flex-col items-center justify-center flex-1 gap-4 p-8 text-gray-400">
      <svg
        xmlns="http://www.w3.org/2000/svg"
        class="w-12 h-12"
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
      <p class="text-lg">No routers available</p>
      <button
        @click="$emit('create-router')"
        class="mt-2 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
      >
        Create New Router
      </button>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    routers: Array,
    statusBadgeClass: Function
  },
  emits: ['view-details', 'edit-router', 'create-router', 'delete-router']
}
</script>