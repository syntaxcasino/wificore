<template>
  <DataViewContainer
    title="Branding"
    subtitle="Create and manage branding templates for customer communications"
    color-theme="violet"
    :loading="loading"
    @refresh="fetchTemplates"
  >
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
      </svg>
    </template>

    <template #actions>
      <BaseButton @click="openCreateOverlay" variant="primary" size="sm">
        <Plus class="w-4 h-4 mr-1" />
        Create Template
      </BaseButton>
    </template>

    <template #stats>
      <div class="flex items-center gap-2 px-3 py-2 bg-slate-50 rounded-lg border border-slate-200">
        <span class="w-2 h-2 bg-violet-500 rounded-full"></span>
        <span class="text-xs font-semibold text-slate-700">{{ templates.length }} Templates</span>
        <span class="text-slate-300">|</span>
        <span class="text-xs font-semibold text-emerald-600">{{ activeTemplate ? '1 Active' : '0 Active' }}</span>
      </div>
    </template>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-center">{{ error }}</p>
      <button @click="fetchTemplates" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">
        Retry
      </button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="templates.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
      <!-- Active Template Banner -->
      <div v-if="activeTemplate" class="mb-4 bg-gradient-to-r from-emerald-50 to-green-50 border border-emerald-200 rounded-lg p-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-emerald-100 rounded-lg flex items-center justify-center">
              <CheckCircle class="w-5 h-5 text-emerald-600" />
            </div>
            <div>
              <div class="text-sm font-semibold text-emerald-900">Active Template</div>
              <div class="text-xs text-emerald-700">{{ activeTemplate.name }}</div>
            </div>
          </div>
          <button @click="openViewOverlay(activeTemplate)" class="px-3 py-1.5 text-xs font-medium text-emerald-700 bg-emerald-100 rounded-lg hover:bg-emerald-200 transition-colors">
            View
          </button>
        </div>
      </div>

      <!-- Templates Grid -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 overflow-y-auto flex-1 min-h-0">
        <div
          v-for="template in templates"
          :key="template.id"
          class="bg-white border border-slate-200 shadow-sm overflow-hidden flex-colver:shadow-md transition-shadow"
          :class="template.is_active ? 'ring-2 ring-emerald-500' : ''"
        >
          <!-- Template Preview -->
          <div class="h-32 bg-slate-100 relative">
            <div v-if="template.logo_url" class="absolute inset-0 flex items-center justify-center p-4">
              <img :src="template.logo_url" alt="Logo" class="max-h-16 max-w-full object-contain" />
            </div>
            <div v-else class="absolute inset-0 flex items-center justify-center">
              <ImageIcon class="w-12 h-12 text-slate-300" />
            </div>
            <div v-if="template.is_active" class="absolute top-2 right-2">
              <span class="px-2 py-0.5 bg-emerald-500 text-white text-xs font-medium rounded-full">Active</span>
            </div>
          </div>

          <!-- Template Info -->
          <div class="p-4">
            <h4 class="font-semibold text-slate-900 truncate">{{ template.name }}</h4>
            <p class="text-xs text-slate-500 mt-1 line-clamp-2">{{ template.description || 'No description' }}</p>

            <div class="flex items-center gap-2 mt-3 text-xs text-slate-500 dark:text-slate-400">
              <div class="flex items-center gap-1">
                <div class="w-3 h-3 rounded-full border" :style="{ backgroundColor: template.primary_color }"></div>
                <span>Primary</span>
              </div>
              <div class="flex items-center gap-1">
                <div class="w-3 h-3 rounded-full border" :style="{ backgroundColor: template.secondary_color }"></div>
                <span>Secondary</span>
              </div>
            </div>

            <div class="flex items-center justify-between mt-4 pt-3 border-t border-slate-100">
              <span class="text-xs text-slate-400">{{ formatDate(template.created_at) }}</span>
              <div class="flex items-center gap-1">
                <button
                  v-if="!template.is_active"
                  @click="activateTemplate(template)"
                  class="px-2 py-1 text-xs font-medium text-emerald-700 bg-emerald-50 rounded hover:bg-emerald-100 transition-colors"
                >
                  Activate
                </button>
                <button @click="openViewOverlay(template)" class="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors" title="View">
                  <Eye class="w-4 h-4" />
                </button>
                <button @click="openEditOverlay(template)" class="p-1.5 text-slate-400 hover:text-amber-600 hover:bg-amber-50 rounded transition-colors" title="Edit">
                  <Pencil class="w-4 h-4" />
                </button>
                <button @click="handleDelete(template)" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors" title="Delete">
                  <Trash2 class="w-4 h-4" />
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      title="No Branding Templates"
      description="Create branding templates to customize your customer-facing materials"
      icon="palette"
      color-theme="violet"
      add-text="Create First Template"
      @add="openCreateOverlay"
    />
  </DataViewContainer>

  <!-- Create Overlay -->
  <SlideOverlay v-model="showCreateOverlay" title="Create Branding Template" subtitle="Design your branding template" icon="Palette" width="60%">
    <div class="p-6 space-y-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Template Name *</label>
        <input v-model="form.name" type="text" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-violet-500 text-sm" placeholder="e.g. Corporate Branding" />
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Description</label>
        <textarea v-model="form.description" rows="2" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-violet-500 text-sm resize-none" placeholder="Brief description of this branding template..."></textarea>
      </div>

      <!-- Colors -->
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Primary Color</label>
          <div class="flex items-center gap-2">
            <input v-model="form.primary_color" type="color" class="w-10 h-10 rounded-lg border border-slate-300 cursor-pointer" />
            <input v-model="form.primary_color" type="text" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm" placeholder="#3B82F6" />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Secondary Color</label>
          <div class="flex items-center gap-2">
            <input v-model="form.secondary_color" type="color" class="w-10 h-10 rounded-lg border border-slate-300 cursor-pointer" />
            <input v-model="form.secondary_color" type="text" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm" placeholder="#10B981" />
          </div>
        </div>
      </div>

      <!-- Logo Upload -->
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Logo</label>
        <div class="border-2 border-dashed border-slate-300 rounded-lg p-4 text-center hover:border-violet-400 transition-colors">
          <div v-if="form.logo_preview" class="mb-3">
            <img :src="form.logo_preview" alt="Logo preview" class="max-h-24 mx-auto object-contain" />
          </div>
          <div v-else class="mb-3">
            <ImageIcon class="w-12 h-12 text-slate-300 mx-auto" />
          </div>
          <input type="file" accept="image/*" @change="handleLogoUpload" class="hidden" id="logo-upload" />
          <label for="logo-upload" class="cursor-pointer text-sm text-violet-600 hover:text-violet-700 font-medium">
            {{ form.logo_preview ? 'Change Logo' : 'Upload Logo' }}
          </label>
          <p class="text-xs text-slate-400 mt-1">PNG, JPG, SVG up to 2MB</p>
        </div>
      </div>

      <!-- Contact Info -->
      <div class="border-t border-slate-200 pt-4">
        <h4 class="text-sm font-semibold text-slate-700 mb-3">Contact Information</h4>
        <div class="space-y-3">
          <input v-model="form.company_name" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" placeholder="Company Name" />
          <input v-model="form.contact_phone" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" placeholder="Phone Number" />
          <input v-model="form.contact_email" type="email" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" placeholder="Email Address" />
          <input v-model="form.website" type="url" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" placeholder="Website URL" />
        </div>
      </div>

      <div class="flex items-center gap-4">
        <label class="flex items-center gap-2 cursor-pointer">
          <input v-model="form.is_active" type="checkbox" class="w-4 h-4 text-violet-600 border-slate-300 rounded focus:ring-violet-500" />
          <span class="text-sm text-slate-700">Set as active template</span>
        </label>
      </div>

      <div v-if="formError" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">{{ formError }}</div>
    </div>

    <template #footer>
      <div class="flex gap-3">
        <button @click="showCreateOverlay = false" class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600">Cancel</button>
        <button @click="handleCreate" :disabled="formSubmitting" class="flex-1 px-4 py-2 text-sm font-medium text-white bg-violet-600 hover:bg-violet-700 rounded-lg transition-colors disabled:opacity-50">
          {{ formSubmitting ? 'Creating...' : 'Create Template' }}
        </button>
      </div>
    </template>
  </SlideOverlay>

  <!-- View Overlay -->
  <SlideOverlay v-model="showViewOverlay" title="Branding Template" :subtitle="selectedTemplate?.name || ''" icon="Eye" width="60%">
    <div v-if="selectedTemplate" class="p-6 space-y-6">
      <!-- Preview Card -->
      <div class="border border-slate-200 rounded-lg overflow-hidden">
        <div class="p-4" :style="{ backgroundColor: selectedTemplate.primary_color + '10' }">
          <div v-if="selectedTemplate.logo_url" class="mb-4">
            <img :src="selectedTemplate.logo_url" alt="Logo" class="h-16 object-contain" />
          </div>
          <div v-else class="w-16 h-16 rounded-lg flex items-center justify-center mb-4" :style="{ backgroundColor: selectedTemplate.primary_color }">
            <span class="text-white font-bold text-xl">{{ selectedTemplate.company_name?.charAt(0) || 'B' }}</span>
          </div>
          <h3 class="font-semibold" :style="{ color: selectedTemplate.primary_color }">{{ selectedTemplate.company_name || 'Your Company' }}</h3>
          <p v-if="selectedTemplate.description" class="text-sm text-slate-600 mt-1">{{ selectedTemplate.description }}</p>
        </div>
        <div class="p-4 bg-slate-50">
          <div class="space-y-2 text-sm">
            <div v-if="selectedTemplate.contact_phone" class="flex items-center gap-2">
              <Phone class="w-4 h-4 text-slate-400" />
              <span>{{ selectedTemplate.contact_phone }}</span>
            </div>
            <div v-if="selectedTemplate.contact_email" class="flex items-center gap-2">
              <Mail class="w-4 h-4 text-slate-400" />
              <span>{{ selectedTemplate.contact_email }}</span>
            </div>
            <div v-if="selectedTemplate.website" class="flex items-center gap-2">
              <Globe class="w-4 h-4 text-slate-400" />
              <span>{{ selectedTemplate.website }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Colors -->
      <div>
        <h4 class="text-sm font-semibold text-slate-700 mb-2">Brand Colors</h4>
        <div class="flex gap-4">
          <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-lg border" :style="{ backgroundColor: selectedTemplate.primary_color }"></div>
            <span class="text-sm text-slate-600 dark:text-slate-400">Primary</span>
          </div>
          <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-lg border" :style="{ backgroundColor: selectedTemplate.secondary_color }"></div>
            <span class="text-sm text-slate-600 dark:text-slate-400">Secondary</span>
          </div>
        </div>
      </div>

      <!-- Usage Info -->
      <div class="border-t border-slate-200 pt-4">
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <span class="text-slate-500">Created:</span>
            <span class="ml-2">{{ formatDate(selectedTemplate.created_at) }}</span>
          </div>
          <div>
            <span class="text-slate-500">Status:</span>
            <span :class="selectedTemplate.is_active ? 'text-emerald-600 font-medium' : 'text-slate-600'" class="ml-2">
              {{ selectedTemplate.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <template #footer>
      <div class="flex gap-3">
        <button v-if="!selectedTemplate?.is_active" @click="activateTemplate(selectedTemplate)" class="flex-1 px-4 py-2 text-sm font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg hover:bg-emerald-100 transition-colors">
          Activate
        </button>
        <button @click="openEditOverlay(selectedTemplate)" class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors">
          <Pencil class="w-4 h-4 inline mr-1" /> Edit
        </button>
        <button @click="showViewOverlay = false" class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600">Close</button>
      </div>
    </template>
  </SlideOverlay>

  <!-- Edit Overlay -->
  <SlideOverlay v-model="showEditOverlay" title="Edit Branding Template" subtitle="Update your branding" icon="Pencil" width="60%">
    <div class="p-6 space-y-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Template Name *</label>
        <input v-model="editForm.name" type="text" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-violet-500 text-sm" />
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Description</label>
        <textarea v-model="editForm.description" rows="2" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-violet-500 text-sm resize-none"></textarea>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Primary Color</label>
          <div class="flex items-center gap-2">
            <input v-model="editForm.primary_color" type="color" class="w-10 h-10 rounded-lg border border-slate-300 cursor-pointer" />
            <input v-model="editForm.primary_color" type="text" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm" />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Secondary Color</label>
          <div class="flex items-center gap-2">
            <input v-model="editForm.secondary_color" type="color" class="w-10 h-10 rounded-lg border border-slate-300 cursor-pointer" />
            <input v-model="editForm.secondary_color" type="text" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm" />
          </div>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Logo</label>
        <div class="border-2 border-dashed border-slate-300 rounded-lg p-4 text-center hover:border-violet-400 transition-colors">
          <div v-if="editForm.logo_preview || editForm.logo_url" class="mb-3">
            <img :src="editForm.logo_preview || editForm.logo_url" alt="Logo preview" class="max-h-24 mx-auto object-contain" />
          </div>
          <input type="file" accept="image/*" @change="handleEditLogoUpload" class="hidden" id="edit-logo-upload" />
          <label for="edit-logo-upload" class="cursor-pointer text-sm text-violet-600 hover:text-violet-700 font-medium">
            Change Logo
          </label>
        </div>
      </div>

      <div class="border-t border-slate-200 pt-4">
        <h4 class="text-sm font-semibold text-slate-700 mb-3">Contact Information</h4>
        <div class="space-y-3">
          <input v-model="editForm.company_name" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" placeholder="Company Name" />
          <input v-model="editForm.contact_phone" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" placeholder="Phone Number" />
          <input v-model="editForm.contact_email" type="email" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" placeholder="Email Address" />
          <input v-model="editForm.website" type="url" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" placeholder="Website URL" />
        </div>
      </div>

      <div v-if="formError" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">{{ formError }}</div>
    </div>

    <template #footer>
      <div class="flex gap-3">
        <button @click="showEditOverlay = false" class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600">Cancel</button>
        <button @click="handleUpdate" :disabled="formSubmitting" class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors disabled:opacity-50">
          {{ formSubmitting ? 'Saving...' : 'Save Changes' }}
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { onMounted } from 'vue'
import { Palette, Plus, AlertCircle, Eye, Pencil, Trash2, CheckCircle, Image as ImageIcon, Phone, Mail, Globe } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useBranding } from '@/modules/tenant/composables/useBranding.js'

const {
  loading, error, templates, selectedTemplate, formSubmitting, formError,
  showCreateOverlay, showViewOverlay, showEditOverlay,
  form, editForm, activeTemplate,
  formatDate, handleLogoUpload,
  fetchTemplates, openCreateOverlay, openViewOverlay, openEditOverlay,
  handleCreate, handleUpdate, activateTemplate, handleDelete
} = useBranding()

const handleCreateLogoUpload = (e) => handleLogoUpload(e, 'create')
const handleEditLogoUpload = (e) => handleLogoUpload(e, 'edit')

onMounted(fetchTemplates)
</script>

<style scoped>
/* Scrollbar — no Tailwind equivalent for ::-webkit-scrollbar pseudo-elements */
::-webkit-scrollbar        { width: 8px; height: 8px; }
::-webkit-scrollbar-track  { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb  { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
:global(.dark) ::-webkit-scrollbar-track { background: #1e293b; }
:global(.dark) ::-webkit-scrollbar-thumb { background: #475569; }
</style>
