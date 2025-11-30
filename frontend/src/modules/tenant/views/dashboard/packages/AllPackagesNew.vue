<template>
  <PageContainer>
    <!-- Header -->
    <PageHeader
      title="Internet Packages"
      subtitle="Manage your internet service packages and pricing"
      icon="Package"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="refreshPackages" variant="ghost" :loading="refreshing">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': refreshing }" />
          Refresh
        </BaseButton>
        <BaseButton @click="$router.push('/dashboard/packages/add')" variant="primary">
          <Plus class="w-4 h-4 mr-1" />
          Add Package
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Stats Cards -->
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Total Packages</div>
              <div class="text-2xl font-bold text-blue-900">{{ stats.total }}</div>
            </div>
            <div class="p-3 bg-blue-100 rounded-lg">
              <Package class="w-6 h-6 text-blue-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Active</div>
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
              <div class="text-xs text-purple-600 font-medium mb-1">Hotspot</div>
              <div class="text-2xl font-bold text-purple-900">{{ stats.hotspot }}</div>
            </div>
            <div class="p-3 bg-purple-100 rounded-lg">
              <Wifi class="w-6 h-6 text-purple-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-cyan-50 to-blue-50 rounded-lg p-4 border border-cyan-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-cyan-600 font-medium mb-1">PPPoE</div>
              <div class="text-2xl font-bold text-cyan-900">{{ stats.pppoe }}</div>
            </div>
            <div class="p-3 bg-cyan-100 rounded-lg">
              <Network class="w-6 h-6 text-cyan-600" />
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
          <BaseSearch v-model="searchQuery" placeholder="Search packages..." />
        </div>
        
        <!-- Filters Group -->
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.type" placeholder="All Types" class="w-36">
            <option value="">All Types</option>
            <option value="hotspot">Hotspot</option>
            <option value="pppoe">PPPoE</option>
          </BaseSelect>
          
          <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </BaseSelect>
          
          <BaseSelect v-model="viewMode" class="w-32">
            <option value="grid">Grid View</option>
            <option value="list">List View</option>
          </BaseSelect>
          
          <BaseButton v-if="hasActiveFilters" @click="clearFilters" variant="ghost" size="sm">
            <X class="w-4 h-4 mr-1" />
            Clear
          </BaseButton>
        </div>
        
        <!-- Results Count -->
        <div class="ml-auto">
          <BaseBadge variant="info">{{ filteredData.length }} packages</BaseBadge>
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
            <BaseButton @click="fetchPackages" variant="danger" size="sm">
              <RefreshCw class="w-4 h-4 mr-1" />
              Retry
            </BaseButton>
          </div>
        </BaseAlert>
      </div>

      <!-- Empty State -->
      <div v-else-if="!filteredData.length">
        <BaseEmpty
          :title="searchQuery ? 'No packages found' : 'No packages yet'"
          :description="searchQuery ? 'No packages match your search criteria.' : 'Get started by creating your first internet package.'"
          icon="Package"
          actionText="Add Package"
          actionIcon="Plus"
          @action="$router.push('/dashboard/packages/add')"
        />
      </div>

      <!-- Grid View -->
      <div v-else-if="viewMode === 'grid'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div
          v-for="pkg in paginatedData"
          :key="pkg.id"
          class="bg-white rounded-xl border-2 border-slate-200 hover:border-blue-400 hover:shadow-lg transition-all duration-200 overflow-hidden group cursor-pointer"
          @click="viewPackage(pkg)"
        >
          <!-- Package Header -->
          <div class="p-6 bg-gradient-to-br" :class="getPackageGradient(pkg.type)">
            <div class="flex items-start justify-between mb-4">
              <div class="p-3 bg-white/90 rounded-lg">
                <component :is="getPackageIcon(pkg.type)" class="w-6 h-6" :class="getIconColor(pkg.type)" />
              </div>
              <BaseBadge :variant="pkg.status === 'active' ? 'success' : 'secondary'" size="sm">
                {{ pkg.status }}
              </BaseBadge>
            </div>
            
            <h3 class="text-xl font-bold text-white mb-1">{{ pkg.name }}</h3>
            <p class="text-white/80 text-sm">{{ pkg.description }}</p>
          </div>

          <!-- Package Details -->
          <div class="p-6 space-y-4">
            <!-- Price -->
            <div class="flex items-baseline justify-between">
              <div>
                <div class="text-3xl font-bold text-slate-900">KES {{ formatMoney(pkg.price) }}</div>
                <div class="text-xs text-slate-500">per {{ pkg.validity }}</div>
              </div>
              <BaseBadge :variant="getTypeVariant(pkg.type)">
                {{ pkg.type }}
              </BaseBadge>
            </div>

            <!-- Features -->
            <div class="space-y-2">
              <div class="flex items-center gap-2 text-sm text-slate-700">
                <Zap class="w-4 h-4 text-blue-600" />
                <span class="font-medium">{{ pkg.speed }}</span>
              </div>
              <div class="flex items-center gap-2 text-sm text-slate-700">
                <HardDrive class="w-4 h-4 text-green-600" />
                <span class="font-medium">{{ pkg.data_limit || 'Unlimited' }}</span>
              </div>
              <div class="flex items-center gap-2 text-sm text-slate-700">
                <Clock class="w-4 h-4 text-amber-600" />
                <span class="font-medium">{{ pkg.validity }}</span>
              </div>
              <div v-if="pkg.users_count" class="flex items-center gap-2 text-sm text-slate-700">
                <Users class="w-4 h-4 text-purple-600" />
                <span class="font-medium">{{ pkg.users_count }} active users</span>
              </div>
            </div>

            <!-- Actions -->
            <div class="pt-4 border-t border-slate-200 flex items-center gap-2" @click.stop>
              <BaseButton @click="editPackage(pkg)" variant="ghost" size="sm" class="flex-1">
                <Edit2 class="w-3 h-3 mr-1" />
                Edit
              </BaseButton>
              <BaseButton 
                @click="toggleStatus(pkg)" 
                :variant="pkg.status === 'active' ? 'warning' : 'success'" 
                size="sm"
                class="flex-1"
              >
                {{ pkg.status === 'active' ? 'Deactivate' : 'Activate' }}
              </BaseButton>
              <BaseButton @click="deletePackage(pkg)" variant="ghost" size="sm" class="text-red-600">
                <Trash2 class="w-3 h-3" />
              </BaseButton>
            </div>
          </div>
        </div>
      </div>

      <!-- List View -->
      <div v-else>
        <BaseCard :padding="false">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Package</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Type</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Price</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Speed</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Data Limit</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Validity</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                  <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="pkg in paginatedData"
                  :key="pkg.id"
                  class="border-b border-slate-100 hover:bg-blue-50/50 transition-colors cursor-pointer"
                  @click="viewPackage(pkg)"
                >
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <div class="p-2 rounded-lg" :class="getIconBg(pkg.type)">
                        <component :is="getPackageIcon(pkg.type)" class="w-4 h-4" :class="getIconColor(pkg.type)" />
                      </div>
                      <div>
                        <div class="text-sm font-semibold text-slate-900">{{ pkg.name }}</div>
                        <div class="text-xs text-slate-500">{{ pkg.description }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <BaseBadge :variant="getTypeVariant(pkg.type)">
                      {{ pkg.type }}
                    </BaseBadge>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-bold text-slate-900">KES {{ formatMoney(pkg.price) }}</div>
                  </td>
                  <td class="px-6 py-4 text-sm text-slate-900">{{ pkg.speed }}</td>
                  <td class="px-6 py-4 text-sm text-slate-900">{{ pkg.data_limit || 'Unlimited' }}</td>
                  <td class="px-6 py-4 text-sm text-slate-900">{{ pkg.validity }}</td>
                  <td class="px-6 py-4">
                    <BaseBadge 
                      :variant="pkg.status === 'active' ? 'success' : 'secondary'"
                      :dot="pkg.status === 'active'"
                    >
                      {{ pkg.status }}
                    </BaseBadge>
                  </td>
                  <td class="px-6 py-4 text-right" @click.stop>
                    <div class="flex items-center justify-end gap-1">
                      <BaseButton @click="editPackage(pkg)" variant="ghost" size="sm">
                        <Edit2 class="w-3 h-3" />
                      </BaseButton>
                      <BaseButton 
                        @click="toggleStatus(pkg)" 
                        :variant="pkg.status === 'active' ? 'warning' : 'success'" 
                        size="sm"
                      >
                        {{ pkg.status === 'active' ? 'Deactivate' : 'Activate' }}
                      </BaseButton>
                      <BaseButton @click="deletePackage(pkg)" variant="ghost" size="sm" class="text-red-600">
                        <Trash2 class="w-3 h-3" />
                      </BaseButton>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>
      </div>
    </PageContent>

    <!-- Footer -->
    <PageFooter>
      <div class="text-sm text-slate-600">
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} packages
      </div>
      <BasePagination
        v-model="currentPage"
        :total-pages="totalPages"
        :total-items="filteredData.length"
      />
    </PageFooter>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { 
  Package, Plus, RefreshCw, X, Edit2, Trash2, Eye,
  CheckCircle, Wifi, Network, Zap, HardDrive, Clock, Users
} from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import PageFooter from '@/modules/common/components/layout/templates/PageFooter.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import BaseSearch from '@/modules/common/components/base/BaseSearch.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BasePagination from '@/modules/common/components/base/BasePagination.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'
import BaseEmpty from '@/modules/common/components/base/BaseEmpty.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'

