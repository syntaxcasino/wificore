# Notification System Implementation Guide

**Date**: December 2, 2025  
**Status**: ✅ **Priority 1 Complete** | ⏳ **Priority 2 & 3 Pending**

---

## ✅ **Completed (Priority 1)**

### **1. NotificationToast in App.vue** ✅
- Global toast component added to `App.vue`
- Available throughout the application
- No additional setup needed in child components

### **2. TenantRegistrationView.vue** ✅
- LoadingProgress component integrated
- Progress tracking with 5 stages:
  - 20%: Validating information
  - 40%: Creating tenant workspace
  - 60%: Setting up database schema
  - 80%: Configuring RADIUS authentication
  - 90%: Finalizing setup
  - 100%: Success!
- Success toast on completion
- Error toast on failure
- Smooth redirect after success

---

## 📋 **Priority 2: Global WebSocket Listeners** (1-2 hours)

### **Implementation Steps**

#### **Step 1: Create WebSocket Service**

Create `frontend/src/services/websocket.js`:

```javascript
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useNotificationStore } from '@/stores/notifications'

window.Pusher = Pusher

export const initializeWebSocket = (tenantId, userId) => {
  const notificationStore = useNotificationStore()
  
  const echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    wsHost: import.meta.env.VITE_PUSHER_HOST || 'localhost',
    wsPort: import.meta.env.VITE_PUSHER_PORT || 6001,
    wssPort: import.meta.env.VITE_PUSHER_PORT || 6001,
    forceTLS: false,
    encrypted: true,
    disableStats: true,
    enabledTransports: ['ws', 'wss'],
  })

  // Listen to tenant channel
  if (tenantId) {
    echo.channel(`tenant.${tenantId}`)
      .listen('TenantCreated', (event) => {
        notificationStore.fromWebSocketEvent('TenantCreated', event)
      })
      .listen('UserCreated', (event) => {
        notificationStore.fromWebSocketEvent('UserCreated', event)
      })
      .listen('UserUpdated', (event) => {
        notificationStore.fromWebSocketEvent('UserUpdated', event)
      })
      .listen('UserDeleted', (event) => {
        notificationStore.fromWebSocketEvent('UserDeleted', event)
      })
      .listen('PaymentCompleted', (event) => {
        notificationStore.fromWebSocketEvent('PaymentCompleted', event)
      })
  }

  // Listen to user private channel
  if (userId) {
    echo.private(`user.${userId}`)
      .listen('PasswordChanged', (event) => {
        notificationStore.fromWebSocketEvent('PasswordChanged', event)
      })
      .listen('AccountSuspended', (event) => {
        notificationStore.fromWebSocketEvent('AccountSuspended', event)
      })
  }

  return echo
}
```

#### **Step 2: Initialize in Auth Store**

Update `frontend/src/stores/auth.js`:

```javascript
import { initializeWebSocket } from '@/services/websocket'

export const useAuthStore = defineStore('auth', () => {
  // ... existing code ...
  
  let echo = null

  const login = async (credentials) => {
    // ... existing login code ...
    
    // Initialize WebSocket after successful login
    if (user.value && tenant.value) {
      echo = initializeWebSocket(tenant.value.id, user.value.id)
    }
    
    return result
  }

  const logout = async () => {
    // Disconnect WebSocket
    if (echo) {
      echo.disconnect()
      echo = null
    }
    
    // ... existing logout code ...
  }

  return {
    // ... existing returns ...
  }
})
```

---

## 📋 **Priority 3: Add to Forms** (Optional)

### **User Management Forms**

#### **Create User Form**

```vue
<script setup>
import { ref } from 'vue'
import { useNotificationStore } from '@/stores/notifications'
import LoadingProgress from '@/components/LoadingProgress.vue'

const notificationStore = useNotificationStore()
const loading = ref(false)
const loadingState = ref({
  title: '🔄 Creating user...',
  message: 'Please wait',
  progress: 0,
  statusMessage: ''
})

const createUser = async (userData) => {
  loading.value = true
  loadingState.value = {
    title: '🔄 Creating user...',
    message: 'Adding user to system',
    progress: 30,
    statusMessage: 'Validating data...'
  }
  
  try {
    loadingState.value.progress = 60
    loadingState.value.statusMessage = 'Creating user account...'
    
    const response = await axios.post('/users', userData)
    
    loadingState.value.progress = 100
    loadingState.value.statusMessage = 'User created!'
    
    notificationStore.success(
      'User Created',
      `${userData.name} has been added successfully.`,
      5000
    )
    
    // Close form or refresh list
  } catch (err) {
    notificationStore.error(
      'Failed to Create User',
      err.response?.data?.message || 'An error occurred.',
      7000
    )
  } finally {
    loading.value = false
  }
}
</script>
```

#### **Update User Form**

