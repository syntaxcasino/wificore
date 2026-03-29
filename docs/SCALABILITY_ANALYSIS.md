# WiFiCore Scalability Analysis & Recommendations
## Target: 1000+ Tenants × 1000+ Devices/Users Each

**Analysis Date:** January 2, 2026  
**Target Scale:** 
- **1,000+ Tenants**
- **Per Tenant:** 1,000+ Devices + 1,000+ PPPoE Users + 1,000+ Hotspot Users
- **Total System Load:** ~3 million concurrent users/devices

---

## Executive Summary

### Current State: ⚠️ **NOT READY** for Target Scale

**Critical Bottlenecks Identified:**
1. ❌ Single PostgreSQL instance (max ~200 connections)
2. ❌ Single Redis instance (512MB RAM limit)
3. ❌ Single FreeRADIUS instance (will collapse under load)
4. ❌ Database queue driver (not scalable)
5. ❌ Schema-per-tenant approach (1000+ schemas = performance degradation)
6. ❌ No horizontal scaling capability
7. ❌ Insufficient resource allocation

**Estimated Current Capacity:** ~50-100 tenants with degraded performance

---

## 1. Database Layer Analysis

### Current Configuration
```yaml
PostgreSQL:
  - Single instance
  - Max connections: 200
  - Shared buffers: 256MB
  - Effective cache: 1GB
  - Schema-based multi-tenancy
```

### Problems at Scale

#### 1.1 Connection Pool Exhaustion
**Math:**
- 1,000 tenants × 5 concurrent requests/tenant = 5,000 connections needed
- Current limit: 200 connections
- **Result:** 96% of requests will fail

#### 1.2 Schema Overhead
**Current:** Each tenant = 1 PostgreSQL schema
- 1,000 schemas = massive metadata overhead
- Schema switching adds 5-10ms per query
- PostgreSQL catalog bloat
- Vacuum/analyze becomes extremely slow

#### 1.3 Query Performance
**At 3M users:**
- RADIUS auth queries: ~10,000/second peak
- Session tracking: ~5,000 writes/second
- Accounting: ~2,000 writes/second
- **Current setup:** Will timeout/crash

### Solutions Required

#### ✅ Solution 1: Database Sharding (CRITICAL)
```
┌─────────────────────────────────────────┐
│         PgBouncer (Connection Pool)     │
│         - 10,000 connections            │
│         - Transaction pooling           │
└──────────────┬──────────────────────────┘
               │
    ┌──────────┴──────────┬──────────────┐
    ▼                     ▼              ▼
┌─────────┐         ┌─────────┐    ┌─────────┐
│ Shard 1 │         │ Shard 2 │    │ Shard N │
│ 0-249   │         │ 250-499 │    │ 750-999 │
│ tenants │         │ tenants │    │ tenants │
└─────────┘         └─────────┘    └─────────┘
```

**Implementation:**
- Use **Citus** (PostgreSQL extension for sharding)
- OR **Vitess** (MySQL sharding - consider migration)
- OR **Manual sharding** with tenant routing

**Configuration per shard:**
```sql
-- Each shard handles 250 tenants
max_connections = 500
shared_buffers = 4GB
effective_cache_size = 12GB
work_mem = 16MB
maintenance_work_mem = 1GB
```

#### ✅ Solution 2: Read Replicas
```
Master (Writes)
  ├── Replica 1 (Reads - Tenants 0-333)
  ├── Replica 2 (Reads - Tenants 334-666)
  └── Replica 3 (Reads - Tenants 667-999)
```

#### ✅ Solution 3: Switch to Row-Level Tenancy
**Instead of schemas, use:**
```sql
-- Add tenant_id to every table
CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    tenant_id UUID NOT NULL,
    username VARCHAR(64),
    ...
    -- Composite index for fast tenant queries
    INDEX idx_tenant_users ON users(tenant_id, id)
);

-- Partition by tenant_id ranges
CREATE TABLE users_0_249 PARTITION OF users
    FOR VALUES FROM (0) TO (250);
```

**Benefits:**
- No schema switching overhead
- Better query planner statistics
- Easier to shard
- Simpler backups

---

## 2. RADIUS Layer Analysis