// Breadcrumbs
const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Packages', to: '/dashboard/packages' },
  { label: 'All Packages' }
]

// State
const loading = ref(false)
const refreshing = ref(false)
const error = ref(null)
const packages = ref([])
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(9)
const viewMode = ref('grid')

const filters = ref({
  type: '',
  status: ''
})

// Mock data
const mockPackages = [
  {
    id: 1,
    name: '1 Hour - 5GB',
    description: 'Perfect for quick browsing',
    type: 'hotspot',
    price: 50,
    speed: '10 Mbps',
    data_limit: '5 GB',
    validity: '1 hour',
    status: 'active',
    users_count: 45
  },
  {
    id: 2,
    name: '1 Day - 20GB',
    description: 'Full day unlimited access',
    type: 'hotspot',
    price: 200,
    speed: '10 Mbps',
    data_limit: '20 GB',
    validity: '24 hours',
    status: 'active',
    users_count: 120
  },
  {
    id: 3,
    name: 'Home Basic - 10 Mbps',
    description: 'Residential internet package',
    type: 'pppoe',
    price: 2000,
    speed: '10 Mbps',
    data_limit: null,
    validity: '30 days',
    status: 'active',
    users_count: 35
  },
  {
    id: 4,
    name: 'Home Premium - 20 Mbps',
    description: 'Fast home internet',
    type: 'pppoe',
    price: 3500,
    speed: '20 Mbps',
    data_limit: null,
    validity: '30 days',
    status: 'active',
    users_count: 28
  },
  {
    id: 5,
    name: '1 Week - 50GB',
    description: 'Weekly hotspot package',
    type: 'hotspot',
    price: 500,
    speed: '10 Mbps',
    data_limit: '50 GB',
    validity: '7 days',
    status: 'active',
    users_count: 67
  },
  {
    id: 6,
    name: 'Business - 50 Mbps',
    description: 'For small businesses',
    type: 'pppoe',
    price: 7500,
    speed: '50 Mbps',
    data_limit: null,
    validity: '30 days',
    status: 'inactive',
    users_count: 0
  }
]

