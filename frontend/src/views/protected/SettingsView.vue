<template>
  <div class="protected-view">
    <Sidebar />

    <div class="main-content">
      <AppHeader @logout="handleLogout" />

      <div class="content">
        <h1>Settings</h1>

        <div class="settings-tabs">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            @click="activeTab = tab.id"
            :class="{ active: activeTab === tab.id }"
          >
            {{ tab.label }}
          </button>
        </div>

        <div class="settings-content">
          <div v-if="activeTab === 'profile'" class="profile-settings">
            <h2>Profile Settings</h2>
            <form @submit.prevent="saveProfile">
              <div class="form-group">
                <label>Full Name</label>
                <input type="text" v-model="profile.name" />
              </div>

              <div class="form-group">
                <label>Email</label>
                <input type="email" v-model="profile.email" />
              </div>

              <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" v-model="profile.phone" />
              </div>

              <button type="submit" class="save-btn">Save Changes</button>
            </form>
          </div>

          <div v-if="activeTab === 'security'" class="security-settings">
            <h2>Security Settings</h2>
            <form @submit.prevent="changePassword">
              <div class="form-group">
                <label>Current Password</label>
                <input type="password" v-model="password.current" />
              </div>

              <div class="form-group">
                <label>New Password</label>
                <input type="password" v-model="password.new" />
              </div>

              <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" v-model="password.confirm" />
              </div>

              <button type="submit" class="save-btn">Change Password</button>
            </form>
          </div>

          <div v-if="activeTab === 'system'" class="system-settings">
            <h2>System Preferences</h2>
            <div class="preference">
              <label>
                <input type="checkbox" v-model="preferences.darkMode" />
                Dark Mode
              </label>
            </div>

            <div class="preference">
              <label>
                <input type="checkbox" v-model="preferences.notifications" />
                Enable Notifications
              </label>
            </div>

            <div class="preference">
              <label>Language</label>
              <select v-model="preferences.language">
                <option value="en">English</option>
                <option value="sw">Swahili</option>
              </select>
            </div>

            <button class="save-btn" @click="savePreferences">Save Preferences</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import AppHeader from '@/components/AppHeader.vue'
import { useAuth } from '@/composables/useAuth'

const { logout, user } = useAuth()
const activeTab = ref('profile')

const tabs = [
  { id: 'profile', label: 'Profile' },
  { id: 'security', label: 'Security' },
  { id: 'system', label: 'System' },
]

// Mock data - replace with actual user data
const profile = ref({
  name: user.value?.name || '',
  email: user.value?.email || '',
  phone: '',
})

const password = ref({
  current: '',
  new: '',
  confirm: '',
})

const preferences = ref({
  darkMode: false,
  notifications: true,
  language: 'en',
})

const saveProfile = () => {
  alert('Profile changes saved!')
}

const changePassword = () => {
  alert('Password changed successfully!')
  password.value = { current: '', new: '', confirm: '' }
}

const savePreferences = () => {
  alert('Preferences saved!')
}

const handleLogout = () => {
  logout()
}
</script>

<style scoped>
.settings-tabs {
  display: flex;
  border-bottom: 1px solid #ddd;
  margin-bottom: 1.5rem;
}

.settings-tabs button {
  padding: 0.75rem 1.5rem;
  background: none;
  border: none;
  border-bottom: 3px solid transparent;
  cursor: pointer;
  font-weight: 500;
}

.settings-tabs button.active {
  border-bottom-color: #4a90e2;
  color: #4a90e2;
}

.form-group {
  margin-bottom: 1.5rem;
}

.form-group label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 500;
}

.form-group input,
.form-group select {
  width: 100%;
  max-width: 400px;
  padding: 0.75rem;
  border: 1px solid #ddd;
  border-radius: 4px;
}

.save-btn {
  padding: 0.75rem 1.5rem;
  background-color: #4a90e2;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.preference {
  margin-bottom: 1.5rem;
}

.preference label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

/* Shared protected view styles */
.protected-view {
  display: flex;
  min-height: 100vh;
}

.main-content {
  flex: 1;
  margin-left: 250px;
}

.content {
  padding: 20px;
}
</style>