```vue
<script setup>
const updateUser = async (userId, userData) => {
  loading.value = true
  
  try {
    await axios.put(`/users/${userId}`, userData)
    
    notificationStore.success(
      'User Updated',
      `${userData.name}'s information has been updated.`,
      5000
    )
  } catch (err) {
    notificationStore.error(
      'Failed to Update User',
      err.response?.data?.message || 'An error occurred.',
      7000
    )
  } finally {
    loading.value = false
  }
}
</script>
```

#### **Delete User**

```vue
<script setup>
const deleteUser = async (userId, userName) => {
  // Show confirmation dialog first
  if (!confirm(`Are you sure you want to delete ${userName}?`)) {
    return
  }
  
  loading.value = true
  
  try {
    await axios.delete(`/users/${userId}`)
    
    notificationStore.warning(
      'User Deleted',
      `${userName} has been removed from the system.`,
      6000
    )
  } catch (err) {
    notificationStore.error(
      'Failed to Delete User',
      err.response?.data?.message || 'An error occurred.',
      7000
    )
  } finally {
    loading.value = false
  }
}
</script>
```

---

### **Password Change Form**

```vue
<template>
  <LoadingProgress
    :show="loading"
    title="🔒 Changing Password..."
    message="Updating your credentials"
    :progress="progress"
    statusMessage="Please wait..."
  />

  <form @submit.prevent="changePassword">
    <!-- Password fields -->
  </form>
</template>

<script setup>
import { ref } from 'vue'
import { useNotificationStore } from '@/stores/notifications'
import LoadingProgress from '@/components/LoadingProgress.vue'

const notificationStore = useNotificationStore()
const loading = ref(false)
const progress = ref(0)

const changePassword = async () => {
  loading.value = true
  progress.value = 0
  
  try {
    progress.value = 30
    await axios.post('/change-password', {
      old_password: form.value.oldPassword,
      new_password: form.value.newPassword
    })
    
    progress.value = 100
    
    notificationStore.success(
      'Password Changed',
      'Your password has been updated successfully.',
      5000
    )
    
    // Clear form
    form.value = { oldPassword: '', newPassword: '', confirmPassword: '' }
  } catch (err) {
    notificationStore.error(
      'Password Change Failed',
      err.response?.data?.message || 'Please check your current password.',
      7000
    )
  } finally {
    loading.value = false
  }
}
</script>
```

---

### **System Admin Views**

#### **Tenant Management**

```vue
<script setup>
const approveTenant = async (tenantId, tenantName) => {
  loading.value = true
  
  try {
    await axios.post(`/admin/tenants/${tenantId}/approve`)
    
    notificationStore.success(
      'Tenant Approved',
      `${tenantName} has been approved and activated.`,
      5000
    )
  } catch (err) {
    notificationStore.error(
      'Failed to Approve Tenant',
      err.response?.data?.message || 'An error occurred.',
      7000
    )
  } finally {
    loading.value = false
  }
}

const suspendTenant = async (tenantId, tenantName) => {
  if (!confirm(`Are you sure you want to suspend ${tenantName}?`)) {
    return
  }
  
  loading.value = true
  
  try {
    await axios.post(`/admin/tenants/${tenantId}/suspend`)
    
    notificationStore.warning(
      'Tenant Suspended',
      `${tenantName} has been suspended.`,
      6000
    )
  } catch (err) {
    notificationStore.error(
      'Failed to Suspend Tenant',
      err.response?.data?.message || 'An error occurred.',
      7000
    )
  } finally {
    loading.value = false
  }
}
</script>
```

---

## 🎯 **Quick Reference**

### **Import Statements**

```javascript
import { useNotificationStore } from '@/stores/notifications'
import LoadingProgress from '@/components/LoadingProgress.vue'
```

### **Basic Setup**

```javascript
const notificationStore = useNotificationStore()
const loading = ref(false)
const loadingState = ref({
  title: '🔄 Processing...',
  message: 'Please wait',
  progress: 0,
  statusMessage: ''
})
```

### **Show Notifications**

```javascript
// Success
notificationStore.success('Title', 'Message', 5000)

// Error
notificationStore.error('Title', 'Message', 7000)

// Warning
notificationStore.warning('Title', 'Message', 6000)

// Info
notificationStore.info('Title', 'Message', 5000)
```

### **Update Progress**

```javascript
loadingState.value.progress = 50
loadingState.value.statusMessage = 'Processing...'
```

---

## 📊 **Implementation Checklist**

### **Priority 1** 🔴 ✅ COMPLETE
- [x] Add NotificationToast to App.vue
- [x] Update TenantRegistrationView.vue
- [x] Create LoadingProgress component
- [x] Add documentation

### **Priority 2** 🟡 PENDING
- [ ] Create WebSocket service
- [ ] Initialize WebSocket in auth store
- [ ] Add global event listeners
- [ ] Test real-time notifications

### **Priority 3** 🟢 OPTIONAL
- [ ] Add to user management forms
- [ ] Add to password change form
- [ ] Add to system admin views
- [ ] Add to tenant management views
- [ ] Add to settings pages
- [ ] Add to profile pages

---

## 🎉 **Summary**

**Completed**:
- ✅ Global toast notification system
- ✅ Loading progress component
- ✅ Tenant registration with progress tracking
- ✅ Success/error state handling
- ✅ Comprehensive documentation

**Next Steps**:
1. Implement WebSocket integration (Priority 2)
2. Add to remaining forms (Priority 3)
3. Test across all user flows

**Status**: Priority 1 complete! Ready for Priority 2 implementation. 🚀