// Computed
const stats = computed(() => {
  return {
    total: packages.value.length,
    active: packages.value.filter(p => p.status === 'active').length,
    hotspot: packages.value.filter(p => p.type === 'hotspot').length,
    pppoe: packages.value.filter(p => p.type === 'pppoe').length
  }
})

const filteredData = computed(() => {
  let data = packages.value

  // Search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(p =>
      p.name.toLowerCase().includes(query) ||
      p.description.toLowerCase().includes(query)
    )
  }

  // Type filter
  if (filters.value.type) {
    data = data.filter(p => p.type === filters.value.type)
  }

  // Status filter
  if (filters.value.status) {
    data = data.filter(p => p.status === filters.value.status)
  }

  return data
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredData.value.slice(start, end)
})

const paginationInfo = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value + 1
  const end = Math.min(start + itemsPerPage.value - 1, filteredData.value.length)
  return {
    start,
    end,
    total: filteredData.value.length
  }
})

const hasActiveFilters = computed(() => {
  return filters.value.type || filters.value.status || searchQuery.value
})

// Methods
const fetchPackages = async () => {
  loading.value = true
  error.value = null
  
  try {
    // TODO: Replace with actual API call
    await new Promise(resolve => setTimeout(resolve, 500))
    packages.value = mockPackages
  } catch (err) {
    error.value = 'Failed to load packages. Please try again.'
    console.error('Error fetching packages:', err)
  } finally {
    loading.value = false
  }
}

