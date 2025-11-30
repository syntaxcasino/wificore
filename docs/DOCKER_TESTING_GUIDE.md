# Docker Environment Testing Guide

**Environment:** Docker Compose  
**Date:** October 12, 2025

---

## 🐳 Docker Architecture

Your application runs in Docker containers:
- **traidnet-frontend** - Vue.js frontend (Vite)
- **traidnet-backend** - Laravel backend (PHP-FPM)
- **traidnet-nginx** - Nginx reverse proxy (Port 80)
- **traidnet-postgres** - PostgreSQL database
- **traidnet-redis** - Redis cache
- **traidnet-soketi** - WebSocket server
- **traidnet-freeradius** - FreeRADIUS server

**Access URL:** `http://localhost` (Port 80)

---

## 🚀 Quick Start Testing

### **Step 1: Ensure Containers Are Running**

```bash
# Check container status
docker-compose ps

# If not running, start them
docker-compose up -d

# Wait for services to be ready (30 seconds)
```

### **Step 2: Run Docker Test Script**

```bash
# Make executable
chmod +x tests/docker-test.sh

# Run test
./tests/docker-test.sh
```

This will:
- ✅ Check Docker is running
- ✅ Verify all containers are up
- ✅ Test all routes
- ✅ Check component files exist
- ✅ Test API endpoints
- ✅ Show container health

### **Step 3: Open Browser and Test**

Once the script passes, open: **`http://localhost`**

Test these URLs:
1. **Admin Users:** `http://localhost/dashboard/users/all`
2. **PPPoE Users:** `http://localhost/dashboard/pppoe/users`
3. **Hotspot Users:** `http://localhost/dashboard/hotspot/users`

---

## 🔄 If You Made Changes to Frontend Code

### **Option 1: Rebuild Frontend Container (Recommended)**

```bash
# Use the rebuild script
./tests/docker-rebuild-frontend.sh
```

Or manually:
```bash
# Stop, rebuild, and start
docker-compose stop traidnet-frontend
docker-compose build --no-cache traidnet-frontend
docker-compose up -d traidnet-frontend
```

### **Option 2: Restart Without Rebuild (Quick)**

```bash
# If you only changed files that are mounted as volumes
docker-compose restart traidnet-frontend
```

---

## 🔍 Troubleshooting

### **Problem: Containers Not Running**

```bash
# Check status
docker-compose ps

# Start all containers
docker-compose up -d

# Check logs
docker-compose logs -f traidnet-frontend
```

### **Problem: Frontend Not Accessible**

```bash
# Check nginx logs
docker-compose logs traidnet-nginx

# Check frontend logs
docker-compose logs traidnet-frontend

# Restart nginx
docker-compose restart traidnet-nginx
```

### **Problem: Changes Not Showing**

```bash
# Rebuild frontend container
docker-compose build --no-cache traidnet-frontend
docker-compose up -d traidnet-frontend

# Clear browser cache (Ctrl+Shift+R)
```

### **Problem: API Errors**

```bash
# Check backend logs
docker-compose logs traidnet-backend

# Check database connection
docker-compose exec traidnet-backend php artisan migrate:status

# Restart backend
docker-compose restart traidnet-backend
```

---

## 🧪 Manual Testing in Docker

### **Test 1: Admin Users View**

1. Open: `http://localhost/dashboard/users/all`
2. **Expected:**
   - ✅ Title: "Admin Users"
   - ✅ Icon: Shield
   - ✅ Button: "Add Admin"
   - ✅ Table columns: Admin User, Contact, Role, Status, Last Login, Created, Actions
   - ✅ Avatars: Indigo/Purple gradient
   - ✅ Filter by Role (Super Admin, Admin, Staff)

### **Test 2: PPPoE Users View**

1. Open: `http://localhost/dashboard/pppoe/users`
2. **Expected:**
   - ✅ Title: "PPPoE Users"
   - ✅ Icon: Network
   - ✅ Button: "Add PPPoE User"
   - ✅ Table columns: User, Contact, Package, Status, Expiry, Actions
   - ✅ Avatars: Purple/Indigo gradient
   - ✅ Filter by Status and Package

### **Test 3: Hotspot Users View**

1. Open: `http://localhost/dashboard/hotspot/users`
2. **Expected:**
   - ✅ Title: "Hotspot Users"
   - ✅ Icon: Wifi
   - ✅ Button: "Generate Vouchers"
   - ✅ Table columns: User, Voucher Code, Package, Status, Expiry, Data Used, Actions
   - ✅ Avatars: Blue/Cyan gradient
   - ✅ NO edit/delete buttons (read-only)

