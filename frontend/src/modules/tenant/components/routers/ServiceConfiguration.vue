<template>
  <div class="p-6">
    <div class="mb-8">
      <h3 class="text-2xl font-semibold text-slate-900 dark:text-slate-100 mb-1">Service Configuration</h3>
      <p class="text-sm text-slate-500 dark:text-slate-400">Configure services on router interfaces (Zero-Config)</p>
    </div>

    <div v-if="loading" class="text-center py-12">
      <div class="w-10 h-10 border-[3px] border-slate-200 dark:border-slate-600 border-t-blue-500 rounded-full animate-spin mx-auto mb-4"></div>
      <p class="text-slate-500 dark:text-slate-400">Loading interfaces...</p>
    </div>

    <div v-else-if="error" class="text-center py-12">
      <p class="text-red-600 dark:text-red-400 mb-3">{{ error }}</p>
      <button @click="loadInterfaces" class="px-4 py-2 bg-slate-600 hover:bg-slate-700 text-white rounded-md text-sm transition-colors">Retry</button>
    </div>

    <div v-else class="grid gap-6">
      <div class="border border-blue-200 dark:border-blue-800/50 rounded-xl bg-blue-50 dark:bg-blue-950/30 p-4">
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-3">
            <div class="w-[34px] h-[34px] rounded-xl bg-white dark:bg-slate-800 border border-blue-200 dark:border-blue-700 flex items-center justify-center text-blue-600 dark:text-blue-400">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 15.5A3.5 3.5 0 1 0 12 8.5a3.5 3.5 0 0 0 0 7Z" />
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.65 1.65 0 0 0 15 19.4a1.65 1.65 0 0 0-1 .6 1.65 1.65 0 0 0-.33 1.82V22a2 2 0 1 1-4 0v-.08a1.65 1.65 0 0 0-.33-1.82 1.65 1.65 0 0 0-1-.6 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-.6-1 1.65 1.65 0 0 0-1.82-.33H2a2 2 0 1 1 0-4h.08a1.65 1.65 0 0 0 1.82-.33 1.65 1.65 0 0 0 .6-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06A2 2 0 1 1 6.94 3.6l.06.06A1.65 1.65 0 0 0 9 4.6c.3 0 .6-.1 1-.3.4-.2.7-.5 1-.9V2a2 2 0 1 1 4 0v.08c.1.4.4.7.7 1 .3.3.7.5 1.1.5.4 0 .8-.1 1.1-.3l.06-.06A2 2 0 1 1 21.2 6.5l-.06.06c-.2.3-.3.7-.3 1.1 0 .4.2.8.5 1.1.3.3.6.6 1 .7H22a2 2 0 1 1 0 4h-.08c-.4.1-.7.4-1 .7-.3.3-.5.7-.5 1.1Z" />
              </svg>
            </div>
            <div>
              <h4 class="text-lg font-bold text-slate-900 dark:text-slate-100">Service Mapping</h4>
              <p class="text-sm text-slate-600 dark:text-slate-400 mt-0.5">Select exactly one service per interface. Advanced options are applied automatically.</p>
            </div>
          </div>
        </div>

        <div class="bg-white dark:bg-slate-800 border border-blue-200 dark:border-blue-800/50 rounded-xl overflow-hidden">
          <div
            v-for="iface in interfaces"
            :key="iface.name"
            class="px-4 py-3 border-b border-blue-100 dark:border-blue-900/40 last:border-b-0"
          >
            <div class="flex items-center justify-between gap-4">
              <div class="min-w-0">
                <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ iface.name }}</div>
                <div class="text-xs text-slate-500 dark:text-slate-400">{{ iface.type }}</div>
              </div>

              <div class="flex items-center gap-4 flex-wrap">
              <label v-for="svc in ['hotspot', 'pppoe', 'hybrid']" :key="svc" class="inline-flex items-center gap-2 cursor-pointer select-none">
                <input type="checkbox" class="sr-only" :checked="(iface.selectedService || 'none') === svc" @change="(e) => setServiceMapping(iface, svc, e.target.checked)" />
                <span class="relative w-[38px] h-5 rounded-full transition-colors duration-150 shadow-inner" :class="(iface.selectedService || 'none') === svc ? (svc === 'pppoe' ? 'bg-indigo-600' : svc === 'hybrid' ? 'bg-emerald-600' : 'bg-blue-600') : 'bg-slate-200 dark:bg-slate-600'">
                  <span class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform duration-150" :class="(iface.selectedService || 'none') === svc ? 'translate-x-[18px]' : ''" />
                </span>
                <span class="text-xs font-semibold text-slate-600 dark:text-slate-300 capitalize">{{ svc }}</span>
              </label>
            </div>
            </div>

            <div v-if="iface.selectedService !== 'none'" class="mt-3 pt-3 border-t border-blue-100 dark:border-blue-900/40">
              <div class="flex gap-4 p-3 bg-green-50 dark:bg-green-950/30 border border-green-200 dark:border-green-800/50 rounded-lg mb-3">
                <span class="text-xl">✨</span>
                <div>
                  <strong class="block text-green-800 dark:text-green-300 text-sm">Zero-Config Enabled</strong>
                  <p class="text-sm text-green-700 dark:text-green-400">IP pools, VLANs, and RADIUS will be configured automatically</p>
                </div>
              </div>

              <button
                @click="toggleAdvanced(iface)"
                class="bg-transparent border-none text-blue-600 dark:text-blue-400 cursor-pointer py-2 font-medium text-sm hover:underline"
              >
                {{ iface.showAdvanced ? '▼' : '▶' }} Advanced Options
              </button>

              <div v-if="iface.showAdvanced" class="mt-3 p-4 bg-slate-50 dark:bg-slate-900/50 rounded-lg space-y-4">
                <div class="p-3 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/50 rounded text-sm">
                  <strong class="block text-amber-800 dark:text-amber-300">Advanced Options</strong>
                  <p class="text-amber-700 dark:text-amber-400">Incorrect configuration may disrupt service.</p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Service Name</label>
                  <input type="text" v-model="iface.advancedOptions.service_name" :placeholder="`${iface.selectedService} Service`" class="w-full px-2 py-2 border border-slate-300 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-sm text-slate-900 dark:text-slate-100" />
                </div>

                <div v-if="iface.selectedService !== 'hybrid'">
                  <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">IP Pool</label>
                  <select v-model="iface.advancedOptions.ip_pool_id" class="w-full px-2 py-2 border border-slate-300 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-sm text-slate-900 dark:text-slate-100">
                    <option :value="null">Auto (Recommended)</option>
                    <option v-for="pool in ipPools" :key="pool.id" :value="pool.id">{{ pool.network_cidr }} ({{ pool.service_type }})</option>
                  </select>
                </div>

                <div v-if="iface.selectedService === 'hybrid'">
                  <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Hotspot IP Pool</label>
                  <select v-model="iface.advancedOptions.hotspot_pool_id" class="w-full px-2 py-2 border border-slate-300 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-sm text-slate-900 dark:text-slate-100">
                    <option :value="null">Auto (Recommended)</option>
                    <option v-for="pool in hotspotPools" :key="pool.id" :value="pool.id">{{ pool.network_cidr }}</option>
                  </select>
                </div>

                <div v-if="iface.selectedService === 'hybrid'">
                  <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">PPPoE IP Pool</label>
                  <select v-model="iface.advancedOptions.pppoe_pool_id" class="w-full px-2 py-2 border border-slate-300 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-sm text-slate-900 dark:text-slate-100">
                    <option :value="null">Auto (Recommended)</option>
                    <option v-for="pool in pppoePools" :key="pool.id" :value="pool.id">{{ pool.network_cidr }}</option>
                  </select>
                </div>

                <div v-if="iface.selectedService === 'hybrid'">
                  <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Hotspot VLAN ID</label>
                  <input type="number" v-model.number="iface.advancedOptions.hotspot_vlan" placeholder="Auto (100-199)" min="1" max="4094" class="w-full px-2 py-2 border border-slate-300 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-sm text-slate-900 dark:text-slate-100" />
                </div>

                <div v-if="iface.selectedService === 'hybrid'">
                  <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">PPPoE VLAN ID</label>
                  <input type="number" v-model.number="iface.advancedOptions.pppoe_vlan" placeholder="Auto (200-299)" min="1" max="4094" class="w-full px-2 py-2 border border-slate-300 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-sm text-slate-900 dark:text-slate-100" />
                </div>

                <div>
                  <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">RADIUS Profile</label>
                  <input type="text" v-model="iface.advancedOptions.radius_profile" :placeholder="`${iface.selectedService}-${router.tenant_id}`" class="w-full px-2 py-2 border border-slate-300 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-sm text-slate-900 dark:text-slate-100" />
                </div>
              </div>

            <div class="flex gap-4 mt-4">
              <button
                @click="deploySelectedService(iface)"
                :disabled="iface.deploying"
                class="px-6 py-3 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-400 disabled:cursor-not-allowed text-white font-medium rounded text-sm transition-colors"
              >
                {{ iface.deploying ? 'Deploying...' : 'Deploy' }}
              </button>
            </div>
            </div>

            <div v-if="iface.currentService" class="mt-4 pt-4 border-t border-blue-100 dark:border-blue-900/40 flex justify-between items-center">
              <div class="flex items-center gap-3">
                <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Current Service:</span>
                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ iface.currentService.service_name }}</span>
                <span class="px-2 py-0.5 text-xs font-medium rounded"
                  :class="{
                    'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300': iface.currentService.deployment_status === 'pending',
                    'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300': iface.currentService.deployment_status === 'in_progress',
                    'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300': iface.currentService.deployment_status === 'deployed',
                    'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300': iface.currentService.deployment_status === 'failed',
                  }">
                  {{ iface.currentService.deployment_status }}
                </span>
              </div>
              <div class="flex gap-2">
                <button @click="removeService(iface)" class="px-3 py-1.5 bg-red-500 hover:bg-red-600 text-white text-xs font-medium rounded transition-colors">Remove</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useToast } from 'vue-toastification'
