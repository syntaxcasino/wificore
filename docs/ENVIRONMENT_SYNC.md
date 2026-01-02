# Environment & Docker Compose Synchronization Guide

## Overview
This document ensures all environment and docker-compose files remain synchronized across development and production environments.

## File Synchronization Matrix

### Environment Files
| Variable | .env.example | .env | .env.production | Notes |
|----------|--------------|------|-----------------|-------|
| **Application** |
| APP_NAME | Template | Dev value | Prod value | Different per environment |
| APP_ENV | production | development | production | Different per environment |
| APP_KEY | Empty | Generated | Generated | **NEVER commit actual keys** |
| APP_URL | Template | Dev URL | Prod URL | Different per environment |
| FRONTEND_URL | Template | Dev URL | Prod URL | Different per environment |
| TZ | Africa/Nairobi | Africa/Nairobi | Africa/Nairobi | **MUST be identical** |
| **Database** |
| DB_CONNECTION | pgsql | pgsql | pgsql | **MUST be identical** |
| DB_HOST | wificore-postgres | wificore-postgres | wificore-postgres | **MUST be identical** |
| DB_PORT | 5432 | 5432 | 5432 | **MUST be identical** |
| DB_DATABASE | wms_770_ts | wms_770_ts | wms_770_ts | **MUST be identical** |
| DB_USERNAME | Empty | Value | Value | **NEVER commit actual credentials** |
| DB_PASSWORD | Empty | Value | Value | **NEVER commit actual credentials** |
| DB_TIMEOUT | 5 | 5 | 5 | **MUST be identical** |
| DB_STATEMENT_TIMEOUT | 30000 | 30000 | 30000 | **MUST be identical** |
| DB_LOCK_TIMEOUT | 10000 | 10000 | 10000 | **MUST be identical** |
| DB_PERSISTENT | true | true | true | **MUST be identical** |
| **Redis** |
| REDIS_CLIENT | phpredis | phpredis | phpredis | **MUST be identical** |
| REDIS_HOST | wificore-redis | wificore-redis | wificore-redis | **MUST be identical** |
| REDIS_PORT | 6379 | 6379 | 6379 | **MUST be identical** |
| REDIS_PASSWORD | Empty | Value | Value | **NEVER commit actual passwords** |
| **RADIUS** |
| RADIUS_SERVER_HOST | wificore-freeradius | wificore-freeradius | wificore-freeradius | **MUST be identical** |
| RADIUS_SERVER_IP | 172.70.0.2 | 172.70.0.2 | 172.70.0.2 | **MUST be identical - Static IP** |
| RADIUS_SERVER_PORT | 1812 | 1812 | 1812 | **MUST be identical** |
| RADIUS_SECRET | Empty | Value | Value | **NEVER commit actual secrets** |
| **WireGuard** |
| WIREGUARD_API_KEY | Empty | Generated | Generated | **NEVER commit actual keys** |
| WIREGUARD_CONTROLLER_URL | http://172.70.255.254:8080 | http://172.70.255.254:8080 | http://172.70.255.254:8080 | **MUST be identical - Gateway IP** |
| VPN_MODE | host | host | host | **MUST be identical** |
| VPN_INTERFACE_NAME | wg0 | wg0 | wg0 | **MUST be identical** |
| VPN_SUBNET_BASE | 10.0.0.0/8 | 10.0.0.0/8 | 10.0.0.0/8 | **MUST be identical** |
| **Pusher/Soketi** |
| PUSHER_APP_ID | app-id | app-id | app-id | **MUST be identical** |
| PUSHER_APP_KEY | app-key | app-key | app-key | **MUST be identical** |
| PUSHER_APP_SECRET | app-secret | app-secret | app-secret | **MUST be identical** |
| PUSHER_HOST | wificore-soketi | wificore-soketi | wificore-soketi | **MUST be identical** |
| PUSHER_PORT | 6071 | 6071 | 6071 | **MUST be identical** |
| PUSHER_SCHEME | http | http | http | **MUST be identical** |

### Docker Compose Files