---

## 🐛 Debugging Commands

### **View Container Logs**

```bash
# All logs
docker-compose logs -f

# Specific container
docker-compose logs -f traidnet-frontend
docker-compose logs -f traidnet-backend
docker-compose logs -f traidnet-nginx
```

### **Execute Commands in Container**

```bash
# Check if files exist in frontend
docker exec traidnet-frontend ls -la src/views/dashboard/users/

# Check backend status
docker exec traidnet-backend php artisan --version

# Check database
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT COUNT(*) FROM users;"
```

### **Restart Specific Services**

```bash
# Restart frontend only
docker-compose restart traidnet-frontend

# Restart backend only
docker-compose restart traidnet-backend

# Restart all
docker-compose restart
```

### **Rebuild Everything (Nuclear Option)**

```bash
# Stop all containers
docker-compose down

# Rebuild all (no cache)
docker-compose build --no-cache

# Start all
docker-compose up -d

# Wait for services
sleep 30
```

---

## 📊 Health Checks

### **Check Container Health**

```bash
# All containers
docker-compose ps

# Specific health status
docker inspect --format='{{.State.Health.Status}}' traidnet-frontend
docker inspect --format='{{.State.Health.Status}}' traidnet-backend
docker inspect --format='{{.State.Health.Status}}' traidnet-postgres
```

### **Test Endpoints**

```bash
# Frontend
curl -I http://localhost

# Backend API
curl -I http://localhost/api/health

# WebSocket
curl -I http://localhost:6001
```

---

## 🎯 Testing Checklist

### Pre-Testing
- [ ] Docker Desktop is running
- [ ] All containers are up: `docker-compose ps`
- [ ] Frontend is accessible: `http://localhost`
- [ ] No errors in logs: `docker-compose logs`

### Route Testing
- [ ] Admin Users loads: `/dashboard/users/all`
- [ ] PPPoE Users loads: `/dashboard/pppoe/users`
- [ ] Hotspot Users loads: `/dashboard/hotspot/users`
- [ ] Component Showcase loads: `/component-showcase`

### Visual Testing
- [ ] Admin Users has Shield icon
- [ ] PPPoE Users has Network icon
- [ ] Hotspot Users has Wifi icon
- [ ] Avatar colors are distinct
- [ ] Sidebar shows correct menus

### Functional Testing
- [ ] Search works in all views
- [ ] Filters work correctly
- [ ] Pagination works
- [ ] Modals open/close
- [ ] Actions trigger correctly

---

## 📝 Common Issues

### Issue 1: Port 80 Already in Use

**Solution:**
```bash
# Find what's using port 80
netstat -ano | findstr :80

# Stop the process or change docker-compose.yml ports
```

### Issue 2: Frontend Container Keeps Restarting

**Solution:**
```bash
# Check logs
docker-compose logs traidnet-frontend

# Common causes:
# - Build errors
# - Missing dependencies
# - Port conflicts
```

### Issue 3: Changes Not Reflecting

**Solution:**
```bash
# Rebuild frontend
docker-compose build --no-cache traidnet-frontend
docker-compose up -d traidnet-frontend

# Clear browser cache (Ctrl+Shift+R)
```

### Issue 4: Database Connection Errors

**Solution:**
```bash
# Check postgres is healthy
docker-compose ps traidnet-postgres

# Run migrations
docker-compose exec traidnet-backend php artisan migrate

# Check connection
docker-compose exec traidnet-backend php artisan tinker
>>> DB::connection()->getPdo();
```

---

## 🚀 Next Steps After Testing

### If All Tests Pass ✅
1. Document test results
2. Proceed with Phase 3 (Hotspot/PPPoE sessions)
3. Continue module implementation

### If Issues Found ⚠️
1. Check container logs
2. Verify file changes are in container
3. Rebuild if necessary
4. Re-test after fixes

---

## 📞 Quick Reference

**Start Everything:**
```bash
docker-compose up -d
```

**Stop Everything:**
```bash
docker-compose down
```

**Rebuild Frontend:**
```bash
./tests/docker-rebuild-frontend.sh
```

**Run Tests:**
```bash
./tests/docker-test.sh
```

**View Logs:**
```bash
docker-compose logs -f traidnet-frontend
```

**Access URL:**
```
http://localhost
```

---

**Ready to test?** Run `./tests/docker-test.sh` now! 🐳
