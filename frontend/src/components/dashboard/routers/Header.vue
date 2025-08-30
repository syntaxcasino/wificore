<template>
  <div>
    <div class="sticky top-0 z-30 flex-shrink-0 px-6 py-4 border-b border-gray-200 bg-gray-50">
      <div class="flex items-center justify-between">
        <h2 class="text-2xl font-bold text-gray-800">Router List</h2>
        <div class="flex gap-2">
          <button
            @click="$emit('fetch-routers')"
            :disabled="loading"
            class="flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-500 rounded-md hover:bg-blue-600 disabled:opacity-70 disabled:cursor-not-allowed transition-colors"
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
          <button
            @click="$emit('create-router')"
            class="flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-green-500 rounded-md hover:bg-green-600 transition-colors"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />
            </svg>
            Create
          </button>

        </div>
      </div>
    </div>

  </div>
</template>

<script>
export default {
  props: {
    loading: Boolean,
    selectedRouter: Object
  },
  emits: ['fetch-routers', 'create-router', 'edit-router']
}
</script>