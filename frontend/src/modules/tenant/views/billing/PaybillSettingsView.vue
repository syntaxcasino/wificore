<template>
  <PageContainer>
    <PageHeader
      title="Paybill Settings"
      subtitle="Configure MPesa Paybill for automatic PPPoE payments"
      icon="CreditCard"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="testConnection" variant="ghost" size="sm" :loading="testing">
          <Wifi class="w-4 h-4 mr-1" />
          Test Connection
        </BaseButton>
        <BaseButton @click="triggerPaymentCheck" variant="secondary" size="sm" :loading="checkingPayments">
          <RefreshCw class="w-4 h-4 mr-1" />
          Check Payments
        </BaseButton>
      </template>
    </PageHeader>

    <PageContent>
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Paybill Configuration Card -->
        <div class="lg:col-span-2">
          <BaseCard>
            <template #header>
              <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Paybill Configuration</h3>
                <BaseBadge v-if="settings?.has_own_paybill" variant="success">Own Paybill Active</BaseBadge>
                <BaseBadge v-else-if="settings?.using_landlord_paybill" variant="info">Using Landlord Paybill</BaseBadge>
                <BaseBadge v-else variant="warning">Not Configured</BaseBadge>
              </div>
            </template>

            <div v-if="loading" class="p-4">
              <BaseLoading type="form" />
            </div>

            <form v-else @submit.prevent="saveSettings" class="space-y-6">
              <!-- Paybill Type Selection -->
              <div class="bg-slate-50 rounded-lg p-4 space-y-4">
                <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Payment Collection Method</label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div 
                    @click="form.use_landlord_paybill = true"
                    :class="[
                      'p-4 rounded-lg border-2 cursor-pointer transition-all',
                      form.use_landlord_paybill 
                        ? 'border-primary-500 bg-primary-50' 
                        : 'border-slate-200 hover:border-slate-300'
                    ]"
                  >
                    <div class="flex items-center gap-3">
                      <div :class="['w-4 h-4 rounded-full border-2', form.use_landlord_paybill ? 'border-primary-500 bg-primary-500' : 'border-slate-300']">
                        <Check v-if="form.use_landlord_paybill" class="w-3 h-3 text-white" />
                      </div>
                      <div>
                        <p class="font-medium text-slate-900 dark:text-slate-100">Use Landlord Paybill</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Payments go to {{ settings?.landlord_shortcode || 'system' }} Paybill</p>
                      </div>
                    </div>
                  </div>

                  <div 
                    @click="form.use_landlord_paybill = false"
                    :class="[
                      'p-4 rounded-lg border-2 cursor-pointer transition-all',
                      !form.use_landlord_paybill 
                        ? 'border-primary-500 bg-primary-50' 
                        : 'border-slate-200 hover:border-slate-300'
                    ]"
                  >
                    <div class="flex items-center gap-3">
                      <div :class="['w-4 h-4 rounded-full border-2', !form.use_landlord_paybill ? 'border-primary-500 bg-primary-500' : 'border-slate-300']">
                        <Check v-if="!form.use_landlord_paybill" class="w-3 h-3 text-white" />
                      </div>
                      <div>
                        <p class="font-medium text-slate-900 dark:text-slate-100">Use Own Paybill</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Payments go directly to your Paybill</p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Own Paybill Configuration -->
              <div v-if="!form.use_landlord_paybill" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <BaseInput
                    v-model="form.business_shortcode"
                    label="Business Shortcode (Paybill Number)"
                    placeholder="e.g., 174379"
                    required
                  />
                  <BaseSelect
                    v-model="form.environment"
                    label="Environment"
                    required
                  >
                    <option value="sandbox">Sandbox (Testing)</option>
                    <option value="production">Production (Live)</option>
                  </BaseSelect>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <BaseInput
                    v-model="form.consumer_key"
                    label="Consumer Key"
                    placeholder="Enter your Safaricom API Consumer Key"
                    type="password"
                  />
                  <BaseInput
                    v-model="form.consumer_secret"
                    label="Consumer Secret"
                    placeholder="Enter your Safaricom API Consumer Secret"
                    type="password"
                  />
                </div>

                <BaseInput
                  v-model="form.passkey"
                  label="Passkey"
                  placeholder="Enter your Safaricom API Passkey"
                  type="password"
                />

                <BaseInput
                  v-model="form.account_reference"
                  label="Default Account Reference Prefix (Optional)"
                  placeholder="e.g., ISP-"
                  help="Prefix added to account numbers for payment matching"
                />

                <!-- URL Registration Status -->
                <div v-if="settings?.data?.validation_url" class="bg-green-50 border border-green-200 rounded-lg p-4">
                  <div class="flex items-center gap-2 text-green-700">
                    <CheckCircle class="w-5 h-5" />
                    <span class="font-medium">Callback URLs Registered</span>
                  </div>
                  <p class="text-sm text-green-600 mt-1">
                    Registered on {{ formatDate(settings?.data?.urls_registered_at) }}
                  </p>
                </div>

                <BaseButton 
                  v-else 
                  @click="registerUrls" 
                  type="button" 
                  variant="secondary"
                  :loading="registeringUrls"
                  :disabled="!form.business_shortcode"
                >
                  <Link class="w-4 h-4 mr-1" />
                  Register Callback URLs
                </BaseButton>
              </div>

              <!-- Actions -->
              <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-slate-700">
                <BaseButton type="submit" :loading="saving">
                  <Save class="w-4 h-4 mr-1" />
                  Save Settings
                </BaseButton>
              </div>
            </form>
          </BaseCard>
        </div>

        <!-- Status & Instructions Card -->
        <div class="space-y-6">
          <!-- Current Status -->
          <BaseCard>
            <template #header>
              <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Payment Status</h3>
            </template>

            <div class="space-y-4">
              <div class="flex items-center justify-between">
                <span class="text-sm text-slate-600 dark:text-slate-400">Connection</span>
                <BaseBadge :variant="connectionStatus === 'connected' ? 'success' : 'danger'">
                  {{ connectionStatus === 'connected' ? 'Connected' : 'Not Connected' }}
                </BaseBadge>
              </div>

              <div class="flex items-center justify-between">
                <span class="text-sm text-slate-600 dark:text-slate-400">Last Payment Check</span>
                <span class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ lastCheckTime || 'Never' }}</span>
              </div>

              <div class="flex items-center justify-between">
                <span class="text-sm text-slate-600 dark:text-slate-400">Active Paybill</span>
                <span class="text-sm font-medium text-slate-900 dark:text-slate-100">
                  {{ activePaybill || 'None' }}
                </span>
              </div>
            </div>
          </BaseCard>

          <!-- Payment Instructions Preview -->
          <BaseCard>
            <template #header>
              <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Payment Instructions</h3>
            </template>

            <div class="bg-slate-50 rounded-lg p-4 text-sm space-y-2">
              <p class="font-medium text-slate-700">Your users will pay using:</p>
              <ol class="list-decimal list-inside space-y-1 text-slate-600">
                <li>Go to M-Pesa menu</li>
                <li>Select "Lipa na M-Pesa"</li>
                <li>Select "Pay Bill"</li>
                <li>Business Number: <strong class="text-slate-900">{{ activePaybill || '---' }}</strong></li>
                <li>Account Number: <strong class="text-slate-900">[Username]</strong></li>
                <li>Enter Amount</li>
                <li>Enter PIN & Confirm</li>
              </ol>
            </div>
          </BaseCard>

          <!-- Recent Transactions -->
          <BaseCard>
            <template #header>
              <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Recent Transactions</h3>
                <router-link to="/billing/transactions" class="text-sm text-primary-600 hover:text-primary-700">
                  View All
                </router-link>
              </div>
            </template>

            <div v-if="recentTransactions.length === 0" class="text-center py-4 text-slate-500">
              No recent transactions
            </div>

            <div v-else class="divide-y divide-slate-100 dark:divide-slate-700">
              <div v-for="tx in recentTransactions" :key="tx.id" class="py-3 flex items-center justify-between">
                <div>
                  <p class="font-medium text-slate-900 dark:text-slate-100">{{ tx.bill_ref_number }}</p>
                  <p class="text-sm text-slate-500 dark:text-slate-400">{{ formatDate(tx.transaction_time) }}</p>
                </div>
                <div class="text-right">
                  <p class="font-medium text-green-600">KES {{ formatAmount(tx.amount) }}</p>
                  <BaseBadge :variant="tx.is_matched ? 'success' : 'warning'" size="sm">
                    {{ tx.is_matched ? 'Matched' : 'Pending' }}
                  </BaseBadge>
                </div>
              </div>
            </div>
          </BaseCard>
        </div>
      </div>
    </PageContent>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useToast } from '@/modules/common/composables/useToast'
