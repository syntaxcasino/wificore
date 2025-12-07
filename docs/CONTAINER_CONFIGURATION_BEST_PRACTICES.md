# Container Configuration Best Practices
## Baked Configurations vs Volume Mounts
**Date**: December 7, 2025 - 8:05 AM

---

## 🎯 **Principle**: All Configurations Baked Into Containers

**Rule**: Configuration files should be **COPIED** into container images during build, **NOT** mounted as volumes.

---

## ✅ **Why Bake Configurations?**

### **1. Portability**
- ✅ Container is self-contained
- ✅ No external file dependencies
- ✅ Works anywhere Docker runs
- ✅ Easy to ship to different environments

### **2. Consistency**
- ✅ Same configuration everywhere
- ✅ No "works on my machine" issues
- ✅ Reproducible builds
- ✅ Version controlled with code

### **3. Security**
- ✅ Immutable configurations
- ✅ No accidental file modifications
- ✅ Audit trail via image layers
- ✅ Signed images guarantee integrity

### **4. Performance**
- ✅ No I/O overhead from host mounts
- ✅ Faster container startup
- ✅ Better caching
- ✅ Optimized for production

### **5. Simplicity**
- ✅ Fewer moving parts
- ✅ Easier debugging
- ✅ Simpler deployment
- ✅ Less configuration drift

---

## 📋 **Services Configuration Status**

### **✅ CORRECT: Baked Configurations**

#### **1. PostgreSQL**
```dockerfile
# postgres/Dockerfile
FROM postgres:16.10-trixie

# Bake init.sql into image
COPY init.sql /docker-entrypoint-initdb.d/init.sql
RUN chmod 644 /docker-entrypoint-initdb.d/init.sql
```

```yaml
# docker-compose.yml
traidnet-postgres:
  build:
    context: ./postgres
    dockerfile: Dockerfile
  # ✅ No config volume mounts
  volumes:
    - postgres_data:/var/lib/postgresql/data  # ✅ Data only
```

**Benefits**:
- ✅ `init.sql` is part of the image
- ✅ No external file dependencies
- ✅ Consistent across all environments

---

#### **2. FreeRADIUS**
```dockerfile
# freeradius/Dockerfile
FROM freeradius/freeradius-server:latest

# Bake all configs into image
COPY clients.conf /opt/etc/raddb/clients.conf
COPY sql /opt/etc/raddb/mods-available/sql
COPY dictionary /opt/etc/raddb/dictionary

# Set permissions
RUN chmod 640 /opt/etc/raddb/clients.conf \
    && chmod 640 /opt/etc/raddb/mods-available/sql \
    && chmod 644 /opt/etc/raddb/dictionary
```

```yaml
# docker-compose.yml
traidnet-freeradius:
  build:
    context: ./freeradius
    dockerfile: Dockerfile
  # ✅ No volumes - all configs baked in
```

**Benefits**:
- ✅ All RADIUS configs in image
- ✅ No runtime file dependencies
- ✅ Immutable configuration

---

#### **3. Backend (Laravel)**
```dockerfile
# backend/Dockerfile
FROM php:8.3-fpm

# Copy application code
COPY . /var/www/html

# Copy configs
COPY php.ini /usr/local/etc/php/php.ini
COPY php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# Set permissions
RUN chown -R www-data:www-data /var/www/html
```

```yaml
# docker-compose.yml
traidnet-backend:
  build:
    context: ./backend
    dockerfile: Dockerfile
  # ✅ Only runtime data volumes
  volumes:
    - laravel-storage:/var/www/html/storage:rw  # ✅ Runtime data
    - laravel-logs:/var/www/html/storage/logs:rw  # ✅ Logs
```

**Benefits**:
- ✅ Application code in image
- ✅ PHP configs in image
- ✅ Only runtime data in volumes

---

#### **4. Frontend (Vue.js)**
```dockerfile
# frontend/Dockerfile
FROM node:20-alpine AS builder

# Build application
COPY . /app
WORKDIR /app
RUN npm install && npm run build

# Production image
FROM nginx:alpine
COPY --from=builder /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/nginx.conf
```

```yaml
# docker-compose.yml
traidnet-frontend:
  build:
    context: ./frontend
  # ✅ No volumes - all baked in
```

**Benefits**:
- ✅ Built assets in image
- ✅ Nginx config in image
- ✅ No external dependencies

---

#### **5. Nginx (Reverse Proxy)**
```dockerfile
# nginx/Dockerfile
FROM nginx:alpine

# Copy nginx configuration
COPY nginx.conf /etc/nginx/nginx.conf
COPY conf.d/ /etc/nginx/conf.d/

# Copy SSL certificates (if any)
COPY ssl/ /etc/nginx/ssl/
```

```yaml
# docker-compose.yml
traidnet-nginx:
  build:
    context: ./nginx
  # ✅ No volumes - all baked in
```

**Benefits**:
- ✅ Nginx config in image
- ✅ SSL certs in image
- ✅ Immutable proxy configuration

---

#### **6. Soketi (WebSocket Server)**
```dockerfile
# soketi/Dockerfile
FROM quay.io/soketi/soketi:latest-16-alpine

# Copy custom config if needed
COPY soketi.json /app/soketi.json
```

