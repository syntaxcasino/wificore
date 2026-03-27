<template>
  <DataViewContainer
    title="Payment Gateways"
    subtitle="Configure and manage payment gateways"
    color-theme="emerald"
    :loading="loading"
    @refresh="fetchGateways"
  >
    <template #icon>
      <CreditCard class="h-5 w-5 md:h-6 md:w-6 text-white" />
    </template>

    <template #actions>
      <BaseButton @click="openCreateOverlay" variant="primary" size="sm">
        <Plus class="w-4 h-4 mr-1" />
        Add Gateway
      </BaseButton>
    </template>

    <template #stats>
      <div class="flex items-center gap-2 px-3 py-2 bg-slate-50 rounded-lg border border-slate-200">
        <span class="w-2 h-2 bg-emerald-500 rounded-full"></span>
        <span class="text-xs font-semibold text-slate-700">{{ activeGateways.length }} Active</span>
        <span class="text-slate-300">|</span>
        <span class="text-xs font-semibold text-emerald-600">{{ gateways.length }} Total</span>
      </div>
    </template>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <AlertCircle class="w-10 h-10" />
      <p class="text-center">{{ error }}</p>
      <button @click="fetchGateways" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">
        Retry
      </button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="gateways.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="gateway in gateways"
          :key="gateway.id"
          :title="gateway.name"
          :subtitle="formatProvider(gateway.provider)"
          :meta-lines="[
            { text: gateway.environment === 'live' ? 'Live Mode' : 'Sandbox', class: gateway.environment === 'live' ? 'text-emerald-600' : 'text-amber-600' }
          ]"
          :status="gateway.is_active ? 'active' : 'inactive'"
          :badges="gateway.is_default ? [{ text: 'Default', class: 'bg-emerald-100 text-emerald-700' }] : []"
          :actions="getGatewayActions(gateway)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Gateway</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Provider</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Environment</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-center text-xs font-semibold text-slate-700 uppercase tracking-wider">Default</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="gateway in gateways" :key="gateway.id" class="hover:bg-emerald-50/50 transition-colors">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <CreditCard class="w-4 h-4 text-emerald-600" />
                    <span class="text-sm font-medium text-slate-900">{{ gateway.name }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-slate-600 capitalize">{{ formatProvider(gateway.provider) }}</span>
                </td>
                <td class="px-6 py-4">
                  <span :class="gateway.environment === 'live' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'" class="px-2 py-0.5 rounded-full text-xs font-medium">
                    {{ gateway.environment === 'live' ? 'Live' : 'Sandbox' }}
                  </span>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="gateway.is_active ? 'active' : 'inactive'" size="sm" />
                </td>
                <td class="px-6 py-4 text-center">
                  <span v-if="gateway.is_default" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">
                    Default
                  </span>
                  <span v-else class="text-slate-400">—</span>
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button v-if="!gateway.is_default && gateway.is_active" @click="setDefault(gateway)" class="px-2 py-1 text-xs font-medium text-emerald-700 bg-emerald-50 rounded hover:bg-emerald-100 transition-colors">
                      Set Default
                    </button>
                    <button @click="openViewOverlay(gateway)" class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors">
                      View
                    </button>
                    <button @click="openEditOverlay(gateway)" class="px-2 py-1 text-xs font-medium text-slate-700 bg-slate-100 rounded hover:bg-slate-200 transition-colors">
                      Edit
                    </button>
                    <button @click="handleDelete(gateway)" class="px-2 py-1 text-xs font-medium text-red-600 bg-red-50 rounded hover:bg-red-100 transition-colors">
                      Delete
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      title="No Payment Gateways"
      description="Configure payment gateways to accept customer payments"
      icon="credit-card"
      color-theme="emerald"
      add-text="Add First Gateway"
      @add="openCreateOverlay"
    />
  </DataViewContainer>

  <!-- Create Overlay -->
  <SlideOverlay v-model="showCreateOverlay" title="Add Payment Gateway" subtitle="Configure a new payment gateway" icon="Plus" width="480px">
    <div class="p-6 space-y-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Gateway Name *</label>
        <input v-model="form.name" type="text" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm" placeholder="e.g. Main M-Pesa" />
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Provider *</label>
        <select v-model="form.provider" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm" @change="onProviderChange">
          <option value="">Select provider...</option>
          <option value="mpesa">M-Pesa (Daraja)</option>
          <option value="stripe">Stripe</option>
          <option value="paypal">PayPal</option>
          <option value="flutterwave">Flutterwave</option>
          <option value="custom">Custom API</option>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Environment *</label>
        <select v-model="form.environment" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm">
          <option value="sandbox">Sandbox (Test)</option>
          <option value="live">Live (Production)</option>
        </select>
      </div>

      <!-- Provider-specific credentials -->
      <div class="border border-slate-200 rounded-lg p-4 bg-slate-50">
        <h4 class="text-sm font-semibold text-slate-700 mb-3">API Credentials</h4>
        <div class="space-y-3">
          <div v-for="field in credentialFields" :key="field.key">
            <label class="block text-xs font-medium text-slate-600 mb-1">{{ field.label }}</label>
            <input v-model="form.credentials[field.key]" :type="field.secret ? 'password' : 'text'" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm" :placeholder="field.placeholder || ''" />
          </div>
        </div>
      </div>

      <div class="flex items-center gap-4">
        <label class="flex items-center gap-2 cursor-pointer">
          <input v-model="form.is_active" type="checkbox" class="w-4 h-4 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500" />
          <span class="text-sm text-slate-700">Active</span>
        </label>
        <label class="flex items-center gap-2 cursor-pointer">
          <input v-model="form.is_default" type="checkbox" class="w-4 h-4 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500" />
          <span class="text-sm text-slate-700">Set as default</span>
        </label>
      </div>

      <div v-if="formError" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">{{ formError }}</div>
    </div>

    <template #footer>
      <div class="flex gap-3">
        <button
          @click="showCreateOverlay = false"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
        >
          Cancel
        </button>
        <button
          @click="handleCreate"
          :disabled="formSubmitting"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors disabled:opacity-50"
        >
          {{ formSubmitting ? 'Creating...' : 'Create Gateway' }}
        </button>
      </div>
    </template>
  </SlideOverlay>

  <!-- View Overlay -->
  <SlideOverlay v-model="showViewOverlay" title="Gateway Details" :subtitle="selectedGateway?.name || ''" icon="Eye" width="480px">
    <div v-if="selectedGateway" class="space-y-4 p-6">
      <div class="grid grid-cols-2 gap-4">
        <div>
          <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Name</div>
          <div class="mt-1 text-sm font-semibold text-slate-900">{{ selectedGateway.name }}</div>
        </div>
        <div>
          <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Provider</div>
          <div class="mt-1 text-sm text-slate-700 capitalize">{{ formatProvider(selectedGateway.provider) }}</div>
        </div>
        <div>
          <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Environment</div>
          <div class="mt-1">
            <span :class="selectedGateway.environment === 'live' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'" class="px-2 py-0.5 rounded-full text-xs font-medium">
              {{ selectedGateway.environment === 'live' ? 'Live' : 'Sandbox' }}
            </span>
          </div>
        </div>
        <div>
          <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Status</div>
          <div class="mt-1">
            <span :class="selectedGateway.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'" class="px-2 py-0.5 rounded-full text-xs font-medium">
              {{ selectedGateway.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>
        </div>
      </div>

      <div class="border-t border-slate-200 pt-4">
        <h4 class="text-sm font-semibold text-slate-700 mb-3">Test Payment</h4>
        <div class="flex gap-3">
          <input v-model="testAmount" type="number" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm" placeholder="Amount (e.g. 100)" />
          <button @click="testGateway" :disabled="testing || !testAmount" class="px-4 py-2 text-sm font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors">
            <span v-if="testing">Testing...</span>
            <span v-else>Test</span>
          </button>
        </div>
        <div v-if="testResult" :class="testResult.success ? 'text-emerald-600' : 'text-red-600'" class="mt-2 text-xs">{{ testResult.message }}</div>
      </div>
    </div>

    <template #footer>
      <div class="flex gap-3">
        <button
          @click="showViewOverlay = false"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
        >
          Close
        </button>
        <button
          @click="openEditOverlay(selectedGateway)"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors"
        >
          <Pencil class="w-4 h-4 inline mr-1" /> Edit
        </button>
      </div>
    </template>
  </SlideOverlay>

  <!-- Edit Overlay -->
  <SlideOverlay v-model="showEditOverlay" title="Edit Payment Gateway" subtitle="Update gateway configuration" icon="Pencil" width="480px">
    <div class="p-6 space-y-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Gateway Name *</label>
        <input v-model="editForm.name" type="text" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm" />
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Environment *</label>
        <select v-model="editForm.environment" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm">
          <option value="sandbox">Sandbox (Test)</option>
          <option value="live">Live (Production)</option>
        </select>
      </div>

      <div class="border border-slate-200 rounded-lg p-4 bg-slate-50">
        <h4 class="text-sm font-semibold text-slate-700 mb-3">API Credentials</h4>
        <p class="text-xs text-slate-500 mb-3">Leave blank to keep existing credentials</p>
        <div class="space-y-3">
          <div v-for="field in editCredentialFields" :key="field.key">
            <label class="block text-xs font-medium text-slate-600 mb-1">{{ field.label }}</label>
            <input v-model="editForm.credentials[field.key]" :type="field.secret ? 'password' : 'text'" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm" :placeholder="field.placeholder || 'Leave blank to keep current'" />
          </div>
        </div>
      </div>

      <div class="flex items-center gap-4">
        <label class="flex items-center gap-2 cursor-pointer">
          <input v-model="editForm.is_active" type="checkbox" class="w-4 h-4 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500" />
          <span class="text-sm text-slate-700">Active</span>
        </label>
        <label class="flex items-center gap-2 cursor-pointer">
          <input v-model="editForm.is_default" type="checkbox" class="w-4 h-4 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500" />
          <span class="text-sm text-slate-700">Set as default</span>
        </label>
      </div>

      <div v-if="formError" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">{{ formError }}</div>
    </div>

    <template #footer>
      <div class="flex gap-3">
        <button
          @click="showEditOverlay = false"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
        >
          Cancel
        </button>
        <button
          @click="handleUpdate"
          :disabled="formSubmitting"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors disabled:opacity-50"
        >
          {{ formSubmitting ? 'Saving...' : 'Save Changes' }}
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { CreditCard, Plus, AlertCircle, Eye, Pencil, Trash2 } from 'lucide-vue-next'
import axios from 'axios'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const loading = ref(false)
const error = ref(null)
const gateways = ref([])

const showCreateOverlay = ref(false)
const showViewOverlay = ref(false)
const showEditOverlay = ref(false)
const selectedGateway = ref(null)
const formSubmitting = ref(false)
const formError = ref(null)

const testAmount = ref('')
const testing = ref(false)
const testResult = ref(null)

const activeGateways = computed(() => gateways.value.filter(g => g.is_active))

const credentialFields = computed(() => getCredentialFields(form.value.provider))
const editCredentialFields = computed(() => getCredentialFields(editForm.value.provider))

const form = ref({
  name: '',
  provider: '',
  environment: 'sandbox',
  is_active: true,
  is_default: false,
  credentials: {}
})

const editForm = ref({
  id: null,
  name: '',
  provider: '',
  environment: 'sandbox',
  is_active: true,
  is_default: false,
  credentials: {}
})

const getCredentialFields = (provider) => {
  const fields = {
    mpesa: [
      { key: 'consumer_key', label: 'Consumer Key' },
      { key: 'consumer_secret', label: 'Consumer Secret', secret: true },
      { key: 'passkey', label: 'Passkey', secret: true },
      { key: 'shortcode', label: 'Shortcode', placeholder: 'e.g. 174379' }
    ],
    stripe: [
      { key: 'publishable_key', label: 'Publishable Key' },
      { key: 'secret_key', label: 'Secret Key', secret: true }
    ],
    paypal: [
      { key: 'client_id', label: 'Client ID' },
      { key: 'client_secret', label: 'Client Secret', secret: true }
    ],
    flutterwave: [
      { key: 'public_key', label: 'Public Key' },
      { key: 'secret_key', label: 'Secret Key', secret: true }
    ],
    custom: [
      { key: 'api_key', label: 'API Key', secret: true },
      { key: 'api_endpoint', label: 'API Endpoint', placeholder: 'https://api.example.com/pay' }
    ]
  }
  return fields[provider] || fields.custom
}

const formatProvider = (p) => {
  const map = { mpesa: "M-Pesa (Daraja)", stripe: 'Stripe', paypal: 'PayPal', flutterwave: 'Flutterwave', custom: 'Custom API' }
  return map[p] || p
}

const onProviderChange = () => {
  form.value.credentials = {}
}

const fetchGateways = async () => {
  loading.value = true
  error.value = null
  try {
    const response = await axios.get('/settings/payment-gateways')
    gateways.value = response.data?.gateways || []
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to load payment gateways'
    console.error('fetchGateways error:', err)
  } finally {
    loading.value = false
  }
}

const openCreateOverlay = () => {
  form.value = { name: '', provider: '', environment: 'sandbox', is_active: true, is_default: false, credentials: {} }
  formError.value = null
  showCreateOverlay.value = true
}

const openViewOverlay = (gateway) => {
  selectedGateway.value = gateway
  testAmount.value = ''
  testResult.value = null
  showViewOverlay.value = true
}

const openEditOverlay = (gateway) => {
  showViewOverlay.value = false
  editForm.value = {
    id: gateway.id,
    name: gateway.name,
    provider: gateway.provider,
    environment: gateway.environment,
    is_active: gateway.is_active,
    is_default: gateway.is_default,
    credentials: {}
  }
  formError.value = null
  showEditOverlay.value = true
}

const handleCreate = async () => {
  formSubmitting.value = true
  formError.value = null
  try {
    await axios.post('/settings/payment-gateways', form.value)
    showCreateOverlay.value = false
    await fetchGateways()
  } catch (err) {
    formError.value = err.response?.data?.message || 'Failed to create gateway'
  } finally {
    formSubmitting.value = false
  }
}

const handleUpdate = async () => {
  formSubmitting.value = true
  formError.value = null
  try {
    const payload = { ...editForm.value }
    delete payload.id
    delete payload.provider
    if (payload.credentials) {
      const filtered = {}
      for (const [k, v] of Object.entries(payload.credentials)) {
        if (v && v.trim()) filtered[k] = v
      }
      if (Object.keys(filtered).length === 0) {
        delete payload.credentials
      } else {
        payload.credentials = filtered
      }
    }
    await axios.patch(`/settings/payment-gateways/${editForm.value.id}`, payload)
    showEditOverlay.value = false
    await fetchGateways()
  } catch (err) {
    formError.value = err.response?.data?.message || 'Failed to update gateway'
  } finally {
    formSubmitting.value = false
  }
}

const handleDelete = async (gateway) => {
  if (!confirm(`Delete gateway "${gateway.name}"? This cannot be undone.`)) return
  try {
    await axios.delete(`/settings/payment-gateways/${gateway.id}`)
    await fetchGateways()
  } catch (err) {
    console.error('Failed to delete gateway:', err)
    alert(err.response?.data?.message || 'Failed to delete gateway')
  }
}

const setDefault = async (gateway) => {
  try {
    await axios.patch(`/settings/payment-gateways/${gateway.id}`, { is_default: true })
    await fetchGateways()
  } catch (err) {
    console.error('Failed to set default:', err)
    alert(err.response?.data?.message || 'Failed to set as default')
  }
}

const testGateway = async () => {
  if (!selectedGateway.value || !testAmount.value) return
  testing.value = true
  testResult.value = null
  try {
    const res = await axios.post(`/settings/payment-gateways/${selectedGateway.value.id}/test`, { amount: testAmount.value })
    testResult.value = { success: true, message: res.data?.message || 'Test initiated' }
  } catch (err) {
    testResult.value = { success: false, message: err.response?.data?.message || 'Test failed' }
  } finally {
    testing.value = false
  }
}

const getGatewayActions = (gateway) => [
  { label: 'View', onClick: () => openViewOverlay(gateway), class: 'text-blue-700 bg-blue-50 hover:bg-blue-100' },
  { label: 'Edit', onClick: () => openEditOverlay(gateway), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' },
  ...(gateway.is_active && !gateway.is_default ? [{ label: 'Set Default', onClick: () => setDefault(gateway), class: 'text-emerald-700 bg-emerald-50 hover:bg-emerald-100' }] : [])
]

onMounted(() => {
  fetchGateways()
})
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