### Current Configuration
```yaml
FreeRADIUS:
  - Single instance
  - Direct PostgreSQL queries
  - No caching
  - No load balancing
```

### Problems at Scale

#### 2.1 Authentication Load
**Expected load:**
- 3M users × 10% concurrent = 300,000 active sessions
- Session refresh every 5 minutes = 1,000 auth/sec sustained
- Peak (morning login): 10,000 auth/sec
- **Current capacity:** ~500 auth/sec before collapse

#### 2.2 Accounting Load
- 300,000 sessions × accounting updates every 60s = 5,000 writes/sec
- **Current capacity:** ~200 writes/sec

### Solutions Required

#### ✅ Solution 1: RADIUS Cluster with Load Balancer
```
                    ┌──────────────┐
                    │   HAProxy    │
                    │  (RADIUS LB) │
                    └──────┬───────┘
                           │
        ┌──────────────────┼──────────────────┐
        ▼                  ▼                  ▼
┌──────────────┐   ┌──────────────┐   ┌──────────────┐
│ FreeRADIUS 1 │   │ FreeRADIUS 2 │   │ FreeRADIUS N │
│ (Tenants     │   │ (Tenants     │   │ (Tenants     │
│  0-333)      │   │  334-666)    │   │  667-999)    │
└──────┬───────┘   └──────┬───────┘   └──────┬───────┘
       │                  │                  │
       └──────────────────┼──────────────────┘
                          ▼
                  ┌───────────────┐
                  │ Redis Cluster │
                  │ (Session      │
                  │  Cache)       │
                  └───────────────┘
```

**Configuration per RADIUS instance:**
```conf
# /etc/raddb/radiusd.conf
max_requests = 16384
max_request_time = 30
cleanup_delay = 5
max_servers = 512
start_servers = 128
```

#### ✅ Solution 2: Redis Session Caching
**Cache all active sessions in Redis:**
```redis
# Key pattern: session:{username}
# TTL: 300 seconds (5 minutes)
# Reduces DB queries by 90%

SET session:user@tenant1 "{"ip":"10.1.1.2","mac":"AA:BB:CC:DD:EE:FF"}" EX 300
```

**Implementation:**
```python
# Custom FreeRADIUS rlm_python module
def authorize(p):
    username = p['User-Name']
    
    # Try Redis first
    session = redis.get(f"session:{username}")
    if session:
        return (RLM_MODULE_OK, tuple(), tuple())
    
    # Fallback to database
    result = db_query(username)
    redis.setex(f"session:{username}", 300, result)
    return (RLM_MODULE_OK, tuple(), tuple())
```

#### ✅ Solution 3: Accounting Buffering
**Buffer accounting updates:**
```
RADIUS → Redis Queue → Batch Writer → PostgreSQL
         (10,000/s)    (Process 100   (100 writes/s)
                        at a time)
```

---

## 3. WireGuard VPN Layer Analysis

### Current Configuration
```yaml
WireGuard Controller:
  - Single instance
  - Host mode (1 interface per tenant)
  - Max interfaces: 100
```

### Problems at Scale

#### 3.1 Interface Limits
- Linux kernel limit: ~1,000 network interfaces
- Performance degradation after 500 interfaces
- **Current design:** 1 interface per tenant = max 1,000 tenants (theoretical)
- **Practical limit:** 500 tenants before performance issues

#### 3.2 Port Allocation
- Need 1,000 UDP ports (51820-52820)
- Firewall rules: 1,000 DNAT rules
- **Result:** Manageable but complex

### Solutions Required

#### ✅ Solution 1: WireGuard Clustering
```
                    ┌──────────────┐
                    │  WireGuard   │
                    │  Controller  │
                    │  (API)       │
                    └──────┬───────┘
                           │
        ┌──────────────────┼──────────────────┐
        ▼                  ▼                  ▼
┌──────────────┐   ┌──────────────┐   ┌──────────────┐
│ WG Server 1  │   │ WG Server 2  │   │ WG Server N  │
│ Tenants      │   │ Tenants      │   │ Tenants      │
│ 0-249        │   │ 250-499      │   │ 500-749      │
│ wg0-wg249    │   │ wg250-wg499  │   │ wg500-wg749  │
└──────────────┘   └──────────────┘   └──────────────┘
```

