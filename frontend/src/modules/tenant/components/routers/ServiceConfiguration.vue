<template>
  <div class="service-configuration">
    <div class="section-header">
      <h3>Service Configuration</h3>
      <p class="text-sm text-gray-600">Configure services on router interfaces (Zero-Config)</p>
    </div>

    <div v-if="loading" class="loading-state">
      <div class="spinner"></div>
      <p>Loading interfaces...</p>
    </div>

    <div v-else-if="error" class="error-state">
      <p class="text-red-600">{{ error }}</p>
      <button @click="loadInterfaces" class="btn-secondary">Retry</button>
    </div>

    <div v-else class="interfaces-list">
      <div class="service-mapping-card">
        <div class="service-mapping-header">
          <div class="service-mapping-header-left">
            <div class="service-mapping-icon">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 15.5A3.5 3.5 0 1 0 12 8.5a3.5 3.5 0 0 0 0 7Z" />
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.65 1.65 0 0 0 15 19.4a1.65 1.65 0 0 0-1 .6 1.65 1.65 0 0 0-.33 1.82V22a2 2 0 1 1-4 0v-.08a1.65 1.65 0 0 0-.33-1.82 1.65 1.65 0 0 0-1-.6 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-.6-1 1.65 1.65 0 0 0-1.82-.33H2a2 2 0 1 1 0-4h.08a1.65 1.65 0 0 0 1.82-.33 1.65 1.65 0 0 0 .6-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06A2 2 0 1 1 6.94 3.6l.06.06A1.65 1.65 0 0 0 9 4.6c.3 0 .6-.1 1-.3.4-.2.7-.5 1-.9V2a2 2 0 1 1 4 0v.08c.1.4.4.7.7 1 .3.3.7.5 1.1.5.4 0 .8-.1 1.1-.3l.06-.06A2 2 0 1 1 21.2 6.5l-.06.06c-.2.3-.3.7-.3 1.1 0 .4.2.8.5 1.1.3.3.6.6 1 .7H22a2 2 0 1 1 0 4h-.08c-.4.1-.7.4-1 .7-.3.3-.5.7-.5 1.1Z" />
              </svg>
            </div>
            <div class="service-mapping-header-text">
              <h4>Service Mapping</h4>
              <p>Select exactly one service per interface. Advanced options are applied automatically.</p>
            </div>
          </div>
        </div>

        <div class="service-mapping-table">
          <div
            v-for="iface in interfaces"
            :key="iface.name"
            class="service-mapping-row"
          >
            <div class="service-mapping-row-main">
              <div class="service-mapping-info">
                <div class="service-mapping-name">{{ iface.name }}</div>
                <div class="service-mapping-type">{{ iface.type }}</div>
              </div>

              <div class="service-mapping-controls">
              <label class="service-toggle">
                <input
                  type="checkbox"
                  class="service-toggle-input"
                  :checked="(iface.selectedService || 'none') === 'hotspot'"
                  @change="(e) => setServiceMapping(iface, 'hotspot', e.target.checked)"
                />
                <span
                  class="service-toggle-track"
                  :class="(iface.selectedService || 'none') === 'hotspot' ? 'toggle-on toggle-hotspot' : 'toggle-off'"
                >
                  <span
                    class="service-toggle-thumb"
                    :class="(iface.selectedService || 'none') === 'hotspot' ? 'thumb-on' : ''"
                  ></span>
                </span>
                <span class="service-toggle-label">Hotspot</span>
              </label>

              <label class="service-toggle">
                <input
                  type="checkbox"
                  class="service-toggle-input"
                  :checked="(iface.selectedService || 'none') === 'pppoe'"
                  @change="(e) => setServiceMapping(iface, 'pppoe', e.target.checked)"
                />
                <span
                  class="service-toggle-track"
                  :class="(iface.selectedService || 'none') === 'pppoe' ? 'toggle-on toggle-pppoe' : 'toggle-off'"
                >
                  <span
                    class="service-toggle-thumb"
                    :class="(iface.selectedService || 'none') === 'pppoe' ? 'thumb-on' : ''"
                  ></span>
                </span>
                <span class="service-toggle-label">PPPoE</span>
              </label>

              <label class="service-toggle">
                <input
                  type="checkbox"
                  class="service-toggle-input"
                  :checked="(iface.selectedService || 'none') === 'hybrid'"
                  @change="(e) => setServiceMapping(iface, 'hybrid', e.target.checked)"
                />
                <span
                  class="service-toggle-track"
                  :class="(iface.selectedService || 'none') === 'hybrid' ? 'toggle-on toggle-hybrid' : 'toggle-off'"
                >
                  <span
                    class="service-toggle-thumb"
                    :class="(iface.selectedService || 'none') === 'hybrid' ? 'thumb-on' : ''"
                  ></span>
                </span>
                <span class="service-toggle-label">Hybrid</span>
              </label>
            </div>
            </div>

            <div v-if="iface.selectedService !== 'none'" class="service-details">
              <div class="auto-config-notice">
                <span class="icon">✨</span>
                <div>
                  <strong>Zero-Config Enabled</strong>
                  <p>IP pools, VLANs, and RADIUS will be configured automatically</p>
                </div>
              </div>

              <button
                @click="toggleAdvanced(iface)"
                class="btn-link"
              >
                {{ iface.showAdvanced ? '▼' : '▶' }} Advanced Options
              </button>

              <div v-if="iface.showAdvanced" class="advanced-options">
                <div class="form-group">
                  <div class="warning-box">
                    <strong>Advanced Options</strong>
                    <p>Incorrect configuration may disrupt service.</p>
                  </div>
                </div>

                <div class="form-group">
                  <label>Service Name</label>
                  <input
                    type="text"
                    v-model="iface.advancedOptions.service_name"
                    :placeholder="`${iface.selectedService} Service`"
                    class="form-input"
                  />
                </div>

                <div v-if="iface.selectedService !== 'hybrid'" class="form-group">
                  <label>IP Pool</label>
                  <select v-model="iface.advancedOptions.ip_pool_id" class="form-select">
                    <option :value="null">Auto (Recommended)</option>
                    <option v-for="pool in ipPools" :key="pool.id" :value="pool.id">
                      {{ pool.network_cidr }} ({{ pool.service_type }})
                    </option>
                  </select>
                </div>

                <div v-if="iface.selectedService === 'hybrid'" class="form-group">
                  <label>Hotspot IP Pool</label>
                  <select v-model="iface.advancedOptions.hotspot_pool_id" class="form-select">
                    <option :value="null">Auto (Recommended)</option>
                    <option v-for="pool in hotspotPools" :key="pool.id" :value="pool.id">
                      {{ pool.network_cidr }}
                    </option>
                  </select>
                </div>

                <div v-if="iface.selectedService === 'hybrid'" class="form-group">
                  <label>PPPoE IP Pool</label>
                  <select v-model="iface.advancedOptions.pppoe_pool_id" class="form-select">
                    <option :value="null">Auto (Recommended)</option>
                    <option v-for="pool in pppoePools" :key="pool.id" :value="pool.id">
                      {{ pool.network_cidr }}
                    </option>
                  </select>
                </div>

                <div v-if="iface.selectedService === 'hybrid'" class="form-group">
                  <label>Hotspot VLAN ID</label>
                  <input
                    type="number"
                    v-model.number="iface.advancedOptions.hotspot_vlan"
                    placeholder="Auto (100-199)"
                    min="1"
                    max="4094"
                    class="form-input"
                  />
                </div>

                <div v-if="iface.selectedService === 'hybrid'" class="form-group">
                  <label>PPPoE VLAN ID</label>
                  <input
                    type="number"
                    v-model.number="iface.advancedOptions.pppoe_vlan"
                    placeholder="Auto (200-299)"
                    min="1"
                    max="4094"
                    class="form-input"
                  />
                </div>

                <div class="form-group">
                  <label>RADIUS Profile</label>
                  <input
                    type="text"
                    v-model="iface.advancedOptions.radius_profile"
                    :placeholder="`${iface.selectedService}-${router.tenant_id}`"
                    class="form-input"
                  />
                </div>
              </div>

            <div class="action-buttons">
              <button 
                @click="deploySelectedService(iface)"
                :disabled="iface.deploying"
                class="btn-primary"
              >
                {{ iface.deploying ? 'Deploying...' : 'Deploy' }}
              </button>
            </div>
            </div>

            <div v-if="iface.currentService" class="current-service">
              <div class="service-status">
                <span class="status-label">Current Service:</span>
                <span class="service-name">{{ iface.currentService.service_name }}</span>
                <span :class="`deployment-status status-${iface.currentService.deployment_status}`">
                  {{ iface.currentService.deployment_status }}
                </span>
              </div>
              <div class="service-actions">
                <button @click="removeService(iface)" class="btn-danger btn-sm">Remove</button>
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
import api from '@/services/api'

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
        toast.success('Service deployment started')
        await loadServices()
      }
    }
  } catch (err) {
    const message = err.response?.data?.message || 'Failed to deploy service'
    toast.error(message)
  } finally {
    iface.deploying = false
  }
}

