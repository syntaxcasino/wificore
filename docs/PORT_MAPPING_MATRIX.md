# Port Mapping Matrix - Wificore

This document confirms the port mappings for all services in the wificore stack.

## External Port Mappings (Host:Container)

| Service | External Port(s) | Internal Port(s) | Protocol | Status |
|---------|-----------------|------------------|----------|--------|
| **nginx** | 8070 | 80 | TCP | ✅ Configured |
| **soketi** | 6071, 9670 | 6071, 9670 | TCP | ✅ Configured |
| **freeradius** | 1872-1873 | 1812-1813 | UDP | ✅ Configured |
| **postgres** | 5472 | 5432 | TCP | ✅ Configured |
| **redis** | 6379 | 6379 | TCP | ✅ Configured |

## Database Configuration

| Parameter | Value | Status |
|-----------|-------|--------|
| **Database Name** | wms_770_ts | ✅ Configured |
| **Database User** | admin | ✅ Configured |
| **Database Password** | secret | ✅ Configured |

## Service URLs

### External Access (from host machine)
- **Frontend/Nginx**: http://localhost:8070
- **Soketi WebSocket**: ws://localhost:6071
- **Soketi Metrics**: http://localhost:9670
- **FreeRADIUS Auth**: localhost:1872 (UDP)
- **FreeRADIUS Acct**: localhost:1873 (UDP)
- **PostgreSQL**: localhost:5472
- **Redis**: localhost:6379

### Internal Access (container-to-container)
- **Frontend**: http://wificore-frontend:80
- **Backend**: http://wificore-backend:9000
- **Soketi**: http://wificore-soketi:6071
- **FreeRADIUS**: wificore-freeradius:1812 (UDP)
- **PostgreSQL**: wificore-postgres:5432
- **Redis**: wificore-redis:6379

## Important Notes

1. **FreeRADIUS Port Mapping**: External ports 1872-1873 map to internal RADIUS standard ports 1812-1813
   - Port 1812: RADIUS Authentication
   - Port 1813: RADIUS Accounting
   - The backend connects internally using port 1812 (not 1872)

2. **Soketi Ports**:
   - Port 6071: WebSocket connections
   - Port 9670: Metrics and health checks

3. **Network**: All services communicate on the `wificore-network` bridge network with subnet 172.70.0.0/16

## Configuration Files

- **docker-compose.yml**: Main service configuration with port mappings
- **backend/.env**: Backend environment variables (RADIUS_SERVER_PORT=1812)
- **frontend/.env**: Frontend environment variables (VITE_PUSHER_PORT=6071)

## Verification Commands

```bash
# Check all running containers and their ports
docker ps --filter name=wificore

# Test nginx
curl http://localhost:8070

# Test soketi metrics
curl http://localhost:9670

# Test postgres connection
docker exec wificore-postgres pg_isready -U admin -d wms_770_ts

# Test redis connection
docker exec wificore-redis redis-cli ping

# Check RADIUS is listening
docker exec wificore-freeradius netstat -uln | grep 1812
```

## Last Updated
December 16, 2025 - All port mappings verified and documented
