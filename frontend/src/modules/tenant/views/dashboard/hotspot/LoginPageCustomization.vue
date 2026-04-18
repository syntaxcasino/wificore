<script setup>
import { ref, reactive } from 'vue'
import { Plus, Palette, Check, Eye, Edit2, Trash2 } from 'lucide-vue-next'
import { useConfirmStore } from '@/stores/confirm'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseInput from '@/modules/common/components/base/BaseInput.vue'
import BaseTextarea from '@/modules/common/components/base/BaseTextarea.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'

const confirmStore = useConfirmStore()

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Hotspot', to: '/dashboard/hotspot' },
  { label: 'Branding' }
]

const showCreateOverlay = ref(false)
const showPreviewOverlay = ref(false)
const selectedTemplate = ref(null)
const saving = ref(false)

const form = reactive({
  name: '',
  companyName: '',
  welcomeMessage: '',
  primaryColor: '#3B82F6',
  secondaryColor: '#10B981'
})

const templates = ref([
  {
    id: 1,
    name: 'Default Template',
    companyName: 'Traidnet Solutions',
    welcomeMessage: 'Welcome! Please login to access the internet.',
    primaryColor: '#3B82F6',
    secondaryColor: '#10B981',
    isActive: true,
    createdAt: '2024-01-15'
  }
])

const openCreateOverlay = () => {
  form.name = ''
  form.companyName = ''
  form.welcomeMessage = ''
  form.primaryColor = '#3B82F6'
  form.secondaryColor = '#10B981'
  showCreateOverlay.value = true
}

const closeCreateOverlay = () => {
  showCreateOverlay.value = false
}

const handleSubmit = async () => {
  saving.value = true
  try {
    await new Promise(resolve => setTimeout(resolve, 1000))
    templates.value.push({
      id: Date.now(),
      name: form.name,
      companyName: form.companyName,
      welcomeMessage: form.welcomeMessage,
      primaryColor: form.primaryColor,
      secondaryColor: form.secondaryColor,
      isActive: false,
      createdAt: new Date().toISOString().split('T')[0]
    })
    closeCreateOverlay()
  } finally {
    saving.value = false
  }
}

const activateTemplate = (id) => {
  templates.value.forEach(t => t.isActive = t.id === id)
}

const deleteTemplate = async (id) => {
  const confirmed = await confirmStore.open({ title: 'Delete Template', message: 'Delete this template?', confirmText: 'Delete', cancelText: 'Cancel', variant: 'danger' })
  if (confirmed) templates.value = templates.value.filter(t => t.id !== id)
}

const previewTemplate = (template) => {
  selectedTemplate.value = template
  showPreviewOverlay.value = true
}
</script>

