# Notification & Toast System

**Date**: December 2, 2025  
**Status**: ✅ **IMPLEMENTED**

---

## 🎯 **Overview**

The notification system provides a unified way to display toast notifications, loading progress, and real-time event updates across the application.

---

## 📦 **Components**

### **1. NotificationToast** (`@/components/NotificationToast.vue`)

Global toast notification component that displays success, error, warning, and info messages.

**Features**:
- ✅ 4 notification types (success, error, warning, info)
- ✅ Auto-dismiss with configurable duration
- ✅ Click to dismiss
- ✅ Smooth animations
- ✅ Stacked notifications
- ✅ Responsive design

**Usage**:
```vue
<template>
  <NotificationToast />
</template>

<script setup>
import NotificationToast from '@/components/NotificationToast.vue'
</script>
```

---

### **2. LoadingProgress** (`@/components/LoadingProgress.vue`)

Full-screen loading overlay with progress bar and status messages.

**Features**:
- ✅ Progress bar (0-100%)
- ✅ Dynamic status messages
- ✅ Animated spinner
- ✅ Backdrop blur effect
- ✅ Customizable title and message

**Usage**:
```vue
<template>
  <LoadingProgress
    :show="loading"
    :title="loadingState.title"
    :message="loadingState.message"
    :progress="loadingState.progress"
    :statusMessage="loadingState.statusMessage"
  />
</template>

<script setup>
import { ref } from 'vue'
import LoadingProgress from '@/components/LoadingProgress.vue'

const loading = ref(false)
const loadingState = ref({
  title: '🔄 Processing...',
  message: 'Please wait',
  progress: 0,
  statusMessage: ''
})
</script>
```

---

## 🏪 **Notification Store**

**Location**: `@/stores/notifications.js`

### **Methods**

#### **add(notification)**
Add a custom notification.

```javascript
notificationStore.add({
  type: 'success', // success, error, warning, info
  title: 'Success!',
  message: 'Operation completed',
  duration: 5000 // milliseconds
})
```

#### **success(title, message, duration)**
Show success notification.

```javascript
notificationStore.success(
  'Registration Successful! 🎉',
  'Your account has been created.',
  5000
)
```

#### **error(title, message, duration)**
Show error notification.

```javascript
notificationStore.error(
  'Registration Failed',
  'Please check your information and try again.',
  7000
)
```

#### **warning(title, message, duration)**
Show warning notification.

```javascript
notificationStore.warning(
  'Session Expiring',
  'Your session will expire in 5 minutes.',
  6000
)
```

#### **info(title, message, duration)**
Show info notification.

```javascript
notificationStore.info(
  'New Feature',
  'Check out our new dashboard!',
  5000
)
```

#### **remove(id)**
Remove a specific notification.

```javascript
notificationStore.remove(notificationId)
```

#### **clear()**
Clear all notifications.

```javascript
notificationStore.clear()
```

#### **fromWebSocketEvent(eventName, data)**
Create notification from WebSocket event.

```javascript
notificationStore.fromWebSocketEvent('TenantCreated', {
  tenant: { name: 'Acme Corp' }
})
```

---

## 🎨 **Implementation Examples**

### **Example 1: Tenant Registration with Progress**

