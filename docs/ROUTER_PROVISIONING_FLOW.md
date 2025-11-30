# Multi-Stage Router Provisioning Flow - Implementation Guide

## Overview

This document describes the complete multi-stage router provisioning workflow implemented in the WiFi Hotspot Management System. The flow automates the process of setting up MikroTik routers from initial configuration to full service deployment.

---

## Architecture

### Flow Stages

```
Stage 1: Router Identity Creation
    ↓
Stage 2: Continuous Probing (Automatic)
    ↓
Stage 3: Interface Discovery & Service Selection
    ↓
Stage 4: Service Configuration Generation
    ↓
Stage 5: Configuration Deployment (Job-based)
```

---

## Stage 1: Router Identity & Initial Configuration

### Frontend Component
**File**: `frontend/src/components/dashboard/routers/createOverlay.vue`

### User Actions
1. Opens the "Add Router" overlay
2. Enters router name (identity)
3. Clicks "Create Router"

### Backend Process
**Endpoint**: `POST /routers/create-with-config`
**Controller**: `RouterController@createRouterWithConfig`

**Actions**:
1. Validates router name (unique)
2. Creates router record with:
   - Name
   - Auto-generated `config_token` (secure authentication)
   - Status: `config_pending`
3. Generates initial configuration script containing:
   - System identity setup
   - API user creation with token-based auth
   - Network discovery settings
4. **Automatically dispatches `RouterProbingJob`** to `router-monitoring` queue
5. Returns router data and configuration script to frontend

### Initial Configuration Script Contents
```routeros
/system identity set name="[ROUTER_NAME]"
/user add name="api-[ROUTER_NAME]" group=full password="[CONFIG_TOKEN]"
/ip neighbor discovery-settings set discover-interface-list=all
```

### Frontend Display
- Shows generated configuration script
- Provides "Copy Script" button
- Displays instructions to apply config on MikroTik router
- Shows "Continue to Monitoring" button

---

## Stage 2: Continuous Router Probing (Automatic)

### Job
**File**: `backend/app/Jobs/RouterProbingJob.php`
**Queue**: `router-monitoring`

### Automatic Trigger
The `RouterProbingJob` is automatically dispatched when a router is created (Stage 1), eliminating the need for manual intervention.

### Probing Parameters
```php
public function __construct(
    int $routerId,
    int $attempts = 0,
    int $maxAttempts = 60,      // 60 attempts
    int $checkInterval = 10      // Every 10 seconds
)
// Total monitoring time: 10 minutes
```

### Probing Process
1. **Check router status**: Skip if already `online`, `connected`, `active`, or `provisioning`
2. **Attempt connectivity**: Call `MikrotikProvisioningService->verifyConnectivity()`
3. **On Success**:
   - Update router status to `online`
   - Store router model and OS version
   - Broadcast `RouterConnected` event
   - Stop probing
4. **On Failure**:
   - If attempts < maxAttempts:
     - Schedule next probe after 10 seconds
     - Continue monitoring
   - If max attempts reached:
     - Update status to `connection_failed`
     - Stop probing

### Frontend Polling
**Method**: `startConnectionMonitoring()`
- Polls `/routers/{id}/provisioning-status` every 3 seconds
- Updates connection status indicator
- Shows real-time progress

### WebSocket Events
**Channel**: `router-provisioning`
**Events**:
- `.RouterStatusUpdated`: Router status changes
- `.RouterConnected`: Router successfully connected

### Auto-Advancement
When router status becomes `online`:
1. Frontend automatically advances to Stage 3
2. Clears polling interval
3. Calls `discoverInterfaces()` to fetch router information

---

## Stage 3: Interface Discovery & Service Selection

### Backend Process
**Endpoint**: `GET /routers/{id}/interfaces`
**Controller**: `RouterController@getRouterInterfaces`

### Discovery Process
1. **Verify router is online**: Check status === `online`
2. **Fetch router information**:
   - System identity
   - Model (e.g., "RB3011UiAS-RM")
   - RouterOS version
   - Available interfaces (ethernet, bridge, wireless, etc.)
