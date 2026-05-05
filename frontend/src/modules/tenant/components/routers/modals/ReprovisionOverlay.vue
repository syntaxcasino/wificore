<template>
  <SlideOverlay
    :model-value="visible"
    title="Reprovisioning Router"
    :subtitle="router?.name || 'Router'"
    icon="RefreshCw"
    :gradient="true"
    :badge="stageBadge"
    width="50%"
    :close-on-backdrop="false"
    :close-on-escape="false"
    no-padding
    @update:modelValue="val => { if (!val) handleClose() }"
    @close="handleClose"
  >
    <template #status-bar>
      <!-- Progress Bar -->
      <div class="px-4 py-2 bg-white border-b border-gray-100">
        <div class="flex items-center justify-between mb-1">
          <div class="flex items-center gap-1.5">
            <div
              class="w-1.5 h-1.5 rounded-full animate-pulse"
              :class="isDone ? 'bg-green-500' : isFailed ? 'bg-red-500' : 'bg-blue-500'"
            ></div>
            <span class="text-[11px] font-semibold text-gray-600">{{ statusLabel }}</span>
          </div>
          <span
            class="text-xs font-bold"
            :class="isDone ? 'text-green-600' : isFailed ? 'text-red-600' : 'text-blue-600'"
          >{{ progress }}%</span>
        </div>
        <div class="relative w-full bg-gray-100 rounded-full h-1.5 overflow-hidden">
          <div
            class="absolute inset-0 rounded-full transition-all duration-500 ease-out"
            :class="isDone
              ? 'bg-gradient-to-r from-green-400 to-emerald-500'
              : isFailed
                ? 'bg-gradient-to-r from-red-400 to-red-500'
                : 'bg-gradient-to-r from-blue-500 to-indigo-600'"
            :style="{ width: progress + '%' }"
          ></div>
        </div>
        <div class="mt-1 text-[10px] text-gray-500 text-right">{{ statusLabel }}</div>
      </div>

      <!-- Stage pills -->
      <div class="flex items-center px-4 py-2 gap-1 bg-gray-50 border-b border-gray-100 overflow-x-auto">
        <template v-for="(lbl, idx) in stageLabels" :key="idx">
          <div class="flex items-center gap-1 flex-shrink-0">
            <div
              class="w-5 h-5 rounded-full flex items-center justify-center text-[9px] font-bold ring-1.5 transition-all"
              :class="stageIdx > idx
                ? 'bg-green-500 ring-green-300 text-white'
                : stageIdx === idx
                  ? 'bg-blue-600 ring-blue-300 text-white'
                  : 'bg-white ring-gray-300 text-gray-400'"
            >
              <svg v-if="stageIdx > idx" class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
              </svg>
              <span v-else>{{ idx + 1 }}</span>
            </div>
            <span
              class="text-[10px] font-medium"
              :class="stageIdx > idx ? 'text-green-600' : stageIdx === idx ? 'text-blue-600' : 'text-gray-400'"
            >{{ lbl }}</span>
          </div>
          <div
            v-if="idx < stageLabels.length - 1"
            class="flex-1 h-px min-w-[8px] mx-0.5 transition-colors"
            :class="stageIdx > idx ? 'bg-green-300' : 'bg-gray-200'"
          />
        </template>
      </div>
    </template>

    <!-- Main Content -->
    <div class="p-5 bg-gray-50">
      <!-- In Progress -->
      <div v-if="inProgress" class="space-y-4">
        <div class="text-center">
          <div class="w-16 h-16 mx-auto mb-3 rounded-full flex items-center justify-center bg-blue-100">
            <svg class="w-8 h-8 text-blue-600 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
            </svg>
          </div>
          <h4 class="text-base font-bold text-gray-800 mb-1">{{ stageTitle }}</h4>
          <p class="text-sm text-gray-500 max-w-sm mx-auto">{{ stageDescription }}</p>
        </div>

        <!-- Router Info Card -->
        <div class="bg-white rounded-lg border border-gray-200 p-4">
          <div class="grid grid-cols-2 gap-3 text-sm">
            <div>
              <span class="text-gray-500 text-xs uppercase tracking-wide">Router</span>
              <p class="font-medium text-gray-900 mt-0.5">{{ router?.name || 'N/A' }}</p>
            </div>
            <div>
              <span class="text-gray-500 text-xs uppercase tracking-wide">IP Address</span>
              <p class="font-medium text-gray-900 mt-0.5">{{ router?.ip_address || 'Detecting...' }}</p>
            </div>
            <div>
              <span class="text-gray-500 text-xs uppercase tracking-wide">Current Status</span>
              <p class="font-medium mt-0.5" :class="statusColor">{{ currentStatus }}</p>
            </div>
            <div>
              <span class="text-gray-500 text-xs uppercase tracking-wide">Stage</span>
              <p class="font-medium text-gray-900 mt-0.5">{{ stageLabels[stageIdx] || 'Initializing' }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Done -->
      <div v-else-if="isDone" class="text-center py-8">
        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4 ring-4 ring-green-50">
          <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
        </div>
        <h4 class="text-lg font-bold text-gray-800 mb-1">Reprovisioning Complete</h4>
        <p class="text-sm text-gray-500">Router "{{ router?.name }}" has been successfully reprovisioned.</p>
      </div>

      <!-- Failed -->
      <div v-else-if="isFailed" class="text-center py-8">
        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4 ring-4 ring-red-50">
          <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
        </div>
        <h4 class="text-lg font-bold text-gray-800 mb-1">Reprovisioning Failed</h4>
        <p class="text-sm text-gray-500 mb-4">{{ errorMessage || 'An error occurred during reprovisioning.' }}</p>
        <button
          @click="retryReprovision"
          class="px-5 py-2 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors"
        >
          Try Again
        </button>
      </div>
    </div>

    <!-- Activity Logs -->
    <div class="border-t border-gray-100 bg-gray-50">
      <div class="px-4 pt-3 pb-3">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-semibold text-gray-600">Activity Logs</span>
          <span class="text-[10px] text-gray-400 bg-gray-200 rounded-full px-2 py-0.5">{{ logs.length }}</span>
        </div>
        <div class="max-h-36 overflow-y-auto space-y-0.5 bg-slate-900 p-2.5 rounded-lg font-mono">
          <div
            v-for="(log, index) in reversedLogs"
            :key="index"
            class="flex items-start gap-2 text-[11px]"
          >
            <span class="text-slate-500 flex-shrink-0 w-14">{{ formatLogTime(log.timestamp) }}</span>
            <span :class="getLogLevelClass(log.level)" class="font-bold flex-shrink-0 w-12">{{ log.level.toUpperCase() }}</span>
            <span class="text-slate-300 flex-1 leading-tight">{{ log.message }}</span>
          </div>
          <div v-if="logs.length === 0" class="text-[11px] text-slate-500 italic text-center py-2">Waiting for activity...</div>
        </div>
      </div>
    </div>

    <template #footer>
      <div class="flex gap-3">
        <button
          v-if="isDone"
          @click="handleClose"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded-lg transition-colors"
        >
          Done — View Dashboard
        </button>
        <template v-else-if="isFailed">
          <button
            @click="handleClose"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
          >
            Close
          </button>
          <button
            @click="retryReprovision"
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors"
          >
            Try Again
          </button>
        </template>
        <button
          v-else
          disabled
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-400 bg-slate-100 border border-slate-200 rounded-lg cursor-not-allowed"
        >
          Reprovisioning in progress...
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { ref, computed, watch, onUnmounted } from 'vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  router:  { type: Object, default: null },
})

