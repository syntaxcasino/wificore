<template>
  <PageContainer>
    <PageHeader title="Organization Settings" subtitle="Company info, system preferences & feature toggles" icon="Settings" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="fetchSettings" variant="ghost" :loading="loading">
          <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': loading }" />
        </BaseButton>
        <BaseButton @click="openEditOverlay('all')" variant="primary">
          <Pencil class="w-4 h-4 mr-1" />
          Edit Settings
        </BaseButton>
      </template>
    </PageHeader>

    <PageContent>
      <div class="space-y-6">
        <!-- Company Information -->
        <BaseCard>
          <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Company Information</h3>
            <button @click="openEditOverlay('company')" class="text-sm text-blue-600 hover:text-blue-700 font-medium">Edit</button>
          </div>
          <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
              <div>
                <div class="text-xs text-slate-500 dark:text-slate-400">Company Name</div>
                <div class="text-sm font-medium text-slate-900 mt-0.5">{{ formData.company_name || '—' }}</div>
              </div>
              <div>
                <div class="text-xs text-slate-500 dark:text-slate-400">Contact Email</div>
                <div class="text-sm font-medium text-slate-900 mt-0.5">{{ formData.contact_email || '—' }}</div>
              </div>
              <div>
                <div class="text-xs text-slate-500 dark:text-slate-400">Contact Phone</div>
                <div class="text-sm font-medium text-slate-900 mt-0.5">{{ formData.contact_phone || '—' }}</div>
              </div>
              <div>
                <div class="text-xs text-slate-500 dark:text-slate-400">Website</div>
                <div class="text-sm font-medium text-slate-900 mt-0.5">{{ formData.website || '—' }}</div>
              </div>
              <div class="md:col-span-2">
                <div class="text-xs text-slate-500 dark:text-slate-400">Address</div>
                <div class="text-sm font-medium text-slate-900 mt-0.5">{{ formData.address || '—' }}</div>
              </div>
            </div>
          </div>
        </BaseCard>

        <!-- System Settings Summary -->
        <BaseCard>
          <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">System Preferences</h3>
            <button @click="openEditOverlay('system')" class="text-sm text-blue-600 hover:text-blue-700 font-medium">Edit</button>
          </div>
          <div class="p-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-x-8 gap-y-4">
              <div>
                <div class="text-xs text-slate-500 dark:text-slate-400">Currency</div>
                <div class="text-sm font-medium text-slate-900 mt-0.5">{{ formData.currency }}</div>
              </div>
              <div>
                <div class="text-xs text-slate-500 dark:text-slate-400">Date Format</div>
                <div class="text-sm font-medium text-slate-900 mt-0.5">{{ formData.date_format }}</div>
              </div>
              <div>
                <div class="text-xs text-slate-500 dark:text-slate-400">Time Format</div>
                <div class="text-sm font-medium text-slate-900 mt-0.5">{{ formData.time_format === '24' ? '24 Hour' : '12 Hour' }}</div>
              </div>
              <div>
                <div class="text-xs text-slate-500 dark:text-slate-400">Language</div>
                <div class="text-sm font-medium text-slate-900 mt-0.5">{{ formData.language === 'en' ? 'English' : 'Swahili' }}</div>
              </div>
            </div>
          </div>
        </BaseCard>

        <!-- Session & Features Summary -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <BaseCard>
            <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
              <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Session Settings</h3>
              <button @click="openEditOverlay('session')" class="text-sm text-blue-600 hover:text-blue-700 font-medium">Edit</button>
            </div>
            <div class="p-6 space-y-3">
              <div class="flex items-center justify-between">
                <span class="text-sm text-slate-600 dark:text-slate-400">Session Timeout</span>
                <span class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ formData.session_timeout }} min</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-sm text-slate-600 dark:text-slate-400">Max Login Attempts</span>
                <span class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ formData.max_login_attempts }}</span>
              </div>
            </div>
          </BaseCard>

          <BaseCard>
            <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
              <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Feature Toggles</h3>
              <button @click="openEditOverlay('features')" class="text-sm text-blue-600 hover:text-blue-700 font-medium">Edit</button>
            </div>
            <div class="p-6 space-y-3">
              <div class="flex items-center justify-between">
                <span class="text-sm text-slate-600 dark:text-slate-400">User Registration</span>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="formData.enable_registration ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600'">{{ formData.enable_registration ? 'Enabled' : 'Disabled' }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-sm text-slate-600 dark:text-slate-400">Maintenance Mode</span>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="formData.maintenance_mode ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700'">{{ formData.maintenance_mode ? 'On' : 'Off' }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-sm text-slate-600 dark:text-slate-400">Email Notifications</span>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="formData.email_notifications ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600'">{{ formData.email_notifications ? 'On' : 'Off' }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-sm text-slate-600 dark:text-slate-400">SMS Notifications</span>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="formData.sms_notifications ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600'">{{ formData.sms_notifications ? 'On' : 'Off' }}</span>
              </div>
            </div>
          </BaseCard>
        </div>
      </div>
    </PageContent>

    <!-- Edit Settings Overlay -->
    <SlideOverlay v-model="showEditOverlay" :title="editOverlayTitle" subtitle="Update organization settings" icon="Settings" width="60%">
      <div class="space-y-6">
        <!-- Company Information Section -->
        <div v-if="editSection === 'all' || editSection === 'company'" class="space-y-4">
          <h4 v-if="editSection === 'all'" class="text-sm font-semibold text-slate-900 border-b border-slate-200 pb-2">Company Information</h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Company Name *</label>
              <input v-model="editData.company_name" type="text" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Contact Email *</label>
              <input v-model="editData.contact_email" type="email" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Contact Phone</label>
              <input v-model="editData.contact_phone" type="tel" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Website</label>
              <input v-model="editData.website" type="url" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Address</label>
            <textarea v-model="editData.address" rows="2" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500"></textarea>
          </div>
        </div>

        <!-- System Settings Section -->
        <div v-if="editSection === 'all' || editSection === 'system'" class="space-y-4">
          <h4 v-if="editSection === 'all'" class="text-sm font-semibold text-slate-900 border-b border-slate-200 pb-2">System Preferences</h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Currency</label>
              <select v-model="editData.currency" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500">
                <option value="KES">KES - Kenyan Shilling</option>
                <option value="USD">USD - US Dollar</option>
                <option value="EUR">EUR - Euro</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Date Format</label>
              <select v-model="editData.date_format" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500">
                <option value="DD/MM/YYYY">DD/MM/YYYY</option>
                <option value="MM/DD/YYYY">MM/DD/YYYY</option>
                <option value="YYYY-MM-DD">YYYY-MM-DD</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Time Format</label>
              <select v-model="editData.time_format" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500">
                <option value="12">12 Hour</option>
                <option value="24">24 Hour</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Language</label>
              <select v-model="editData.language" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500">
                <option value="en">English</option>
                <option value="sw">Swahili</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Session Settings Section -->
        <div v-if="editSection === 'all' || editSection === 'session'" class="space-y-4">
          <h4 v-if="editSection === 'all'" class="text-sm font-semibold text-slate-900 border-b border-slate-200 pb-2">Session Settings</h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Session Timeout (minutes)</label>
              <input v-model.number="editData.session_timeout" type="number" min="5" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Max Login Attempts</label>
              <input v-model.number="editData.max_login_attempts" type="number" min="3" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500" />
            </div>
          </div>
        </div>

        <!-- Feature Toggles Section -->
        <div v-if="editSection === 'all' || editSection === 'features'" class="space-y-3">
          <h4 v-if="editSection === 'all'" class="text-sm font-semibold text-slate-900 border-b border-slate-200 pb-2">Feature Toggles</h4>
          <div class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-700/50 rounded-lg">
            <div>
              <div class="text-sm font-medium text-slate-900 dark:text-slate-100">Enable User Registration</div>
              <div class="text-xs text-slate-500 dark:text-slate-400">Allow new users to self-register</div>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input v-model="editData.enable_registration" type="checkbox" class="sr-only peer" />
              <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
            </label>
          </div>
          <div class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-700/50 rounded-lg">
            <div>
              <div class="text-sm font-medium text-slate-900 dark:text-slate-100">Maintenance Mode</div>
              <div class="text-xs text-slate-500 dark:text-slate-400">Disable user access for maintenance</div>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input v-model="editData.maintenance_mode" type="checkbox" class="sr-only peer" />
              <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
            </label>
          </div>
          <div class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-700/50 rounded-lg">
            <div>
              <div class="text-sm font-medium text-slate-900 dark:text-slate-100">Email Notifications</div>
              <div class="text-xs text-slate-500 dark:text-slate-400">Send email notifications to users</div>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input v-model="editData.email_notifications" type="checkbox" class="sr-only peer" />
              <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
            </label>
          </div>
          <div class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-700/50 rounded-lg">
            <div>
              <div class="text-sm font-medium text-slate-900 dark:text-slate-100">SMS Notifications</div>
              <div class="text-xs text-slate-500 dark:text-slate-400">Send SMS notifications to users</div>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input v-model="editData.sms_notifications" type="checkbox" class="sr-only peer" />
              <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
            </label>
          </div>
        </div>

        <div v-if="error" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">{{ error }}</div>
      </div>

      <template #footer>
        <div class="flex gap-3">
          <button
            @click="showEditOverlay = false"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
          >
            Cancel
          </button>
          <button
            @click="saveSettings"
            :disabled="saving"
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors disabled:opacity-50"
          >
            {{ saving ? 'Saving...' : 'Save Changes' }}
          </button>
        </div>
      </template>
    </SlideOverlay>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Settings, RotateCcw, Save, Pencil, RefreshCw } from 'lucide-vue-next'
