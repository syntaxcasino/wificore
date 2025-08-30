<template>
  <div class="flex flex-col h-screen bg-white rounded-lg shadow-md overflow-hidden">
    <!-- Header -->
    <div class="sticky top-0 z-30 flex-shrink-0 px-6 py-4 border-b border-gray-200 bg-gray-50">
      <div class="flex items-center justify-between">
        <h2 class="text-2xl font-bold text-gray-800">Packages</h2>
        <div class="flex gap-2">
          <button
            @click="fetchPackages()"
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
            @click="openCreateOverlay"
            class="flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-green-500 rounded-md hover:bg-green-600 transition-colors"
          >
            Create
          </button>
          <button
            v-if="selectedPackage"
            @click="openEditOverlay"
            class="flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-yellow-500 rounded-md hover:bg-yellow-600 transition-colors"
          >
            Update
          </button>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex flex-col items-center justify-center flex-1 gap-4 p-8">
      <div
        class="w-10 h-10 border-4 border-gray-200 border-t-blue-500 rounded-full animate-spin"
      ></div>
      <p class="text-gray-600">Loading packages...</p>
    </div>

    <!-- Error -->
    <div
      v-else-if="error"
      class="flex flex-col items-center justify-center flex-1 gap-4 p-8 text-red-500"
    >
      <svg
        xmlns="http://www.w3.org/2000/svg"
        class="w-10 h-10"
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
      <p class="text-center">{{ error }}</p>
      <button
        @click="fetchPackages()"
        class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors"
      >
        Retry
      </button>
    </div>

    <!-- Content -->
    <div v-else class="flex-1 overflow-hidden relative">
      <!-- Details Overlay -->
      <transition
        enter-active-class="transition-transform duration-300 ease-out"
        enter-from-class="translate-x-full"
        enter-to-class="translate-x-0"
        leave-active-class="transition-transform duration-300 ease-in"
        leave-from-class="translate-x-0"
        leave-to-class="translate-x-full"
      >
        <div
          v-if="showDetailsOverlay"
          key="details"
          class="fixed inset-y-0 right-0 z-50 w-full sm:w-1/2 lg:w-1/3 bg-white shadow-xl border-l border-gray-200 flex flex-col"
        >
          <div class="flex items-center justify-between p-4 border-b border-gray-100 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-800">Package Details</h3>
            <button
              @click="closeDetails"
              class="p-1 rounded-full hover:bg-gray-200 transition-colors text-gray-500"
            >
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-5 h-5"
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
          <div class="p-6 overflow-y-auto flex-1">
            <div class="space-y-4">
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider">Type</h4>
                  <p class="mt-1 text-gray-900 font-medium">{{ currentPackage.type }}</p>
                </div>
                <div>
                  <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider">Name</h4>
                  <p class="mt-1 text-gray-900 font-medium">{{ currentPackage.name }}</p>
                </div>
                <div>
                  <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider">Price</h4>
                  <p class="mt-1 text-gray-900 font-medium">${{ currentPackage.price }}</p>
                </div>
                <div>
                  <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Duration
                  </h4>
                  <p class="mt-1 text-gray-900">{{ currentPackage.duration }}</p>
                </div>
                <div>
                  <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Upload Speed
                  </h4>
                  <p class="mt-1 text-gray-900">{{ currentPackage.upload_speed }}</p>
                </div>
                <div>
                  <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Download Speed
                  </h4>
                  <p class="mt-1 text-gray-900">{{ currentPackage.download_speed }}</p>
                </div>
                <div>
                  <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Devices
                  </h4>
                  <p class="mt-1 text-gray-900">{{ currentPackage.devices }}</p>
                </div>
                <div>
                  <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Enable Burst
                  </h4>
                  <p class="mt-1 text-gray-900">{{ currentPackage.enable_burst ? 'Yes' : 'No' }}</p>
                </div>
                <div>
                  <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Enable Schedule
                  </h4>
                  <p class="mt-1 text-gray-900">
                    {{ currentPackage.enable_schedule ? 'Yes' : 'No' }}
                  </p>
                </div>
                <div>
                  <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Hide from Client
                  </h4>
                  <p class="mt-1 text-gray-900">
                    {{ currentPackage.hide_from_client ? 'Yes' : 'No' }}
                  </p>
                </div>
              </div>
              <div class="grid grid-cols-2 gap-4 pt-4 border-t border-gray-100">
                <div>
                  <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Created At
                  </h4>
                  <p class="mt-1 text-gray-500 text-sm">
                    {{ formatTimestamp(currentPackage.created_at) }}
                  </p>
                </div>
                <div>
                  <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Updated At
                  </h4>
                  <p class="mt-1 text-gray-500 text-sm">
                    {{ formatTimestamp(currentPackage.updated_at) }}
                  </p>
                </div>
              </div>
            </div>
          </div>
          <div class="sticky bottom-0 p-4 border-t border-gray-100 bg-gray-50 flex justify-end">
            <button
              @click="closeDetails"
              class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors"
            >
              Close
            </button>
          </div>
        </div>
      </transition>

      <!-- Create/Edit Overlay -->
      <transition
        enter-active-class="transition-transform duration-300 ease-out"
        enter-from-class="translate-x-full"
        enter-to-class="translate-x-0"
        leave-active-class="transition-transform duration-300 ease-in"
        leave-from-class="translate-x-0"
        leave-to-class="translate-x-full"
      >
        <div
          v-if="showFormOverlay"
          key="form"
          class="fixed inset-y-0 right-0 z-50 w-full sm:w-1/2 lg:w-1/3 bg-white shadow-xl border-l border-gray-200 flex flex-col"
        >
          <div class="flex items-center justify-between p-4 border-b border-gray-100 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-800">
              {{ isEditing ? 'Edit Package' : 'Create Package' }}
            </h3>
            <button
              @click="closeFormOverlay"
              class="p-1 rounded-full hover:bg-gray-200 transition-colors text-gray-500"
            >
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-5 h-5"
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
          <div class="p-6 overflow-y-auto flex-1">
            <div
              v-if="formSubmitting"
              class="flex flex-col items-center justify-center h-full gap-4"
            >
              <div
                class="w-10 h-10 border-4 border-gray-200 border-t-blue-500 rounded-full animate-spin"
              ></div>
              <p class="text-gray-600">
                {{ isEditing ? 'Updating package...' : 'Creating package...' }}
              </p>
            </div>
            <div v-else class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                <input
                  v-model="formData.type"
                  type="text"
                  class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                <input
                  v-model="formData.name"
                  type="text"
                  class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                />
              </div>
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Price</label>
                  <input
                    v-model="formData.price"
                    type="number"
                    step="0.01"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Duration</label>
                  <input
                    v-model="formData.duration"
                    type="text"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
              </div>
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Upload Speed</label>
                  <input
                    v-model="formData.upload_speed"
                    type="text"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Download Speed</label>
                  <input
                    v-model="formData.download_speed"
                    type="text"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Devices</label>
                <input
                  v-model="formData.devices"
                  type="number"
                  class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                />
              </div>
              <div class="grid grid-cols-3 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Enable Burst</label>
                  <input
                    v-model="formData.enable_burst"
                    type="checkbox"
                    class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1"
                    >Enable Schedule</label
                  >
                  <input
                    v-model="formData.enable_schedule"
                    type="checkbox"
                    class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1"
                    >Hide from Client</label
                  >
                  <input
                    v-model="formData.hide_from_client"
                    type="checkbox"
                    class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                  />
                </div>
              </div>
            </div>
          </div>
          <div
            class="sticky bottom-0 p-4 border-t border-gray-100 bg-gray-50 flex justify-end gap-3"
          >
            <button
              v-if="!formSubmitting"
              @click="closeFormOverlay"
              class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors"
            >
              Cancel
            </button>
            <button
              v-if="!formSubmitting"
              @click="submitForm"
              class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
            >
              {{ isEditing ? 'Update' : 'Create' }}
            </button>
          </div>
        </div>
      </transition>

      <div v-if="packages.length" class="h-full flex flex-col">
        <!-- Table Container -->
        <div class="flex-1 overflow-hidden flex flex-col">
          <!-- Table Header -->
          <div class="border-b border-gray-200 bg-gray-50">
            <div class="px-6 py-3">
              <div class="flex items-center justify-between">
                <div class="flex space-x-4">
                  <div class="relative">
                    <input
                      type="text"
                      placeholder="Search packages..."
                      class="pl-10 pr-4 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    />
                    <div
                      class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
                    >
                      <svg class="h-4 w-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                        <path
                          fill-rule="evenodd"
                          d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z"
                          clip-rule="evenodd"
                        />
                      </svg>
                    </div>
                  </div>
                  <select
                    class="border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  >
                    <option>All Types</option>
                    <option>Type 1</option>
                    <option>Type 2</option>
                  </select>
                </div>
                <div class="flex items-center space-x-2 text-sm text-gray-500">
                  <span>{{ packages.length }} packages</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Table -->
          <div class="flex-1 overflow-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50 sticky top-0 z-10">
                <tr>
                  <th
                    scope="col"
                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                  >
                    ID
                  </th>
                  <th
                    scope="col"
                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                  >
                    Type
                  </th>
                  <th
                    scope="col"
                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                  >
                    Name
                  </th>
                  <th
                    scope="col"
                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                  >
                    Price
                  </th>
                  <th
                    scope="col"
                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                  >
                    Duration
                  </th>
                  <th
                    scope="col"
                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                  >
                    Created At
                  </th>
                  <th scope="col" class="relative px-6 py-3">
                    <span class="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr
                  v-for="pkg in packages"
                  :key="pkg.id"
                  class="hover:bg-gray-50 transition-colors duration-150"
                >
                  <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-500">
                    {{ pkg.id }}
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm font-medium text-gray-900">{{ pkg.type }}</div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">{{ pkg.name }}</div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900 font-medium">${{ pkg.price }}</div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">{{ pkg.duration }}</div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-500">
                      {{ formatTimestamp(pkg.created_at) }}
                    </div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                    <button
                      @click="openDetails(pkg)"
                      class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                    >
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="-ml-0.5 mr-2 h-4 w-4"
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
          <div
            class="bg-white px-6 py-3 flex items-center justify-between border-t border-gray-200"
          >
            <div class="flex-1 flex justify-between items-center">
              <div>
                <p class="text-sm text-gray-700">
                  Showing
                  <span class="font-medium">1</span>
                  to
                  <span class="font-medium">10</span>
                  of
                  <span class="font-medium">{{ packages.length }}</span>
                  results
                </p>
              </div>
              <div class="flex space-x-2">
                <button
                  class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                  disabled
                >
                  Previous
                </button>
                <button
                  class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                >
                  Next
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty State -->
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
        <p class="text-lg">No packages available</p>
        <button
          @click="openCreateOverlay"
          class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
        >
          Create your first package
        </button>
      </div>
    </div>

    <!-- Main Footer -->
    <div
      class="sticky bottom-0 flex-shrink-0 px-6 py-3 text-xs text-gray-500 border-t border-gray-200 bg-gray-50"
    >
      <div class="flex items-center justify-between">
        <span>© {{ new Date().getFullYear() }} Packages Viewer</span>
        <span
          :class="{
            'text-green-600 bg-green-100': !loading,
            'text-yellow-600 bg-yellow-100': loading,
          }"
          class="px-2 py-1 rounded-full text-xs font-medium"
        >
          {{ loading ? 'Loading...' : 'Ready' }}
        </span>
      </div>
    </div>
  </div>
