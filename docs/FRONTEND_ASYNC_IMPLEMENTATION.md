# Frontend Async Implementation Guide

## 🎯 **Overview**

This guide shows how to update the frontend to handle async (202 Accepted) responses and WebSocket events from the event-based backend.

---

## ✅ **Files Created**

### **1. Composables**
- ✅ `src/composables/useAsyncOperation.js` - Handle async operations with WebSocket
- ✅ `src/composables/useWebSocketEvents.js` - Easy WebSocket event subscription

### **2. Components**
- ✅ `src/components/AsyncOperationStatus.vue` - Visual status indicator

---

## 📝 **How to Use**

### **Example 1: Tenant Registration**

```vue
<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import { useAsyncOperation } from '@/composables/useAsyncOperation'
import AsyncOperationStatus from '@/components/AsyncOperationStatus.vue'

const router = useRouter()
const form = ref({
  tenant_name: '',
  tenant_slug: '',
  // ... other fields
})

// Setup async operation handler
const {
  isProcessing,
  isComplete,
  hasError,
  errorMessage,
  progress,
  execute
} = useAsyncOperation({
  channel: 'tenants', // Listen on 'tenants' channel
  event: 'TenantCreated', // Listen for 'TenantCreated' event
  timeout: 60000, // 60 seconds
  onSuccess: (data) => {
    console.log('Tenant created!', data)
    // Redirect to login after 2 seconds
    setTimeout(() => {
      router.push('/login')
    }, 2000)
  },
  onError: (error) => {
    console.error('Registration failed:', error)
  }
})

const handleSubmit = async () => {
  await execute(async () => {
    return await axios.post('/register', form.value)
  })
}
</script>

<template>
  <div>
    <!-- Show async status -->
    <AsyncOperationStatus
      :is-processing="isProcessing"
      :is-complete="isComplete"
      :has-error="hasError"
      :error-message="errorMessage"
      :progress="progress"
      processing-message="Creating your account..."
      processing-description="Setting up your workspace and admin account. This may take a few moments."
      success-message="Account created successfully!"
      success-description="Redirecting to login page..."
    />

    <!-- Registration form -->
    <form @submit.prevent="handleSubmit">
      <!-- ... form fields ... -->
      
      <button 
        type="submit" 
        :disabled="isProcessing"
        class="btn-primary"
      >
        {{ isProcessing ? 'Creating Account...' : 'Create Account' }}
      </button>
    </form>
  </div>
</template>
```

---

### **Example 2: User Creation**

```vue
<script setup>
import { ref } from 'vue'
import axios from 'axios'
import { useAsyncOperation } from '@/composables/useAsyncOperation'
import { useWebSocketEvents } from '@/composables/useWebSocketEvents'
import AsyncOperationStatus from '@/components/AsyncOperationStatus.vue'

const form = ref({
  name: '',
  username: '',
  email: '',
  password: ''
})

// Setup WebSocket listeners for tenant events
const { listenToTenantEvents } = useWebSocketEvents()
const tenantId = localStorage.getItem('tenantId')

// Listen to user events
listenToTenantEvents(tenantId, [
  {
    channel: 'users',
    event: 'UserCreated',
    callback: (data) => {
      console.log('User created:', data)
      // Refresh user list or show notification
    }
  },
  {
    channel: 'users',
    event: 'UserUpdated',
    callback: (data) => {
      console.log('User updated:', data)
    }
  }
])

// Setup async operation
const {
  isProcessing,
  isComplete,
  hasError,
  errorMessage,
  progress,
  execute,
  reset
} = useAsyncOperation({
  channel: `tenant.${tenantId}.users`,
  event: 'UserCreated',
  timeout: 30000,
  onSuccess: (data) => {
    // User created successfully
    form.value = { name: '', username: '', email: '', password: '' }
    // Auto-close after 3 seconds
    setTimeout(reset, 3000)
  }
})

const createUser = async () => {
  await execute(async () => {
    return await axios.post('/admin/users', form.value)
  })
}
</script>

<template>
  <div>
    <AsyncOperationStatus
      :is-processing="isProcessing"
      :is-complete="isComplete"
      :has-error="hasError"
      :error-message="errorMessage"
      :progress="progress"
      processing-message="Creating user..."
      success-message="User created successfully!"
      @close="reset"
      @retry="createUser"
    />

    <form @submit.prevent="createUser">
      <!-- ... form fields ... -->
    </form>
  </div>
</template>
```

---

### **Example 3: Password Change**

```vue
<script setup>
import { ref } from 'vue'
import axios from 'axios'
import { useAsyncOperation } from '@/composables/useAsyncOperation'
import { useWebSocketEvents } from '@/composables/useWebSocketEvents'

const form = ref({
  current_password: '',
  new_password: '',
  new_password_confirmation: ''
})

// Listen to private user channel
const { listenToUserEvents } = useWebSocketEvents()
const userId = JSON.parse(localStorage.getItem('user')).id

listenToUserEvents(userId, [
  {
    event: 'PasswordChanged',
    callback: (data) => {
      console.log('Password changed successfully!')
      // Show success notification
    }
  }
])

const {
  isProcessing,
  isComplete,
  hasError,
  errorMessage,
  execute
} = useAsyncOperation({
  channel: `user.${userId}`,
  event: 'PasswordChanged',
  timeout: 15000,
  onSuccess: () => {
    form.value = {
      current_password: '',
      new_password: '',
      new_password_confirmation: ''
    }
  }
})

const changePassword = async () => {
  await execute(async () => {
    return await axios.post('/change-password', form.value)
  })
}
</script>
```

