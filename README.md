# WiFi Core SaaS

**Enterprise-Grade WiFi Hotspot Management & Billing Platform**

[![Version](https://img.shields.io/badge/version-2.0.0-blue)](./CHANGELOG.md)
[![Status](https://img.shields.io/badge/status-production--ready-green)](./ARCHITECTURE_REVIEW.md)
[![Architecture](https://img.shields.io/badge/architecture-microservices-brightgreen)](./docs/ARCHITECTURE_DIAGRAM.md)
[![Security](https://img.shields.io/badge/security-hardened-success)](./docs/SECURITY_AUDIT_REPORT.md)

A comprehensive multi-tenant WiFi hotspot billing and management system with enterprise-grade security, real-time monitoring, and automated provisioning capabilities.

---

## 🚀 Overview

WiFi Core is a SaaS platform that enables ISPs and network operators to manage WiFi hotspots, PPPoE connections, and billing with full multi-tenant isolation. Built with Laravel, Vue.js, and Docker microservices architecture.

### Key Capabilities

- **Multi-Tenant SaaS** - PostgreSQL schema-based tenant isolation
- **Router Management** - Automated MikroTik provisioning and monitoring
- **PPPoE & Hotspot** - Complete user lifecycle management
- **Billing & Payments** - M-Pesa integration with invoicing
- **Real-Time Monitoring** - Live session tracking via WebSocket
- **RADIUS AAA** - FreeRADIUS with SHA-256 authentication
- **VPN Management** - WireGuard automation for secure management
- **Network Segmentation** - Isolated provisioning service architecture

---

## 📐 Architecture

### Tech Stack

| Layer | Technology |
|-------|------------|
| **Backend** | Laravel 11, PHP 8.2 |
| **Frontend** | Vue.js 3, Vite, Pinia, Tailwind CSS |
| **Database** | PostgreSQL 15 (Primary + Replica) |
| **Cache/Queue** | Redis (HAProxy Load Balanced) |
| **WebSocket** | Soketi (Laravel Echo) |
| **RADIUS** | FreeRADIUS 3.x |
| **Proxy** | Nginx, HAProxy |
| **VPN** | WireGuard |
| **Monitoring** | VictoriaMetrics, Grafana, Telegraf |
| **Container** | Docker, Docker Compose |

### Microservices Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         NGINX (Reverse Proxy)                  │
│                    172.70.0.10 - wificore-nginx                 │
└──────────────┬────────────────────────────────┬─────────────────┘
               │                                │
    ┌──────────▼──────────┐        ┌────────────▼────────────┐
    │   Vue.js Frontend   │        │     Laravel Backend     │
    │  172.70.0.11        │        │   172.70.0.20 (PHP-FPM) │
    └──────────┬──────────┘        └────────────┬────────────┘
               │                                │
               │         ┌──────────────────────┼──────────────────────┐
               │         │                      │                      │
    ┌──────────▼──────────┐        ┌────────────▼────────┐    ┌────────▼────────┐
    │   PostgreSQL        │        │      Redis        │    │   Soketi        │
    │ 172.70.0.3 (Write) │        │  172.70.0.5       │    │  172.70.0.6     │
    │ 172.70.0.4 (Read)  │        │   (HAProxy)       │    │  (WebSocket)    │
    └──────────┬──────────┘        └────────────┬────────┘    └─────────────────┘
               │                                │
    ┌──────────▼──────────┐        ┌────────────▼────────┐
    │   FreeRADIUS        │        │   PgBouncer         │
    │  172.70.0.2         │        │  172.70.0.15/16    │
    └─────────────────────┘        └─────────────────────┘
```

### Service Inventory

| Service | Container | IP Address | Purpose |
|---------|-----------|------------|---------|
| Nginx | wificore-nginx | 172.70.0.10 | Reverse proxy, static assets |
| Frontend | wificore-frontend | 172.70.0.11 | Vue.js SPA |
| Backend | wificore-backend | 172.70.0.20 | Laravel API |
| Backend SSE | wificore-backend-sse | 172.70.0.21 | Server-sent events |
| PostgreSQL | wificore-postgres | 172.70.0.3 | Primary database |
| Postgres Replica | wificore-postgres-replica | 172.70.0.4 | Read replica |
| Redis Primary | wificore-redis-primary | 172.70.0.7 | Cache/Queue master |
| Redis Replica | wificore-redis-replica | 172.70.0.8 | Cache/Queue slave |
| Redis Sentinel | wificore-redis-sentinel-{1,2,3} | 172.70.0.100-102 | Redis HA |
| Redis Proxy | wificore-redis | 172.70.0.5 | HAProxy Redis load balancer |
| FreeRADIUS | wificore-freeradius | 172.70.0.2 | RADIUS AAA server |
| Soketi | wificore-soketi | 172.70.0.6 | WebSocket server |
| PgBouncer | wificore-pgbouncer | 172.70.0.15 | Connection pool (write) |
| PgBouncer Read | wificore-pgbouncer-read | 172.70.0.16 | Connection pool (read) |
| VictoriaMetrics | wificore-victoriametrics | 172.70.0.20 | Metrics storage |
| Telegraf | wificore-telegraf | 172.70.0.20 | Metrics collection |
| WireGuard | wificore-wireguard | Host network | VPN controller |
| Provisioning | wificore-provisioning | 172.70.0.13 | Router provisioning API |
| GenieACS | genieacs-{ui,nb,cw,fs} | 172.70.0.50-53 | TR-069 ACS (optional) |

---

## 🛠️ Project Structure

```
wificore/
├── backend/                    # Laravel API
│   ├── app/
│   │   ├── Console/           # Artisan commands
│   │   ├── Contracts/         # Interfaces
│   │   ├── Database/        # Migrations, seeders
│   │   ├── Http/            # Controllers, Middleware
│   │   ├── Jobs/            # Queue jobs
│   │   ├── Models/          # Eloquent models
│   │   ├── Providers/       # Service providers
│   │   ├── Services/        # Business logic
│   │   └── Scopes/          # Eloquent scopes
│   ├── bootstrap/           # App bootstrap
│   ├── config/              # Configuration
│   ├── docker/              # Docker entrypoints
│   ├── resources/           # Views, localization
│   ├── routes/              # API routes
│   ├── storage/             # Logs, cache, uploads
│   ├── supervisor/          # Queue worker configs
│   └── tests/               # Pest PHP tests
│
├── frontend/                  # Vue.js SPA
│   ├── src/
│   │   ├── assets/          # Static assets
│   │   ├── components/      # Vue components
│   │   │   ├── common/      # Shared components
│   │   │   ├── dashboard/   # Dashboard widgets
│   │   │   ├── layout/      # Layout components
│   │   │   ├── routers/     # Router components
│   │   │   └── packages/    # Package components
│   │   ├── composables/     # Vue composables (41 total)
│   │   ├── router/          # Vue Router
│   │   ├── stores/          # Pinia stores
│   │   └── views/           # Page components
│   └── e2e/                 # Playwright tests
│
├── provisioning-service/      # Go-based provisioning API
│   ├── cmd/                 # Main applications
│   └── internal/            # Internal packages
│
├── docker/                    # Docker configurations
│   ├── redis/               # Redis + Sentinel
│   ├── redis-proxy/         # HAProxy for Redis
│   └── php-fpm-custom.conf  # PHP-FPM settings
│
├── freeradius/                # FreeRADIUS config
├── nginx/                     # Nginx configurations
├── postgres/                  # PostgreSQL init scripts
├── pgbouncer/                 # PgBouncer config
├── soketi/                    # Soketi WebSocket config
├── wireguard-controller/      # WireGuard automation
├── monitoring/                # Grafana/Prometheus
│   ├── grafana/
│   └── prometheus/
│
├── docs/                      # Documentation (600+ files)
│   ├── IA/                  # AI context
│   └── *.md                 # Architecture docs
│
├── scripts/                   # Utility scripts
├── storage/                   # Application storage
└── logs/                      # Application logs
```

---

## 🚀 Quick Start

### Prerequisites

- Docker 24.0+
- Docker Compose 2.20+
- Git
- 8GB+ RAM
- Linux/macOS/Windows WSL2

### Local Development

```bash
# Clone repository
git clone <repository-url>
cd wificore

# Copy environment files
cp .env.example .env
cp .env.production.example .env.production

# Start development stack
docker-compose up -d

# Install backend dependencies
docker-compose exec backend composer install

# Generate application key
docker-compose exec backend php artisan key:generate

# Run migrations with seeders
docker-compose exec backend php artisan migrate --seed

# Install frontend dependencies
docker-compose exec frontend npm install

# Start development server
docker-compose exec frontend npm run dev
```

### Access Points

| Service | URL |
|---------|-----|
| Frontend | http://localhost:5173 |
| API | http://localhost:8000 |
| Soketi WS | http://localhost:6001 |
| FreeRADIUS | localhost:1812/1813 (UDP) |

---

## 📦 Production Deployment

### Build and Push

```bash
# Build all images and deploy to remote server
./build-and-push.sh 1

# Build without cache
./build-and-push.sh 0
```

### Manual Deployment

```bash
# On production server
cd /opt/wificore

# Pull latest images
docker-compose -f docker-compose.production.yml pull

# Restart services
docker-compose -f docker-compose.production.yml up -d

# Run migrations
docker-compose -f docker-compose.production.yml exec backend php artisan migrate

# Clear caches
docker-compose -f docker-compose.production.yml exec backend php artisan optimize:clear
```

---

## 🔒 Security

### Implemented Security Measures

| Feature | Implementation | Status |
|---------|----------------|--------|
| Tenant Isolation | PostgreSQL Schema + `search_path` | ✅ Active |
| Authentication | Laravel Sanctum + Token-based | ✅ Active |
| DDoS Protection | Redis-based IP throttling | ✅ Active |
| Rate Limiting | Per-route configurable limits | ✅ Active |
| Password Hashing | SHA-256 + NT-Password | ✅ Active |
| RADIUS CoA | Packet of Disconnect support | ✅ Active |
| Request Signing | HMAC-SHA256 provisioning | ✅ Active |
| Audit Logging | Comprehensive event logging | ✅ Active |
| VPN Management | WireGuard automation | ✅ Active |
| SSH Security | Key-based + password fallback | ✅ Active |

### Security Documentation

- [Security Audit Report](./docs/SECURITY_AUDIT_REPORT.md)
- [Developer Security Guidelines](./docs/DEVELOPER_SECURITY_GUIDELINES.md)
- [RADIUS Security Hardening](./docs/SECURITY_BEST_PRACTICES_HOTSPOT.md)
- [Production Hardening Checklist](./docs/PRODUCTION_HARDENING_CHECKLIST.md)

---

## 📊 Monitoring & Observability

### Metrics Collection

| Source | Collector | Storage | Dashboard |
|--------|-----------|---------|-----------|
| Application | Telegraf | VictoriaMetrics | Grafana |
| System | Telegraf | VictoriaMetrics | Grafana |
| RADIUS | radsniff | VictoriaMetrics | Grafana |
| Queue | Laravel Horizon | Redis | Built-in |
| Docker | cAdvisor | VictoriaMetrics | Grafana |

### Health Checks

All services implement Docker health checks:

```bash
# Check all service health
docker-compose ps

# View service logs
docker-compose logs -f [service-name]

# Check specific service
docker-compose exec [service] healthcheck
```

### Log Aggregation

```bash
# View centralized logs
tail -f logs/agent-actions.log

# Backend application logs
docker-compose exec backend tail -f storage/logs/laravel.log
```

---

## 🧪 Testing

### Backend Tests

```bash
# Run Pest PHP tests
cd backend
./vendor/bin/pest

# Run specific test suite
./vendor/bin/pest --filter=RouterTest
```

### Frontend Tests

```bash
cd frontend

# Unit tests
npm run test

# E2E tests
npm run test:e2e

# Specific test
npx playwright test auth.spec.js
```

### Integration Tests

```bash
# Test RADIUS authentication
docker-compose exec freeradius radtest username password localhost 0 testing123

# Test Redis connectivity
docker-compose exec redis redis-cli ping

# Test PostgreSQL
docker-compose exec postgres psql -U postgres -c "SELECT version();"
```

---

## 📚 Documentation

### Architecture Documentation

| Document | Description |
|----------|-------------|
| [Architecture Review](./ARCHITECTURE_REVIEW.md) | End-to-end architecture analysis |
| [Architecture Diagram](./docs/ARCHITECTURE_DIAGRAM.md) | Visual architecture diagrams |
| [System Architecture](./docs/SYSTEM_ARCHITECTURE.md) | High-level system design |
| [Network Segmentation](./docs/NETWORK_SEGMENTATION_ANALYSIS.md) | Network isolation design |

### Feature Documentation

| Feature | Documentation |
|---------|---------------|
| Multi-Tenancy | [Part 1-4 Guide](./docs/MULTITENANCY_PART1_OVERVIEW.md) |
| RADIUS AAA | [RADIUS Integration](./docs/RADIUS_FIX.md), [AAA Implementation](./docs/AAA_IMPLEMENTATION.md) |
| Router Provisioning | [Provisioning Flow](./docs/ROUTER_PROVISIONING_FLOW.md) |
| PPPoE | [PPPoE Implementation](./docs/PPPOE_IMPLEMENTATION_PLAN.md) |
| Hotspot | [Hotspot System](./docs/HOTSPOT_SYSTEM_COMPLETE.md) |
| VPN | [WireGuard Setup](./docs/WIREGUARD_SETUP.md), [VPN Automation](./docs/VPN_AUTOMATION_COMPLETE.md) |
| Billing | [Payment Flow](./docs/PAYMENT_FLOW_VERIFICATION.md) |

### Operations Documentation

| Operation | Documentation |
|-----------|---------------|
| Deployment | [Deployment Guide](./docs/DEPLOYMENT_INSTRUCTIONS.md), [Production Deployment](./docs/PRODUCTION_DEPLOYMENT.md) |
| Queue Workers | [Queue System](./docs/QUEUE_SYSTEM.md), [Queue Troubleshooting](./docs/QUEUE_TROUBLESHOOTING.md) |
| Database | [Migration Guide](./docs/DATABASE_MIGRATION_GUIDE.md), [Partition Management](./docs/PARTITION_RETENTION_STRATEGY.md) |
| Monitoring | [Metrics Setup](./docs/TELEGRAF_METRICS_SETUP.md), [Health Checks](./docs/HEALTH_CHECK_SYSTEM.md) |
| Troubleshooting | [Troubleshooting Guide](./docs/TROUBLESHOOTING_GUIDE.md), [Diagnostics](./docs/PRODUCTION_DIAGNOSTICS.md) |

---

## 🔧 Configuration

### Environment Variables

Key environment variables for production:

```env
# Application
APP_NAME=WiFi Core
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false

# Database
DB_HOST=wificore-pgbouncer
DB_READ_HOST=wificore-pgbouncer-read
DB_PORT=5432
DB_DATABASE=wificore
DB_USERNAME=wificore
DB_PASSWORD=secure-password

# Redis
REDIS_HOST=wificore-redis
REDIS_PASSWORD=redis-password
REDIS_PORT=6379

# RADIUS
RADIUS_SERVER_HOST=wificore-freeradius
RADIUS_SERVER_PORT=1812
RADIUS_SECRET=radius-secret
RADIUS_ALLOW_CLEARTEXT=false

# WebSocket
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=app-id
PUSHER_APP_KEY=app-key
PUSHER_APP_SECRET=app-secret
PUSHER_HOST=wificore-soketi
PUSHER_PORT=6001

# VPN
WIREGUARD_ENABLED=true
WIREGUARD_ENDPOINT=your-server.com
WIREGUARD_PORT=51820
```

### Full Configuration Reference

See [Environment Variables Guide](./docs/ENVIRONMENT_VARIABLES_WARNINGS.md) for complete documentation.

---

## 🤝 Contributing

1. Fork the repository
2. Create feature branch: `git checkout -b feature/amazing-feature`
3. Commit changes: `git commit -m 'Add amazing feature'`
4. Push to branch: `git push origin feature/amazing-feature`
5. Open Pull Request

### Development Guidelines

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards
- Write tests for new features
- Update documentation for API changes
- Use conventional commit messages
- Ensure all tests pass before submitting PR

---

## 📈 Roadmap

### Completed ✅

- [x] Multi-tenancy with schema isolation
- [x] RADIUS AAA with SHA-256
- [x] Real-time WebSocket updates
- [x] Automated router provisioning
- [x] VPN management (WireGuard)
- [x] Queue worker optimization
- [x] Production hardening
- [x] Monitoring & alerting
- [x] PWA support
- [x] Comprehensive test coverage

### In Progress 🚧

- [ ] Mobile app (React Native)
- [ ] Advanced analytics dashboard
- [ ] API rate limiting per tenant
- [ ] Automated backup system
- [ ] SMS notification system

### Planned 📋

- [ ] Kubernetes deployment
- [ ] Multi-region support
- [ ] Edge caching (CDN)
- [ ] AI-powered analytics
- [ ] Self-service portal

---

## 📞 Support

### Resources

- **Documentation**: [docs/README.md](./docs/README.md)
- **Troubleshooting**: [docs/TROUBLESHOOTING_GUIDE.md](./docs/TROUBLESHOOTING_GUIDE.md)
- **Security Issues**: [docs/SECURITY_AUDIT_REPORT.md](./docs/SECURITY_AUDIT_REPORT.md)
- **Quick Start**: [docs/QUICK_START.md](./docs/QUICK_START.md)

### Contact

For enterprise support and custom development:
- Email: support@traidsolutions.com
- Website: https://traidsolutions.com

---

## 📝 License

This project is proprietary software. All rights reserved.

Copyright © 2024-2026 Traid Solutions

---

## 🏆 Acknowledgments

- Laravel Framework - Taylor Otwell and contributors
- Vue.js - Evan You and contributors
- FreeRADIUS - The FreeRADIUS project
- MikroTik - RouterOS platform
- Docker - Containerization platform

---

**Version**: 2.0.0  
**Last Updated**: May 2026  
**Status**: Production Ready ✅  
**Architecture Grade**: A (Enterprise-Ready)