import {
  CreditCard, Wifi, RefreshCw, Check, CheckCircle, Link, Save
} from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/PageContent.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseInput from '@/modules/common/components/base/BaseInput.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'
import { useMpesaSettings } from '@/modules/tenant/composables/useMpesaSettings.js'

const { showSuccess, showError } = useToast()

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Billing', to: '/billing' },
  { label: 'Paybill Settings' },
]

const recentTransactions = ref([])

const loadTransactions = async () => {
  recentTransactions.value = await fetchRecentTransactions(5)
}

const {
  loading, saving, testing, activating, error,
  settingsData, landlordPaybillAvailable, landlordShortcode,
  connectionStatus, formData, gatewayActive,
  fetchSettings: fetchBase, saveSettings, testConnection, activateGateway, fetchRecentTransactions
} = useMpesaSettings()

const settings = settingsData

const activePaybill = computed(() => {
  if (formData.value.use_landlord_paybill) return settingsData.value?.landlord_shortcode || null
  return formData.value.business_shortcode || settingsData.value?.data?.business_shortcode || null
})

const fetchSettings = async () => {
  await fetchBase()
  await loadTransactions()
}

const handleSave = async () => {
  const result = await saveSettings()
  if (result.success) {
    showSuccess('Settings saved successfully')
    await fetchSettings()
  } else {
    showError(error.value || 'Failed to save settings')
  }
}

const handleTest = async () => {
  await testConnection()
}

const handleActivate = async () => {
  await activateGateway()
  await fetchSettings()
}

onMounted(fetchSettings)
</script>
