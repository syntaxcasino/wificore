<template>
  <div class="sidebar">
    <!-- Logo and other elements -->
    <nav class="menu">
      <!-- ... other menu items ... -->

      <div class="menu-section">
        <div class="menu-header" @click="toggleWiFiMenu">
          <i class="icon">📶</i>
          <span>WiFi Hotspot</span>
          <i class="arrow" :class="{ rotated: wifiMenuOpen }">›</i>
        </div>

        <div class="submenu" v-show="wifiMenuOpen">
          <router-link to="/hotspot/config" class="submenu-item">
            <i class="icon">⚙️</i>
            <span>Configuration</span>
          </router-link>

          <router-link to="/hotspot/users" class="submenu-item">
            <i class="icon">👥</i>
            <span>User Management</span>
          </router-link>

          <router-link to="/hotspot/vouchers" class="submenu-item">
            <i class="icon">🎫</i>
            <span>Voucher System</span>
          </router-link>

          <router-link to="/hotspot/bandwidth" class="submenu-item">
            <i class="icon">🚀</i>
            <span>Bandwidth Control</span>
          </router-link>
        </div>
      </div>

      <!-- ... other menu items ... -->
    </nav>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const wifiMenuOpen = ref(true)
const toggleWiFiMenu = () => {
  wifiMenuOpen.value = !wifiMenuOpen.value
}
</script>
