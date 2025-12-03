# WebSocket Integration Guide

**Date**: December 3, 2025  
**Status**: ✅ **IMPLEMENTED**

---

## 🎯 **Overview**

The WebSocket integration provides real-time, bidirectional communication between the server and clients. It automatically connects on login, subscribes to relevant channels, and displays toast notifications for events.

---

## 📦 **Architecture**

```
┌─────────────────────────────────────────────────────────┐
│                    Frontend Application                  │
├─────────────────────────────────────────────────────────┤
│                                                           │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │
│  │  Auth Store  │  │  WebSocket   │  │ Notification │  │
│  │              │──│   Service    │──│    Store     │  │
│  └──────────────┘  └──────────────┘  └──────────────┘  │
│         │                  │                  │          │
│         │                  │                  │          │
│         └──────────────────┴──────────────────┘          │
│                            │                             │
└────────────────────────────┼─────────────────────────────┘
                             │
                    ┌────────▼────────┐
                    │   Soketi/Pusher │
                    │   WebSocket     │
                    │     Server      │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │  Laravel Echo   │
                    │   Broadcasting  │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │  Laravel Backend│
                    │   Event System  │
                    └─────────────────┘
```

---

## 🔌 **WebSocket Service**

**Location**: `frontend/src/services/websocket.js`

### **Features**

- ✅ Singleton pattern for single connection
- ✅ Automatic channel subscription
- ✅ Event listener management
- ✅ Graceful connection/disconnection
- ✅ Integration with notification store

### **Channels**

#### **1. Tenant Channel** (`tenant.{tenantId}`)

**Public channel** - No authentication required

**Events**:
- `TenantCreated` - New tenant registered
- `TenantUpdated` - Tenant information updated
- `UserCreated` - New user added
- `UserUpdated` - User information updated
- `UserDeleted` - User removed
- `PaymentCompleted` - Payment successful
- `PaymentFailed` - Payment failed
- `HotspotUserCreated` - Hotspot user provisioned
- `HotspotUserExpired` - User session expired
- `SystemNotification` - General notifications

#### **2. User Private Channel** (`user.{userId}`)

**Private channel** - Requires authentication

**Events**:
- `PasswordChanged` - User password updated
- `AccountSuspended` - Account suspended
- `AccountActivated` - Account activated
- `ProfileUpdated` - Profile information updated

#### **3. System Admin Channel** (`system.admin`)

**Private channel** - System admins only

**Events**:
- `TenantRegistered` - New tenant pending approval
- `TenantApproved` - Tenant approved
- `SystemAlert` - System-wide alerts

---

## 🚀 **Implementation**

### **Automatic Connection**

WebSocket automatically connects when:
1. User logs in successfully
2. Page refreshes with valid auth token

```javascript
// In auth store - automatically called on login
this.initializeWebSocket()
```

### **Automatic Disconnection**

WebSocket automatically disconnects when:
1. User logs out
2. Auth token expires

```javascript
// In auth store - automatically called on logout
this.disconnectWebSocket()
```

---

## 📡 **Channel Subscriptions**

### **Tenant Users**

```javascript
// Automatically subscribed to:
websocketService.subscribeTenantChannel(tenantId)  // Tenant events
websocketService.subscribeUserChannel(userId)      // User private events
```

### **System Admins**

```javascript
// Automatically subscribed to:
websocketService.subscribeSystemAdminChannel()     // System admin events
websocketService.subscribeUserChannel(userId)      // User private events
// Note: System admins don't have tenantId
```

---

## 🔔 **Event Handling**

### **Automatic Toast Notifications**

All WebSocket events automatically trigger toast notifications:

```javascript
// Example: UserCreated event
{
  type: 'success',
  title: 'User Created',
  message: 'John Doe has been added to the system.',
  duration: 5000
}
```

### **Event Flow**

