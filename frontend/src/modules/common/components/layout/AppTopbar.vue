<template>
  <header
    class="fixed top-0 left-0 right-0 h-14 sm:h-16 bg-white dark:bg-slate-900 dark:border-b dark:border-slate-700/60 shadow z-50 flex items-center justify-between px-2 sm:px-4 transition-colors duration-200"
  >
    <div class="flex items-center gap-2 sm:gap-3 min-w-0 flex-1">
      <button
        @click="$emit('toggle-sidebar')"
        class="p-2 rounded-md hover:bg-gray-100 dark:hover:bg-slate-800 active:bg-gray-200 transition-colors duration-150 flex-shrink-0"
      >
        <Menu class="w-5 h-5 sm:w-6 sm:h-6 text-gray-800 dark:text-slate-300" />
      </button>
      
      <!-- Company Name -->
      <h1 class="text-sm sm:text-xl font-bold text-gray-800 dark:text-slate-100 truncate">{{ tenantName }}</h1>
      
      <!-- Divider (hidden on mobile) -->
      <div class="hidden sm:block h-6 w-px bg-gray-300 dark:bg-slate-700 flex-shrink-0"></div>
      
      <!-- Page Icon and Breadcrumbs (hidden on mobile) -->
      <div class="hidden sm:flex items-center gap-3 min-w-0">
        <component 
          v-if="pageIcon" 
          :is="pageIcon" 
          class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0"
        />
        <nav class="flex items-center gap-2 text-sm min-w-0">
          <router-link 
            v-for="(crumb, index) in breadcrumbs" 
            :key="index"
            :to="crumb.to || '#'"
            class="hover:text-blue-600 dark:hover:text-blue-400 transition-colors whitespace-nowrap"
            :class="[
              index === breadcrumbs.length - 1 
                ? 'text-gray-900 dark:text-slate-100 font-semibold truncate' 
                : 'text-gray-600 dark:text-slate-400'
            ]"
          >
            {{ crumb.label }}
            <span v-if="index < breadcrumbs.length - 1" class="mx-2 text-gray-400">/</span>
          </router-link>
        </nav>
      </div>
    </div>

    <!-- Right side: User Menu -->
    <div class="flex items-center gap-2 sm:gap-3 flex-shrink-0" v-if="user">
      <div class="relative">
        <button
          @click="toggleUserMenu"
          class="flex items-center gap-2 p-2 rounded-md hover:bg-gray-100 dark:hover:bg-slate-800 active:bg-gray-200 transition-colors duration-150"
        >
          <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center text-white font-semibold text-xs sm:text-sm">
            {{ userInitials }}
          </div>
          <div class="hidden md:block text-left">
            <div class="text-sm font-medium text-gray-800 dark:text-slate-200">{{ user.name || user.username || 'User' }}</div>
            <div class="text-xs text-gray-500 dark:text-slate-400">{{ user.email || 'No email' }}</div>
          </div>
          <ChevronDown class="w-4 h-4 text-gray-500 dark:text-slate-400 hidden sm:block" :class="{ 'rotate-180': isUserMenuOpen }" />
        </button>

        <!-- Dropdown Menu -->
        <div
          v-if="isUserMenuOpen"
          class="absolute right-0 mt-2 w-48 bg-white dark:bg-slate-800 rounded-md shadow-lg border border-gray-200 dark:border-slate-700 z-50"
          @click.stop
        >
          <div class="py-1">
            <div class="px-4 py-2 border-b border-gray-100 dark:border-slate-700">
              <div class="text-sm font-medium text-gray-800 dark:text-slate-200">{{ user.name || user.username || 'User' }}</div>
              <div class="text-xs text-gray-500 dark:text-slate-400">{{ user.email || 'No email' }}</div>
              <div class="text-xs text-gray-400 dark:text-slate-500 mt-1">Role: {{ user.role || 'User' }}</div>
            </div>

            <button
              @click="darkMode.toggle(); closeUserMenu()"
              class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center gap-2"
            >
              <Sun v-if="darkMode.isDark" class="w-4 h-4 text-amber-400" />
              <Moon v-else class="w-4 h-4 text-slate-500" />
              {{ darkMode.isDark ? 'Light Mode' : 'Dark Mode' }}
            </button>

            <button
              @click="viewProfile"
              class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center gap-2"
            >
              <User class="w-4 h-4" />
              Profile
            </button>

            <button
              @click="handleLogout"
              class="w-full text-left px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2"
            >
              <LogOut class="w-4 h-4" />
              Logout
            </button>
          </div>
        </div>
      </div>
    </div>
  </header>

  <!-- Overlay to close menu when clicking outside -->
  <div
    v-if="isUserMenuOpen"
    class="fixed inset-0 z-40"
    @click="closeUserMenu"
  ></div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { Menu, ChevronDown, User, LogOut, Wifi, Users, Package, Activity, Settings, Shield, Sun, Moon } from 'lucide-vue-next'
