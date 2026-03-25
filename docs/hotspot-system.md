# Hotspot System Documentation

## Overview

The WifiCore Hotspot system provides a fully tenant-aware, event-driven captive portal solution integrated with MikroTik routers and FreeRADIUS for AAA (Authentication, Authorization, Accounting).

## Architecture

### Key Principles

1. **Zero Synchronous Router Operations**: All MikroTik operations run via queued jobs
2. **Event-Driven UI**: Real-time updates via WebSocket (no polling)
3. **Strict Tenant Isolation**: Schema-based multi-tenancy with zero cross-tenant data leakage
4. **Persistent Credentials**: User credentials persist across renewals
5. **FreeRADIUS Integration**: Session timeouts, rate limits, and access control enforced by RADIUS

### Components

```
┌─────────────────────────────────────────────────────────────────────┐
│                         Frontend (Vue.js)                           │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────────┐ │
│  │ HotspotUsers│  │ HotspotConfig│  │ Captive Portal (Public)    │ │
│  └──────┬──────┘  └──────┬──────┘  └──────────────┬──────────────┘ │
│         │                │                         │                │
│         └────────────────┼─────────────────────────┘                │
│                          │ WebSocket + REST API                     │
└──────────────────────────┼──────────────────────────────────────────┘
                           │
┌──────────────────────────┼──────────────────────────────────────────┐
│                     Backend (Laravel)                               │
│  ┌─────────────────┐  ┌─────────────────┐  ┌───────────────────┐   │
│  │ HotspotController│  │CaptivePortalCtrl│  │ HotspotRadiusService│ │
│  └────────┬────────┘  └────────┬────────┘  └─────────┬─────────┘   │
│           │                    │                      │             │
│  ┌────────┴────────────────────┴──────────────────────┴─────────┐  │
│  │                      Job Queue                                │  │
│  │  ┌──────────────────┐  ┌─────────────────┐  ┌──────────────┐ │  │
│  │  │ProvisionHotspotJob│ │GrantHotspotAccess│ │DisconnectUser│ │  │
│  │  └──────────────────┘  └─────────────────┘  └──────────────┘ │  │
│  └───────────────────────────────────────────────────────────────┘  │
└──────────────────────────┬──────────────────────────────────────────┘
                           │
┌──────────────────────────┼──────────────────────────────────────────┐
│                     Infrastructure                                   │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐                  │
│  │  MikroTik   │  │ FreeRADIUS  │  │  PostgreSQL │                  │
│  │   Router    │  │   Server    │  │  (Tenant DB)│                  │
│  └─────────────┘  └─────────────┘  └─────────────┘                  │
└─────────────────────────────────────────────────────────────────────┘
```

## Domain Events

| Event | Description | Channel |
|-------|-------------|---------|
| `HotspotProvisionRequested` | Hotspot deployment initiated | `tenant.{id}.hotspot` |
| `HotspotProvisioned` | Hotspot deployment completed | `tenant.{id}.hotspot` |
| `HotspotAccessGranted` | User access enabled (payment/renewal) | `tenant.{id}.hotspot` |
| `HotspotAccessRevoked` | User access disabled (admin action) | `tenant.{id}.hotspot` |
| `HotspotPackageExpired` | User subscription expired | `tenant.{id}.hotspot` |
| `HotspotUserCreated` | New user created after payment | `tenant.{id}.hotspot-users` |
| `HotspotUserLoginAttempted` | Login attempt (success/failure) | `tenant.{id}.hotspot` |

## Jobs

### `ProvisionHotspotJob`

Deploys Hotspot configuration to a MikroTik router via SSH.

```php
ProvisionHotspotJob::dispatch($serviceId, $routerId, $tenantId)
    ->onQueue('router-provisioning');
```

### `GrantHotspotAccessJob`

Grants access to a user by:
1. Removing `Auth-Type := Reject` from radcheck
2. Updating RADIUS reply attributes (rate limit, session timeout)
3. Updating user subscription status

```php
GrantHotspotAccessJob::dispatch($userId, $tenantId, $packageId, 'payment')
    ->onQueue('hotspot-access');
```

### `DisconnectHotspotUserJob`

Disconnects an active user session by:
1. Sending RADIUS Disconnect-Request (CoA)
2. Updating session status in database
3. Broadcasting event

```php
DisconnectHotspotUserJob::dispatch($sessionId, $tenantId, $reason, $adminId)
    ->onQueue('hotspot-sessions');
```

### `CheckHotspotExpirationsJob`

Scheduled job (runs every minute) that:
1. Finds users with expired subscriptions
2. Blocks them in RADIUS
3. Disconnects active sessions
4. Broadcasts expiration events

## API Endpoints

### Public (Captive Portal)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/portal/config` | Get portal config and packages |
| POST | `/api/portal/login` | Validate user login |
| POST | `/api/portal/payment/initiate` | Start payment process |
| GET | `/api/portal/payment/{id}/status` | Check payment status |

### Authenticated (Admin)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/hotspot/users` | List hotspot users |
| GET | `/api/hotspot/users/{id}` | Get user details |
| POST | `/api/hotspot/users/{id}/disconnect` | Disconnect user |
| POST | `/api/hotspot/users/{id}/grant-access` | Grant access |
| POST | `/api/hotspot/users/{id}/revoke-access` | Revoke access |
| GET | `/api/hotspot/sessions` | List active sessions |
| GET | `/api/hotspot/stats` | Get statistics |

## FreeRADIUS Integration

### radcheck Table

