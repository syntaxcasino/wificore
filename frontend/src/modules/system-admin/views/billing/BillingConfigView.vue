<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Billing Configuration</h1>
        <p class="text-xs sm:text-sm text-gray-500 mt-1">Manage SaaS billing rates, plans, and default payment settings</p>
      </div>
      <button @click="fetchConfig" :disabled="loading" class="inline-flex items-center gap-2 px-3 py-1.5 sm:px-4 sm:py-2 bg-gray-100 text-gray-700 text-xs sm:text-sm font-medium rounded-lg hover:bg-gray-200 disabled:opacity-50 transition-colors self-start sm:self-auto">
        <RefreshCw class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
        Refresh
      </button>
    </div>

    <div v-if="loading" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-gray-500">
      <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
      Loading configuration...
    </div>
    <div v-else-if="error" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-red-500">
      {{ error }}
      <button @click="fetchConfig" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
    </div>
    <template v-else>
      <!-- Billing Rates Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden overflow-x-auto">
        <div class="flex items-center justify-between px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200">
          <h2 class="text-base sm:text-lg font-semibold text-gray-900">Current Billing Rates</h2>
        </div>
        <table class="w-full min-w-[400px]">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Rate</th>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Value</th>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Description</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr class="hover:bg-gray-50 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900">PPPoE Rate</td>
              <td class="px-6 py-4 text-sm font-semibold text-blue-600">{{ config.pppoe_rate || 'N/A' }}</td>
              <td class="px-6 py-4 text-sm text-gray-500">Per user/month</td>
            </tr>
            <tr class="hover:bg-gray-50 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900">Hotspot Revenue %</td>
              <td class="px-6 py-4 text-sm font-semibold text-blue-600">{{ config.hotspot_revenue_pct || 'N/A' }}%</td>
              <td class="px-6 py-4 text-sm text-gray-500">Of hotspot revenue</td>
            </tr>
            <tr class="hover:bg-gray-50 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900">Minimum Subscription</td>
              <td class="px-6 py-4 text-sm font-semibold text-blue-600">{{ config.minimum_subscription || 'N/A' }}</td>
              <td class="px-6 py-4 text-sm text-gray-500">KES/month</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Subscription Plans Table -->
      <div v-if="config.plans && Object.keys(config.plans).length" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
          <h2 class="text-lg font-semibold text-gray-900">Subscription Plans</h2>
        </div>
        <table class="w-full">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Plan</th>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Details</th>
              <th class="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(plan, key) in config.plans" :key="key" class="hover:bg-gray-50 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 capitalize">{{ key }}</td>
              <td class="px-6 py-4 text-sm text-gray-600">
                <div v-if="typeof plan === 'object'" class="flex flex-wrap gap-1.5">
                  <span v-for="(val, prop) in plan" :key="prop" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">{{ prop }}: {{ val }}</span>
                </div>
                <span v-else>{{ plan }}</span>
              </td>
              <td class="px-6 py-4 text-right">
                <button @click="openPlanDetail(key, plan)" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors" title="View">
                  <Eye class="w-4 h-4" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Paybill Settings Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
          <h2 class="text-lg font-semibold text-gray-900">Default Paybill Settings</h2>
          <button @click="showPaybillOverlay = true" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors">
            <Pencil class="w-3.5 h-3.5" />
            Edit Paybill
          </button>
        </div>
        <table class="w-full">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Setting</th>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Value</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr class="hover:bg-gray-50"><td class="px-6 py-4 text-sm font-medium text-gray-900">Paybill Number</td><td class="px-6 py-4 text-sm text-gray-600 font-mono">{{ config.default_paybill || 'Not configured' }}</td></tr>
            <tr class="hover:bg-gray-50"><td class="px-6 py-4 text-sm font-medium text-gray-900">Paybill Name</td><td class="px-6 py-4 text-sm text-gray-600">{{ config.default_paybill_name || 'Not configured' }}</td></tr>
            <tr class="hover:bg-gray-50"><td class="px-6 py-4 text-sm font-medium text-gray-900">Environment</td><td class="px-6 py-4"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="paybillForm.environment === 'production' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'">{{ paybillForm.environment }}</span></td></tr>
            <tr class="hover:bg-gray-50"><td class="px-6 py-4 text-sm font-medium text-gray-900">Credentials</td><td class="px-6 py-4 text-sm text-gray-500">{{ config.default_paybill ? 'Configured (hidden)' : 'Not set' }}</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Edit Paybill Overlay -->
    <SlideOverlay v-model="showPaybillOverlay" title="Edit Paybill Settings" subtitle="Update M-Pesa paybill credentials" icon="CreditCard" width="40%" @close="showPaybillOverlay = false">
      <form @submit.prevent="updatePaybill" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Paybill Number</label>
          <input v-model="paybillForm.paybill" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Paybill Name</label>
          <input v-model="paybillForm.paybill_name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Consumer Key</label>
          <input v-model="paybillForm.consumer_key" type="password" placeholder="Leave blank to keep current" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Consumer Secret</label>
          <input v-model="paybillForm.consumer_secret" type="password" placeholder="Leave blank to keep current" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Passkey</label>
          <input v-model="paybillForm.passkey" type="password" placeholder="Leave blank to keep current" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Environment</label>
          <select v-model="paybillForm.environment" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
            <option value="sandbox">Sandbox</option>
            <option value="production">Production</option>
          </select>
        </div>
        <div v-if="saveMessage" class="text-sm" :class="saveError ? 'text-red-600' : 'text-green-600'">{{ saveMessage }}</div>
      </form>
      <template #footer>
        <div class="flex justify-end gap-3">
          <button type="button" @click="showPaybillOverlay = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
          <button @click="updatePaybill" :disabled="saving" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors">{{ saving ? 'Saving...' : 'Update Paybill' }}</button>
        </div>
      </template>
    </SlideOverlay>

    <!-- Plan Detail Overlay -->
    <SlideOverlay v-model="showPlanOverlay" :title="selectedPlanKey" subtitle="Plan configuration details" icon="FileText" width="40%" @close="showPlanOverlay = false">
      <div v-if="selectedPlan && typeof selectedPlan === 'object'" class="space-y-3">
        <div v-for="(val, prop) in selectedPlan" :key="prop" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-700 capitalize">{{ String(prop).replace(/_/g, ' ') }}</span>
          <span class="text-sm font-semibold text-gray-900">{{ val }}</span>
        </div>
      </div>
      <div v-else class="text-sm text-gray-500">{{ selectedPlan }}</div>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { RefreshCw, Pencil, Eye } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const config = ref({})