</template>

<script>
import { ref } from 'vue'
import { usePackages } from '@/composables/usePackages'

export default {
  setup() {
    const { packages, loading, error, fetchPackages, addPackage, editPackage } = usePackages()

    const showDetailsOverlay = ref(false)
    const currentPackage = ref({})
    const showFormOverlay = ref(false)
    const isEditing = ref(false)
    const selectedPackage = ref(null)
    const formData = ref({
      type: '',
      name: '',
      duration: '',
      upload_speed: '',
      download_speed: '',
      price: 0,
      devices: 0,
      enable_burst: false,
      enable_schedule: false,
      hide_from_client: false,
    })
    const formSubmitting = ref(false)

    const formatTimestamp = (timestamp) => {
      return new Date(timestamp).toLocaleString()
    }

    const openDetails = (pkg) => {
      currentPackage.value = pkg
      showDetailsOverlay.value = true
    }

    const closeDetails = () => {
      showDetailsOverlay.value = false
      currentPackage.value = {}
    }

    const openCreateOverlay = () => {
      isEditing.value = false
      formData.value = {
        type: '',
        name: '',
        duration: '',
        upload_speed: '',
        download_speed: '',
        price: 0,
        devices: 0,
        enable_burst: false,
        enable_schedule: false,
        hide_from_client: false,
      }
      showFormOverlay.value = true
    }

    const openEditOverlay = () => {
      if (selectedPackage.value) {
        isEditing.value = true
        formData.value = { ...selectedPackage.value }
        showFormOverlay.value = true
      }
    }

    const closeFormOverlay = () => {
      showFormOverlay.value = false
      formSubmitting.value = false
      selectedPackage.value = null
      formData.value = {
        type: '',
        name: '',
        duration: '',
        upload_speed: '',
        download_speed: '',
        price: 0,
        devices: 0,
        enable_burst: false,
        enable_schedule: false,
        hide_from_client: false,
      }
    }

    const submitForm = async () => {
      formSubmitting.value = true
      try {
        if (isEditing.value) {
          await editPackage(selectedPackage.value.id, formData.value)
        } else {
          await addPackage(formData.value)
        }
        closeFormOverlay()
        fetchPackages()
      } catch (err) {
        formSubmitting.value = false
        // Error is already handled in usePackages composable
      }
    }

    return {
      packages,
      loading,
      error,
      fetchPackages,
      showDetailsOverlay,
      currentPackage,
      showFormOverlay,
      isEditing,
      selectedPackage,
      formData,
      formSubmitting,
      openDetails,
      closeDetails,
      openCreateOverlay,
      openEditOverlay,
      closeFormOverlay,
      submitForm,
      formatTimestamp,
    }
  },
  mounted() {
    this.fetchPackages()
  },
}
</script>
