<template>
  <PageContainer>
    <PageHeader
      title="Base Components Showcase"
      subtitle="Testing all base components and layout templates"
      icon="Layout"
      :breadcrumbs="[
        { label: 'Dashboard', to: '/dashboard' },
        { label: 'Test', to: '/test' },
        { label: 'Components' }
      ]"
    >
      <template #actions>
        <BaseButton variant="secondary" size="sm" @click="refreshPage">
          <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          Refresh
        </BaseButton>
        <BaseButton variant="primary" size="sm" @click="showModal = true">
          <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Add Item
        </BaseButton>
      </template>
    </PageHeader>

    <PageContent>
      <div class="space-y-8">
        <!-- Alerts Section -->
        <BaseCard title="Alerts" subtitle="Different alert variants">
          <div class="space-y-3">
            <BaseAlert variant="success" title="Success" message="This is a success alert message" dismissible />
            <BaseAlert variant="info" title="Information" message="This is an info alert message" dismissible />
            <BaseAlert variant="warning" title="Warning" message="This is a warning alert message" dismissible />
            <BaseAlert variant="danger" title="Error" message="This is an error alert message" dismissible />
          </div>
        </BaseCard>

        <!-- Buttons Section -->
        <BaseCard title="Buttons" subtitle="Different button variants and sizes">
          <div class="space-y-4">
            <div class="flex flex-wrap gap-3">
              <BaseButton variant="primary">Primary</BaseButton>
              <BaseButton variant="secondary">Secondary</BaseButton>
              <BaseButton variant="success">Success</BaseButton>
              <BaseButton variant="warning">Warning</BaseButton>
              <BaseButton variant="danger">Danger</BaseButton>
              <BaseButton variant="ghost">Ghost</BaseButton>
            </div>
            <div class="flex flex-wrap gap-3">
              <BaseButton variant="primary" size="sm">Small</BaseButton>
              <BaseButton variant="primary" size="md">Medium</BaseButton>
              <BaseButton variant="primary" size="lg">Large</BaseButton>
            </div>
            <div class="flex flex-wrap gap-3">
              <BaseButton variant="primary" :loading="true">Loading</BaseButton>
              <BaseButton variant="primary" :disabled="true">Disabled</BaseButton>
            </div>
          </div>
        </BaseCard>

        <!-- Badges Section -->
        <BaseCard title="Badges" subtitle="Status badges with different variants">
          <div class="flex flex-wrap gap-3">
            <BaseBadge variant="default">Default</BaseBadge>
            <BaseBadge variant="success" dot pulse>Online</BaseBadge>
            <BaseBadge variant="warning">Pending</BaseBadge>
            <BaseBadge variant="danger">Error</BaseBadge>
            <BaseBadge variant="info">Info</BaseBadge>
            <BaseBadge variant="purple">Purple</BaseBadge>
            <BaseBadge variant="pink">Pink</BaseBadge>
          </div>
        </BaseCard>

        <!-- Form Inputs Section -->
        <BaseCard title="Form Inputs" subtitle="Input fields with validation">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <BaseInput
              v-model="form.name"
              label="Name"
              placeholder="Enter your name"
              hint="This is a hint text"
            />
            <BaseInput
              v-model="form.email"
              type="email"
              label="Email"
              placeholder="Enter your email"
              required
            />
            <BaseInput
              v-model="form.error"
              label="With Error"
              placeholder="This has an error"
              error="This field is required"
            />
            <BaseSelect v-model="form.status" label="Status" placeholder="Select status">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="pending">Pending</option>
            </BaseSelect>
          </div>
        </BaseCard>

        <!-- Search Section -->
        <BaseCard title="Search" subtitle="Search input with clear button">
          <div class="max-w-md">
            <BaseSearch v-model="searchQuery" placeholder="Search anything..." />
            <p v-if="searchQuery" class="mt-2 text-sm text-slate-600">
              Searching for: <strong>{{ searchQuery }}</strong>
            </p>
          </div>
        </BaseCard>

        <!-- Loading States Section -->
        <BaseCard title="Loading States" subtitle="Different loading indicators">
          <div class="space-y-6">
            <div>
              <h4 class="text-sm font-medium text-slate-700 mb-3">Spinner</h4>
              <BaseLoading type="spinner" text="Loading data..." />
            </div>
            <div>
              <h4 class="text-sm font-medium text-slate-700 mb-3">Dots</h4>
              <BaseLoading type="dots" />
            </div>
            <div>
              <h4 class="text-sm font-medium text-slate-700 mb-3">Skeleton</h4>
              <BaseLoading type="skeleton" :rows="3" />
            </div>
          </div>
        </BaseCard>

        <!-- Empty State Section -->
        <BaseCard title="Empty State" subtitle="When there's no data to display">
          <BaseEmpty
            title="No Data Found"
            description="There are no items to display at the moment. Get started by adding your first item."
            icon="Inbox"
            actionText="Add Item"
            actionIcon="Plus"
            @action="showModal = true"
          />
        </BaseCard>

        <!-- Pagination Section -->
        <BaseCard title="Pagination" subtitle="Pagination controls">
          <div class="flex justify-center">
            <BasePagination
              v-model="currentPage"
              :total-pages="10"
              :items-per-page="itemsPerPage"
              @update:items-per-page="itemsPerPage = $event"
            />
          </div>
          <p class="text-center mt-3 text-sm text-slate-600">
            Current page: {{ currentPage }}, Items per page: {{ itemsPerPage }}
          </p>
        </BaseCard>

        <!-- Data Table Example -->
        <BaseCard title="Data Table Example" subtitle="Sample table with base components">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase">Name</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase">Email</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase">Status</th>
                  <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="user in sampleUsers" :key="user.id" class="border-b border-slate-100 hover:bg-blue-50/50 transition-colors">
                  <td class="px-6 py-4 text-sm font-medium text-slate-900">{{ user.name }}</td>
                  <td class="px-6 py-4 text-sm text-slate-600">{{ user.email }}</td>
                  <td class="px-6 py-4">
                    <BaseBadge :variant="user.status === 'active' ? 'success' : 'warning'">
                      {{ user.status }}
                    </BaseBadge>
                  </td>
                  <td class="px-6 py-4 text-right">
                    <BaseButton variant="ghost" size="sm">Edit</BaseButton>
                    <BaseButton variant="ghost" size="sm" class="text-red-600">Delete</BaseButton>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>
      </div>
    </PageContent>

    <PageFooter>
      <div class="text-sm text-slate-600">
        Showing 1 to 10 of 100 items
      </div>
      <BasePagination
        v-model="currentPage"
        :total-pages="10"
        :show-items-per-page="false"
      />
    </PageFooter>

    <!-- Modal Example -->
    <BaseModal v-model="showModal" title="Example Modal" size="md">
      <div class="space-y-4">
        <p class="text-slate-600">This is an example modal using the BaseModal component.</p>
        <BaseInput v-model="form.modalInput" label="Input Field" placeholder="Enter something..." />
      </div>
      <template #footer>
        <BaseButton variant="secondary" @click="showModal = false">Cancel</BaseButton>
        <BaseButton variant="primary" @click="handleSave">Save Changes</BaseButton>
      </template>
    </BaseModal>
  </PageContainer>
