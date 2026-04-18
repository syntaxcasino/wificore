<template>
  <SlideOverlay
    v-model="isOpen"
    :title="currentPackage?.name || 'Package Details'"
    subtitle="Package Details"
    icon="Package"
    width="50%"
    @close="$emit('close-details')"
  >
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
              <Zap class="w-4 h-4 text-blue-600" />
              <span class="text-sm text-gray-600">Speed</span>
            </div>
            <span class="text-sm font-semibold text-gray-900">{{ currentPackage?.speed }}</span>
          </div>
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <ArrowUp class="w-4 h-4 text-green-600" />
              <span class="text-sm text-gray-600">Upload Speed</span>
            </div>
            <span class="text-sm font-semibold text-gray-900">{{ currentPackage?.upload_speed }}</span>
          </div>
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <ArrowDown class="w-4 h-4 text-blue-600" />
              <span class="text-sm text-gray-600">Download Speed</span>
            </div>
            <span class="text-sm font-semibold text-gray-900">{{ currentPackage?.download_speed }}</span>
          </div>
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <Database class="w-4 h-4 text-amber-600" />
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
              <Clock class="w-4 h-4 text-purple-600" />
              <span class="text-sm text-gray-600">Duration</span>
            </div>
            <span class="text-sm font-semibold text-gray-900">{{ currentPackage?.duration }}</span>
          </div>
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <Calendar class="w-4 h-4 text-indigo-600" />
              <span class="text-sm text-gray-600">Validity</span>
            </div>
            <span class="text-sm font-semibold text-gray-900">{{ currentPackage?.validity }}</span>
          </div>
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <Users class="w-4 h-4 text-teal-600" />
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
            <UsersIcon class="w-4 h-4 text-blue-600" />
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

    <template #footer>
      <div class="flex gap-3">
        <button
          type="button"
          @click="$emit('close-details')"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors"
        >
          Close
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { computed } from 'vue'
import { Zap, ArrowUp, ArrowDown, Database, Clock, Calendar, Users, Users as UsersIcon } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const props = defineProps({
  showDetailsOverlay: Boolean,
  currentPackage: Object
})

const emit = defineEmits(['close-details'])

const isOpen = computed({
  get: () => props.showDetailsOverlay,
  set: (val) => { if (!val) emit('close-details') }
})

const formatMoney = (amount) => {
  return new Intl.NumberFormat('en-KE').format(amount)
}

const formatTimestamp = (timestamp) => {
  if (!timestamp) return 'N/A'
  const date = new Date(timestamp)
  return date.toLocaleString()
}
</script>