**Each WireGuard server:**
- 250 interfaces
- 250 UDP ports
- Dedicated public IP
- 4GB RAM, 4 vCPUs

#### ✅ Solution 2: Shared Interface with Peer Isolation
**Alternative approach:**
```
Single WireGuard interface per server
  ├── 1,000 peers (tenants)
  ├── Firewall rules for isolation
  └── Subnet routing per peer
```

**Pros:**
- Simpler management
- Better performance
- Less overhead

**Cons:**
- More complex firewall rules
- Requires careful subnet planning

---

## 4. Queue & Job Processing Analysis

### Current Configuration
```yaml
Queue:
  - Driver: database
  - Workers: 10
  - Max tries: 3
```

### Problems at Scale

#### 4.1 Database Queue Bottleneck
**At scale:**
- 1,000 tenants × 100 jobs/hour = 100,000 jobs/hour
- Database polling overhead
- Lock contention
- **Result:** Jobs delayed by hours

### Solutions Required

#### ✅ Solution 1: Switch to Redis Queue
```php
// config/queue.php
'default' => 'redis',

'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'queue',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
    ],
],
```

#### ✅ Solution 2: Horizontal Queue Workers
```yaml
# docker-compose.production.yml
wificore-queue-worker-1:
  replicas: 10
  command: php artisan queue:work redis --queue=high,default,low --tries=3

wificore-queue-worker-2:
  replicas: 10
  command: php artisan queue:work redis --queue=tenant-jobs --tries=3
```

**Total:** 20 worker containers = 200 concurrent jobs

#### ✅ Solution 3: Job Prioritization
```php
// High priority: Real-time operations
Queue::connection('redis')->pushOn('high', new DisconnectUserJob($userId));

// Default: Normal operations
Queue::connection('redis')->push(new ProcessPaymentJob($paymentId));

// Low priority: Batch operations
Queue::connection('redis')->pushOn('low', new GenerateReportJob($tenantId));
```

---

## 5. Redis Layer Analysis

### Current Configuration
```yaml
Redis:
  - Single instance
  - Max memory: 512MB
  - No persistence
  - No clustering
```

### Problems at Scale

#### 5.1 Memory Exhaustion
**Expected usage:**
- Session cache: 300,000 sessions × 1KB = 300MB
- Queue data: 100,000 jobs × 2KB = 200MB
- Application cache: 100MB
- **Total needed:** 600MB minimum
- **Current limit:** 512MB
- **Result:** Cache eviction, performance degradation

### Solutions Required

#### ✅ Solution 1: Redis Cluster
```
┌─────────────────────────────────────────┐
│         Redis Sentinel (HA)             │
└──────────────┬──────────────────────────┘
               │
    ┌──────────┴──────────┬──────────────┐
    ▼                     ▼              ▼
┌─────────┐         ┌─────────┐    ┌─────────┐
│ Master 1│         │ Master 2│    │ Master 3│
│ 8GB RAM │         │ 8GB RAM │    │ 8GB RAM │
│ Sessions│         │ Queue   │    │ Cache   │
└────┬────┘         └────┬────┘    └────┬────┘
     │                   │              │
┌────▼────┐         ┌────▼────┐    ┌────▼────┐
│Replica 1│         │Replica 2│    │Replica 3│
└─────────┘         └─────────┘    └─────────┘
```

**Configuration:**
```conf
# redis.conf
maxmemory 8gb
maxmemory-policy allkeys-lru
save 900 1
save 300 10
save 60 10000
```

#### ✅ Solution 2: Separate Redis Instances
```yaml
redis-sessions:
  maxmemory: 4GB
  purpose: RADIUS session caching

redis-queue:
  maxmemory: 2GB
  purpose: Job queue

redis-cache:
  maxmemory: 2GB
  purpose: Application cache
```

---

## 6. Application Layer Analysis

### Current Configuration
```yaml
Backend:
  - Single container
  - PHP-FPM workers: 50
  - No horizontal scaling
```

### Problems at Scale

#### 6.1 Request Handling Capacity
**Expected load:**
- 1,000 tenants × 10 active users = 10,000 concurrent users
- Average 5 requests/minute/user = 833 requests/second
- **Current capacity:** ~200 requests/second
- **Result:** 75% of requests timeout