</template>

<script setup>
import { ref } from 'vue'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import PageFooter from '@/modules/common/components/layout/templates/PageFooter.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import BaseInput from '@/modules/common/components/base/BaseInput.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseSearch from '@/modules/common/components/base/BaseSearch.vue'
import BasePagination from '@/modules/common/components/base/BasePagination.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'
import BaseEmpty from '@/modules/common/components/base/BaseEmpty.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'
import BaseModal from '@/modules/common/components/base/BaseModal.vue'

// State
const showModal = ref(false)
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)

const form = ref({
  name: '',
  email: '',
  error: '',
  status: '',
  modalInput: ''
})

const sampleUsers = ref([
  { id: 1, name: 'John Doe', email: 'john@example.com', status: 'active' },
  { id: 2, name: 'Jane Smith', email: 'jane@example.com', status: 'active' },
  { id: 3, name: 'Bob Johnson', email: 'bob@example.com', status: 'inactive' },
  { id: 4, name: 'Alice Williams', email: 'alice@example.com', status: 'active' },
  { id: 5, name: 'Charlie Brown', email: 'charlie@example.com', status: 'inactive' }
])

// Methods
const refreshPage = () => {
  console.log('Refreshing page...')
}

const handleSave = () => {
  console.log('Saving...', form.value)
  showModal.value = false
}
</script>
