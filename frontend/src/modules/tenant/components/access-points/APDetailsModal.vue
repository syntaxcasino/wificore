<template>
  <SlideOverlay
    :model-value="modelValue"
    title="Access Point Details"
    :subtitle="apDetails?.name || 'Device information'"
    icon="wifi"
    width="50%"
    @update:model-value="$emit('update:modelValue', $event)"
    @close="$emit('close')"
  >
    <!-- Loading State -->
    <div v-if="loading" class="flex flex-col items-center justify-center flex-1 gap-4 p-8">
      <div class="relative">
        <div class="w-12 h-12 border-[3px] border-blue-100 rounded-full"></div>
        <div class="w-12 h-12 border-[3px] border-t-blue-500 border-r-transparent border-b-blue-500 border-l-blue-500 rounded-full animate-spin absolute top-0"></div>
      </div>
      <p class="text-gray-500 font-medium">Loading access point details...</p>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="flex flex-col items-center justify-center flex-1 gap-4 p-8">
      <div class="p-3 bg-red-100 rounded-full">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <p class="text-center text-gray-700 font-medium max-w-md">{{ error }}</p>
    </div>

    <!-- Main Content -->
    <div v-else class="p-4 overflow-y-auto flex-1 bg-gray-50">
      <!-- Status Indicator -->
      <div class="flex items-center justify-between mb-6 p-4 bg-white rounded-xl shadow-sm">
        <div class="flex items-center">
          <div :class="statusDotClass" class="w-3 h-3 rounded-full mr-3"></div>
          <span class="text-sm font-medium capitalize">{{ apDetails?.status || 'unknown' }}</span>
        </div>
        <EntityStatusBadge :status="apDetails?.status" size="sm" />
      </div>

      <!-- AP Details -->
      <div class="space-y-4">
        <!-- Basic Info Card -->
        <div class="bg-white p-5 rounded-xl shadow-sm">
          <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Basic Information
          </h4>
          <div class="space-y-4">
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Name</label>
              <p class="text-gray-900 font-medium">{{ apDetails?.name || 'N/A' }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Location</label>
              <p class="text-gray-900 text-sm">{{ apDetails?.location || 'No location specified' }}</p>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Vendor</label>
                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium capitalize bg-slate-100 text-slate-800">
                  {{ apDetails?.vendor || 'other' }}
                </span>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Model</label>
                <p class="text-gray-900 text-sm">{{ apDetails?.model || 'N/A' }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Network Info Card -->
        <div class="bg-white p-5 rounded-xl shadow-sm">
          <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
            </svg>
            Network Information
          </h4>
          <div class="space-y-4">
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">IP Address</label>
              <p class="text-gray-900 font-mono text-sm">{{ apDetails?.ip_address || 'N/A' }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">MAC Address</label>
              <p class="text-gray-900 font-mono text-sm">{{ apDetails?.mac_address || 'N/A' }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Serial Number</label>
              <p class="text-gray-900 font-mono text-sm">{{ apDetails?.serial_number || 'N/A' }}</p>
            </div>
          </div>
        </div>

        <!-- Router Info Card -->
        <div v-if="apDetails?.router" class="bg-white p-5 rounded-xl shadow-sm">
          <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
            </svg>
            Connected Router
          </h4>
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-semibold text-sm">
              {{ apDetails.router.name?.charAt(0).toUpperCase() || 'R' }}
            </div>
            <div>
              <p class="text-sm font-medium text-gray-900">{{ apDetails.router.name || 'Unknown Router' }}</p>
              <p class="text-xs text-gray-500 font-mono">{{ apDetails.router.ip_address || '' }}</p>
            </div>
          </div>
        </div>

        <!-- Usage Stats Card -->
        <div class="bg-white p-5 rounded-xl shadow-sm">
          <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
            </svg>
            Usage Statistics
          </h4>
          <div class="grid grid-cols-2 gap-4">
            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 p-3 rounded-lg border border-blue-200">
              <div class="text-[11px] text-blue-600 font-medium">Active Users</div>
              <div class="text-blue-900 font-bold">{{ apDetails?.active_users || 0 }}</div>
            </div>
            <div class="bg-gradient-to-br from-emerald-50 to-green-50 p-3 rounded-lg border border-emerald-200">
              <div class="text-[11px] text-emerald-600 font-medium">Total Capacity</div>
              <div class="text-emerald-900 font-bold">{{ apDetails?.total_capacity || 'N/A' }}</div>
            </div>
          </div>
        </div>

        <!-- Timestamps Card -->
        <div class="bg-white p-5 rounded-xl shadow-sm">
          <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Timestamps
          </h4>
          <div class="grid grid-cols-1 gap-4">
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Created</label>
              <p class="text-gray-900 text-sm">{{ formatDate(apDetails?.created_at) }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Last Updated</label>
              <p class="text-gray-900 text-sm">{{ formatDate(apDetails?.updated_at) }}</p>
            </div>
            <div v-if="apDetails?.last_synced">
              <label class="block text-xs font-medium text-gray-500 mb-1">Last Synced</label>
              <p class="text-gray-900 text-sm">{{ formatDate(apDetails.last_synced) }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer Actions -->
    <template #footer>
      <div class="flex gap-3">
        <button
          @click="$emit('close')"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
        >
          Close
        </button>
        <button
          v-if="apDetails?.status !== 'online'"
          @click="$emit('sync')"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors"
        >
          Sync Now
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { computed } from 'vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'

const props = defineProps({
  modelValue: { type: Boolean, required: true },
  apDetails: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' }
})

const emit = defineEmits(['update:modelValue', 'close', 'sync', 'edit'])

const statusDotClass = computed(() => {
  const status = props.apDetails?.status
  if (status === 'online') return 'bg-emerald-500'
  if (status === 'offline') return 'bg-red-500'
  return 'bg-slate-400'
})

const formatDate = (dateString) => {
  if (!dateString) return 'N/A'
  const date = new Date(dateString)
  return date.toLocaleDateString('en-US', { 
    year: 'numeric', 
    month: 'short', 
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}
</script>
