<template>
  <DataViewContainer
    title="Voucher Management"
    subtitle="Generate and manage hotspot vouchers"
    color-theme="cyan"
    v-model:search-model="searchQuery"
    search-placeholder="Search by voucher code..."
    :stats="statsForView"
    :total="pagination.total"
    :loading="loading"
    add-button-text="Create Voucher"
    @refresh="refreshVouchers"
    @add="openCreateOverlay"
    @search-clear="searchQuery = ''"
  >

    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
      </svg>
    </template>

    <!-- Create Voucher Overlay -->
    <SlideOverlay
      v-model="showCreateOverlay"
      title="Create Voucher"
      subtitle="Generate new hotspot vouchers"
      icon="Ticket"
      width="70%"
      gradient
      no-padding
      :close-on-backdrop="!generating"
      @close="closeCreateOverlay"
    >
      <div class="flex flex-col h-full overflow-hidden bg-slate-50">
        <!-- Header Strip -->
        <div class="flex-shrink-0 bg-gradient-to-r from-cyan-600 to-teal-600 px-6 py-4">
          <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center text-white text-xl font-bold shadow-lg flex-shrink-0">
              <Ticket class="w-7 h-7 text-white" />
            </div>
            <div class="flex-1 min-w-0">
              <div class="text-lg font-bold text-white truncate">New Voucher Batch</div>
              <div class="text-sm text-white/70 mt-0.5">
                {{ formData.quantity || 0 }} voucher{{ formData.quantity !== 1 ? 's' : '' }}
                <span v-if="selectedPackage"> &middot; {{ selectedPackage.name }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto min-h-0 p-6">
          <form @submit.prevent="handleGenerate" class="space-y-5">
            <!-- Package Selection -->
            <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
              <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center gap-2">
                <svg class="h-4 w-4 text-cyan-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
                Package Selection
              </h4>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Select Package *</label>
                <select v-model="formData.package_id" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 bg-white">
                  <option value="">Choose a package...</option>
                  <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
                </select>
                <p v-if="selectedPackage" class="mt-2 text-xs text-slate-500 bg-slate-50 rounded-lg p-2">
                  <span class="font-medium">Price:</span> KES {{ selectedPackage.price }} &nbsp;|&nbsp;
                  <span class="font-medium">Speed:</span> {{ selectedPackage.download_speed || '-' }} &nbsp;|&nbsp;
                  <span class="font-medium">Validity:</span> {{ selectedPackage.validity || selectedPackage.duration || '-' }}
                </p>
              </div>
            </div>

            <!-- Voucher Settings -->
            <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
              <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center gap-2">
                <svg class="h-4 w-4 text-cyan-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                Voucher Settings
              </h4>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-2">Number of Vouchers *</label>
                  <input v-model.number="formData.quantity" type="number" min="1" max="100" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500" placeholder="1-100" />
                  <p class="mt-1 text-xs text-slate-400">Maximum 100 vouchers per batch</p>
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-2">Voucher Prefix (Optional)</label>
                  <input v-model="formData.prefix" type="text" maxlength="10" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500" placeholder="e.g., WIFI, HOT" />
                </div>
              </div>
            </div>

            <!-- Additional Options -->
            <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
              <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center gap-2">
                <svg class="h-4 w-4 text-cyan-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                Additional Options
              </h4>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-2">Unused Voucher Expiry (Optional)</label>
                  <input v-model="formData.expires_at" type="date" :min="minDate" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500" />
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-2">Notes (Optional)</label>
                  <textarea v-model="formData.notes" rows="1" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 resize-none" placeholder="Any notes..."></textarea>
                </div>
              </div>
            </div>

            <!-- Summary -->
            <div v-if="formData.package_id && formData.quantity" class="bg-cyan-50 border border-cyan-200 rounded-xl p-4">
              <h4 class="text-sm font-semibold text-cyan-900 mb-3 flex items-center gap-2">
                <svg class="h-4 w-4 text-cyan-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
                Summary
              </h4>
              <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                <div class="bg-white/70 rounded-lg p-2.5">
                  <div class="text-xs text-cyan-600 mb-0.5">Package</div>
                  <div class="font-semibold text-cyan-900 truncate">{{ selectedPackage?.name }}</div>
                </div>
                <div class="bg-white/70 rounded-lg p-2.5">
                  <div class="text-xs text-cyan-600 mb-0.5">Quantity</div>
                  <div class="font-semibold text-cyan-900">{{ formData.quantity }}</div>
                </div>
                <div class="bg-white/70 rounded-lg p-2.5">
                  <div class="text-xs text-cyan-600 mb-0.5">Total Value</div>
                  <div class="font-semibold text-cyan-900">KES {{ totalValue }}</div>
                </div>
                <div class="bg-white/70 rounded-lg p-2.5">
                  <div class="text-xs text-cyan-600 mb-0.5">Prefix</div>
                  <div class="font-semibold text-cyan-900">{{ formData.prefix || 'None' }}</div>
                </div>
              </div>
            </div>

            <!-- Error -->
            <div v-if="generateError" class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700 flex items-center gap-2">
              <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              {{ generateError }}
            </div>
          </form>
        </div>

        <!-- Footer -->
        <div class="flex-shrink-0 px-6 py-4 border-t border-slate-200 bg-white flex gap-3">
          <button type="button" @click="closeCreateOverlay" class="flex-1 px-4 py-2.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">Cancel</button>
          <button @click="handleGenerate" :disabled="generating || !formData.package_id || !formData.quantity" class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-white bg-cyan-600 rounded-lg hover:bg-cyan-700 transition-colors disabled:opacity-50 shadow-sm">
            <Ticket class="w-4 h-4" />
            {{ generating ? 'Generating...' : `Generate ${formData.quantity || 0} Voucher${formData.quantity !== 1 ? 's' : ''}` }}
          </button>
        </div>
      </div>
    </SlideOverlay>

    <!-- Voucher Detail Overlay -->
    <SlideOverlay
      v-model="showDetailOverlay"
      title="Voucher Details"
      subtitle="View voucher information"
      icon="Ticket"
      width="70%"
      gradient
      no-padding
      @close="closeDetailOverlay"
    >
      <div v-if="selectedVoucher" class="flex flex-col h-full overflow-hidden bg-slate-50">
        <!-- Header Strip -->
        <div class="flex-shrink-0 bg-gradient-to-r from-cyan-600 to-teal-600 px-6 py-4">
          <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center shadow-lg flex-shrink-0">
              <Ticket class="w-7 h-7 text-white" />
            </div>
            <div class="flex-1 min-w-0">
              <div class="text-lg font-bold text-white truncate">Voucher Detail</div>
              <div class="text-sm text-white/70 mt-0.5 font-mono">{{ selectedVoucher.code }}</div>
            </div>
            <button
              @click="copyToClipboard(selectedVoucher.code, selectedVoucher.id)"
              class="flex items-center gap-1.5 px-3 py-1.5 bg-white/20 hover:bg-white/30 text-white rounded-lg text-xs font-medium transition-colors backdrop-blur"
              :title="copiedId === selectedVoucher.id ? 'Copied!' : 'Copy code'"
            >
              <Check v-if="copiedId === selectedVoucher.id" class="w-3.5 h-3.5" />
              <Copy v-else class="w-3.5 h-3.5" />
              {{ copiedId === selectedVoucher.id ? 'Copied' : 'Copy' }}
            </button>
          </div>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto min-h-0 p-6 space-y-4">
          <!-- Voucher Code Card -->
          <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between mb-3">
              <h4 class="text-sm font-semibold text-slate-700 flex items-center gap-2">
                <svg class="h-4 w-4 text-cyan-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" /></svg>
                Voucher Code
              </h4>
              <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium capitalize" :class="statusClass(selectedVoucher.status, selectedVoucher.archived_at)">
                {{ selectedVoucher.archived_at ? 'archived' : selectedVoucher.status }}
              </span>
            </div>
            <div class="flex items-center gap-3">
              <div class="flex-1 bg-slate-50 border border-slate-200 rounded-lg px-4 py-3 font-mono text-lg font-bold text-slate-800 tracking-wide">
                {{ selectedVoucher.code }}
              </div>
              <button
                @click="copyToClipboard(selectedVoucher.code, selectedVoucher.id)"
                class="p-3 bg-cyan-50 hover:bg-cyan-100 text-cyan-600 rounded-lg transition-colors"
                :title="copiedId === selectedVoucher.id ? 'Copied!' : 'Copy code'"
              >
                <Check v-if="copiedId === selectedVoucher.id" class="w-5 h-5" />
                <Copy v-else class="w-5 h-5" />
              </button>
            </div>
          </div>

          <!-- Package Info -->
          <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center gap-2">
              <svg class="h-4 w-4 text-cyan-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
              Package Information
            </h4>
            <div class="grid grid-cols-2 gap-3">
              <div class="bg-slate-50 rounded-lg p-3">
                <div class="text-xs text-slate-500 mb-0.5">Package</div>
                <div class="text-sm font-semibold text-slate-900">{{ selectedVoucher.package?.name || '-' }}</div>
              </div>
              <div class="bg-slate-50 rounded-lg p-3">
                <div class="text-xs text-slate-500 mb-0.5">Price</div>
                <div class="text-sm font-semibold text-slate-900">{{ selectedVoucher.package?.price ? `KES ${selectedVoucher.package.price}` : '-' }}</div>
              </div>
              <div class="bg-slate-50 rounded-lg p-3">
                <div class="text-xs text-slate-500 mb-0.5">Speed</div>
                <div class="text-sm font-semibold text-slate-900">{{ selectedVoucher.package?.download_speed || '-' }}</div>
              </div>
              <div class="bg-slate-50 rounded-lg p-3">
                <div class="text-xs text-slate-500 mb-0.5">Router</div>
                <div class="text-sm font-semibold text-slate-900">{{ selectedVoucher.router?.name || 'Any' }}</div>
              </div>
            </div>
          </div>

          <!-- Usage Info -->
          <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center gap-2">
              <svg class="h-4 w-4 text-cyan-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              Usage Information
            </h4>
            <div class="grid grid-cols-2 gap-3">
              <div class="bg-slate-50 rounded-lg p-3">
                <div class="text-xs text-slate-500 mb-0.5">Unused Expiry</div>
                <div class="text-sm font-semibold text-slate-900">{{ selectedVoucher.expires_at ? formatDate(selectedVoucher.expires_at) : 'No expiry' }}</div>
              </div>
              <div class="bg-slate-50 rounded-lg p-3">
                <div class="text-xs text-slate-500 mb-0.5">Created</div>
                <div class="text-sm font-semibold text-slate-900">{{ formatDate(selectedVoucher.created_at) }}</div>
              </div>
              <div v-if="selectedVoucher.used_at" class="bg-slate-50 rounded-lg p-3">
                <div class="text-xs text-slate-500 mb-0.5">Used At</div>
                <div class="text-sm font-semibold text-slate-900">{{ formatDate(selectedVoucher.used_at) }}</div>
              </div>
              <div v-if="selectedVoucher.batch_id" class="bg-slate-50 rounded-lg p-3">
                <div class="text-xs text-slate-500 mb-0.5">Batch ID</div>
                <div class="text-xs font-mono text-slate-700">{{ selectedVoucher.batch_id }}</div>
              </div>
            </div>
            <div v-if="selectedVoucher.notes" class="mt-3 bg-slate-50 rounded-lg p-3">
              <div class="text-xs text-slate-500 mb-0.5">Notes</div>
              <div class="text-sm text-slate-700">{{ selectedVoucher.notes }}</div>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="flex-shrink-0 px-6 py-4 border-t border-slate-200 bg-white flex gap-3 flex-wrap">
          <button type="button" @click="closeDetailOverlay" class="flex-1 min-w-[80px] px-4 py-2.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">Close</button>
          <button v-if="selectedVoucher?.archived_at" @click="handleRestore(selectedVoucher)" class="flex-1 min-w-[100px] inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 transition-colors shadow-sm">
            <RotateCcw class="w-4 h-4" />Restore
          </button>
          <button v-if="selectedVoucher && isVoucherArchivable(selectedVoucher)" @click="handleArchive(selectedVoucher)" class="flex-1 min-w-[100px] inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 transition-colors shadow-sm">
            <Archive class="w-4 h-4" />Archive
          </button>
          <button v-if="selectedVoucher?.status === 'unused' && !selectedVoucher?.used_by && !selectedVoucher?.archived_at" @click="handleRevoke(selectedVoucher)" class="flex-1 min-w-[100px] inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors shadow-sm">
            <Ban class="w-4 h-4" />Revoke
          </button>
        </div>
      </div>
    </SlideOverlay>

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filterStatus" placeholder="All Statuses" class="w-40" @change="handleFilterChange">
        <option value="">All Statuses</option>
        <option value="unused">Unused</option>
        <option value="used">Used</option>
        <option value="expired">Expired</option>
        <option value="revoked">Revoked</option>
      </BaseSelect>
      <BaseSelect v-model="filterPackage" placeholder="All Packages" class="w-48" @change="handleFilterChange">
        <option value="">All Packages</option>
        <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
      </BaseSelect>
      <label class="inline-flex items-center gap-2 text-sm text-slate-600 cursor-pointer select-none">
        <input
          v-model="showArchived"
          type="checkbox"
          class="w-4 h-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500"
          @change="handleShowArchivedChange"
        />
        <span>Show Archived</span>
      </label>
      <button v-if="hasActiveFilters" @click="clearFilters" class="text-xs text-cyan-600 hover:text-cyan-700 font-medium">Clear filters</button>
    </template>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <Ticket class="w-10 h-10" />
      <p class="text-center">{{ error }}</p>
      <button @click="refreshVouchers" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading && !vouchers.length" :count="5" />

    <!-- Data Content -->
    <div v-else-if="vouchers.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
      <!-- Bulk Actions Toolbar -->
      <div v-if="selectedCount > 0" class="flex items-center gap-3 px-4 py-2.5 bg-cyan-50 border border-cyan-200 rounded-lg mb-3 flex-shrink-0">
        <span class="text-sm font-medium text-cyan-800">{{ selectedCount }} selected</span>
        <div class="flex-1"></div>
        <button @click="handleExportSelected" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-cyan-700 bg-white border border-cyan-300 rounded-md hover:bg-cyan-50 transition-colors">
          <Download class="w-3.5 h-3.5" />
          Export
        </button>
        <button @click="handleBulkArchive" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-amber-700 bg-white border border-amber-300 rounded-md hover:bg-amber-50 transition-colors">
          <Archive class="w-3.5 h-3.5" />
          Archive
        </button>
        <button @click="clearSelection" class="text-xs text-slate-500 hover:text-slate-700 font-medium px-2">Clear</button>
      </div>

      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="voucher in paginatedVouchers"
          :key="voucher.id"
          :title="voucher.code"
          :subtitle="voucher.package?.name || 'No package'"
          :meta-lines="[
            { text: `Status: ${voucher.status}${voucher.archived_at ? ' (archived)' : ''}`, class: statusClass(voucher.status, voucher.archived_at) },
            { text: `Unused Expiry: ${voucher.expires_at ? formatDate(voucher.expires_at) : 'No expiry'}` },
            { text: `Created: ${formatDate(voucher.created_at)}` }
          ]"
          :status="voucher.status"
          :actions="getVoucherActions(voucher)"
          hoverable
        >
          <template #prefix>
            <input
              type="checkbox"
              :checked="selectedIds.has(voucher.id)"
              @click.stop="toggleSelect(voucher)"
              class="w-4 h-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 flex-shrink-0"
            />
          </template>
        </MobileDataCard>
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border-x border-t border-slate-200 flex-col min-h-0 flex-1">
        <!-- Fixed Header -->
        <div class="bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
          <table class="w-full">
            <thead>
              <tr>
                <th class="px-3 py-3 w-[3%]">
                  <input
                    type="checkbox"
                    :checked="isAllSelected"
                    :indeterminate="isIndeterminate"
                    @change="toggleSelectAll"
                    class="w-4 h-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500"
                  />
                </th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[18%]">Code</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[23%]">Package</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[12%]">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[17%]">Unused Expiry</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[14%]">Created</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider w-[13%]">Actions</th>
              </tr>
            </thead>
          </table>
        </div>
        <!-- Scrollable Body -->
        <div class="overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
              <tr
                v-for="voucher in paginatedVouchers"
                :key="voucher.id"
                @click="openDetailOverlay(voucher)"
                class="hover:bg-cyan-50/50 transition-colors cursor-pointer"
                :class="voucher.archived_at ? 'opacity-60' : ''"
              >
                <td class="px-3 py-4 w-[3%]" @click.stop>
                  <input
                    type="checkbox"
                    :checked="selectedIds.has(voucher.id)"
                    @change="toggleSelect(voucher)"
                    class="w-4 h-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500"
                  />
                </td>
                <td class="px-6 py-4 w-[18%]">
                  <div class="flex items-center gap-2">
                    <span class="font-mono text-sm font-semibold" :class="voucher.archived_at ? 'text-gray-500' : 'text-cyan-700'">{{ voucher.code }}</span>
                    <button
                      @click.stop="copyToClipboard(voucher.code, voucher.id)"
                      class="p-1 text-cyan-500 hover:text-cyan-700 hover:bg-cyan-50 rounded transition-colors"
                      :title="copiedId === voucher.id ? 'Copied!' : 'Copy code'"
                    >
                      <Check v-if="copiedId === voucher.id" class="w-3.5 h-3.5" />
                      <Copy v-else class="w-3.5 h-3.5" />
                    </button>
                  </div>
                </td>
                <td class="px-6 py-4 w-[23%]">
                  <span class="text-sm" :class="voucher.archived_at ? 'text-gray-400' : 'text-slate-900'">{{ voucher.package?.name || '-' }}</span>
                </td>
                <td class="px-6 py-4 w-[12%]">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium capitalize" :class="statusClass(voucher.status, voucher.archived_at)">
                    {{ voucher.archived_at ? 'archived' : voucher.status }}
                  </span>
                </td>
                <td class="px-6 py-4 w-[17%]">
                  <span class="text-xs text-slate-500 dark:text-slate-400">{{ voucher.expires_at ? formatDate(voucher.expires_at) : 'No expiry' }}</span>
                </td>
                <td class="px-6 py-4 w-[14%]">
                  <span class="text-xs text-slate-500 dark:text-slate-400">{{ formatDate(voucher.created_at) }}</span>
                </td>
                <td class="px-6 py-4 text-right w-[13%]">
                  <div class="flex items-center justify-end gap-1">
                    <button @click.stop="openDetailOverlay(voucher)" class="p-1.5 text-cyan-600 hover:bg-cyan-50 rounded-md transition-colors" title="View Details">
                      <Eye class="w-4 h-4" />
                    </button>
                    <button v-if="isVoucherArchivable(voucher)" @click.stop="handleArchive(voucher)" class="p-1.5 text-amber-500 hover:bg-amber-50 rounded-md transition-colors" title="Archive Voucher">
                      <Archive class="w-4 h-4" />
                    </button>
                    <button v-if="voucher.archived_at" @click.stop="handleRestore(voucher)" class="p-1.5 text-emerald-500 hover:bg-emerald-50 rounded-md transition-colors" title="Restore Voucher">
                      <RotateCcw class="w-4 h-4" />
                    </button>
                    <button v-if="voucher.status === 'unused' && !voucher.used_by && !voucher.archived_at" @click.stop="handleRevoke(voucher)" class="p-1.5 text-red-500 hover:bg-red-50 rounded-md transition-colors" title="Revoke Voucher">
                      <Ban class="w-4 h-4" />
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
        :total-pages="pagination.lastPage"
        :total-items="pagination.total"
        item-name="vouchers"
        class="mt-auto"
        @pageChange="handlePageChange"
      />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery || hasActiveFilters ? 'No Matches Found' : 'No Vouchers'"
      :description="searchQuery || hasActiveFilters ? 'No vouchers match your search criteria.' : 'Create your first voucher to get started.'"
      icon="box"
      color-theme="cyan"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      clear-text="Clear Filters"
      add-text="Create Voucher"
      @clear="clearFilters"
      @add="openCreateOverlay"
    />
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { Ticket, Eye, Ban, Copy, Check, Archive, Download, RotateCcw } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useVouchers } from '@/modules/tenant/composables/useVouchers'
import { useConfirmStore } from '@/stores/confirm'
import { useToast } from '@/modules/common/composables/useToast.js'