3. **Return data**:
   ```json
   {
     "success": true,
     "interfaces": [
       {"name": "ether1", "type": "ether", "running": true},
       {"name": "ether2", "type": "ether", "running": false},
       {"name": "bridge1", "type": "bridge", "running": true}
     ],
     "router_info": {
       "model": "RB3011UiAS-RM",
       "os_version": "7.10.2",
       "last_seen": "2025-10-05T11:00:00+03:00"
     },
     "stage": 3
   }
   ```

### Frontend Display
**Component**: Stage 3 section in `createOverlay.vue`

**Features**:
1. **Router Information Card**
   - Model
   - OS Version

2. **Service Selection**
   - **Hotspot Service** (checkbox toggle)
     - Interface selection (multi-select)
     - Captive portal settings:
       - Portal title
       - Login method (MAC, HTTP-CHAP, HTTPS)
   
   - **PPPoE Service** (checkbox toggle)
     - Interface selection (multi-select)
     - PPPoE settings:
       - Service name
       - IP pool range

3. **Validation**
   - At least one service must be enabled
   - At least one interface must be selected per enabled service

### User Actions
1. Enable desired service(s)
2. Select interfaces for each service
3. Configure service-specific settings
4. Click "Generate Config"

---

## Stage 4: Service Configuration Generation

### Backend Process
**Endpoint**: `POST /routers/{id}/generate-service-config`
**Controller**: `RouterController@generateServiceConfig`

### Request Validation
```php
$request->validate([
    'enable_hotspot' => 'nullable|boolean',
    'hotspot_interfaces' => 'nullable|array',
    'hotspot_interfaces.*' => 'string',
    'portal_title' => 'nullable|string|max:255',
    'login_method' => 'nullable|in:mac,http-chap,https',
    'enable_pppoe' => 'nullable|boolean',
    'pppoe_interfaces' => 'nullable|array',
    'pppoe_interfaces.*' => 'string',
    'pppoe_service_name' => 'nullable|string|max:255',
    'pppoe_ip_pool' => 'nullable|string|regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}-\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/',
]);
```

### Configuration Generation
**Service**: `MikrotikProvisioningService->generateConfigs()`

**Process**:
1. **Validate interfaces exist** on router
2. **Build interface assignments** array
3. **Generate service-specific configurations**:
   - Hotspot: IP pools, profiles, servers, captive portal
   - PPPoE: IP pools, profiles, servers, secrets
4. **Generate RouterOS script** from templates
5. **Validate script syntax**
6. **Save to database**: Store in `router_configs` table with type `service`
7. **Return script** to frontend

### Generated Script Structure
```routeros
# Hotspot Configuration
/ip pool add name=hs-pool-[interface] ranges=[pool_range]
/ip hotspot profile add name=[profile_name] login-by=mac,http-chap
/ip hotspot add name=hotspot-[interface] interface=[interface] address-pool=hs-pool-[interface] profile=[profile_name]

# PPPoE Configuration
/ip pool add name=pppoe-pool-[interface] ranges=[pool_range]
/ppp profile add name=pppoe-profile-[interface] local-address=[gateway] remote-address=pppoe-pool-[interface]
/interface pppoe-server server add service-name=[service_name] interface=[interface] default-profile=pppoe-profile-[interface]

# RADIUS Integration (if enabled)
/radius add address=[radius_server] secret=[radius_secret] service=hotspot,pppoe
```

### Frontend Display
- Configuration preview (optional, currently hidden)
- "Deploy Configuration" button
- Deployment status indicator

---

## Stage 5: Configuration Deployment

### Backend Process
**Endpoint**: `POST /routers/{id}/deploy-service-config`
**Controller**: `RouterController@deployServiceConfig`

### Request Data
```json
{
  "service_type": "hotspot",  // or "pppoe"
  "commands": ["command1", "command2", ...]
}
```

### Deployment Process
1. **Update router status** to `provisioning`
2. **Dispatch `RouterProvisioningJob`** to `router-provisioning` queue
3. **Return immediate response** with job initiation status
4. **Job processes asynchronously**

### RouterProvisioningJob Workflow
**File**: `backend/app/Jobs/RouterProvisioningJob.php`

**Execution Steps**:

#### Step 1: Verify Connectivity (Progress: 10%)
```php
$connectivity = $provisioningService->verifyConnectivity($router);
if ($connectivity['status'] !== 'connected' && $connectivity['status'] !== 'online') {
    throw new \Exception('Router not connected');
}
```

