<template>
  <PageContainer>
    <PageHeader title="Create Support Ticket" subtitle="Submit a new support request" icon="Plus" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="$router.back()" variant="ghost">
          <ArrowLeft class="w-4 h-4 mr-1" />
          Back
        </BaseButton>
      </template>
    </PageHeader>

    <PageContent>
      <div class="max-w-3xl mx-auto">
        <form @submit.prevent="handleSubmit" class="space-y-6">
          <BaseCard>
            <div class="p-6 space-y-4">
              <h3 class="text-lg font-semibold text-slate-900 mb-4">Ticket Information</h3>
              
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Subject *</label>
                <input v-model="formData.subject" type="text" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Brief description of the issue" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Category *</label>
                <BaseSelect v-model="formData.category" required class="w-full">
                  <option value="">Select category...</option>
                  <option value="technical">Technical Support</option>
                  <option value="billing">Billing</option>
                  <option value="general">General Inquiry</option>
                  <option value="complaint">Complaint</option>
                </BaseSelect>
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Priority *</label>
                <BaseSelect v-model="formData.priority" required class="w-full">
                  <option value="low">Low</option>
                  <option value="medium">Medium</option>
                  <option value="high">High</option>
                  <option value="urgent">Urgent</option>
                </BaseSelect>
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Description *</label>
                <textarea v-model="formData.description" rows="6" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Detailed description of the issue..."></textarea>
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Customer Name</label>
                <input v-model="formData.customer_name" type="text" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Customer name" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Customer Email</label>
                <input v-model="formData.customer_email" type="email" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="customer@example.com" />
              </div>
            </div>
          </BaseCard>

          <div class="flex items-center justify-end gap-3">
            <BaseButton @click="$router.back()" variant="ghost" type="button">Cancel</BaseButton>
            <BaseButton type="submit" variant="primary" :loading="saving">
              <Save class="w-4 h-4 mr-1" />
              Create Ticket
            </BaseButton>
          </div>
        </form>
      </div>
    </PageContent>
  </PageContainer>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { Plus, ArrowLeft, Save } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'

const router = useRouter()

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Support', to: '/dashboard/support' },
  { label: 'Create Ticket' }
]

const saving = ref(false)

const formData = ref({
  subject: '',
  category: '',
  priority: 'medium',
  description: '',
  customer_name: '',
  customer_email: ''
})

const handleSubmit = async () => {
  saving.value = true
  
  try {
    await new Promise(resolve => setTimeout(resolve, 1000))
    alert('Ticket created successfully!')
    router.push('/dashboard/support/tickets')
  } catch (err) {
    console.error(err)
    alert('Failed to create ticket')
  } finally {
    saving.value = false
  }
}
</script>