const confirmStore = useConfirmStore()
const { error: showError } = useToast()

const {
  vouchers,
  packages,
  loading,
  error,
  generating,
  generateError,
  pagination,
  statsForView,
  fetchPackages,
  fetchVouchers,
  refreshVouchers,
  setFilters,
  fetchStats,
  fetchVoucherDetails,
  generateVouchers,
  revokeVoucher,
  archiveVoucher,
  restoreVoucher,
  bulkArchiveVouchers,
  exportVouchers,
  statusClass,
  formatDate,
  getPackageById,
  calculateTotalValue,
  setupWebSocketListeners,
  cleanupWebSocketListeners
} = useVouchers()

// State
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(25)
const showCreateOverlay = ref(false)
const showDetailOverlay = ref(false)
const selectedVoucher = ref(null)
const filterStatus = ref('')
const filterPackage = ref('')
const showArchived = ref(false)

// Selection state
const selectedIds = ref(new Set())

const hasActiveFilters = computed(() => !!(filterStatus.value || filterPackage.value || searchQuery.value || showArchived.value))

const selectedCount = computed(() => selectedIds.value.size)
const isAllSelected = computed(() => {
  const visible = paginatedVouchers.value.filter(v => !v.archived_at || showArchived.value)
  return visible.length > 0 && visible.every(v => selectedIds.value.has(v.id))
})
const isIndeterminate = computed(() => selectedCount.value > 0 && !isAllSelected.value)