### Solutions Required

#### ✅ Solution 1: Horizontal Scaling
```yaml
# docker-compose.production.yml
wificore-backend:
  deploy:
    replicas: 10
  resources:
    limits:
      cpus: '2'
      memory: 4G
```

**Load balancer configuration:**
```nginx
upstream backend {
    least_conn;
    server backend-1:9000;
    server backend-2:9000;
    server backend-3:9000;
    server backend-4:9000;
    server backend-5:9000;
    server backend-6:9000;
    server backend-7:9000;
    server backend-8:9000;
    server backend-9:9000;
    server backend-10:9000;
}
```

#### ✅ Solution 2: PHP-FPM Optimization
```ini
; php-fpm.conf
pm = dynamic
pm.max_children = 100
pm.start_servers = 25
pm.min_spare_servers = 10
pm.max_spare_servers = 50
pm.max_requests = 1000

; php.ini
memory_limit = 512M
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
```

---

## 7. Monitoring & Observability

### Required Components

#### ✅ Metrics Collection
```yaml
prometheus:
  - PostgreSQL metrics (pg_exporter)
  - Redis metrics (redis_exporter)
  - RADIUS metrics (custom exporter)
  - Application metrics (Laravel)
  - System metrics (node_exporter)

grafana:
  - Real-time dashboards
  - Alerting rules
  - Capacity planning
```

#### ✅ Logging
```yaml
elk-stack:
  elasticsearch:
    - Centralized logging
    - 30-day retention
  logstash:
    - Log aggregation
    - Parsing
  kibana:
    - Log analysis
    - Debugging
```

#### ✅ Tracing
```yaml
jaeger:
  - Distributed tracing
  - Request flow visualization
  - Performance bottleneck identification
```

---

## 8. Infrastructure Requirements

### Minimum Production Setup

#### 8.1 Database Cluster (4 Shards)
```
4× PostgreSQL Servers:
  - CPU: 16 cores
  - RAM: 64GB
  - Storage: 2TB NVMe SSD
  - Network: 10Gbps
  
Total: 64 cores, 256GB RAM, 8TB storage
```

#### 8.2 RADIUS Cluster
```
3× RADIUS Servers:
  - CPU: 8 cores
  - RAM: 16GB
  - Network: 10Gbps
  
Total: 24 cores, 48GB RAM
```

#### 8.3 WireGuard Cluster
```
4× WireGuard Servers:
  - CPU: 8 cores
  - RAM: 16GB
  - Network: 10Gbps
  - Public IP: 1 per server
  
Total: 32 cores, 64GB RAM
```

#### 8.4 Redis Cluster
```
3× Redis Servers (Master + Replica):
  - CPU: 4 cores
  - RAM: 32GB
  - Storage: 500GB SSD
  
Total: 12 cores, 96GB RAM
```

#### 8.5 Application Servers
```
10× Backend Servers:
  - CPU: 4 cores
  - RAM: 8GB
  
Total: 40 cores, 80GB RAM
```

#### 8.6 Load Balancers
```
2× HAProxy/Nginx:
  - CPU: 4 cores
  - RAM: 8GB
  - Network: 10Gbps
  
Total: 8 cores, 16GB RAM
```

### Total Infrastructure
```
Servers: 26
Total CPU: 180 cores
Total RAM: 560GB
Total Storage: 9TB
Estimated Cost: $5,000-8,000/month (cloud)
```

---

## 9. Migration Path

### Phase 1: Immediate (Week 1-2)
1. ✅ Switch queue driver from database to Redis
2. ✅ Increase Redis memory to 4GB
3. ✅ Add PgBouncer connection pooler
4. ✅ Optimize PostgreSQL configuration
5. ✅ Add monitoring (Prometheus + Grafana)

### Phase 2: Short-term (Week 3-6)
1. ✅ Implement RADIUS session caching
2. ✅ Add read replicas for PostgreSQL
3. ✅ Scale backend to 5 replicas
4. ✅ Add Redis Sentinel for HA
5. ✅ Implement job prioritization