import { useConfirmStore } from '@/stores/confirm'
import api from '@/modules/common/services/api/axios'

const props = defineProps({
  router: {
    type: Object,
    required: true
  }
})

const toast = useToast()
const loading = ref(true)
const error = ref(null)
const interfaces = ref([])
const ipPools = ref([])
const ipPoolsLoaded = ref(false)

const hotspotPools = computed(() => ipPools.value.filter(p => p.service_type === 'hotspot'))
const pppoePools = computed(() => ipPools.value.filter(p => p.service_type === 'pppoe'))

onMounted(async () => {
  await Promise.all([
    loadInterfaces(),
    loadServices()
  ])
})

async function loadInterfaces() {
  try {
    loading.value = true
    error.value = null
    const response = await api.get(`/routers/${props.router.id}/interfaces`)
    
    interfaces.value = response.data.interfaces.map(iface => ({
      ...iface,
      selectedService: 'none',
      showAdvanced: false,
      advancedOptions: {},
      deploying: false,
      currentService: null
    }))
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to load interfaces'
    toast.error(error.value)
  } finally {
    loading.value = false
  }
}

async function loadIpPools() {
  try {
    if (ipPoolsLoaded.value) return
    const response = await api.get('/tenant/ip-pools')
    ipPools.value = response.data.pools || []
    ipPoolsLoaded.value = true
  } catch (err) {
    console.error('Failed to load IP pools:', err)
  }
}