const emit = defineEmits(['close', 'retry'])

// ─── Stage labels matching provisioning flow ───────────────────────────────
const stageLabels = ['Reset', 'Probing', 'Configuring', 'Complete']

// ─── Reactive state ────────────────────────────────────────────────────────
const progress      = ref(0)
const currentStatus = ref('pending')
const stageIdx      = ref(0)
const errorMessage  = ref('')
const logs          = ref([])

// ─── Computed ──────────────────────────────────────────────────────────────
const isDone = computed(() =>
  progress.value >= 100 ||
  currentStatus.value === 'online' ||
  currentStatus.value === 'provisioned'
)

const isFailed = computed(() =>
  currentStatus.value === 'error' ||
  currentStatus.value === 'failed'
)

const inProgress = computed(() => !isDone.value && !isFailed.value)

const stageBadge = computed(() => {
  if (isDone.value)   return 'Complete'
  if (isFailed.value) return 'Failed'
  return `Step ${stageIdx.value + 1}`
})

const statusLabel = computed(() => {
  if (isDone.value)   return 'Reprovisioning Complete'
  if (isFailed.value) return 'Reprovisioning Failed'
  const labels = ['Resetting...', 'Probing router...', 'Configuring...', 'Finalizing...']
  return labels[stageIdx.value] || 'Initializing...'
})

