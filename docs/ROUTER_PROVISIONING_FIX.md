# Router Provisioning Fix - Event-Based Flow

## Problem
Router registration was stuck at 40% after VPN connectivity verification because:
1. Frontend was using **polling** instead of **events** for interface discovery
2. No automatic progression from VPN verification to service configuration
3. Missing `router.interfaces.discovered` event

## Solution

### Backend Changes (Commit `0e03a85`)

1. **Created `RouterInterfacesDiscovered` Event**
   - File: `backend/app/Events/RouterInterfacesDiscovered.php`
   - Broadcasts router interfaces after VPN connectivity is verified
   - Channel: `tenant.{tenantId}.routers`
   - Event: `router.interfaces.discovered`

2. **Updated `VerifyVpnConnectivityJob`**
   - File: `backend/app/Jobs/VerifyVpnConnectivityJob.php`
   - Added `discoverRouterInterfaces()` method
   - Auto-discovers interfaces after VPN verification succeeds
   - Fetches live data from router via MikroTik API
   - Broadcasts interfaces to frontend via WebSocket

### Frontend Changes (Commit `32cd625`)

1. **Updated `useRouterProvisioning` Composable**
   - File: `frontend/src/modules/tenant/composables/useRouterProvisioning.js`
   - Removed polling-based `probeVpnConnectivity()` call
   - Added listener for `router.interfaces.discovered` event
   - Auto-progresses to Stage 3 (Service Configuration) when interfaces are received

## Flow (Event-Based - No Polling)

```
Stage 1: Router Creation
  ↓
  User clicks "Continue"
  ↓
Stage 2: VPN Connectivity Verification
  ↓
  Backend: VerifyVpnConnectivityJob runs
  ↓
  Event: vpn.connectivity.checking (progress updates)
  ↓
  Event: vpn.connectivity.verified ✅
  ↓
  Backend: Auto-discovers interfaces via MikroTik API
  ↓
  Event: router.interfaces.discovered ✅
  ↓
Stage 3: Service Configuration (Hotspot/PPPoE)
  ↓
  User selects services and interfaces
  ↓
  User clicks "Generate"
  ↓
Stage 4: Configuration Deployment
  ↓
  Backend: Applies configuration to router
  ↓
Stage 5: Complete ✅
```

## System Admin User

- **Username**: `sysadmin`
- **Password**: `Admin@123!`
- **Role**: `system_admin`
- **Tenant**: None (landlord/system administrator)

The system admin can login and manage the SaaS platform.

## Deployment Instructions

```bash
# On production server
cd /opt/wificore

# Pull latest changes
git pull origin main

# Rebuild backend (includes new event)
docker compose -f docker-compose.production.yml build --no-cache wificore-backend

# Rebuild frontend (includes updated composable)
docker compose -f docker-compose.production.yml build --no-cache wificore-frontend

# Restart services
docker compose -f docker-compose.production.yml restart wificore-backend wificore-queue-worker wificore-frontend

# Verify logs
docker compose -f docker-compose.production.yml logs -f wificore-backend
```

## Testing

1. Login as tenant user
2. Navigate to Routers → Add Router
3. Enter router name and click "Create Router"
4. Copy the fetch command and apply it to MikroTik router
5. Click "Continue" to start VPN verification
6. **Watch for automatic progression**:
   - Progress should move from 40% → 60% (VPN verified)
   - Then automatically to 75% (Interfaces discovered)
   - Stage 3 should appear with interface list
7. Select services (Hotspot/PPPoE) and interfaces
8. Click "Generate" → "Deploy"
9. Verify completion at 100%

## Key Benefits

✅ **Purely Event-Based** - No polling, real-time updates via WebSocket
✅ **Automatic Progression** - Moves through stages automatically
✅ **Better UX** - Instant feedback, no delays
✅ **Scalable** - No repeated API calls, efficient resource usage
✅ **Reliable** - Events guarantee delivery, no missed updates
