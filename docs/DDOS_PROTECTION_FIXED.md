# DDoS Protection - Countdown Timer Fixed

**Date:** October 30, 2025, 2:08 AM  
**Status:** ✅ **FIXED - Timer Now Counts Down**

---

## 🔍 Issue Identified

### **Problem**
When an IP is blocked, the response shows:
```json
{
  "message": "Access denied. Your IP has been temporarily blocked due to suspicious activity.",
  "retry_after": 900
}
```

**The `retry_after` value stays at 900 seconds (15 minutes) and never counts down!**

---

## 🐛 Root Cause

In `DDoSProtection.php` middleware:

### **Before (Broken)** ❌
```php
// Check if IP is already blocked
if (Cache::has($blockKey)) {
    return response()->json([
        'message' => 'Access denied...',
        'retry_after' => 900  // ❌ HARDCODED! Never changes
    ], 403);
}

// When blocking
Cache::put($blockKey, true, now()->addMinutes(15));  // ❌ Stores boolean
```

**Issues:**
1. Stores `true` instead of expiration timestamp
2. Returns static `900` instead of calculating remaining time
3. No way to know when block expires

---

## ✅ Solution Applied

### **After (Fixed)** ✅
```php
// Check if IP is already blocked
if (Cache::has($blockKey)) {
    // Get the actual expiration time from cache
    $blockedUntil = Cache::get($blockKey);
    $retryAfter = is_numeric($blockedUntil) 
        ? max(0, $blockedUntil - now()->timestamp)
        : 900;
    
    return response()->json([
        'message' => 'Access denied...',
        'retry_after' => $retryAfter,              // ✅ Calculates remaining time
        'blocked_until' => date('Y-m-d H:i:s', $blockedUntil)  // ✅ Shows exact time
    ], 403);
}

// When blocking - store timestamp
$blockedUntil = now()->addMinutes(15)->timestamp;
Cache::put($blockKey, $blockedUntil, now()->addMinutes(15));  // ✅ Stores timestamp
```

**Improvements:**
1. ✅ Stores Unix timestamp of expiration
2. ✅ Calculates actual remaining seconds
3. ✅ Provides exact unblock time
4. ✅ Timer counts down on each request

---

## 📊 API Response Comparison

### **Before** ❌
```json
{
  "message": "Access denied. Your IP has been temporarily blocked due to suspicious activity.",
  "retry_after": 900
}
```
- Always shows 900
- No way to know when it expires
- Timer doesn't update

### **After** ✅
```json
{
  "message": "Access denied. Your IP has been temporarily blocked due to suspicious activity.",
  "retry_after": 723,
  "blocked_until": "2025-10-30 02:20:15"
}
```
- Shows actual remaining seconds (723, 722, 721...)
- Exact expiration time provided
- Timer counts down in real-time

---

## 🎯 How It Works Now

### **1. When IP Gets Blocked**
```php
// Calculate expiration timestamp
$blockedUntil = now()->addMinutes(15)->timestamp;  // e.g., 1730250015

// Store timestamp in cache
Cache::put($blockKey, $blockedUntil, now()->addMinutes(15));
```

### **2. When Blocked IP Makes Request**
```php
// Get stored timestamp
$blockedUntil = Cache::get($blockKey);  // e.g., 1730250015

// Calculate remaining seconds
$retryAfter = $blockedUntil - now()->timestamp;  // e.g., 723 seconds

// Return countdown
return response()->json([
    'retry_after' => $retryAfter,  // 723, then 722, then 721...
    'blocked_until' => '2025-10-30 02:20:15'
]);
```

### **3. Timer Countdown Example**
```
Request 1: retry_after: 900 (15:00 remaining)
Request 2: retry_after: 895 (14:55 remaining)
Request 3: retry_after: 890 (14:50 remaining)
...
Request N: retry_after: 0   (Block expired)
```

---

## 🔧 New Unblock Command

