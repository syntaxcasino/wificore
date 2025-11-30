<template>
  <transition
    v-if="showUpdateOverlay"
    enter-active-class="transition-transform duration-300 ease-out"
    enter-from-class="translate-x-full"
    enter-to-class="translate-x-0"
    leave-active-class="transition-transform duration-300 ease-in"
    leave-from-class="translate-x-0"
    leave-to-class="translate-x-full"
  >
    <div
      class="fixed inset-y-0 right-0 z-[9999] w-full sm:w-2/3 lg:w-1/2 xl:w-2/5 bg-white shadow-2xl flex flex-col"
    >
      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-indigo-50 flex-shrink-0">
        <div class="flex items-center gap-2">
          <div class="p-1.5 bg-blue-100 rounded-lg">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
          </div>
          <div>
            <h3 class="text-base font-semibold text-gray-800">Edit Router</h3>
            <p class="text-xs text-gray-500">{{ selectedRouter?.name || 'Update router configuration' }}</p>
          </div>
        </div>
        <button
          type="button"
          @click="$emit('close-update')"
          class="p-1.5 rounded-lg hover:bg-white transition-colors text-gray-500 hover:text-gray-700"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            class="w-4 h-4"
            viewBox="0 0 20 20"
            fill="currentColor"
          >
            <path
              fill-rule="evenodd"
              d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
              clip-rule="evenodd"
            />
          </svg>
        </button>
      </div>

      <!-- Loading State -->
      <div v-if="formSubmitting" class="flex flex-col items-center justify-center flex-1 gap-4 p-8">
        <div
          class="w-12 h-12 border-[3px] border-gray-100 border-t-blue-500 rounded-full animate-spin"
        ></div>
        <p class="text-gray-500 font-medium">Updating router...</p>
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="flex flex-col items-center justify-center flex-1 gap-4 p-8">
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
        <p class="text-center text-gray-700 font-medium max-w-md">{{ error }}</p>
        <button
          type="button"
          @click="$emit('retry')"
          class="px-5 py-2.5 text-sm font-medium text-white bg-red-500 rounded-lg hover:bg-red-600 transition-colors shadow-sm"
        >
          Retry
        </button>
      </div>

      <!-- Main Form Content -->
      <div v-else class="p-4 overflow-y-auto flex-1 bg-gray-50">
        <!-- Form Message -->
        <div v-if="formMessage.text" class="mb-5">
          <div
            :class="{
              'bg-green-50 text-green-700': formMessage.type === 'success',
              'bg-red-50 text-red-700': formMessage.type === 'error',
            }"
            class="p-3 rounded-lg text-sm font-medium"
          >
            {{ formMessage.text }}
          </div>
        </div>

        <form @submit.prevent="$emit('update-router')" class="space-y-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Router Name</label>
            <input
              v-model="formData.name"
              type="text"
              required
              class="block w-full px-3.5 py-2.5 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
              placeholder="Router name"
            />
            <p v-if="!formData.name && formSubmitted" class="mt-1.5 text-sm text-red-500">
              Router name is required
            </p>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">IP Address</label>
              <input
                v-model="formData.ip_address"
                type="text"
                class="block w-full px-3.5 py-2.5 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                placeholder="192.168.1.1"
              />
              <p
                v-if="
                  formSubmitted && formData.ip_address && !isValidIPAddress(formData.ip_address)
                "
                class="mt-1.5 text-sm text-red-500"
              >
                Invalid IP address
              </p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Port</label>
              <input
                v-model.number="formData.port"
                type="number"
                min="1"
                max="65535"
                class="block w-full px-3.5 py-2.5 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                placeholder="22"
              />
              <p
                v-if="formSubmitted && (formData.port < 1 || formData.port > 65535)"
                class="mt-1.5 text-sm text-red-500"
              >
                Port must be between 1 and 65535
              </p>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Username</label>
              <input
                v-model="formData.username"
                type="text"
                class="block w-full px-3.5 py-2.5 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                placeholder="admin"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Password</label>
              <input
                v-model="formData.password"
                type="password"
                class="block w-full px-3.5 py-2.5 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                placeholder="••••••••"
              />
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Location</label>
            <input
              v-model="formData.location"
              type="text"
              class="block w-full px-3.5 py-2.5 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
              placeholder="Server room A"
            />
          </div>

          <div>
            <button
              type="button"
              @click="$emit('generate-configs')"
              :disabled="configLoading"
              class="w-full px-5 py-2.5 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors shadow-sm flex items-center justify-center"
            >
              <svg
                v-if="configLoading"
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4 mr-2 animate-spin"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                />
              </svg>
              {{ configLoading ? 'Generating...' : 'Generate Configurations' }}
            </button>
          </div>

          <div v-if="configToken" class="space-y-3">
            <label class="block text-sm font-medium text-gray-700 mb-2">Configuration Token</label>
            <div class="relative">
              <pre
                class="text-sm font-mono text-gray-800 bg-gray-50 p-3.5 rounded-lg overflow-x-auto"
                >{{ configToken }}</pre
              >
              <button
                type="button"
                @click="$emit('copy-token', configToken)"
                class="absolute top-2.5 right-2.5 p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
              >
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  class="h-4 w-4"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"
                  />
                </svg>
              </button>
            </div>
            <p class="text-xs text-gray-500">
              Use this command on the router to apply configurations:
              <code class="text-blue-500"
                >/system script run [fetch url="https://api.yourserver.com/configs?token={{
                  configToken
                }}"]</code
              >
            </p>
          </div>
        </form>
      </div>

      <!-- Footer Buttons -->
      <div class="border-t border-gray-200 bg-white px-4 py-2.5 flex justify-between gap-3 flex-shrink-0">
        <button
          type="button"
          @click="$emit('close-update')"
          class="px-3 py-1.5 text-xs font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors"
        >
          Cancel
        </button>
        <button
          type="button"
          @click="$emit('update-router')"
          :disabled="
            !formData.name ||
            (formData.ip_address && !isValidIPAddress(formData.ip_address)) ||
            (formData.port && (formData.port < 1 || formData.port > 65535))
          "
          class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Update Router
        </button>
      </div>
    </div>
  </transition>
</template>

<script>
export default {
  props: {
    showUpdateOverlay: Boolean,
    selectedRouter: Object,
    formData: Object,
    formSubmitting: Boolean,
    formMessage: Object,
    formSubmitted: Boolean,
    configToken: String,
    configLoading: Boolean,
    error: String,
    formatTimestamp: Function,
  },
  emits: ['close-update', 'generate-configs', 'copy-token', 'update-router', 'retry'],
  methods: {
    isValidIPAddress(ip) {
      const ipRegex =
        /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/
      return ipRegex.test(ip)
    },
  },
}
</script>