<template>
  <DataViewContainer
    title="Branding Templates"
    subtitle="Manage hotspot login page templates"
    color-theme="cyan"
    :breadcrumbs="breadcrumbs"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
      </svg>
    </template>

    <template #actions>
      <BaseButton @click="openCreateOverlay" variant="primary">
        <Plus class="w-4 h-4 mr-1" />
        Create Template
      </BaseButton>
    </template>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <BaseCard v-for="template in templates" :key="template.id" class="relative">
        <div class="p-6">
          <div v-if="template.isActive" class="absolute top-4 right-4">
            <BaseBadge variant="success">Active</BaseBadge>
          </div>

          <div 
            class="h-32 rounded-lg mb-4 flex items-center justify-center"
            :style="{ background: `linear-gradient(135deg, ${template.primaryColor} 0%, ${template.secondaryColor} 100%)` }"
          >
            <span class="text-white font-semibold">{{ template.companyName }}</span>
          </div>

          <h3 class="font-semibold text-slate-900 mb-1">{{ template.name }}</h3>
          <p class="text-sm text-slate-500 mb-4">{{ template.welcomeMessage.substring(0, 50) }}...</p>

          <div class="flex items-center justify-between">
            <div class="flex space-x-2">
              <button @click="previewTemplate(template)" class="p-2 text-slate-500 hover:text-primary-600 hover:bg-slate-100 rounded-lg">
                <Eye class="w-4 h-4" />
              </button>
              <button class="p-2 text-slate-500 hover:text-primary-600 hover:bg-slate-100 rounded-lg">
                <Edit2 class="w-4 h-4" />
              </button>
              <button @click="deleteTemplate(template.id)" class="p-2 text-slate-500 hover:text-red-600 hover:bg-red-50 rounded-lg">
                <Trash2 class="w-4 h-4" />
              </button>
            </div>
            <button
              v-if="!template.isActive"
              @click="activateTemplate(template.id)"
              class="px-3 py-1.5 text-sm font-medium text-primary-600 bg-primary-50 hover:bg-primary-100 rounded-lg"
            >
              Activate
            </button>
            <span v-else class="flex items-center text-sm text-green-600">
              <Check class="w-4 h-4 mr-1" />
              Active
            </span>
          </div>
        </div>
      </BaseCard>
    </div>

    <SlideOverlay v-model="showCreateOverlay" title="Create Branding Template" width="60%">
      <div class="space-y-6">
        <BaseInput v-model="form.name" label="Template Name" placeholder="e.g., Holiday Theme" required />
        <BaseInput v-model="form.companyName" label="Company Name" placeholder="Your company name" required />
        <BaseTextarea v-model="form.welcomeMessage" label="Welcome Message" placeholder="Welcome message" rows="3" />

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Primary Color</label>
            <div class="flex items-center space-x-2">
              <input v-model="form.primaryColor" type="color" class="w-10 h-10 rounded-lg border border-slate-300 cursor-pointer" />
              <span class="text-sm text-slate-600 dark:text-slate-400">{{ form.primaryColor }}</span>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Secondary Color</label>
            <div class="flex items-center space-x-2">
              <input v-model="form.secondaryColor" type="color" class="w-10 h-10 rounded-lg border border-slate-300 cursor-pointer" />
              <span class="text-sm text-slate-600 dark:text-slate-400">{{ form.secondaryColor }}</span>
            </div>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-2">Preview</label>
          <div 
            class="h-32 rounded-lg flex items-center justify-center"
            :style="{ background: `linear-gradient(135deg, ${form.primaryColor} 0%, ${form.secondaryColor} 100%)` }"
          >
            <div class="text-center text-white">
              <h4 class="font-bold text-lg">{{ form.companyName || 'Company' }}</h4>
              <p class="text-sm opacity-90">{{ form.welcomeMessage || 'Welcome' }}</p>
            </div>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="flex gap-3">
          <button
            @click="closeCreateOverlay"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
          >
            Cancel
          </button>
          <button
            @click="handleSubmit"
            :disabled="saving"
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors disabled:opacity-50"
          >
            {{ saving ? 'Creating...' : 'Create Template' }}
          </button>
        </div>
      </template>
    </SlideOverlay>

    <SlideOverlay v-model="showPreviewOverlay" title="Preview" width="60%">
      <div v-if="selectedTemplate" class="space-y-4">
        <div 
          class="h-48 rounded-lg flex items-center justify-center"
          :style="{ background: `linear-gradient(135deg, ${selectedTemplate.primaryColor} 0%, ${selectedTemplate.secondaryColor} 100%)` }"
        >
          <div class="text-center text-white">
            <h4 class="font-bold text-xl">{{ selectedTemplate.companyName }}</h4>
            <p class="text-sm opacity-90 mt-2">{{ selectedTemplate.welcomeMessage }}</p>
          </div>
        </div>
      </div>
      <template #footer>
        <div class="flex gap-3">
          <button
            @click="showPreviewOverlay = false"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
          >
            Close
          </button>
        </div>
      </template>
    </SlideOverlay>
  </DataViewContainer>
</template>
