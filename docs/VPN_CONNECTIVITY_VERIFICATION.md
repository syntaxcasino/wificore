# VPN Connectivity Verification System

## Overview

The VPN Connectivity Verification system ensures that VPN tunnels are fully operational before proceeding with router provisioning. It performs actual ping tests from the server to the router's VPN IP to verify bidirectional connectivity.

## Architecture

### Components

1. **VpnConnectivityService** (`backend/app/Services/VpnConnectivityService.php`)
   - Core service that performs ping tests
   - Parses ping output for latency and packet loss
   - Provides retry logic with configurable timeouts

2. **API Endpoints** (`backend/app/Http/Controllers/Api/VpnConfigurationController.php`)
   - `POST /api/vpn/{id}/verify-connectivity` - Quick connectivity check
   - `POST /api/vpn/{id}/wait-connectivity` - Wait for connectivity with retries

3. **Frontend Integration** (To be implemented)
   - Poll connectivity status after router applies configuration
   - Display real-time connectivity status to user
   - Block progression until connectivity verified

## API Usage

### Quick Connectivity Check

**Endpoint:** `POST /api/vpn/{id}/verify-connectivity`

**Description:** Performs a single ping test to verify if the router is reachable via VPN.

**Request:**
```bash
curl -X POST https://wificore.traidsolutions.com/api/vpn/123/verify-connectivity \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json"
```

**Response:**
```json
{
  "success": true,
  "data": {
    "config_id": 123,
    "router_id": "2d1c3455-4a4c-42bf-b57e-3fd998695305",
    "client_ip": "10.100.1.1",
    "connectivity": {
      "reachable": true,
      "packet_loss": 0,
      "latency_ms": 165.5,
      "status": "connected"
    },
    "message": "VPN connectivity verified"
  }
}
```

**Response (Failed):**
```json
{
  "success": false,
  "data": {
    "config_id": 123,
    "router_id": "2d1c3455-4a4c-42bf-b57e-3fd998695305",
    "client_ip": "10.100.1.1",
    "connectivity": {
      "reachable": false,
      "packet_loss": 100,
      "latency_ms": null,
      "status": "disconnected"
    },
    "message": "Ping failed"
  }
}
```

### Wait for Connectivity (Recommended)

**Endpoint:** `POST /api/vpn/{id}/wait-connectivity`

**Description:** Polls for connectivity with automatic retries until successful or timeout.

**Request:**
```bash
curl -X POST https://wificore.traidsolutions.com/api/vpn/123/wait-connectivity \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "max_wait_seconds": 120,
    "retry_interval": 5
  }'
```

**Parameters:**
- `max_wait_seconds` (optional): Maximum time to wait (10-300 seconds, default: 120)
- `retry_interval` (optional): Seconds between retries (2-30 seconds, default: 5)

**Response (Success):**
```json
{
  "success": true,
  "data": {
    "config_id": 123,
    "router_id": "2d1c3455-4a4c-42bf-b57e-3fd998695305",
    "client_ip": "10.100.1.1",
    "connectivity": {
      "reachable": true,
      "packet_loss": 0,
      "latency_ms": 165.5,
      "status": "connected",
      "attempts": 8
    },
    "message": "VPN connectivity verified"
  }
}
```

**Response (Timeout):**
```json
{
  "success": false,
  "data": {
    "config_id": 123,
    "router_id": "2d1c3455-4a4c-42bf-b57e-3fd998695305",
    "client_ip": "10.100.1.1",
    "connectivity": {
      "reachable": false,
      "packet_loss": 100,
      "latency_ms": null,
      "status": "timeout",
      "attempts": 24
    },
    "message": "VPN connectivity timeout - router did not respond within 120 seconds"
  }
}
```

## Integration Flow

### Recommended Provisioning Flow

```
1. User creates router
   └─> Backend creates router + VPN config
   └─> Returns fetch command

2. User applies fetch command on MikroTik
   └─> Router downloads and applies configuration
   └─> WireGuard tunnel establishes

3. Frontend polls connectivity
   └─> POST /api/vpn/{id}/wait-connectivity
   └─> Backend pings router VPN IP every 5 seconds
   └─> Returns when connectivity verified (0% packet loss)

4. If connectivity verified:
   └─> Frontend proceeds to next step
   └─> User can configure services

5. If connectivity timeout:
   └─> Frontend shows error message
   └─> Provides troubleshooting steps
   └─> Allows retry
```

### Frontend Implementation Example

