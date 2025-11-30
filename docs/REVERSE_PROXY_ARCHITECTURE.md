# 🏗️ Reverse Proxy Architecture

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                         Client Browser                       │
│                    http://localhost:80                       │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                    NGINX Reverse Proxy                       │
│                    (traidnet-nginx:80)                       │
│                                                              │
│  Routes:                                                     │
│  ┌────────────────────────────────────────────────────┐    │
│  │ /              → Frontend (Vue.js)                  │    │
│  │ /api/*         → Backend (Laravel API)              │    │
│  │ /app           → WebSocket (Soketi)                 │    │
│  │ /broadcasting  → Broadcasting Auth                  │    │
│  └────────────────────────────────────────────────────┘    │
└──────┬──────────────────┬──────────────────┬────────────────┘
       │                  │                  │
       ▼                  ▼                  ▼
┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│  Frontend    │  │   Backend    │  │   Soketi     │
│  Container   │  │  Container   │  │  Container   │
│  (Vue.js)    │  │  (Laravel)   │  │ (WebSocket)  │
│  Port: 80    │  │  Port: 9000  │  │  Port: 6001  │
└──────────────┘  └──────┬───────┘  └──────────────┘
                         │
                         ▼
                  ┌──────────────┐
                  │  PostgreSQL  │
                  │  Database    │
                  │  Port: 5432  │
                  └──────────────┘
```

---

## 🔧 Configuration Details

### 1. Nginx Reverse Proxy Configuration

**File**: `nginx/nginx.conf`

#### Frontend Routing
```nginx
location / {
    proxy_pass http://traidnet-frontend:80;
    # Serves Vue.js application
}
```

#### Backend API Routing
```nginx
location ~ ^/api(/.*)?$ {
    fastcgi_pass traidnet-backend:9000;
    # Serves Laravel API
}
```

#### WebSocket Routing
```nginx
location /app {
    proxy_pass http://traidnet-soketi:6001;
    # Serves Soketi WebSocket
}
```

#### Broadcasting Auth
```nginx
location ~ ^/(api/)?broadcasting/auth$ {
    fastcgi_pass traidnet-backend:9000;
    # Handles WebSocket authentication
}
```

---

### 2. Frontend Environment Variables

**File**: `frontend/.env`

```env
# ✅ CORRECT - All requests go through nginx on port 80
VITE_API_URL=http://localhost/api
VITE_API_BASE_URL=http://localhost/api

# ❌ WRONG - Don't use direct backend port
# VITE_API_URL=http://localhost:8000/api  # ❌ Backend not exposed directly
```

**Why?**
- Frontend runs inside Docker container
- All external requests go through nginx reverse proxy
- Nginx routes `/api/*` to backend container
- Backend container is NOT exposed on port 8000 externally

---

### 3. Docker Compose Configuration

**File**: `docker-compose.yml`

```yaml
services:
  traidnet-nginx:
    ports:
      - "80:80"      # ✅ Only nginx is exposed
      - "443:443"    # ✅ For SSL

  traidnet-frontend:
    # NO ports exposed - accessed through nginx
    networks:
      - traidnet-network

  traidnet-backend:
    # NO ports exposed - accessed through nginx
    networks:
      - traidnet-network

  traidnet-soketi:
    ports:
      - "6001:6001"  # ✅ Exposed for direct WebSocket
    networks:
      - traidnet-network
```

---

## 🌐 URL Routing

### From Browser (External)

| URL | Nginx Routes To | Container | Purpose |
|-----|----------------|-----------|---------|
| `http://localhost/` | Frontend | `traidnet-frontend:80` | Vue.js app |
| `http://localhost/login` | Frontend | `traidnet-frontend:80` | Login page |
| `http://localhost/register` | Frontend | `traidnet-frontend:80` | Registration |
| `http://localhost/api/login` | Backend | `traidnet-backend:9000` | Login API |
| `http://localhost/api/register/tenant` | Backend | `traidnet-backend:9000` | Register API |
| `http://localhost/app` | Soketi | `traidnet-soketi:6001` | WebSocket |
| `http://localhost/api/broadcasting/auth` | Backend | `traidnet-backend:9000` | WS Auth |

### From Frontend Container (Internal)

Frontend makes API calls to:
```javascript
// Frontend code
axios.post('/api/login', data)  // Relative URL
// OR
axios.post('http://localhost/api/login', data)  // Absolute URL through nginx
```

Both work because:
1. Frontend is served from `http://localhost/`
2. Relative URLs resolve to same domain
3. Nginx routes `/api/*` to backend

---

## 🔐 Security Benefits

### 1. Single Entry Point
- ✅ Only nginx exposed on port 80/443
- ✅ Backend and frontend not directly accessible
- ✅ Easier to secure with firewall rules

### 2. SSL Termination
```nginx
server {
    listen 443 ssl;
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;
    
    # All backend services use HTTP internally
    # SSL handled by nginx
}
```

### 3. Rate Limiting
```nginx
limit_req_zone $binary_remote_addr zone=api:10m rate=10r/s;

location /api/ {
    limit_req zone=api burst=20;
}
```

### 4. CORS Handling
```nginx
# Centralized CORS in nginx
add_header Access-Control-Allow-Origin * always;
add_header Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS" always;
```

---

## 🚀 Deployment Steps

### Development (Local)

```bash
# 1. Start all services
docker-compose up -d

# 2. Check nginx is running
docker-compose ps traidnet-nginx

# 3. Access application
# Frontend: http://localhost/
# API: http://localhost/api/health
# WebSocket: ws://localhost/app

# 4. View logs
docker-compose logs -f traidnet-nginx
```

### Production

```bash
# 1. Update .env for production domain
VITE_API_URL=https://yourdomain.com/api
VITE_PUSHER_HOST=yourdomain.com
VITE_PUSHER_SCHEME=wss  # Use secure WebSocket

# 2. Add SSL certificates to nginx
# 3. Update nginx.conf for SSL
# 4. Deploy with docker-compose
```

---

## 🧪 Testing

### Test 1: Frontend Access
```bash
curl http://localhost/
# Should return HTML from Vue.js app
```

### Test 2: API Access
```bash
curl http://localhost/api/health
# Should return JSON from Laravel
```

### Test 3: Login API
```bash
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"login":"sysadmin","password":"Admin@123!"}'
# Should return auth token
```

### Test 4: WebSocket
```javascript
// In browser console
const echo = new Echo({
  broadcaster: 'pusher',
  key: 'app-key',
  wsHost: 'localhost',
  wsPort: 80,
  wssPort: 443,
  forceTLS: false,
  disableStats: true,
  enabledTransports: ['ws', 'wss'],
  cluster: 'mt1',
  authEndpoint: '/api/broadcasting/auth',
})
```

---

## 🔍 Troubleshooting

### Issue: API calls return 404

**Check nginx routing:**
```bash
docker exec -it traidnet-nginx nginx -t
docker-compose logs traidnet-nginx
```

**Verify backend is running:**
```bash
docker-compose ps traidnet-backend
docker-compose logs traidnet-backend
```

### Issue: WebSocket not connecting

**Check Soketi:**
```bash
docker-compose ps traidnet-soketi
docker-compose logs traidnet-soketi
```

**Verify nginx WebSocket proxy:**
```bash
# Check nginx config
docker exec -it traidnet-nginx cat /etc/nginx/conf.d/default.conf | grep -A 20 "location /app"
```

### Issue: CORS errors

**Check nginx CORS headers:**
```bash
curl -I http://localhost/api/health
# Should see Access-Control-Allow-Origin header
```

---

## 📊 Performance Optimization

### 1. Nginx Caching
```nginx
# Cache static assets
location ~* \.(css|js|png|jpg|jpeg|gif|ico)$ {
    add_header Cache-Control "public, max-age=31536000, immutable";
}
```

### 2. Gzip Compression
```nginx
gzip on;
gzip_types text/plain text/css application/javascript application/json;
gzip_min_length 1000;
```

### 3. Connection Pooling
```nginx
upstream backend {
    server traidnet-backend:9000;
    keepalive 32;
}
```

---

## 🎯 Best Practices

### ✅ DO

1. **Use relative URLs in frontend**
   ```javascript
   axios.post('/api/login', data)  // ✅ Good
   ```

2. **Let nginx handle CORS**
   - Centralized configuration
   - Consistent across all endpoints

3. **Use environment variables**
   ```env
   VITE_API_URL=http://localhost/api  # ✅ Through nginx
   ```

4. **Monitor nginx logs**
   ```bash
   docker-compose logs -f traidnet-nginx
   ```

### ❌ DON'T

1. **Don't bypass nginx**
   ```javascript
   axios.post('http://localhost:9000/api/login')  // ❌ Wrong
   ```

2. **Don't expose backend ports**
   ```yaml
   traidnet-backend:
     ports:
       - "9000:9000"  # ❌ Not needed
   ```

3. **Don't hardcode URLs**
   ```javascript
   const API_URL = 'http://localhost:8000'  // ❌ Wrong
   ```

---

## 📝 Summary

### Architecture
- ✅ Nginx reverse proxy on port 80
- ✅ Frontend served through nginx
- ✅ Backend API through nginx at `/api`
- ✅ WebSocket through nginx at `/app`

### Configuration
- ✅ `VITE_API_URL=http://localhost/api`
- ✅ `VITE_API_BASE_URL=http://localhost/api`
- ✅ All requests go through nginx

### Benefits
- ✅ Single entry point
- ✅ Centralized security
- ✅ Easy SSL termination
- ✅ Better performance
- ✅ Simplified deployment

---

**Status**: ✅ **CORRECTLY CONFIGURED**  
**Architecture**: Reverse Proxy  
**Entry Point**: Nginx on port 80
