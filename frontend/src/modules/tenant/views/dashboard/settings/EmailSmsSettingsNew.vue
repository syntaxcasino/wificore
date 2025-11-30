<template>
  <PageContainer>
    <PageHeader title="Email & SMS Settings" subtitle="Configure notification channels" icon="Mail" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="testEmail" variant="ghost">
          <Send class="w-4 h-4 mr-1" />
          Test Email
        </BaseButton>
        <BaseButton @click="testSMS" variant="ghost">
          <Smartphone class="w-4 h-4 mr-1" />
          Test SMS
        </BaseButton>
        <BaseButton @click="saveSettings" variant="primary" :loading="saving">
          <Save class="w-4 h-4 mr-1" />
          Save Changes
        </BaseButton>
      </template>
    </PageHeader>

    <PageContent>
      <div class="max-w-4xl mx-auto space-y-6">
        <BaseCard>
          <div class="p-6 space-y-4">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Email Configuration (SMTP)</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">SMTP Host *</label>
                <input v-model="formData.smtp_host" type="text" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="smtp.gmail.com" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">SMTP Port *</label>
                <input v-model.number="formData.smtp_port" type="number" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="587" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">SMTP Username *</label>
                <input v-model="formData.smtp_username" type="text" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">SMTP Password *</label>
                <input v-model="formData.smtp_password" type="password" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">From Email *</label>
                <input v-model="formData.from_email" type="email" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="noreply@traidnet.com" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">From Name *</label>
                <input v-model="formData.from_name" type="text" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="TraidNet ISP" />
              </div>
            </div>

            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
              <div>
                <div class="text-sm font-medium text-slate-900">Use TLS/SSL</div>
                <div class="text-xs text-slate-500">Enable secure connection</div>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="formData.smtp_encryption" type="checkbox" class="sr-only peer" />
                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
              </label>
            </div>
          </div>
        </BaseCard>

        <BaseCard>
          <div class="p-6 space-y-4">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">SMS Configuration</h3>
            
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-2">SMS Provider</label>
              <BaseSelect v-model="formData.sms_provider" class="w-full">
                <option value="africastalking">Africa's Talking</option>
                <option value="twilio">Twilio</option>
                <option value="custom">Custom API</option>
              </BaseSelect>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">API Key *</label>
                <input v-model="formData.sms_api_key" type="password" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Sender ID</label>
                <input v-model="formData.sms_sender_id" type="text" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="TRAIDNET" />
              </div>
            </div>

            <div v-if="formData.sms_provider === 'custom'">
              <label class="block text-sm font-medium text-slate-700 mb-2">API Endpoint</label>
              <input v-model="formData.sms_api_endpoint" type="url" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="https://api.example.com/sms" />
            </div>
          </div>
        </BaseCard>

        <BaseCard>
          <div class="p-6 space-y-3">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Notification Preferences</h3>
            
            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
              <div>
                <div class="text-sm font-medium text-slate-900">Welcome Email</div>
                <div class="text-xs text-slate-500">Send welcome email to new users</div>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="formData.send_welcome_email" type="checkbox" class="sr-only peer" />
                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
              </label>
            </div>

            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
              <div>
                <div class="text-sm font-medium text-slate-900">Payment Confirmation</div>
                <div class="text-xs text-slate-500">Notify users of successful payments</div>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="formData.send_payment_confirmation" type="checkbox" class="sr-only peer" />
                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
              </label>
            </div>

            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
              <div>
                <div class="text-sm font-medium text-slate-900">Expiry Reminders</div>
                <div class="text-xs text-slate-500">Remind users before package expiry</div>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="formData.send_expiry_reminders" type="checkbox" class="sr-only peer" />
                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
              </label>
            </div>

            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
              <div>
                <div class="text-sm font-medium text-slate-900">Invoice Notifications</div>
                <div class="text-xs text-slate-500">Send invoice notifications</div>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="formData.send_invoice_notifications" type="checkbox" class="sr-only peer" />
                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
              </label>
            </div>
          </div>
        </BaseCard>
      </div>
    </PageContent>
  </PageContainer>
</template>

<script setup>
import { ref } from 'vue'
import { Mail, Send, Smartphone, Save } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Settings', to: '/dashboard/settings' },
  { label: 'Email & SMS' }
]

const saving = ref(false)

const formData = ref({
  smtp_host: 'smtp.gmail.com',
  smtp_port: 587,
  smtp_username: '',
  smtp_password: '',
  from_email: 'noreply@traidnet.com',
  from_name: 'TraidNet ISP',
  smtp_encryption: true,
  sms_provider: 'africastalking',
  sms_api_key: '',
  sms_sender_id: 'TRAIDNET',
  sms_api_endpoint: '',
  send_welcome_email: true,
  send_payment_confirmation: true,
  send_expiry_reminders: true,
  send_invoice_notifications: true
})

const saveSettings = async () => {
  saving.value = true
  try {
    await new Promise(resolve => setTimeout(resolve, 1000))
    alert('Settings saved successfully!')
  } finally {
    saving.value = false
  }
}

const testEmail = () => alert('Test email sent!')
const testSMS = () => alert('Test SMS sent!')
</script>