```vue
<template>
  <LoadingProgress
    :show="loading"
    :title="loadingState.title"
    :message="loadingState.message"
    :progress="loadingState.progress"
    :statusMessage="loadingState.statusMessage"
  />

  <form @submit.prevent="handleSubmit">
    <!-- Form fields -->
  </form>
</template>

<script setup>
import { ref } from 'vue'
import { useNotificationStore } from '@/stores/notifications'
import LoadingProgress from '@/components/LoadingProgress.vue'

const notificationStore = useNotificationStore()
const loading = ref(false)

const loadingState = ref({
  title: '🔄 Creating your account...',
  message: 'Setting up your workspace...',
  progress: 0,
  statusMessage: ''
})

const handleSubmit = async () => {
  loading.value = true
  
  // Reset loading state
  loadingState.value = {
    title: '🔄 Creating your account...',
    message: 'Setting up your workspace...',
    progress: 0,
    statusMessage: 'Initializing...'
  }
  
  try {
    // Simulate progress stages
    const progressStages = [
      { progress: 20, message: 'Validating information...' },
      { progress: 40, message: 'Creating workspace...' },
      { progress: 60, message: 'Setting up database...' },
      { progress: 80, message: 'Configuring services...' },
      { progress: 90, message: 'Finalizing...' }
    ]
    
    // Start progress simulation
    let currentStage = 0
    const progressInterval = setInterval(() => {
      if (currentStage < progressStages.length) {
        loadingState.value.progress = progressStages[currentStage].progress
        loadingState.value.statusMessage = progressStages[currentStage].message
        currentStage++
      }
    }, 800)
    
    // Make API call
    const response = await axios.post('/api/register/tenant', formData)
    
    // Clear progress interval
    clearInterval(progressInterval)
    
    if (response.data.success) {
      // Complete progress
      loadingState.value.progress = 100
      loadingState.value.title = '✅ Success!'
      loadingState.value.message = 'Your workspace is ready'
      loadingState.value.statusMessage = 'Redirecting...'
      
      // Show success toast
      notificationStore.success(
        'Registration Successful! 🎉',
        'Your account has been created.',
        8000
      )
      
      // Wait before redirect
      await new Promise(resolve => setTimeout(resolve, 1500))
      
      // Redirect
      router.push('/login')
    }
  } catch (err) {
    // Show error toast
    notificationStore.error(
      'Registration Failed',
      err.response?.data?.message || 'Please try again.',
      7000
    )
    
    loading.value = false
  }
}
</script>
```

---

### **Example 2: User Management with Toasts**

```vue
<script setup>
import { useNotificationStore } from '@/stores/notifications'

const notificationStore = useNotificationStore()

const createUser = async (userData) => {
  try {
    const response = await axios.post('/api/users', userData)
    
    // Show success toast
    notificationStore.success(
      'User Created',
      `${userData.name} has been added successfully.`,
      5000
    )
    
    return response.data
  } catch (err) {
    // Show error toast
    notificationStore.error(
      'Failed to Create User',
      err.response?.data?.message || 'An error occurred.',
      7000
    )
    
    throw err
  }
}

const deleteUser = async (userId, userName) => {
  try {
    await axios.delete(`/api/users/${userId}`)
    
    // Show warning toast
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
  }
}
</script>
```

---

### **Example 3: Password Change with Feedback**

```vue
<script setup>
import { ref } from 'vue'
import { useNotificationStore } from '@/stores/notifications'

const notificationStore = useNotificationStore()
const loading = ref(false)

const changePassword = async (oldPassword, newPassword) => {
  loading.value = true
  
  try {
    await axios.post('/api/change-password', {
      old_password: oldPassword,
      new_password: newPassword
    })
    
    // Show success toast
    notificationStore.success(
      'Password Changed',
      'Your password has been updated successfully.',
      5000
    )
    
    // Clear form
    form.value = { old_password: '', new_password: '', confirm_password: '' }
  } catch (err) {
    // Show error toast
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

## 🎭 **Notification Types**

### **Success** (Green)
- ✅ Account created
- ✅ Data saved
- ✅ Operation completed
- ✅ Payment successful

### **Error** (Red)
- ❌ Validation failed
- ❌ Server error
- ❌ Authentication failed
- ❌ Operation failed

### **Warning** (Orange)
- ⚠️ Session expiring
- ⚠️ Data will be deleted
- ⚠️ Unsaved changes
- ⚠️ Quota exceeded

### **Info** (Blue)
- ℹ️ New feature available
- ℹ️ System update
- ℹ️ Maintenance scheduled
- ℹ️ Tip or suggestion

---

## 🔔 **WebSocket Integration**

The notification store can automatically create notifications from WebSocket events:

```javascript
// In your WebSocket listener
echo.channel('tenant.123')
  .listen('TenantCreated', (event) => {
    notificationStore.fromWebSocketEvent('TenantCreated', event)
  })
  .listen('UserCreated', (event) => {
    notificationStore.fromWebSocketEvent('UserCreated', event)
  })
