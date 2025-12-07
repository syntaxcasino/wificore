<template>
  <aside
    class="fixed top-16 left-0 w-64 h-[calc(100vh-4rem)] bg-gradient-to-b from-gray-900 via-gray-900 to-gray-950 text-gray-100 flex flex-col justify-between border-r border-gray-800/50 shadow-2xl transition-all duration-300 ease-in-out z-40"
    :class="{
      hidden: !isSidebarOpen,
      block: isSidebarOpen,
    }"
  >
    <div
      class="overflow-y-auto scrollbar-thin scrollbar-thumb-gray-700 scrollbar-track-transparent hover:scrollbar-thumb-gray-600"
    >
      <nav class="p-4 space-y-0.5">
        <!-- Dashboard -->
        <router-link
          :to="dashboardRoute"
          class="w-full flex items-center gap-3 py-3 px-3.5 rounded-lg hover:bg-gradient-to-r hover:from-blue-600/20 hover:to-blue-500/10 hover:border-l-2 hover:border-blue-500 transition-all duration-200 group"
          :class="isDashboardActive ? 'bg-gradient-to-r from-blue-600/30 to-blue-500/20 border-l-2 border-blue-500 text-white shadow-lg shadow-blue-500/10' : 'text-gray-300'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <LayoutDashboard class="w-5 h-5 flex-shrink-0 group-hover:scale-110 transition-transform duration-200" :class="isDashboardActive ? 'text-blue-400' : ''" />
          <span class="text-sm font-semibold">Dashboard</span>
        </router-link>

        <!-- Todos -->
        <router-link
          to="/dashboard/todos"
          class="w-full flex items-center gap-3 py-3 px-3.5 rounded-lg hover:bg-gradient-to-r hover:from-green-600/20 hover:to-green-500/10 hover:border-l-2 hover:border-green-500 transition-all duration-200 group"
          :class="route.path === '/dashboard/todos' ? 'bg-gradient-to-r from-green-600/30 to-green-500/20 border-l-2 border-green-500 text-white shadow-lg shadow-green-500/10' : 'text-gray-300'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <CheckSquare class="w-5 h-5 flex-shrink-0 group-hover:scale-110 transition-transform duration-200" :class="route.path === '/dashboard/todos' ? 'text-green-400' : ''" />
          <span class="text-sm font-semibold">Todos</span>
        </router-link>

        <!-- Section: Customers & Users -->
        <div class="pt-6 pb-2">
          <div class="px-3 py-2 text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2">
            <div class="w-1 h-4 bg-gradient-to-b from-blue-500 to-purple-500 rounded-full"></div>
            Customers
          </div>

          <!-- Hotspot Users -->
          <div>
            <button
              @click="toggleMenu('hotspot')"
              class="w-full flex items-center justify-between py-3 px-3.5 rounded-lg hover:bg-gray-800/60 transition-all duration-200 group"
              :class="isActiveHotspot ? 'bg-gray-800/80 text-white' : 'text-gray-300'"
            >
              <span class="flex items-center gap-3">
                <Radio class="w-5 h-5 flex-shrink-0 group-hover:scale-110 transition-transform duration-200" :class="isActiveHotspot ? 'text-blue-400' : ''" />
                <span class="text-sm font-semibold">Hotspot Users</span>
              </span>
              <ChevronDown
                class="w-4 h-4 transition-transform duration-200"
                :class="activeMenu === 'hotspot' ? 'rotate-180 text-blue-400' : ''"
              />
            </button>
            <div
              v-show="activeMenu === 'hotspot'"
              class="overflow-hidden transition-all duration-200 ease-out"
              :class="activeMenu === 'hotspot' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
            >
              <div class="ml-8 space-y-0.5 mt-1 border-l-2 border-gray-800/50 pl-3">
                <router-link
                  to="/dashboard/hotspot/users"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/hotspot/users' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-blue-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  <span class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-gray-600 group-hover:bg-blue-400 transition-colors" :class="route.path === '/dashboard/hotspot/users' ? 'bg-blue-400' : ''"></span>
                    All Users
                  </span>
                </router-link>
                <router-link
                  to="/dashboard/hotspot/sessions"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/hotspot/sessions' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-blue-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  <span class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-gray-600 group-hover:bg-blue-400 transition-colors" :class="route.path === '/dashboard/hotspot/sessions' ? 'bg-blue-400' : ''"></span>
                    Active Sessions
                  </span>
                </router-link>
                <router-link
                  to="/dashboard/hotspot/profiles"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/hotspot/profiles' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-blue-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  <span class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-gray-600 group-hover:bg-blue-400 transition-colors" :class="route.path === '/dashboard/hotspot/profiles' ? 'bg-blue-400' : ''"></span>
                    User Groups
                  </span>
                </router-link>
              </div>
            </div>
          </div>

          <!-- PPPoE Users -->
          <div>
            <button
              @click="toggleMenu('pppoe')"
              class="w-full flex items-center justify-between py-3 px-3.5 rounded-lg hover:bg-gray-800/60 transition-all duration-200 group"
              :class="isActivePPPoE ? 'bg-gray-800/80 text-white' : 'text-gray-300'"
            >
              <span class="flex items-center gap-3">
                <Cable class="w-5 h-5 flex-shrink-0 group-hover:scale-110 transition-transform duration-200" :class="isActivePPPoE ? 'text-purple-400' : ''" />
                <span class="text-sm font-semibold">PPPoE Users</span>
              </span>
              <ChevronDown
                class="w-4 h-4 transition-transform duration-200"
                :class="activeMenu === 'pppoe' ? 'rotate-180 text-purple-400' : ''"
              />
            </button>
            <div
              v-show="activeMenu === 'pppoe'"
              class="overflow-hidden transition-all duration-200 ease-out"
              :class="activeMenu === 'pppoe' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
            >
              <div class="ml-8 space-y-0.5 mt-1 border-l-2 border-gray-800/50 pl-3">
                <router-link
                  to="/dashboard/pppoe/users"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/pppoe/users' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-purple-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  All Users
                </router-link>
                <router-link
                  to="/dashboard/pppoe/sessions"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/pppoe/sessions' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-purple-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Active Sessions
                </router-link>
                <router-link
                  to="/dashboard/pppoe/add-user"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/pppoe/add-user' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-purple-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Add User
                </router-link>
                <router-link
                  to="/dashboard/pppoe/radius-profiles"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/pppoe/radius-profiles' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-purple-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  RADIUS Profiles
                </router-link>
              </div>
            </div>
          </div>
        </div>

        <!-- Section: Products & Services -->
        <div class="pt-6 pb-2">
          <div class="px-3 py-2 text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2">
            <div class="w-1 h-4 bg-gradient-to-b from-emerald-500 to-teal-500 rounded-full"></div>
            Products & Services
          </div>

          <!-- Packages -->
          <div v-if="!isOnSystemAdminRoute">
            <button
              @click="toggleMenu('packages')"
              class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
              :class="isActivePackages ? 'bg-gray-800 text-white' : ''"
            >
              <span class="flex items-center gap-3">
                <Box class="w-5 h-5 flex-shrink-0" />
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
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/packages/all' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  All Packages
                </router-link>
                <router-link
                  to="/dashboard/packages/groups"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/packages/groups' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Package Groups
                </router-link>
              </div>
            </div>
          </div>

          <!-- Vouchers -->
          <div>
            <button
              @click="toggleMenu('vouchers')"
              class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
              :class="isActiveVouchers ? 'bg-gray-800 text-white' : ''"
            >
              <span class="flex items-center gap-3">
                <Ticket class="w-5 h-5 flex-shrink-0" />
                <span class="text-sm font-medium">Vouchers</span>
              </span>
              <ChevronDown
                class="w-4 h-4 transition-transform duration-200"
                :class="activeMenu === 'vouchers' ? 'rotate-180' : ''"
              />
            </button>
            <div
              v-show="activeMenu === 'vouchers'"
              class="overflow-hidden transition-all duration-200 ease-out"
              :class="activeMenu === 'vouchers' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
            >
              <div class="ml-9 space-y-1">
                <router-link
                  to="/dashboard/hotspot/vouchers/generate"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/hotspot/vouchers/generate' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Generate Vouchers
                </router-link>
                <router-link
                  to="/dashboard/hotspot/vouchers/bulk"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/hotspot/vouchers/bulk' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Bulk Upload
                </router-link>
                <router-link
                  to="/dashboard/hotspot/voucher-templates"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/hotspot/voucher-templates' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Voucher Templates
                </router-link>
              </div>
            </div>
          </div>
        </div>

        <!-- Section: Finance & Billing -->
        <div class="pt-6 pb-2">
          <div class="px-3 py-2 text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2">
            <div class="w-1 h-4 bg-gradient-to-b from-amber-500 to-orange-500 rounded-full"></div>
            Finance & Billing
          </div>

          <!-- Billing -->
          <div>
            <button
              @click="toggleMenu('billing')"
              class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
              :class="isActiveBilling ? 'bg-gray-800 text-white' : ''"
            >
              <span class="flex items-center gap-3">
                <Wallet class="w-5 h-5 flex-shrink-0" />
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
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/billing/invoices' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Invoices
                </router-link>
                <router-link
                  to="/dashboard/billing/payments"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/billing/payments' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Payments
                </router-link>
                <router-link
                  to="/dashboard/billing/mpesa"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/billing/mpesa' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  M-Pesa Transactions
                </router-link>
                <router-link
                  to="/dashboard/billing/wallet"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/billing/wallet' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Wallet / Balance
                </router-link>
                <router-link
                  to="/dashboard/billing/payment-methods"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/billing/payment-methods' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Payment Methods
                </router-link>
                <router-link
                  to="/dashboard/finance/expenses"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/finance/expenses' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Expenses
                </router-link>
                <router-link
                  to="/dashboard/finance/revenues"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/finance/revenues' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Revenues
                </router-link>
              </div>
            </div>
          </div>
        </div>

        <!-- Section: Network & Infrastructure -->
        <div class="pt-6 pb-2" v-if="!isOnSystemAdminRoute">
          <div class="px-3 py-2 text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2">
            <div class="w-1 h-4 bg-gradient-to-b from-indigo-500 to-violet-500 rounded-full"></div>
            Network & Infrastructure
          </div>

          <!-- Routers / Devices -->
          <div>
            <button
              @click="toggleMenu('routers')"
              class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
              :class="isActiveRouters ? 'bg-gray-800 text-white' : ''"
            >
              <span class="flex items-center gap-3">
                <Router class="w-5 h-5 flex-shrink-0" />
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
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/routers/mikrotik' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  MikroTik List
                </router-link>
                <router-link
                  to="/dashboard/routers/add"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/routers/add' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Add Router
                </router-link>
                <router-link
                  to="/dashboard/routers/api-status"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/routers/api-status' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  API Status
                </router-link>
                <router-link
                  to="/dashboard/routers/backup"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/routers/backup' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Backup Configs
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
                <LineChart class="w-5 h-5 flex-shrink-0" />
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
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/monitoring/connections' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Live Connections
                </router-link>
                <router-link
                  to="/dashboard/monitoring/traffic-graphs"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/monitoring/traffic-graphs' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Traffic Graphs
                </router-link>
                <router-link
                  to="/dashboard/monitoring/latency-tests"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/monitoring/latency-tests' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Latency Tests
                </router-link>
                <router-link
                  to="/dashboard/monitoring/session-logs"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/monitoring/session-logs' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Session Logs
                </router-link>
              </div>
            </div>
          </div>
        </div>

        <!-- Section: Analytics & Reports -->
        <div class="pt-6 pb-2">
          <div class="px-3 py-2 text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2">
            <div class="w-1 h-4 bg-gradient-to-b from-pink-500 to-rose-500 rounded-full"></div>
            Analytics & Reports
          </div>

          <!-- Reports -->
          <div>
            <button
              @click="toggleMenu('reports')"
              class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
              :class="isActiveReports ? 'bg-gray-800 text-white' : ''"
            >
              <span class="flex items-center gap-3">
                <TrendingUp class="w-5 h-5 flex-shrink-0" />
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
                  to="/dashboard/reports/revenue"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/reports/revenue' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Revenue Reports
                </router-link>
                <router-link
                  to="/dashboard/reports/users"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/reports/users' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  User Analytics
                </router-link>
                <router-link
                  to="/dashboard/reports/bandwidth-usage-summary"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/reports/bandwidth-usage-summary' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Bandwidth Usage
                </router-link>
              </div>
            </div>
          </div>
        </div>

        <!-- Section: Team Management -->
        <div class="pt-6 pb-2">
          <div class="px-3 py-2 text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2">
            <div class="w-1 h-4 bg-gradient-to-b from-cyan-500 to-sky-500 rounded-full"></div>
            Team Management
          </div>

          <!-- Admin Users -->
          <div>
            <button
              @click="toggleMenu('users')"
              class="w-full flex items-center justify-between py-3 px-3.5 rounded-lg hover:bg-gray-800/60 transition-all duration-200 group"
              :class="isActiveUsers ? 'bg-gray-800/80 text-white' : 'text-gray-300'"
            >
              <span class="flex items-center gap-3">
                <UserCircle class="w-5 h-5 flex-shrink-0 group-hover:scale-110 transition-transform duration-200" :class="isActiveUsers ? 'text-cyan-400' : ''" />
                <span class="text-sm font-semibold">Admin Users</span>
              </span>
              <ChevronDown
                class="w-4 h-4 transition-transform duration-200"
                :class="activeMenu === 'users' ? 'rotate-180 text-cyan-400' : ''"
              />
            </button>
            <div
              v-show="activeMenu === 'users'"
              class="overflow-hidden transition-all duration-200 ease-out"
              :class="activeMenu === 'users' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
            >
              <div class="ml-8 space-y-0.5 mt-1 border-l-2 border-gray-800/50 pl-3">
                <router-link
                  to="/dashboard/users/all"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/users/all' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  <span class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-gray-600 group-hover:bg-cyan-400 transition-colors" :class="route.path === '/dashboard/users/all' ? 'bg-cyan-400' : ''"></span>
                    All Admins
                  </span>
                </router-link>
                <router-link
                  to="/dashboard/users/create"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/users/create' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  <span class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-gray-600 group-hover:bg-cyan-400 transition-colors" :class="route.path === '/dashboard/users/create' ? 'bg-cyan-400' : ''"></span>
                    Create Admin
                  </span>
                </router-link>
                <router-link
                  to="/dashboard/users/roles"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/users/roles' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  <span class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-gray-600 group-hover:bg-cyan-400 transition-colors" :class="route.path === '/dashboard/users/roles' ? 'bg-cyan-400' : ''"></span>
                    Roles & Permissions
                  </span>
                </router-link>
                <router-link
                  to="/dashboard/users/online"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/users/online' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  <span class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-gray-600 group-hover:bg-cyan-400 transition-colors" :class="route.path === '/dashboard/users/online' ? 'bg-cyan-400' : ''"></span>
                    Online Users
                  </span>
                </router-link>
              </div>
            </div>
          </div>

          <!-- HR Management -->
          <div>
            <button
              @click="toggleMenu('hr')"
              class="w-full flex items-center justify-between py-3 px-3.5 rounded-lg hover:bg-gray-800/60 transition-all duration-200 group"
              :class="isActiveHR ? 'bg-gray-800/80 text-white' : 'text-gray-300'"
            >
              <span class="flex items-center gap-3">
                <Briefcase class="w-5 h-5 flex-shrink-0 group-hover:scale-110 transition-transform duration-200" :class="isActiveHR ? 'text-cyan-400' : ''" />
                <span class="text-sm font-semibold">HR Management</span>
              </span>
              <ChevronDown
                class="w-4 h-4 transition-transform duration-200"
                :class="activeMenu === 'hr' ? 'rotate-180 text-cyan-400' : ''"
              />
            </button>
            <div
              v-show="activeMenu === 'hr'"
              class="overflow-hidden transition-all duration-200 ease-out"
              :class="activeMenu === 'hr' ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'"
            >
              <div class="ml-8 space-y-0.5 mt-1 border-l-2 border-gray-800/50 pl-3">
                <router-link
                  to="/dashboard/hr/departments"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/hr/departments' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  <span class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-gray-600 group-hover:bg-cyan-400 transition-colors" :class="route.path === '/dashboard/hr/departments' ? 'bg-cyan-400' : ''"></span>
                    Departments
                  </span>
                </router-link>
                <router-link
                  to="/dashboard/hr/positions"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/hr/positions' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  <span class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-gray-600 group-hover:bg-cyan-400 transition-colors" :class="route.path === '/dashboard/hr/positions' ? 'bg-cyan-400' : ''"></span>
                    Positions
                  </span>
                </router-link>
                <router-link
                  to="/dashboard/hr/employees"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/hr/employees' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  <span class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-gray-600 group-hover:bg-cyan-400 transition-colors" :class="route.path === '/dashboard/hr/employees' ? 'bg-cyan-400' : ''"></span>
                    Employees
                  </span>
                </router-link>
              </div>
            </div>
          </div>
        </div>

        <!-- Section: Branding & Customization -->
        <div class="pt-6 pb-2">
          <div class="px-3 py-2 text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2">
            <div class="w-1 h-4 bg-gradient-to-b from-fuchsia-500 to-pink-500 rounded-full"></div>
            Branding & Customization
          </div>

          <!-- Hotspot Portal -->
          <router-link
            to="/dashboard/hotspot/login-page"
            class="w-full flex items-center gap-3 py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
            :class="route.path === '/dashboard/hotspot/login-page' ? 'bg-gray-800 text-white' : ''"
            @click="isMobile && $emit('close-sidebar')"
          >
            <Palette class="w-5 h-5 flex-shrink-0" />
            <span class="text-sm font-medium">Hotspot Portal</span>
          </router-link>
        </div>

        <!-- Section: Support & Help -->
        <div class="pt-6 pb-2">
          <div class="px-3 py-2 text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2">
            <div class="w-1 h-4 bg-gradient-to-b from-red-500 to-orange-500 rounded-full"></div>
            Support & Help
          </div>

          <!-- Support / Tickets -->
          <div>
            <button
              @click="toggleMenu('support')"
              class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
              :class="isActiveSupport ? 'bg-gray-800 text-white' : ''"
            >
              <span class="flex items-center gap-3">
                <LifeBuoy class="w-5 h-5 flex-shrink-0" />
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
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/support/create-ticket' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Create Ticket
                </router-link>
                <router-link
                  to="/dashboard/support/all-tickets"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/support/all-tickets' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  All Tickets
                </router-link>
                <router-link
                  to="/dashboard/support/categories"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/support/categories' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Categories
                </router-link>
              </div>
            </div>
          </div>
        </div>

        <!-- Section: Settings -->
        <div class="pt-6 pb-2">
          <div class="px-3 py-2 text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2">
            <div class="w-1 h-4 bg-gradient-to-b from-slate-500 to-gray-500 rounded-full"></div>
            Settings
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
                  to="/dashboard/settings/organization"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/settings/organization' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Organization Profile
                </router-link>
                <router-link
                  to="/dashboard/settings/profile"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/settings/profile' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  My Account
                </router-link>
                <router-link
                  to="/dashboard/settings/security"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/settings/security' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Security
                </router-link>
                <router-link
                  to="/dashboard/settings/notifications"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/settings/notifications' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Notifications
                </router-link>
              </div>
            </div>
          </div>
        </div>

        <!-- Admin Tools (System Admin Only) -->
        <div v-if="isSystemAdmin" class="pt-6 pb-2">
          <div class="px-3 py-2 text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2">
            <div class="w-1 h-4 bg-gradient-to-b from-red-600 to-rose-600 rounded-full"></div>
            System Administration
          </div>

          <div>
            <button
              @click="toggleMenu('adminTools')"
              class="w-full flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-800 transition-all duration-200"
              :class="isActiveAdminTools ? 'bg-gray-800 text-white' : ''"
            >
              <span class="flex items-center gap-3">
                <Shield class="w-5 h-5 flex-shrink-0" />
                <span class="text-sm font-medium">Admin Tools</span>
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
                  to="/dashboard/admin/system-health"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/admin/system-health' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  System Health
                </router-link>
                <router-link
                  to="/dashboard/admin/database-backup"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/admin/database-backup' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  Database Backup
                </router-link>
                <router-link
                  to="/dashboard/admin/system-updates"
                  class="block py-2.5 px-3 rounded-md hover:bg-gray-800/40 hover:text-white text-sm transition-all duration-150 group"
                  :class="route.path === '/dashboard/admin/system-updates' ? 'bg-gray-800/60 text-white font-semibold border-l-2 border-cyan-400 -ml-[14px] pl-[14px]' : 'text-gray-400'"
                  @click="isMobile && $emit('close-sidebar')"
                >
                  System Updates
                </router-link>
              </div>
            </div>
          </div>
        </div>
      </nav>
    </div>
    <!-- Sidebar Footer -->
    <div class="p-4 text-xs text-gray-600 border-t border-gray-800/50 bg-gray-950/50">
      <div class="font-semibold">© {{ new Date().getFullYear() }} TraidNet Solutions</div>
      <div class="mt-0.5 text-gray-700">All rights reserved</div>
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
  UserCircle,
  Radio,
  Cable,
  Receipt,
  Box,
  Router,
  LineChart,
  LifeBuoy,
  FileText,
  Briefcase,
  Building2,
  UserCog,
  TrendingUp,
  Wallet,
  Shield,
  Ticket,
  Palette,
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
    if (path.startsWith('/dashboard/users')) {
      activeMenu.value = 'users'
    } else if (path.startsWith('/dashboard/hotspot')) {
      if (path.includes('/vouchers') || path.includes('/voucher-templates')) {
        activeMenu.value = 'vouchers'
      } else {
        activeMenu.value = 'hotspot'
      }
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
    } else if (path.startsWith('/dashboard/hr')) {
      activeMenu.value = 'hr'
    } else if (path.startsWith('/dashboard/finance')) {
      activeMenu.value = 'finance'
    }
  },
  { immediate: true },
)