const toggleSelectAll = () => {
  if (isAllSelected.value) {
    paginatedVouchers.value.forEach(v => selectedIds.value.delete(v.id))
  } else {
    paginatedVouchers.value.forEach(v => selectedIds.value.add(v.id))
  }
  selectedIds.value = new Set(selectedIds.value)
}

const toggleSelect = (voucher) => {
  if (selectedIds.value.has(voucher.id)) {
    selectedIds.value.delete(voucher.id)
  } else {
    selectedIds.value.add(voucher.id)
  }
  selectedIds.value = new Set(selectedIds.value)
}

const clearSelection = () => {
  selectedIds.value = new Set()
}

// Computed
const paginatedVouchers = computed(() => vouchers.value)

const selectedPackage = computed(() => getPackageById(formData.value.package_id))
const totalValue = computed(() => calculateTotalValue(selectedPackage.value, formData.value.quantity))
const minDate = computed(() => new Date().toISOString().split('T')[0])

// Calculate expiry date from package validity string (e.g., "30 days", "1 hour", "7 days", "1 month")
const calculateExpiryFromPackage = (pkg) => {
  if (!pkg) return ''
  const validity = (pkg.validity || pkg.duration || '').trim()
  if (!validity) return ''

  const match = validity.match(/^\s*(\d+)\s*(minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years)\s*$/i)
  if (!match) return ''

  const value = parseInt(match[1], 10)
  const unit = match[2].toLowerCase()
  if (value <= 0) return ''

  const date = new Date()
  switch (unit) {
    case 'minute':
    case 'minutes':
      date.setMinutes(date.getMinutes() + value)
      break
    case 'hour':
    case 'hours':
      date.setHours(date.getHours() + value)
      break
    case 'day':
    case 'days':
      date.setDate(date.getDate() + value)
      break
    case 'week':
    case 'weeks':
      date.setDate(date.getDate() + value * 7)
      break
    case 'month':
    case 'months':
      date.setMonth(date.getMonth() + value)
      break
    case 'year':
    case 'years':
      date.setFullYear(date.getFullYear() + value)
      break
    default:
      return ''
  }

  return date.toISOString().split('T')[0]
}

