# Telegraf Router Metrics Setup Guide

## Overview

This guide covers the complete setup of Telegraf-based router metrics collection using SNMP polling and VictoriaMetrics storage.

## Architecture

```
Routers (SNMP) → Telegraf → VictoriaMetrics → Backend API → Frontend
```

### Components

1. **VictoriaMetrics** - Time-series database for metrics storage
2. **Telegraf Config Generator** - Laravel service that generates SNMP polling configs
3. **Telegraf** - SNMP poller that collects metrics from routers
4. **Backend API** - Queries VictoriaMetrics and serves metrics to frontend
5. **Frontend** - Displays router metrics (CPU, Memory, Disk, Traffic)

## Prerequisites

- Docker and Docker Compose
- Routers with SNMP enabled (v2c or v3)
- Network connectivity between Telegraf and routers (preferably via VPN)

## Deployment Steps

### 1. Add Services to Production

The following services have been added to `docker-compose.production.yml`:

- `wificore-victoriametrics` (172.70.0.21)
- `wificore-telegraf-config` (172.70.0.22)
- `wificore-telegraf` (172.70.0.20)

### 2. Configure Environment Variables

Add to `.env.production`:

```bash
# Telegraf Configuration
TELEGRAF_SHARD_INDEX=0
TELEGRAF_SHARD_COUNT=1
TELEGRAF_FAST_INTERVAL=3s
TELEGRAF_SLOW_INTERVAL=30s

# SNMPv3 Credentials (REQUIRED - generate secure passwords)
TELEGRAF_SNMPV3_USER=snmpmonitor
TELEGRAF_SNMPV3_AUTH_PASSWORD=<generate-secure-32-char-password>
TELEGRAF_SNMPV3_PRIV_PASSWORD=<generate-secure-32-char-password>

# VictoriaMetrics
VICTORIA_METRICS_WRITE_URL=http://wificore-nginx/internal/vm/api/v1/write
VICTORIA_METRICS_Qv3UERY_URL=http://wificore-nginx/internal/vm
VICTORIA_METRICS_HTTP_TIMEOUT=5
**IMPORTANT:** SNMP is **NOT** included in the bootstrap script (`/tool fetch ... /import config.rsc`) to avoid conflicts. SNMP is configured separately via the provisioning service.

```

**Gev3neraecure passwords:**
```bash
# Generate auth password
openssl rand -hex 16

# Generate priv password
openssl rand -her move6
/snmpcommunity  name=snmpmonito addrscurityrivate athentatio`-rotocol=SHA256authnicaion-pswrd"<ecure-assword>"ncytionprotol=aes ecrton-password="<seure-password>"
### 3. Enable SNMP on Routers

#### Automatic (via provisioning)

SNMP is now automatically enablev3d when you provision a router through the system. The provisioning script includes:

```routeros
/snmp set enabled=yes
/snmp set contact="Network Admin"
/snmp set location="Managed by WifiCore"
:do { /snmp community remove [find name=traidnet-monitor]; } on-error={}
/snmp community add name=traidnet-monitor addresses=<TENANT_VPN_SUBNET> security=none read-access=yes write-access=no
/snmp set trap-community=traidnet-monitor trap-version=2
```
remov
/snmp community add name=snmpmonitor security=private authentication-protocol=SHA256 authentication-password="YourAuthPassword" encryption-protocol=aes encryption-password="YourPrivPassword"
#### Manual (for existing routers)

Run the migration to enable SNMP on existing routers:

```bash
php artisan migrate --path=database/migrations/tenant/2026_01_28_000001_enable_snmp_on_existing_routers.php
```

Or manually enable via SSH:

```routeros
/snmp set enabled=yes
:do { /snmp community remove [find name=traidnet-monitor]; } on-error={}
/snmp community add name=traidnet-monitor addresses=<TENANT_VPN_SUBNET> security=none read-access=yes write-access=no
/snmp set trap-community=traidnet-monitor trap-version=2
```