#### Step 2: Apply Configuration (Progress: 40%)
```php
$applyResult = $provisioningService->applyConfigs($router);
```

**Apply Process** (`MikrotikProvisioningService->applyConfigs()`):
1. Retrieve saved service configuration from database
2. Validate script syntax
3. Connect to router via API
4. Upload script as file to router
5. Execute script using `/import` command
6. Verify execution success

#### Step 3: Verify Deployment (Progress: 85%)
```php
sleep(3);  // Allow router to process configs
$liveData = $provisioningService->fetchLiveRouterData($router);
```

#### Step 4: Complete (Progress: 100%)
```php
$router->update([
    'status' => 'active',
    'last_seen' => now(),
]);
```

### Real-Time Progress Broadcasting
**Event**: `RouterProvisioningProgress`
**Channel**: `router-provisioning`

**Broadcast Points**:
- `verifying` (10%): "Verifying router connectivity..."
- `connected` (20%): "Router connected successfully"
- `deploying` (40%): "Deploying service configuration to router..."
- `deployed` (70%): "Configuration deployed successfully"
- `verifying_deployment` (85%): "Verifying deployment..."
- `completed` (100%): "Router provisioned successfully!"
- `failed` (0%): "Provisioning failed: [error message]"

### Frontend Real-Time Updates
**WebSocket Listener**: `.RouterProvisioningProgress`

**Update Handling**:
```javascript
channel.listen('.RouterProvisioningProgress', (e) => {
  provisioningProgress.value = e.progress
  deploymentProgress.value = e.progress
  provisioningStatus.value = e.message
  deploymentMessage.value = e.message
  
  addLog('info', `${e.stage}: ${e.message}`)
  
  if (e.stage === 'completed') {
    handleJobCompletion(e)
  } else if (e.stage === 'failed') {
    handleJobFailure(e)
  }
})
```

### Success Flow
1. Job completes successfully
2. Frontend receives `completed` event
3. Shows success message with checkmarks
4. Waits 3 seconds
5. Closes overlay
6. Refreshes router list (router now shows as `active`)

### Failure Handling
1. Job encounters error
2. Router status set to `failed`
3. Frontend receives `failed` event
4. Shows error message with details
5. Provides "Retry Deployment" button

---

## Error Handling & Recovery

### Common Issues and Solutions

#### Issue 1: Router Not Connecting
**Symptoms**: Stage 2 stays in "Waiting" for extended time
**Causes**:
- Initial configuration not applied on router
- Network connectivity issues
- Incorrect config token
- Router not reachable from server

**Solutions**:
1. Verify initial configuration was applied
2. Check router network connectivity
3. Check firewall rules allow API port (8728)
4. Manually test connectivity: `ping [router-ip]`
5. Check router logs for API connection attempts

#### Issue 2: Configuration Deployment Fails
**Symptoms**: Stage 5 shows "Failed" status
**Causes**:
- Invalid RouterOS script syntax
- Router resources exhausted
- API connection lost during deployment
- Insufficient router permissions

**Solutions**:
1. Check job logs: `docker logs traidnet-backend | grep -i provision`
2. Verify script syntax manually on router terminal
3. Check router CPU/memory usage
4. Verify API user has `full` group permissions
5. Use "Retry Deployment" button

#### Issue 3: Interface Discovery Fails
**Symptoms**: Stage 3 shows empty or error
**Causes**:
- Router connection lost after Stage 2
- API query timeout
- Router firewall blocking API

**Solutions**:
1. Verify router still online
2. Test manual connectivity: `GET /routers/{id}/verify-connectivity`
3. Check router firewall rules
4. Retry interface discovery (built-in retry mechanism: 3 attempts)

---

## Testing Guide

### Prerequisites
1. Running Docker stack
2. MikroTik router (physical or virtual - CHR)
3. Network connectivity between server and router
4. Router with factory reset or clean configuration

### Test Procedure

#### 1. Create Router (Stage 1)
```bash
# Frontend: Open Router Management page
# Click "Add Router"
# Enter name: "TEST-ROUTER-01"
# Click "Create Router"
# Expected: Router created, initial config displayed
```

**Verify Backend**:
```bash
docker exec traidnet-backend php artisan tinker
>>> $router = App\Models\Router::where('name', 'TEST-ROUTER-01')->first();
>>> $router->status; // Should be "probing"
>>> $router->config_token; // Should have a token
```

