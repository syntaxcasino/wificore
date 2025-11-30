<template>
  <PageContainer>
    <!-- Header -->
    <PageHeader
      title="Package Groups"
      subtitle="Organize packages into categories for better management"
      icon="Layers"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="refreshGroups" variant="ghost" :loading="refreshing">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': refreshing }" />
          Refresh
        </BaseButton>
        <BaseButton @click="openCreateModal" variant="primary">
          <Plus class="w-4 h-4 mr-1" />
          Add Group
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Stats Cards -->
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Total Groups</div>
              <div class="text-2xl font-bold text-blue-900">{{ stats.total }}</div>
            </div>
            <div class="p-3 bg-blue-100 rounded-lg">
              <Layers class="w-6 h-6 text-blue-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Active Groups</div>
              <div class="text-2xl font-bold text-green-900">{{ stats.active }}</div>
            </div>
            <div class="p-3 bg-green-100 rounded-lg">
              <CheckCircle class="w-6 h-6 text-green-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-4 border border-purple-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-purple-600 font-medium mb-1">Total Packages</div>
              <div class="text-2xl font-bold text-purple-900">{{ stats.totalPackages }}</div>
            </div>
            <div class="p-3 bg-purple-100 rounded-lg">
              <Package class="w-6 h-6 text-purple-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">Featured</div>
              <div class="text-2xl font-bold text-amber-900">{{ stats.featured }}</div>
            </div>
            <div class="p-3 bg-amber-100 rounded-lg">
              <Star class="w-6 h-6 text-amber-600" />
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Search and Filters Bar -->
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <!-- Search Box -->
        <div class="flex-1 min-w-[300px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search groups..." />
        </div>
        
        <!-- Filters Group -->
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </BaseSelect>
          
          <BaseButton v-if="hasActiveFilters" @click="clearFilters" variant="ghost" size="sm">
            <X class="w-4 h-4 mr-1" />
            Clear
          </BaseButton>
        </div>
        
        <!-- Results Count -->
        <div class="ml-auto">
          <BaseBadge variant="info">{{ filteredData.length }} groups</BaseBadge>
        </div>
      </div>
    </div>

    <!-- Content -->
    <PageContent>
      <!-- Loading State -->
      <div v-if="loading">
        <BaseLoading type="grid" :items="6" />
      </div>

      <!-- Error State -->
      <div v-else-if="error">
        <BaseAlert variant="danger" :title="error" dismissible>
          <div class="mt-2">
            <BaseButton @click="fetchGroups" variant="danger" size="sm">
              <RefreshCw class="w-4 h-4 mr-1" />
              Retry
            </BaseButton>
          </div>
        </BaseAlert>
      </div>

      <!-- Empty State -->
      <div v-else-if="!filteredData.length">
        <BaseEmpty
          :title="searchQuery ? 'No groups found' : 'No package groups yet'"
          :description="searchQuery ? 'No groups match your search criteria.' : 'Get started by creating your first package group.'"
          icon="Layers"
          actionText="Add Group"
          actionIcon="Plus"
          @action="openCreateModal"
        />
      </div>

      <!-- Groups Grid -->
      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div
          v-for="group in filteredData"
          :key="group.id"
          class="bg-white rounded-xl border-2 border-slate-200 hover:border-blue-400 hover:shadow-lg transition-all duration-200 overflow-hidden group cursor-pointer"
          @click="viewGroup(group)"
        >
          <!-- Group Header -->
          <div class="p-6 bg-gradient-to-br" :class="getGroupGradient(group.color)">
            <div class="flex items-start justify-between mb-4">
              <div class="p-3 bg-white/90 rounded-lg">
                <Layers class="w-6 h-6" :class="getIconColor(group.color)" />
              </div>
              <div class="flex items-center gap-2">
                <BaseBadge v-if="group.is_featured" variant="warning" size="sm">
                  <Star class="w-3 h-3 mr-1" />
                  Featured
                </BaseBadge>
                <BaseBadge :variant="group.status === 'active' ? 'success' : 'secondary'" size="sm">
                  {{ group.status }}
                </BaseBadge>
              </div>
            </div>
            
            <h3 class="text-xl font-bold text-white mb-1">{{ group.name }}</h3>
            <p class="text-white/80 text-sm">{{ group.description }}</p>
          </div>

          <!-- Group Details -->
          <div class="p-6 space-y-4">
            <!-- Package Count -->
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2 text-sm text-slate-600">
                <Package class="w-4 h-4" />
                <span>{{ group.packages_count }} packages</span>
              </div>
              <div class="text-sm font-semibold text-slate-900">
                Order: {{ group.display_order }}
              </div>
            </div>

            <!-- Package List Preview -->
            <div v-if="group.packages && group.packages.length" class="space-y-2">
              <div class="text-xs font-semibold text-slate-500 uppercase">Packages</div>
              <div class="space-y-1">
                <div
                  v-for="pkg in group.packages.slice(0, 3)"
                  :key="pkg.id"
                  class="flex items-center justify-between text-sm p-2 bg-slate-50 rounded"
                >
                  <span class="text-slate-700">{{ pkg.name }}</span>
                  <span class="text-slate-500 text-xs">KES {{ formatMoney(pkg.price) }}</span>
                </div>
                <div v-if="group.packages.length > 3" class="text-xs text-slate-500 text-center py-1">
                  +{{ group.packages.length - 3 }} more
                </div>
              </div>
            </div>
            <div v-else class="text-sm text-slate-400 italic">
              No packages in this group
            </div>

            <!-- Actions -->
            <div class="pt-4 border-t border-slate-200 flex items-center gap-2" @click.stop>
              <BaseButton @click="editGroup(group)" variant="ghost" size="sm" class="flex-1">
                <Edit2 class="w-3 h-3 mr-1" />
                Edit
              </BaseButton>
              <BaseButton 
                @click="toggleStatus(group)" 
                :variant="group.status === 'active' ? 'warning' : 'success'" 
                size="sm"
                class="flex-1"
              >
                {{ group.status === 'active' ? 'Deactivate' : 'Activate' }}
              </BaseButton>
              <BaseButton @click="deleteGroup(group)" variant="ghost" size="sm" class="text-red-600">
                <Trash2 class="w-3 h-3" />
              </BaseButton>
            </div>
          </div>
        </div>
      </div>
    </PageContent>

    <!-- Create/Edit Modal -->
    <BaseModal v-model="showModal" :title="editingGroup ? 'Edit Group' : 'Create Group'" size="lg">
      <form @submit.prevent="handleSubmit" class="space-y-4">
        <!-- Group Name -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-2">
            Group Name *
          </label>
          <input
            v-model="formData.name"
            type="text"
            required
            class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            placeholder="e.g., Home Packages"
          />
        </div>

        <!-- Description -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-2">
            Description
          </label>
          <textarea
            v-model="formData.description"
            rows="3"
            class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            placeholder="Brief description of the group..."
          ></textarea>
        </div>

        <!-- Color Selection -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-2">
            Color Theme
          </label>
          <div class="grid grid-cols-4 gap-3">
            <div
              v-for="color in colorOptions"
              :key="color.value"
              @click="formData.color = color.value"
              class="relative p-4 rounded-lg cursor-pointer border-2 transition-all"
              :class="[
                color.bg,
                formData.color === color.value ? 'border-slate-900' : 'border-slate-200 hover:border-slate-400'
              ]"
            >
              <div class="text-center">
                <div class="text-xs font-medium" :class="color.text">{{ color.label }}</div>
              </div>
              <CheckCircle v-if="formData.color === color.value" class="absolute top-1 right-1 w-4 h-4 text-slate-900" />
            </div>
          </div>
        </div>

        <!-- Display Order -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-2">
            Display Order
          </label>
          <input
            v-model.number="formData.display_order"
            type="number"
            min="0"
            step="1"
            class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            placeholder="e.g., 1"
          />
          <p class="mt-1 text-xs text-slate-500">Lower numbers appear first</p>
        </div>

        <!-- Settings -->
        <div class="space-y-3">
          <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
            <div>
              <div class="text-sm font-medium text-slate-900">Active</div>
              <div class="text-xs text-slate-500">Show this group to users</div>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input v-model="formData.is_active" type="checkbox" class="sr-only peer" />
              <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
            </label>
          </div>

          <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
            <div>
              <div class="text-sm font-medium text-slate-900">Featured</div>
              <div class="text-xs text-slate-500">Highlight this group</div>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input v-model="formData.is_featured" type="checkbox" class="sr-only peer" />
              <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
            </label>
          </div>
        </div>
      </form>

      <template #footer>
        <BaseButton @click="showModal = false" variant="ghost">Cancel</BaseButton>
        <BaseButton @click="handleSubmit" variant="primary" :loading="saving">
          {{ editingGroup ? 'Update' : 'Create' }} Group
        </BaseButton>
      </template>
    </BaseModal>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { 
  Layers, Plus, RefreshCw, X, Edit2, Trash2,
  CheckCircle, Package, Star
} from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import BaseSearch from '@/modules/common/components/base/BaseSearch.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'
import BaseEmpty from '@/modules/common/components/base/BaseEmpty.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'
import BaseModal from '@/modules/common/components/base/BaseModal.vue'

