<template>
  <div class="flex flex-col h-full bg-gradient-to-br from-slate-50 via-gray-50 to-blue-50/30 rounded-lg shadow-lg overflow-hidden">
    <!-- Header -->
    <div class="flex-shrink-0 bg-white border-b border-slate-200 shadow-sm relative z-0">
      <div class="px-6 py-5">
        <div class="flex items-center justify-between gap-6">
          <!-- Left: Title & Icon -->
          <div class="flex items-center gap-3 z-0">
            <div class="w-11 h-11 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
              </svg>
            </div>
            <div>
              <h2 class="text-xl font-bold text-slate-900">Access Points</h2>
              <p class="text-xs text-slate-500 mt-0.5">Manage your wireless access points</p>
            </div>
          </div>
          
          <!-- Center: Search Bar -->
          <div class="flex-1 max-w-xl">
            <div class="relative">
              <div class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
              </div>
              <input 
                v-model="searchQuery"
                type="text" 
                placeholder="Search by name, IP, MAC, or Serial Number..." 
                class="w-full py-2.5 pl-10 pr-10 text-sm text-slate-700 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 focus:bg-white focus:outline-none transition-all placeholder:text-slate-400"
              >
              <div v-if="searchQuery" class="absolute inset-y-0 right-0 flex items-center pr-3">
                <button @click="searchQuery = ''" class="text-slate-400 hover:text-slate-600 transition-colors p-1 hover:bg-slate-100 rounded">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                  </svg>
                </button>
              </div>
            </div>
          </div>
          
          <!-- Right: Actions -->
          <div class="flex items-center gap-3">
            <button @click="fetchAccessPoints" :disabled="loading"
              class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 hover:border-slate-400 disabled:opacity-50 disabled:cursor-not-allowed transition-all">
              <svg xmlns="http://www.w3.org/2000/svg" :class="loading ? 'animate-spin' : ''" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd"
                  d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z"
                  clip-rule="evenodd" />
              </svg>
              <span>Refresh</span>
            </button>
            <button @click="openCreateOverlay"
              class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold text-white bg-gradient-to-r from-blue-600 to-indigo-600 rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all shadow-md hover:shadow-lg">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd"
                  d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z"
                  clip-rule="evenodd" />
              </svg>
              <span>Add Access Point</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="flex-1 min-h-0 overflow-y-auto">
      <!-- Loading State -->
      <div v-if="loading && !accessPoints.length" class="p-6 space-y-4">
        <div v-for="i in 5" :key="i" class="bg-white rounded-lg p-4 shadow-sm border border-gray-200 animate-pulse">
          <div class="flex items-center space-x-4">
            <div class="w-12 h-12 bg-gray-200 rounded-lg"></div>
            <div class="flex-1 space-y-2">
              <div class="h-4 bg-gray-200 rounded w-1/4"></div>
              <div class="h-3 bg-gray-200 rounded w-1/3"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p class="text-center">{{ error }}</p>
        <button @click="fetchAccessPoints" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">
          Retry
        </button>
      </div>

      <!-- Table -->
      <div v-else class="flex flex-col">
        <div v-if="filteredAccessPoints.length" class="px-6 pt-6 pb-2">
          <div class="bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
              <table class="w-full">
                <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
                  <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">IP Address</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">MAC Address</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Serial Number</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Vendor</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Active Users</th>
                    <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="ap in filteredAccessPoints" :key="ap.id" class="hover:bg-blue-50/50 transition-colors">
                    <td class="px-6 py-4">
                      <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center text-blue-600 font-bold text-xs">
                          {{ ap.name.charAt(0).toUpperCase() }}
                        </div>
                        <div>
                          <p class="text-sm font-medium text-slate-900">{{ ap.name }}</p>
                          <p class="text-xs text-slate-500">{{ ap.location || 'No Location' }}</p>
                        </div>
                      </div>
                    </td>
                    <td class="px-6 py-4 text-sm text-slate-600 font-mono">{{ ap.ip_address }}</td>
                    <td class="px-6 py-4 text-sm text-slate-600 font-mono">{{ ap.mac_address || '—' }}</td>
                    <td class="px-6 py-4 text-sm text-slate-600 font-mono">{{ ap.serial_number || '—' }}</td>
                    <td class="px-6 py-4 text-sm text-slate-600 capitalize">{{ ap.vendor }}</td>
                    <td class="px-6 py-4">
                      <span :class="{
                        'bg-green-100 text-green-800': ap.status === 'online',
                        'bg-red-100 text-red-800': ap.status === 'offline',
                        'bg-yellow-100 text-yellow-800': ap.status === 'unknown'
                      }" class="px-2 py-1 text-xs font-medium rounded-full capitalize">
                        {{ ap.status }}
                      </span>
                    </td>
                    <td class="px-6 py-4 text-sm text-slate-600">{{ ap.active_users }}</td>
                    <td class="px-6 py-4 text-right">
                      <div class="flex items-center justify-end gap-2">
                        <button @click="syncStatus(ap)" class="p-1 text-slate-400 hover:text-blue-600 transition-colors" title="Sync Status">
                          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                          </svg>
                        </button>
                        <button @click="openEditOverlay(ap)" class="p-1 text-slate-400 hover:text-indigo-600 transition-colors" title="Edit">
                          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                          </svg>
                        </button>
                        <button @click="deleteAccessPoint(ap)" class="p-1 text-slate-400 hover:text-red-600 transition-colors" title="Delete">
                          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                          </svg>
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Empty State -->
        <div v-if="!filteredAccessPoints.length && !loading" class="flex flex-col items-center justify-center gap-6 p-12 text-center">
          <div class="relative">
            <div class="absolute inset-0 bg-blue-100 rounded-full blur-3xl opacity-30"></div>
            <div class="relative w-32 h-32 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-2xl flex items-center justify-center shadow-xl">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-16 h-16 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
              </svg>
            </div>
          </div>
          <div class="space-y-2">
            <h3 class="text-2xl font-bold text-slate-900">No Access Points Found</h3>
            <p class="text-slate-600 max-w-md">
              {{ searchQuery ? 'No access points match your search criteria.' : 'Get started by adding your first access point.' }}
            </p>
          </div>
          <button v-if="!searchQuery" @click="openCreateOverlay"
            class="px-5 py-2.5 text-sm font-medium text-white bg-gradient-to-r from-blue-600 to-indigo-600 rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all shadow-md hover:shadow-lg flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />
            </svg>
            Add Access Point
          </button>
        </div>
      </div>
    </div>

    <!-- Form Overlay -->
    <div v-if="showFormOverlay" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
      <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" @click="closeFormOverlay"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="relative inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
          <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
            <div class="sm:flex sm:items-start">
              <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                  {{ isEditing ? 'Edit Access Point' : 'Add Access Point' }}
                </h3>
                <div class="mt-4 space-y-4">
                  <!-- Form Fields -->
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Router</label>
                    <select v-model="formData.router_id" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                      <option value="" disabled>Select a router</option>
                      <option v-for="router in availableRouters" :key="router.id" :value="router.id">{{ router.name }} ({{ router.ip_address }})</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Name</label>
                    <input v-model="formData.name" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Vendor</label>
                    <select v-model="formData.vendor" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                      <option v-for="v in vendors" :key="v.value" :value="v.value">{{ v.label }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Model</label>
                    <input v-model="formData.model" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">IP Address</label>
                    <input v-model="formData.ip_address" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">MAC Address</label>
                    <input v-model="formData.mac_address" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Serial Number</label>
                    <input v-model="formData.serial_number" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="Required for Zero-Touch">
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Location</label>
                    <input v-model="formData.location" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                  </div>
                </div>
                <!-- Error Message -->
                <div v-if="formMessage.text" :class="formMessage.type === 'error' ? 'text-red-600' : 'text-green-600'" class="mt-2 text-sm">
                  {{ formMessage.text }}
                </div>
              </div>
            </div>
          </div>
          <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
            <button @click="submitForm" :disabled="formSubmitting" type="button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm disabled:opacity-50">
              {{ formSubmitting ? 'Saving...' : 'Save' }}
            </button>
            <button @click="closeFormOverlay" type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
              Cancel
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAccessPoints } from '@/modules/tenant/composables/data/useAccessPoints'

const {
  accessPoints,
  loading,
  error,
  showFormOverlay,
  isEditing,
  formData,
  availableRouters,
  vendors,
  formSubmitting,
  formMessage,
  fetchAccessPoints,
  openCreateOverlay,
  openEditOverlay,
  closeFormOverlay,
  submitForm,
  deleteAccessPoint,
  syncStatus
} = useAccessPoints()

const searchQuery = ref('')

const filteredAccessPoints = computed(() => {
  if (!searchQuery.value) return accessPoints.value
  const query = searchQuery.value.toLowerCase()
  return accessPoints.value.filter(ap => 
    ap.name.toLowerCase().includes(query) ||
    ap.ip_address.toLowerCase().includes(query) ||
    (ap.mac_address && ap.mac_address.toLowerCase().includes(query)) ||
    (ap.serial_number && ap.serial_number.toLowerCase().includes(query))
  )
})

onMounted(() => {
  fetchAccessPoints()
})
</script>