```
1. Backend fires event
   ↓
2. Laravel Broadcasting sends to Soketi
   ↓
3. Soketi pushes to connected clients
   ↓
4. WebSocket service receives event
   ↓
5. Notification store creates toast
   ↓
6. User sees notification
```

---

## 🛠️ **Configuration**

### **Environment Variables**

Add to `frontend/.env`:

```env
VITE_PUSHER_APP_KEY=local-key
VITE_PUSHER_HOST=localhost
VITE_PUSHER_PORT=6001
VITE_PUSHER_APP_CLUSTER=mt1
```

### **Backend Configuration**

Ensure `backend/config/broadcasting.php` is configured:

```php
'connections' => [
    'pusher' => [
        'driver' => 'pusher',
        'key' => env('PUSHER_APP_KEY'),
        'secret' => env('PUSHER_APP_SECRET'),
        'app_id' => env('PUSHER_APP_ID'),
        'options' => [
            'host' => env('PUSHER_HOST', '127.0.0.1'),
            'port' => env('PUSHER_PORT', 6001),
            'scheme' => env('PUSHER_SCHEME', 'http'),
            'encrypted' => true,
            'useTLS' => env('PUSHER_SCHEME', 'http') === 'https',
        ],
    ],
],
```

---

## 📝 **Usage Examples**

### **Manual Event Listening**

If you need custom event handling:

```javascript
import { websocketService } from '@/services/websocket'

// Get Echo instance
const echo = websocketService.getEcho()

// Listen to custom event
echo.channel('tenant.123')
  .listen('CustomEvent', (event) => {
    console.log('Custom event received:', event)
    // Handle event
  })
```

### **Check Connection Status**

```javascript
import { websocketService } from '@/services/websocket'

if (websocketService.isConnected()) {
  console.log('WebSocket is connected')
} else {
  console.log('WebSocket is disconnected')
}
```

### **Manual Subscription**

```javascript
import { websocketService } from '@/services/websocket'

// Subscribe to additional channel
websocketService.subscribeTenantChannel('another-tenant-id')

// Unsubscribe from channel
websocketService.unsubscribe('tenant.123')
```

---

## 🧪 **Testing**

### **Test WebSocket Connection**

```bash
# Start Soketi server
docker-compose up -d traidnet-soketi

# Check Soketi logs
docker logs traidnet-soketi -f

# Test connection from browser console
websocketService.isConnected()
// Should return: true
```

### **Test Event Broadcasting**

```bash
# In Laravel backend
php artisan tinker

# Fire test event
use App\Events\UserCreated;
use App\Models\User;

$user = User::first();
broadcast(new UserCreated($user))->toOthers();
```

### **Monitor Events in Browser**

Open browser console and watch for:

```
✅ WebSocket initialized
📡 Subscribing to tenant channel: tenant.123
📡 Subscribing to user private channel: user.456
👤 UserCreated event: {...}
```

---

## 🔍 **Debugging**

### **Enable Debug Logging**

```javascript
// In websocket.js, add:
Pusher.logToConsole = true
```

### **Common Issues**

#### **Issue: WebSocket not connecting**

**Causes**:
- Soketi server not running
- Wrong host/port configuration
- Firewall blocking connection

**Solution**:
```bash
# Check Soketi is running
docker ps | grep soketi

# Check Soketi logs
docker logs traidnet-soketi

# Verify environment variables
echo $VITE_PUSHER_HOST
echo $VITE_PUSHER_PORT
```

#### **Issue: Events not received**

**Causes**:
- Not subscribed to channel
- Event not being broadcast
- Channel name mismatch

**Solution**:
```javascript
// Check subscribed channels
console.log(websocketService.channels)

// Verify channel name matches backend
// Backend: broadcast(new Event())->toOthers()
// Frontend: echo.channel('channel-name')
```

#### **Issue: Private channel authentication fails**

**Causes**:
- Invalid auth token
- Broadcasting auth endpoint not configured
- CORS issues