// Breadcrumbs
const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Packages', to: '/dashboard/packages' },
  { label: 'Groups' }
]

// State
const loading = ref(false)
const refreshing = ref(false)
const error = ref(null)
const groups = ref([])
const searchQuery = ref('')
const showModal = ref(false)
const editingGroup = ref(null)
const saving = ref(false)

const filters = ref({
  status: ''
})

const formData = ref({
  name: '',
  description: '',
  color: 'blue',
  display_order: 0,
  is_active: true,
  is_featured: false
})

const colorOptions = [
  { value: 'blue', label: 'Blue', bg: 'bg-blue-100', text: 'text-blue-900' },
  { value: 'purple', label: 'Purple', bg: 'bg-purple-100', text: 'text-purple-900' },
  { value: 'green', label: 'Green', bg: 'bg-green-100', text: 'text-green-900' },
  { value: 'amber', label: 'Amber', bg: 'bg-amber-100', text: 'text-amber-900' },
  { value: 'red', label: 'Red', bg: 'bg-red-100', text: 'text-red-900' },
  { value: 'cyan', label: 'Cyan', bg: 'bg-cyan-100', text: 'text-cyan-900' },
  { value: 'pink', label: 'Pink', bg: 'bg-pink-100', text: 'text-pink-900' },
  { value: 'indigo', label: 'Indigo', bg: 'bg-indigo-100', text: 'text-indigo-900' }
]