async function loadServices() {
  try {
    const response = await api.get(`/routers/${props.router.id}/services`)
    const services = response.data.services || []
    
    services.forEach(service => {
      const iface = interfaces.value.find(i => i.name === service.interface_name)
      if (iface) {
        iface.currentService = service
        iface.selectedService = service.service_type
      }
    })
  } catch (err) {
    console.error('Failed to load services:', err)
  }
}

function onServiceChange(iface) {
  iface.advancedOptions = {}
  iface.showAdvanced = false
}

function setServiceMapping(iface, serviceType, enabled) {
  const current = iface.selectedService || 'none'

  if (enabled) {
    iface.selectedService = serviceType
  } else if (current === serviceType) {
    iface.selectedService = 'none'
  }

  onServiceChange(iface)
}

function toggleAdvanced(iface) {
  iface.showAdvanced = !iface.showAdvanced

  if (iface.showAdvanced) {
    loadIpPools()
  }
}

async function deploySelectedService(iface) {
  try {
    iface.deploying = true
    
    const response = await api.post(`/routers/${props.router.id}/services/configure`, {
      interface: iface.name,
      service_type: iface.selectedService,
      advanced_options: iface.advancedOptions
    })

    if (response.data.success) {
      if (iface.selectedService === 'none') {
        toast.success('Service removed')
        iface.currentService = null
        return
      }

      iface.currentService = response.data.service

      if (response.data.validation && !response.data.validation.valid) {
        toast.error(response.data.message || 'Service validation failed')
        return
      }

      const deployResponse = await api.post(
        `/routers/${props.router.id}/services/${iface.currentService.id}/deploy`
      )

      if (deployResponse.data.success) {
        toast.info('Service deployment started...')
        await waitForDeploymentViaWs(iface, iface.currentService.id)
      }
    }
  } catch (err) {
    const message = err.response?.data?.message || 'Failed to deploy service'
    toast.error(message)
  } finally {
    iface.deploying = false
  }
}

