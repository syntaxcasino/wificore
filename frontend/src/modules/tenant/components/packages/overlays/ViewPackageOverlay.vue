<template>
  <SlideOverlay
    v-model="isOpen"
    title="Package Details"
    :subtitle="currentPackage?.name || 'Complete package information'"
    icon="Package"
    width="70%"
    gradient
    no-padding
    @close="$emit('close-details')"
  >
    <!-- Main Content -->
    <div class="flex flex-col h-full overflow-hidden bg-slate-50">
      <!-- Header strip -->
      <div class="flex-shrink-0 bg-gradient-to-r px-6 py-4"
        :class="currentPackage?.type === 'hotspot' ? 'from-purple-700 to-indigo-700' : 'from-cyan-600 to-teal-600'"
      >
        <div class="flex items-center gap-4">
          <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center text-white text-xl font-bold shadow-lg flex-shrink-0">
            {{ (currentPackage?.name || 'P').charAt(0).toUpperCase() }}
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-lg font-bold text-white truncate">{{ currentPackage?.name || 'Package' }}</div>
            <div class="text-sm text-white/70 font-mono mt-0.5">KES {{ formatMoney(currentPackage?.price || 0) }} / {{ currentPackage?.validity || currentPackage?.duration || '—' }}</div>
            <div class="flex items-center gap-2 mt-1.5">
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide"
                :class="currentPackage?.status === 'active' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-slate-500/20 text-slate-300'">
                <span class="w-1.5 h-1.5 rounded-full mr-1" :class="currentPackage?.status === 'active' ? 'bg-emerald-400' : 'bg-slate-400'"></span>
                {{ currentPackage?.status === 'active' ? 'Active' : 'Inactive' }}
              </span>
              <span class="text-xs text-white/70 bg-white/10 px-2 py-0.5 rounded-full capitalize">{{ currentPackage?.type || '—' }}</span>
            </div>
          </div>
          <!-- Quick stats -->
          <div class="hidden md:flex items-center gap-4 flex-shrink-0">
            <div class="text-center">
              <div class="text-lg font-bold text-white">{{ currentPackage?.devices || '—' }}</div>
              <div class="text-[10px] text-white/60 uppercase tracking-wide">Devices</div>
            </div>
            <div class="text-center">
              <div class="text-lg font-bold text-white">{{ currentPackage?.users_count ?? 0 }}</div>
              <div class="text-[10px] text-white/60 uppercase tracking-wide">Users</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Content -->
      <div class="flex-1 overflow-y-auto min-h-0 p-6">
        <!-- KPI Grid -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
          <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
            <div class="text-xs text-slate-500 mb-1">Price</div>
            <div class="text-2xl font-bold text-slate-800">KES {{ formatMoney(currentPackage?.price || 0) }}</div>
            <div class="text-xs text-slate-400 mt-1">per {{ currentPackage?.validity || currentPackage?.duration || '—' }}</div>
          </div>
          <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
            <div class="text-xs text-slate-500 mb-1">Duration</div>
            <div class="text-2xl font-bold text-slate-800">{{ currentPackage?.duration || '—' }}</div>
            <div class="text-xs text-slate-400 mt-1">Package cycle</div>
          </div>
          <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
            <div class="text-xs text-slate-500 mb-1">Max Devices</div>
            <div class="text-2xl font-bold text-slate-800">{{ currentPackage?.devices || '—' }}</div>
            <div class="text-xs text-slate-400 mt-1">Per account</div>
          </div>
          <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
            <div class="text-xs text-slate-500 mb-1">Data Limit</div>
            <div class="text-2xl font-bold text-slate-800">{{ currentPackage?.data_limit || 'Unlimited' }}</div>
            <div class="text-xs text-slate-400 mt-1">Monthly cap</div>
          </div>
        </div>

        <!-- Description -->
        <div v-if="currentPackage?.description" class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm mb-6">
          <h4 class="text-sm font-semibold text-slate-700 mb-3 flex items-center">
            <svg class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7" /></svg>
            Description
          </h4>
          <p class="text-sm text-slate-600">{{ currentPackage?.description }}</p>
        </div>

        <!-- Speed & Data -->
        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm mb-6">
          <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
            <svg class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
            Speed & Data
          </h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Speed</label>
              <p class="text-slate-900 font-medium">{{ currentPackage?.speed || 'N/A' }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Data Limit</label>
              <p class="text-slate-900 font-medium">{{ currentPackage?.data_limit || 'Unlimited' }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Upload Speed</label>
              <p class="text-slate-900 font-medium">{{ currentPackage?.upload_speed || 'N/A' }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Download Speed</label>
              <p class="text-slate-900 font-medium">{{ currentPackage?.download_speed || 'N/A' }}</p>
            </div>
          </div>
        </div>

        <!-- Duration & Validity -->
        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm mb-6">
          <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
            <svg class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            Duration & Validity
          </h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Duration</label>
              <p class="text-slate-900 font-medium">{{ currentPackage?.duration || 'N/A' }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Validity</label>
              <p class="text-slate-900 font-medium">{{ currentPackage?.validity || currentPackage?.duration || 'N/A' }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Max Devices</label>
              <p class="text-slate-900 font-medium">{{ currentPackage?.devices || 'N/A' }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Type</label>
              <p class="text-slate-900 font-medium capitalize">{{ currentPackage?.type || 'N/A' }}</p>
            </div>
          </div>
        </div>

        <!-- Advanced Options -->
        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm mb-6">
          <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
            <svg class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
            Advanced Options
          </h4>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Burst Enabled</label>
              <span :class="[
                'inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold uppercase tracking-wide',
                currentPackage?.enable_burst ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'
              ]">
                {{ currentPackage?.enable_burst ? 'Yes' : 'No' }}
              </span>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Schedule Enabled</label>
              <span :class="[
                'inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold uppercase tracking-wide',
                currentPackage?.enable_schedule ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'
              ]">
                {{ currentPackage?.enable_schedule ? 'Yes' : 'No' }}
              </span>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Hidden from Client</label>
              <span :class="[
                'inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold uppercase tracking-wide',
                currentPackage?.hide_from_client ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600'
              ]">
                {{ currentPackage?.hide_from_client ? 'Yes' : 'No' }}
              </span>
            </div>
          </div>
        </div>

        <!-- Statistics -->
        <div v-if="currentPackage?.users_count !== undefined" class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm mb-6">
          <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
            <svg class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
            Statistics
          </h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Active Users</label>
              <p class="text-2xl font-bold text-slate-800">{{ currentPackage?.users_count }}</p>
            </div>
          </div>
        </div>

        <!-- Metadata -->
        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
          <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
            <svg class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            Metadata
          </h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Package ID</label>
              <p class="text-slate-900 font-mono text-sm">{{ currentPackage?.id || 'N/A' }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Visibility</label>
              <p class="text-slate-900 font-medium">{{ currentPackage?.hide_from_client ? 'Hidden from clients' : 'Visible to clients' }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Created</label>
              <p class="text-slate-900 text-sm">{{ formatTimestamp(currentPackage?.created_at) }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Last Updated</label>
              <p class="text-slate-900 text-sm">{{ formatTimestamp(currentPackage?.updated_at) }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <template #footer>
      <div class="flex gap-3">
        <button
          type="button"
          @click="$emit('close-details')"
          class="flex-1 px-4 py-2.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors"
        >
          Close
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { computed } from 'vue'
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