### **Manual Unblock**
```bash
# Unblock specific IP
docker exec traidnet-backend php artisan ddos:unblock 192.168.1.100

# Unblock all IPs
docker exec traidnet-backend php artisan ddos:unblock --all
```

### **Command Output**
```bash
$ php artisan ddos:unblock 192.168.1.100
✅ Successfully unblocked IP: 192.168.1.100

$ php artisan ddos:unblock --all
🔍 Searching for blocked IPs...
  Unblocked: 192.168.1.100
  Unblocked: 10.0.0.50
  Unblocked: 172.16.0.25
✅ Successfully unblocked 3 IP(s)
```

---

## 📋 DDoS Protection Rules

### **Trigger Conditions**

#### **1. Excessive Requests**
- **Threshold:** 100 requests per minute
- **Block Duration:** 15 minutes
- **Message:** "Access denied. Your IP has been temporarily blocked due to excessive requests."

#### **2. Rapid-Fire Requests**
- **Threshold:** 20 requests in less than 5 seconds
- **Block Duration:** 15 minutes
- **Message:** "Access denied. Suspicious activity detected."

### **Block Details**
```json
{
  "message": "Access denied...",
  "retry_after": 723,                    // Seconds remaining
  "blocked_until": "2025-10-30 02:20:15" // Exact unblock time
}
```

---

## 🧪 Testing the Fix

### **1. Trigger a Block**
```bash
# Make 101 requests rapidly
for i in {1..101}; do
  curl http://localhost/api/test
done
```

**Response:**
```json
{
  "message": "Access denied. Your IP has been temporarily blocked due to excessive requests.",
  "retry_after": 900,
  "blocked_until": "2025-10-30 02:20:15"
}
```

### **2. Watch Timer Count Down**
```bash
# Wait 5 seconds and try again
sleep 5
curl http://localhost/api/test
```

**Response:**
```json
{
  "message": "Access denied...",
  "retry_after": 895,                    // ✅ Decreased by 5!
  "blocked_until": "2025-10-30 02:20:15"
}
```

### **3. Verify Unblock**
```bash
# Unblock manually
docker exec traidnet-backend php artisan ddos:unblock YOUR_IP

# Or wait 15 minutes for automatic unblock
```

---

## 🎨 Frontend Integration

### **Display Countdown Timer**

```vue
<template>
  <div v-if="isBlocked" class="blocked-message">
    <h2>⛔ Access Temporarily Blocked</h2>
    <p>{{ errorMessage }}</p>
    
    <!-- Countdown Timer -->
    <div class="countdown">
      <h3>Retry in: {{ formatTime(retryAfter) }}</h3>
      <p>Unblocked at: {{ blockedUntil }}</p>
      <div class="progress-bar">
        <div class="progress" :style="{ width: progressPercent + '%' }"></div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  data() {
    return {
      isBlocked: false,
      errorMessage: '',
      retryAfter: 0,
      blockedUntil: '',
      maxRetryTime: 900, // 15 minutes
      countdownInterval: null
    }
  },
  
  computed: {
    progressPercent() {
      return ((this->maxRetryTime - this.retryAfter) / this.maxRetryTime) * 100;
    }
  },
  
  methods: {
    handleBlockedResponse(error) {
      if (error.response?.status === 403) {
        const data = error.response.data;
        this.isBlocked = true;
        this.errorMessage = data.message;
        this.retryAfter = data.retry_after;
        this.blockedUntil = data.blocked_until;
        this.maxRetryTime = data.retry_after;
        
        // Start countdown
        this.startCountdown();
      }
    },
    
    startCountdown() {
      // Clear existing interval
      if (this.countdownInterval) {
        clearInterval(this.countdownInterval);
      }
      
      // Update every second
      this.countdownInterval = setInterval(() => {
        if (this.retryAfter > 0) {
          this.retryAfter--;
        } else {
          // Block expired, reload page
          clearInterval(this.countdownInterval);
          this.isBlocked = false;
          location.reload();
        }
      }, 1000);
    },
    
    formatTime(seconds) {
      const mins = Math.floor(seconds / 60);
      const secs = seconds % 60;
      return `${mins}:${secs.toString().padStart(2, '0')}`;
    }
  },
  
  beforeUnmount() {
    if (this.countdownInterval) {
      clearInterval(this.countdownInterval);
    }
  }
}
</script>

<style scoped>
.blocked-message {
  padding: 2rem;
  background: #fee;
  border: 2px solid #f00;
  border-radius: 8px;
  text-align: center;
}

.countdown {
  margin-top: 1rem;
}

.countdown h3 {
  font-size: 2rem;
  color: #d00;
  font-family: monospace;
}

.progress-bar {
  width: 100%;
  height: 20px;
  background: #ddd;
  border-radius: 10px;
  overflow: hidden;
  margin-top: 1rem;
}

.progress {
  height: 100%;
  background: linear-gradient(90deg, #f00, #0f0);
  transition: width 1s linear;
}
</style>
```