// Form
const formData = ref({
  package_id: '',
  quantity: 10,
  prefix: '',
  expires_at: '',
  notes: ''
})

// Watch package selection to auto-calculate expiry date
watch(() => formData.value.package_id, (newPackageId) => {
  const pkg = getPackageById(newPackageId)
  if (pkg) {
    formData.value.expires_at = calculateExpiryFromPackage(pkg)
  } else {
    formData.value.expires_at = ''
  }
})

// Reset page and fetch on search change
watch(searchQuery, () => {
  currentPage.value = 1
  setFilters({ search: searchQuery.value })
  fetchVouchers({ page: 1, per_page: itemsPerPage.value })
})

// Watch filter changes to trigger API call
watch([filterStatus, filterPackage], () => {
  currentPage.value = 1
  setFilters({ status: filterStatus.value, package_id: filterPackage.value })
  fetchVouchers({ page: 1, per_page: itemsPerPage.value })
})

// Actions
const clearFilters = () => {
  filterStatus.value = ''
  filterPackage.value = ''
  searchQuery.value = ''
  showArchived.value = false
  currentPage.value = 1
  clearSelection()
  setFilters({ search: '', status: '', package_id: '', include_archived: false })
  fetchVouchers({ page: 1, per_page: itemsPerPage.value })
}