const stageTitle = computed(() => {
  const titles = [
    'Resetting provisioning state...',
    'Probing router connectivity...',
    'Applying configuration...',
    'Finalizing...',
  ]
  return titles[stageIdx.value] || 'Processing...'
})

const stageDescription = computed(() => {
  const descs = [
    'Clearing the previous configuration and preparing for reprovisioning.',
    'The system is checking for VPN connectivity and discovering router interfaces.',
    'Deploying service configuration to the router.',
    'Verifying the final state of the router.',
  ]
  return descs[stageIdx.value] || ''
})

const statusColor = computed(() => {
  const map = {
    pending:      'text-yellow-600',
    probing:      'text-blue-600',
    configuring:  'text-indigo-600',
    online:       'text-green-600',
    provisioned:  'text-green-600',
    error:        'text-red-600',
    failed:       'text-red-600',
  }
  return map[currentStatus.value] || 'text-gray-600'
})

const reversedLogs = computed(() => [...logs.value].reverse())

// ─── Helpers ───────────────────────────────────────────────────────────────
const addLog = (level, message) => {
  logs.value.push({ level, message, timestamp: new Date().toISOString() })
}

const formatLogTime = (ts) => {
  try {
    return new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
  } catch { return '' }
}

const getLogLevelClass = (level) => {
  const classes = {
    info:    'text-blue-400',
    success: 'text-emerald-400',
    warning: 'text-amber-400',
    error:   'text-red-400',
  }
  return classes[level] || 'text-slate-400'
}

// Map incoming WebSocket event to local state
const updateFromEvent = (event) => {
  if (event.status) {
    currentStatus.value = event.status

    const stageMap = { pending: 0, probing: 1, configuring: 2, online: 3, provisioned: 3 }
    const idx = stageMap[event.status]
    if (idx !== undefined) stageIdx.value = idx

    const progressMap = { pending: 10, probing: 35, configuring: 65, online: 100, provisioned: 100 }
    const pct = progressMap[event.status]
    if (pct !== undefined) progress.value = pct
  }

  if (event.progress !== undefined) progress.value = event.progress

  if (event.message) {
    const level = (event.status === 'error' || event.status === 'failed') ? 'error' : 'info'
    addLog(level, event.message)
  }
}

// ─── Echo subscriptions ────────────────────────────────────────────────────
const subscribeToEvents = (routerId) => {
  if (!routerId || !window.Echo) return

  const channelName = `router-provisioning.${routerId}`
  window.Echo.private(channelName)
    .listen('.RouterProvisioningProgress', (event) => {
      updateFromEvent(event)
    })
    .listen('.RouterProvisioned', () => {
      progress.value      = 100
      currentStatus.value = 'provisioned'
      stageIdx.value      = 3
      addLog('success', 'Router reprovisioned successfully')
    })
    .listen('.RouterProvisioningFailed', (event) => {
      currentStatus.value = 'error'
      errorMessage.value  = event.message || 'Provisioning failed'
      addLog('error', errorMessage.value)
    })
}

const unsubscribeFromEvents = (routerId) => {
  if (routerId && window.Echo) {
    window.Echo.leave(`router-provisioning.${routerId}`)
  }
}

// ─── State management ──────────────────────────────────────────────────────
const resetState = () => {
  progress.value      = 0
  currentStatus.value = 'pending'
  stageIdx.value      = 0
  errorMessage.value  = ''
  logs.value          = []
}

const handleClose = () => {
  if (props.router?.id) unsubscribeFromEvents(props.router.id)
  emit('close')
}

const retryReprovision = () => {
  resetState()
  emit('retry', props.router)
}

// ─── Watchers ──────────────────────────────────────────────────────────────
watch(() => props.visible, (val) => {
  if (val && props.router?.id) {
    resetState()
    progress.value = 10
    addLog('info', `Starting reprovisioning for "${props.router.name}"...`)
    subscribeToEvents(props.router.id)
  } else if (!val && props.router?.id) {
    unsubscribeFromEvents(props.router.id)
  }
})

onUnmounted(() => {
  if (props.router?.id) unsubscribeFromEvents(props.router.id)
})
</script>

<style scoped>
.font-mono::-webkit-scrollbar        { width: 4px; }
.font-mono::-webkit-scrollbar-track  { background: transparent; }
.font-mono::-webkit-scrollbar-thumb  { background: rgba(100,116,139,0.5); border-radius: 4px; }
</style>