import { useAuthStore } from '@/stores/auth'
import { useDarkModeStore } from '@/stores/darkMode'

// Props
const props = defineProps({
  pageIcon: {
    type: Object,
    default: null
  },
  breadcrumbs: {
    type: Array,
    default: () => []
  }
})

const authStore = useAuthStore()
const darkMode = useDarkModeStore()
const user = computed(() => authStore.user)
const router = useRouter()
const route = useRoute()

// Auto-generate breadcrumbs if not provided
const breadcrumbs = computed(() => {
  if (props.breadcrumbs.length > 0) return props.breadcrumbs
  
  // Auto-generate from route
  const paths = route.path.split('/').filter(p => p)
  const crumbs = [{ label: 'Dashboard', to: '/dashboard' }]
  
  let currentPath = ''
  paths.forEach((path, index) => {
    if (index === 0) return // Skip 'dashboard'
    currentPath += `/${path}`
    const label = path.charAt(0).toUpperCase() + path.slice(1).replace(/-/g, ' ')
    crumbs.push({
      label,
      to: index === paths.length - 1 ? undefined : `/dashboard${currentPath}`
    })
  })
  
  return crumbs
})

// Auto-detect page icon from route
const pageIcon = computed(() => {
  if (props.pageIcon) return props.pageIcon
  
  const path = route.path
  if (path.includes('/hotspot')) return Wifi
  if (path.includes('/users')) return Users
  if (path.includes('/packages')) return Package
  if (path.includes('/monitoring')) return Activity
  if (path.includes('/settings')) return Settings
  if (path.includes('/admin')) return Shield
  
  return null
})

// User menu state
const isUserMenuOpen = ref(false)

// Computed properties
const tenantName = computed(() => {
  if (!user.value) return 'TraidNet Solutions'
  if (user.value.tenant?.name) return user.value.tenant.name
  if (user.value.role === 'system_admin') return 'System Administration'
  return 'TraidNet Solutions'
})

const userInitials = computed(() => {
  if (!user.value) return '?'
  if (!user.value.name) return user.value.username?.charAt(0).toUpperCase() || 'U'
  return user.value.name
    .split(' ')
    .map(word => word.charAt(0).toUpperCase())
    .join('')
    .slice(0, 2)
})


// Methods
const toggleUserMenu = () => {
  isUserMenuOpen.value = !isUserMenuOpen.value
}

const closeUserMenu = () => {
  isUserMenuOpen.value = false
}

const viewProfile = () => {
  closeUserMenu()
  // Navigate to profile page (you can create this route later)
  router.push('/dashboard/settings/general')
}

const handleLogout = async () => {
  closeUserMenu()
  await authStore.logout()
  await router.push('/login')
}

// Close menu on escape key
const handleKeydown = (event) => {
  if (event.key === 'Escape') {
    closeUserMenu()
  }
}

// Lifecycle
onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
})

// Emit toggle-sidebar event
defineEmits(['toggle-sidebar'])
</script>