```

**Supported Events**:
- `TenantCreated`
- `UserCreated`
- `UserUpdated`
- `UserDeleted`
- `PasswordChanged`
- `PaymentCompleted`
- `HotspotUserCreated`
- `AccountSuspended`

---

## 📱 **Responsive Design**

Both components are fully responsive:

**Desktop**:
- Notifications appear in top-right corner
- Max width: 24rem
- Stacked vertically

**Mobile**:
- Notifications span full width (with margins)
- Optimized touch targets
- Smooth animations

---

## 🎨 **Customization**

### **Notification Duration**

```javascript
// Short (3 seconds)
notificationStore.info('Quick tip', 'Message', 3000)

// Medium (5 seconds) - default
notificationStore.success('Success', 'Message', 5000)

// Long (7 seconds)
notificationStore.error('Error', 'Message', 7000)

// Persistent (no auto-dismiss)
notificationStore.add({
  type: 'warning',
  title: 'Important',
  message: 'Click to dismiss',
  duration: 0 // Won't auto-dismiss
})
```

### **Custom Styling**

Notifications use Tailwind CSS and can be customized in `NotificationToast.vue`:

```css
.notification-success {
  border-left-color: #10b981; /* Green */
}

.notification-error {
  border-left-color: #ef4444; /* Red */
}

.notification-warning {
  border-left-color: #f59e0b; /* Orange */
}

.notification-info {
  border-left-color: #3b82f6; /* Blue */
}
```

---

## 📋 **Best Practices**

### **1. Use Appropriate Types**
- Success: Completed actions
- Error: Failed operations
- Warning: Cautionary messages
- Info: Informational updates

### **2. Keep Messages Concise**
```javascript
// ✅ Good
notificationStore.success('User Created', 'John Doe added successfully.')

// ❌ Too long
notificationStore.success('User Created', 'The user John Doe with email john@example.com has been successfully added to the system and will receive a verification email shortly.')
```

### **3. Provide Context**
```javascript
// ✅ Good
notificationStore.error('Failed to Save', 'Network connection lost. Please try again.')

// ❌ Too vague
notificationStore.error('Error', 'Something went wrong.')
```

### **4. Use Progress for Long Operations**
```javascript
// ✅ Good - Show progress for operations > 2 seconds
const loading = ref(true)
const loadingState = ref({ progress: 0, statusMessage: 'Processing...' })

// ❌ Bad - No feedback for long operations
await longRunningOperation()
```

---

## 🧪 **Testing**

### **Manual Testing**

```vue
<template>
  <div>
    <button @click="testSuccess">Test Success</button>
    <button @click="testError">Test Error</button>
    <button @click="testWarning">Test Warning</button>
    <button @click="testInfo">Test Info</button>
    <button @click="testProgress">Test Progress</button>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useNotificationStore } from '@/stores/notifications'

const notificationStore = useNotificationStore()
const loading = ref(false)
const loadingState = ref({ title: '', message: '', progress: 0, statusMessage: '' })

const testSuccess = () => {
  notificationStore.success('Success!', 'This is a success message.')
}

const testError = () => {
  notificationStore.error('Error!', 'This is an error message.')
}

const testWarning = () => {
  notificationStore.warning('Warning!', 'This is a warning message.')
}

const testInfo = () => {
  notificationStore.info('Info!', 'This is an info message.')
}

const testProgress = async () => {
  loading.value = true
  loadingState.value = {
    title: '🔄 Testing Progress...',
    message: 'Simulating long operation',
    progress: 0,
    statusMessage: 'Starting...'
  }
  
  for (let i = 0; i <= 100; i += 10) {
    loadingState.value.progress = i
    loadingState.value.statusMessage = `Processing... ${i}%`
    await new Promise(resolve => setTimeout(resolve, 300))
  }
  
  loading.value = false
  notificationStore.success('Complete!', 'Progress test finished.')
}
</script>
```

---

## 🎉 **Summary**

✅ **NotificationToast** - Global toast notifications  
✅ **LoadingProgress** - Full-screen progress overlay  
✅ **Notification Store** - Centralized state management  
✅ **WebSocket Integration** - Real-time event notifications  
✅ **Responsive Design** - Works on all devices  
✅ **Customizable** - Easy to extend and style  

**Status**: Ready to use across the application! 🚀