const handleFilterChange = () => {
  // Handled by watcher
}

const handleShowArchivedChange = () => {
  currentPage.value = 1
  clearSelection()
  setFilters({ include_archived: showArchived.value })
  fetchVouchers({ page: 1, per_page: itemsPerPage.value })
}

const openCreateOverlay = () => {
  formData.value = { package_id: '', quantity: 10, prefix: '', expires_at: '', notes: '' }
  showCreateOverlay.value = true
}

const closeCreateOverlay = () => {
  showCreateOverlay.value = false
}

const openDetailOverlay = async (voucher) => {
  showDetailOverlay.value = true
  selectedVoucher.value = voucher

  try {
    const details = await fetchVoucherDetails(voucher.id)
    if (details?.id === voucher.id) {
      selectedVoucher.value = details
    }
  } catch (err) {
    console.error('Failed to load voucher details:', err)
  }
}

const closeDetailOverlay = () => {
  showDetailOverlay.value = false
  selectedVoucher.value = null
}

const handleGenerate = async () => {
  const success = await generateVouchers(formData.value)
  if (success) {
    closeCreateOverlay()
    currentPage.value = 1
    await fetchVouchers({ page: 1, per_page: itemsPerPage.value })
  }
}

const copiedId = ref(null)

const copyToClipboard = async (text, id) => {
  try {
    await navigator.clipboard.writeText(text)
    copiedId.value = id
    setTimeout(() => { copiedId.value = null }, 2000)
  } catch (err) {
    console.error('Failed to copy:', err)
  }
}

