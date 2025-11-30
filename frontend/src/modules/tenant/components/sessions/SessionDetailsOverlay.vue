<template>
  <transition
    v-if="show"
    enter-active-class="transition-transform duration-300 ease-out"
    enter-from-class="translate-x-full"
    enter-to-class="translate-x-0"
    leave-active-class="transition-transform duration-300 ease-in"
    leave-from-class="translate-x-0"
    leave-to-class="translate-x-full"
  >
    <div class="fixed inset-y-0 right-0 z-[9999] w-full sm:w-2/3 lg:w-1/2 xl:w-2/5 bg-white shadow-2xl flex flex-col">
      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-indigo-50 flex-shrink-0">
        <div class="flex items-center gap-2">
          <div class="p-1.5 bg-blue-100 rounded-lg">
            <component :is="icon" class="h-4 w-4 text-blue-600" />
          </div>
          <div>
            <h3 class="text-base font-semibold text-gray-800">Session Details</h3>
            <p class="text-xs text-gray-500">{{ session?.user?.name || session?.username || 'Session information' }}</p>
          </div>
        </div>
        <button
          type="button"
          @click="$emit('close')"
          class="p-1.5 rounded-lg hover:bg-white transition-colors text-gray-500 hover:text-gray-700"
        >
          <X class="w-4 h-4" />
        </button>
      </div>

      <!-- Content -->
      <div v-if="session" class="flex-1 overflow-y-auto p-4 space-y-4">
        <!-- User Information -->
        <div class="bg-gradient-to-br from-slate-50 to-gray-50 rounded-lg p-4 border border-slate-200">
          <div class="flex items-center gap-2 mb-3">
            <Users class="w-4 h-4 text-slate-600" />
            <h4 class="text-sm font-semibold text-slate-800">User Information</h4>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <div class="text-xs text-slate-500 mb-0.5">{{ session.type === 'pppoe' ? 'Username' : 'Name' }}</div>
              <div class="text-sm font-medium text-slate-900">{{ session.user?.name || session.username }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500 mb-0.5">Phone</div>
              <div class="text-sm font-medium text-slate-900">{{ session.user?.phone || session.phone || 'N/A' }}</div>
            </div>
            <div v-if="session.package">
              <div class="text-xs text-slate-500 mb-0.5">Package</div>
              <div class="text-sm font-medium text-slate-900">{{ session.package.name }}</div>
            </div>
            <div v-if="session.package?.speed || session.profile?.speed">
              <div class="text-xs text-slate-500 mb-0.5">Speed</div>
              <div class="text-sm font-medium text-slate-900">{{ session.package?.speed || session.profile?.speed }}</div>
            </div>
          </div>
        </div>

        <!-- Connection Details -->
        <div class="bg-gradient-to-br from-slate-50 to-gray-50 rounded-lg p-4 border border-slate-200">
          <div class="flex items-center gap-2 mb-3">
            <Network class="w-4 h-4 text-slate-600" />
            <h4 class="text-sm font-semibold text-slate-800">Connection Details</h4>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <div class="text-xs text-slate-500 mb-0.5">IP Address</div>
              <div class="text-sm font-medium text-slate-900 font-mono">{{ session.ip_address || session.framed_ip }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500 mb-0.5">{{ session.type === 'pppoe' ? 'Calling Station' : 'MAC Address' }}</div>
              <div class="text-sm font-medium text-slate-900 font-mono">{{ session.mac_address || session.calling_station_id }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500 mb-0.5">Session ID</div>
              <div class="text-sm font-medium text-slate-900 font-mono text-xs">{{ session.session_id || session.acct_session_id }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500 mb-0.5">NAS IP</div>
              <div class="text-sm font-medium text-slate-900">{{ session.nas_ip || session.nas_ip_address || 'N/A' }}</div>
            </div>
          </div>
        </div>

        <!-- Session Statistics -->
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center gap-2 mb-3">
            <Activity class="w-4 h-4 text-blue-600" />
            <h4 class="text-sm font-semibold text-blue-800">Session Statistics</h4>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <div class="text-xs text-blue-600 mb-0.5">Duration</div>
              <div class="text-sm font-semibold text-blue-900">{{ formatDuration(session.duration || session.session_duration) }}</div>
            </div>
            <div>
              <div class="text-xs text-blue-600 mb-0.5">Started</div>
              <div class="text-sm font-semibold text-blue-900">{{ formatDateTime(session.start_time || session.login_time) }}</div>
            </div>
            <div>
              <div class="text-xs text-green-600 mb-0.5">Download</div>
              <div class="text-sm font-semibold text-green-700">{{ formatBytes(session.bytes_in || session.input_octets) }}</div>
            </div>
            <div>
              <div class="text-xs text-blue-600 mb-0.5">Upload</div>
              <div class="text-sm font-semibold text-blue-700">{{ formatBytes(session.bytes_out || session.output_octets) }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-600 mb-0.5">Total Data</div>
              <div class="text-sm font-semibold text-slate-900">{{ formatBytes((session.bytes_in || session.input_octets || 0) + (session.bytes_out || session.output_octets || 0)) }}</div>
            </div>
            <div v-if="session.current_bandwidth || session.download_speed">
              <div class="text-xs text-slate-600 mb-0.5">Current Speed</div>
              <div class="text-sm font-semibold text-slate-900">{{ formatBytes(session.current_bandwidth || session.download_speed) }}/s</div>
            </div>
          </div>
        </div>

        <!-- PPPoE Specific: Speed Breakdown -->
        <div v-if="session.type === 'pppoe' && (session.download_speed || session.upload_speed)" class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-4 border border-purple-200">
          <div class="flex items-center gap-2 mb-3">
            <Gauge class="w-4 h-4 text-purple-600" />
            <h4 class="text-sm font-semibold text-purple-800">Current Speeds</h4>
          </div>
          <div class="space-y-3">
            <div>
              <div class="flex items-center justify-between mb-1">
                <span class="text-xs text-green-600 font-medium">Download</span>
                <span class="text-xs font-semibold text-green-700">{{ formatBytes(session.download_speed) }}/s</span>
              </div>
              <div class="w-full bg-slate-200 rounded-full h-2">
                <div 
                  class="bg-gradient-to-r from-green-500 to-emerald-500 h-2 rounded-full transition-all duration-300"
                  :style="{ width: getSpeedPercentage(session.download_speed, session.profile?.max_download) + '%' }"
                ></div>
              </div>
            </div>
            <div>
              <div class="flex items-center justify-between mb-1">
                <span class="text-xs text-blue-600 font-medium">Upload</span>
                <span class="text-xs font-semibold text-blue-700">{{ formatBytes(session.upload_speed) }}/s</span>
              </div>
              <div class="w-full bg-slate-200 rounded-full h-2">
                <div 
                  class="bg-gradient-to-r from-blue-500 to-cyan-500 h-2 rounded-full transition-all duration-300"
                  :style="{ width: getSpeedPercentage(session.upload_speed, session.profile?.max_upload) + '%' }"
                ></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Hotspot Specific: Bandwidth -->
        <div v-if="session.type === 'hotspot' && session.current_bandwidth" class="bg-gradient-to-br from-cyan-50 to-blue-50 rounded-lg p-4 border border-cyan-200">
          <div class="flex items-center gap-2 mb-3">
            <Gauge class="w-4 h-4 text-cyan-600" />
            <h4 class="text-sm font-semibold text-cyan-800">Current Bandwidth</h4>
          </div>
          <div>
            <div class="flex items-center justify-between mb-1">
              <span class="text-xs text-cyan-600 font-medium">Bandwidth Usage</span>
              <span class="text-xs font-semibold text-cyan-700">{{ formatBytes(session.current_bandwidth) }}/s</span>
            </div>
            <div class="w-full bg-slate-200 rounded-full h-2">
              <div 
                class="bg-gradient-to-r from-blue-500 to-cyan-500 h-2 rounded-full transition-all duration-300"
                :style="{ width: getBandwidthPercentage(session.current_bandwidth) + '%' }"
              ></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer Actions -->
      <div class="flex-shrink-0 px-4 py-3 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-2">
        <button
          @click="$emit('close')"
          class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors"
        >
          Close
        </button>
        <button
          @click="$emit('disconnect', session)"
          class="px-4 py-2 text-sm font-medium text-white bg-gradient-to-r from-red-500 to-rose-600 rounded-lg hover:from-red-600 hover:to-rose-700 transition-all shadow-sm flex items-center gap-1.5"
        >
          <Power class="w-4 h-4" />
          Disconnect
        </button>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { X, Users, Network, Activity, Power, Gauge } from 'lucide-vue-next'

const props = defineProps({
  show: Boolean,
  session: Object,
  icon: {
    type: Object,
    default: () => Activity
  }
})

defineEmits(['close', 'disconnect'])

const formatBytes = (bytes) => {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

const formatDuration = (seconds) => {
  if (!seconds) return '0s'
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const secs = seconds % 60
  
  if (hours > 0) return `${hours}h ${minutes}m ${secs}s`
  if (minutes > 0) return `${minutes}m ${secs}s`
  return `${secs}s`
}

const formatDateTime = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleString()
}

const getSpeedPercentage = (current, max) => {
  if (!max) return 0
  return Math.min((current / max) * 100, 100)
}

const getBandwidthPercentage = (current) => {
  const maxBandwidth = 10485760 // 10 MB/s
  return Math.min((current / maxBandwidth) * 100, 100)
}
</script>
