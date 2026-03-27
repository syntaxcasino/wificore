<template>
  <DataViewContainer
    title="Expense Management"
    subtitle="Track and manage business expenses"
    color-theme="purple"
    v-model:search-model="searchQuery"
    search-placeholder="Search expenses by number, description, or vendor..."
    :stats="[
      { color: 'bg-yellow-500', value: pendingCount },
      { color: 'bg-emerald-500', value: approvedCount }
    ]"
    :total="expenses.length"
    :loading="loading"
    add-button-text="Add Expense"
    @refresh="fetchExpenses"
    @add="openCreateModal"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
      </svg>
    </template>

    <!-- SlideOverlay for Create/Edit -->
    <SlideOverlay
      v-model="showFormOverlay"
      :title="isEditing ? 'Edit Expense' : 'Add Expense'"
      :subtitle="isEditing ? 'Update expense details' : 'Create a new expense entry'"
      icon="wallet"
      width="480px"
      @close="closeForm"
    >
      <div class="p-6 space-y-4">
        <!-- Expense Number -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Expense Number</label>
          <input
            v-model="formData.expense_number"
            type="text"
            placeholder="EXP-001"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none"
          />
        </div>

        <!-- Description -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
          <textarea
            v-model="formData.description"
            rows="3"
            placeholder="Enter expense description..."
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none resize-none"
          />
        </div>

        <!-- Vendor -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Vendor Name</label>
          <input
            v-model="formData.vendor_name"
            type="text"
            placeholder="Vendor or supplier name"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none"
          />
        </div>

        <!-- Amount -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Amount</label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500">$</span>
            <input
              v-model="formData.amount"
              type="number"
              step="0.01"
              placeholder="0.00"
              class="w-full pl-8 pr-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none"
            />
          </div>
        </div>

        <!-- Status -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
          <select
            v-model="formData.status"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none bg-white"
          >
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="paid">Paid</option>
          </select>
        </div>

        <!-- Error Message -->
        <div v-if="formError" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-600">
          {{ formError }}
        </div>
      </div>

      <!-- Actions -->
      <template #footer>
        <div class="flex gap-3">
          <button
            @click="closeForm"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
          >
            Cancel
          </button>
          <button
            @click="handleSubmit"
            :disabled="formSubmitting"
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-purple-600 hover:bg-purple-700 rounded-lg transition-colors disabled:opacity-50"
          >
            {{ formSubmitting ? 'Saving...' : (isEditing ? 'Save Changes' : 'Create Expense') }}
          </button>
        </div>
      </template>
    </SlideOverlay>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-center">{{ error }}</p>
      <button @click="fetchExpenses" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">
        Retry
      </button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredExpenses.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="expense in paginatedExpenses"
          :key="expense.id"
          :title="expense.expense_number"
          :subtitle="expense.description"
          :meta-lines="[{ text: expense.vendor_name || 'No vendor' }]"
          :status="expense.status"
          :value="`$${formatAmount(expense.amount)}`"
          value-class="text-slate-900"
          :actions="getExpenseActions(expense)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Expense #</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Description</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Vendor</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Amount</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Date</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="expense in paginatedExpenses" :key="expense.id" class="hover:bg-purple-50/50 transition-colors">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <span :class="getStatusDotClass(expense.status)" class="w-1.5 h-1.5 rounded-full"></span>
                    <span class="text-sm font-medium text-slate-900">{{ expense.expense_number }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-slate-700">{{ expense.description }}</span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-slate-600">{{ expense.vendor_name || '-' }}</span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm font-semibold text-slate-900">${{ formatAmount(expense.amount) }}</span>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="expense.status" size="sm" />
                </td>
                <td class="px-6 py-4 hidden lg:table-cell">
                  <span class="text-xs text-slate-500">{{ formatDate(expense.created_at) }}</span>
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button
                      v-if="expense.status === 'pending'"
                      @click="handleApprove(expense)"
                      class="px-2 py-1 text-xs font-medium text-emerald-700 bg-emerald-50 rounded hover:bg-emerald-100 transition-colors"
                    >
                      Approve
                    </button>
                    <button
                      v-if="expense.status === 'approved'"
                      @click="handlePay(expense)"
                      class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors"
                    >
                      Pay
                    </button>
                    <button @click="openEditModal(expense)" class="px-2 py-1 text-xs font-medium text-slate-700 bg-slate-100 rounded hover:bg-slate-200 transition-colors">
                      Edit
                    </button>
                    <button @click="handleDelete(expense)" class="px-2 py-1 text-xs font-medium text-red-600 bg-red-50 rounded hover:bg-red-100 transition-colors">
                      Delete
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <DataPagination
        v-model:current-page="currentPage"
        v-model:items-per-page="itemsPerPage"
        :total-pages="totalPages"
        :total-items="filteredExpenses.length"
        item-name="expenses"
        class="mt-auto"
      />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Matches Found' : 'No Expenses Found'"
      :description="searchQuery ? 'No expenses match your search criteria. Try adjusting your filters.' : 'Get started by adding your first expense to begin tracking your business spending.'"
      icon="wallet"
      color-theme="purple"
      :show-clear="!!searchQuery"
      :has-filters="!!searchQuery"
      clear-text="Clear Search"
      add-text="Add Your First Expense"
      @clear="searchQuery = ''"
      @add="openCreateModal"
    />
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useExpenses } from '@/modules/tenant/composables/useExpenses'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const {
  expenses,
  pendingExpenses,
  approvedExpenses,
  loading,
  error,
  fetchExpenses,
  fetchStatistics,
  createExpense,
  updateExpense,
  deleteExpense,
  approveExpense,
  markAsPaidExpense,
  searchExpenses,
  setupWebSocketListeners,
  cleanupWebSocketListeners
} = useExpenses()

