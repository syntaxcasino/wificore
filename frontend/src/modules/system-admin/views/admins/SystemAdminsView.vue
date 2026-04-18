<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-slate-100">System Administrators</h1>
        <p class="text-xs sm:text-sm text-gray-500 dark:text-slate-400 mt-1">Manage platform-level administrator accounts</p>
      </div>
      <button
        @click="showCreateModal = true"
        class="inline-flex items-center gap-2 px-3 py-1.5 sm:px-4 sm:py-2 bg-blue-600 text-white text-xs sm:text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors self-start sm:self-auto"
      >
        <Plus class="w-4 h-4" />
        Add Admin
      </button>
    </div>

    <!-- Table -->
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden overflow-x-auto">
      <div v-if="loading" class="p-8 text-center text-gray-500 dark:text-slate-400">
        <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
        Loading administrators...
      </div>
      <div v-else-if="error" class="p-8 text-center text-red-500">
        {{ error }}
        <button @click="fetchAdmins" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
      </div>
      <table v-else class="w-full min-w-[560px]">
        <thead class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
          <tr>
            <th class="text-left px-3 sm:px-4 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Name</th>
            <th class="text-left px-3 sm:px-4 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase hidden sm:table-cell">Username</th>
            <th class="text-left px-3 sm:px-4 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Email</th>
            <th class="text-center px-3 sm:px-4 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Status</th>
            <th class="text-left px-3 sm:px-4 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase hidden md:table-cell">Last Login</th>
            <th class="text-right px-3 sm:px-4 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
          <tr v-for="admin in admins" :key="admin.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors">
            <td class="px-3 sm:px-4 py-3">
              <div class="font-medium text-gray-900 dark:text-slate-100 text-sm">{{ admin.name }}</div>
              <div v-if="admin.phone_number" class="text-xs text-gray-500 dark:text-slate-400">{{ admin.phone_number }}</div>
            </td>
            <td class="px-3 sm:px-4 py-3 text-sm text-gray-600 dark:text-slate-400 font-mono hidden sm:table-cell">{{ admin.username }}</td>
            <td class="px-3 sm:px-4 py-3 text-sm text-gray-600 dark:text-slate-400">{{ admin.email }}</td>
            <td class="px-3 sm:px-4 py-3 text-center">
              <span
                class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                :class="admin.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'"
              >
                {{ admin.is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="px-3 sm:px-4 py-3 text-sm text-gray-500 dark:text-slate-400 hidden md:table-cell">
              {{ admin.last_login_at ? formatDate(admin.last_login_at) : 'Never' }}
            </td>
            <td class="px-3 sm:px-4 py-3 text-right">
              <div class="flex items-center justify-end gap-1">
                <button
                  v-if="!isDefaultAdmin(admin)"
                  @click="toggleActive(admin)"
                  class="p-1.5 rounded-md transition-colors"
                  :class="admin.is_active ? 'text-red-500 hover:bg-red-50' : 'text-green-500 hover:bg-green-50'"
                  :title="admin.is_active ? 'Deactivate' : 'Activate'"
                >
                  <component :is="admin.is_active ? ShieldOff : ShieldCheck" class="w-4 h-4" />
                </button>
                <button
                  v-if="!isDefaultAdmin(admin)"
                  @click="deleteAdmin(admin)"
                  class="p-1.5 text-red-500 hover:bg-red-50 rounded-md transition-colors"
                  title="Delete"
                >
                  <Trash2 class="w-4 h-4" />
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="admins.length === 0">
            <td colspan="6" class="px-4 py-8 text-center text-gray-400 dark:text-slate-500 text-sm">No administrators found</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create Admin Overlay -->
    <SlideOverlay
      v-model="showCreateModal"
      title="Add System Administrator"
      subtitle="Create a new platform-level admin account"
      icon="UserPlus"
      width="50%"
      @close="showCreateModal = false"
    >
      <form @submit.prevent="createAdmin" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Full Name *</label>
          <input v-model="form.name" type="text" required class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg text-sm text-gray-900 dark:text-slate-100 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Username *</label>
          <input v-model="form.username" type="text" required pattern="[a-z0-9_]+" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg text-sm text-gray-900 dark:text-slate-100 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-blue-500 font-mono" placeholder="lowercase, numbers, underscores" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Email *</label>
          <input v-model="form.email" type="email" required class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg text-sm text-gray-900 dark:text-slate-100 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Phone</label>
          <input v-model="form.phone_number" type="text" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg text-sm text-gray-900 dark:text-slate-100 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Password *</label>
          <input v-model="form.password" type="password" required minlength="8" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg text-sm text-gray-900 dark:text-slate-100 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-blue-500" />
          <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">Min 8 chars, uppercase, lowercase, number, special char</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Confirm Password *</label>
          <input v-model="form.password_confirmation" type="password" required class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg text-sm text-gray-900 dark:text-slate-100 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-blue-500" />
        </div>
        <div v-if="formError" class="text-sm text-red-600">{{ formError }}</div>
      </form>

      <template #footer>
        <div class="flex justify-end gap-3">
          <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-gray-300 dark:border-slate-600 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-600 transition-colors">Cancel</button>
          <button @click="createAdmin" :disabled="creating" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors">
            {{ creating ? 'Creating...' : 'Create Admin' }}
          </button>
        </div>
      </template>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { Plus, ShieldCheck, ShieldOff, Trash2 } from 'lucide-vue-next'
import { useConfirmStore } from '@/stores/confirm'
import { useToast } from '@/modules/common/composables/useToast.js'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const confirmStore = useConfirmStore()
const { error: showError } = useToast()

const admins = ref([])
const loading = ref(true)
const error = ref(null)
const showCreateModal = ref(false)
const creating = ref(false)
const formError = ref(null)

const form = reactive({
  name: '',
  username: '',
  email: '',
  phone_number: '',
  password: '',
  password_confirmation: ''
})

const DEFAULT_ADMIN_ID = '00000000-0000-0000-0000-000000000001'
const isDefaultAdmin = (admin) => admin.id === DEFAULT_ADMIN_ID

const formatDate = (dateStr) => {
  if (!dateStr) return ''
  return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const fetchAdmins = async () => {
  try {
    loading.value = true
    error.value = null
    const res = await axios.get('/system/admins')
    admins.value = res.data.data?.data || []
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load administrators'
  } finally {
    loading.value = false
  }
}

const createAdmin = async () => {
  try {
    creating.value = true
    formError.value = null
    await axios.post('/system/admins', form)
    showCreateModal.value = false
    Object.assign(form, { name: '', username: '', email: '', phone_number: '', password: '', password_confirmation: '' })
    await fetchAdmins()
  } catch (err) {
    const errors = err.response?.data?.errors
    formError.value = errors ? Object.values(errors).flat().join('. ') : (err.response?.data?.message || 'Failed to create admin')
  } finally {
    creating.value = false
  }
}

const toggleActive = async (admin) => {
  try {
    await axios.put(`/system/admins/${admin.id}`, { is_active: !admin.is_active })
    await fetchAdmins()
  } catch (err) {
    showError(err.response?.data?.message || 'Failed to update admin')
  }
}

const deleteAdmin = async (admin) => {
  const confirmed = await confirmStore.open({ title: 'Delete Administrator', message: `Delete administrator ${admin.name}? This cannot be undone.`, confirmText: 'Delete', cancelText: 'Cancel', variant: 'danger' })
  if (!confirmed) return
  try {
    await axios.delete(`/system/admins/${admin.id}`)
    await fetchAdmins()
  } catch (err) {
    showError(err.response?.data?.message || 'Failed to delete admin')
  }
}

onMounted(() => fetchAdmins())
</script>