### 4. Deploy Services

```bash
# On production server
cd /path/to/wificore

# Pull latest images
docker-compose -f docker-compose.production.yml pull

# Start services
docker-compose -f docker-compose.production.yml up -d wificore-victoriametrics wificore-telegraf-config wificore-telegraf

# Verify services are running
docker-compose -f docker-compose.production.yml ps | grep -E "(victoriametrics|telegraf)"
```

### 5. Verify Telegraf Configuration

Check that Telegraf config is being generated:

```bash
# Check config generator logs
docker logs wificore-telegraf-config --tail 50

# Verify config file exists
docker exec wificore-telegraf-config ls -lh /var/www/html/storage/app/telegraf/shards/

# View generated config
docker exec wificore-telegraf-config cat /var/www/html/storage/app/telegraf/shards/0.conf
```

### 6. Verify Telegraf is Polling

```bash
# Check Telegraf logs
docker logs wificore-telegraf --tail 100

# Should see SNMP polling activity
# Look for: "Gathered metrics" or "Writing batch"
```

### 7. Verify Metrics in VictoriaMetrics

```bash
# Query VictoriaMetrics directly
curl -s "http://localhost/internal/vm/api/v1/query?query=router_health_cpu_load" | jq .

# Should return metrics data for routers
```

### 8. Test Frontend Display

1. Navigate to **Routers** page
2. Click on a router to view details
3. Metrics should display:
   - **CPU Load** (percentage)
   - **Memory** (total/free)
   - **Disk** (total/free)
   - **Traffic Graph** (download/upload)

## Troubleshooting

### No Metrics Showing

**Check 1: SNMP Enabled on Router**

```bash
# Via backend container
docker exec -it wificore-backend php artisan tinker

# In tinker:
$router = App\Models\Router::find('router-id-here');
echo $router->snmp_enabled ? 'Enabled' : 'Disabled';
```

**Check 2: Telegraf Config Generated**

```bash
docker exec wificore-telegraf-config cat /var/www/html/storage/app/telegraf/shards/0.conf | grep "router_id"
```

Should show your router IDs.

**Check 3: Telegraf Connectivity**

```bash
# Test SNMP from Telegraf container
docker exec wificore-telegraf snmpwalk -v2c -c public <router-vpn-ip> 1.3.6.1.2.1.1.5.0
```

Should return router identity.

**Check 4: VictoriaMetrics Data**

```bash
# Query for specific router
curl -s "http://localhost/internal/vm/api/v1/query?query=router_health_cpu_load{router_id=\"<router-id>\"}" | jq .
```

**Check 5: Backend API**

```bash
# Test metrics API endpoint
curl -H "Authorization: Bearer <token>" \
     -H "X-Tenant-ID: <tenant-id>" \
     "http://localhost/api/routers/<router-id>/metrics/live"
```

### Telegraf Not Starting

```bash
# Check config file exists
docker exec wificore-telegraf-config test -f /var/www/html/storage/app/telegraf/shards/0.conf && echo "Config exists" || echo "Config missing"

# Check Telegraf logs
docker logs wificore-telegraf --tail 100
```

### VictoriaMetrics Not Receiving Data

```bash
# Check Telegraf output plugin
docker logs wificore-telegraf 2>&1 | grep -i "error\|fail"

# Check VictoriaMetrics logs
docker logs wificore-victoriametrics --tail 100

# Verify Nginx proxy
curl -I http://localhost/internal/vm/api/v1/write
```

## Performance Tuning

### Polling Intervals

Adjust in `.env.production`:

```bash
# Fast interval for interface counters (traffic)
TELEGRAF_FAST_INTERVAL=3s

# Slow interval for health metrics (CPU, memory)
TELEGRAF_SLOW_INTERVAL=30s
```

### Sharding (for large deployments)

If you have many routers (>100), use sharding:

