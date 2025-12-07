<template>
  <aside
    class="fixed top-16 left-0 w-64 h-[calc(100vh-4rem)] bg-gray-900 text-gray-200 flex flex-col justify-between border-r border-gray-800 transition-all duration-300 ease-in-out z-40"
    :class="{
      hidden: !isSidebarOpen,
      block: isSidebarOpen,
    }"
  >
    <div
      class="overflow-y-auto scrollbar-thin scrollbar-thumb-gray-700 scrollbar-track-transparent hover:scrollbar-thumb-gray-600"
    >
      <nav class="p-4 space-y-1">
        <!-- Dashboard -->
        <router-link
          :to="dashboardRoute"
          class="w-full flex items-center gap-3 py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
          :class="isDashboardActive ? 'bg-gray-800 text-white' : ''"
          @click="isMobile && $emit('close-sidebar')"
        >
          <LayoutDashboard class="w-5 h-5 flex-shrink-0" />
          <span class="text-sm font-medium">Dashboard</span>
        </router-link>
        <!-- Admin Users -->
        <div>
          <button
            @click="toggleMenu('users')"
            class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
            :class="isActiveUsers ? 'bg-gray-800 text-white' : ''"
          >
            <span class="flex items-center gap-3">
              <Users class="w-5 h-5 flex-shrink-0" />
              <span class="text-sm font-medium">Admin Users</span>
            </span>
            <ChevronDown
              class="w-4 h-4 transition-transform duration-200"
              :class="activeMenu === 'users' ? 'rotate-180' : ''"
            />
          </button>
          <div
            v-show="activeMenu === 'users'"
            class="overflow-hidden transition-all duration-200 ease-out"
            :class="activeMenu === 'users' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
          >
            <div class="ml-9 space-y-1">
              <router-link
                to="/dashboard/users/all"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/users/all' ? 'bg-gray-800 text-white font-medium' : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                All Admin Users
              </router-link>
              <router-link
                to="/dashboard/users/create"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/users/create'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Create Admin
              </router-link>
              <router-link
                to="/dashboard/users/roles"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/users/roles'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Roles & Permissions
              </router-link>
              <router-link
                to="/dashboard/users/online"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/users/online'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Online Users
              </router-link>
            </div>
          </div>
        </div>
        <!-- Hotspot -->
        <div>
          <button
            @click="toggleMenu('hotspot')"
            class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
            :class="isActiveHotspot ? 'bg-gray-800 text-white' : ''"
          >
            <span class="flex items-center gap-3">
              <Wifi class="w-5 h-5 flex-shrink-0" />
              <span class="text-sm font-medium">Hotspot</span>
            </span>
            <ChevronDown
              class="w-4 h-4 transition-transform duration-200"
              :class="activeMenu === 'hotspot' ? 'rotate-180' : ''"
            />
          </button>
          <div
            v-show="activeMenu === 'hotspot'"
            class="overflow-hidden transition-all duration-200 ease-out"
            :class="activeMenu === 'hotspot' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
          >
            <div class="ml-9 space-y-1">
              <router-link
                to="/dashboard/hotspot/users"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/hotspot/users'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Hotspot Users
              </router-link>
              <router-link
                to="/dashboard/hotspot/sessions"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/hotspot/sessions'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Active Sessions
              </router-link>
              <router-link
                to="/dashboard/hotspot/vouchers/generate"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/hotspot/vouchers/generate'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Generate Vouchers
              </router-link>
              <router-link
                to="/dashboard/hotspot/vouchers/bulk"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/hotspot/vouchers/bulk'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Bulk Voucher Upload
              </router-link>
              <router-link
                to="/dashboard/hotspot/voucher-templates"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/hotspot/voucher-templates'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Voucher Templates
              </router-link>
              <router-link
                to="/dashboard/hotspot/profiles"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/hotspot/profiles'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Hotspot Profiles
              </router-link>
              <router-link
                to="/dashboard/hotspot/login-page"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/hotspot/login-page'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Login Page Customization
              </router-link>
            </div>
          </div>
        </div>
        <!-- PPPoE -->
        <div>
          <button
            @click="toggleMenu('pppoe')"
            class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
            :class="isActivePPPoE ? 'bg-gray-800 text-white' : ''"
          >
            <span class="flex items-center gap-3">
              <Network class="w-5 h-5 flex-shrink-0" />
              <span class="text-sm font-medium">PPPoE</span>
            </span>
            <ChevronDown
              class="w-4 h-4 transition-transform duration-200"
              :class="activeMenu === 'pppoe' ? 'rotate-180' : ''"
            />
          </button>
          <div
            v-show="activeMenu === 'pppoe'"
            class="overflow-hidden transition-all duration-200 ease-out"
            :class="activeMenu === 'pppoe' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
          >
            <div class="ml-9 space-y-1">
              <router-link
                to="/dashboard/pppoe/users"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/pppoe/users'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                PPPoE Users
              </router-link>
              <router-link
                to="/dashboard/pppoe/sessions"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/pppoe/sessions'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Active Sessions
              </router-link>
              <router-link
                to="/dashboard/pppoe/add-user"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/pppoe/add-user'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Add PPPoE User
              </router-link>
              <router-link
                to="/dashboard/pppoe/radius-profiles"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/pppoe/radius-profiles'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Radius Profiles
              </router-link>
              <router-link
                to="/dashboard/pppoe/queues"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/pppoe/queues'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Queues / Bandwidth Control
              </router-link>
              <router-link
                to="/dashboard/pppoe/auto-disconnect"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/pppoe/auto-disconnect'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Auto Disconnect Rules
              </router-link>
            </div>
          </div>
        </div>
        <!-- Billing -->
        <div>
          <button
            @click="toggleMenu('billing')"
            class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
            :class="isActiveBilling ? 'bg-gray-800 text-white' : ''"
          >
            <span class="flex items-center gap-3">
              <CreditCard class="w-5 h-5 flex-shrink-0" />
              <span class="text-sm font-medium">Billing</span>
            </span>
            <ChevronDown
              class="w-4 h-4 transition-transform duration-200"
              :class="activeMenu === 'billing' ? 'rotate-180' : ''"
            />
          </button>
          <div
            v-show="activeMenu === 'billing'"
            class="overflow-hidden transition-all duration-200 ease-out"
            :class="activeMenu === 'billing' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
          >
            <div class="ml-9 space-y-1">
              <router-link
                to="/dashboard/billing/invoices"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/billing/invoices'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Invoices
              </router-link>
              <router-link
                to="/dashboard/billing/payments"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/billing/payments'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Payments
              </router-link>
              <router-link
                to="/dashboard/billing/mpesa"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/billing/mpesa'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Mpesa Transactions
              </router-link>
              <router-link
                to="/dashboard/billing/wallet"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/billing/wallet'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Wallet / Account Balance
              </router-link>
              <router-link
                to="/dashboard/billing/payment-methods"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/billing/payment-methods'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Payment Methods
              </router-link>
            </div>
          </div>
        </div>
        <!-- Packages (Tenant Only) -->
        <div v-if="!isOnSystemAdminRoute">
          <button
            @click="toggleMenu('packages')"
            class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
            :class="isActivePackages ? 'bg-gray-800 text-white' : ''"
          >
            <span class="flex items-center gap-3">
              <Package class="w-5 h-5 flex-shrink-0" />
              <span class="text-sm font-medium">Packages</span>
            </span>
            <ChevronDown
              class="w-4 h-4 transition-transform duration-200"
              :class="activeMenu === 'packages' ? 'rotate-180' : ''"
            />
          </button>
          <div
            v-show="activeMenu === 'packages'"
            class="overflow-hidden transition-all duration-200 ease-out"
            :class="activeMenu === 'packages' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
          >
            <div class="ml-9 space-y-1">
              <router-link
                to="/dashboard/packages/all"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/packages/all'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                All Packages
              </router-link>
              <!-- <router-link
                to="/dashboard/packages/add"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/packages/add'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Add New Package
              </router-link> -->
              <router-link
                to="/dashboard/packages/groups"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/packages/groups'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Package Groups
              </router-link>
              <!-- <router-link
                to="/dashboard/packages/bandwidth-limits"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/packages/bandwidth-limits'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Bandwidth Limit Rules
              </router-link> -->
            </div>
          </div>
        </div>
        <!-- Routers / Devices (Tenant Only) -->
        <div v-if="!isOnSystemAdminRoute">
          <button
            @click="toggleMenu('routers')"
            class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
            :class="isActiveRouters ? 'bg-gray-800 text-white' : ''"
          >
            <span class="flex items-center gap-3">
              <Server class="w-5 h-5 flex-shrink-0" />
              <span class="text-sm font-medium">Routers / Devices</span>
            </span>
            <ChevronDown
              class="w-4 h-4 transition-transform duration-200"
              :class="activeMenu === 'routers' ? 'rotate-180' : ''"
            />
          </button>
          <div
            v-show="activeMenu === 'routers'"
            class="overflow-hidden transition-all duration-200 ease-out"
            :class="activeMenu === 'routers' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
          >
            <div class="ml-9 space-y-1">
              <router-link
                to="/dashboard/routers/mikrotik"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/routers/mikrotik'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                MikroTik List
              </router-link>
              <router-link
                to="/dashboard/routers/add"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/routers/add'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Add New Router
              </router-link>
              <router-link
                to="/dashboard/routers/api-status"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/routers/api-status'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                API Connection Status
              </router-link>
              <router-link
                to="/dashboard/routers/backup"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/routers/backup'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Backup Configurations
              </router-link>
            </div>
          </div>
        </div>
        <!-- Monitoring -->
        <div>
          <button
            @click="toggleMenu('monitoring')"
            class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
            :class="isActiveMonitoring ? 'bg-gray-800 text-white' : ''"
          >
            <span class="flex items-center gap-3">
              <Activity class="w-5 h-5 flex-shrink-0" />
              <span class="text-sm font-medium">Monitoring</span>
            </span>
            <ChevronDown
              class="w-4 h-4 transition-transform duration-200"
              :class="activeMenu === 'monitoring' ? 'rotate-180' : ''"
            />
          </button>
          <div
            v-show="activeMenu === 'monitoring'"
            class="overflow-hidden transition-all duration-200 ease-out"
            :class="activeMenu === 'monitoring' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
          >
            <div class="ml-9 space-y-1">
              <router-link
                to="/dashboard/monitoring/connections"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/monitoring/connections'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Live Connections
              </router-link>
              <router-link
                to="/dashboard/monitoring/traffic-graphs"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/monitoring/traffic-graphs'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Traffic Graphs
              </router-link>

              <router-link
                to="/dashboard/monitoring/latency-tests"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/monitoring/latency-tests'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Latency & Ping Tests
              </router-link>
              <router-link
                to="/dashboard/monitoring/session-logs"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/monitoring/session-logs'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Session Logs
              </router-link>
              <router-link
                to="/dashboard/monitoring/system-logs"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/monitoring/system-logs'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                System Logs
              </router-link>
            </div>
          </div>
        </div>
        <!-- Support / Tickets -->
        <div>
          <button
            @click="toggleMenu('support')"
            class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
            :class="isActiveSupport ? 'bg-gray-800 text-white' : ''"
          >
            <span class="flex items-center gap-3">
              <HelpCircle class="w-5 h-5 flex-shrink-0" />
              <span class="text-sm font-medium">Support / Tickets</span>
            </span>
            <ChevronDown
              class="w-4 h-4 transition-transform duration-200"
              :class="activeMenu === 'support' ? 'rotate-180' : ''"
            />
          </button>
          <div
            v-show="activeMenu === 'support'"
            class="overflow-hidden transition-all duration-200 ease-out"
            :class="activeMenu === 'support' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
          >
            <div class="ml-9 space-y-1">
              <router-link
                to="/dashboard/support/create-ticket"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/support/create-ticket'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Create Ticket
              </router-link>
              <router-link
                to="/dashboard/support/all-tickets"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/support/all-tickets'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                All Tickets
              </router-link>
              <router-link
                to="/dashboard/support/categories"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/support/categories'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Ticket Categories
              </router-link>
              <router-link
                to="/dashboard/support/response-templates"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/support/response-templates'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Response Templates
              </router-link>
            </div>
          </div>
        </div>
        <!-- Reports -->
        <div>
          <button
            @click="toggleMenu('reports')"
            class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
            :class="isActiveReports ? 'bg-gray-800 text-white' : ''"
          >
            <span class="flex items-center gap-3">
              <BarChart2 class="w-5 h-5 flex-shrink-0" />
              <span class="text-sm font-medium">Reports</span>
            </span>
            <ChevronDown
              class="w-4 h-4 transition-transform duration-200"
              :class="activeMenu === 'reports' ? 'rotate-180' : ''"
            />
          </button>
          <div
            v-show="activeMenu === 'reports'"
            class="overflow-hidden transition-all duration-200 ease-out"
            :class="activeMenu === 'reports' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
          >
            <div class="ml-9 space-y-1">
              <router-link
                to="/dashboard/reports/daily-logins"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/reports/daily-logins'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Daily Login Reports
              </router-link>
              <router-link
                to="/dashboard/reports/payments"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/reports/payments'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Payment Reports
              </router-link>
              <router-link
                to="/dashboard/reports/expired-accounts"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/reports/expired-accounts'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Expired Accounts
              </router-link>
              <router-link
                to="/dashboard/reports/session-history"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/reports/session-history'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                User Session History
              </router-link>
              <router-link
                to="/dashboard/reports/bandwidth-usage"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/reports/bandwidth-usage'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Bandwidth Usage Summary
              </router-link>
            </div>
          </div>
        </div>
        <!-- Todos -->
        <router-link
          to="/todos"
          class="w-full flex items-center gap-3 py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
          :class="route.path === '/todos' ? 'bg-gray-800 text-white' : ''"
          @click="isMobile && $emit('close-sidebar')"
        >
          <CheckSquare class="w-5 h-5 flex-shrink-0" />
          <span class="text-sm font-medium">Todos</span>
        </router-link>

        <!-- HR -->
        <div>
          <button
            @click="toggleMenu('hr')"
            class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
            :class="isActiveHR ? 'bg-gray-800 text-white' : ''"
          >
            <span class="flex items-center gap-3">
              <Users class="w-5 h-5 flex-shrink-0" />
              <span class="text-sm font-medium">HR</span>
            </span>
            <ChevronDown
              class="w-4 h-4 transition-transform duration-200"
              :class="activeMenu === 'hr' ? 'rotate-180' : ''"
            />
          </button>
          <div
            v-show="activeMenu === 'hr'"
            class="overflow-hidden transition-all duration-200 ease-out"
            :class="activeMenu === 'hr' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
          >
            <div class="ml-9 space-y-1">
              <router-link
                to="/hr/departments"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="route.path === '/hr/departments' ? 'bg-gray-800 text-white font-medium' : ''"
                @click="isMobile && $emit('close-sidebar')"
              >
                Departments
              </router-link>
              <router-link
                to="/hr/positions"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="route.path === '/hr/positions' ? 'bg-gray-800 text-white font-medium' : ''"
                @click="isMobile && $emit('close-sidebar')"
              >
                Positions
              </router-link>
              <router-link
                to="/hr/employees"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="route.path === '/hr/employees' ? 'bg-gray-800 text-white font-medium' : ''"
                @click="isMobile && $emit('close-sidebar')"
              >
                Employees
              </router-link>
            </div>
          </div>
        </div>

        <!-- Finance -->
        <div>
          <button
            @click="toggleMenu('finance')"
            class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
            :class="isActiveFinance ? 'bg-gray-800 text-white' : ''"
          >
            <span class="flex items-center gap-3">
              <DollarSign class="w-5 h-5 flex-shrink-0" />
              <span class="text-sm font-medium">Finance</span>
            </span>
            <ChevronDown
              class="w-4 h-4 transition-transform duration-200"
              :class="activeMenu === 'finance' ? 'rotate-180' : ''"
            />
          </button>
          <div
            v-show="activeMenu === 'finance'"
            class="overflow-hidden transition-all duration-200 ease-out"
            :class="activeMenu === 'finance' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
          >
            <div class="ml-9 space-y-1">
              <router-link
                to="/finance/expenses"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="route.path === '/finance/expenses' ? 'bg-gray-800 text-white font-medium' : ''"
                @click="isMobile && $emit('close-sidebar')"
              >
                Expenses
              </router-link>
              <router-link
                to="/finance/revenues"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="route.path === '/finance/revenues' ? 'bg-gray-800 text-white font-medium' : ''"
                @click="isMobile && $emit('close-sidebar')"
              >
                Revenues
              </router-link>
            </div>
          </div>
        </div>

        <!-- Settings -->
        <div>
          <button
            @click="toggleMenu('settings')"
            class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
            :class="isActiveSettings ? 'bg-gray-800 text-white' : ''"
          >
            <span class="flex items-center gap-3">
              <Settings class="w-5 h-5 flex-shrink-0" />
              <span class="text-sm font-medium">Settings</span>
            </span>
            <ChevronDown
              class="w-4 h-4 transition-transform duration-200"
              :class="activeMenu === 'settings' ? 'rotate-180' : ''"
            />
          </button>
          <div
            v-show="activeMenu === 'settings'"
            class="overflow-hidden transition-all duration-200 ease-out"
            :class="activeMenu === 'settings' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
          >
            <div class="ml-9 space-y-1">
              <router-link
                to="/dashboard/settings/general"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/settings/general'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                General Settings
              </router-link>
              <router-link
                to="/dashboard/settings/mikrotik-api"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/settings/mikrotik-api'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Mikrotik API Credentials
              </router-link>
              <router-link
                to="/dashboard/settings/radius-server"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/settings/radius-server'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Radius Server Settings
              </router-link>
              <router-link
                to="/dashboard/settings/email-sms"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/settings/email-sms'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Email & SMS Settings
              </router-link>
              <router-link
                to="/dashboard/settings/mpesa-api"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/settings/mpesa-api'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Mpesa/API Keys
              </router-link>
              <router-link
                to="/dashboard/settings/timezone-locale"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/settings/timezone-locale'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Timezone & Locale
              </router-link>
            </div>
          </div>
        </div>
        <!-- Admin Tools (System Admin Only) -->
        <div v-if="isSystemAdmin">
          <button
            @click="toggleMenu('adminTools')"
            class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
            :class="isActiveAdminTools ? 'bg-gray-800 text-white' : ''"
          >
            <span class="flex items-center gap-3">
              <Wrench class="w-5 h-5 flex-shrink-0" />
              <span class="text-sm font-medium">System Admin Tools</span>
            </span>
            <ChevronDown
              class="w-4 h-4 transition-transform duration-200"
              :class="activeMenu === 'adminTools' ? 'rotate-180' : ''"
            />
          </button>
          <div
            v-show="activeMenu === 'adminTools'"
            class="overflow-hidden transition-all duration-200 ease-out"
            :class="activeMenu === 'adminTools' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
          >
            <div class="ml-9 space-y-1">
              <router-link
                to="/dashboard/admin/roles-permissions"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/admin/roles-permissions'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Roles & Permissions
              </router-link>
              <router-link
                to="/dashboard/admin/activity-logs"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/admin/activity-logs'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Activity Logs
              </router-link>
              <router-link
                to="/dashboard/admin/backup-restore"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/admin/backup-restore'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Backup & Restore
              </router-link>
              <router-link
                to="/dashboard/admin/cache-management"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/admin/cache-management'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                Cache Management
              </router-link>
              <router-link
                to="/dashboard/admin/system-updates"
                class="block py-2 px-3 rounded-lg hover:bg-gray-800 text-sm transition-colors duration-150"
                :class="
                  route.path === '/dashboard/admin/system-updates'
                    ? 'bg-gray-800 text-white font-medium'
                    : ''
                "
                @click="isMobile && $emit('close-sidebar')"
              >
                System Updates
              </router-link>
            </div>
          </div>
        </div>
      </nav>
    </div>
    <!-- Sidebar Footer -->
    <div class="text-xs text-gray-500 border-t border-gray-800">
      <div>© {{ new Date().getFullYear() }} TraidNet Solutions</div>
      <div class="mt-0.5">All rights reserved</div>
    </div>
  </aside>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useStorage } from '@vueuse/core'