**Verify Queue**:
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT * FROM jobs WHERE queue = 'router-monitoring' ORDER BY id DESC LIMIT 1;"
```

#### 2. Apply Configuration on Router (Stage 1→2)
```routeros
# On MikroTik terminal, paste the initial configuration script
/system identity set name="TEST-ROUTER-01"
/user add name="api-TEST-ROUTER-01" group=full password="[TOKEN_FROM_FRONTEND]"
/ip neighbor discovery-settings set discover-interface-list=all
```

**Expected**: Within 10-30 seconds, router status changes to `online`

**Verify**:
```bash
# Check router status
docker exec traidnet-backend php artisan tinker
>>> App\Models\Router::find(1)->status; // Should be "online"
```

#### 3. Service Selection (Stage 3)
```bash
# Frontend: Overlay automatically advances to Stage 3
# Select "Hotspot Service"
# Check interfaces: ether2, ether3
# Set Portal Title: "Welcome to Test WiFi"
# Login Method: MAC Address
# Click "Generate Config"
```

**Verify Backend**:
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT * FROM router_configs WHERE router_id = 1 AND config_type = 'service' ORDER BY id DESC LIMIT 1;"
# Should show generated service configuration
```

#### 4. Deploy Configuration (Stage 4→5)
```bash
# Frontend: Click "Deploy Configuration"
# Expected: Deployment progress shows in real-time
# Watch logs update with each stage
```

**Monitor Job Execution**:
```bash
# In separate terminal
docker logs -f traidnet-backend | grep -i "router provisioning"
```

**Expected Output**:
```
Router provisioning job started
Stage: verifying - Verifying router connectivity...
Stage: connected - Router connected successfully
Stage: deploying - Deploying service configuration to router...
Stage: deployed - Configuration deployed successfully
Stage: verifying_deployment - Verifying deployment...
Stage: completed - Router provisioned successfully!
```

#### 5. Verify Final State
```bash
# Check router status
docker exec traidnet-backend php artisan tinker
>>> $router = App\Models\Router::find(1);
>>> $router->status; // Should be "active"
>>> $router->model; // Should show router model
>>> $router->os_version; // Should show RouterOS version
```

**On Router**:
```routeros
# Verify hotspot is running
/ip hotspot print
# Verify IP pools created
/ip pool print
# Verify hotspot profile
/ip hotspot profile print
```

---

## Monitoring & Debugging

### Backend Logs
```bash
# General application logs
docker logs -f traidnet-backend

# Queue worker logs (if separate container)
docker logs -f traidnet-queue-worker

# Filter for provisioning
docker logs traidnet-backend | grep -i "provision"

# Filter for specific router
docker logs traidnet-backend | grep "router_id.*1"
```

### Database Queries
```sql
-- Check router status
SELECT id, name, status, model, os_version, last_seen, created_at 
FROM routers 
ORDER BY id DESC;

-- Check jobs queue
SELECT id, queue, payload, attempts, created_at 
FROM jobs 
ORDER BY id DESC 
LIMIT 10;

-- Check failed jobs
SELECT id, queue, payload, exception, failed_at 
FROM failed_jobs 
ORDER BY id DESC 
LIMIT 10;

-- Check router configs
SELECT id, router_id, config_type, LENGTH(config_content) as config_size, created_at 
FROM router_configs 
ORDER BY id DESC 
LIMIT 10;
```

### Frontend Console
```javascript
// Monitor WebSocket events
window.Echo.connector.channels['router-provisioning'].listenForWhisper('ping', (e) => {
  console.log('WebSocket active:', e);
});

// Check current provisioning state
console.log('Current Stage:', currentStage.value);
console.log('Provisioning Progress:', provisioningProgress.value);
console.log('Router:', provisioningRouter.value);
```

---

## Performance Optimization

### Queue Worker Configuration
```bash
# Recommended queue worker command
php artisan queue:work \
  --queue=router-provisioning,router-monitoring,router-data \
  --tries=3 \
  --timeout=300 \
  --memory=512
```

### Database Indexing
```sql
-- Ensure indexes exist for performance
CREATE INDEX IF NOT EXISTS idx_routers_status ON routers(status);
CREATE INDEX IF NOT EXISTS idx_routers_last_seen ON routers(last_seen);
CREATE INDEX IF NOT EXISTS idx_jobs_queue ON jobs(queue);
CREATE INDEX IF NOT EXISTS idx_router_configs_router_id ON router_configs(router_id);
```