### Phase 3: Medium-term (Month 2-3)
1. ✅ Implement database sharding (Citus)
2. ✅ Deploy RADIUS cluster (3 nodes)
3. ✅ Deploy WireGuard cluster (4 nodes)
4. ✅ Migrate to row-level tenancy
5. ✅ Add ELK stack for logging

### Phase 4: Long-term (Month 4-6)
1. ✅ Full horizontal scaling
2. ✅ Multi-region deployment
3. ✅ Advanced caching strategies
4. ✅ Auto-scaling policies
5. ✅ Disaster recovery setup

---

## 10. Cost Analysis

### Current Setup (Single Server)
```
1× VPS (16 cores, 64GB RAM): $200/month
Total: $200/month
Capacity: ~50 tenants
```

### Scaled Setup (Target: 1000 tenants)
```
Database Cluster: $2,000/month
RADIUS Cluster: $600/month
WireGuard Cluster: $800/month
Redis Cluster: $600/month
Application Servers: $1,200/month
Load Balancers: $200/month
Monitoring: $300/month
Backup Storage: $300/month

Total: $6,000/month
Capacity: 1,000+ tenants
Cost per tenant: $6/month
```

### Revenue Requirements
```
To break even at $6/tenant/month infrastructure cost:
- Minimum charge: $20/tenant/month (30% infrastructure cost)
- Recommended: $50-100/tenant/month
```

---

## 11. Performance Targets

### Current vs Target

| Metric | Current | Target | Gap |
|--------|---------|--------|-----|
| Tenants | 50 | 1,000 | 20x |
| Concurrent Users | 5,000 | 300,000 | 60x |
| RADIUS Auth/sec | 500 | 10,000 | 20x |
| API Requests/sec | 200 | 5,000 | 25x |
| Database Connections | 200 | 10,000 | 50x |
| Response Time (p95) | 200ms | <100ms | Improve |
| Uptime | 99% | 99.9% | Improve |

---

## 12. Critical Action Items

### Must Do (Before Scaling Beyond 100 Tenants)
1. ❌ **Switch to Redis queue** - Database queue will fail
2. ❌ **Add PgBouncer** - Connection pool exhaustion imminent
3. ❌ **Implement RADIUS caching** - Database will collapse
4. ❌ **Add monitoring** - Flying blind is dangerous
5. ❌ **Increase Redis memory** - Current 512MB insufficient

### Should Do (Before 500 Tenants)
1. ⚠️ **Database sharding** - Single DB won't handle load
2. ⚠️ **RADIUS clustering** - Single instance bottleneck
3. ⚠️ **Backend horizontal scaling** - Need more capacity
4. ⚠️ **Redis clustering** - HA and capacity
5. ⚠️ **WireGuard clustering** - Interface limits

### Nice to Have (Before 1000 Tenants)
1. 📊 **ELK stack** - Better debugging
2. 📊 **Distributed tracing** - Performance analysis
3. 📊 **Auto-scaling** - Dynamic capacity
4. 📊 **Multi-region** - Geographic distribution
5. 📊 **CDN** - Static asset delivery

---

## 13. Risk Assessment

### High Risk (Will Cause Outages)
- ❌ Database connection exhaustion
- ❌ RADIUS server overload
- ❌ Queue processing delays
- ❌ Memory exhaustion (Redis)

### Medium Risk (Will Cause Degradation)
- ⚠️ Slow query performance
- ⚠️ Schema switching overhead
- ⚠️ Network interface limits
- ⚠️ Disk I/O saturation

### Low Risk (Manageable)
- 📊 Log storage growth
- 📊 Backup duration
- 📊 Monitoring overhead

---

## Conclusion

**Current Status:** System is optimized for ~50-100 tenants

**To reach 1,000+ tenants:**
- **Investment Required:** $6,000/month infrastructure
- **Development Time:** 4-6 months
- **Team Required:** 2-3 DevOps engineers
- **Risk Level:** High (major architectural changes)

**Recommended Approach:**
1. Start with Phase 1 (immediate fixes)
2. Grow to 200 tenants with Phase 2
3. Implement Phase 3 before 500 tenants
4. Complete Phase 4 for 1,000+ tenants

**Alternative:** Consider managed services (AWS RDS, ElastiCache, etc.) to reduce operational complexity.
