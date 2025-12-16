# Port Mapping Matrix - Wificore

This document confirms the port mappings for all services in the wificore stack.

## External Port Mappings (Host:Container)

| Service | External Port(s) | Internal Port(s) | Protocol | Status | Access |
|---------|-----------------|------------------|----------|--------|--------|
| **nginx** | 8070 | 80 | TCP | ✅ Configured | External |
| **soketi** | - | 6071, 9670 | TCP | ✅ Configured | Internal Only |
| **freeradius** | - | 1812-1813 | UDP | ✅ Configured | Internal Only |
| **postgres** | - | 5432 | TCP | ✅ Configured | Internal Only |
| **redis** | - | 6379 | TCP | ✅ Configured | Internal Only |
| **backend** | - | 9000 | TCP | ✅ Configured | Internal Only |
| **frontend** | - | 80 | TCP | ✅ Configured | Internal Only |

## Database Configuration

| Parameter | Value | Status |
|-----------|-------|--------|
| **Database Name** | wms_770_ts | ✅ Configured |
| **Database User** | admin | ✅ Configured |
| **Database Password** | secret | ✅ Configured |

## Service URLs

### External Access (from host machine)
- **Frontend/Nginx**: http://localhost:8070 (ONLY externally accessible service)

### Internal Services (NOT accessible from host)
- **Soketi WebSocket**: Internal only - accessed via nginx proxy
- **FreeRADIUS**: Internal only - backend connects internally
- **PostgreSQL**: Internal only - backend connects internally
- **Redis**: Internal only - backend connects internally

### Internal Access (container-to-container)
- **Frontend**: http://wificore-frontend:80
- **Backend**: http://wificore-backend:9000
- **Soketi**: http://wificore-soketi:6071
- **FreeRADIUS**: wificore-freeradius:1812 (UDP)
- **PostgreSQL**: wificore-postgres:5432
- **Redis**: wificore-redis:6379

## Important Notes

1. **Security Best Practice**: Only nginx (port 8070) is exposed externally. All other services are internal-only for security.

2. **FreeRADIUS**: 
   - Internal ports: 1812 (Authentication), 1813 (Accounting)
   - Backend connects internally using port 1812
   - No external access required

3. **Soketi**:
   - Internal port 6071: WebSocket connections (proxied through nginx)
   - Internal port 9670: Metrics and health checks
   - No external access required

4. **Database Access**:
   - PostgreSQL (5432) and Redis (6379) are internal-only
   - For development database access, use: `docker exec -it wificore-postgres psql -U admin -d wms_770_ts`
   - For Redis CLI: `docker exec -it wificore-redis redis-cli`

5. **Network**: All services communicate on the `wificore-network` bridge network with subnet 172.70.0.0/16

## Configuration Files

- **docker-compose.yml**: Main service configuration with port mappings
- **backend/.env**: Backend environment variables (RADIUS_SERVER_PORT=1812)
- **frontend/.env**: Frontend environment variables (VITE_PUSHER_PORT=6071)

## Verification Commands

```bash
# Check all running containers and their ports
docker ps --filter name=wificore

# Test nginx (only external service)
curl http://localhost:8070

# Test internal services (from inside containers)
docker exec wificore-backend curl -f http://wificore-soketi:9670
docker exec wificore-postgres pg_isready -U admin -d wms_770_ts
docker exec wificore-redis redis-cli ping

# Access database for development
docker exec -it wificore-postgres psql -U admin -d wms_770_ts

# Access Redis CLI
docker exec -it wificore-redis redis-cli
```

## Security Benefits

By removing external port mappings for internal services:
- ✅ **Reduced attack surface** - Only nginx is exposed to the host
- ✅ **Better isolation** - Database, cache, and authentication services are not accessible externally
- ✅ **Production-ready** - Follows Docker security best practices
- ✅ **Simplified firewall rules** - Only port 8070 needs to be managed

## Last Updated
December 16, 2025 - Removed external port mappings for internal services (security hardening)
