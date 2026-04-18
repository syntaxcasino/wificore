<template>
  <div class="fixed inset-0 z-[9999] flex items-end sm:items-center justify-center p-0 sm:p-4">
    <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-[2px]" @click="$emit('close')" />
    <div class="relative bg-white w-full sm:max-w-2xl sm:rounded-2xl rounded-t-2xl shadow-2xl ring-1 ring-black/10 flex flex-col overflow-hidden max-h-[92vh]">
      <!-- Header -->
      <div class="bg-gradient-to-r from-blue-600 via-blue-700 to-indigo-700 px-5 py-3.5 flex items-center justify-between flex-shrink-0">
        <div>
          <h2 class="text-sm font-bold text-white">Router Provisioning</h2>
          <p class="text-blue-200 text-xs mt-0.5">{{ router ? router.name : 'New Router' }}</p>
        </div>
        <button @click="$emit('close')" class="p-1.5 text-white/70 hover:text-white hover:bg-white/15 rounded-lg transition-colors">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <!-- Progress bar -->
      <div class="h-1 bg-blue-100 flex-shrink-0">
        <div class="h-full bg-gradient-to-r from-blue-500 to-indigo-500 transition-all duration-700" :style="{ width: progressPct + '%' }" />
      </div>
      <!-- Stepper -->
      <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex-shrink-0">
        <ol class="flex items-center">
          <li v-for="(step, i) in steps" :key="i" class="flex items-center" :class="i < steps.length - 1 ? 'flex-1' : ''">
            <div class="flex flex-col items-center">
              <div class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold ring-2 transition-all" :class="stepBubbleClass(i)">
                <svg v-if="stepStatus(i)==='done'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                <span v-else-if="stepStatus(i)==='active'" class="w-2 h-2 rounded-full bg-white animate-pulse"/>
                <span v-else>{{ i+1 }}</span>
              </div>
              <span class="text-[10px] font-medium mt-0.5 whitespace-nowrap" :class="stepStatus(i)==='active' ? 'text-blue-600' : stepStatus(i)==='done' ? 'text-green-600' : 'text-gray-400'">{{ step }}</span>
            </div>
            <div v-if="i < steps.length-1" class="flex-1 h-px mx-1 transition-colors" :class="stepStatus(i)==='done' ? 'bg-green-400' : 'bg-gray-200'" />
          </li>
        </ol>
      </div>

      <!-- Stage content -->
      <div class="flex-1 overflow-y-auto px-5 py-4">
          <!-- Stage 1 -->
          <div v-if="currentStage === 1" class="space-y-4">
            <div class="flex items-center gap-3 p-4 bg-blue-50 rounded-xl border border-blue-100">
              <div class="w-9 h-9 bg-blue-600 rounded-lg flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
              </div>
              <div>
                <p class="text-sm font-semibold text-gray-800">Create Router Identity</p>
                <p class="text-xs text-gray-500">Name your router and generate the initial config</p>
              </div>
            </div>
            <form @submit.prevent="handleStage1Submit" class="space-y-3">
              <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Router Name</label>
                <input v-model="stage1Data.name" type="text" required placeholder="e.g. GGN-HSP-01"
                  class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <button type="submit" class="w-full py-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors">Generate Configuration</button>
            </form>
          </div>

          <!-- Stage 2 -->
          <div v-else-if="currentStage === 2" class="flex flex-col items-center text-center gap-4 py-10">
            <div class="relative w-14 h-14 bg-yellow-100 rounded-full flex items-center justify-center">
              <svg class="w-7 h-7 text-yellow-500 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
              <span class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-yellow-400 rounded-full border-2 border-white animate-ping"/>
            </div>
            <div>
              <p class="text-sm font-semibold text-gray-800">Waiting for Router Connection</p>
              <p class="text-xs text-gray-500 mt-1">Apply the config script, then the system will detect your router automatically</p>
            </div>
          </div>

          <!-- Stage 3 -->
          <div v-else-if="currentStage === 3" class="flex flex-col items-center text-center gap-4 py-10">
            <div class="w-14 h-14 bg-green-100 rounded-full flex items-center justify-center ring-4 ring-green-50">
              <svg class="w-7 h-7 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
              <p class="text-sm font-semibold text-gray-800">Router Connected!</p>
              <p class="text-xs text-gray-500 mt-1">Configuring services...</p>
            </div>
          </div>

          <!-- Stage 4 -->
          <div v-else-if="currentStage === 4" class="flex flex-col items-center text-center gap-4 py-10">
            <div class="w-14 h-14 bg-purple-100 rounded-full flex items-center justify-center">
              <svg class="w-7 h-7 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </div>
            <div>
              <p class="text-sm font-semibold text-gray-800">Deploying Configuration</p>
              <p class="text-xs text-gray-500 mt-1">Applying service settings to the router...</p>
            </div>
          </div>

          <!-- Stage 5 -->
          <div v-else-if="currentStage === 5" class="flex flex-col items-center text-center gap-4 py-10">
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center ring-4 ring-green-200">
              <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
              <p class="text-base font-bold text-gray-800">Provisioning Complete!</p>
              <p class="text-xs text-gray-500 mt-1">Router has been successfully provisioned.</p>
            </div>
            <button @click="$emit('close')" class="px-6 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 rounded-xl shadow-md transition-all">
              Close &amp; Return to Dashboard
            </button>
          </div>
      </div>

      <!-- Activity log panel -->
      <div class="border-t border-gray-100 bg-gray-50 px-5 py-3 flex-shrink-0">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-semibold text-gray-600">Activity Log</span>
          <span class="text-[10px] text-gray-400 bg-gray-200 rounded-full px-2 py-0.5">{{ logs.length }}</span>
        </div>
        <div ref="logScrollRef" class="max-h-28 overflow-y-auto space-y-0.5 font-mono">
          <div v-for="log in logs.slice(-20)" :key="log.timestamp" class="flex items-start gap-1.5 text-[11px] py-0.5">
            <span class="text-gray-400 flex-shrink-0 w-14">{{ new Date(log.timestamp).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit',second:'2-digit'}) }}</span>
            <span :class="getLogLevelClass(log.level)" class="flex-shrink-0 w-12 uppercase font-semibold">{{ log.level }}</span>
            <span class="text-gray-600 leading-tight">{{ log.message }}</span>
          </div>
          <div v-if="logs.length === 0" class="text-[11px] text-gray-400 italic py-1">No activity yet...</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, nextTick } from 'vue'

// Props
const props = defineProps({
  router: {
    type: Object,
    default: null
  },
  currentStage: {
    type: Number,
    default: 1
  },
  stageData: {
    type: Object,
    default: () => ({
      config: null,
      interfaces: [],
      routerInfo: null,
      serviceConfig: null
    })
  },
  progress: {
    type: Number,
    default: 0
  },
  logs: {
    type: Array,
    default: () => []
  }
})

// Emits
const emit = defineEmits([
  'close',
  'next-stage',
  'copy-config',
  'start-probing',
  'generate-service-config',
  'deploy-service-config'
])

// Reactive data
const stage1Data = reactive({
  name: ''
})

const stage3Data = reactive({
  serviceType: '',
  selectedInterface: '' // Changed from selectedInterfaces array to string
})

// Stepper
const steps = ['Identity', 'Connecting', 'Connected', 'Configuring', 'Done']

const progressPct = computed(() => {
  const pct = props.progress ?? 0
  if (pct > 0) return pct
  return Math.min(((props.currentStage - 1) / (steps.length - 1)) * 100, 100)
})

const stepStatus = (i) => {
  const active = props.currentStage - 1
  if (i < active) return 'done'
  if (i === active) return 'active'
  return 'pending'
}

const stepBubbleClass = (i) => {
  const s = stepStatus(i)
  if (s === 'done') return 'bg-green-500 ring-green-300 text-white'
  if (s === 'active') return 'bg-blue-600 ring-blue-300 text-white'
  return 'bg-white ring-gray-300 text-gray-400'
}

// Auto-scroll log
const logScrollRef = ref(null)
watch(() => props.logs?.length, async () => {
  await nextTick()
  if (logScrollRef.value) {
    logScrollRef.value.scrollTop = logScrollRef.value.scrollHeight
  }
})

// Methods
const handleStage1Submit = () => {
  if (!stage1Data.name.trim()) return

  emit('next-stage', 1, {
    name: stage1Data.name.trim()
  })
}

const handleStage3Submit = () => {
  if (!stage3Data.serviceType || !stage3Data.selectedInterface) return

  emit('next-stage', 3, {
    service_type: stage3Data.serviceType,
    selectedInterfaces: [stage3Data.selectedInterface] // Convert to array for backend
  })
}

const handleStage4Submit = () => {
  if (!props.stageData.serviceConfig?.commands) return

  emit('deploy-service-config', {
    service_type: stage3Data.serviceType,
    commands: props.stageData.serviceConfig.commands
  })
}

const getLogLevelClass = (level) => {
  const classes = {
    'info': 'text-blue-400',
    'success': 'text-emerald-400',
    'warning': 'text-amber-400',
    'error': 'text-red-400'
  }
  return classes[level] || 'text-slate-400'
}
</script>

<style scoped>
/* Scrollbar — no Tailwind equivalent for ::-webkit-scrollbar pseudo-elements */
.font-mono::-webkit-scrollbar         { width: 4px; }
.font-mono::-webkit-scrollbar-track   { background: transparent; }
.font-mono::-webkit-scrollbar-thumb   { background: rgba(100,116,139,0.5); border-radius: 4px; }
.font-mono::-webkit-scrollbar-thumb:hover { background: rgba(100,116,139,0.8); }

.overflow-y-auto::-webkit-scrollbar        { width: 5px; }
.overflow-y-auto::-webkit-scrollbar-track  { background: transparent; }
.overflow-y-auto::-webkit-scrollbar-thumb  { background: #e2e8f0; border-radius: 4px; }
.overflow-y-auto::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
:global(.dark) .overflow-y-auto::-webkit-scrollbar-thumb { background: #475569; }
</style>
