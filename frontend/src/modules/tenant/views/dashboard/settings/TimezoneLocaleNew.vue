<template>
  <PageContainer>
    <PageHeader title="Timezone & Locale" subtitle="Configure regional and display settings" icon="Globe" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="fetchSettings" variant="ghost" :loading="loading">
          <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': loading }" />
        </BaseButton>
        <BaseButton @click="openEditOverlay" variant="primary">
          <Pencil class="w-4 h-4 mr-1" />
          Edit Settings
        </BaseButton>
      </template>
    </PageHeader>

    <PageContent>
      <div class="space-y-6">
        <BaseCard>
          <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Regional Settings</h3>
            <button @click="openEditOverlay" class="text-sm text-blue-600 hover:text-blue-700 font-medium">Edit</button>
          </div>
          <div class="p-6">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-x-8 gap-y-5">
              <div>
                <div class="text-xs text-slate-500 dark:text-slate-400">Timezone</div>
                <div class="text-sm font-medium text-slate-900 mt-0.5">{{ formData.timezone }}</div>
              </div>
              <div>
                <div class="text-xs text-slate-500 dark:text-slate-400">Language</div>
                <div class="text-sm font-medium text-slate-900 mt-0.5">{{ languageLabel }}</div>
              </div>
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
                <div class="text-xs text-slate-500 dark:text-slate-400">First Day of Week</div>
                <div class="text-sm font-medium text-slate-900 mt-0.5">{{ formData.first_day_of_week === '0' ? 'Sunday' : 'Monday' }}</div>
              </div>
            </div>
          </div>
        </BaseCard>
      </div>
    </PageContent>

    <!-- Edit Overlay -->
    <SlideOverlay v-model="showEditOverlay" title="Edit Timezone & Locale" subtitle="Update regional display settings" icon="Globe" width="60%">
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Timezone *</label>
          <select v-model="editData.timezone" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500">
            <option value="Africa/Nairobi">Africa/Nairobi (EAT)</option>
            <option value="Africa/Lagos">Africa/Lagos (WAT)</option>
            <option value="Africa/Johannesburg">Africa/Johannesburg (SAST)</option>
            <option value="Africa/Cairo">Africa/Cairo (EET)</option>
            <option value="UTC">UTC</option>
            <option value="Europe/London">Europe/London (GMT)</option>
            <option value="America/New_York">America/New_York (EST)</option>
            <option value="Asia/Dubai">Asia/Dubai (GST)</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Language *</label>
          <select v-model="editData.language" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500">
            <option value="en">English</option>
            <option value="sw">Swahili</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Currency *</label>
          <select v-model="editData.currency" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500">
            <option value="KES">KES - Kenyan Shilling</option>
            <option value="USD">USD - US Dollar</option>
            <option value="EUR">EUR - Euro</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Date Format *</label>
          <select v-model="editData.date_format" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500">
            <option value="DD/MM/YYYY">DD/MM/YYYY</option>
            <option value="MM/DD/YYYY">MM/DD/YYYY</option>
            <option value="YYYY-MM-DD">YYYY-MM-DD</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Time Format *</label>
          <select v-model="editData.time_format" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500">
            <option value="12">12 Hour</option>
            <option value="24">24 Hour</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">First Day of Week</label>
          <select v-model="editData.first_day_of_week" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500">
            <option value="0">Sunday</option>
            <option value="1">Monday</option>
          </select>
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
import { Globe, Save, Pencil, RefreshCw } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useSettings } from '@/modules/tenant/composables/useSettings.js'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Settings', to: '/dashboard/settings' },
  { label: 'Timezone & Locale' }
]

const defaults = {
  timezone: 'Africa/Nairobi',
  language: 'en',
  date_format: 'DD/MM/YYYY',
  time_format: '24',
  currency: 'KES',
  first_day_of_week: '1'
}

const showEditOverlay = ref(false)
const editData = ref({ ...defaults })

const {
  loading, saving, error, formData,
  fetchSettings, saveSettings
} = useSettings('/settings/locale', defaults)

const languageLabel = computed(() => formData.value.language === 'en' ? 'English' : 'Swahili')

const openEditOverlay = () => {
  editData.value = { ...formData.value }
  error.value = ''
  showEditOverlay.value = true
}

const handleSave = async () => {
  const result = await saveSettings(editData.value)
  if (result.success) {
    formData.value = { ...editData.value }
    showEditOverlay.value = false
  }
}

onMounted(fetchSettings)
</script>
