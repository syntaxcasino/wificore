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
      <div v-for="iface in interfaces" :key="iface.name" class="interface-card">
        <div class="interface-header">
          <div class="interface-info">
            <h4>{{ iface.name }}</h4>
            <span class="interface-type">{{ iface.type }}</span>
            <span v-if="iface.running" class="status-badge status-active">Active</span>
            <span v-else class="status-badge status-inactive">Inactive</span>
          </div>
        </div>

        <div class="service-selector">
          <label class="form-label">Service Type</label>
          <div class="service-options">
            <label class="service-option">
              <input 
                type="radio" 
                :name="`service-${iface.name}`"
                value="none"
                v-model="iface.selectedService"
                @change="onServiceChange(iface)"
              />
              <span class="option-content">
                <span class="option-icon">⭕</span>
                <span class="option-label">None</span>
              </span>
            </label>

            <label class="service-option">
              <input 
                type="radio" 
                :name="`service-${iface.name}`"
                value="hotspot"
                v-model="iface.selectedService"
                @change="onServiceChange(iface)"
              />
              <span class="option-content">
                <span class="option-icon">📶</span>
                <span class="option-label">Hotspot</span>
              </span>
            </label>

            <label class="service-option">
              <input 
                type="radio" 
                :name="`service-${iface.name}`"
                value="pppoe"
                v-model="iface.selectedService"
                @change="onServiceChange(iface)"
              />
              <span class="option-content">
                <span class="option-icon">🔌</span>
                <span class="option-label">PPPoE</span>
              </span>
            </label>

            <label class="service-option">
              <input 
                type="radio" 
                :name="`service-${iface.name}`"
                value="hybrid"
                v-model="iface.selectedService"
                @change="onServiceChange(iface)"
              />
              <span class="option-content">
                <span class="option-icon">🔀</span>
                <span class="option-label">Hybrid</span>
                <span class="option-badge">VLAN</span>
              </span>
            </label>
          </div>

          <div v-if="iface.selectedService && iface.selectedService !== 'none'" class="service-details">
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
                @click="configureService(iface)"
                :disabled="iface.configuring"
                class="btn-primary"
              >
                {{ iface.configuring ? 'Configuring...' : 'Configure Service' }}
              </button>
            </div>
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
            <button @click="deployService(iface)" class="btn-secondary btn-sm">Deploy</button>
            <button @click="removeService(iface)" class="btn-danger btn-sm">Remove</button>
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

const hotspotPools = computed(() => ipPools.value.filter(p => p.service_type === 'hotspot'))
const pppoePools = computed(() => ipPools.value.filter(p => p.service_type === 'pppoe'))

onMounted(async () => {
  await Promise.all([
    loadInterfaces(),
    loadIpPools(),
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
      configuring: false,
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
    const response = await api.get('/tenant/ip-pools')
    ipPools.value = response.data.pools || []
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

function toggleAdvanced(iface) {
  iface.showAdvanced = !iface.showAdvanced
}

async function configureService(iface) {
  try {
    iface.configuring = true
    
    const response = await api.post(`/routers/${props.router.id}/services/configure`, {
      interface: iface.name,
      service_type: iface.selectedService,
      advanced_options: iface.advancedOptions
    })

    if (response.data.success) {
      toast.success('Service configured successfully')
      iface.currentService = response.data.service
      
      if (response.data.validation && !response.data.validation.valid) {
        toast.warning('Service configured but has validation warnings')
      }
    }
  } catch (err) {
    const message = err.response?.data?.message || 'Failed to configure service'
    toast.error(message)
  } finally {
    iface.configuring = false
  }
}

async function deployService(iface) {
  if (!iface.currentService) return

  try {
    const response = await api.post(
      `/routers/${props.router.id}/services/${iface.currentService.id}/deploy`
    )

    if (response.data.success) {
      toast.success('Service deployment started')
      await loadServices()
    }
  } catch (err) {
    const message = err.response?.data?.message || 'Failed to deploy service'
    toast.error(message)
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