import { useAuthStore } from '@/stores/auth'
import {
  LayoutDashboard,
  Users,
  Wifi,
  Network,
  CreditCard,
  Package,
  Server,
  Activity,
  HelpCircle,
  BarChart2,
  Settings,
  Wrench,
  ChevronDown,
  CheckSquare,
  DollarSign,
} from 'lucide-vue-next'

// Props to control sidebar visibility
defineProps({
  isSidebarOpen: {
    type: Boolean,
    required: true,
  },
  isMobile: {
    type: Boolean,
    required: true,
  },
})

// Define emit for closing sidebar
defineEmits(['close-sidebar'])

// Current route
const route = useRoute()

// Auth store for role checking
const authStore = useAuthStore()
const user = computed(() => authStore.user)

// Role-based visibility
const isSystemAdmin = computed(() => user.value?.role === 'system_admin')
const isTenantAdmin = computed(() => user.value?.role === 'admin')
const isHotspotUser = computed(() => user.value?.role === 'hotspot_user')

// Single state for active menu (persistent)
const activeMenu = useStorage('sidebar-active-menu', null)

// Toggle menu function
const toggleMenu = (menu) => {
  activeMenu.value = activeMenu.value === menu ? null : menu
}

// Expand submenu based on route
watch(
  () => route.path,
  (path) => {
    if (
      path === '/dashboard' ||
      path.startsWith('/dashboard/statistics') ||
      path.startsWith('/dashboard/health')
    ) {
      activeMenu.value = 'dashboard'
    } else if (path.startsWith('/dashboard/users')) {
      activeMenu.value = 'users'
    } else if (path.startsWith('/dashboard/hotspot')) {
      activeMenu.value = 'hotspot'
    } else if (path.startsWith('/dashboard/pppoe')) {
      activeMenu.value = 'pppoe'
    } else if (path.startsWith('/dashboard/billing')) {
      activeMenu.value = 'billing'
    } else if (path.startsWith('/dashboard/packages')) {
      activeMenu.value = 'packages'
    } else if (path.startsWith('/dashboard/routers')) {
      activeMenu.value = 'routers'
    } else if (path.startsWith('/dashboard/monitoring')) {
      activeMenu.value = 'monitoring'
    } else if (path.startsWith('/dashboard/support')) {
      activeMenu.value = 'support'
    } else if (path.startsWith('/dashboard/reports')) {
      activeMenu.value = 'reports'
    } else if (path.startsWith('/dashboard/settings')) {
      activeMenu.value = 'settings'
    } else if (path.startsWith('/dashboard/admin')) {
      activeMenu.value = 'adminTools'
    } else if (path.startsWith('/hr')) {
      activeMenu.value = 'hr'
    } else if (path.startsWith('/finance')) {
      activeMenu.value = 'finance'
    }
  },
  { immediate: true },
)

