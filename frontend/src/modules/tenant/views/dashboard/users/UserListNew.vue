<template>
  <DataViewContainer
    title="Admin Users"
    subtitle="Manage system administrators and staff accounts"
    color-theme="indigo"
    v-model:search-model="searchQuery"
    search-placeholder="Search admin users..."
    :stats="[
      { color: 'bg-indigo-500', value: totalUsers },
      { color: 'bg-emerald-500', value: activeUsers.length },
      { color: 'bg-yellow-500', value: inactiveUsers.length }
    ]"
    :total="users.length"
    :loading="loading"
    add-button-text="Add Admin"
    @refresh="fetchUsers"
    @add="openCreateModal"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
      </svg>
    </template>

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
        <option value="">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </BaseSelect>
      <BaseSelect v-model="filters.role" placeholder="All Roles" class="w-36">
        <option value="">All Roles</option>
        <option value="super_admin">Super Admin</option>
        <option value="admin">Admin</option>
        <option value="staff">Staff</option>
      </BaseSelect>
    </template>

    <!-- SlideOverlay for Create -->
    <SlideOverlay
      v-model="showCreateOverlay"
      title="Add Admin User"
      subtitle="Create a new administrator account"
      icon="shield"
      width="480px"
      @close="closeCreateForm"
    >
      <div class="p-6 space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Name</label>
          <input v-model="createForm.name" type="text" placeholder="Full name" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Username</label>
          <input v-model="createForm.username" type="text" placeholder="Username" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
          <input v-model="createForm.email" type="email" placeholder="email@example.com" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Phone</label>
          <input v-model="createForm.phone" type="tel" placeholder="Phone number" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Role</label>
          <select v-model="createForm.role" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white">
            <option value="staff">Staff</option>
            <option value="admin">Admin</option>
            <option value="super_admin">Super Admin</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
          <input v-model="createForm.password" type="password" placeholder="Set password" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none" />
        </div>
        <div v-if="formError" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-600">{{ formError }}</div>
      </div>

      <template #footer>
        <div class="flex gap-3">
          <button @click="closeCreateForm" class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">Cancel</button>
          <button @click="handleCreate" :disabled="formSubmitting" class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors disabled:opacity-50">
            <span v-if="formSubmitting">Creating...</span>
            <span v-else>Create User</span>
          </button>
        </div>
      </template>
    </SlideOverlay>

    <!-- SlideOverlay for Edit -->
    <SlideOverlay
      v-model="showEditOverlay"
      title="Edit Admin User"
      subtitle="Update user details"
      icon="edit"
      width="480px"
      @close="closeEditForm"
    >
      <div v-if="selectedUser" class="p-6 space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Name</label>
          <input v-model="editForm.name" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
          <input v-model="editForm.email" type="email" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Phone</label>
          <input v-model="editForm.phone" type="tel" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Role</label>
          <select v-model="editForm.role" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white">
            <option value="staff">Staff</option>
            <option value="admin">Admin</option>
            <option value="super_admin">Super Admin</option>
          </select>
        </div>
        <div v-if="formError" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-600">{{ formError }}</div>
      </div>

      <template #footer>
        <div class="flex gap-3">
          <button @click="closeEditForm" class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">Cancel</button>
          <button @click="handleUpdate" :disabled="formSubmitting" class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors disabled:opacity-50">
            <span v-if="formSubmitting">Updating...</span>
            <span v-else>Update User</span>
          </button>
        </div>
      </template>
    </SlideOverlay>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500 min-h-[300px]">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-center font-medium">{{ error || 'An error occurred' }}</p>
      <button @click="fetchUsers" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <!-- Loading Skeleton -->
    <div v-else-if="loading" class="min-h-[300px] flex items-center justify-center">
      <DataSkeleton :count="5" />
    </div>

    <!-- Data Content -->
    <div v-else-if="filteredData && filteredData.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-[300px]">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="user in paginatedData"
          :key="user.id"
          :title="user.name || user.username"
          :subtitle="user.email"
          :meta-lines="[{ text: user.role || 'staff' }, { text: formatDate(user.last_login) }]"
          :status="user.status"
          :actions="getUserActions(user)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Admin User</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Contact</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Role</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Last Login</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Created</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="user in paginatedData" :key="user.id" class="hover:bg-indigo-50/50 transition-colors">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-purple-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">{{ getUserInitials(user) }}</div>
                    <div>
                      <div class="text-sm font-medium text-slate-900">{{ user.name || user.username }}</div>
                      <div class="text-xs text-slate-500">@{{ user.username }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="text-sm text-slate-900">{{ user.email || 'N/A' }}</div>
                  <div class="text-xs text-slate-500">{{ user.phone || 'No phone' }}</div>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="user.role || 'staff'" size="sm" />
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="user.status || 'inactive'" size="sm" />
                </td>
                <td class="px-6 py-4 text-sm text-slate-600">{{ formatDate(user.last_login) }}</td>
                <td class="px-6 py-4 text-sm text-slate-600">{{ formatDate(user.created_at) }}</td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button @click="openEditModal(user)" class="px-2 py-1 text-xs font-medium text-slate-700 bg-slate-100 rounded hover:bg-slate-200 transition-colors">Edit</button>
                    <button @click="handleToggleStatus(user)" :class="user.status === 'inactive' ? 'text-emerald-700 bg-emerald-50 hover:bg-emerald-100' : 'text-yellow-700 bg-yellow-50 hover:bg-yellow-100'" class="px-2 py-1 text-xs font-medium rounded transition-colors">{{ user.status === 'inactive' ? 'Activate' : 'Deactivate' }}</button>
                    <button @click="handleDelete(user)" class="px-2 py-1 text-xs font-medium text-red-600 bg-red-50 rounded hover:bg-red-100 transition-colors">Delete</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredData.length" item-name="users" class="mt-auto" />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Matches Found' : 'No Admin Users Found'"
      :description="searchQuery ? 'No admin users match your search criteria.' : 'Get started by creating your first administrator account.'"
      icon="shield"
      color-theme="indigo"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      clear-text="Clear Search"
      add-text="Add Admin"
      @clear="searchQuery = ''"
      @add="openCreateModal"
    />
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Plus, X, RefreshCw } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import { useUsers } from '@/modules/tenant/composables/data/useUsers'
import { useFilters } from '@/modules/common/composables/utils/useFilters'
import { usePagination } from '@/modules/common/composables/utils/usePagination'

