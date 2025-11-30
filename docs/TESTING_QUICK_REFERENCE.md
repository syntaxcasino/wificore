# Testing Quick Reference

## 🚀 Start Testing

### 1. Verify Services
```bash
docker ps --format "table {{.Names}}\t{{.Status}}"
```
All should show "healthy"

### 2. Access Application
- **URL:** http://localhost
- **Credentials:** admin@example.com / password

### 3. Open Browser Console (F12)
Check for:
```
✅ Connected to Soketi successfully!
📡 Socket ID: xxx.xxx
```

---

## 🧪 Test Scenarios

### Test 1: WebSocket Connection (30 seconds)
1. Open http://localhost
2. Login
3. Check console for connection logs
4. **Expected:** Green checkmarks, no errors

### Test 2: Private Channel Auth (1 minute)
1. Navigate to http://localhost/websocket-test
2. Click "Subscribe to Private Channel"
3. Click "Send Test Event"
4. **Expected:** Event appears in log

### Test 3: Router Provisioning (5 minutes)
1. Go to Dashboard → Routers → MikroTik
2. Click "Add Router"
3. Enter name, click "Generate Configuration"
4. **Expected:** 
   - Config generated
   - Console shows: "🔐 Subscribing to private channel..."
   - Activity log shows events

---

## 📊 What to Look For

### Console Logs (Good Signs)
```
✅ Connected to Soketi successfully!
🔐 Subscribing to private channel: router-provisioning.1
✅ Successfully subscribed
📊 Provisioning progress: {stage: "init", progress: 0}
```

### Console Logs (Problems)
```
❌ Connection error
❌ Channel subscription error
❌ 404 on /api/broadcasting/auth
```

---

## 🔍 Quick Debugging

### WebSocket Not Connecting
```bash
# Check Soketi
docker logs traidnet-soketi --tail 20

# Check Nginx proxy
docker logs traidnet-nginx | grep "/app"
```

### Private Channel Auth Fails
```bash
# Check broadcasting auth endpoint
docker logs traidnet-nginx | grep "broadcasting/auth"

# Should see 200, not 404
```

### Events Not Received
```bash
# Check backend is dispatching events
docker logs traidnet-backend | grep "Broadcasting"

# Check queue workers
docker exec traidnet-backend php artisan queue:work --once
```

---

## 📝 Expected Behavior

### Router Creation
1. User enters router name
2. Frontend calls API
3. Backend creates router
4. **WebSocket:** Frontend subscribes to `router-provisioning.{id}`
5. Activity log shows: "Real-time updates enabled"

### Router Connection
1. User applies config to MikroTik
2. Backend polling detects connection
3. **WebSocket:** Backend emits `RouterStatusUpdated`
4. Frontend receives event
5. UI updates: Status badge changes to "online"
6. Activity log shows: "Router connected successfully!"

### Configuration Deployment
1. User clicks "Deploy Configuration"
2. Backend queues provisioning job
3. **WebSocket:** Backend emits multiple `provisioning.progress` events
4. Frontend receives events in real-time
5. Progress bar updates: 0% → 20% → 40% → 60% → 80% → 100%
6. Activity log shows each stage
7. Completion message appears

---

## 🎯 Success Criteria

- ✅ WebSocket connects within 1 second
- ✅ Private channels authenticate successfully
- ✅ Events appear in console with emojis
- ✅ Activity log updates in real-time
- ✅ Progress bar animates smoothly
- ✅ No 404 errors in network tab
- ✅ No errors in console

---

## 📞 Quick Help

### Service Not Running
```bash
docker restart traidnet-{service-name}
```

### Clear Everything and Restart
```bash
docker compose down
docker compose up -d
```

### View Logs
```bash
docker logs traidnet-{service-name} -f
```

---

## 📚 Documentation

- **Full Guide:** `docs/WEBSOCKET_TESTING_GUIDE.md`
- **Optimization Details:** `docs/FRONTEND_OPTIMIZATION_COMPLETE.md`
- **Issues Fixed:** `docs/ISSUES_FIXED.md`
- **Quick Start:** `QUICK_START.md`

---

**Ready to test!** Open http://localhost and start provisioning! 🎉