async function removeService(iface) {
  if (!iface.currentService) return

  if (!confirm('Are you sure you want to remove this service?')) return

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

<style scoped>
.service-configuration {
  padding: 1.5rem;
}

.section-header {
  margin-bottom: 2rem;
}

.section-header h3 {
  font-size: 1.5rem;
  font-weight: 600;
  margin-bottom: 0.5rem;
}

.loading-state, .error-state {
  text-align: center;
  padding: 3rem;
}

.spinner {
  border: 3px solid #f3f3f3;
  border-top: 3px solid #3498db;
  border-radius: 50%;
  width: 40px;
  height: 40px;
  animation: spin 1s linear infinite;
  margin: 0 auto 1rem;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.interfaces-list {
  display: grid;
  gap: 1.5rem;
}

.service-mapping-card {
  border: 1px solid #dbeafe;
  border-radius: 0.75rem;
  background: #eff6ff;
  padding: 1rem;
}

.service-mapping-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.75rem;
}

.service-mapping-header-left {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.service-mapping-icon {
  width: 34px;
  height: 34px;
  border-radius: 0.75rem;
  background: #ffffff;
  border: 1px solid #bfdbfe;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #2563eb;
}

.service-mapping-icon svg {
  width: 18px;
  height: 18px;
}

.service-mapping-header-text h4 {
  font-size: 1.125rem;
  font-weight: 700;
  color: #111827;
  margin: 0;
}

.service-mapping-header-text p {
  font-size: 0.875rem;
  color: #374151;
  margin: 0.25rem 0 0;
}



.service-mapping-table {
  background: #ffffff;
  border: 1px solid #bfdbfe;
  border-radius: 0.75rem;
  overflow: hidden;
}

.service-mapping-row {
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #dbeafe;
}

.service-mapping-row:last-child {
  border-bottom: none;
}

.service-mapping-row-main {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.service-mapping-info {
  min-width: 0;
}

.service-mapping-name {
  font-size: 0.875rem;
  font-weight: 600;
  color: #111827;
}

.service-mapping-type {
  font-size: 0.75rem;
  color: #6b7280;
}

.service-mapping-controls {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
}

.service-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  user-select: none;
  cursor: pointer;
  position: relative;
}

.service-toggle-input {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
  pointer-events: none;
}

.service-toggle-track {
  position: relative;
  width: 38px;
  height: 20px;
  border-radius: 999px;
  transition: background-color 0.15s ease;
  box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.08);
}

.toggle-off {
  background: #e5e7eb;
}

.toggle-on {
  background: #2563eb;
}

.toggle-pppoe.toggle-on {
  background: #4f46e5;
}

.toggle-hybrid.toggle-on {
  background: #059669;
}

.service-toggle-thumb {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 16px;
  height: 16px;
  border-radius: 999px;
  background: #ffffff;
  transition: transform 0.15s ease;
}

.thumb-on {
  transform: translateX(18px);
}

.service-toggle-label {
  font-size: 0.75rem;
  font-weight: 600;
  color: #374151;
}

.interface-card {
  border: 1px solid #e5e7eb;
  border-radius: 0.5rem;
  padding: 1.5rem;
  background: white;
}

.interface-header {
  margin-bottom: 1.5rem;
}

.interface-info {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.interface-info h4 {
  font-size: 1.125rem;
  font-weight: 600;
}

.interface-type {
  padding: 0.25rem 0.75rem;
  background: #f3f4f6;
  border-radius: 0.25rem;
  font-size: 0.875rem;
}

.status-badge {
  padding: 0.25rem 0.75rem;
  border-radius: 0.25rem;
  font-size: 0.875rem;
  font-weight: 500;
}

.status-active {
  background: #d1fae5;
  color: #065f46;
}

.status-inactive {
  background: #fee2e2;
  color: #991b1b;
}

.service-selector {
  margin-top: 1rem;
}

.form-label {
  display: block;
  font-weight: 500;
  margin-bottom: 0.75rem;
}

.service-options {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}

.service-option {
  position: relative;
  cursor: pointer;
}

.service-option input[type="radio"] {
  position: absolute;
  opacity: 0;
}

.option-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1rem;
  border: 2px solid #e5e7eb;
  border-radius: 0.5rem;
  transition: all 0.2s;
}

.service-option input[type="radio"]:checked + .option-content {
  border-color: #3b82f6;
  background: #eff6ff;
}

.option-icon {
  font-size: 2rem;
  margin-bottom: 0.5rem;
}

.option-label {
  font-weight: 500;
}

.option-badge {
  margin-top: 0.25rem;
  padding: 0.125rem 0.5rem;
  background: #fef3c7;
  color: #92400e;
  border-radius: 0.25rem;
  font-size: 0.75rem;
}

.service-details {
  margin-top: 1.5rem;
  padding-top: 1.5rem;
  border-top: 1px solid #e5e7eb;
}

.auto-config-notice {
  display: flex;
  gap: 1rem;
  padding: 1rem;
  background: #f0fdf4;
  border: 1px solid #86efac;
  border-radius: 0.5rem;
  margin-bottom: 1rem;
}

.auto-config-notice .icon {
  font-size: 1.5rem;
}

.auto-config-notice strong {
  display: block;
  color: #166534;
  margin-bottom: 0.25rem;
}

.auto-config-notice p {
  font-size: 0.875rem;
  color: #166534;
}

.btn-link {
  background: none;
  border: none;
  color: #3b82f6;
  cursor: pointer;
  padding: 0.5rem 0;
  font-weight: 500;
}

.advanced-options {
  margin-top: 1rem;
  padding: 1rem;
  background: #f9fafb;
  border-radius: 0.5rem;
}

.form-group {
  margin-bottom: 1rem;
}

.form-group label {
  display: block;
  font-weight: 500;
  margin-bottom: 0.5rem;
  font-size: 0.875rem;
}

.form-input, .form-select {
  width: 100%;
  padding: 0.5rem;
  border: 1px solid #d1d5db;
  border-radius: 0.375rem;
}

.action-buttons {
  margin-top: 1.5rem;
  display: flex;
  gap: 1rem;
}

.btn-primary, .btn-secondary, .btn-danger {
  padding: 0.75rem 1.5rem;
  border-radius: 0.375rem;
  font-weight: 500;
  cursor: pointer;
  border: none;
}

.btn-primary {
  background: #3b82f6;
  color: white;
}

.btn-primary:disabled {
  background: #9ca3af;
  cursor: not-allowed;
}

.btn-secondary {
  background: #6b7280;
  color: white;
}

.btn-danger {
  background: #ef4444;
  color: white;
}

.btn-sm {
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
}

.current-service {
  margin-top: 1.5rem;
  padding-top: 1.5rem;
  border-top: 1px solid #e5e7eb;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.service-status {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.status-label {
  font-weight: 500;
  color: #6b7280;
}

.service-name {
  font-weight: 600;
}

.deployment-status {
  padding: 0.25rem 0.75rem;
  border-radius: 0.25rem;
  font-size: 0.875rem;
  font-weight: 500;
}

.status-pending {
  background: #fef3c7;
  color: #92400e;
}

.status-in_progress {
  background: #dbeafe;
  color: #1e40af;
}

.status-deployed {
  background: #d1fae5;
  color: #065f46;
}

.status-failed {
  background: #fee2e2;
  color: #991b1b;
}

.service-actions {
  display: flex;
  gap: 0.5rem;
}
</style>