function waitForDeploymentViaWs(iface, serviceId) {
  return new Promise((resolve) => {
    const channelName = `router-provisioning.${props.router.id}`
    const ch = window.Echo?.private(channelName)

    if (!ch) {
      // Echo unavailable — optimistically report success; job runs server-side
      toast.success('Service deployment queued')
      loadServices()
      resolve()
      return
    }

    const timeout = setTimeout(async () => {
      window.Echo?.leave(channelName)
      toast.warning('Deployment is still in progress. Check status later.')
      await loadServices()
      resolve()
    }, 120_000)

    ch.listen('.provisioning.progress', async (data) => {
      const stage = data.stage || ''
      if (stage === `service_deploy_completed` || stage.endsWith('_completed')) {
        clearTimeout(timeout)
        window.Echo?.leave(channelName)
        toast.success('Service deployed successfully')
        await loadServices()
        resolve()
      } else if (stage === `service_deploy_failed` || stage.endsWith('_failed')) {
        clearTimeout(timeout)
        window.Echo?.leave(channelName)
        toast.error(data.message || 'Service deployment failed')
        await loadServices()
        resolve()
      }
    })
  })
}

async function removeService(iface) {
  if (!iface.currentService) return

  const confirmStore = useConfirmStore()
  const confirmed = await confirmStore.open({
    title: 'Remove Service',
    message: 'Are you sure you want to remove this service?',
    confirmText: 'Remove',
    cancelText: 'Cancel',
    variant: 'danger',
  })
  if (!confirmed) return

  try {
    const response = await api.delete(
      `/routers/${props.router.id}/services/${iface.currentService.id}`
    )

    if (response.data.success) {
      toast.success('Service removed')
      iface.currentService = null
      iface.selectedService = 'none'
    }
  } catch (err) {
    const message = err.response?.data?.message || 'Failed to remove service'
    toast.error(message)
  }
}
</script>