const isVoucherFree = (v) => v.status === 'unused' && !v.used_by
const isVoucherArchivable = (v) => !v.archived_at && v.status !== 'used' && !v.used_by

const handlePageChange = async (page) => {
  currentPage.value = page
  clearSelection()
  await fetchVouchers({ page, per_page: itemsPerPage.value })
}

const handleRevoke = async (voucher) => {
  if (!isVoucherFree(voucher)) {
    showError('This voucher is associated with a user and cannot be revoked')
    return
  }
  const confirmed = await confirmStore.open({
    title: 'Revoke Voucher',
    message: `Are you sure you want to revoke voucher ${voucher.code}? This action cannot be undone.`,
    confirmText: 'Revoke',
    cancelText: 'Cancel',
    variant: 'danger'
  })

  if (!confirmed) return

  const success = await revokeVoucher(voucher)
  if (success) {
    closeDetailOverlay()
  }
}

const handleArchive = async (voucher) => {
  if (!isVoucherArchivable(voucher)) {
    showError('This voucher cannot be archived')
    return
  }
  const confirmed = await confirmStore.open({
    title: 'Archive Voucher',
    message: `Archive voucher ${voucher.code}? It will be hidden from the main list.`,
    confirmText: 'Archive',
    cancelText: 'Cancel',
    variant: 'warning'
  })

  if (!confirmed) return

  const success = await archiveVoucher(voucher)
  if (success) {
    closeDetailOverlay()
    clearSelection()
    await fetchVouchers({ page: currentPage.value, per_page: itemsPerPage.value })
  }
}

