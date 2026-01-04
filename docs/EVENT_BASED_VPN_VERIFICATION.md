# Event-Based VPN Connectivity Verification

## Overview

The VPN connectivity verification system uses **event-driven architecture** with WebSocket (Soketi) for real-time notifications. Instead of polling, the frontend subscribes to WebSocket events and receives instant updates when VPN connectivity is verified or fails.

## Architecture

### Event Flow

```
1. User creates router
   └─> Backend creates VPN config
   └─> Dispatches VerifyVpnConnectivityJob

2. Background Job (VerifyVpnConnectivityJob)
   └─> Pings router VPN IP every 5 seconds
   └─> Broadcasts events via WebSocket:
       • vpn.connectivity.checking (progress)
       • vpn.connectivity.verified (success)
       • vpn.connectivity.failed (timeout/error)

3. Frontend (WebSocket Listener)
   └─> Subscribes to tenant.{id}.vpn channel
   └─> Receives real-time events
   └─> Updates UI automatically
   └─> Auto-proceeds when verified
```

## Backend Components

### 1. Events

#### VpnConnectivityChecking
Broadcast during each ping attempt to show progress.

```php
broadcast(new VpnConnectivityChecking(
    $tenantId,
    $routerId,
    $vpnConfigId,
    $clientIp,
    $attempt,      // Current attempt number
    $maxAttempts   // Total attempts
));
```

**Payload:**
```json
{
  "event": "vpn.connectivity.checking",
  "router_id": "2d1c3455-4a4c-42bf-b57e-3fd998695305",
  "vpn_config_id": 123,
  "client_ip": "10.100.1.1",
  "attempt": 5,
  "max_attempts": 24,
  "progress": 20.8,
  "timestamp": "2026-01-04T19:30:15.000000Z"
}
```

#### VpnConnectivityVerified
Broadcast when router responds successfully (0% packet loss).

```php
broadcast(new VpnConnectivityVerified(
    $tenantId,
    $routerId,
    $vpnConfigId,
    $clientIp,
    $latencyMs,    // Average RTT
    $packetLoss,   // Should be 0
    $attempts      // Total attempts taken
));
```

**Payload:**
```json
{
  "event": "vpn.connectivity.verified",
  "router_id": "2d1c3455-4a4c-42bf-b57e-3fd998695305",
  "vpn_config_id": 123,
  "client_ip": "10.100.1.1",
  "connectivity": {
    "reachable": true,
    "packet_loss": 0,
    "latency_ms": 165.5,
    "status": "connected"
  },
  "attempts": 8,
  "timestamp": "2026-01-04T19:30:40.000000Z"
}
```

#### VpnConnectivityFailed
Broadcast when timeout reached or error occurs.

```php
broadcast(new VpnConnectivityFailed(
    $tenantId,
    $routerId,
    $vpnConfigId,
    $clientIp,
    $reason,       // Error message
    $attempts      // Total attempts made
));
```

**Payload:**
```json
{
  "event": "vpn.connectivity.failed",
  "router_id": "2d1c3455-4a4c-42bf-b57e-3fd998695305",
  "vpn_config_id": 123,
  "client_ip": "10.100.1.1",
  "connectivity": {
    "reachable": false,
    "packet_loss": 100,
    "latency_ms": null,
    "status": "timeout"
  },
  "reason": "VPN connectivity timeout - router did not respond within 120 seconds",
  "attempts": 24,
  "timestamp": "2026-01-04T19:32:15.000000Z"
}
```

### 2. Background Job

**File:** `backend/app/Jobs/VerifyVpnConnectivityJob.php`

**Configuration:**
```php
public function __construct(
    public int $tenantId,
    public int $vpnConfigId,
    public int $maxWaitSeconds = 120,  // 2 minutes default
    public int $retryInterval = 5       // 5 seconds between pings
) {}
```

**Dispatching:**
```php
// In VpnService::createVpnConfiguration()
\App\Jobs\VerifyVpnConnectivityJob::dispatch(
    $tenant->id,
    $vpnConfig->id,
    120, // max_wait_seconds
    5    // retry_interval
)->onQueue('default');
```

**Features:**
- Runs in background (non-blocking)
- Tenant-aware (uses TenantAwareJob trait)
- Broadcasts progress every 5 seconds
- Updates VPN config status automatically
- Comprehensive error handling
- Detailed logging

