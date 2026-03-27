<template>
  <DataViewContainer
    title="Support Tickets"
    subtitle="Create and manage support tickets"
    icon="MessageSquare"
    :breadcrumbs="breadcrumbs"
  >
    <template #actions>
      <BaseButton @click="openCreateOverlay" variant="primary">
        <Plus class="w-4 h-4 mr-1" />
        New Ticket
      </BaseButton>
    </template>

    <div class="flex flex-col items-center justify-center py-16">
      <div class="text-center max-w-md">
        <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <MessageSquare class="w-8 h-8 text-blue-600" />
        </div>
        <h3 class="text-lg font-semibold text-slate-900 mb-2">Create Support Tickets</h3>
        <p class="text-slate-500 mb-6">Click the "New Ticket" button to create a support ticket for customers.</p>
        <BaseButton @click="openCreateOverlay" variant="primary">
          <Plus class="w-4 h-4 mr-1" />
          Create New Ticket
        </BaseButton>
      </div>
    </div>

    <!-- Create Ticket SlideOverlay -->
    <SlideOverlay
      v-model="showCreateOverlay"
      title="New Support Ticket"
      subtitle="Create a new support ticket for a customer"
      icon="Plus"
      width="480px"
      @close="closeCreateOverlay"
    >
      <div class="p-6 space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Subject *</label>
          <input
            v-model="formData.subject"
            type="text"
            required
            class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            placeholder="Brief description of the issue"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Category *</label>
          <BaseSelect v-model="formData.category" required class="w-full">
            <option value="">Select category...</option>
            <option value="technical">Technical Support</option>
            <option value="billing">Billing</option>
            <option value="general">General Inquiry</option>
            <option value="complaint">Complaint</option>
          </BaseSelect>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Priority *</label>
          <BaseSelect v-model="formData.priority" required class="w-full">
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </BaseSelect>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Description *</label>
          <textarea
            v-model="formData.description"
            rows="6"
            required
            class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            placeholder="Detailed description of the issue..."
          ></textarea>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Customer Name</label>
          <input
            v-model="formData.customer_name"
            type="text"
            class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            placeholder="Customer name"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Customer Email</label>
          <input
            v-model="formData.customer_email"
            type="email"
            class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            placeholder="customer@example.com"
          />
        </div>
      </div>

      <template #footer>
        <div class="flex gap-3">
          <button
            @click="closeCreateOverlay"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
          >
            Cancel
          </button>
          <button
            @click="handleSubmit"
            :disabled="saving"
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors disabled:opacity-50"
          >
            {{ saving ? 'Creating...' : 'Create Ticket' }}
          </button>
        </div>
      </template>
    </SlideOverlay>
  </DataViewContainer>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { Plus, MessageSquare, Save } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const router = useRouter()

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Support', to: '/dashboard/support' },
  { label: 'Create Ticket' }
]

const saving = ref(false)
const showCreateOverlay = ref(false)

const formData = ref({
  subject: '',
  category: '',
  priority: 'medium',
  description: '',
  customer_name: '',
  customer_email: ''
})

const openCreateOverlay = () => {
  formData.value = {
    subject: '',
    category: '',
    priority: 'medium',
    description: '',
    customer_name: '',
    customer_email: ''
  }
  showCreateOverlay.value = true
}

const closeCreateOverlay = () => {
  showCreateOverlay.value = false
}

const handleSubmit = async () => {
  saving.value = true
  
  try {
    await new Promise(resolve => setTimeout(resolve, 1000))
    alert('Ticket created successfully!')
    closeCreateOverlay()
    router.push('/dashboard/support/all-tickets')
  } catch (err) {
    console.error(err)
    alert('Failed to create ticket')
  } finally {
    saving.value = false
  }
}
</script>