#### Static IP Assignments (MUST BE IDENTICAL)
| Service | IP Address | Purpose |
|---------|------------|---------|
| wificore-freeradius | 172.70.0.2 | **CRITICAL: Used in WireGuard iptables DNAT rules** |
| wificore-pgbouncer | 172.70.0.3 | Database connection pooler (dev only) |
| wificore-postgres | 172.70.0.4 | PostgreSQL database |
| wificore-backend | 172.70.0.5 | Laravel backend |
| wificore-soketi | 172.70.0.6 | WebSocket server |
| wificore-redis | 172.70.0.7 | Redis cache/queue |
| wificore-mongo | 172.70.0.8 | MongoDB for GenieACS |
| wificore-genieacs-cwmp | 172.70.0.9 (dev) / 172.70.0.12 (prod) | TR-069 ACS |
| wificore-nginx | 172.70.0.10 | Reverse proxy |
| wificore-frontend | 172.70.0.11 | Vue.js frontend |
| wificore-genieacs-nbi | 172.70.0.12 (dev) / 172.70.0.13 (prod) | GenieACS NBI |
| wificore-genieacs-fs | 172.70.0.13 (dev) / 172.70.0.14 (prod) | GenieACS FS |
| wificore-genieacs-ui | 172.70.0.14 (dev) / 172.70.0.15 (prod) | GenieACS UI |

#### Network Configuration (MUST BE IDENTICAL)
```yaml
networks:
  wificore-network:
    name: wificore-network
    driver: bridge
    ipam:
      driver: default
      config:
        - subnet: 172.70.0.0/16
          gateway: 172.70.255.254
```

#### WireGuard Service (MUST BE IDENTICAL)
```yaml
wificore-wireguard:
  network_mode: host  # CRITICAL: Must use host network mode
  cap_add:
    - NET_ADMIN
    - SYS_MODULE
  devices:
    - /dev/net/tun
```

## Critical Rules

### 1. Static IP Requirements
- **FreeRADIUS MUST always be 172.70.0.2** - This IP is hardcoded in WireGuard PostUp/PostDown iptables DNAT rules
- All container IPs must be static to prevent configuration drift
- Never change subnet (172.70.0.0/16) or gateway (172.70.255.254)

### 2. WireGuard Configuration
- **WIREGUARD_CONTROLLER_URL MUST be http://172.70.255.254:8080** - This is the Docker bridge gateway IP
- Backend containers (bridge network) use gateway IP to reach WireGuard controller (host network)
- **RADIUS_SERVER_IP MUST be 172.70.0.2** - Used in WireGuard iptables DNAT rules

### 3. Synchronization Workflow
When modifying configurations:
1. Update `.env.example` with new variables (no secrets)
2. Update `.env` with development values
3. Update `.env.production` on server with production values
4. Update `docker-compose.yml` with new service configurations
5. Update `docker-compose.production.yml` with identical configurations
6. Commit `.env.example`, `docker-compose.yml`, and `docker-compose.production.yml`
7. **NEVER commit `.env` or `.env.production`**

### 4. Deployment Checklist
Before deploying to production:
- [ ] Verify `.env.production` has `RADIUS_SERVER_IP=172.70.0.2`
- [ ] Verify `.env.production` has `WIREGUARD_CONTROLLER_URL=http://172.70.255.254:8080`
- [ ] Verify `docker-compose.production.yml` has all static IPs assigned
- [ ] Verify FreeRADIUS static IP is `172.70.0.2`
- [ ] Verify WireGuard service uses `network_mode: host`
- [ ] Run `docker compose -f docker-compose.production.yml config` to validate syntax

## Verification Commands

### Check Static IPs
```bash
# Verify FreeRADIUS has correct IP
docker inspect wificore-freeradius --format='{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}'
# Must output: 172.70.0.2

# Verify all container IPs
docker inspect $(docker ps -q) --format='{{.Name}}: {{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}'
```

### Check Environment Variables
```bash
# On production server
grep -E "RADIUS_SERVER_IP|WIREGUARD_CONTROLLER_URL" .env.production

# Expected output:
# RADIUS_SERVER_IP=172.70.0.2
# WIREGUARD_CONTROLLER_URL=http://172.70.255.254:8080
```

### Check WireGuard Network Mode
```bash
docker inspect wificore-wireguard --format='{{.HostConfig.NetworkMode}}'
# Must output: host
```

## Troubleshooting

### Issue: WireGuard interface not visible on host
**Cause:** Container not using host network mode
**Fix:** Ensure `network_mode: host` in docker-compose file and recreate container

### Issue: iptables DNAT fails with "Bad IP address"
**Cause:** FreeRADIUS IP changed or RADIUS_SERVER_IP incorrect
**Fix:** Verify FreeRADIUS has static IP 172.70.0.2 and RADIUS_SERVER_IP matches

### Issue: Backend cannot reach WireGuard controller
**Cause:** Incorrect WIREGUARD_CONTROLLER_URL
**Fix:** Must use gateway IP 172.70.255.254, not localhost or container name

### Issue: wg syncconf fails with "Line unrecognized"
**Cause:** Full config passed to syncconf (it only accepts peers)
**Fix:** WireGuard controller now extracts peers-only config automatically

## Last Updated
2026-01-02 - Added static IP assignments and WireGuard network mode requirements