// Mock data
const mockGroups = [
  {
    id: 1,
    name: 'Home Packages',
    description: 'Residential internet packages',
    color: 'blue',
    packages_count: 5,
    display_order: 1,
    status: 'active',
    is_featured: true,
    packages: [
      { id: 1, name: 'Home Basic 10 Mbps', price: 2000 },
      { id: 2, name: 'Home Premium 20 Mbps', price: 3500 },
      { id: 3, name: 'Home Ultra 50 Mbps', price: 7500 }
    ]
  },
  {
    id: 2,
    name: 'Hotspot Packages',
    description: 'Quick access vouchers',
    color: 'purple',
    packages_count: 8,
    display_order: 2,
    status: 'active',
    is_featured: false,
    packages: [
      { id: 4, name: '1 Hour - 5GB', price: 50 },
      { id: 5, name: '1 Day - 20GB', price: 200 }
    ]
  },
  {
    id: 3,
    name: 'Business Packages',
    description: 'For small and medium businesses',
    color: 'green',
    packages_count: 3,
    display_order: 3,
    status: 'active',
    is_featured: true,
    packages: [
      { id: 6, name: 'Business 50 Mbps', price: 7500 },
      { id: 7, name: 'Business 100 Mbps', price: 12000 }
    ]
  }
]

// Computed
const stats = computed(() => {
  const totalPackages = groups.value.reduce((sum, g) => sum + g.packages_count, 0)
  const featured = groups.value.filter(g => g.is_featured).length
  
  return {
    total: groups.value.length,
    active: groups.value.filter(g => g.status === 'active').length,
    totalPackages,
    featured
  }
})

const filteredData = computed(() => {
  let data = groups.value

  // Search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(g =>
      g.name.toLowerCase().includes(query) ||
      g.description.toLowerCase().includes(query)
    )
  }

  // Status filter
  if (filters.value.status) {
    data = data.filter(g => g.status === filters.value.status)
  }

  return data
})