// Active section highlight
const isActiveDashboard = computed(
  () =>
    route.path === '/dashboard' ||
    route.path.startsWith('/dashboard/statistics') ||
    route.path.startsWith('/dashboard/health'),
)
const isActiveUsers = computed(() => route.path.startsWith('/dashboard/users'))
const isActiveHotspot = computed(() => route.path.startsWith('/dashboard/hotspot'))
const isActivePPPoE = computed(() => route.path.startsWith('/dashboard/pppoe'))
const isActiveBilling = computed(() => route.path.startsWith('/dashboard/billing'))
const isActivePackages = computed(() => route.path.startsWith('/dashboard/packages'))
const isActiveRouters = computed(() => route.path.startsWith('/dashboard/routers'))
const isActiveMonitoring = computed(() => route.path.startsWith('/dashboard/monitoring'))
const isActiveSupport = computed(() => route.path.startsWith('/dashboard/support'))
const isActiveReports = computed(() => route.path.startsWith('/dashboard/reports'))
const isActiveSettings = computed(() => route.path.startsWith('/dashboard/settings'))
const isActiveAdminTools = computed(() => route.path.startsWith('/dashboard/admin'))
const isActiveHR = computed(() => route.path.startsWith('/hr'))
const isActiveFinance = computed(() => route.path.startsWith('/finance'))

// Check if we're on system admin routes
const isOnSystemAdminRoute = computed(() => route.path.startsWith('/system'))

// Dynamic dashboard route based on user role
const dashboardRoute = computed(() => {
  if (isSystemAdmin.value) {
    return '/system/dashboard'
  }
  return '/dashboard'
})

// Check if dashboard is active
const isDashboardActive = computed(() => {
  if (isSystemAdmin.value) {
    return route.path === '/system/dashboard'
  }
  return route.path === '/dashboard'
})
</script>