// Active section highlight
const isActiveUsers = computed(() => route.path.startsWith('/dashboard/users'))
const isActiveHotspot = computed(() => route.path.startsWith('/dashboard/hotspot') && !route.path.includes('/vouchers'))
const isActiveVouchers = computed(() => route.path.includes('/vouchers') || route.path.includes('/voucher-templates'))
const isActivePPPoE = computed(() => route.path.startsWith('/dashboard/pppoe'))
const isActiveBilling = computed(() => route.path.startsWith('/dashboard/billing'))
const isActivePackages = computed(() => route.path.startsWith('/dashboard/packages'))
const isActiveRouters = computed(() => route.path.startsWith('/dashboard/routers'))
const isActiveMonitoring = computed(() => route.path.startsWith('/dashboard/monitoring'))
const isActiveSupport = computed(() => route.path.startsWith('/dashboard/support'))
const isActiveReports = computed(() => route.path.startsWith('/dashboard/reports'))
const isActiveSettings = computed(() => route.path.startsWith('/dashboard/settings'))
const isActiveAdminTools = computed(() => route.path.startsWith('/dashboard/admin'))
const isActiveHR = computed(() => route.path.startsWith('/dashboard/hr'))
const isActiveFinance = computed(() => route.path.startsWith('/dashboard/finance'))

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