import { useConfirmStore } from '@/stores/confirm'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useSettings } from '@/modules/tenant/composables/useSettings.js'

const confirmStore = useConfirmStore()

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Settings', to: '/dashboard/settings' },
  { label: 'General Settings' }
]

const defaults = {
  company_name: '',
  contact_email: '',
  contact_phone: '',
  website: '',
  address: '',
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

const showEditOverlay = ref(false)
const editSection = ref('all')
const editData = ref({ ...defaults })

const {
  loading, saving, error, formData,
  fetchSettings, saveSettings: saveBase, resetToDefaults: resetForm
} = useSettings('/settings/general', defaults)

const editOverlayTitle = computed(() => {
  const titles = {
    all: 'Edit All Settings',
    company: 'Edit Company Information',
    system: 'Edit System Preferences',
    session: 'Edit Session Settings',
    features: 'Edit Feature Toggles'
  }
  return titles[editSection.value] || 'Edit Settings'
})

const openEditOverlay = (section) => {
  editSection.value = section
  editData.value = { ...formData.value }
  error.value = ''
  showEditOverlay.value = true
}

const saveSettings = async () => {
  const result = await saveBase(editData.value)
  if (result.success) {
    formData.value = { ...editData.value }
    showEditOverlay.value = false
  }
}

const resetToDefaults = async () => {
  const confirmed = await confirmStore.open({
    title: 'Reset to Defaults',
    message: 'Reset all settings to defaults?',
    confirmText: 'Reset',
    cancelText: 'Cancel',
    variant: 'warning',
  })
  if (confirmed) editData.value = { ...defaults }
}

onMounted(fetchSettings)
</script>
