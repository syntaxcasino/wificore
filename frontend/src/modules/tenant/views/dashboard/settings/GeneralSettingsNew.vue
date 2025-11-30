<template>
  <PageContainer>
    <PageHeader title="General Settings" subtitle="Configure system-wide settings" icon="Settings" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="resetToDefaults" variant="ghost">
          <RotateCcw class="w-4 h-4 mr-1" />
          Reset to Defaults
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
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Company Information</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Company Name *</label>
                <input v-model="formData.company_name" type="text" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Contact Email *</label>
                <input v-model="formData.contact_email" type="email" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Contact Phone</label>
                <input v-model="formData.contact_phone" type="tel" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Website</label>
                <input v-model="formData.website" type="url" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" />
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-2">Address</label>
              <textarea v-model="formData.address" rows="3" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
            </div>
          </div>
        </BaseCard>

        <BaseCard>
          <div class="p-6 space-y-4">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">System Settings</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Currency</label>
                <BaseSelect v-model="formData.currency" class="w-full">
                  <option value="KES">KES - Kenyan Shilling</option>
                  <option value="USD">USD - US Dollar</option>
                  <option value="EUR">EUR - Euro</option>
                </BaseSelect>
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Date Format</label>
                <BaseSelect v-model="formData.date_format" class="w-full">
                  <option value="DD/MM/YYYY">DD/MM/YYYY</option>
                  <option value="MM/DD/YYYY">MM/DD/YYYY</option>
                  <option value="YYYY-MM-DD">YYYY-MM-DD</option>
                </BaseSelect>
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Time Format</label>
                <BaseSelect v-model="formData.time_format" class="w-full">
                  <option value="12">12 Hour</option>
                  <option value="24">24 Hour</option>
                </BaseSelect>
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Language</label>
                <BaseSelect v-model="formData.language" class="w-full">
                  <option value="en">English</option>
                  <option value="sw">Swahili</option>
                </BaseSelect>
              </div>
            </div>
          </div>
        </BaseCard>

        <BaseCard>
          <div class="p-6 space-y-4">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Session Settings</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Session Timeout (minutes)</label>
                <input v-model.number="formData.session_timeout" type="number" min="5" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" />
                <p class="mt-1 text-xs text-slate-500">Auto-logout after inactivity</p>
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Max Login Attempts</label>
                <input v-model.number="formData.max_login_attempts" type="number" min="3" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" />
                <p class="mt-1 text-xs text-slate-500">Before account lockout</p>
              </div>
            </div>
          </div>
        </BaseCard>

        <BaseCard>
          <div class="p-6 space-y-3">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Feature Toggles</h3>
            
            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
              <div>
                <div class="text-sm font-medium text-slate-900">Enable User Registration</div>
                <div class="text-xs text-slate-500">Allow new users to self-register</div>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="formData.enable_registration" type="checkbox" class="sr-only peer" />
                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
              </label>
            </div>

            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
              <div>
                <div class="text-sm font-medium text-slate-900">Maintenance Mode</div>
                <div class="text-xs text-slate-500">Disable user access for maintenance</div>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="formData.maintenance_mode" type="checkbox" class="sr-only peer" />
                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
              </label>
            </div>

            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
              <div>
                <div class="text-sm font-medium text-slate-900">Email Notifications</div>
                <div class="text-xs text-slate-500">Send email notifications to users</div>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="formData.email_notifications" type="checkbox" class="sr-only peer" />
                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
              </label>
            </div>

            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
              <div>
                <div class="text-sm font-medium text-slate-900">SMS Notifications</div>
                <div class="text-xs text-slate-500">Send SMS notifications to users</div>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="formData.sms_notifications" type="checkbox" class="sr-only peer" />
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
import { Settings, RotateCcw, Save } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Settings', to: '/dashboard/settings' },
  { label: 'General Settings' }
]

const saving = ref(false)

const formData = ref({
  company_name: 'TraidNet ISP',
  contact_email: 'info@traidnet.com',
  contact_phone: '+254 700 000 000',
  website: 'https://traidnet.com',
  address: 'Nairobi, Kenya',
  currency: 'KES',
  date_format: 'DD/MM/YYYY',
  time_format: '24',
  language: 'en',
  session_timeout: 30,
  max_login_attempts: 5,
  enable_registration: true,
  maintenance_mode: false,
  email_notifications: true,
  sms_notifications: true
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

const resetToDefaults = () => {
  if (confirm('Reset all settings to defaults?')) {
    formData.value = {
      company_name: 'TraidNet ISP',
      contact_email: 'info@traidnet.com',
      contact_phone: '+254 700 000 000',
      website: 'https://traidnet.com',
      address: 'Nairobi, Kenya',
      currency: 'KES',
      date_format: 'DD/MM/YYYY',
      time_format: '24',
      language: 'en',
      session_timeout: 30,
      max_login_attempts: 5,
      enable_registration: true,
      maintenance_mode: false,
      email_notifications: true,
      sms_notifications: true
    }
  }
}
</script>