```bash
# Run multiple Telegraf instances
TELEGRAF_SHARD_COUNT=3

# Instance 1
TELEGRAF_SHARD_INDEX=0

# Instance 2
TELEGRAF_SHARD_INDEX=1

# Instance 3
TELEGRAF_SHARD_INDEX=2
```

### VictoriaMetrics Retention

Adjust retention period (default 12 months):

```yaml
wificore-victoriametrics:
  command:
    - '-storageDataPath=/victoria-metrics-data'
    - '-retentionPeriod=6'  # 6 months
```

## Metrics Collected

### Router Health (`router_health`)

- `cpu_load` - CPU usage percentage
- `total_memory` - Total RAM in bytes
- `free_memory` - Free RAM in bytes
- `uptime_ticks` - System uptime in timeticks
- `identity` - Router identity/hostname

### Storage (`router_storage`)

- `hrStorageSize` - Storage size in allocation units
- `hrStorageUsed` - Storage used in allocation units
- `hrStorageAllocationUnits` - Size of allocation unit

### Interface Counters (`interface_counters`)

- `ifHCInOctets` - Bytes received (64-bit counter)
- `ifHCOutOctets` - Bytes transmitted (64-bit counter)
- `ifName` - Interface name

## API Endpoints

### Single Router Metrics

```
GET /api/routers/{router}/metrics/live
```

Returns current CPU, memory, disk metrics.

### Batch Router Metrics

```
POST /api/routers/metrics/live
Body: {"rv3oReq_dred: ["Syst m",ses SNMPv3 wi.h.SHA256 a}thentationand AES enryptin
2. **Secre Credetals**: Generaestrog32-chaacter passwrs for ah and prv
3``
4
5eturNo Bootstrap ns mics mult is NOT in bootstrap script to preientlconfl crs
6.u**Separtr. Cofgur**: SNMPconigued viaprovisioig serviaft bootsrap

### Traffic Range Query

```
GET /api/routers/{router}/metrics/traffic?range=1h&step=30s
```

Returns time-series traffic data.

## Security Notes

1. **SNMP Community**: Change default `public` community in production
2. **Network Isolation**: Telegraf runs in Docker network `172.70.0.0/16`
3. **VictoriaMetrics Access**: Only accessible via Nginx internal proxy
4. **SNMP v3**: Use SNMPv3 with authentication for enhanced security

## Monitoring

### Health Checks

```bash
# VictoriaMetrics
curl http://localhost/internal/vm/health

# Telegraf config generator
docker exec wificore-telegraf-config test -f /var/www/html/storage/app/telegraf/shards/0.conf

# Telegraf process
docker exec wificore-telegraf pgrep telegraf
```

### Metrics

Monitor these metrics in VictoriaMetrics:

- `telegraf_internal_gather_metrics_total` - Metrics gathered
- `telegraf_internal_write_metrics_total` - Metrics written
- `vm_rows` - Total rows in VictoriaMetrics

## Maintenance

### Regenerate Telegraf Config

```bash
# Manually trigger config regeneration
docker exec wificore-backend php artisan telegraf:generate-config
```

### Clear VictoriaMetrics Data

```bash
# Stop VictoriaMetrics
docker-compose -f docker-compose.production.yml stop wificore-victoriametrics

# Remove data volume
docker volume rm wificore-victoriametrics-data

# Restart
docker-compose -f docker-compose.production.yml up -d wificore-victoriametrics
```

## Support

For issues or questions:
1. Check logs: `docker-compose -f docker-compose.production.yml logs <service-name>`
2. Verify network connectivity between services
3. Ensure routers are reachable via VPN IP addresses
4. Check SNMP is enabled on routers

## References

- [Telegraf Documentation](https://docs.influxdata.com/telegraf/)
- [VictoriaMetrics Documentation](https://docs.victoriametrics.com/)
- [MikroTik SNMP Guide](https://wiki.mikrotik.com/wiki/Manual:SNMP)