const handleRestore = async (voucher) => {
  const confirmed = await confirmStore.open({
    title: 'Restore Voucher',
    message: `Restore voucher ${voucher.code} to the main list?`,
    confirmText: 'Restore',
    cancelText: 'Cancel',
    variant: 'default'
  })

  if (!confirmed) return

  const success = await restoreVoucher(voucher)
  if (success) {
    closeDetailOverlay()
    await fetchVouchers({ page: currentPage.value, per_page: itemsPerPage.value })
  }
}

const handleBulkArchive = async () => {
  const ids = Array.from(selectedIds.value)
  if (ids.length === 0) {
    showError('Please select vouchers to archive')
    return
  }
  const confirmed = await confirmStore.open({
    title: 'Archive Selected Vouchers',
    message: `Archive ${ids.length} selected voucher(s)? They will be hidden from the main list.`,
    confirmText: 'Archive',
    cancelText: 'Cancel',
    variant: 'warning'
  })

  if (!confirmed) return

  const success = await bulkArchiveVouchers(ids)
  if (success) {
    clearSelection()
    await fetchVouchers({ page: currentPage.value, per_page: itemsPerPage.value })
  }
}

const handleExportSelected = async () => {
  const ids = Array.from(selectedIds.value)
  if (ids.length === 0) {
    await exportVouchers()
  } else {
    await exportVouchers(ids)
  }
}

