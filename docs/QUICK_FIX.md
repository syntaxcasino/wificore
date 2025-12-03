# Quick Fix - Run This Now!

## 🚀 One Command to Fix Everything

```powershell
.\final-fix-all-issues.ps1
```

**That's it!** This will fix all three issues:
1. ✅ FreeRADIUS permission error
2. ✅ Duplicate seeder error  
3. ✅ Login failure

---

## ⏱️ What to Expect

**Time**: ~5-10 minutes (depending on your internet speed)

**Steps**:
1. Stops containers
2. Rebuilds with fixes
3. Starts everything
4. Verifies it works

**Output**:
```
✅ All containers rebuilt successfully
✅ All containers started
✅ FreeRADIUS running
✅ System admin created
✅ Database seeded successfully
✅ Exactly ONE system admin in database

🎉 All issues fixed and system ready!
```

---

## 🔑 Login Credentials

After the script completes:

**System Admin (Landlord)**:
- URL: `http://localhost`
- Username: `sysadmin`
- Password: `Admin@123`

⚠️ **Change password in production!**

---

## ✅ Verify It Works

### Test 1: RADIUS
```bash
docker exec traidnet-freeradius radtest sysadmin Admin@123 localhost 0 testing123
```
**Expected**: `Received Access-Accept`

### Test 2: Login API
```bash
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"sysadmin","password":"Admin@123"}'
```
**Expected**: `"success": true`

### Test 3: Frontend
Open browser: `http://localhost`  
Login with `sysadmin` / `Admin@123`  
**Expected**: Dashboard loads

---

## 🐛 If Something Goes Wrong

### Check Logs:
```bash
docker-compose logs --tail 100 traidnet-freeradius
docker-compose logs --tail 100 traidnet-backend
```

### Check Status:
```bash
docker-compose ps
```

### Try Again:
```bash
docker-compose down -v
.\final-fix-all-issues.ps1
```

---

## 📚 Full Documentation

See **FINAL_FIX_SUMMARY.md** for complete details.

---

**Just run the script and you're done!** 🎉
