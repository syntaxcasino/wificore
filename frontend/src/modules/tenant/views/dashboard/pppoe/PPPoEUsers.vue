<template>
  <DataViewContainer
    title="PPPoE Users"
    subtitle="Manage PPPoE customer accounts"
    color-theme="purple"
    v-model:search-model="searchQuery"
    search-placeholder="Search users, usernames..."
    :stats="[
      { color: 'bg-emerald-500', value: activeUsers.length, tooltip: 'Active' },
      { color: 'bg-amber-500', value: inactiveUsers.length, tooltip: 'Inactive' },
      { color: 'bg-rose-500', value: blockedUsers.length, tooltip: 'Blocked' },
      { color: 'bg-slate-400', value: expiredUsers.length, tooltip: 'Expired' },
    ]"
    :total="users.length"
    :loading="loading"
    add-button-text="Add User"
    @refresh="fetchUsers"
    @add="openAddUser"
    @searchClear="searchQuery = ''"
  >
    <template #icon>
      <Cable class="h-5 w-5 md:h-6 md:w-6 text-white" />
    </template>

    <template #filters>
      <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
        <option value="">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
        <option value="blocked">Blocked</option>
        <option value="expired">Expired</option>
      </BaseSelect>
      <BaseSelect v-model="filters.package_id" placeholder="All Packages" class="w-44">
        <option value="">All Packages</option>
        <option v-for="pkg in pppoePackages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
      </BaseSelect>
    </template>

    <!-- Error -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-10 text-rose-500">
      <AlertCircle class="w-10 h-10 opacity-60" />
      <p class="text-sm text-center">{{ error }}</p>
      <button @click="fetchUsers" class="text-sm text-purple-600 hover:underline font-medium">Retry</button>
    </div>

    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Users table -->
    <div v-else-if="filteredData.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
      <!-- Mobile cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0 px-1">
        <div
          v-for="user in paginatedData"
          :key="user.id"
          class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm hover:shadow-md hover:border-purple-300 transition-all"
        >
          <div class="flex items-center gap-3" @click="openUserPanel(user)">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center text-white text-sm font-bold flex-shrink-0 shadow">
              {{ getUserInitials(user) }}
            </div>
            <div class="flex-1 min-w-0">
              <div class="text-sm font-semibold text-slate-800 truncate">{{ user.name || user.username }}</div>
              <div v-if="user.name && user.name !== user.username" class="text-xs text-slate-500 font-mono truncate">{{ user.username }}</div>
            </div>
            <EntityStatusBadge :status="user.status || 'inactive'" size="sm" />
          </div>
          <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-500">
            <div><span class="text-slate-400">Package:</span> {{ user.package?.name || 'N/A' }}</div>
            <div><span class="text-slate-400">Router:</span> {{ user.router?.name || 'N/A' }}</div>
            <div><span class="text-slate-400">Expires:</span> {{ formatDateShort(user.expires_at) }}</div>
            <div><span class="text-slate-400">Balance:</span> KES {{ user.amount_due ?? 0 }}</div>
          </div>
          <!-- Mobile action buttons -->
          <div class="mt-3 pt-3 border-t border-slate-100 flex items-center gap-2" @click.stop>
            <button @click="openUserPanel(user)"
              class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-purple-700 bg-purple-50 border border-purple-200 rounded-lg hover:bg-purple-100 transition-colors">
              <Eye class="w-3.5 h-3.5" /> View
            </button>
            <button @click="handleToggleStatus(user)"
              class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors"
              :class="user.status === 'blocked'
                ? 'text-emerald-700 bg-emerald-50 border-emerald-200 hover:bg-emerald-100'
                : 'text-amber-700 bg-amber-50 border-amber-200 hover:bg-amber-100'">
              <ShieldOff v-if="user.status !== 'blocked'" class="w-3.5 h-3.5" />
              <ShieldCheck v-else class="w-3.5 h-3.5" />
              {{ user.status === 'blocked' ? 'Unblock' : 'Block' }}
            </button>
            <button @click="toggleMenu(user.id, $event)"
              data-menu-button
              class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition-colors">
              <MoreVertical class="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      <!-- Desktop table -->
      <div class="hidden md:flex bg-white border border-slate-200 rounded-lg flex-col min-h-0 flex-1 shadow-sm overflow-hidden">
        <div class="bg-slate-50 border-b border-slate-200 flex-shrink-0">
          <table class="w-full table-fixed">
            <thead>
              <tr>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[26%]">User</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[16%]">Package</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[14%]">Router</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[11%]">Status</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[15%]">Expires</th>
                <th class="px-5 py-3 text-right text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[18%]">Actions</th>
              </tr>
            </thead>
          </table>
        </div>
        <div class="overflow-y-auto flex-1 min-h-0">
          <table class="w-full table-fixed">
            <tbody class="divide-y divide-slate-100">
              <tr
                v-for="user in paginatedData"
                :key="user.id"
                class="hover:bg-purple-50/50 transition-colors cursor-pointer group"
                @click="openUserPanel(user)"
              >
                <td class="px-5 py-3.5 w-[26%]">
                  <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center text-white text-xs font-bold flex-shrink-0 shadow-sm">
                      {{ getUserInitials(user) }}
                    </div>
                    <div class="min-w-0">
                      <div class="text-sm font-semibold text-slate-800 truncate">{{ user.name || user.username }}</div>
                      <div v-if="user.name && user.name !== user.username" class="text-xs text-slate-400 font-mono truncate">{{ user.username }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-5 py-3.5 w-[16%]">
                  <div class="text-sm text-slate-700 truncate">{{ user.package?.name || '—' }}</div>
                  <div class="text-xs text-slate-400">{{ formatPackageSpeed(user.package) }}</div>
                </td>
                <td class="px-5 py-3.5 w-[14%] text-sm text-slate-600 truncate">{{ user.router?.name || '—' }}</td>
                <td class="px-5 py-3.5 w-[11%]">
                  <EntityStatusBadge :status="user.status || 'inactive'" size="sm" />
                </td>
                <td class="px-5 py-3.5 w-[15%]">
                  <div class="text-sm text-slate-600">{{ formatDateShort(user.expires_at) }}</div>
                  <div v-if="user.days_to_expiry !== null && user.days_to_expiry !== undefined" class="text-xs mt-0.5" :class="getDaysClass(user.days_to_expiry)">
                    {{ formatDaysToExpiry(user.days_to_expiry) }}
                  </div>
                </td>
                <td class="px-5 py-3.5 w-[18%] text-right" @click.stop>
                  <div class="flex items-center justify-end gap-1.5">
                    <button
                      @click="openUserPanel(user)"
                      class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-purple-700 bg-purple-50 hover:bg-purple-100 rounded-lg transition-colors"
                    >
                      <Eye class="w-3.5 h-3.5" /> View
                    </button>
                    <button
                      @click="handleToggleStatus(user)"
                      class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold rounded-lg border transition-colors"
                      :class="user.status === 'blocked'
                        ? 'text-emerald-700 bg-emerald-50 border-emerald-200 hover:bg-emerald-100'
                        : 'text-amber-700 bg-amber-50 border-amber-200 hover:bg-amber-100'"
                      :title="user.status === 'blocked' ? 'Unblock' : 'Block'"
                    >
                      <ShieldOff v-if="user.status !== 'blocked'" class="w-3.5 h-3.5" />
                      <ShieldCheck v-else class="w-3.5 h-3.5" />
                      {{ user.status === 'blocked' ? 'Unblock' : 'Block' }}
                    </button>
                    <button
                      data-menu-button
                      @click="toggleMenu(user.id, $event)"
                      class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
                      title="More actions"
                    >
                      <MoreVertical class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredData.length" item-name="users" class="mt-auto" />
    </div>

    <!-- Empty -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Matches Found' : 'No PPPoE Users Found'"
      :description="searchQuery ? 'No users match your search.' : 'Create your first PPPoE user account.'"
      icon="users"
      color-theme="purple"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      clear-text="Clear Search"
      add-text="Add User"
      @clear="searchQuery = ''"
      @add="openAddUser"
    />
  </DataViewContainer>

  <!-- ═══ THREE-DOT DROPDOWN MENU (portal) ═══ -->
  <Teleport to="body">
    <div
      v-if="activeMenu !== null"
      data-dropdown-menu
      :style="menuPosition"
      class="fixed w-52 bg-white rounded-xl shadow-2xl border border-slate-200 py-1.5 z-[9999] overflow-hidden"
    >
      <button @click="openUserPanel(users.find(u => u.id === activeMenu)); closeMenu()"
        class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-purple-50 hover:text-purple-700 transition-colors gap-3">
        <Eye class="w-4 h-4 flex-shrink-0" /> View Details
      </button>
      <button @click="openEditFromMenu(users.find(u => u.id === activeMenu))"
        class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-purple-50 hover:text-purple-700 transition-colors gap-3">
        <Pencil class="w-4 h-4 flex-shrink-0" /> Edit
      </button>
      <div class="border-t border-slate-100 my-1"></div>
      <button v-if="users.find(u => u.id === activeMenu)?.status === 'blocked'"
        @click="handleToggleStatus(users.find(u => u.id === activeMenu)); closeMenu()"
        class="flex items-center w-full px-4 py-2.5 text-sm text-emerald-700 hover:bg-emerald-50 transition-colors gap-3">
        <ShieldCheck class="w-4 h-4 flex-shrink-0" /> Unblock
      </button>
      <button v-else
        @click="handleToggleStatus(users.find(u => u.id === activeMenu)); closeMenu()"
        class="flex items-center w-full px-4 py-2.5 text-sm text-amber-700 hover:bg-amber-50 transition-colors gap-3">
        <ShieldOff class="w-4 h-4 flex-shrink-0" /> Block
      </button>
      <div class="border-t border-slate-100 my-1"></div>
      <button @click="handleResetPasswordForMenu(users.find(u => u.id === activeMenu))"
        class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-purple-50 hover:text-purple-700 transition-colors gap-3">
        <RotateCcw class="w-4 h-4 flex-shrink-0" /> Reset Password
      </button>
      <button @click="handleResetPortalPasswordForMenu(users.find(u => u.id === activeMenu))"
        class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-purple-50 hover:text-purple-700 transition-colors gap-3">
        <RotateCcw class="w-4 h-4 flex-shrink-0" /> Reset Portal Password
      </button>
    </div>
  </Teleport>

  <!-- ═══════════════════════════════════════════════════════════════════════
       USER DETAIL PANEL — full-featured tabbed slide-out
  ═══════════════════════════════════════════════════════════════════════ -->
  <SlideOverlay
    v-model="showUserPanel"
    :title="panelUser ? (panelUser.name || panelUser.username) : 'PPPoE User'"
    :subtitle="panelUser?.name && panelUser.name !== panelUser.username ? panelUser.username : ''"
    gradient
    width="70%"
    no-padding
    @close="showUserPanel = false"
  >
    <div v-if="panelUser" class="flex flex-col h-full">

      <!-- Panel header strip -->
      <div class="flex-shrink-0 bg-gradient-to-r from-purple-700 to-indigo-700 px-6 py-4">
        <div class="flex items-center gap-4">
          <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center text-white text-xl font-bold shadow-lg flex-shrink-0">
            {{ getUserInitials(panelUser) }}
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-lg font-bold text-white truncate">{{ panelUser.name || panelUser.username }}</div>
            <div class="text-sm text-purple-200 font-mono mt-0.5">{{ panelUser.username }}</div>
            <div class="flex items-center gap-2 mt-1.5">
              <EntityStatusBadge :status="panelUser.status || 'inactive'" size="sm" />
              <span v-if="panelUser.package?.name" class="text-xs text-purple-200 bg-white/10 px-2 py-0.5 rounded-full">{{ panelUser.package.name }}</span>
              <span v-if="panelUser.router?.name" class="text-xs text-purple-200 bg-white/10 px-2 py-0.5 rounded-full">{{ panelUser.router.name }}</span>
            </div>
          </div>
          <!-- Quick stats -->
          <div class="hidden md:flex items-center gap-4 flex-shrink-0">
            <div class="text-center">
              <div class="text-lg font-bold text-white">{{ panelUser.days_to_expiry != null ? Math.round(panelUser.days_to_expiry) : '—' }}</div>
              <div class="text-[10px] text-purple-300 uppercase tracking-wide">Days Left</div>
            </div>
            <div class="text-center">
              <div class="text-lg font-bold text-white">KES {{ panelUser.amount_due ?? 0 }}</div>
              <div class="text-[10px] text-purple-300 uppercase tracking-wide">Balance Due</div>
            </div>
          </div>
        </div>

        <!-- Tabs -->
        <div class="flex gap-1 mt-4 bg-white/10 rounded-xl p-1 overflow-x-auto scrollbar-hide">
          <button
            v-for="tab in panelTabs"
            :key="tab.id"
            @click="activeTab = tab.id"
            class="flex-shrink-0 flex items-center justify-center gap-1.5 py-1.5 px-2 text-xs font-semibold rounded-lg transition-all relative whitespace-nowrap"
            :class="activeTab === tab.id ? 'bg-white text-purple-700 shadow-sm' : 'text-purple-200 hover:text-white hover:bg-white/10'"
          >
            <component :is="tab.icon" class="w-3.5 h-3.5" />
            {{ tab.label }}
            <!-- Live dot on Current Session tab -->
            <span v-if="tab.id === 'session' && !sessionLoading && currentSession"
              class="absolute top-1 right-1 w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
            <span v-else-if="tab.id === 'session' && !sessionLoading && !currentSession"
              class="absolute top-1 right-1 w-1.5 h-1.5 rounded-full bg-slate-400/60"></span>
          </button>
        </div>
      </div>

      <!-- Tab content -->
      <div class="flex-1 overflow-y-auto min-h-0 bg-slate-50" ref="panelScrollRef">

        <!-- ── TAB: User Details ── -->
        <div v-if="activeTab === 'details'" class="p-6 space-y-5">

          <!-- Account info -->
          <section>
            <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
              <UserCircle2 class="w-3.5 h-3.5" /> Account Information
            </h3>
            <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
              <div class="bg-gradient-to-r from-purple-50 to-indigo-50 px-4 py-3 border-b border-slate-100">
                <div class="text-xs text-slate-500">Account Number</div>
                <div class="text-base font-mono font-bold text-slate-800 mt-0.5">{{ panelUser.account_number || 'N/A' }}</div>
              </div>
              <div class="p-4 grid grid-cols-2 gap-4">
                <InfoCell label="PPPoE Username" :value="panelUser.username" mono />
                <InfoCell label="Full Name" :value="panelUser.name || '—'" />
                <InfoCell label="Phone" :value="panelUser.phone || '—'" />
                <InfoCell label="Email" :value="panelUser.email || '—'" />
                <InfoCell label="Status">
                  <EntityStatusBadge :status="panelUser.status || 'inactive'" size="sm" />
                </InfoCell>
                <InfoCell label="Created" :value="formatDate(panelUser.created_at)" />
              </div>
            </div>
          </section>

          <!-- Credentials -->
          <section>
            <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
              <KeyRound class="w-3.5 h-3.5" /> Credentials
            </h3>
            <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm space-y-3">
              <div>
                <div class="text-xs text-slate-500 mb-1.5">PPPoE Password</div>
                <div class="flex items-center gap-2">
                  <div class="flex-1 font-mono text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-800">
                    {{ showPasswordValue ? userPassword : '••••••••••••' }}
                  </div>
                  <button v-if="!showPasswordValue" @click="handleViewPassword" :disabled="loadingPassword"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50 transition-colors">
                    <Eye class="w-3.5 h-3.5" />{{ loadingPassword ? '...' : 'View' }}
                  </button>
                  <button v-else @click="hidePassword" class="p-2 text-slate-400 hover:text-slate-700 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">
                    <EyeOff class="w-3.5 h-3.5" />
                  </button>
                  <button @click="handleResetPassword"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-amber-700 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition-colors">
                    <RotateCcw class="w-3.5 h-3.5" /> Reset
                  </button>
                </div>
              </div>

              <div>
                <div class="text-xs text-slate-500 mb-1.5">Portal Password</div>
                <div class="flex items-center gap-2">
                  <div class="flex-1 font-mono text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-800">
                    {{ showPortalPasswordValue ? userPortalPassword : '••••••••••••' }}
                  </div>
                  <button v-if="!showPortalPasswordValue" @click="handleViewPortalPassword" :disabled="loadingPortalPassword"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50 transition-colors">
                    <Eye class="w-3.5 h-3.5" />{{ loadingPortalPassword ? '...' : 'View' }}
                  </button>
                  <button v-else @click="hidePortalPassword" class="p-2 text-slate-400 hover:text-slate-700 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">
                    <EyeOff class="w-3.5 h-3.5" />
                  </button>
                  <button @click="handleResetPortalPassword"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-amber-700 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition-colors">
                    <RotateCcw class="w-3.5 h-3.5" /> Reset
                  </button>
                </div>
                <div class="mt-1.5 text-[10px] text-slate-400">Used for customer self-service portal login</div>
                <div class="mt-2">
                  <div class="text-[10px] text-slate-400 mb-1">Client Service Provisioning URL</div>
                  <div class="flex items-center gap-2">
                    <a
                      :href="portalProvisioningUrl"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="flex-1 font-mono text-[11px] text-indigo-700 bg-indigo-50 border border-indigo-100 rounded-lg px-3 py-2 break-all hover:bg-indigo-100 transition-colors"
                    >
                      {{ portalProvisioningUrl }}
                    </a>
                    <button
                      @click="copyPortalProvisioningUrl"
                      class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors"
                    >
                      <Copy class="w-3.5 h-3.5" /> Copy
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <!-- Subscription & billing -->
          <section>
            <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
              <CreditCard class="w-3.5 h-3.5" /> Subscription & Billing
            </h3>
            <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm grid grid-cols-2 gap-4">
              <InfoCell label="Package" :value="panelUser.package?.name || '—'" />
              <InfoCell label="Speed" :value="formatPackageSpeed(panelUser.package)" />
              <InfoCell label="Router" :value="panelUser.router?.name || '—'" />
              <InfoCell label="Simultaneous" :value="String(panelUser.simultaneous_use ?? 1)" />
              <InfoCell label="Rate Limit" :value="panelUser.rate_limit || '—'" mono />
              <InfoCell label="Expires">
                <div :class="['text-sm font-semibold', getDaysClass(panelUser.days_to_expiry)]">
                  {{ formatDateShort(panelUser.expires_at) }}
                </div>
              </InfoCell>
              <InfoCell label="Days Left">
                <div :class="['text-sm font-semibold', getDaysClass(panelUser.days_to_expiry)]">
                  {{ formatDaysToExpiry(panelUser.days_to_expiry) }}
                </div>
              </InfoCell>
              <InfoCell label="Payment Status">
                <EntityStatusBadge :status="panelUser.payment_status || 'unpaid'" size="sm" />
              </InfoCell>
              <InfoCell label="Amount Due" :value="`KES ${panelUser.amount_due ?? 0}`" />
              <InfoCell label="Last Payment" :value="formatDate(panelUser.last_payment_date) || 'Never'" />
              <InfoCell label="Next Due" :value="formatDate(panelUser.next_payment_due) || '—'" />
            </div>
          </section>

          <!-- Edit form (inline, toggleable) -->
          <section>
            <div class="flex items-center justify-between mb-3">
              <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                <Pencil class="w-3.5 h-3.5" /> Edit Account
              </h3>
            </div>
            <Transition name="slide-down">
              <div v-if="editExpanded" class="bg-white rounded-xl border border-purple-200 p-4 shadow-sm space-y-4">
                <BaseAlert v-if="editFormError" variant="danger" :title="editFormError" dismissible />
                <div class="font-mono text-sm text-slate-500 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                  <span class="text-xs text-slate-400 block mb-0.5">Username (read-only)</span>
                  {{ panelUser.username }}
                </div>
                <div class="grid grid-cols-2 gap-4">
                  <BaseSelect v-model="editForm.router_id" label="Router" :error="editFieldErrors.router_id" required>
                    <option v-for="r in routers" :key="r.id" :value="r.id">{{ r.name }}</option>
                  </BaseSelect>
                  <BaseSelect v-model="editForm.package_id" label="Package" :error="editFieldErrors.package_id" required>
                    <option v-for="pkg in pppoePackages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
                  </BaseSelect>
                  <BaseInput v-model="editForm.simultaneous_use" type="number" label="Simultaneous Sessions" :error="editFieldErrors.simultaneous_use" required />
                  <BaseSelect v-model="editForm.status" label="Status" :error="editFieldErrors.status" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="blocked">Blocked</option>
                    <option value="expired">Expired</option>
                  </BaseSelect>
                </div>
                <div class="flex justify-end gap-2 pt-1">
                  <button @click="editExpanded = false" class="px-4 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">Cancel</button>
                  <button @click="handleUpdateUser" :disabled="editSubmitting"
                    class="px-5 py-2 text-sm font-semibold text-white bg-gradient-to-r from-purple-600 to-indigo-600 rounded-lg hover:from-purple-700 hover:to-indigo-700 disabled:opacity-50 transition-all shadow-md">
                    {{ editSubmitting ? 'Saving…' : 'Save Changes' }}
                  </button>
                </div>
              </div>
            </Transition>
          </section>
        </div>

        <!-- ── TAB: Current Session ── -->
        <div v-else-if="activeTab === 'session'" class="p-6">
          <div v-if="sessionLoading" class="flex items-center justify-center py-16">
            <div class="w-7 h-7 border-2 border-purple-500 border-t-transparent rounded-full animate-spin"></div>
          </div>
          <div v-else-if="currentSession" class="space-y-5">
            <!-- Stale accounting warning -->
            <div v-if="currentSession.acct_staleness_seconds > 600"
              class="flex items-center gap-2 px-3 py-2 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-700 font-medium">
              <span class="w-2 h-2 rounded-full bg-amber-500 flex-shrink-0"></span>
              Accounting stale — last update {{ Math.floor(currentSession.acct_staleness_seconds / 60) }}m ago
            </div>
            <!-- Live banner -->
            <div class="bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl p-4 text-white shadow-md">
              <div class="flex items-center gap-2 mb-3">
                <span class="w-2 h-2 bg-white rounded-full animate-pulse"></span>
                <span class="text-sm font-bold">Live Session</span>
              </div>
              <div class="grid grid-cols-3 gap-4">
                <div>
                  <div class="text-[10px] text-emerald-200 uppercase tracking-wide">Online Since</div>
                  <div class="text-sm font-bold mt-0.5">{{ formatDate(currentSession.connected_at || currentSession.acctstarttime || currentSession.start_time) }}</div>
                </div>
                <div>
                  <div class="text-[10px] text-emerald-200 uppercase tracking-wide">Session Time</div>
                  <div class="text-sm font-bold mt-0.5">{{ formatDuration(currentSession.uptime || currentSession.duration || currentSession.acctsessiontime) }}</div>
                </div>
                <div>
                  <div class="text-[10px] text-emerald-200 uppercase tracking-wide">IP Address</div>
                  <div class="text-sm font-mono font-bold mt-0.5">{{ currentSession.ip_address || currentSession.framed_ip || currentSession.framed_ip_address || currentSession.framedipaddress || '—' }}</div>
                </div>
              </div>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
              <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-100">
                  <tr class="grid grid-cols-2">
                    <td class="flex items-center justify-between px-4 py-2.5 border-r border-slate-100">
                      <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">↓ Download</span>
                      <span class="font-semibold text-emerald-700">{{ formatBytes(currentSession.download_rate || currentSession.download_speed || 0) }}/s</span>
                    </td>
                    <td class="flex items-center justify-between px-4 py-2.5">
                      <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">↑ Upload</span>
                      <span class="font-semibold text-blue-700">{{ formatBytes(currentSession.upload_rate || currentSession.upload_speed || 0) }}/s</span>
                    </td>
                  </tr>
                  <tr class="grid grid-cols-2">
                    <td class="flex items-center justify-between px-4 py-2.5 border-r border-slate-100">
                      <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">Total Downloaded</span>
                      <span class="text-slate-700">{{ formatBytes(currentSession.output_octets || currentSession.acctoutputoctets || currentSession.bytes_in || 0) }}</span>
                    </td>
                    <td class="flex items-center justify-between px-4 py-2.5">
                      <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">Total Uploaded</span>
                      <span class="text-slate-700">{{ formatBytes(currentSession.input_octets || currentSession.acctinputoctets || currentSession.bytes_out || 0) }}</span>
                    </td>
                  </tr>
                  <tr class="grid grid-cols-2">
                    <td class="flex items-center justify-between px-4 py-2.5 border-r border-slate-100">
                      <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">NAS IP</span>
                      <span class="font-mono text-slate-700">{{ currentSession.nas_ip_address || currentSession.nasipaddress || currentSession.nas_ip || '—' }}</span>
                    </td>
                    <td class="flex items-center justify-between px-4 py-2.5">
                      <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">MAC Address</span>
                      <span class="font-mono text-slate-700 text-xs">{{ currentSession.calling_station_id || currentSession.callingstationid || currentSession.mac_address || '—' }}</span>
                    </td>
                  </tr>
                  <tr class="grid grid-cols-2">
                    <td class="flex items-center justify-between px-4 py-2.5 border-r border-slate-100">
                      <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">Router</span>
                      <span class="text-slate-700">{{ currentSession.router_name || '—' }}</span>
                    </td>
                    <td class="flex items-center justify-between px-4 py-2.5">
                      <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">Port</span>
                      <span class="text-slate-700">{{ currentSession.nas_port || currentSession.nasport || '—' }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <!-- ── Traffic Chart ── -->
            <div class="space-y-3">
              <div class="flex items-center justify-between">
                <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                  <TrendingUp class="w-3.5 h-3.5" /> Traffic History
                </h3>
                <div class="flex items-center gap-2">
                  <select v-model="chartRange" @change="fetchUserChart" class="text-xs border border-slate-300 rounded-lg px-2 py-1.5 bg-white text-slate-700 focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                    <option value="15m">Last 15 min</option>
                    <option value="30m">Last 30 min</option>
                    <option value="1h">Last 1 hour</option>
                    <option value="6h">Last 6 hours</option>
                    <option value="24h">Last 24 hours</option>
                    <option value="7d">Last 7 days</option>
                  </select>
                  <select v-model="chartType" class="text-xs border border-slate-300 rounded-lg px-2 py-1.5 bg-white text-slate-700 focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                    <option value="line">Line</option>
                    <option value="area">Area</option>
                  </select>
                  <button @click="fetchUserChart" class="p-1.5 text-slate-400 hover:text-purple-600 border border-slate-200 rounded-lg hover:border-purple-300 transition-colors">
                    <RotateCcw class="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>

              <div v-if="trafficLoading" class="flex items-center justify-center py-10">
                <div class="w-6 h-6 border-2 border-purple-500 border-t-transparent rounded-full animate-spin"></div>
              </div>

              <div v-else-if="chartHasData" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-4">
                  <div class="flex" style="height:200px">
                    <div class="relative w-16 mr-2 border-r border-slate-100 flex-shrink-0">
                      <div v-for="tick in chartYTicks" :key="tick.label"
                        class="absolute right-2 text-[10px] text-slate-500 font-medium"
                        :style="{ bottom: tick.percent + '%', transform: 'translateY(50%)' }">
                        {{ tick.label }}
                      </div>
                    </div>
                    <div class="relative flex-1 flex flex-col min-w-0">
                      <div class="relative flex-1 overflow-hidden cursor-crosshair"
                        @mousemove="onChartHover" @mouseleave="onChartLeave"
                        ref="chartContainer">
                        <div v-for="tick in chartYTicks" :key="'g'+tick.label"
                          class="absolute w-full border-t border-slate-100"
                          :style="{ bottom: tick.percent + '%' }" />
                        <svg class="absolute inset-0 w-full h-full" viewBox="0 0 1000 200" preserveAspectRatio="none">
                          <defs>
                            <linearGradient id="pGradDL" x1="0" x2="0" y1="0" y2="1">
                              <stop offset="0%" stop-color="#22c55e" stop-opacity="0.25"/>
                              <stop offset="100%" stop-color="#22c55e" stop-opacity="0"/>
                            </linearGradient>
                            <linearGradient id="pGradUL" x1="0" x2="0" y1="0" y2="1">
                              <stop offset="0%" stop-color="#a855f7" stop-opacity="0.25"/>
                              <stop offset="100%" stop-color="#a855f7" stop-opacity="0"/>
                            </linearGradient>
                          </defs>
                          <template v-if="chartType === 'area'">
                            <path :d="svgDlAreaPath" fill="url(#pGradDL)" stroke="none" />
                            <path :d="svgUlAreaPath" fill="url(#pGradUL)" stroke="none" />
                          </template>
                          <path :d="svgDlPath" fill="none" stroke="#22c55e" stroke-width="2" vector-effect="non-scaling-stroke" />
                          <path :d="svgUlPath" fill="none" stroke="#a855f7" stroke-width="2" vector-effect="non-scaling-stroke" />
                        </svg>
                        <div v-if="chartHoverIdx >= 0"
                          class="absolute top-0 bottom-0 border-l border-slate-400 border-dashed pointer-events-none z-10"
                          :style="{ left: chartHoverX + '%' }">
                          <div class="absolute w-2 h-2 bg-green-500 rounded-full -ml-1 border-2 border-white shadow"
                            :style="{ bottom: (chartHoverData.dl / chartMax * 100) + '%' }"/>
                          <div class="absolute w-2 h-2 bg-purple-500 rounded-full -ml-1 border-2 border-white shadow"
                            :style="{ bottom: (chartHoverData.ul / chartMax * 100) + '%' }"/>
                          <div class="absolute top-0 left-3 bg-white/95 backdrop-blur shadow-xl border border-slate-200 rounded-xl p-2.5 text-xs whitespace-nowrap z-20 pointer-events-none"
                            :class="chartHoverX > 70 ? '-translate-x-full -left-3' : ''">
                            <div class="font-mono text-slate-400 mb-1.5">{{ chartFormatTime(chartHoverData.t) }}</div>
                            <div class="flex items-center gap-2 mb-1">
                              <span class="w-2 h-2 rounded-full bg-green-500 flex-shrink-0"></span>
                              <span class="font-semibold text-slate-700">↓ {{ formatBytes(chartHoverData.dl) }}/s</span>
                            </div>
                            <div class="flex items-center gap-2">
                              <span class="w-2 h-2 rounded-full bg-purple-500 flex-shrink-0"></span>
                              <span class="font-semibold text-slate-700">↑ {{ formatBytes(chartHoverData.ul) }}/s</span>
                            </div>
                          </div>
                        </div>
                      </div>
                      <div class="h-7 relative mt-1 flex-shrink-0 border-t border-slate-200 pt-1">
                        <div v-for="tick in chartXTicks" :key="tick.x"
                          class="absolute text-[10px] text-slate-400 font-medium -translate-x-1/2 whitespace-nowrap"
                          :style="{ left: tick.x + '%' }">{{ tick.label }}</div>
                      </div>
                    </div>
                  </div>
                  <div class="flex items-center justify-between mt-3 pt-3 border-t border-slate-100">
                    <div class="flex items-center gap-5">
                      <div class="flex items-center gap-1.5">
                        <div class="w-3 h-3 rounded-full bg-green-500"></div>
                        <span class="text-xs text-slate-600 font-medium">Download</span>
                      </div>
                      <div class="flex items-center gap-1.5">
                        <div class="w-3 h-3 rounded-full bg-purple-500"></div>
                        <span class="text-xs text-slate-600 font-medium">Upload</span>
                      </div>
                    </div>
                    <div class="flex items-center gap-4 text-xs text-slate-500">
                      <span>Peak ↓ <strong class="text-green-700">{{ formatBytes(chartPeakDl) }}/s</strong></span>
                      <span>Peak ↑ <strong class="text-purple-700">{{ formatBytes(chartPeakUl) }}/s</strong></span>
                    </div>
                  </div>
                </div>
              </div>

              <div v-else class="flex flex-col items-center gap-2 py-8 text-slate-400 bg-white rounded-xl border border-slate-200">
                <TrendingUp class="w-8 h-8 opacity-30" />
                <p class="text-xs font-medium">No traffic data for this session</p>
              </div>
            </div>
          </div>
          <div v-else class="flex flex-col items-center gap-3 py-16 text-slate-400">
            <WifiOff class="w-12 h-12 opacity-30" />
            <p class="text-sm font-medium">No active session</p>
            <p class="text-xs">This user is currently offline</p>
          </div>
        </div>

        <!-- ── TAB: Historical Sessions ── -->
        <div v-else-if="activeTab === 'history'" class="p-6">
          <div v-if="historyLoading" class="flex items-center justify-center py-16">
            <div class="w-7 h-7 border-2 border-purple-500 border-t-transparent rounded-full animate-spin"></div>
          </div>
          <div v-else-if="sessionHistory.length" class="space-y-3">
            <div class="text-xs text-slate-500 font-medium mb-2">{{ sessionHistory.length }} sessions — most recent first</div>
            <div
              v-for="(s, i) in sessionHistory"
              :key="s.radacctid || s.id || i"
              class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm hover:border-purple-200 transition-colors"
            >
              <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-2">
                  <div class="w-7 h-7 rounded-lg bg-slate-100 flex items-center justify-center flex-shrink-0 text-xs font-bold text-slate-500">{{ i + 1 }}</div>
                  <div>
                    <div class="text-sm font-semibold text-slate-800">{{ formatDate(s.disconnected_at || s.acctstoptime) }}</div>
                    <div class="text-xs text-slate-400 mt-0.5">Connected: {{ formatDate(s.connected_at || s.acctstarttime) }}</div>
                  </div>
                </div>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold flex-shrink-0"
                  :class="reasonBadge(s.terminate_cause || s.acctterminatecause)">
                  {{ s.terminate_cause || s.acctterminatecause || 'Unknown' }}
                </span>
              </div>
              <div class="mt-3 grid grid-cols-3 gap-3 text-xs">
                <div class="bg-slate-50 rounded-lg p-2">
                  <div class="text-slate-400 mb-0.5">Duration</div>
                  <div class="font-semibold text-slate-700">{{ formatDuration(s.duration || s.acctsessiontime) }}</div>
                </div>
                <div class="bg-slate-50 rounded-lg p-2">
                  <div class="text-slate-400 mb-0.5">Download</div>
                  <div class="font-semibold text-slate-700">{{ formatBytes(s.output_octets || s.acctoutputoctets || 0) }}</div>
                </div>
                <div class="bg-slate-50 rounded-lg p-2">
                  <div class="text-slate-400 mb-0.5">Upload</div>
                  <div class="font-semibold text-slate-700">{{ formatBytes(s.input_octets || s.acctinputoctets || 0) }}</div>
                </div>
              </div>
              <div class="mt-2 flex items-center gap-4 text-xs text-slate-400">
                <span class="font-mono">{{ s.framed_ip_address || s.framedipaddress || '—' }}</span>
                <span class="font-mono">{{ s.calling_station_id || s.callingstationid || '—' }}</span>
              </div>
            </div>
          </div>
          <div v-else class="flex flex-col items-center gap-3 py-16 text-slate-400">
            <History class="w-12 h-12 opacity-30" />
            <p class="text-sm font-medium">No session history</p>
          </div>
        </div>

        <!-- ── TAB: Historical Payments ── -->
        <div v-else-if="activeTab === 'payments'" class="p-6">
          <div v-if="paymentsLoading" class="flex items-center justify-center py-16">
            <div class="w-7 h-7 border-2 border-purple-500 border-t-transparent rounded-full animate-spin"></div>
          </div>
          <div v-else-if="paymentHistory.length" class="space-y-3">
            <div class="text-xs text-slate-500 font-medium mb-2">{{ paymentHistory.length }} payments — most recent first</div>
            <!-- Totals strip -->
            <div class="grid grid-cols-3 gap-3 mb-4">
              <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3 text-center">
                <div class="text-lg font-bold text-emerald-700">{{ paymentTotals.count }}</div>
                <div class="text-[10px] text-emerald-500 uppercase tracking-wide">Total Payments</div>
              </div>
              <div class="bg-blue-50 border border-blue-100 rounded-xl p-3 text-center">
                <div class="text-lg font-bold text-blue-700">KES {{ paymentTotals.total }}</div>
                <div class="text-[10px] text-blue-500 uppercase tracking-wide">Total Paid</div>
              </div>
              <div class="bg-purple-50 border border-purple-100 rounded-xl p-3 text-center">
                <div class="text-lg font-bold text-purple-700">KES {{ paymentTotals.last }}</div>
                <div class="text-[10px] text-purple-500 uppercase tracking-wide">Last Payment</div>
              </div>
            </div>
            <div
              v-for="(p, i) in paymentHistory"
              :key="p.id || i"
              class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm hover:border-purple-200 transition-colors"
            >
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
                    :class="p.status === 'verified' || p.status === 'completed' ? 'bg-emerald-100' : 'bg-amber-100'">
                    <CheckCircle v-if="p.status === 'verified' || p.status === 'completed'" class="w-4 h-4 text-emerald-600" />
                    <Clock v-else class="w-4 h-4 text-amber-600" />
                  </div>
                  <div>
                    <div class="text-sm font-semibold text-slate-800">KES {{ p.amount ?? '—' }}</div>
                    <div class="text-xs text-slate-400 mt-0.5">{{ formatDate(p.created_at || p.paid_at) }}</div>
                  </div>
                </div>
                <div class="text-right">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold"
                    :class="p.status === 'verified' || p.status === 'completed' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                    {{ p.status || 'pending' }}
                  </span>
                  <div class="text-xs text-slate-400 mt-1">{{ p.payment_method || p.channel || '—' }}</div>
                </div>
              </div>
              <div v-if="p.reference || p.transaction_id || p.mpesa_receipt" class="mt-2 text-xs text-slate-400 font-mono bg-slate-50 px-3 py-1.5 rounded-lg">
                Ref: {{ p.reference || p.transaction_id || p.mpesa_receipt }}
              </div>
            </div>
          </div>
          <div v-else class="flex flex-col items-center gap-3 py-16 text-slate-400">
            <Banknote class="w-12 h-12 opacity-30" />
            <p class="text-sm font-medium">No payment history</p>
          </div>
        </div>


      </div><!-- end tab content -->

      <!-- Panel footer -->
      <div class="flex-shrink-0 px-6 py-3 bg-white border-t border-slate-200 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <button
            @click="handleToggleStatus(panelUser)"
            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border transition-colors"
            :class="panelUser.status === 'blocked'
              ? 'text-emerald-700 bg-emerald-50 border-emerald-200 hover:bg-emerald-100'
              : 'text-amber-700 bg-amber-50 border-amber-200 hover:bg-amber-100'"
          >
            <ShieldOff v-if="panelUser.status !== 'blocked'" class="w-3.5 h-3.5" />
            <ShieldCheck v-else class="w-3.5 h-3.5" />
            {{ panelUser.status === 'blocked' ? 'Unblock' : 'Block' }}
          </button>
          <!-- Disconnect button — only visible on session tab when live session exists -->
          <button v-if="activeTab === 'session' && currentSession"
            @click="handleDisconnect(currentSession)"
            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-rose-600 bg-rose-50 border border-rose-200 hover:bg-rose-100 rounded-lg transition-colors">
            <Unplug class="w-3.5 h-3.5" /> Disconnect Session
          </button>
          <!-- Edit button — only on details tab -->
          <button v-if="activeTab === 'details'"
            @click="toggleEdit"
            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border transition-colors"
            :class="editExpanded
              ? 'text-slate-600 bg-slate-50 border-slate-300 hover:bg-slate-100'
              : 'text-purple-700 bg-purple-50 border-purple-200 hover:bg-purple-100'">
            <Pencil class="w-3.5 h-3.5" />
            {{ editExpanded ? 'Collapse Edit' : 'Edit User' }}
          </button>
        </div>
        <button @click="showUserPanel = false" class="px-4 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
          Close
        </button>
      </div>
    </div>
  </SlideOverlay>

  <!-- ═══ ADD USER OVERLAY ═══ -->
  <SlideOverlay
    v-model="showAddUserOverlay"
    title="Add PPPoE User"
    subtitle="Create a new PPPoE customer account"
    gradient
    width="50%"
    :closeOnBackdrop="false"
    @close="closeAddUser"
  >
    <div class="p-6 space-y-4">
      <BaseAlert v-if="addFormError" variant="danger" :title="addFormError" dismissible />
      <form id="addPppoeUserForm" class="space-y-4" @submit.prevent="handleCreateUser">
        <BaseInput v-model="addForm.username" label="Username *" placeholder="e.g. john.doe" :error="addFieldErrors.username" required autocomplete="off" />
        <BaseSelect v-model="addForm.router_id" label="Router *" placeholder="Select a router" :error="addFieldErrors.router_id" required>
          <option v-for="router in routers" :key="router.id" :value="router.id">{{ router.name }}</option>
        </BaseSelect>
        <BaseSelect v-model="addForm.package_id" label="Package *" placeholder="Select a PPPoE package" :error="addFieldErrors.package_id" required>
          <option v-for="pkg in pppoePackages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
        </BaseSelect>
        <BaseInput v-model="addForm.simultaneous_use" type="number" label="Simultaneous Sessions" :error="addFieldErrors.simultaneous_use" />
      </form>
    </div>
    <template #footer>
      <div class="flex gap-3">
        <button type="button" :disabled="addSubmitting" @click="closeAddUser"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50 transition-colors">Cancel</button>
        <button type="submit" form="addPppoeUserForm" :disabled="addSubmitting"
          class="flex-1 px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 rounded-lg disabled:opacity-50 shadow transition-all">
          {{ addSubmitting ? 'Creating…' : 'Create User' }}
        </button>
      </div>
    </template>
  </SlideOverlay>

  <!-- ═══ PASSWORD CREATED OVERLAY ═══ -->
  <SlideOverlay
    v-model="showPasswordModal"
    title="User Created"
    subtitle="Account credentials generated"
    gradient
    width="45%"
    :closeOnBackdrop="false"
    :closeOnEscape="false"
  >
    <div class="p-6 space-y-5">
      <div class="flex items-center gap-3 p-4 bg-emerald-50 border border-emerald-200 rounded-xl">
        <CheckCircle class="w-8 h-8 text-emerald-600 flex-shrink-0" />
        <div>
          <div class="text-sm font-semibold text-emerald-800">Account Created Successfully</div>
          <div class="text-xs text-emerald-600 mt-0.5 font-mono">{{ createdUser?.username }}</div>
        </div>
      </div>
      <div>
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Generated Password</div>
        <div class="flex items-center gap-2">
          <div class="flex-1 font-mono text-sm bg-white border border-slate-200 rounded-lg px-3 py-2.5 text-slate-800 select-all">{{ generatedPassword }}</div>
          <button @click="copyPassword" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
            <Copy class="w-3.5 h-3.5" /> Copy
          </button>
        </div>
        <p class="text-xs text-amber-600 mt-2 flex items-center gap-1">
          <AlertCircle class="w-3 h-3 flex-shrink-0" /> Shown only once — save it securely.
        </p>
      </div>
    </div>
    <template #footer>
      <button @click="finishCreateUser" class="w-full px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-lg hover:from-emerald-700 hover:to-teal-700 transition-all shadow">
        Done
      </button>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import {
  Cable, Eye, EyeOff, AlertCircle, ShieldOff, ShieldCheck, WifiOff, Unplug,
  UserCircle2, KeyRound, CreditCard, Pencil, History, Banknote, Clock,
  CheckCircle, RotateCcw, Copy, User, Wifi, ReceiptText, TrendingUp, MoreVertical
} from 'lucide-vue-next'
import axios from '@/modules/common/services/api/axios'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import BaseInput from '@/modules/common/components/base/BaseInput.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'
import { usePppoeUsers } from '@/modules/tenant/composables/data/usePppoeUsers'
import { useFilters } from '@/modules/common/composables/utils/useFilters'
import { usePagination } from '@/modules/common/composables/utils/usePagination'
import { usePackages } from '@/modules/tenant/composables/data/usePackages'
import { useRouters } from '@/modules/tenant/composables/data/useRouters'
import { useConfirmStore } from '@/stores/confirm'
import { defineComponent, h } from 'vue'

const confirmStore = useConfirmStore()

// ── Data ───────────────────────────────────────────────────────────────────
const {
  users, loading, error,
  activeUsers, inactiveUsers, blockedUsers, expiredUsers,
  fetchUsers, createUser, updateUser, viewPassword, viewPortalPassword, resetPassword, resetPortalPassword,
  toggleUserStatus, subscribeToWebSocket, unsubscribeFromWebSocket,
} = usePppoeUsers()

const { packages, fetchPackages } = usePackages()
const { routers, fetchRouters } = useRouters()
const pppoePackages = computed(() => (packages.value || []).filter(p => p?.type === 'pppoe'))

const { filters, searchQuery, filteredData, hasActiveFilters } = useFilters(users, { status: '', package_id: '' })
const { currentPage, itemsPerPage, paginatedData, totalPages } = usePagination(filteredData, 10)

watch([searchQuery, itemsPerPage, () => filters.status, () => filters.package_id], () => { currentPage.value = 1 })

// ── Panel state ───────────────────────────────────────────────────────────
const showUserPanel   = ref(false)
const panelUser       = ref(null)
const activeTab       = ref('details')
const editExpanded    = ref(false)
const panelScrollRef  = ref(null)

const panelTabs = [
  { id: 'details',  label: 'User Details',    icon: User },
  { id: 'session',  label: 'Current Session', icon: Wifi },
  { id: 'history',  label: 'Historical Sessions', icon: History },
  { id: 'payments', label: 'Payments',        icon: ReceiptText },
]

// Session data for the panel
const currentSession  = ref(null)
const sessionHistory  = ref([])
const paymentHistory  = ref([])
const sessionLoading  = ref(false)
const historyLoading  = ref(false)
const paymentsLoading = ref(false)
const trafficLoading  = ref(false)

const paymentTotals = computed(() => {
  const list = paymentHistory.value
  return {
    count: list.length,
    total: list.reduce((s, p) => s + Number(p.amount ?? 0), 0),
    last:  list.length ? Number(list[0].amount ?? 0) : 0,
  }
})

const openUserPanel = async (user) => {
  panelUser.value   = user
  showUserPanel.value = true
  activeTab.value   = 'details'
  editExpanded.value = false
  showPasswordValue.value = false
  userPassword.value = ''
  showPortalPasswordValue.value = false
  userPortalPassword.value = ''
  portalLoginPath.value = '/portal/login'
  portalAccountNumber.value = user.account_number || ''
  editForm.router_id = user.router_id || user.router?.id || ''
  editForm.package_id = user.package_id || user.package?.id || ''
  editForm.simultaneous_use = Number(user.simultaneous_use ?? 1)
  editForm.status = user.status || 'active'
  resetEditErrors()

  // load tab data in parallel
  loadCurrentSession(user)
  loadSessionHistory(user)
  loadPaymentHistory(user)
  loadTrafficMetrics(user)
}

watch(showUserPanel, (v) => {
  if (!v) {
    currentSession.value = null
    sessionHistory.value = []
    paymentHistory.value = []
    downloadSeries.value = []
    uploadSeries.value   = []
    editExpanded.value   = false
  }
})

// Refresh live data when switching to session tab
watch(activeTab, (tab) => {
  if (tab === 'session' && panelUser.value && showUserPanel.value) {
    loadCurrentSession(panelUser.value)
    fetchUserChart()
  }
  if (tab === 'history' && panelUser.value && showUserPanel.value) {
    loadSessionHistory(panelUser.value)
  }
  if (tab === 'payments' && panelUser.value && showUserPanel.value) {
    loadPaymentHistory(panelUser.value)
  }
})

// Sync panelUser from WebSocket PppoeUserUpdated events
watch(users, (list) => {
  if (!panelUser.value || !showUserPanel.value) return
  const updated = list.find(u => u.id === panelUser.value.id)
  if (updated) panelUser.value = { ...panelUser.value, ...updated }
}, { deep: true })

const loadCurrentSession = async (user, silent = false) => {
  if (!silent) sessionLoading.value = true
  try {
    const res = await axios.get('pppoe/sessions/live')
    const list = res.data?.sessions || res.data?.data || res.data || []
    const match = Array.isArray(list)
      ? list.find(s => (s.username || '').toLowerCase() === user.username.toLowerCase())
      : null
    currentSession.value = match || null
  } catch { if (!silent) currentSession.value = null }
  finally { if (!silent) sessionLoading.value = false }
}

const loadSessionHistory = async (user) => {
  historyLoading.value = true
  try {
    const res = await axios.get('/pppoe/sessions/inactive', { params: { username: user.username, per_page: 200 } })
    const raw = res.data?.data || res.data?.sessions || res.data || []
    const list = Array.isArray(raw) ? raw : []
    // Always filter client-side to guarantee only this user's sessions are shown
    const filtered = list.filter(s =>
      (s.username || '').toLowerCase() === user.username.toLowerCase()
    )
    sessionHistory.value = filtered.sort((a, b) =>
      new Date(b.disconnected_at || b.acctstoptime || 0) - new Date(a.disconnected_at || a.acctstoptime || 0)
    )
  } catch { sessionHistory.value = [] }
  finally { historyLoading.value = false }
}

const loadPaymentHistory = async (user) => {
  paymentsLoading.value = true
  try {
    const res = await axios.get(`/pppoe/payments/user/${user.id}`)
    const list = res.data?.data || res.data?.payments || res.data || []
    paymentHistory.value = Array.isArray(list) ? list.sort((a, b) => new Date(b.created_at || b.paid_at || 0) - new Date(a.created_at || a.paid_at || 0)) : []
  } catch { paymentHistory.value = [] }
  finally { paymentsLoading.value = false }
}

// ── Traffic chart (mirrors SessionDetailsOverlay) ────────────────────────
const chartContainer  = ref(null)
const chartRange      = ref('1h')
const chartType       = ref('line')
const downloadSeries  = ref([])   // [{ t: unix, v: bytes/s }]
const uploadSeries    = ref([])
const chartHoverIdx   = ref(-1)
const chartHoverData  = ref(null)

const chartHasData = computed(() =>
  downloadSeries.value.some(p => p.v > 0) || uploadSeries.value.some(p => p.v > 0)
)

const chartMax = computed(() => {
  const all = [...downloadSeries.value.map(p => p.v), ...uploadSeries.value.map(p => p.v)]
  return Math.max(...all, 1)
})

const chartPeakDl = computed(() => Math.max(...downloadSeries.value.map(p => p.v), 0))
const chartPeakUl = computed(() => Math.max(...uploadSeries.value.map(p => p.v), 0))

const chartYTicks = computed(() => {
  const max = chartMax.value
  const n = 4
  return Array.from({ length: n + 1 }, (_, i) => ({
    value:   (max / n) * i,
    label:   formatBytes((max / n) * i) + '/s',
    percent: (i / n) * 100,
  }))
})

const chartXTicks = computed(() => {
  const data = downloadSeries.value
  if (!data.length) return []
  const count = 5
  const step  = Math.floor((data.length - 1) / (count - 1)) || 1
  const pad   = 5
  const usable = 100 - pad * 2
  const ticks = []
  const seen  = new Set()
  let ti = 0
  for (let i = 0; i < data.length && ticks.length < count; i += step) {
    const label = new Date(data[i].t * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    if (seen.has(label) && i > 0 && i < data.length - 1) continue
    seen.add(label)
    ticks.push({ x: pad + (ti / (count - 1)) * usable, label })
    ti++
  }
  const last = data[data.length - 1]
  const lastLabel = new Date(last.t * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  if (ticks.length && ticks[ticks.length - 1].label !== lastLabel)
    ticks.push({ x: pad + usable, label: lastLabel })
  return ticks
})

const chartHoverX = computed(() => {
  if (chartHoverIdx.value < 0 || !downloadSeries.value.length) return 0
  return (chartHoverIdx.value / (downloadSeries.value.length - 1)) * 100
})

const buildChartPath = (series) => {
  if (!series.length) return ''
  const max  = Math.max(chartMax.value, 1)
  const minT = series[0].t
  const maxT = series[series.length - 1].t || minT + 1
  const range = maxT - minT || 1
  const cx = (t) => ((t - minT) / range) * 1000
  const cy = (v) => 200 - (v / max) * 200
  return 'M' + series.map(p => `${cx(p.t).toFixed(1)},${cy(p.v).toFixed(1)}`).join('L')
}

const buildAreaPath = (series) => {
  if (!series.length) return ''
  const line  = buildChartPath(series)
  const max   = Math.max(chartMax.value, 1)
  const minT  = series[0].t
  const maxT  = series[series.length - 1].t || minT + 1
  const range = maxT - minT || 1
  const lastX = (((series[series.length - 1].t) - minT) / range * 1000).toFixed(1)
  const firstX = '0.0'
  return `${line}L${lastX},200 L${firstX},200 Z`
}

const svgDlPath     = computed(() => buildChartPath(downloadSeries.value))
const svgUlPath     = computed(() => buildChartPath(uploadSeries.value))
const svgDlAreaPath = computed(() => buildAreaPath(downloadSeries.value))
const svgUlAreaPath = computed(() => buildAreaPath(uploadSeries.value))

const onChartHover = (event) => {
  const data = downloadSeries.value
  if (!data.length || !chartContainer.value) return
  const rect  = chartContainer.value.getBoundingClientRect()
  let idx = Math.round(((event.clientX - rect.left) / rect.width) * (data.length - 1))
  idx = Math.max(0, Math.min(idx, data.length - 1))
  chartHoverIdx.value  = idx
  chartHoverData.value = { t: data[idx].t, dl: data[idx].v, ul: uploadSeries.value[idx]?.v ?? 0 }
}
const onChartLeave = () => { chartHoverIdx.value = -1; chartHoverData.value = null }

const chartFormatTime = (ts) => {
  if (!ts) return ''
  return new Date(ts * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

const fetchUserChart = async () => {
  const username = panelUser.value?.username
  if (!username) return
  trafficLoading.value = true
  downloadSeries.value = []
  uploadSeries.value   = []
  try {
    const step = chartRange.value === '7d'  ? '1h'
               : chartRange.value === '24h' ? '5m'
               : chartRange.value === '6h'  ? '2m'
               : '30s'
    const resp = await axios.get(`pppoe/metrics/user/${encodeURIComponent(username)}`, {
      params: { range: chartRange.value, step },
    })
    const dl = resp.data?.download?.data?.result?.[0]?.values ?? []
    const ul = resp.data?.upload?.data?.result?.[0]?.values   ?? []
    downloadSeries.value = dl.map(([t, v]) => ({ t: Number(t), v: parseFloat(v) || 0 }))
    uploadSeries.value   = ul.map(([t, v]) => ({ t: Number(t), v: parseFloat(v) || 0 }))
  } catch { /**/ }
  finally { trafficLoading.value = false }
}

const loadTrafficMetrics = (user) => {
  if (!user?.username) return
  fetchUserChart()
}

// ── Password ──────────────────────────────────────────────────────────────
const showPasswordValue = ref(false)
const userPassword      = ref('')
const loadingPassword   = ref(false)

const handleViewPassword = async () => {
  if (!panelUser.value) return
  loadingPassword.value = true
  try {
    const data = await viewPassword(panelUser.value.id)
    userPassword.value = data?.password || ''
    showPasswordValue.value = true
  } catch (err) {
    const message = err?.response?.data?.message || err?.message || ''
    const canReset = /not available|disabled|reset/i.test(message)
    if (!canReset) return
    const ok = await confirmStore.open({
      title: 'Password Not Available',
      message: 'The current PPPoE password is not retrievable. Generate a new password now?',
      confirmText: 'Generate New',
      cancelText: 'Cancel',
      variant: 'warning',
    })
    if (!ok) return
    const { generatedPassword: pw } = await resetPassword(panelUser.value.id)
    userPassword.value = pw || ''
    showPasswordValue.value = true
  } finally { loadingPassword.value = false }
}

const hidePassword = () => { showPasswordValue.value = false; userPassword.value = '' }

const handleResetPassword = async () => {
  if (!panelUser.value) return
  const ok = await confirmStore.open({ title: 'Reset Password', message: `Reset password for ${panelUser.value.username}?`, confirmText: 'Reset', cancelText: 'Cancel', variant: 'warning' })
  if (!ok) return
  try {
    const { generatedPassword: pw } = await resetPassword(panelUser.value.id)
    userPassword.value = pw || ''
    showPasswordValue.value = true
  } catch { /**/ }
}

// ── Portal Password ───────────────────────────────────────────────────────
const showPortalPasswordValue = ref(false)
const userPortalPassword      = ref('')
const loadingPortalPassword   = ref(false)
const portalLoginPath         = ref('/portal/login')
const portalAccountNumber     = ref('')

const portalProvisioningUrl = computed(() => {
  const path = portalLoginPath.value || '/portal/login'
  const normalized = path.startsWith('/') ? path : `/${path}`
  const accountNo = portalAccountNumber.value || panelUser.value?.account_number || ''
  const withAccount = accountNo
    ? `${normalized}?account_number=${encodeURIComponent(accountNo)}`
    : normalized

  if (typeof window === 'undefined') return withAccount
  return `${window.location.origin}${withAccount}`
})

const handleViewPortalPassword = async () => {
  if (!panelUser.value) return
  try {
    const meta = await viewPortalPassword(panelUser.value.id)
    portalLoginPath.value = meta?.portal_login_url || '/portal/login'
    portalAccountNumber.value = meta?.account_number || panelUser.value?.account_number || ''
  } catch { /**/ }
  // Portal password is stored hashed and cannot be retrieved.
  // Prompt user to reset to generate a new viewable password.
  const ok = await confirmStore.open({
    title: 'View Portal Password',
    message: 'Portal passwords are stored securely and cannot be viewed. Would you like to generate a new portal password?',
    confirmText: 'Generate New',
    cancelText: 'Cancel',
    variant: 'warning',
  })
  if (!ok) return
  loadingPortalPassword.value = true
  try {
    const { portalPassword: newPw } = await resetPortalPassword(panelUser.value.id)
    userPortalPassword.value = newPw || ''
    showPortalPasswordValue.value = true
  } catch { /**/ } finally { loadingPortalPassword.value = false }
}

const hidePortalPassword = () => { showPortalPasswordValue.value = false; userPortalPassword.value = '' }

const handleResetPortalPassword = async () => {
  if (!panelUser.value) return
  const ok = await confirmStore.open({ title: 'Reset Portal Password', message: `Reset portal password for ${panelUser.value.username}? A new password will be generated.`, confirmText: 'Reset', cancelText: 'Cancel', variant: 'warning' })
  if (!ok) return
  try {
    const { portalPassword: newPw } = await resetPortalPassword(panelUser.value.id)
    userPortalPassword.value = newPw || ''
    showPortalPasswordValue.value = true
  } catch { /**/ }
}

const copyPortalProvisioningUrl = async () => {
  try {
    await navigator.clipboard.writeText(portalProvisioningUrl.value)
  } catch { /**/ }
}

// ── Edit ──────────────────────────────────────────────────────────────────
const editSubmitting  = ref(false)
const editFormError   = ref('')
const editFieldErrors = reactive({ package_id: '', router_id: '', simultaneous_use: '', status: '' })
const editForm = reactive({ package_id: '', router_id: '', simultaneous_use: 1, status: 'active' })

const resetEditErrors = () => { editFormError.value = ''; Object.keys(editFieldErrors).forEach(k => editFieldErrors[k] = '') }

const handleUpdateUser = async () => {
  if (!panelUser.value) return
  resetEditErrors()
  editSubmitting.value = true
  try {
    const updated = await updateUser(panelUser.value.id, {
      package_id: editForm.package_id,
      router_id: editForm.router_id,
      simultaneous_use: Number(editForm.simultaneous_use || 1),
      status: String(editForm.status || 'active'),
    })
    panelUser.value = { ...panelUser.value, ...(updated || {}) }
    editExpanded.value = false
    resetEditErrors()
    Object.assign(editForm, { package_id: '', router_id: '', simultaneous_use: 1, status: 'active' })
    await fetchUsers()
  } catch (err) {
    const message = err.response?.data?.message || 'Failed to update user'
    if (err.response?.status === 422) {
      const errs = err.response?.data?.errors || {}
      Object.keys(editFieldErrors).forEach(k => editFieldErrors[k] = errs[k]?.[0] || '')
    }
    editFormError.value = message
  } finally { editSubmitting.value = false }
}

// ── Block / Disconnect ────────────────────────────────────────────────────
const handleToggleStatus = async (user) => {
  const action = user.status === 'blocked' ? 'unblock' : 'block'
  const ok = await confirmStore.open({
    title: 'Confirm Action',
    message: `${action === 'block' ? 'Block' : 'Unblock'} ${user.name || user.username}?`,
    confirmText: 'Confirm', cancelText: 'Cancel',
    variant: user.status === 'blocked' ? 'success' : 'warning',
  })
  if (!ok) return
  try {
    await toggleUserStatus(user.id, user.status !== 'blocked')
    if (panelUser.value?.id === user.id) {
      panelUser.value = { ...panelUser.value, status: action === 'block' ? 'blocked' : 'active' }
    }
    await fetchUsers()
  } catch { /**/ }
}

const handleDisconnect = async (session) => {
  const ok = await confirmStore.open({ title: 'Disconnect Session', message: 'Force-disconnect this session?', confirmText: 'Disconnect', cancelText: 'Cancel', variant: 'warning' })
  if (!ok) return
  try {
    await axios.post('/pppoe/sessions/disconnect', { username: panelUser.value?.username, session_id: session.radacctid || session.id })
    currentSession.value = null
  } catch { /**/ }
}

// ── Add user ──────────────────────────────────────────────────────────────
const showAddUserOverlay = ref(false)
const addSubmitting      = ref(false)
const addFormError       = ref('')
const addFieldErrors     = reactive({ username: '', package_id: '', router_id: '', simultaneous_use: '' })
const addForm = reactive({ username: '', package_id: '', router_id: '', simultaneous_use: 1 })

const showPasswordModal = ref(false)
const generatedPassword = ref('')
const createdUser       = ref(null)

const openAddUser = () => {
  addFormError.value = ''
  Object.keys(addFieldErrors).forEach(k => addFieldErrors[k] = '')
  Object.assign(addForm, { username: '', package_id: '', router_id: '', simultaneous_use: 1 })
  showAddUserOverlay.value = true
}
const closeAddUser = () => {
  showAddUserOverlay.value = false
  addFormError.value = ''
  Object.keys(addFieldErrors).forEach(k => addFieldErrors[k] = '')
  Object.assign(addForm, { username: '', package_id: '', router_id: '', simultaneous_use: 1 })
}

const handleCreateUser = async () => {
  addFormError.value = ''
  Object.keys(addFieldErrors).forEach(k => addFieldErrors[k] = '')
  addSubmitting.value = true
  try {
    const { user, generatedPassword: pw } = await createUser({
      username: String(addForm.username || '').trim(),
      package_id: addForm.package_id,
      router_id: addForm.router_id,
      simultaneous_use: Number(addForm.simultaneous_use || 1),
    })
    createdUser.value = user
    generatedPassword.value = pw || ''
    showPasswordModal.value = true
    closeAddUser()
  } catch (err) {
    const message = err.response?.data?.message || 'Failed to create user'
    if (err.response?.status === 422) {
      const errs = err.response?.data?.errors || {}
      Object.keys(addFieldErrors).forEach(k => addFieldErrors[k] = errs[k]?.[0] || '')
    }
    addFormError.value = message
  } finally { addSubmitting.value = false }
}

const copyPassword = async () => {
  if (!generatedPassword.value) return
  await navigator.clipboard.writeText(generatedPassword.value).catch(() => {})
}

const finishCreateUser = async () => {
  showPasswordModal.value = false
  generatedPassword.value = ''
  createdUser.value = null
  await fetchUsers()
}

// ── Helpers ───────────────────────────────────────────────────────────────
const getUserInitials = (user) => {
  const n = user?.name || user?.username || 'U'
  return n.split(' ').map(x => x[0]).join('').toUpperCase().slice(0, 2)
}

const formatDate = (d) => {
  if (!d) return '—'
  const ts = typeof d === 'number' || /^\d+$/.test(String(d)) ? new Date(Number(d) * 1000) : new Date(d)
  return ts.toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const formatDateShort = (d) => {
  if (!d) return '—'
  const ts = typeof d === 'number' || /^\d+$/.test(String(d)) ? new Date(Number(d) * 1000) : new Date(d)
  return ts.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

const formatPackageSpeed = (pkg) => {
  if (!pkg) return '—'
  const d = pkg.download_speed ? String(pkg.download_speed).trim() : ''
  const u = pkg.upload_speed ? String(pkg.upload_speed).trim() : ''
  if (d && u) return `${d} / ${u}`
  return d || u || '—'
}

const getDaysClass = (days) => {
  if (days === null || days === undefined) return 'text-slate-400'
  if (days < 0) return 'text-rose-600'
  if (days <= 7) return 'text-amber-600'
  return 'text-emerald-600'
}

const formatDaysToExpiry = (days) => {
  if (days === null || days === undefined) return '—'
  const d = Math.round(Number(days))
  if (d < 0) return `Expired ${Math.abs(d)}d ago`
  if (d === 0) return 'Expires today'
  return `${d} days`
}

const formatBytes = (bytes) => {
  const b = Number(bytes) || 0
  if (b < 1024) return `${b.toFixed(2)} B`
  if (b < 1024 ** 2) return `${(b / 1024).toFixed(2)} KB`
  if (b < 1024 ** 3) return `${(b / 1024 ** 2).toFixed(2)} MB`
  return `${(b / 1024 ** 3).toFixed(2)} GB`
}

const formatDuration = (secs) => {
  const s = Number(secs) || 0
  if (!s) return '—'
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  if (h > 0) return `${h}h ${m}m`
  return `${m}m`
}

const reasonBadge = (cause) => {
  if (!cause) return 'bg-slate-100 text-slate-600'
  if (cause === 'User-Request') return 'bg-blue-100 text-blue-700'
  if (cause.includes('Timeout')) return 'bg-amber-100 text-amber-700'
  if (cause.includes('Error') || cause.includes('Lost')) return 'bg-rose-100 text-rose-700'
  if (cause.includes('Admin') || cause.includes('NAS')) return 'bg-purple-100 text-purple-700'
  return 'bg-slate-100 text-slate-600'
}

// ── InfoCell component ────────────────────────────────────────────────────
const InfoCell = defineComponent({
  props: { label: String, value: String, mono: Boolean },
  setup(props, { slots }) {
    return () => h('div', {}, [
      h('div', { class: 'text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1' }, props.label),
      slots.default
        ? slots.default()
        : h('div', { class: props.mono ? 'text-sm font-mono text-slate-700' : 'text-sm text-slate-700' }, props.value || '—'),
    ])
  },
})

// ── Three-dot menu ────────────────────────────────────────────────────────
const activeMenu   = ref(null)
const menuPosition = ref({})

// ── Toggle edit with auto-scroll ─────────────────────────────────────────
const toggleEdit = async () => {
  editExpanded.value = !editExpanded.value
  if (editExpanded.value) {
    await nextTick()
    // Scroll the panel content area to show the edit form
    const container = panelScrollRef.value
    if (container) {
      const bottom = container.scrollHeight
      container.scrollTo({ top: bottom, behavior: 'smooth' })
    }
  }
}

const toggleMenu = (userId, event) => {
  event.stopPropagation()
  if (activeMenu.value === userId) { closeMenu(); return }
  activeMenu.value = userId
  const rect = event.currentTarget.getBoundingClientRect()
  const menuWidth = 208
  const menuHeight = 220
  let top  = rect.bottom + 4
  let left = rect.right - menuWidth
  if (rect.bottom + menuHeight > window.innerHeight) top  = rect.top - menuHeight - 4
  if (left < 8) left = rect.left
  if (left + menuWidth > window.innerWidth - 8) left = window.innerWidth - menuWidth - 8
  menuPosition.value = { top: `${top}px`, left: `${left}px` }
}

const closeMenu = () => { activeMenu.value = null; menuPosition.value = {} }

const handleClickOutside = (e) => {
  const menu   = document.querySelector('[data-dropdown-menu]')
  const button = document.querySelector('[data-menu-button]')
  if (menu && !menu.contains(e.target) && button && !button.contains(e.target)) closeMenu()
}

const handleKeydown = (e) => { if (e.key === 'Escape') closeMenu() }

const openEditFromMenu = (user) => {
  closeMenu()
  if (!user) return
  openUserPanel(user)
  // switch to edit tab after panel opens
  setTimeout(() => { activeTab.value = 'details'; editExpanded.value = true }, 50)
}

const handleResetPasswordForMenu = async (user) => {
  closeMenu()
  if (!user) return
  const confirmed = await confirmStore.open({
    title: 'Reset Password',
    message: `Reset the password for ${user.username}? A new password will be generated.`,
    confirmText: 'Reset',
    cancelText: 'Cancel',
    variant: 'warning',
  })
  if (!confirmed) return
  try {
    const { generatedPassword: newPwd } = await resetPassword(user.id)
    openUserPanel(user)
    setTimeout(() => {
      userPassword.value = newPwd || ''
      showPasswordValue.value = true
    }, 100)
  } catch {}
}

const handleResetPortalPasswordForMenu = async (user) => {
  closeMenu()
  if (!user) return
  const confirmed = await confirmStore.open({
    title: 'Reset Portal Password',
    message: `Reset the portal password for ${user.username}? A new password will be generated for the customer portal.`,
    confirmText: 'Reset',
    cancelText: 'Cancel',
    variant: 'warning',
  })
  if (!confirmed) return
  try {
    const { portalPassword: newPortalPwd } = await resetPortalPassword(user.id)
    openUserPanel(user)
    setTimeout(() => {
      userPortalPassword.value = newPortalPwd || ''
      showPortalPasswordValue.value = true
    }, 100)
  } catch {}
}

// ── Lifecycle ─────────────────────────────────────────────────────────────
onMounted(() => {
  fetchUsers()
  fetchPackages()
  fetchRouters()
  subscribeToWebSocket()
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  unsubscribeFromWebSocket()
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', handleKeydown)
})
</script>

<style scoped>
.slide-down-enter-active, .slide-down-leave-active { transition: all 0.2s ease; }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-8px); }
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