```javascript
async function verifyVpnConnectivity(vpnConfigId) {
  try {
    // Show loading state
    showConnectivityCheck('Verifying VPN connectivity...')
    
    // Wait for connectivity with 2-minute timeout
    const response = await axios.post(`/api/vpn/${vpnConfigId}/wait-connectivity`, {
      max_wait_seconds: 120,
      retry_interval: 5
    })
    
    if (response.data.success) {
      // Connectivity verified
      showSuccess(`VPN connected! Latency: ${response.data.data.connectivity.latency_ms}ms`)
      return true
    } else {
      // Connectivity failed
      showError(response.data.data.message)
      return false
    }
  } catch (error) {
    showError('Failed to verify VPN connectivity')
    return false
  }
}

// Usage in provisioning flow
async function continueToNextStep() {
  const vpnConfigId = router.vpn_config_id
  
  // Verify connectivity before proceeding
  const isConnected = await verifyVpnConnectivity(vpnConfigId)
  
  if (!isConnected) {
    // Block progression
    addLog('error', 'VPN connectivity verification failed. Please check router configuration.')
    return
  }
  
  // Proceed to next step
  currentStage.value = 3
  addLog('success', 'VPN connectivity verified. Proceeding...')
}
```

## Troubleshooting

### Common Issues

#### 1. Connectivity Timeout
**Symptom:** `wait-connectivity` returns timeout after max_wait_seconds

**Possible Causes:**
- Router hasn't applied configuration yet
- Firewall blocking WireGuard port (51830/UDP)
- Incorrect server endpoint in router config
- Network connectivity issues

**Solutions:**
- Verify router applied the fetch command
- Check firewall rules on server: `sudo iptables -L INPUT -v -n | grep 51830`
- Verify WireGuard interface is up: `sudo wg show wg0`
- Check server can reach router's public IP

#### 2. High Packet Loss
**Symptom:** Connectivity verified but packet_loss > 0%

**Possible Causes:**
- Network congestion
- Unstable connection
- MTU issues

**Solutions:**
- Check network quality
- Adjust MTU if needed (default: 1420)
- Monitor with: `watch -n 1 'sudo wg show wg0'`

#### 3. No VPN Config Found
**Symptom:** 404 error when calling endpoints

**Possible Causes:**
- VPN config not created
- Wrong config ID
- Tenant isolation issue

**Solutions:**
- Verify VPN config exists: `GET /api/vpn`
- Check router has VPN config: `GET /api/routers/{id}`
- Verify tenant context is correct

## Backend Implementation Details

### Ping Command
```bash
ping -c 4 -W 5 10.100.1.1
```
- `-c 4`: Send 4 packets
- `-W 5`: 5-second timeout per packet
- Returns exit code 0 if at least one packet received

### Status Updates
The service automatically updates VPN configuration status:
- `connected`: 0% packet loss
- `disconnected`: 100% packet loss
- `last_handshake_at`: Updated on successful ping

### Logging
All connectivity checks are logged with:
- Config ID and Router ID
- Client IP being tested
- Result (success/failure)
- Latency and packet loss
- Timestamp

**Example Log:**
```
[2026-01-04 18:30:15] VPN connectivity verification requested
  config_id: 123
  router_id: 2d1c3455-4a4c-42bf-b57e-3fd998695305
  client_ip: 10.100.1.1

[2026-01-04 18:30:20] VPN connectivity established
  router_id: 2d1c3455-4a4c-42bf-b57e-3fd998695305
  attempt: 2
  elapsed_seconds: 10
  latency: 165.5ms
```

## Security Considerations

1. **Authentication Required:** All endpoints require valid Sanctum token
2. **Tenant Isolation:** VPN configs are automatically scoped to tenant
3. **Rate Limiting:** Consider adding rate limits to prevent abuse
4. **Timeout Limits:** Max wait time capped at 300 seconds (5 minutes)

## Performance

- **Quick Check:** ~5 seconds (4 pings with 5s timeout)
- **Wait with Retries:** Up to max_wait_seconds (default: 120s)
- **Resource Usage:** Minimal (single ping process)
- **Concurrent Checks:** Supported (each check is independent)

## Future Enhancements

1. **Bidirectional Testing:** Test router → server connectivity
2. **Bandwidth Testing:** Measure throughput
3. **Latency Monitoring:** Track latency over time
4. **Automatic Remediation:** Auto-fix common issues
5. **WebSocket Updates:** Real-time connectivity status
6. **Historical Data:** Store connectivity metrics for analytics