**Solution**:
```javascript
// Check auth token
console.log(localStorage.getItem('authToken'))

// Verify broadcasting auth endpoint
// Should be: /broadcasting/auth

// Check CORS configuration in backend
```

---

## 📊 **Event Reference**

### **Tenant Events**

| Event | Type | Channel | Description |
|-------|------|---------|-------------|
| `TenantCreated` | Public | `tenant.{id}` | New tenant registered |
| `TenantUpdated` | Public | `tenant.{id}` | Tenant info updated |
| `TenantApproved` | Private | `system.admin` | Tenant approved |
| `TenantSuspended` | Public | `tenant.{id}` | Tenant suspended |

### **User Events**

| Event | Type | Channel | Description |
|-------|------|---------|-------------|
| `UserCreated` | Public | `tenant.{id}` | New user added |
| `UserUpdated` | Public | `tenant.{id}` | User info updated |
| `UserDeleted` | Public | `tenant.{id}` | User removed |
| `PasswordChanged` | Private | `user.{id}` | Password updated |
| `AccountSuspended` | Private | `user.{id}` | Account suspended |
| `AccountActivated` | Private | `user.{id}` | Account activated |

### **Payment Events**

| Event | Type | Channel | Description |
|-------|------|---------|-------------|
| `PaymentCompleted` | Public | `tenant.{id}` | Payment successful |
| `PaymentFailed` | Public | `tenant.{id}` | Payment failed |

### **Hotspot Events**

| Event | Type | Channel | Description |
|-------|------|---------|-------------|
| `HotspotUserCreated` | Public | `tenant.{id}` | User provisioned |
| `HotspotUserExpired` | Public | `tenant.{id}` | Session expired |

### **System Events**

| Event | Type | Channel | Description |
|-------|------|---------|-------------|
| `SystemNotification` | Public | `tenant.{id}` | General notification |
| `SystemAlert` | Private | `system.admin` | System alert |
| `TenantRegistered` | Private | `system.admin` | New registration |

---

## 🔐 **Security**

### **Authentication**

- Private channels require valid auth token
- Auth token sent in `Authorization` header
- Backend validates token before allowing subscription

### **Authorization**

- Users can only subscribe to their own private channel
- Users can only subscribe to their tenant's channel
- System admins can subscribe to system admin channel

### **Best Practices**

1. ✅ Always use private channels for sensitive data
2. ✅ Validate user permissions on backend before broadcasting
3. ✅ Don't send sensitive data in public channels
4. ✅ Use HTTPS/WSS in production
5. ✅ Implement rate limiting on broadcasting auth endpoint

---

## 📈 **Performance**

### **Connection Pooling**

- Single WebSocket connection per client
- Multiple channels over same connection
- Automatic reconnection on disconnect

### **Message Size**

- Keep event payloads small (< 10KB)
- Use IDs instead of full objects
- Fetch full data via API if needed

### **Scaling**

- Soketi supports horizontal scaling
- Use Redis for presence channels
- Configure load balancer for WebSocket support

---

## 🎉 **Summary**

✅ **WebSocket Service** - Singleton service for connection management  
✅ **Automatic Connection** - Connects on login, disconnects on logout  
✅ **Channel Subscriptions** - Tenant, user, and admin channels  
✅ **Event Handling** - Automatic toast notifications  
✅ **Real-time Updates** - Instant notifications across all clients  
✅ **Secure** - Private channels with authentication  
✅ **Scalable** - Supports multiple channels and events  

---

## 📚 **Additional Resources**

- [Laravel Echo Documentation](https://laravel.com/docs/broadcasting)
- [Soketi Documentation](https://docs.soketi.app/)
- [Pusher Protocol](https://pusher.com/docs/channels/library_auth_reference/pusher-websockets-protocol/)

---

**Status**: ✅ Priority 2 Complete! Real-time notifications are live! 🚀