```yaml
# docker-compose.yml
traidnet-soketi:
  build:
    context: ./soketi
    dockerfile: Dockerfile
  # ✅ Config via environment variables (12-factor app)
  environment:
    - SOKETI_DEFAULT_APP_ID=app-id
    - SOKETI_DEFAULT_APP_KEY=app-key
```

**Benefits**:
- ✅ Config in environment (12-factor)
- ✅ No file dependencies

---

## ❌ **WRONG: Volume-Mounted Configurations**

### **Anti-Pattern Example**:
```yaml
# ❌ DON'T DO THIS
traidnet-postgres:
  image: postgres:16.10-trixie
  volumes:
    - ./postgres/init.sql:/docker-entrypoint-initdb.d/init.sql  # ❌ BAD
    - ./postgres/postgresql.conf:/etc/postgresql/postgresql.conf  # ❌ BAD
```

### **Problems**:
- ❌ External file dependency
- ❌ Different configs in dev/prod
- ❌ File permissions issues
- ❌ Slower container startup
- ❌ Configuration drift
- ❌ Hard to debug

---

## 📝 **When to Use Volumes**

### **✅ ONLY for Runtime Data**:

1. **Database Data**:
   ```yaml
   volumes:
     - postgres_data:/var/lib/postgresql/data  # ✅ Database files
   ```

2. **Application Storage**:
   ```yaml
   volumes:
     - laravel-storage:/var/www/html/storage  # ✅ Uploaded files
   ```

3. **Logs**:
   ```yaml
   volumes:
     - laravel-logs:/var/www/html/storage/logs  # ✅ Application logs
   ```

4. **Cache Data**:
   ```yaml
   volumes:
     - redis_data:/data  # ✅ Redis persistence
   ```

### **❌ NEVER for Configuration**:
- ❌ Application code
- ❌ Configuration files
- ❌ Static assets
- ❌ Compiled binaries
- ❌ SSL certificates
- ❌ Environment configs

---

## 🔄 **Migration from Volume Mounts to Baked Configs**

### **Before (Volume Mount)**:
```yaml
traidnet-postgres:
  image: postgres:16.10-trixie
  volumes:
    - ./postgres/init.sql:/docker-entrypoint-initdb.d/init.sql  # ❌
```

### **After (Baked Config)**:

**1. Create Dockerfile**:
```dockerfile
# postgres/Dockerfile
FROM postgres:16.10-trixie

COPY init.sql /docker-entrypoint-initdb.d/init.sql
RUN chmod 644 /docker-entrypoint-initdb.d/init.sql
```

**2. Update docker-compose.yml**:
```yaml
traidnet-postgres:
  build:
    context: ./postgres
    dockerfile: Dockerfile
  # ✅ No config volumes
```

**3. Rebuild**:
```bash
docker-compose down
docker-compose up -d --build
```

---

## 🚀 **Deployment Benefits**

### **Development**:
```bash
# Build once
docker-compose build

# Run anywhere
docker-compose up -d
```

### **Production**:
```bash
# Build and tag
docker build -t myapp/postgres:1.0.0 ./postgres

# Push to registry
docker push myapp/postgres:1.0.0

# Deploy anywhere
docker pull myapp/postgres:1.0.0
docker run myapp/postgres:1.0.0
```

### **Benefits**:
- ✅ Same image in dev/staging/prod
- ✅ No config file management
- ✅ Easy rollback (just use previous image)
- ✅ Immutable infrastructure

---

## 📊 **Current Architecture Status**

### **Services with Baked Configs**: ✅

| Service | Status | Config Location |
|---------|--------|----------------|
| PostgreSQL | ✅ Baked | `postgres/Dockerfile` |
| FreeRADIUS | ✅ Baked | `freeradius/Dockerfile` |
| Backend | ✅ Baked | `backend/Dockerfile` |
| Frontend | ✅ Baked | `frontend/Dockerfile` |
| Nginx | ✅ Baked | `nginx/Dockerfile` |
| Soketi | ✅ Baked | `soketi/Dockerfile` |
| Redis | ✅ No config | Uses command args |

### **Volume Usage**: ✅ Correct

| Volume | Purpose | Status |
|--------|---------|--------|
| `postgres_data` | Database files | ✅ Runtime data |
| `redis_data` | Cache persistence | ✅ Runtime data |
| `laravel-storage` | Uploaded files | ✅ Runtime data |
| `laravel-logs` | Application logs | ✅ Runtime data |

---

## ✅ **Summary**

### **Configuration Strategy**:
```
✅ Baked into Images:
   - Application code
   - Configuration files
   - Static assets
   - Dependencies
   - Scripts

✅ Volumes for:
   - Database data
   - Uploaded files
   - Logs
   - Cache data
```

### **Benefits Achieved**:
- ✅ **Portability**: Containers run anywhere
- ✅ **Consistency**: Same config everywhere
- ✅ **Security**: Immutable configurations
- ✅ **Performance**: Faster startup
- ✅ **Simplicity**: Fewer moving parts

### **Deployment**:
```bash
# Build images
docker-compose build

# Deploy anywhere
docker-compose up -d

# No config file management needed!
```

---

**Status**: ✅ **ALL CONFIGURATIONS BAKED INTO CONTAINERS**  
**Architecture**: ✅ **PRODUCTION-READY**  
**Best Practices**: ✅ **FULLY COMPLIANT**
