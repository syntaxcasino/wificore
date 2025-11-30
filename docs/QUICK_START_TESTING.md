# 🚀 Quick Start - Testing Guide

## ⚡ 5-Minute Setup

### 1. Reset Database (Fresh Start)
```bash
docker-compose down
docker volume rm wifi-hotspot_postgres-data
docker-compose up -d
```

### 2. Wait for Services to Start
```bash
# Check all services are running
docker-compose ps

# Wait for nginx to be healthy (about 30 seconds)
docker-compose logs -f traidnet-nginx
```

### 3. Test Login
```
URL: http://localhost/login  (Note: Port 80, not 5173!)
Username: sysadmin
Password: Admin@123!
```

**Note**: This stack uses Docker with nginx reverse proxy. Frontend is served through nginx on port 80, NOT on port 5173!

---

## 🧪 Test Scenarios

### ✅ Test 1: System Admin Login
```
1. Go to: http://localhost/login
2. Enter: sysadmin / Admin@123!
3. Click: Sign In
4. Expected: Redirect to /dashboard
5. Check localStorage: userRole = 'system_admin'
```

### ✅ Test 2: Register New Tenant
```
1. Go to: http://localhost/register
2. Fill Organization:
   - Name: My Company
   - Slug: my-company
   - Email: admin@mycompany.com
3. Fill Administrator:
   - Name: John Doe
   - Username: johndoe
   - Email: john@mycompany.com
   - Password: SecurePass123!
4. Accept Terms
5. Click: Start Free Trial
6. Expected: Success message → Redirect to login
```

### ✅ Test 3: Tenant Admin Login
```
1. Go to: http://localhost/login
2. Enter: johndoe / SecurePass123!
3. Click: Sign In
4. Expected: Redirect to /dashboard
5. Check localStorage: userRole = 'admin', tenantId = UUID
```

### ✅ Test 4: Role-Based Routing
```
1. Login as sysadmin
2. Try: http://localhost/dashboard
3. Expected: Access granted

4. Login as johndoe
5. Try: http://localhost/system/dashboard
6. Expected: Redirect to /dashboard
```

---

## 🔍 Quick Checks

### Check Default Admin in Database
```bash
docker exec -it traidnet-postgres psql -U postgres -d wifi_hotspot -c "SELECT id, username, role FROM users WHERE role = 'system_admin';"
```

### Check Registered Tenants
```bash
docker exec -it traidnet-postgres psql -U postgres -d wifi_hotspot -c "SELECT id, name, slug FROM tenants;"
```

### Check Auth Token
```javascript
// In browser console
localStorage.getItem('authToken')
localStorage.getItem('userRole')
localStorage.getItem('tenantId')
```

---

## 🐛 Common Issues

### Issue: Default admin not found
```bash
# Recreate database
docker-compose down
docker volume rm wifi-hotspot_postgres-data
docker-compose up -d
```

### Issue: CORS error
```bash
# Check backend is running
docker-compose ps
docker-compose logs traidnet-backend
```

### Issue: Frontend not loading
```bash
# Check frontend container
docker-compose ps traidnet-frontend
docker-compose logs traidnet-frontend

# Restart frontend container
docker-compose restart traidnet-frontend
```

---

## 📊 Expected Results

### After System Admin Login:
```javascript
{
  token: "1|abc123...",
  user: {
    id: "00000000-0000-0000-0000-000000000001",
    username: "sysadmin",
    role: "system_admin",
    tenant_id: null
  },
  dashboardRoute: "/system/dashboard"
}
```

### After Tenant Registration:
```javascript
{
  success: true,
  data: {
    tenant: {
      id: "uuid",
      name: "My Company",
      slug: "my-company"
    },
    admin: {
      id: "uuid",
      username: "johndoe",
      role: "admin"
    }
  }
}
```

### After Tenant Admin Login:
```javascript
{
  token: "2|def456...",
  user: {
    id: "uuid",
    username: "johndoe",
    role: "admin",
    tenant_id: "tenant-uuid"
  },
  dashboardRoute: "/dashboard"
}
```

---

## ✅ Success Checklist

- [ ] Default admin can login
- [ ] Can register new tenant
- [ ] Tenant admin can login
- [ ] Role-based routing works
- [ ] Tokens stored correctly
- [ ] Logout clears data

---

## 🎯 Quick Commands

```bash
# Start everything
docker-compose up -d && cd frontend && npm run dev

# Stop everything
docker-compose down

# View logs
docker-compose logs -f

# Reset database
docker-compose down && docker volume rm wifi-hotspot_postgres-data && docker-compose up -d

# Check database
docker exec -it traidnet-postgres psql -U postgres -d wifi_hotspot

# Test API
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"login":"sysadmin","password":"Admin@123!"}'
```

---

**Status**: ✅ **READY TO TEST**  
**Time**: 5 minutes  
**Difficulty**: Easy ⭐
