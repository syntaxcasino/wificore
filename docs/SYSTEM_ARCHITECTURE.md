# WifiCore System Architecture

## Overview
WifiCore is a high-performance, multi-tenant water management system built with Laravel 12, Vue.js, and PostgreSQL. The system uses schema-based multi-tenancy with complete data isolation.

## Branding
- **System Name**: WifiCore
- **Company**: TraidNet Solutions
- **Container Prefix**: traidnetsolutions-*

## Infrastructure Components

### Core Services
1. **Nginx** (Port 8070)
   - Reverse proxy and load balancer
   - Handles SSL termination
   - Routes requests to frontend and backend

2. **PostgreSQL** (Port 5472)
   - Primary database with schema-based multi-tenancy
   - High-performance configuration (200 connections, 256MB shared buffers)
   - Daily table partitioning for high-volume tables
   - 90-day retention policy

3. **Redis** (Port 6379)
   - Session storage
   - Cache backend
   - Queue backend
   - High-performance (512MB maxmemory)

4. **Soketi** (Ports 6071, 9670)
   - WebSocket server for real-time notifications
   - Pusher-compatible protocol
   - Metrics endpoint on 9670

5. **FreeRADIUS** (Ports 1872-1873)
   - AAA (Authentication, Authorization, Accounting)
   - Multi-tenant RADIUS with schema isolation
   - Custom Tenant-ID attribute

### Application Services

1. **Backend** (Laravel 12)
   - PHP 8.3 FPM
   - Multi-tenant with automatic schema switching
   - Event-driven architecture
   - Queue workers managed by Supervisor

2. **Frontend** (Vue.js)
   - Modern SPA with TailwindCSS
   - Real-time updates via Soketi
   - Progressive Web App (PWA) support

## Table Partitioning Strategy

### High-Volume Tables (Daily Partitioning)
- `radacct` - RADIUS accounting records
- `radpostauth` - RADIUS authentication logs
- `water_transactions` - Water dispensing transactions
- `jobs` - Laravel queue jobs

### Partitioning Configuration
- **Interval**: Daily partitions
- **Pre-create**: 7 days ahead
- **Retention**: 90 days (auto-drop old partitions)
- **Maintenance**: Automated via pg_cron (2 AM daily)

### Performance Benefits
- Faster queries (partition pruning)
- Efficient data archival
- Reduced index bloat
- Better vacuum performance

## Volume Strategy

### Data Volumes (Persisted)
- `postgres_data` - Database files
- `redis_data` - Redis persistence
- `laravel-storage` - Application uploads
- `laravel-logs` - Application logs

### Configuration (Baked into Containers)
- All configuration files copied during build
- No runtime volume mounts for configs
- Immutable infrastructure approach
- Faster container startup

## Network Architecture

### Network: traidnetsolutions-network
- Subnet: 172.70.0.0/16
- Gateway: 172.70.255.254
- Bridge driver for container communication

### Service Discovery
- DNS resolution via Docker's embedded DNS
- Service names resolve to container IPs
- Health checks ensure service availability

## Multi-Tenancy

### Schema-Based Isolation
- Each tenant gets dedicated schema (ts_*)
- Complete data isolation
- Tenant-specific RADIUS tables
- Automatic schema creation and migration

### Tenant Context
- Middleware sets tenant context per request
- Database queries automatically scoped
- Broadcasting channels tenant-specific

## Security

### Data Protection
- Schema-level isolation
- Row-level security policies
- Encrypted connections
- Secure credential management

### Access Control
- Role-based permissions
- Department-based routing
- Position-based overrides
- API authentication via Sanctum

## Performance Optimizations

### Database
- Connection pooling (200 max connections)
- Query result caching
- Prepared statement caching
- Parallel query execution (4 workers)

### Application
- OPcache enabled
- Redis caching layer
- Queue-based async processing
- Event-driven architecture

### Frontend
- Static asset caching (1 year)
- Gzip compression
- Code splitting
- Lazy loading

## Monitoring

### Health Checks
- All services have health check endpoints
- Automatic restart on failure
- Startup grace periods
- Dependency ordering

### Metrics
- Soketi metrics on port 9670
- PostgreSQL pg_stat_statements
- Application logs
- Error tracking

## Deployment

### Container Build
- Multi-stage builds for optimization
- Alpine Linux base (minimal size)
- Dependencies baked in
- No runtime compilation

### Updates
- Rolling updates supported
- Zero-downtime deployments
- Database migrations automatic
- Backward compatibility maintained

## Backup Strategy

### Database
- Daily automated backups
- Point-in-time recovery
- Partition-level backup
- 90-day retention

### Application
- Volume snapshots
- Configuration in version control
- Disaster recovery procedures

## Scalability

### Horizontal Scaling
- Stateless application design
- Load balancer ready
- Shared session storage (Redis)
- Database connection pooling

### Vertical Scaling
- Optimized resource allocation
- Configurable limits
- Performance tuning parameters
- Resource monitoring

## Development Workflow

### Local Development
- Docker Compose for consistency
- Hot reload enabled
- Debug mode available
- Seed data included

### CI/CD
- Automated testing
- Container builds
- Deployment automation
- Version tagging

## Documentation

### Code Documentation
- Inline comments for complex logic
- API documentation
- Database schema docs
- Architecture decision records

### Operational Docs
- Deployment procedures
- Troubleshooting guides
- Performance tuning
- Security guidelines
