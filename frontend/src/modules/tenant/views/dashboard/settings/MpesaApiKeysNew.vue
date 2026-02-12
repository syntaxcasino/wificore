<template>
  <PageContainer>
    <PageHeader title="Payment Gateway" subtitle="Manage M-Pesa Paybill payment integration" icon="CreditCard" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="fetchSettings" variant="ghost" :loading="loading">
          <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': loading }" />
        </BaseButton>
        <BaseButton @click="showConfigOverlay = true" variant="primary">
          <Settings2 class="w-4 h-4 mr-1" />
          Configure Gateway
        </BaseButton>
      </template>
    </PageHeader>

    <PageContent>
      <div class="space-y-6">
        <!-- Status Cards -->
        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 sm:gap-4">
          <BaseCard>
            <div class="p-4 flex items-center gap-3">
              <div class="p-2 rounded-lg" :class="gatewayActive ? 'bg-green-100' : 'bg-slate-100'">
                <Wifi class="w-5 h-5" :class="gatewayActive ? 'text-green-600' : 'text-slate-400'" />
              </div>
              <div>
                <div class="text-xs text-slate-500">Gateway Status</div>
                <div class="text-sm font-semibold" :class="gatewayActive ? 'text-green-700' : 'text-slate-600'">
                  {{ gatewayActive ? 'Active' : 'Not Configured' }}
                </div>
              </div>
            </div>
          </BaseCard>
          <BaseCard>
            <div class="p-4 flex items-center gap-3">
              <div class="p-2 rounded-lg bg-blue-100">
                <CreditCard class="w-5 h-5 text-blue-600" />
              </div>
              <div>
                <div class="text-xs text-slate-500">Paybill Mode</div>
                <div class="text-sm font-semibold text-slate-800">
                  {{ settingsData?.use_landlord_paybill ? 'System Paybill' : (settingsData?.business_shortcode || 'Not Set') }}
                </div>
              </div>
            </div>
          </BaseCard>
          <BaseCard>
            <div class="p-4 flex items-center gap-3">
              <div class="p-2 rounded-lg bg-amber-100">
                <Globe class="w-5 h-5 text-amber-600" />
              </div>
              <div>
                <div class="text-xs text-slate-500">Environment</div>
                <div class="text-sm font-semibold text-slate-800 capitalize">
                  {{ settingsData?.environment || 'sandbox' }}
                </div>
              </div>
            </div>
          </BaseCard>
        </div>

        <!-- Gateway Table -->
        <BaseCard :padding="false">
          <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900">Configured Payment Gateways</h3>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                  <th class="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase">Gateway</th>
                  <th class="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase">Shortcode</th>
                  <th class="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase">Environment</th>
                  <th class="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase">Mode</th>
                  <th class="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase">Status</th>
                  <th class="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase">Last Transaction</th>
                  <th class="text-right px-6 py-3 text-xs font-medium text-slate-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-if="loading" class="text-center">
                  <td colspan="7" class="px-6 py-8 text-sm text-slate-500">Loading...</td>
                </tr>
                <tr v-else-if="!settingsData" class="text-center">
                  <td colspan="7" class="px-6 py-8">
                    <div class="text-sm text-slate-500">No payment gateway configured</div>
                    <button @click="showConfigOverlay = true" class="mt-2 text-sm text-blue-600 hover:text-blue-700 font-medium">
                      Configure M-Pesa Paybill
                    </button>
                  </td>
                </tr>
                <tr v-else class="hover:bg-slate-50 cursor-pointer" @click="showDetailOverlay = true">
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-2">
                      <Smartphone class="w-4 h-4 text-green-600" />
                      <span class="text-sm font-medium text-slate-900">M-Pesa Paybill</span>
                    </div>
                  </td>
                  <td class="px-6 py-4 text-sm text-slate-700 font-mono">
                    {{ settingsData.use_landlord_paybill ? (landlordShortcode || '—') : (settingsData.business_shortcode || '—') }}
                  </td>
                  <td class="px-6 py-4">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="settingsData.environment === 'production' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'">
                      {{ settingsData.environment === 'production' ? 'Production' : 'Sandbox' }}
                    </span>
                  </td>
                  <td class="px-6 py-4 text-sm text-slate-700">
                    {{ settingsData.use_landlord_paybill ? 'System Paybill' : 'Own Paybill' }}
                  </td>
                  <td class="px-6 py-4">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium" :class="gatewayActive ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600'">
                      <span class="w-1.5 h-1.5 rounded-full" :class="gatewayActive ? 'bg-green-500' : 'bg-slate-400'"></span>
                      {{ gatewayActive ? 'Active' : 'Inactive' }}
                    </span>
                  </td>
                  <td class="px-6 py-4 text-sm text-slate-500">
                    {{ settingsData.last_transaction_at ? new Date(settingsData.last_transaction_at).toLocaleDateString() : 'Never' }}
                  </td>
                  <td class="px-6 py-4 text-right">
                    <button @click.stop="showConfigOverlay = true" class="text-blue-600 hover:text-blue-700 text-sm font-medium">
                      Edit
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>
      </div>
    </PageContent>

    <!-- Configure Gateway Overlay -->
    <SlideOverlay v-model="showConfigOverlay" title="Configure Payment Gateway" subtitle="M-Pesa Paybill Settings" icon="CreditCard" width="50%">
      <div class="space-y-6">
        <BaseAlert v-if="landlordPaybillAvailable" variant="info" title="System Paybill Available">
          A system-wide Paybill ({{ landlordShortcode }}) is available. You can use it or configure your own.
        </BaseAlert>

        <!-- Paybill Mode -->
        <div class="space-y-3">
          <h4 class="text-sm font-semibold text-slate-900">Paybill Mode</h4>
          <div class="flex gap-3">
            <button @click="formData.use_landlord_paybill = true" class="flex-1 p-3 rounded-lg border-2 text-left transition-all" :class="formData.use_landlord_paybill ? 'border-blue-500 bg-blue-50' : 'border-slate-200 hover:border-slate-300'">
              <div class="text-sm font-medium" :class="formData.use_landlord_paybill ? 'text-blue-900' : 'text-slate-700'">Use System Paybill</div>
              <div class="text-xs mt-0.5" :class="formData.use_landlord_paybill ? 'text-blue-600' : 'text-slate-500'">Managed by system admin</div>
            </button>
            <button @click="formData.use_landlord_paybill = false" class="flex-1 p-3 rounded-lg border-2 text-left transition-all" :class="!formData.use_landlord_paybill ? 'border-blue-500 bg-blue-50' : 'border-slate-200 hover:border-slate-300'">
              <div class="text-sm font-medium" :class="!formData.use_landlord_paybill ? 'text-blue-900' : 'text-slate-700'">Own Paybill</div>
              <div class="text-xs mt-0.5" :class="!formData.use_landlord_paybill ? 'text-blue-600' : 'text-slate-500'">Your Safaricom credentials</div>
            </button>
          </div>
        </div>

        <!-- Own Paybill Credentials -->
        <div v-if="!formData.use_landlord_paybill" class="space-y-4">
          <h4 class="text-sm font-semibold text-slate-900">API Credentials</h4>

          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Environment</label>
            <select v-model="formData.environment" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
              <option value="sandbox">Sandbox (Testing)</option>
              <option value="production">Production (Live)</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Business Short Code *</label>
            <input v-model="formData.business_shortcode" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-blue-500" placeholder="174379" />
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Consumer Key *</label>
            <input v-model="formData.consumer_key" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-blue-500" placeholder="Enter consumer key" />
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Consumer Secret *</label>
            <input v-model="formData.consumer_secret" type="password" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-blue-500" placeholder="Enter consumer secret" />
            <p v-if="settingsData?.consumer_secret" class="mt-1 text-xs text-slate-500">Leave blank to keep existing</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Passkey *</label>
            <input v-model="formData.passkey" type="password" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-blue-500" placeholder="Lipa Na M-Pesa Online Passkey" />
            <p v-if="settingsData?.passkey" class="mt-1 text-xs text-slate-500">Leave blank to keep existing</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Account Reference</label>
            <input v-model="formData.account_reference" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" placeholder="e.g. WiFi Payment" />
          </div>
        </div>

        <!-- Connection Test Result -->
        <div v-if="connectionStatus" class="flex items-center gap-3 p-3 rounded-lg" :class="connectionStatus.success ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'">
          <component :is="connectionStatus.success ? CheckCircle : XCircle" class="w-5 h-5 flex-shrink-0" :class="connectionStatus.success ? 'text-green-600' : 'text-red-600'" />
          <div>
            <div class="text-sm font-medium" :class="connectionStatus.success ? 'text-green-900' : 'text-red-900'">{{ connectionStatus.message }}</div>
            <div v-if="connectionStatus.details" class="text-xs" :class="connectionStatus.success ? 'text-green-600' : 'text-red-600'">{{ connectionStatus.details }}</div>
          </div>
        </div>

        <!-- Error -->
        <div v-if="error" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">{{ error }}</div>
      </div>

      <template #footer>
        <div class="flex items-center justify-between">
          <BaseButton @click="testConnection" variant="ghost" :loading="testing" size="sm">
            <Zap class="w-4 h-4 mr-1" />
            Test Connection
          </BaseButton>
          <div class="flex gap-2">
            <BaseButton @click="showConfigOverlay = false" variant="ghost">Cancel</BaseButton>
            <BaseButton @click="saveSettings" variant="primary" :loading="saving">
              <Save class="w-4 h-4 mr-1" />
              Save Settings
            </BaseButton>
          </div>
        </div>
      </template>
    </SlideOverlay>

    <!-- Gateway Detail Overlay -->
    <SlideOverlay v-model="showDetailOverlay" title="Payment Gateway Details" subtitle="M-Pesa Paybill Configuration" icon="Eye" width="40%">
      <div v-if="settingsData" class="space-y-5">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <div class="text-xs text-slate-500">Gateway Type</div>
            <div class="text-sm font-medium text-slate-900 mt-0.5">M-Pesa Paybill</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Status</div>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium mt-0.5" :class="gatewayActive ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600'">
              <span class="w-1.5 h-1.5 rounded-full" :class="gatewayActive ? 'bg-green-500' : 'bg-slate-400'"></span>
              {{ gatewayActive ? 'Active' : 'Inactive' }}
            </span>
          </div>
          <div>
            <div class="text-xs text-slate-500">Mode</div>
            <div class="text-sm font-medium text-slate-900 mt-0.5">{{ settingsData.use_landlord_paybill ? 'System Paybill' : 'Own Paybill' }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Environment</div>
            <div class="text-sm font-medium text-slate-900 mt-0.5 capitalize">{{ settingsData.environment }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Shortcode</div>
            <div class="text-sm font-medium text-slate-900 mt-0.5 font-mono">
              {{ settingsData.use_landlord_paybill ? (landlordShortcode || '—') : (settingsData.business_shortcode || '—') }}
            </div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Consumer Key</div>
            <div class="text-sm font-medium text-slate-900 mt-0.5 font-mono">{{ settingsData.consumer_key || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Account Reference</div>
            <div class="text-sm font-medium text-slate-900 mt-0.5">{{ settingsData.account_reference || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Verified</div>
            <div class="text-sm font-medium mt-0.5" :class="settingsData.is_verified ? 'text-green-700' : 'text-slate-500'">
              {{ settingsData.is_verified ? 'Yes' : 'No' }}
            </div>
          </div>
          <div>
            <div class="text-xs text-slate-500">URLs Registered</div>
            <div class="text-sm font-medium text-slate-900 mt-0.5">{{ settingsData.urls_registered_at || 'Not registered' }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Last Transaction</div>
            <div class="text-sm font-medium text-slate-900 mt-0.5">{{ settingsData.last_transaction_at ? new Date(settingsData.last_transaction_at).toLocaleString() : 'Never' }}</div>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="flex items-center justify-between">
          <BaseButton @click="testConnection" variant="ghost" :loading="testing" size="sm">
            <Zap class="w-4 h-4 mr-1" />
            Test Connection
          </BaseButton>
          <div class="flex gap-2">
            <BaseButton v-if="!settingsData?.use_landlord_paybill && !settingsData?.is_active" @click="activateGateway" variant="primary" :loading="activating" size="sm">
              Activate
            </BaseButton>
            <BaseButton @click="showDetailOverlay = false; showConfigOverlay = true" variant="primary" size="sm">
              <Settings2 class="w-4 h-4 mr-1" />
              Edit
            </BaseButton>
          </div>
        </div>
      </template>
    </SlideOverlay>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { CreditCard, Settings2, RefreshCw, Smartphone, Wifi, Globe, Zap, Save, CheckCircle, XCircle, Eye } from 'lucide-vue-next'
import axios from 'axios'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Settings', to: '/dashboard/settings' },
  { label: 'Payment Gateway' }
]

const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const activating = ref(false)
const error = ref('')
const connectionStatus = ref(null)

const showConfigOverlay = ref(false)
const showDetailOverlay = ref(false)

const settingsData = ref(null)
const landlordPaybillAvailable = ref(false)
const landlordShortcode = ref('')

const gatewayActive = computed(() => {
  if (!settingsData.value) return false
  return settingsData.value.is_active || settingsData.value.use_landlord_paybill
})

const formDefaults = {
  environment: 'sandbox',
  business_shortcode: '',
  consumer_key: '',
  consumer_secret: '',
  passkey: '',
  account_reference: '',
  use_landlord_paybill: true,
}

const formData = ref({ ...formDefaults })

const fetchSettings = async () => {
  loading.value = true
  error.value = ''
  try {
    const response = await axios.get('/billing/paybill/settings')
    const d = response.data
    settingsData.value = d.data
    landlordPaybillAvailable.value = d.landlord_paybill_available ?? false
    landlordShortcode.value = d.landlord_shortcode ?? ''

    if (d.data) {
      formData.value = {
        environment: d.data.environment || 'sandbox',
        business_shortcode: d.data.business_shortcode || '',
        consumer_key: '',
        consumer_secret: '',
        passkey: '',
        account_reference: d.data.account_reference || '',
        use_landlord_paybill: d.data.use_landlord_paybill ?? true,
      }
    } else {
      formData.value = { ...formDefaults }
    }
  } catch (err) {
    console.error('fetchSettings error:', err)
    error.value = err.response?.data?.message || 'Failed to load settings'
  } finally {
    loading.value = false
  }
}

const saveSettings = async () => {
  saving.value = true
  error.value = ''
  connectionStatus.value = null
  try {
    const response = await axios.post('/billing/paybill/settings', formData.value)
    settingsData.value = response.data?.data || settingsData.value
    showConfigOverlay.value = false
    fetchSettings()
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to save settings'
  } finally {
    saving.value = false
  }
}

const testConnection = async () => {
  testing.value = true
  connectionStatus.value = null
  try {
    const response = await axios.post('/billing/paybill/test')
    connectionStatus.value = {
      success: response.data?.success ?? true,
      message: response.data?.message || 'Connection Successful',
      details: response.data?.using_landlord_paybill ? 'Using system Paybill' : 'Using own Paybill'
    }
  } catch (err) {
    connectionStatus.value = {
      success: false,
      message: 'Connection Failed',
      details: err.response?.data?.message || 'Please check your credentials'
    }
  } finally {
    testing.value = false
  }
}

const activateGateway = async () => {
  activating.value = true
  try {
    await axios.post('/billing/paybill/activate')
    fetchSettings()
    showDetailOverlay.value = false
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to activate gateway'
  } finally {
    activating.value = false
  }
}

onMounted(() => {
  fetchSettings()
})
</script>