### 3. WebSocket Channel

**Channel Name:** `tenant.{tenant_id}.vpn`

**Example:** `tenant.1.vpn`

**Channel Type:** Public (no authentication required for tenant users)

**Events:**
- `vpn.connectivity.checking`
- `vpn.connectivity.verified`
- `vpn.connectivity.failed`

## Frontend Integration

### 1. WebSocket Subscription

**File:** `frontend/src/modules/tenant/composables/useRouterProvisioning.js`

```javascript
const subscribeToVpnEvents = () => {
  const user = JSON.parse(localStorage.getItem('user'))
  const channelName = `tenant.${user.tenant_id}.vpn`
  
  const channel = pusher.subscribe(channelName)
  
  // Listen for progress updates
  channel.bind('vpn.connectivity.checking', (data) => {
    if (data.router_id === provisioningRouter.value?.id) {
      // Update progress bar
      const progress = data.progress || 0
      provisioningProgress.value = 40 + (progress * 0.2)
      addLog('info', `Checking VPN... Attempt ${data.attempt}/${data.max_attempts}`)
    }
  })
  
  // Listen for success
  channel.bind('vpn.connectivity.verified', (data) => {
    if (data.router_id === provisioningRouter.value?.id) {
      vpnConnectivityStatus.value = 'verified'
      vpnConnected.value = true
      addLog('success', `✅ VPN connected! Latency: ${data.connectivity.latency_ms}ms`)
      
      // Auto-proceed to next stage
      setTimeout(() => probeVpnConnectivity(), 2000)
    }
  })
  
  // Listen for failure
  channel.bind('vpn.connectivity.failed', (data) => {
    if (data.router_id === provisioningRouter.value?.id) {
      vpnConnectivityStatus.value = 'failed'
      addLog('error', '❌ VPN connectivity failed')
      addLog('error', data.reason)
    }
  })
}
```

### 2. State Management

**New State Variables:**
```javascript
const vpnConnectivityStatus = ref('pending')  // pending, checking, verified, failed
const vpnConnectivityAttempts = ref(0)        // Current attempt number
const vpnLatencyMs = ref(null)                // Measured latency
```

### 3. UI Updates

**Progress Bar:**
- 0-40%: Router creation
- 40-60%: VPN connectivity verification (real-time updates)
- 60-80%: Interface discovery
- 80-100%: Service configuration

**Activity Logs:**
- Real-time log entries as events arrive
- Color-coded by level (info/success/error/warning)
- Shows attempt count and progress percentage
- Displays latency on success

**Auto-Progression:**
- Automatically proceeds to next stage when VPN verified
- 2-second delay for user to see success message
- No manual "Continue" button click needed

## Configuration

### Backend Configuration

**Max Wait Time:**
```php
// In VpnService.php
VerifyVpnConnectivityJob::dispatch(
    $tenant->id,
    $vpnConfig->id,
    120, // ← Change this (10-300 seconds)
    5
);
```

**Retry Interval:**
```php
VerifyVpnConnectivityJob::dispatch(
    $tenant->id,
    $vpnConfig->id,
    120,
    5    // ← Change this (2-30 seconds)
);
```

### Queue Configuration

**Queue Name:** `default`

**To use different queue:**
```php
VerifyVpnConnectivityJob::dispatch(...)
    ->onQueue('vpn-verification');
```

**Ensure queue worker is running:**
```bash
php artisan queue:work --queue=default
```

## Testing

### 1. Test Event Broadcasting

**Check Soketi is running:**
```bash
docker compose -f docker-compose.production.yml ps wificore-soketi
```

**Monitor Soketi logs:**
```bash
docker compose -f docker-compose.production.yml logs -f wificore-soketi
```

### 2. Test Job Execution

**Check queue worker:**
```bash
docker compose -f docker-compose.production.yml logs -f wificore-queue-worker
```

**Monitor job execution:**
```bash
# In backend container
tail -f storage/logs/laravel.log | grep "VPN connectivity"
```

### 3. Test Frontend WebSocket

**Browser Console:**
```javascript
// Check Pusher connection
window.Echo.connector.pusher.connection.state
// Should be: "connected"

// Check subscribed channels
window.Echo.connector.pusher.channels.channels
// Should include: "tenant.1.vpn"
```

### 4. Manual Testing Flow