```sql
-- User credentials
INSERT INTO radcheck (username, attribute, op, value)
VALUES ('hs_abc123', 'Cleartext-Password', ':=', 'password123');

-- Block user (expired/revoked)
INSERT INTO radcheck (username, attribute, op, value)
VALUES ('hs_abc123', 'Auth-Type', ':=', 'Reject');
```

### radreply Table

```sql
-- Rate limit (upload/download)
INSERT INTO radreply (username, attribute, op, value)
VALUES ('hs_abc123', 'Mikrotik-Rate-Limit', ':=', '5M/10M');

-- Session timeout (seconds)
INSERT INTO radreply (username, attribute, op, value)
VALUES ('hs_abc123', 'Session-Timeout', ':=', '3600');

-- Idle timeout
INSERT INTO radreply (username, attribute, op, value)
VALUES ('hs_abc123', 'Idle-Timeout', ':=', '300');

-- Data limit (bytes)
INSERT INTO radreply (username, attribute, op, value)
VALUES ('hs_abc123', 'ChilliSpot-Max-Total-Octets', ':=', '1073741824');
```

## MikroTik Configuration

The Hotspot provisioning creates:

1. **Bridge** for Hotspot interfaces
2. **IP Pool** for client addresses
3. **DHCP Server** on the bridge
4. **Hotspot Server** with captive portal
5. **Hotspot Profile** with RADIUS integration
6. **RADIUS Client** configuration
7. **Firewall Rules** for access control
8. **NAT Rules** for internet access

### Example Generated Script

```routeros
/interface bridge add name=hs-bridge-{router_id} comment="Hotspot Bridge"
/interface bridge port add bridge=hs-bridge-{router_id} interface=ether2

/ip pool add name=hs-pool-{router_id} ranges=10.10.0.2-10.10.0.254
/ip address add address=10.10.0.1/24 interface=hs-bridge-{router_id}

/ip dhcp-server add name=hs-dhcp-{router_id} interface=hs-bridge-{router_id} \
    address-pool=hs-pool-{router_id} lease-time=1h disabled=no

/ip hotspot profile add name=hs-profile-{router_id} \
    hotspot-address=10.10.0.1 \
    login-by=http-chap,mac-cookie,http-pap \
    use-radius=yes

/ip hotspot add name=hs-server-{router_id} interface=hs-bridge-{router_id} \
    profile=hs-profile-{router_id} address-pool=hs-pool-{router_id} disabled=no

/radius add address={vpn_gateway_ip} service=hotspot secret={radius_secret}

/ip firewall nat add chain=srcnat action=masquerade \
    src-address=10.10.0.0/24 out-interface=ether1 comment="Hotspot NAT"
```

## Frontend Integration

### Using the Composable

```javascript
import { useHotspot } from '@/modules/tenant/composables/useHotspot'

const {
  users,
  loading,
  error,
  activeUsers,
  expiredUsers,
  fetchUsers,
  disconnectUser,
  grantAccess,
  revokeAccess,
} = useHotspot()

// Fetch initial data (WebSocket handles real-time updates)
onMounted(() => {
  fetchUsers()
})

// Disconnect a user (via queued job)
async function handleDisconnect(userId) {
  await disconnectUser(userId, 'Admin disconnect')
  // UI updates automatically via WebSocket event
}
```

### WebSocket Events

The composable automatically subscribes to:
- `tenant.{tenantId}.hotspot` - All hotspot events
- `tenant.{tenantId}.hotspot-users` - User creation events

## Payment Flow

```
┌──────────┐     ┌─────────────┐     ┌──────────┐     ┌─────────────┐
│  Client  │────▶│Captive Portal│────▶│  MPesa   │────▶│  Callback   │
└──────────┘     └─────────────┘     └──────────┘     └──────────────┘
                        │                                     │
                        │                                     ▼
                        │                            ┌─────────────────┐
                        │                            │CreateHotspotUser│
                        │                            │      Job        │
                        │                            └────────┬────────┘
                        │                                     │
                        │◀────────WebSocket Event─────────────┘
                        │
                        ▼
                ┌─────────────────┐
                │ Display Login   │
                │  Credentials    │
                └─────────────────┘
```

1. Client selects package on captive portal
2. Payment initiated via MPesa STK Push
3. MPesa callback confirms payment
4. `CreateHotspotUserJob` creates user and RADIUS entries
5. `HotspotUserCreated` event broadcasts credentials
6. Client logs in with credentials

## Security Considerations

1. **Tenant Isolation**: All queries use `TenantScope`
2. **RADIUS Secret**: Unique per router, encrypted in database
3. **Password Storage**: Cleartext in radcheck (required by RADIUS)
4. **WebSocket Auth**: Channels require authenticated admin
5. **Rate Limiting**: All public endpoints rate-limited

## Troubleshooting

### User Cannot Login

1. Check radcheck for `Auth-Type := Reject`
2. Verify subscription hasn't expired
3. Check RADIUS logs: `docker logs wificore-freeradius`

### Session Not Disconnecting

1. Verify RADIUS CoA is configured on router
2. Check CoA port (3799) is accessible
3. Review job queue: `php artisan queue:work hotspot-sessions`

### WebSocket Not Updating

1. Verify Soketi is running: `docker logs wificore-soketi`
2. Check channel authorization in `routes/channels.php`
3. Verify tenant context in frontend

## Queue Configuration

Add these queues to your queue worker:

```bash
php artisan queue:work --queue=router-provisioning,hotspot-sessions,hotspot-access,hotspot-expirations
```

Or in Supervisor:

```ini
[program:wificore-hotspot-worker]
command=php artisan queue:work --queue=hotspot-sessions,hotspot-access,hotspot-expirations --sleep=3 --tries=3
directory=/var/www/html
autostart=true
autorestart=true
numprocs=2
```