const getVoucherActions = (voucher) => {
  const actions = [
    { label: 'Copy', onClick: () => copyToClipboard(voucher.code, voucher.id), class: 'text-cyan-700 bg-cyan-50 hover:bg-cyan-100' },
    { label: 'View', onClick: () => openDetailOverlay(voucher), class: 'text-slate-700 bg-slate-50 hover:bg-slate-100' }
  ]
  if (isVoucherArchivable(voucher)) {
    actions.push({ label: 'Archive', onClick: () => handleArchive(voucher), class: 'text-amber-600 bg-amber-50 hover:bg-amber-100' })
  }
  if (isVoucherFree(voucher)) {
    actions.push({ label: 'Revoke', onClick: () => handleRevoke(voucher), class: 'text-red-600 bg-red-50 hover:bg-red-100' })
  }
  if (voucher.archived_at) {
    actions.push({ label: 'Restore', onClick: () => handleRestore(voucher), class: 'text-emerald-600 bg-emerald-50 hover:bg-emerald-100' })
  }
  return actions
}

// Lifecycle
onMounted(() => {
  void fetchVouchers().catch(() => {})
  setupWebSocketListeners()

  requestAnimationFrame(() => {
    void fetchPackages().catch(() => {})
    void fetchStats().catch(() => {})
  })
})

onUnmounted(() => {
  cleanupWebSocketListeners()
})
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
