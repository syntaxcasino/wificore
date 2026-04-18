<template>
  <DataViewContainer
    title="Package Groups"
    subtitle="Organize packages into display groups"
    color-theme="blue"
    v-model:search-model="searchQuery"
    search-placeholder="Search groups..."
    :stats="[
      { color: 'bg-blue-500', value: totalGroups },
      { color: 'bg-emerald-500', value: activeGroups.length },
      { color: 'bg-amber-500', value: featuredGroups.length }
    ]"
    :total="groups.length"
    :loading="loading"
    add-button-text="Add Group"
    @refresh="fetchGroups"
    @add="openCreateModal"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
      </svg>
    </template>

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
        <option value="">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </BaseSelect>
    </template>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <X class="w-10 h-10" />
      <p class="text-center">{{ error }}</p>
      <button @click="fetchGroups" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="6" type="grid" />

    <!-- Data Content - Grid Layout -->
    <div v-else-if="filteredData.length" class="px-4 md:px-6 pt-2 pb-2">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div
          v-for="group in filteredData"
          :key="group.id"
          class="bg-white border border-slate-200 shadow-sm overflow-hidden flex-colue-400 hover:shadow-lg transition-all duration-200 overflow-hidden group cursor-pointer"
          @click="viewGroup(group)"
        >
          <!-- Group Header -->
          <div class="p-6 bg-gradient-to-br" :class="getGroupGradient(group.color)">
            <div class="flex items-start justify-between mb-4">
              <div class="p-3 bg-white/90 rounded-lg">
                <Layers class="w-6 h-6" :class="getIconColor(group.color)" />
              </div>
              <div class="flex items-center gap-2">
                <EntityStatusBadge v-if="group.is_featured" status="featured" size="sm" />
                <EntityStatusBadge :status="group.status" size="sm" />
              </div>
            </div>
            
            <h3 class="text-xl font-bold text-white mb-1">{{ group.name }}</h3>
            <p class="text-white/80 text-sm line-clamp-2">{{ group.description }}</p>
          </div>

          <!-- Group Details -->
          <div class="p-6 space-y-4">
            <!-- Package Count -->
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                <Package class="w-4 h-4" />
                <span>{{ group.packages_count }} packages</span>
              </div>
              <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                Order: {{ group.display_order }}
              </div>
            </div>

            <!-- Package List Preview -->
            <div v-if="group.packages && group.packages.length" class="space-y-2">
              <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase">Packages</div>
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
              <button @click="editGroup(group)" class="flex-1 px-3 py-1.5 text-xs font-medium text-slate-700 bg-slate-100 rounded hover:bg-slate-200 transition-colors">
                <Edit2 class="w-3 h-3 mr-1 inline" /> Edit
              </button>
              <button 
                @click="toggleStatus(group)" 
                :class="group.status === 'active' ? 'text-amber-700 bg-amber-50 hover:bg-amber-100' : 'text-green-700 bg-green-50 hover:bg-green-100'" 
                class="flex-1 px-3 py-1.5 text-xs font-medium rounded transition-colors"
              >
                {{ group.status === 'active' ? 'Deactivate' : 'Activate' }}
              </button>
              <button @click="deleteGroup(group)" class="px-3 py-1.5 text-xs font-medium text-red-600 bg-red-50 rounded hover:bg-red-100 transition-colors">
                <Trash2 class="w-3 h-3" />
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Matches Found' : 'No Package Groups'"
      :description="searchQuery ? 'No groups match your search criteria.' : 'Get started by creating your first package group.'"
      icon="layers"
      color-theme="blue"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      clear-text="Clear Search"
      add-text="Add Group"
      @clear="searchQuery = ''"
      @add="openCreateModal"
    />
  </DataViewContainer>

  <!-- Create/Edit Overlay -->
  <SlideOverlay
    v-model="showModal"
    :title="editingGroup ? 'Edit Group' : 'Create Group'"
    :subtitle="editingGroup ? 'Update group details' : 'Add a new package group'"
    icon="Layers"
    width="60%"
  >
    <div class="p-6 space-y-4">
      <!-- Group Name -->
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Group Name *</label>
        <input v-model="formData.name" type="text" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="e.g., Home Packages" />
      </div>

      <!-- Description -->
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Description</label>
        <textarea v-model="formData.description" rows="3" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="Brief description of the group..."></textarea>
      </div>

      <!-- Color Selection -->
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-2">Color Theme</label>
        <div class="grid grid-cols-4 gap-3">
          <div
            v-for="color in colorOptions"
            :key="color.value"
            @click="formData.color = color.value"
            class="relative p-3 rounded-lg cursor-pointer border-2 transition-all text-center"
            :class="[color.bg, formData.color === color.value ? 'border-slate-900' : 'border-slate-200 hover:border-slate-400']"
          >
            <div class="text-xs font-medium" :class="color.text">{{ color.label }}</div>
            <CheckCircle v-if="formData.color === color.value" class="absolute top-1 right-1 w-3 h-3 text-slate-900" />
          </div>
        </div>
      </div>

      <!-- Display Order -->
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Display Order</label>
        <input v-model.number="formData.display_order" type="number" min="0" step="1" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="e.g., 1" />
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Lower numbers appear first</p>
      </div>

      <!-- Settings -->
      <div class="space-y-3">
        <div class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-700/50 rounded-lg">
          <div>
            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">Active</div>
            <div class="text-xs text-slate-500 dark:text-slate-400">Show this group to users</div>
          </div>
          <label class="relative inline-flex items-center cursor-pointer">
            <input v-model="formData.is_active" type="checkbox" class="sr-only peer" />
            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
          </label>
        </div>

        <div class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-700/50 rounded-lg">
          <div>
            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">Featured</div>
            <div class="text-xs text-slate-500 dark:text-slate-400">Highlight this group</div>
          </div>
          <label class="relative inline-flex items-center cursor-pointer">
            <input v-model="formData.is_featured" type="checkbox" class="sr-only peer" />
            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
          </label>
        </div>
      </div>
    </div>

    <template #footer>
      <div class="flex gap-3">
        <button
          @click="showModal = false"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
        >
          Cancel
        </button>
        <button
          @click="handleSubmit"
          :disabled="saving"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors disabled:opacity-50"
        >
          {{ saving ? (editingGroup ? 'Updating...' : 'Creating...') : (editingGroup ? 'Update' : 'Create') + ' Group' }}
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { onMounted } from 'vue'
import { Layers, X, Edit2, Trash2, CheckCircle, Package } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import { usePackageGroups } from '@/modules/tenant/composables/usePackageGroups.js'
import { useFilters } from '@/modules/common/composables/utils/useFilters.js'

const {
  loading, error, groups, editingGroup, saving, showModal, formData, colorOptions,
  totalGroups, activeGroups, featuredGroups,
  getGroupGradient, getIconColor, formatMoney,
  fetchGroups, openCreateModal, editGroup, handleSubmit, toggleStatus, deleteGroup
} = usePackageGroups()

const { filters, searchQuery, filteredData, hasActiveFilters } = useFilters(groups, { status: '' })

onMounted(fetchGroups)
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
