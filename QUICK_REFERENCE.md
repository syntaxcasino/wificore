# WiFi Hotspot - Quick Reference

## 🚀 Container Management

### Start All Services
```bash
docker compose up -d
```

### Stop All Services
```bash
docker compose down
```

### Rebuild Everything
```bash
docker compose down -v
docker compose build --no-cache
docker compose up -d
```

### Check Status
```bash
docker compose ps
```

### View Logs
```bash
# All services
docker compose logs -f

# Specific service
docker compose logs -f traidnet-backend
docker compose logs -f traidnet-freeradius
docker compose logs -f traidnet-soketi
```

## 🔍 Verification Commands

### PostgreSQL Functions
```bash
# List all RADIUS functions
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\df radius_*"

# Test schema lookup function
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT get_user_schema('admin@example.com');"
```

### FreeRADIUS Configuration
```bash
# Verify configs are baked (should show empty)
docker inspect traidnet-freeradius --format='{{.Mounts}}'

# Check dictionary
docker exec traidnet-freeradius cat /opt/etc/raddb/dictionary | grep Tenant-ID

# Test FreeRADIUS
docker exec traidnet-freeradius radiusd -C
```

### Soketi Healthcheck
```bash
# Check metrics endpoint
docker exec traidnet-soketi curl -f http://localhost:9601/

# Check WebSocket
curl http://localhost:6001/
```

### Backend Environment
```bash
# Check multitenancy settings
docker exec traidnet-backend env | grep MULTITENANCY

# Check database connection
docker exec traidnet-backend php artisan tinker --execute="DB::connection()->getPdo();"
```

## 📊 Database Management

### Access PostgreSQL
```bash
docker exec -it traidnet-postgres psql -U admin -d wifi_hotspot
```

### Common Queries
```sql
-- List all schemas
SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT LIKE 'pg_%' AND schema_name != 'information_schema';

-- List all tenants
SELECT id, name, schema_name, subdomain, is_active FROM tenants;

-- Check RADIUS users
SELECT username, attribute, value FROM radcheck WHERE attribute = 'Cleartext-Password';

-- Check schema mapping
SELECT username, schema_name FROM radius_user_schema_mapping;
```

### Run Migrations
```bash
# Public schema
docker exec traidnet-backend php artisan migrate

# Specific tenant
docker exec traidnet-backend php artisan tenants:migrate --tenant=<tenant_id>
```

## 🛠️ Troubleshooting

### Container Not Healthy
```bash
# Check specific container
docker inspect traidnet-<service> --format='{{.State.Health.Status}}'

# View healthcheck logs
docker inspect traidnet-<service> --format='{{json .State.Health}}' | jq
```

### FreeRADIUS Issues
```bash
# Check FreeRADIUS logs
docker logs traidnet-freeradius --tail 100

# Test RADIUS authentication
docker exec traidnet-freeradius radtest username password localhost 0 testing123
```

### Database Connection Issues
```bash
# Check PostgreSQL is ready
docker exec traidnet-postgres pg_isready -U admin -d wifi_hotspot

# Check active connections
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT count(*) FROM pg_stat_activity;"
```

### Soketi Issues
```bash
# Check Soketi logs
docker logs traidnet-soketi --tail 50

# Test metrics endpoint
curl http://localhost:9601/
```

## 📝 Scripts

### List RADIUS Users
```bash
cd scripts
./list-radius-users.sh          # List all users
./list-radius-users.sh -d       # Show passwords
./list-radius-users.sh -c       # Count only
./list-radius-users.sh -t       # Tenant users only
./list-radius-users.sh -a       # System admin only
```

### Create RADIUS User
```bash
cd scripts
./create-radius-user.sh <username> <password>
```

### Update RADIUS Password
```bash
cd scripts
./update-radius-password.sh <username> <new_password>
```

### Delete RADIUS User
```bash
cd scripts
./delete-radius-user.sh <username>
```

## 🔐 Access URLs

### Frontend
- **Main**: http://localhost
- **HTTPS**: https://localhost

### Backend API
- **Base URL**: http://localhost/api
- **Health Check**: http://localhost/api/health

### Soketi
- **WebSocket**: ws://localhost:6001
- **Metrics**: http://localhost:9601

### PostgreSQL
- **Host**: localhost
- **Port**: 5432
- **Database**: wifi_hotspot
- **User**: admin
- **Password**: secret

### Redis
- **Host**: localhost
- **Port**: 6379

### FreeRADIUS
- **Auth Port**: 1812/udp
- **Accounting Port**: 1813/udp
- **Secret**: testing123

## 📦 Backup & Restore

### Backup Database
```bash
docker exec traidnet-postgres pg_dump -U admin wifi_hotspot > backup_$(date +%Y%m%d_%H%M%S).sql
```

### Restore Database
```bash
cat backup.sql | docker exec -i traidnet-postgres psql -U admin -d wifi_hotspot
```

### Backup Volumes
```bash
docker run --rm -v traidnet-postgres-data:/data -v $(pwd):/backup alpine tar czf /backup/postgres-data-$(date +%Y%m%d).tar.gz /data
```

## 🎯 Performance Monitoring

### Container Stats
```bash
docker stats
```

### PostgreSQL Performance
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT 
    schemaname,
    tablename,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) AS size
FROM pg_tables
WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC
LIMIT 10;
"
```

### Redis Stats
```bash
docker exec traidnet-redis redis-cli INFO stats
```

## 🔄 Development Workflow

### Make Code Changes
1. Edit files in `backend/` or `frontend/`
2. Rebuild affected container:
   ```bash
   docker compose build traidnet-backend
   docker compose up -d traidnet-backend
   ```

### Update FreeRADIUS Config
1. Edit files in `freeradius/`
2. Rebuild (configs are baked):
   ```bash
   docker compose build traidnet-freeradius
   docker compose up -d traidnet-freeradius
   ```

### Update PostgreSQL Functions
1. Edit `postgres/init.sql`
2. Rebuild database (WARNING: destroys data):
   ```bash
   docker compose down -v
   docker compose up -d
   ```

## 📚 Documentation

- `LIVESTOCK_MANAGEMENT_IMPLEMENTATION.md` - Detailed implementation
- `IMPLEMENTATION_COMPLETE.md` - Completion summary
- `MULTI_TENANT_RADIUS_ARCHITECTURE.md` - Architecture overview
- `OPTIMIZED_MULTI_TENANT_RADIUS.md` - Optimization details
- `QUICK_REFERENCE.md` - This file

---

**Last Updated**: December 6, 2025  
**System Status**: ✅ All Containers Healthy