// Data management
const { users, loading, error, activeUsers, inactiveUsers, totalUsers, fetchUsers, deleteUser, toggleUserStatus, createUser, updateUser } = useUsers()

// Filtering
const { filters, searchQuery, filteredData, hasActiveFilters, clearFilters } = useFilters(users, { status: '', role: '' })

// Pagination
const { currentPage, itemsPerPage, paginatedData, totalPages } = usePagination(filteredData, 10)

// Modal state
const showCreateOverlay = ref(false)
const showEditOverlay = ref(false)
const selectedUser = ref(null)
const formSubmitting = ref(false)
const formError = ref('')

// Forms
const createForm = ref({ name: '', username: '', email: '', phone: '', role: 'staff', password: '' })
const editForm = ref({ name: '', email: '', phone: '', role: '' })

// Helpers
const getUserInitials = (user) => {
  const name = user.name || user.username || 'U'
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const formatDate = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

const getUserActions = (user) => [
  { label: 'Edit', onClick: () => openEditModal(user), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' },
  { label: user.status === 'inactive' ? 'Activate' : 'Deactivate', onClick: () => handleToggleStatus(user), class: user.status === 'inactive' ? 'text-emerald-700 bg-emerald-50 hover:bg-emerald-100' : 'text-yellow-700 bg-yellow-50 hover:bg-yellow-100' },
  { label: 'Delete', onClick: () => handleDelete(user), class: 'text-red-600 bg-red-50 hover:bg-red-100' }
]

// Form handlers
const resetCreateForm = () => {
  createForm.value = { name: '', username: '', email: '', phone: '', role: 'staff', password: '' }
  formError.value = ''
}

const resetEditForm = () => {
  editForm.value = { name: '', email: '', phone: '', role: '' }
  formError.value = ''
}

const openCreateModal = () => {
  resetCreateForm()
  showCreateOverlay.value = true
}

const closeCreateForm = () => {
  showCreateOverlay.value = false
  setTimeout(resetCreateForm, 300)
}

const openEditModal = (user) => {
  selectedUser.value = user
  editForm.value = { name: user.name || '', email: user.email || '', phone: user.phone || '', role: user.role || 'staff' }
  showEditOverlay.value = true
}

const closeEditForm = () => {
  showEditOverlay.value = false
  selectedUser.value = null
  setTimeout(resetEditForm, 300)
}

const handleCreate = async () => {
  formSubmitting.value = true
  formError.value = ''
  try {
    await createUser(createForm.value)
    closeCreateForm()
    await fetchUsers()
  } catch (err) {
    formError.value = err.message || 'Failed to create user'
  } finally {
    formSubmitting.value = false
  }
}

const handleUpdate = async () => {
  if (!selectedUser.value) return
  formSubmitting.value = true
  formError.value = ''
  try {
    await updateUser(selectedUser.value.id, editForm.value)
    closeEditForm()
    await fetchUsers()
  } catch (err) {
    formError.value = err.message || 'Failed to update user'
  } finally {
    formSubmitting.value = false
  }
}

const handleToggleStatus = async (user) => {
  const action = user.status === 'inactive' ? 'activate' : 'deactivate'
  if (!confirm(`Are you sure you want to ${action} ${user.name || user.username}?`)) return
  try {
    await toggleUserStatus(user.id, action === 'deactivate')
    await fetchUsers()
  } catch (err) {
    console.error(`Failed to ${action} user:`, err)
  }
}

const handleDelete = async (user) => {
  if (!confirm(`Are you sure you want to delete ${user.name || user.username}? This cannot be undone.`)) return
  try {
    await deleteUser(user.id)
  } catch (err) {
    console.error('Failed to delete user:', err)
  }
}

// Lifecycle
onMounted(() => fetchUsers())
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
