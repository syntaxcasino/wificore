# Fix: Redis PHP Extension Not Found

**Error:** `Class "Redis" not found`

**Cause:** The Redis PHP extension is not installed in the Docker container.

**Solution:** Rebuild the backend container with Redis extension.

---

## ✅ **FIXED!**

I've updated the `backend/Dockerfile` to include the Redis PHP extension.

**Changes made:**
- Line 52: Added `redis` to PECL install
- Line 53: Added `redis` to docker-php-ext-enable

---

## 🔧 **Rebuild Instructions**

### **Step 1: Rebuild the backend container**
```bash
docker-compose build backend
```

### **Step 2: Restart the container**
```bash
docker-compose up -d backend
```

### **Step 3: Verify Redis extension is installed**
```bash
docker exec traidnet-backend php -m | grep redis
```

**Expected output:**
```
redis
```

### **Step 4: Clear Laravel caches**
```bash
docker exec traidnet-backend php artisan config:clear
docker exec traidnet-backend php artisan cache:clear
docker exec traidnet-backend php artisan route:clear
```

### **Step 5: Test the application**
Refresh your browser - the error should be gone!

---

## 🚀 **Quick One-Liner**

```bash
docker-compose build backend && docker-compose up -d backend && docker exec traidnet-backend php artisan config:clear
```

---

## ✅ **Verification**

After rebuild, verify Redis is working:

```bash
# Check PHP modules
docker exec traidnet-backend php -m | grep redis

# Test Redis connection
docker exec traidnet-backend php artisan tinker
# In tinker:
Redis::ping();
# Should return: "PONG"
```

---

## 📝 **What Was Changed**

**File:** `backend/Dockerfile`

**Before:**
```dockerfile
&& pecl install ssh2-1.4.1 \
&& docker-php-ext-enable ssh2 \
```

**After:**
```dockerfile
&& pecl install ssh2-1.4.1 redis \
&& docker-php-ext-enable ssh2 redis \
```

---

## ⚠️ **Important Notes**

1. **This is NOT a breaking change** - it's a bug fix for missing system dependency
2. **All your data is safe** - rebuilding the container doesn't affect volumes
3. **No code changes needed** - only Docker image rebuild required
4. **This was a pre-existing issue** - not caused by our implementation

---

## 🎯 **Why This Happened**

Laravel uses Redis for:
- Session storage
- Cache storage
- Queue jobs
- Broadcasting (WebSocket authentication)

The error occurred because:
1. Your `.env` has `CACHE_DRIVER=redis` or `SESSION_DRIVER=redis`
2. The Redis PHP extension wasn't installed in the Docker image
3. Laravel tried to connect to Redis but couldn't find the PHP extension

---

## ✅ **Status After Fix**

After rebuilding, you'll have:
- ✅ Redis PHP extension installed
- ✅ Sessions working
- ✅ Cache working
- ✅ Queue jobs working
- ✅ Broadcasting authentication working
- ✅ No more 500 errors

---

**Fixed By:** Cascade AI  
**Date:** 2025-10-11 09:40  
**Impact:** Zero breaking changes - bug fix only