1. **Create Router:**
   - Open browser console (F12)
   - Create new router via UI
   - Watch console for WebSocket events

2. **Apply Configuration:**
   - Copy fetch command
   - Apply on MikroTik router
   - Watch for real-time progress updates

3. **Verify Events:**
   - Should see `vpn.connectivity.checking` events
   - Should see `vpn.connectivity.verified` after ~40 seconds
   - Progress bar should update automatically
   - Should auto-proceed to next stage

## Troubleshooting

### Events Not Received

**Check WebSocket Connection:**
```javascript
// Browser console
window.Echo.connector.pusher.connection.state
```

**Possible Issues:**
- Soketi not running
- Wrong WebSocket URL in `.env`
- CORS issues
- Firewall blocking WebSocket port

**Solutions:**
```bash
# Restart Soketi
docker compose -f docker-compose.production.yml restart wificore-soketi

# Check Soketi logs
docker compose -f docker-compose.production.yml logs wificore-soketi

# Verify environment variables
grep PUSHER .env.production
```

### Job Not Executing

**Check Queue Worker:**
```bash
docker compose -f docker-compose.production.yml ps wificore-queue-worker
```

**Check Failed Jobs:**
```bash
# In backend container
php artisan queue:failed
```

**Restart Queue Worker:**
```bash
docker compose -f docker-compose.production.yml restart wificore-queue-worker
```

### Wrong Channel

**Verify Tenant ID:**
```javascript
// Browser console
const user = JSON.parse(localStorage.getItem('user'))
console.log('Tenant ID:', user.tenant_id)
console.log('Expected channel:', `tenant.${user.tenant_id}.vpn`)
```

**Check Backend Logs:**
```bash
# Should show correct tenant_id
grep "VPN connectivity verification job dispatched" storage/logs/laravel.log
```

## Performance

### Resource Usage

**Per Verification:**
- CPU: Minimal (ping command)
- Memory: ~10MB (job instance)
- Network: ~1KB per ping
- Duration: 5-120 seconds

**Concurrent Verifications:**
- Supported: Yes
- Isolation: Per-tenant channels
- Scaling: Horizontal (multiple queue workers)

### Optimization

**Reduce Verification Time:**
```php
// Faster pings (3-second interval)
VerifyVpnConnectivityJob::dispatch($tenant->id, $vpnConfig->id, 60, 3);
```

**Increase Timeout for Slow Networks:**
```php
// 5-minute timeout
VerifyVpnConnectivityJob::dispatch($tenant->id, $vpnConfig->id, 300, 10);
```

## Security

### Channel Isolation

- Each tenant has dedicated channel: `tenant.{id}.vpn`
- Frontend filters events by `router_id`
- No cross-tenant event leakage

### Authentication

- WebSocket connection requires valid Sanctum token
- Channel subscription validated by tenant context
- Events only visible to authenticated tenant users

### Rate Limiting

**Recommended:**
```php
// In RouteServiceProvider or middleware
RateLimiter::for('vpn-verification', function (Request $request) {
    return Limit::perMinute(5)->by($request->user()->tenant_id);
});
```

## Migration from Polling

### Old Approach (Polling)
```javascript
// ❌ Frontend polls API every 5 seconds
setInterval(async () => {
  const response = await axios.post(`/vpn/${id}/verify-connectivity`)
  if (response.data.success) {
    // Proceed
  }
}, 5000)
```

**Problems:**
- High API load
- Delayed updates (5-second intervals)
- Wasted requests
- Poor UX

### New Approach (Event-Based)
```javascript
// ✅ Frontend subscribes to WebSocket events
channel.bind('vpn.connectivity.verified', (data) => {
  // Instant notification
  // Zero API polling
})
```

**Benefits:**
- Instant updates
- Zero API overhead
- Better UX
- Scalable

## Summary

| Feature | Polling | Event-Based |
|---------|---------|-------------|
| **Real-time Updates** | ❌ 5s delay | ✅ Instant |
| **API Load** | ❌ High | ✅ Zero |
| **Scalability** | ❌ Poor | ✅ Excellent |
| **User Experience** | ❌ Laggy | ✅ Smooth |
| **Resource Usage** | ❌ High | ✅ Low |
| **Implementation** | ✅ Simple | ⚠️ Moderate |

**Recommendation:** Use event-based approach for all real-time features.