const loading = ref(true)
const error = ref(null)
const saving = ref(false)
const saveMessage = ref('')
const saveError = ref(false)
const showPaybillOverlay = ref(false)
const showPlanOverlay = ref(false)
const selectedPlanKey = ref('')
const selectedPlan = ref(null)

const paybillForm = reactive({
  paybill: '',
  paybill_name: '',
  consumer_key: '',
  consumer_secret: '',
  passkey: '',
  environment: 'sandbox'
})

const openPlanDetail = (key, plan) => {
  selectedPlanKey.value = key.charAt(0).toUpperCase() + key.slice(1) + ' Plan'
  selectedPlan.value = plan
  showPlanOverlay.value = true
}

const fetchConfig = async () => {
  try {
    loading.value = true
    error.value = null
    const res = await axios.get('/system/landlord/configuration')
    config.value = res.data.configuration || {}
    paybillForm.paybill = config.value.default_paybill || ''
    paybillForm.paybill_name = config.value.default_paybill_name || ''
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load configuration'
  } finally {
    loading.value = false
  }
}

const updatePaybill = async () => {
  try {
    saving.value = true
    saveMessage.value = ''
    saveError.value = false
    const payload = { ...paybillForm }
    if (!payload.consumer_key) delete payload.consumer_key
    if (!payload.consumer_secret) delete payload.consumer_secret
    if (!payload.passkey) delete payload.passkey
    await axios.put('/system/landlord/paybill', payload)
    saveMessage.value = 'Paybill updated successfully'
    paybillForm.consumer_key = ''
    paybillForm.consumer_secret = ''
    paybillForm.passkey = ''
    await fetchConfig()
  } catch (err) {
    saveError.value = true
    saveMessage.value = err.response?.data?.message || 'Failed to update paybill'
  } finally {
    saving.value = false
  }
}

onMounted(() => fetchConfig())
</script>