---

## 📊 Monitoring

### **Check Blocked IPs**
```bash
# View blocked IPs in Redis
docker exec traidnet-redis redis-cli KEYS "ddos:blocked:*"

# Check specific IP
docker exec traidnet-redis redis-cli GET "ddos:blocked:192.168.1.100"
```

### **View Logs**
```bash
# Watch DDoS protection logs
docker logs traidnet-backend -f | grep "DDoS:"
```

**Example Output:**
```
[2025-10-30 02:05:15] DDoS: IP blocked for excessive requests
  IP: 192.168.1.100
  Requests per minute: 105
  Blocked until: 2025-10-30 02:20:15

[2025-10-30 02:06:30] DDoS: Blocked IP attempted access
  IP: 192.168.1.100
  Retry after: 825 seconds
```

---

## 🔐 Security Best Practices

### **1. Whitelist Trusted IPs**
Add to `.env`:
```env
DDOS_WHITELIST=127.0.0.1,10.0.0.1,192.168.1.1
```

Update middleware:
```php
public function handle(Request $request, Closure $next): Response
{
    $ip = $request->ip();
    
    // Whitelist check
    $whitelist = explode(',', env('DDOS_WHITELIST', ''));
    if (in_array($ip, $whitelist)) {
        return $next($request);
    }
    
    // Continue with DDoS protection...
}
```

### **2. Adjust Thresholds**
```php
// In DDoSProtection.php
const REQUESTS_PER_MINUTE = 100;  // Adjust as needed
const RAPID_FIRE_THRESHOLD = 20;   // Adjust as needed
const RAPID_FIRE_TIMESPAN = 5;     // Seconds
const BLOCK_DURATION = 15;         // Minutes
```

### **3. Add Rate Limit Headers**
```php
return response()->json($data, 403)
    ->header('X-RateLimit-Limit', 100)
    ->header('X-RateLimit-Remaining', 0)
    ->header('X-RateLimit-Reset', $blockedUntil)
    ->header('Retry-After', $retryAfter);
```

---

## ✅ Summary

| Feature | Before | After | Status |
|---------|--------|-------|--------|
| Countdown Timer | Static 900 | Real-time countdown | ✅ Fixed |
| Expiration Time | Not shown | Exact timestamp | ✅ Added |
| Unblock Command | Manual cache clear | `ddos:unblock` | ✅ Added |
| Block Storage | Boolean | Unix timestamp | ✅ Fixed |
| Remaining Time | Hardcoded | Calculated | ✅ Fixed |

---

## 🎉 Result

```
Before: retry_after: 900 → 900 → 900 → 900 ❌
After:  retry_after: 900 → 895 → 890 → 885 ✅
```

**The timer now counts down properly and shows exactly when the block will expire!**

---

**Fixed by:** Cascade AI Assistant  
**Date:** October 30, 2025, 2:08 AM UTC+03:00  
**Files Modified:** 1 (DDoSProtection.php)  
**Files Created:** 1 (UnblockIP.php command)  
**Result:** ✅ **Timer now counts down in real-time!**