const hasActiveFilters = computed(() => {
  return filters.value.status || searchQuery.value
})

// Methods
const fetchGroups = async () => {
  loading.value = true
  error.value = null
  
  try {
    // TODO: Replace with actual API call
    await new Promise(resolve => setTimeout(resolve, 500))
    groups.value = mockGroups
  } catch (err) {
    error.value = 'Failed to load groups. Please try again.'
    console.error('Error fetching groups:', err)
  } finally {
    loading.value = false
  }
}

const refreshGroups = async () => {
  refreshing.value = true
  error.value = null
  
  try {
    await new Promise(resolve => setTimeout(resolve, 500))
    groups.value = mockGroups
  } catch (err) {
    error.value = 'Failed to refresh groups.'
    console.error('Error refreshing groups:', err)
  } finally {
    refreshing.value = false
  }
}

const clearFilters = () => {
  filters.value = { status: '' }
  searchQuery.value = ''
}

const getGroupGradient = (color) => {
  const gradients = {
    blue: 'from-blue-500 to-indigo-600',
    purple: 'from-purple-500 to-indigo-600',
    green: 'from-green-500 to-emerald-600',
    amber: 'from-amber-500 to-yellow-600',
    red: 'from-red-500 to-rose-600',
    cyan: 'from-cyan-500 to-blue-600',
    pink: 'from-pink-500 to-rose-600',
    indigo: 'from-indigo-500 to-purple-600'
  }
  return gradients[color] || gradients.blue
}

const getIconColor = (color) => {
  const colors = {
    blue: 'text-blue-600',
    purple: 'text-purple-600',
    green: 'text-green-600',
    amber: 'text-amber-600',
    red: 'text-red-600',
    cyan: 'text-cyan-600',
    pink: 'text-pink-600',
    indigo: 'text-indigo-600'
  }
  return colors[color] || colors.blue
}

const formatMoney = (amount) => {
  return new Intl.NumberFormat('en-KE').format(amount)
}

const openCreateModal = () => {
  editingGroup.value = null
  formData.value = {
    name: '',
    description: '',
    color: 'blue',
    display_order: 0,
    is_active: true,
    is_featured: false
  }
  showModal.value = true
}

const viewGroup = (group) => {
  console.log('View group:', group)
  // TODO: Implement group details view
}

const editGroup = (group) => {
  editingGroup.value = group
  formData.value = { ...group }
  showModal.value = true
}

const handleSubmit = async () => {
  saving.value = true
  
  try {
    // TODO: Replace with actual API call
    await new Promise(resolve => setTimeout(resolve, 1000))
    
    if (editingGroup.value) {
      // Update existing group
      const index = groups.value.findIndex(g => g.id === editingGroup.value.id)
      if (index !== -1) {
        groups.value[index] = { ...groups.value[index], ...formData.value }
      }
      alert('Group updated successfully!')
    } else {
      // Create new group
      const newGroup = {
        id: Date.now(),
        ...formData.value,
        packages_count: 0,
        packages: []
      }
      groups.value.push(newGroup)
      alert('Group created successfully!')
    }
    
    showModal.value = false
  } catch (err) {
    console.error('Error saving group:', err)
    alert('Failed to save group')
  } finally {
    saving.value = false
  }
}

const toggleStatus = async (group) => {
  const action = group.status === 'active' ? 'deactivate' : 'activate'
  if (!confirm(`Are you sure you want to ${action} ${group.name}?`)) return
  
  try {
    // TODO: Implement API call
    await new Promise(resolve => setTimeout(resolve, 500))
    group.status = group.status === 'active' ? 'inactive' : 'active'
  } catch (err) {
    console.error(`Failed to ${action} group:`, err)
  }
}

const deleteGroup = async (group) => {
  if (!confirm(`Are you sure you want to delete ${group.name}? This action cannot be undone.`)) return
  
  try {
    // TODO: Implement API call
    await new Promise(resolve => setTimeout(resolve, 500))
    groups.value = groups.value.filter(g => g.id !== group.id)
  } catch (err) {
    console.error('Failed to delete group:', err)
  }
}

// Lifecycle
onMounted(() => {
  fetchGroups()
})
</script>
