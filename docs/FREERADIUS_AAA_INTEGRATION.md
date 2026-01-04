# FreeRADIUS AAA Integration Guide

## Overview

The WiFiCore system uses FreeRADIUS for Authentication, Authorization, and Accounting (AAA) of Hotspot and PPPoE users. All routers connect to the central FreeRADIUS server via WireGuard VPN tunnels.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     WiFiCore Platform                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │   Backend    │  │  FreeRADIUS  │  │  PostgreSQL  │      │
│  │   Laravel    │  │   Container  │  │   Database   │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
│         │                 │                   │              │
│         └─────────────────┴───────────────────┘              │
│                           │                                  │
│                  ┌────────▼────────┐                        │
│                  │  WireGuard VPN  │                        │
│                  │   wg0 (10.X.0.1)│                        │
│                  └────────┬────────┘                        │
└───────────────────────────┼─────────────────────────────────┘
                            │
                ┌───────────┴───────────┐
                │                       │
         ┌──────▼──────┐         ┌─────▼──────┐
         │  Router A   │         │  Router B  │
         │ 10.100.1.1  │         │ 10.100.1.2 │
         └─────────────┘         └────────────┘
```

## How It Works

### 1. VPN-Based AAA

Each router connects to the WiFiCore platform via WireGuard VPN:
- **Router VPN IP**: `10.X.Y.Z/32` (unique per router)
- **VPN Gateway**: `10.X.0.1` (tenant-specific)
- **RADIUS Server**: Accessible via VPN gateway IP

### 2. Automatic Configuration

When a router is created, the system automatically:

1. **Allocates VPN IP** from tenant subnet
2. **Generates WireGuard keys** (private, public, preshared)
3. **Configures VPN peer** on server
4. **Generates MikroTik script** with RADIUS settings

### 3. RADIUS Configuration in Router Script

The `.rsc` file downloaded by MikroTik includes:

```routeros
# WireGuard VPN Setup
/interface wireguard add name=wg-xxxxx listen-port=51830 private-key="..."
/ip address add address=10.100.1.1/32 interface=wg-xxxxx
/interface wireguard peers add interface=wg-xxxxx public-key="..." preshared-key="..." endpoint-address=144.91.71.208 endpoint-port=51830 allowed-address=0.0.0.0/0 persistent-keepalive=00:00:25
/ip firewall filter add chain=input action=accept protocol=udp dst-port=51830 comment="Allow WireGuard VPN"
```

### 4. Hotspot RADIUS Configuration

For Hotspot services, the system generates:

```routeros
/ip hotspot profile set [find name="hotspot-profile"] use-radius=yes
/radius add service=hotspot address=10.X.0.1 secret="<tenant-radius-secret>" timeout=3s
/radius add service=hotspot address=10.X.0.1 secret="<tenant-radius-secret>" timeout=3s
```

### 5. PPPoE RADIUS Configuration

For PPPoE services, the system generates:

```routeros
/ppp profile set [find name="pppoe-profile"] use-radius=yes
/radius add service=ppp address=10.X.0.1 secret="<tenant-radius-secret>" timeout=3s
/radius add service=ppp address=10.X.0.1 secret="<tenant-radius-secret>" timeout=3s
```

## Database Schema

### Users Table (Tenant Schema)

```sql
CREATE TABLE users (
    id UUID PRIMARY KEY,
    username VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,  -- Hashed
    email VARCHAR(255),
    phone VARCHAR(50),
    package_id UUID REFERENCES packages(id),
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Packages Table (Tenant Schema)

```sql
CREATE TABLE packages (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    bandwidth_upload VARCHAR(50),    -- e.g., "10M"
    bandwidth_download VARCHAR(50),  -- e.g., "20M"
    session_timeout INTEGER,         -- seconds
    idle_timeout INTEGER,            -- seconds
    price DECIMAL(10,2),
    validity_days INTEGER,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### RADIUS Accounting Table (Tenant Schema)

```sql
CREATE TABLE radacct (
    radacctid BIGSERIAL PRIMARY KEY,
    acctsessionid VARCHAR(64) NOT NULL,
    acctuniqueid VARCHAR(32) NOT NULL UNIQUE,
    username VARCHAR(64),
    nasipaddress INET NOT NULL,
    nasportid VARCHAR(15),
    nasporttype VARCHAR(32),
    acctstarttime TIMESTAMP,
    acctstoptime TIMESTAMP,
    acctsessiontime BIGINT,
    acctinputoctets BIGINT,
    acctoutputoctets BIGINT,
    calledstationid VARCHAR(50),
    callingstationid VARCHAR(50),
    acctterminatecause VARCHAR(32),
    framedipaddress INET,
    framedprotocol VARCHAR(32)
);
```

## FreeRADIUS Configuration

### 1. Multi-Tenancy Support

FreeRADIUS is configured to use PostgreSQL with schema-based multi-tenancy:

```conf
# /etc/freeradius/3.0/mods-available/sql
sql {
    driver = "rlm_sql_postgresql"
    dialect = "postgresql"
    
    server = "wificore-postgres"
    port = 5432
    login = "${POSTGRES_USER}"
    password = "${POSTGRES_PASSWORD}"
    
    # Dynamic schema selection based on NAS-IP-Address
    radius_db = "SELECT get_tenant_schema_from_nas('${NAS-IP-Address}')"
}
```

### 2. Authorization Query

```sql
-- Authenticate user and get package attributes
SELECT 
    u.id,
    u.username,
    u.password,
    p.bandwidth_upload AS "Mikrotik-Rate-Limit",
    p.session_timeout AS "Session-Timeout",
    p.idle_timeout AS "Idle-Timeout"
FROM users u
LEFT JOIN packages p ON u.package_id = p.id
WHERE u.username = '%{SQL-User-Name}'
    AND u.status = 'active'
LIMIT 1;
```

### 3. Accounting Queries

**Start:**
```sql
INSERT INTO radacct (
    acctsessionid, acctuniqueid, username, nasipaddress,
    nasportid, nasporttype, acctstarttime, framedipaddress
) VALUES (
    '%{Acct-Session-Id}', '%{Acct-Unique-Session-Id}',
    '%{SQL-User-Name}', '%{NAS-IP-Address}',
    '%{NAS-Port}', '%{NAS-Port-Type}', NOW(),
    '%{Framed-IP-Address}'
);
```

**Update:**
```sql
UPDATE radacct SET
    acctsessiontime = '%{Acct-Session-Time}',
    acctinputoctets = '%{Acct-Input-Octets}',
    acctoutputoctets = '%{Acct-Output-Octets}'
WHERE acctsessionid = '%{Acct-Session-Id}'
    AND username = '%{SQL-User-Name}'
    AND nasipaddress = '%{NAS-IP-Address}';
```

**Stop:**
```sql
UPDATE radacct SET
    acctstoptime = NOW(),
    acctsessiontime = '%{Acct-Session-Time}',
    acctinputoctets = '%{Acct-Input-Octets}',
    acctoutputoctets = '%{Acct-Output-Octets}',
    acctterminatecause = '%{Acct-Terminate-Cause}'
WHERE acctsessionid = '%{Acct-Session-Id}'
    AND username = '%{SQL-User-Name}'
    AND nasipaddress = '%{NAS-IP-Address}';
```

## Integration Steps

### 1. Create Router with VPN

```bash
POST /api/routers
{
    "name": "Router-01"
}
```

**Response includes:**
```json
{
    "id": "uuid",
    "name": "Router-01",
    "vpn_ip": "10.100.1.1",
    "connectivity_script": "/tool fetch mode=https url=\"...\" dst-path=config.rsc keep-result=yes check-certificate=no; :delay 5s; /import config.rsc"
}
```

### 2. Deploy Configuration to MikroTik

Paste the `connectivity_script` into MikroTik terminal. The router will:
1. Download `config.rsc` file
2. Import all configurations (VPN, services, firewall)
3. Connect to VPN
4. Be ready for RADIUS authentication

### 3. Create User Package

```bash
POST /api/packages
{
    "name": "Basic 10Mbps",
    "bandwidth_upload": "10M",
    "bandwidth_download": "10M",
    "session_timeout": 86400,
    "idle_timeout": 600,
    "price": 500.00,
    "validity_days": 30
}
```

### 4. Create User

```bash
POST /api/users
{
    "username": "user001",
    "password": "password123",
    "email": "user@example.com",
    "package_id": "package-uuid"
}
```

### 5. Configure Hotspot/PPPoE on Router

The system automatically generates service configurations with RADIUS settings pointing to the VPN gateway IP.

## Testing RADIUS Authentication

### 1. Test from Command Line

```bash
# On the WiFiCore server
docker exec -it wificore-freeradius radtest user001 password123 10.100.1.1 0 testing123
```

**Expected Output:**
```
Sent Access-Request Id 1 from 0.0.0.0:xxxxx to 10.100.1.1:1812 length 77
Received Access-Accept Id 1 from 10.100.1.1:1812 to 0.0.0.0:xxxxx length 44
```

### 2. Test from MikroTik

```routeros
/radius incoming print
/log print where topics~"radius"
```

### 3. Monitor Active Sessions

```bash
GET /api/sessions
```

Returns all active RADIUS sessions with:
- Username
- Router (NAS)
- IP Address
- Session time
- Data usage

## Troubleshooting

### Issue: Router cannot reach RADIUS server

**Check:**
1. VPN connection status: `/interface wireguard print`
2. VPN peer status: `/interface wireguard peers print detail`
3. Ping VPN gateway: `/ping 10.X.0.1 count=4`

**Fix:**
- Ensure WireGuard interface is up
- Check firewall rules allow UDP 51830
- Verify peer configuration

### Issue: Authentication fails

**Check:**
1. User exists and is active: `GET /api/users`
2. Package is assigned: Check `package_id` in user record
3. RADIUS logs: `docker logs wificore-freeradius`

**Fix:**
- Verify username/password
- Check user status is 'active'
- Ensure package has valid attributes

### Issue: Accounting not working

**Check:**
1. RADIUS accounting logs: `docker logs wificore-freeradius | grep Acct`
2. Database records: `SELECT * FROM radacct ORDER BY acctstarttime DESC LIMIT 10`

**Fix:**
- Verify router is sending accounting packets
- Check database permissions
- Ensure schema is correct for tenant

## Security Considerations

### 1. VPN Encryption

- All RADIUS traffic is encrypted via WireGuard
- Preshared keys add post-quantum security
- Each router has unique keys

### 2. RADIUS Secrets

- Tenant-specific RADIUS secrets
- Stored encrypted in database
- Automatically rotated on tenant creation

### 3. Password Hashing

- User passwords are bcrypt hashed
- Never stored in plaintext
- RADIUS uses challenge-response when possible

### 4. Firewall Rules

- Only VPN traffic allowed to RADIUS ports
- Inter-tenant traffic blocked
- Rate limiting on authentication attempts

## Performance Optimization

### 1. Connection Pooling

FreeRADIUS uses PostgreSQL connection pooling:
```conf
pool {
    start = 5
    min = 4
    max = 20
    spare = 3
    uses = 0
    lifetime = 0
    idle_timeout = 60
}
```

### 2. Caching

- Authorization results cached for 300s
- Reduces database load
- Improves response time

### 3. Load Balancing

Multiple RADIUS servers can be configured:
```routeros
/radius add service=hotspot address=10.X.0.1 secret="..." timeout=3s
/radius add service=hotspot address=10.X.0.2 secret="..." timeout=3s
```

## Monitoring

### 1. RADIUS Metrics

```bash
# View RADIUS statistics
docker exec -it wificore-freeradius radmin -e "stats client 10.100.1.1"
```

### 2. Active Sessions

```bash
GET /api/sessions/active
```

### 3. Accounting Data

```bash
GET /api/reports/usage?start_date=2026-01-01&end_date=2026-01-31
```

## API Endpoints

### Users
- `GET /api/users` - List all users
- `POST /api/users` - Create user
- `GET /api/users/{id}` - Get user details
- `PUT /api/users/{id}` - Update user
- `DELETE /api/users/{id}` - Delete user

### Packages
- `GET /api/packages` - List all packages
- `POST /api/packages` - Create package
- `GET /api/packages/{id}` - Get package details
- `PUT /api/packages/{id}` - Update package
- `DELETE /api/packages/{id}` - Delete package

### Sessions
- `GET /api/sessions` - List all sessions
- `GET /api/sessions/active` - List active sessions
- `POST /api/sessions/{id}/disconnect` - Disconnect session

### Routers
- `GET /api/routers` - List all routers
- `POST /api/routers` - Create router (auto-configures VPN + RADIUS)
- `GET /api/routers/{id}` - Get router details

## Summary

The FreeRADIUS AAA integration is **fully automated**:

1. ✅ **Create router** → VPN + RADIUS auto-configured
2. ✅ **Create package** → Bandwidth limits defined
3. ✅ **Create user** → Assigned to package
4. ✅ **User authenticates** → RADIUS validates via VPN
5. ✅ **Session tracked** → Accounting data stored
6. ✅ **Monitor usage** → Real-time statistics available

**No manual RADIUS configuration needed on routers!**