const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)

// Form state
const showFormOverlay = ref(false)
const isEditing = ref(false)
const editingId = ref(null)
const formSubmitting = ref(false)
const formError = ref('')
const formData = ref({
  expense_number: '',
  description: '',
  vendor_name: '',
  amount: '',
  status: 'pending'
})

// Stats
const pendingCount = computed(() => pendingExpenses.value.length)
const approvedCount = computed(() => approvedExpenses.value.length)

// Filter and paginate
const filteredExpenses = computed(() => {
  if (!searchQuery.value) return expenses.value
  return searchExpenses(searchQuery.value)
})

const paginatedExpenses = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredExpenses.value.slice(start, end)
})

const totalPages = computed(() => Math.ceil(filteredExpenses.value.length / itemsPerPage.value))

// Reset page on search change
watch(searchQuery, () => { currentPage.value = 1 })
watch(itemsPerPage, () => { currentPage.value = 1 })

// Status helpers
const getStatusDotClass = (status) => {
  const colors = { pending: 'bg-yellow-500', approved: 'bg-emerald-500', rejected: 'bg-red-500', paid: 'bg-blue-500' }
  return colors[status] || 'bg-slate-400'
}

// Formatters
const formatAmount = (amount) => Number(amount).toFixed(2)
const formatDate = (dateString) => dateString ? new Date(dateString).toLocaleDateString() : '-'

// Form helpers
const resetForm = () => {
  formData.value = {
    expense_number: '',
    description: '',
    vendor_name: '',
    amount: '',
    status: 'pending'
  }
  formError.value = ''
  isEditing.value = false
  editingId.value = null
}

const openCreateModal = () => {
  resetForm()
  showFormOverlay.value = true
}

const openEditModal = (expense) => {
  isEditing.value = true
  editingId.value = expense.id
  formData.value = {
    expense_number: expense.expense_number || '',
    description: expense.description || '',
    vendor_name: expense.vendor_name || '',
    amount: expense.amount || '',
    status: expense.status || 'pending'
  }
  showFormOverlay.value = true
}

const closeForm = () => {
  showFormOverlay.value = false
  setTimeout(resetForm, 300)
}

const handleSubmit = async () => {
  formSubmitting.value = true
  formError.value = ''

  try {
    if (isEditing.value) {
      await updateExpense(editingId.value, formData.value)
    } else {
      await createExpense(formData.value)
    }
    closeForm()
    await fetchExpenses()
  } catch (err) {
    formError.value = err.message || 'Failed to save expense'
  } finally {
    formSubmitting.value = false
  }
}

// Actions
const getExpenseActions = (expense) => {
  const actions = []
  if (expense.status === 'pending') {
    actions.push({ label: 'Approve', onClick: () => handleApprove(expense), class: 'text-emerald-700 bg-emerald-50 hover:bg-emerald-100' })
  }
  if (expense.status === 'approved') {
    actions.push({ label: 'Pay', onClick: () => handlePay(expense), class: 'text-blue-700 bg-blue-50 hover:bg-blue-100' })
  }
  actions.push({ label: 'Edit', onClick: () => openEditModal(expense), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' })
  return actions
}

const handleApprove = async (expense) => {
  try { await approveExpense(expense.id) } catch (err) { console.error('Failed to approve expense:', err) }
}

const handlePay = async (expense) => {
  try { await markAsPaidExpense(expense.id, { payment_method: 'cash' }) } catch (err) { console.error('Failed to pay expense:', err) }
}

const handleDelete = async (expense) => {
  if (confirm(`Are you sure you want to delete ${expense.expense_number}?`)) {
    try { await deleteExpense(expense.id) } catch (err) { console.error('Failed to delete expense:', err) }
  }
}

// Lifecycle
onMounted(async () => {
  await fetchExpenses()
  await fetchStatistics()
  setupWebSocketListeners()
})

onUnmounted(() => cleanupWebSocketListeners())
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