const refreshPackages = async () => {
  refreshing.value = true
  error.value = null
  
  try {
    await new Promise(resolve => setTimeout(resolve, 500))
    packages.value = mockPackages
  } catch (err) {
    error.value = 'Failed to refresh packages.'
    console.error('Error refreshing packages:', err)
  } finally {
    refreshing.value = false
  }
}

const clearFilters = () => {
  filters.value = {
    type: '',
    status: ''
  }
  searchQuery.value = ''
}

const getPackageGradient = (type) => {
  return type === 'hotspot' 
    ? 'from-purple-500 to-indigo-600' 
    : 'from-cyan-500 to-blue-600'
}

const getPackageIcon = (type) => {
  return type === 'hotspot' ? Wifi : Network
}

const getIconColor = (type) => {
  return type === 'hotspot' ? 'text-purple-600' : 'text-cyan-600'
}

const getIconBg = (type) => {
  return type === 'hotspot' ? 'bg-purple-100' : 'bg-cyan-100'
}

const getTypeVariant = (type) => {
  return type === 'hotspot' ? 'purple' : 'info'
}

const formatMoney = (amount) => {
  return new Intl.NumberFormat('en-KE').format(amount)
}

const viewPackage = (pkg) => {
  console.log('View package:', pkg)
  // TODO: Implement package details view
}

const editPackage = (pkg) => {
  console.log('Edit package:', pkg)
  // TODO: Navigate to edit page
}

const toggleStatus = async (pkg) => {
  const action = pkg.status === 'active' ? 'deactivate' : 'activate'
  if (!confirm(`Are you sure you want to ${action} ${pkg.name}?`)) return
  
  try {
    // TODO: Implement API call
    await new Promise(resolve => setTimeout(resolve, 500))
    pkg.status = pkg.status === 'active' ? 'inactive' : 'active'
  } catch (err) {
    console.error(`Failed to ${action} package:`, err)
  }
}

const deletePackage = async (pkg) => {
  if (!confirm(`Are you sure you want to delete ${pkg.name}? This action cannot be undone.`)) return
  
  try {
    // TODO: Implement API call
    await new Promise(resolve => setTimeout(resolve, 500))
    packages.value = packages.value.filter(p => p.id !== pkg.id)
  } catch (err) {
    console.error('Failed to delete package:', err)
  }
}

// Lifecycle
onMounted(() => {
  fetchPackages()
})
</script>
