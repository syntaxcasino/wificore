# Load Balancing Configuration Guide

**Priority:** P3 (Low Priority)  
**Status:** Configuration Ready  
**Date:** January 1, 2026

---

## Overview

This guide provides instructions for configuring load balancing for the WiFiCore application to achieve high availability and horizontal scalability.

---

## Architecture Options

### Option 1: Nginx Load Balancer (Recommended)

```nginx
# nginx-lb.conf
upstream backend_servers {
    least_conn;  # Load balancing method
    
    server backend1:9000 weight=3 max_fails=3 fail_timeout=30s;
    server backend2:9000 weight=3 max_fails=3 fail_timeout=30s;
    server backend3:9000 weight=2 max_fails=3 fail_timeout=30s;
    
    keepalive 32;
}

upstream frontend_servers {
    server frontend1:80;
    server frontend2:80;
    server frontend3:80;
}

server {
    listen 80;
    
    location /api {
        proxy_pass http://backend_servers;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
    
    location / {
        proxy_pass http://frontend_servers;
        proxy_set_header Host $host;
    }
}
```

### Option 2: HAProxy

```haproxy
# haproxy.cfg
global
    maxconn 4096
    
defaults
    mode http
    timeout connect 5000ms
    timeout client 50000ms
    timeout server 50000ms

frontend http_front
    bind *:80
    default_backend backend_servers

backend backend_servers
    balance roundrobin
    option httpchk GET /api/health/ping
    server backend1 backend1:9000 check
    server backend2 backend2:9000 check
    server backend3 backend3:9000 check
```

---

## Docker Compose Configuration

### docker-compose.loadbalanced.yml

```yaml
version: '3.8'

services:
  nginx-lb:
    image: nginx:latest
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx-lb/nginx.conf:/etc/nginx/nginx.conf:ro
    depends_on:
      - backend1
      - backend2
      - frontend1
      - frontend2
    networks:
      - wificore-network

  backend1:
    build: ./backend
    environment:
      - APP_NAME=WiFiCore-Backend-1
    volumes:
      - ./backend:/var/www/html
    networks:
      - wificore-network

  backend2:
    build: ./backend
    environment:
      - APP_NAME=WiFiCore-Backend-2
    volumes:
      - ./backend:/var/www/html
    networks:
      - wificore-network

  frontend1:
    build: ./frontend
    networks:
      - wificore-network

  frontend2:
    build: ./frontend
    networks:
      - wificore-network

  postgres:
    image: postgres:16
    environment:
      - POSTGRES_DB=wms_770_ts
    volumes:
      - postgres_data:/var/lib/postgresql/data
    networks:
      - wificore-network

  redis:
    image: redis:7-alpine
    networks:
      - wificore-network

networks:
  wificore-network:
    driver: bridge

volumes:
  postgres_data:
```

---

## Session Management

### Sticky Sessions (Session Affinity)

```nginx
upstream backend_servers {
    ip_hash;  # Ensures same client goes to same backend
    
    server backend1:9000;
    server backend2:9000;
}
```

### Shared Session Storage (Recommended)

Use Redis for session storage:

```env
SESSION_DRIVER=redis
REDIS_HOST=redis-cluster
```

---

## Health Checks

### Backend Health Check Endpoint

Already implemented at `/api/health/ping`

### Nginx Health Check Configuration

```nginx
upstream backend_servers {
    server backend1:9000 max_fails=3 fail_timeout=30s;
    server backend2:9000 max_fails=3 fail_timeout=30s;
    
    # Active health checks (nginx plus)
    # health_check interval=5s fails=3 passes=2;
}
```

---

## Database Considerations

### Read Replicas

```env
# Master (write)
DB_HOST_WRITE=postgres-master

# Replicas (read)
DB_HOST_READ=postgres-replica1,postgres-replica2
```

### Connection Pooling

```php
// config/database.php
'pgsql' => [
    'pool' => [
        'min_connections' => 2,
        'max_connections' => 10,
    ],
],
```

---

## Caching Strategy

### Distributed Cache (Redis Cluster)

```yaml
redis-cluster:
  image: redis:7-alpine
  command: redis-server --cluster-enabled yes
  ports:
    - "6379-6384:6379-6384"
```

### Cache Configuration

```env
CACHE_DRIVER=redis
REDIS_CLUSTER=true
REDIS_CLUSTER_NODES=redis1:6379,redis2:6379,redis3:6379
```

---

## Queue Workers

### Multiple Queue Workers

```yaml
queue-worker-1:
  build: ./backend
  command: php artisan queue:work --queue=high,default
  
queue-worker-2:
  build: ./backend
  command: php artisan queue:work --queue=default,low
```

---

## Monitoring

### Health Check Script

```bash
#!/bin/bash
# check-backends.sh

BACKENDS=("backend1:9000" "backend2:9000" "backend3:9000")

for backend in "${BACKENDS[@]}"; do
    if curl -f "http://$backend/api/health/ping" > /dev/null 2>&1; then
        echo "✓ $backend is healthy"
    else
        echo "✗ $backend is down"
        # Send alert
    fi
done
```

---

## Auto-Scaling

### Docker Swarm

```bash
docker service scale wificore-backend=5
```

### Kubernetes

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: backend-hpa
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: wificore-backend
  minReplicas: 2
  maxReplicas: 10
  metrics:
  - type: Resource
    resource:
      name: cpu
      target:
        type: Utilization
        averageUtilization: 70
```

---

## Testing Load Balancing

### Apache Bench

```bash
ab -n 10000 -c 100 http://yourdomain.com/api/health/ping
```

### Load Testing Script

```bash
#!/bin/bash
for i in {1..1000}; do
    curl http://yourdomain.com/api/health/ping &
done
wait
```

---

## Deployment Strategy

### Blue-Green Deployment

1. Deploy new version to "green" environment
2. Test green environment
3. Switch load balancer to green
4. Keep blue as rollback option

### Rolling Update

```bash
# Update one backend at a time
docker-compose stop backend1
docker-compose up -d backend1
# Wait and verify
docker-compose stop backend2
docker-compose up -d backend2
```

---

## Troubleshooting

### Backend Not Receiving Traffic

```bash
# Check nginx upstream status
curl http://localhost/nginx_status

# Check backend health
docker-compose logs backend1
```

### Uneven Load Distribution

```nginx
# Use least_conn instead of round_robin
upstream backend_servers {
    least_conn;
    server backend1:9000;
    server backend2:9000;
}
```

---

**Status:** Ready for Implementation  
**Estimated Time:** 4-8 hours  
**Risk Level:** Medium (requires careful testing)