---

## 🎧 **WebSocket Event Channels**

### **Public Channels**
```javascript
// System-wide events
listenToChannel('system-admin', 'TenantCreated', callback)
listenToChannel('tenants', 'TenantCreated', callback)
```

### **Private Channels**
```javascript
// Tenant-specific events
listenToPrivateChannel(`tenant.${tenantId}.users`, 'UserCreated', callback)
listenToPrivateChannel(`tenant.${tenantId}.dashboard-stats`, 'StatsUpdated', callback)

// User-specific events
listenToPrivateChannel(`user.${userId}`, 'PasswordChanged', callback)
```

### **Multiple Events**
```javascript
listenToMultipleEvents('tenants', [
  { event: 'TenantCreated', callback: handleCreated },
  { event: 'TenantUpdated', callback: handleUpdated },
  { event: 'TenantDeleted', callback: handleDeleted }
])
```

---

## 📊 **Backend Events Reference**

| Event | Channel | Description |
|-------|---------|-------------|
| `TenantCreated` | `tenants`, `system-admin` | New tenant registered |
| `UserCreated` | `tenant.{id}.users` | New user created |
| `UserUpdated` | `tenant.{id}.users` | User updated |
| `UserDeleted` | `tenant.{id}.users` | User deleted |
| `PasswordChanged` | `user.{id}` | Password changed |
| `PaymentCompleted` | `tenant.{id}.payments` | Payment successful |
| `HotspotUserCreated` | `tenant.{id}.hotspot` | Hotspot user provisioned |
| `AccountSuspended` | `user.{id}` | Account suspended |

---

## 🎨 **Status Component Props**

```vue
<AsyncOperationStatus
  :is-processing="boolean"
  :is-complete="boolean"
  :has-error="boolean"
  :error-message="string"
  :progress="number (0-100)"
  processing-message="string"
  processing-description="string"
  success-message="string"
  success-description="string"
  error-title="string"
  :show-progress="boolean"
  :show-close-button="boolean"
  :show-retry-button="boolean"
  @close="handler"
  @retry="handler"
/>
```

---

## 🔧 **Composable API**

### **useAsyncOperation**

```javascript
const {
  // State
  isProcessing,      // boolean - Operation in progress
  isComplete,        // boolean - Operation completed
  hasError,          // boolean - Operation failed
  errorMessage,      // string - Error message
  result,            // any - Operation result
  progress,          // number - Progress (0-100)
  
  // Methods
  execute,           // (apiCall, channel?, event?) => Promise
  reset,             // () => void - Reset state
  startListening,    // (channel, event) => void
  stopListening,     // () => void
} = useAsyncOperation(options)
```

**Options**:
```javascript
{
  channel: 'channel-name',     // WebSocket channel to listen
  event: 'EventName',          // Event name to listen for
  timeout: 30000,              // Timeout in ms (default: 30000)
  onSuccess: (data) => {},     // Success callback
  onError: (error) => {},      // Error callback
  onTimeout: () => {},         // Timeout callback
}
```

### **useWebSocketEvents**

```javascript
const {
  listenToChannel,           // (channel, event, callback) => Channel
  listenToPrivateChannel,    // (channel, event, callback) => Channel
  listenToPresenceChannel,   // (channel, event, callback) => Channel
  listenToMultipleEvents,    // (channel, events[], isPrivate) => Channel
  listenToTenantEvents,      // (tenantId, events[]) => Channel[]
  listenToUserEvents,        // (userId, events[]) => Channel[]
  cleanup,                   // () => void - Remove all listeners
} = useWebSocketEvents()
```

---

## 🚀 **Implementation Checklist**

### **For Each Form/Operation**:
- [ ] Import `useAsyncOperation` composable
- [ ] Import `AsyncOperationStatus` component
- [ ] Setup async operation with channel and event
- [ ] Replace form submit with `execute()` call
- [ ] Add status component to template
- [ ] Disable submit button when processing
- [ ] Handle success/error callbacks
- [ ] Add WebSocket event listeners if needed

### **Example Checklist for User Management**:
- [ ] User creation form
- [ ] User edit form
- [ ] User deletion confirmation
- [ ] Password change form
- [ ] Listen to UserCreated events
- [ ] Listen to UserUpdated events
- [ ] Listen to UserDeleted events
- [ ] Refresh user list on events

---

## 📝 **Next Steps**

1. **Update TenantRegistrationView.vue** - Add async operation support
2. **Update User Management Views** - Add async operation support
3. **Update Password Change Form** - Add async operation support
4. **Add Global Event Listeners** - Dashboard notifications
5. **Add Toast Notifications** - For background events
6. **Test All Operations** - Verify WebSocket connectivity

---

## 🎯 **Benefits**

- ✅ Immediate user feedback (< 100ms response)
- ✅ Real-time updates via WebSocket
- ✅ Progress tracking for long operations
- ✅ Automatic retry on failure
- ✅ Clean separation of concerns
- ✅ Reusable composables
- ✅ Type-safe event handling

---

**Status**: ✅ **Composables & Components Ready**  
**Next**: Update views to use async operations
