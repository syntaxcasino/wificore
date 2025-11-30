<template>
  <!-- Slide-in Overlay Panel (Right to Left) -->
  <div v-if="showDetailsOverlay" class="fixed inset-y-0 right-0 z-[9999] w-full sm:w-2/3 lg:w-1/2 xl:w-2/5 bg-white shadow-2xl flex flex-col transition-transform duration-300 ease-out"
       :class="showDetailsOverlay ? 'translate-x-0' : 'translate-x-full'">
    <!-- Header -->
    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-indigo-50 flex-shrink-0">
      <div class="flex items-center gap-3">
        <div class="p-2 rounded-lg" :class="currentPackage?.type === 'hotspot' ? 'bg-purple-100' : 'bg-cyan-100'">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" :class="currentPackage?.type === 'hotspot' ? 'text-purple-600' : 'text-cyan-600'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path v-if="currentPackage?.type === 'hotspot'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
            <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
          </svg>
        </div>
        <div>
          <h3 class="text-lg font-semibold text-gray-800">{{ currentPackage?.name }}</h3>
          <p class="text-xs text-gray-500">Package Details</p>
        </div>
      </div>
      <button
        type="button"
        @click="$emit('close-details')"
        class="p-2 rounded-lg hover:bg-white transition-colors text-gray-500 hover:text-gray-700"
      >
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
    </div>

    <!-- Main Content - Scrollable -->
    <div class="flex-1 overflow-y-auto p-6 bg-gray-50">
      <div class="space-y-6">
        <!-- Status Badge -->
        <div class="flex items-center justify-between">
          <span :class="[
            'px-3 py-1 text-sm font-semibold rounded-full',
            currentPackage?.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
          ]">
            {{ currentPackage?.status === 'active' ? 'Active' : 'Inactive' }}
          </span>
          <span :class="[
            'px-3 py-1 text-xs font-medium rounded-full',
            currentPackage?.type === 'hotspot' ? 'bg-purple-100 text-purple-800' : 'bg-cyan-100 text-cyan-800'
          ]">
            {{ currentPackage?.type }}
          </span>
        </div>

        <!-- Price Card -->
        <div class="bg-gradient-to-br p-6 rounded-xl text-white" :class="currentPackage?.type === 'hotspot' ? 'from-purple-500 to-indigo-600' : 'from-cyan-500 to-blue-600'">
          <div class="text-sm opacity-90 mb-1">Package Price</div>
          <div class="text-4xl font-bold mb-1">KES {{ formatMoney(currentPackage?.price || 0) }}</div>
          <div class="text-sm opacity-90">per {{ currentPackage?.validity }}</div>
        </div>

        <!-- Description -->
        <div v-if="currentPackage?.description" class="bg-white p-4 rounded-lg border border-gray-200">
          <h4 class="text-sm font-semibold text-gray-800 mb-2">Description</h4>
          <p class="text-sm text-gray-600">{{ currentPackage?.description }}</p>
        </div>

        <!-- Speed & Data -->
        <div class="bg-white p-4 rounded-lg border border-gray-200">
          <h4 class="text-sm font-semibold text-gray-800 mb-4">Speed & Data</h4>
          <div class="space-y-3">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                <span class="text-sm text-gray-600">Speed</span>
              </div>
              <span class="text-sm font-semibold text-gray-900">{{ currentPackage?.speed }}</span>
            </div>

            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                </svg>
                <span class="text-sm text-gray-600">Upload Speed</span>
              </div>
              <span class="text-sm font-semibold text-gray-900">{{ currentPackage?.upload_speed }}</span>
            </div>

            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10" />
                </svg>
                <span class="text-sm text-gray-600">Download Speed</span>
              </div>
              <span class="text-sm font-semibold text-gray-900">{{ currentPackage?.download_speed }}</span>
            </div>

            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                </svg>
                <span class="text-sm text-gray-600">Data Limit</span>
              </div>
              <span class="text-sm font-semibold text-gray-900">{{ currentPackage?.data_limit || 'Unlimited' }}</span>
            </div>
          </div>
        </div>

        <!-- Duration & Validity -->
        <div class="bg-white p-4 rounded-lg border border-gray-200">
          <h4 class="text-sm font-semibold text-gray-800 mb-4">Duration & Validity</h4>
          <div class="space-y-3">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="text-sm text-gray-600">Duration</span>
              </div>
              <span class="text-sm font-semibold text-gray-900">{{ currentPackage?.duration }}</span>
            </div>

            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <span class="text-sm text-gray-600">Validity</span>
              </div>
              <span class="text-sm font-semibold text-gray-900">{{ currentPackage?.validity }}</span>
            </div>

            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-teal-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                <span class="text-sm text-gray-600">Max Devices</span>
              </div>
              <span class="text-sm font-semibold text-gray-900">{{ currentPackage?.devices }}</span>
            </div>
          </div>
        </div>

        <!-- Advanced Options -->
        <div class="bg-white p-4 rounded-lg border border-gray-200">
          <h4 class="text-sm font-semibold text-gray-800 mb-4">Advanced Options</h4>
          <div class="space-y-2">
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600">Burst Enabled</span>
              <span :class="[
                'px-2 py-1 text-xs font-medium rounded',
                currentPackage?.enable_burst ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'
              ]">
                {{ currentPackage?.enable_burst ? 'Yes' : 'No' }}
              </span>
            </div>

            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600">Schedule Enabled</span>
              <span :class="[
                'px-2 py-1 text-xs font-medium rounded',
                currentPackage?.enable_schedule ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'
              ]">
                {{ currentPackage?.enable_schedule ? 'Yes' : 'No' }}
              </span>
            </div>

            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600">Hidden from Client</span>
              <span :class="[
                'px-2 py-1 text-xs font-medium rounded',
                currentPackage?.hide_from_client ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600'
              ]">
                {{ currentPackage?.hide_from_client ? 'Yes' : 'No' }}
              </span>
            </div>
          </div>
        </div>

        <!-- Statistics -->
        <div v-if="currentPackage?.users_count !== undefined" class="bg-white p-4 rounded-lg border border-gray-200">
          <h4 class="text-sm font-semibold text-gray-800 mb-4">Statistics</h4>
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
              <span class="text-sm text-gray-600">Active Users</span>
            </div>
            <span class="text-lg font-bold text-blue-600">{{ currentPackage?.users_count }}</span>
          </div>
        </div>

        <!-- Metadata -->
        <div class="bg-white p-4 rounded-lg border border-gray-200">
          <h4 class="text-sm font-semibold text-gray-800 mb-4">Metadata</h4>
          <div class="space-y-2 text-xs text-gray-600">
            <div class="flex justify-between">
              <span>Package ID:</span>
              <span class="font-mono text-gray-900">{{ currentPackage?.id }}</span>
            </div>
            <div class="flex justify-between">
              <span>Created:</span>
              <span class="text-gray-900">{{ formatTimestamp(currentPackage?.created_at) }}</span>
            </div>
            <div class="flex justify-between">
              <span>Last Updated:</span>
              <span class="text-gray-900">{{ formatTimestamp(currentPackage?.updated_at) }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <div class="flex-shrink-0 px-6 py-4 border-t border-gray-200 bg-white">
      <div class="flex items-center justify-end gap-3">
        <button
          type="button"
          @click="$emit('close-details')"
          class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
        >
          Close
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  showDetailsOverlay: Boolean,
  currentPackage: Object
})

const emit = defineEmits(['close-details'])

const formatMoney = (amount) => {
  return new Intl.NumberFormat('en-KE').format(amount)
}

const formatTimestamp = (timestamp) => {
  if (!timestamp) return 'N/A'
  const date = new Date(timestamp)
  return date.toLocaleString()
}
</script>
