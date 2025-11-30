<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl mx-4 max-h-[90vh] overflow-hidden">
      <!-- Header -->
      <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-600 to-blue-700 text-white">
        <div class="flex items-center justify-between">
          <div>
            <h2 class="text-xl font-bold">Router Provisioning</h2>
            <p class="text-blue-100 text-sm">
              {{ router ? `Provisioning: ${router.name}` : 'Add New Router' }}
            </p>
          </div>
          <button @click="$emit('close')"
            class="p-2 hover:bg-white hover:bg-opacity-20 rounded-full transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>

      <!-- Content -->
      <div class="flex flex-col max-h-[calc(90vh-120px)]">
        <!-- Stage Content -->
        <div class="flex-1 overflow-y-auto p-6">
          <!-- Stage 1: Router Identity & Initial Config -->
          <div v-if="currentStage === 1" class="space-y-6">
            <div class="text-center">
              <div class="mx-auto w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mb-4">
                <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                </svg>
              </div>
              <h3 class="text-lg font-semibold text-gray-900 mb-2">Create Router Identity</h3>
              <p class="text-gray-600">Enter a name for your router and we'll generate the initial configuration.</p>
            </div>

            <form @submit.prevent="handleStage1Submit" class="max-w-md mx-auto space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Router Name</label>
                <input v-model="stage1Data.name"
                       type="text"
                       required
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
              </div>

              <button type="submit"
                      class="w-full bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors">
                Generate Configuration
              </button>
            </form>
          </div>

          <!-- Stage 2: Router Probing -->
          <div v-else-if="currentStage === 2" class="space-y-6">
            <div class="text-center">
              <div class="mx-auto w-16 h-16 bg-yellow-100 rounded-full flex items-center justify-center mb-4">
                <svg class="w-8 h-8 text-yellow-600 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
              </div>
              <h3 class="text-lg font-semibold text-gray-900 mb-2">Waiting for Router Connection</h3>
              <p class="text-gray-600">Monitoring for router connectivity...</p>
            </div>
          </div>

          <!-- Stage 3: Interface Selection -->
          <div v-else-if="currentStage === 3" class="space-y-6">
            <div class="text-center">
              <div class="mx-auto w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mb-4">
                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <h3 class="text-lg font-semibold text-gray-900 mb-2">Router Connected Successfully!</h3>
              <p class="text-gray-600">Router is connected. Configuring services...</p>
            </div>
          </div>

          <!-- Stage 4: Service Configuration -->
          <div v-else-if="currentStage === 4" class="space-y-6">
            <div class="text-center">
              <div class="mx-auto w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mb-4">
                <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
              </div>
              <h3 class="text-lg font-semibold text-gray-900 mb-2">Service Configuration</h3>
              <p class="text-gray-600">Configuring router services...</p>
            </div>
          </div>

          <!-- Stage 5: Deployment Complete -->
          <div v-else-if="currentStage === 5" class="space-y-6">
            <div class="text-center">
              <div class="mx-auto w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mb-4">
                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <h3 class="text-lg font-semibold text-gray-900 mb-2">Provisioning Complete!</h3>
              <p class="text-gray-600">Router has been successfully provisioned.</p>
            </div>

            <button @click="$emit('close')"
                    class="w-full bg-green-600 text-white py-2 px-4 rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 transition-colors">
              Close & Return to Dashboard
            </button>
          </div>
        </div>

        <!-- Logs Panel -->
        <div class="border-t border-gray-200 bg-gray-50 px-6 py-4">
          <div class="flex items-center justify-between mb-3">
            <h4 class="font-medium text-gray-900">Activity Log</h4>
            <span class="text-xs text-gray-500">{{ logs.length }} entries</span>
          </div>

          <div class="max-h-32 overflow-y-auto space-y-1">
            <div v-for="log in logs.slice(-10)" :key="log.timestamp" class="flex items-start text-xs">
              <span class="text-gray-400 w-16 flex-shrink-0">
                {{ new Date(log.timestamp).toLocaleTimeString() }}
              </span>
              <span :class="getLogLevelClass(log.level)" class="ml-2 flex-shrink-0 w-12 uppercase font-medium">
                {{ log.level }}
              </span>
              <span class="ml-2 text-gray-700">{{ log.message }}</span>
            </div>

            <div v-if="logs.length === 0" class="text-xs text-gray-500 italic">
              No activity yet...
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'

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
    'info': 'text-blue-600',
    'success': 'text-green-600',
    'warning': 'text-yellow-600',
    'error': 'text-red-600'
  }
  return classes[level] || 'text-gray-600'
}
</script>

<style scoped>
/* Custom scrollbar for logs */
.max-h-32::-webkit-scrollbar {
  width: 4px;
}

.max-h-32::-webkit-scrollbar-track {
  background: #f1f1f1;
}

.max-h-32::-webkit-scrollbar-thumb {
  background: #c1c1c1;
  border-radius: 2px;
}

.max-h-32::-webkit-scrollbar-thumb:hover {
  background: #a8a8a8;
}
</style>