### Caching Strategy
- **Router details**: Cache for 5 minutes
- **Router status**: Real-time (no cache)
- **Interface list**: Cache for 10 minutes (until next verification)

---

## Security Considerations

### Configuration Token
- Auto-generated using `Str::random(32)`
- Used for initial router authentication
- Stored encrypted in database
- Transmitted via HTTPS only

### API User Permissions
- Created with `full` group for provisioning
- Consider creating restricted group for production
- Recommended permissions: `read`, `write`, `api`, `policy`

### Network Security
- API port (8728) should be firewalled
- Only allow connections from management server
- Consider VPN tunnel for remote routers
- Use HTTPS for API if possible (port 8729)

### Best Practices
1. **Rotate tokens** periodically
2. **Log all provisioning** activities
3. **Implement rate limiting** on API endpoints
4. **Monitor failed job** attempts
5. **Alert on provisioning failures**

---

## API Endpoints Reference

### Stage 1: Create Router
```http
POST /routers/create-with-config
Content-Type: application/json
Authorization: Bearer [admin-token]

{
  "name": "ROUTER-NAME"
}

Response 201:
{
  "success": true,
  "router": {
    "id": 1,
    "name": "ROUTER-NAME",
    "config_token": "abc123...",
    "status": "probing"
  },
  "config": "# Initial configuration script...",
  "stage": 1,
  "message": "Router created. Please copy and apply..."
}
```

### Stage 2: Get Provisioning Status
```http
GET /routers/{id}/provisioning-status
Authorization: Bearer [admin-token]

Response 200:
{
  "success": true,
  "router": {
    "id": 1,
    "name": "ROUTER-NAME",
    "status": "online",
    "last_seen": "2025-10-05T11:00:00+03:00",
    "model": "RB3011UiAS-RM",
    "os_version": "7.10.2"
  },
  "stage": 3
}
```

### Stage 3: Get Interfaces
```http
GET /routers/{id}/interfaces
Authorization: Bearer [admin-token]

Response 200:
{
  "success": true,
  "interfaces": [...],
  "router_info": {...},
  "stage": 3
}
```

### Stage 4: Generate Service Config
```http
POST /routers/{id}/generate-service-config
Content-Type: application/json
Authorization: Bearer [admin-token]

{
  "enable_hotspot": true,
  "hotspot_interfaces": ["ether2", "ether3"],
  "portal_title": "Welcome",
  "login_method": "mac",
  "enable_pppoe": false
}

Response 200:
{
  "success": true,
  "service_script": "# Service configuration...",
  "stage": 4
}
```

### Stage 5: Deploy Configuration
```http
POST /routers/{id}/deploy-service-config
Content-Type: application/json
Authorization: Bearer [admin-token]

{
  "service_type": "hotspot",
  "commands": ["cmd1", "cmd2", ...]
}

Response 200:
{
  "success": true,
  "message": "Service configuration deployment started",
  "stage": 5
}
```

---

## Changelog

| Date | Version | Changes |
|------|---------|---------|
| 2025-10-05 | 1.0 | Initial implementation and documentation |

---

## Support & Troubleshooting

### Common Questions

**Q: How long does the entire provisioning process take?**
A: Typically 2-5 minutes depending on network conditions and router response time.

**Q: Can I provision multiple routers simultaneously?**
A: Yes, each router has its own job queue and can be provisioned in parallel.

**Q: What happens if I close the overlay during provisioning?**
A: The provisioning job continues in the background. You can check router status in the main router list.

**Q: Can I re-provision a router?**
A: Yes, use the "Reprovision" option from the router actions menu.

**Q: What if the router goes offline during provisioning?**
A: The job will fail and router status will be set to `failed`. You can retry deployment once the router is back online.

### Getting Help
- Check application logs: `docker logs traidnet-backend`
- Review queue status: Check `jobs` and `failed_jobs` tables
- Test connectivity manually: Use verify-connectivity endpoint
- Contact system administrator for persistent issues

---

**Document Version**: 1.0  
**Last Updated**: 2025-10-05  
**Maintainer**: WiFi Hotspot Management System Team
