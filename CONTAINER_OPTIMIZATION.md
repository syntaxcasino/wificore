# Container Size Optimization Guide

## Overview
This document describes the container optimization strategy implemented to reduce container sizes from hundreds of MBs to few MBs level.

## Before Optimization

| Container | Original Size | Target Size | Status |
|-----------|--------------|-------------|--------|
| Backend | 879 MB | <100 MB | ❌ Too Large |
| Soketi | 523 MB | <50 MB | ❌ Too Large |
| FreeRADIUS | 169 MB | <30 MB | ❌ Too Large |
| Frontend | 31.2 MB | <30 MB | ✅ Acceptable |
| Nginx | 20.2 MB | <25 MB | ✅ Acceptable |
| Redis | 7 MB (Alpine) | <10 MB | ✅ Optimal |
| PostgreSQL | Official Image | N/A | ✅ Acceptable |

## Optimization Strategy

### 1. Backend (PHP-FPM + Laravel)

**Changes:**
- Switched from `php:8.4-fpm` (Debian) to `php:8.2-fpm-alpine`
- Multi-stage build with separate composer stage
- Aggressive .dockerignore to exclude:
  - Test files and scripts
  - Documentation
  - Development dependencies
  - Git repository
  - IDE configurations
  - Deployment scripts
- Removed build dependencies after installation
- Optimized layer caching

**Expected Result:** 879 MB → ~80-100 MB

### 2. Soketi (WebSocket Server)

**Changes:**
- Built from `node:20-alpine` instead of Debian-based `quay.io/soketi/soketi`
- Install soketi via npm globally
- Minimal dependencies (only curl for healthchecks)
- Run as non-root user
- Clean npm cache after installation

**Expected Result:** 523 MB → ~40-50 MB

### 3. FreeRADIUS

**Changes:**
- Already using Alpine base
- Removed unnecessary packages
- Optimized file permissions in single layer
- Clean up temp files and cache
- Minimal PostgreSQL client libraries

**Expected Result:** 169 MB → ~25-30 MB

### 4. Frontend (Vue.js)

**Changes:**
- Multi-stage build with `node:20-alpine`
- Production-only npm dependencies
- Clean npm cache
- Comprehensive .dockerignore
- Minimal nginx alpine image

**Expected Result:** 31.2 MB → ~25-30 MB

### 5. Nginx (Reverse Proxy)

**Changes:**
- Already using `nginx:alpine-slim`
- Added .dockerignore
- Minimal configuration

**Expected Result:** 20.2 MB → ~18-20 MB

## .dockerignore Files

### Backend
- Test files (tests/, phpunit.xml)
- Documentation (*.md, docs/)
- Scripts (deploy.sh, *.ps1, test*.php)
- Development files (node_modules, package.json)
- Git repository
- IDE configurations
- Certificates (should be mounted)

### Frontend
- node_modules
- Test files (e2e/, playwright.config.js)
- Documentation
- Scripts
- IDE configurations
- Build output (dist - created during build)

### Soketi, Nginx, FreeRADIUS
- Git repository
- Documentation
- IDE configurations
- OS files

## Build Commands

### Rebuild All Containers (Optimized)
```bash
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### Check Container Sizes
```bash
docker images --format "table {{.Repository}}\t{{.Tag}}\t{{.Size}}" | findstr traidnet
```

### Individual Container Rebuild
```bash
# Backend
docker-compose build --no-cache traidnet-backend

# Frontend
docker-compose build --no-cache traidnet-frontend

# Soketi
docker-compose build --no-cache traidnet-soketi

# FreeRADIUS
docker-compose build --no-cache traidnet-freeradius
```

## Best Practices Applied

1. **Multi-stage Builds**: Separate build and runtime stages
2. **Alpine Linux**: Use Alpine-based images where possible
3. **Layer Optimization**: Combine RUN commands to reduce layers
4. **Cache Cleaning**: Remove package manager caches
5. **Build Dependencies**: Remove after installation
6. **.dockerignore**: Exclude unnecessary files from build context
7. **Production Dependencies**: Install only production packages
8. **Minimal Base Images**: Use slim/alpine variants

## Verification

After rebuilding, verify:

1. **Container Sizes**: All containers should be <100 MB
2. **Functionality**: All services should work correctly
3. **Health Checks**: All containers should pass health checks
4. **Performance**: No degradation in application performance

## Rollback

If issues occur, backup Dockerfiles are available:
- `backend/Dockerfile.backup`
- `frontend/Dockerfile.backup`
- `soketi/Dockerfile.backup`
- `freeradius/Dockerfile.backup`

To rollback:
```bash
Copy-Item -Path ".\backend\Dockerfile.backup" -Destination ".\backend\Dockerfile" -Force
Copy-Item -Path ".\frontend\Dockerfile.backup" -Destination ".\frontend\Dockerfile" -Force
Copy-Item -Path ".\soketi\Dockerfile.backup" -Destination ".\soketi\Dockerfile" -Force
Copy-Item -Path ".\freeradius\Dockerfile.backup" -Destination ".\freeradius\Dockerfile" -Force
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

## Monitoring

Monitor container resource usage:
```bash
docker stats
```

## Notes

- PostgreSQL and Redis use official images (optimized by maintainers)
- Supervisor runs inside backend container (no separate container needed)
- All persistent data uses volumes (not affected by optimization)
- Configuration files are copied during build (no volume mounts for configs)
