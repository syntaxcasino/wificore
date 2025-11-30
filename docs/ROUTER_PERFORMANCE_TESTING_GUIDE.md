# Router Performance Testing Guide 🧪

## Objective
Test if CPU and Memory utilization changes are reflected in real-time on the RouterManagement GUI.

## Prerequisites

1. ✅ Router is online and connected
2. ✅ Queue workers are running (optimized configuration)
3. ✅ Scheduler is fetching live data every 30 seconds
4. ✅ WebSocket connection is active
5. ✅ Browser console is open to monitor updates

## Testing Methods

### Method 1: Ping Flood Test (Easy) ⚡

**What it does**: Generates network traffic to increase CPU usage

#### Step 1: Open RouterManagement Page
```
http://localhost/routers
```

#### Step 2: Open Browser Console (F12)
Watch for WebSocket updates:
```javascript
// You should see every 30 seconds:
📊 RouterLiveDataUpdated: {
  router_id: 1,
  data: {
    cpu_load: "2",  // ← Watch this value
    free_memory: "841555968",
    ...
  }
}
```

#### Step 3: Run Ping Flood from Your Computer
```bash
# Windows (PowerShell - Run as Administrator)
ping -t -l 65500 <router-ip>

# Example:
ping -t -l 65500 192.168.56.244

# Linux/Mac
ping -f <router-ip>

# Or use hping3 for more aggressive testing
sudo hping3 --flood --rand-source <router-ip>
```

#### Step 4: Watch GUI Update
- **Within 30 seconds**, CPU bar should increase
- **Color changes**: Green → Yellow → Orange → Red
- **Percentage updates**: 2% → 15% → 30% → 50%+

#### Step 5: Stop Test
```bash
# Press Ctrl+C to stop ping
```

#### Step 6: Watch CPU Return to Normal
- CPU should drop back down within 30-60 seconds
- GUI updates in real-time

---

### Method 2: Bandwidth Test (Medium) 📊

**What it does**: Generates heavy traffic to stress CPU and memory

#### Using MikroTik Bandwidth Test Tool

**Option A: Via WebFig (Browser)**

1. Click **"Login"** button on router in GUI
2. New tab opens with WebFig
3. Go to **Tools → Bandwidth Test**
4. Configure:
   - **Protocol**: TCP
   - **Direction**: Both
   - **Duration**: 60 seconds
   - **Threads**: 10
5. Click **Start**
6. Switch back to RouterManagement tab
7. Watch CPU/Memory bars increase

**Option B: Via SSH/Terminal**

```bash
# SSH into router
ssh admin@192.168.56.244

# Run bandwidth test (to another router or server)
/tool bandwidth-test address=192.168.1.1 protocol=tcp direction=both duration=60s
```

---

### Method 3: Traffic Generator Script (Advanced) 🚀

**What it does**: Creates multiple concurrent connections to stress the router

#### Create Traffic Generator Script

**File: `stress-test-router.sh`**
```bash
#!/bin/bash

ROUTER_IP="192.168.56.244"
DURATION=60  # seconds
THREADS=20

echo "🔥 Starting Router Stress Test..."
echo "Target: $ROUTER_IP"
echo "Duration: $DURATION seconds"
echo "Threads: $THREADS"
echo ""

# Function to generate traffic
generate_traffic() {
    local thread_id=$1
    echo "Thread $thread_id: Starting..."
    
    for i in $(seq 1 100); do
        # HTTP requests
        curl -s "http://$ROUTER_IP" > /dev/null 2>&1 &
        
        # Ping
        ping -c 1 -W 1 $ROUTER_IP > /dev/null 2>&1 &
        
        # Small delay
        sleep 0.1
    done
    
    echo "Thread $thread_id: Completed"
}

# Start multiple threads
for i in $(seq 1 $THREADS); do
    generate_traffic $i &
done

echo ""
echo "⏳ Test running for $DURATION seconds..."
echo "📊 Watch RouterManagement GUI for CPU/Memory spikes!"
echo ""

# Wait for duration
sleep $DURATION

# Kill all background jobs
killall curl ping 2>/dev/null

echo ""
echo "✅ Stress test completed!"
echo "🔍 Check GUI - CPU and Memory should return to normal in 30-60 seconds"
```

**Run the script:**
```bash
chmod +x stress-test-router.sh
./stress-test-router.sh
```

---

### Method 4: MikroTik Script (Most Accurate) 🎯

**What it does**: Runs CPU-intensive operations directly on the router

#### Create MikroTik Script via WebFig

1. Login to router (click "Login" button in GUI)
2. Go to **System → Scripts**
3. Click **Add New**
4. Name: `cpu-stress-test`
5. Script:

```routeros
# CPU Stress Test Script
:log info "Starting CPU stress test..."

# Create variables to consume memory
:local counter 0
:local array [:toarray ""]

# Run for 60 seconds
:local endtime ([:timestamp] + 60)

:while ([:timestamp] < $endtime) do={
    # CPU intensive operations
    :set counter ($counter + 1)
    
    # String operations (CPU intensive)
    :local str ""
    :for i from=1 to=1000 do={
        :set str ($str . "x")
    }
    
    # Array operations (Memory intensive)
    :set array ($array, $str)
    
    # Log every 1000 iterations
    :if (($counter % 1000) = 0) do={
        :log info "Stress test iteration: $counter"
    }
}

:log info "CPU stress test completed. Iterations: $counter"
```

6. Click **OK**
7. Click **Run Script**
8. Switch to RouterManagement GUI
9. Watch CPU/Memory spike in real-time!

---

### Method 5: iperf3 Network Performance Test (Professional) 💪

**What it does**: Industry-standard network performance testing

#### Setup

**On a separate machine (server):**
```bash
# Install iperf3
sudo apt-get install iperf3  # Ubuntu/Debian
brew install iperf3          # macOS

# Run iperf3 server
iperf3 -s
```

**On your computer (client):**
```bash
# Install iperf3
sudo apt-get install iperf3  # Ubuntu/Debian
brew install iperf3          # macOS

# Run test through router
iperf3 -c <server-ip> -t 60 -P 10

# Example:
iperf3 -c 192.168.1.100 -t 60 -P 10
```

**Parameters:**
- `-c`: Client mode (connect to server)
- `-t 60`: Run for 60 seconds
- `-P 10`: Use 10 parallel streams (more stress)

---

## Expected Results

### Normal State (Idle)
```
CPU:    2-5%   [████░░░░░░░░░░░░░░░░] Green
Memory: 20-25% [█████░░░░░░░░░░░░░░░] Green
Disk:   21%    [█████░░░░░░░░░░░░░░░] Green
Users:  1-2
```

### Under Load (Stress Test)
```
CPU:    60-80% [████████████████░░░░] Orange/Red
Memory: 40-60% [████████████░░░░░░░░] Yellow/Orange
Disk:   21%    [█████░░░░░░░░░░░░░░░] Green (unchanged)
Users:  5-10   (if generating connections)
```

### After Test (Recovery)
```
CPU:    2-5%   [████░░░░░░░░░░░░░░░░] Green (back to normal)
Memory: 22-28% [█████░░░░░░░░░░░░░░░] Green (slightly higher)
Disk:   21%    [█████░░░░░░░░░░░░░░░] Green
Users:  1-2
```

---

## Monitoring Real-Time Updates

### Browser Console Monitoring

Open DevTools (F12) and run:

```javascript
// Monitor WebSocket updates
let updateCount = 0
let lastCPU = 0

// This will log CPU changes
const originalLog = console.log
console.log = function(...args) {
    if (args[0]?.includes?.('RouterLiveDataUpdated')) {
        updateCount++
        const data = args[1]?.data
        if (data?.cpu_load) {
            const cpu = parseInt(data.cpu_load)
            const change = cpu - lastCPU
            console.info(`🔄 Update #${updateCount} - CPU: ${cpu}% (${change > 0 ? '+' : ''}${change}%)`)
            lastCPU = cpu
        }
    }
    originalLog.apply(console, args)
}
```

### Expected Console Output During Test

```
🔄 Update #1 - CPU: 2% (+0%)
🔄 Update #2 - CPU: 15% (+13%)   ← Stress test started
🔄 Update #3 - CPU: 45% (+30%)   ← Load increasing
🔄 Update #4 - CPU: 68% (+23%)   ← Peak load
🔄 Update #5 - CPU: 72% (+4%)    ← Sustained load
🔄 Update #6 - CPU: 35% (-37%)   ← Test stopped
🔄 Update #7 - CPU: 8% (-27%)    ← Recovering
🔄 Update #8 - CPU: 3% (-5%)     ← Back to normal
```

---

## Verification Checklist

### ✅ Before Test
- [ ] Router status shows "Online" (green badge)
- [ ] CPU shows low usage (2-10%)
- [ ] Memory shows normal usage (20-30%)
- [ ] WebSocket connected (check browser console)
- [ ] Last update time is recent (< 1 minute ago)

### ✅ During Test
- [ ] CPU bar increases within 30 seconds
- [ ] Color changes (green → yellow → orange → red)
- [ ] Percentage number updates
- [ ] Memory bar may increase slightly
- [ ] Connected users count may increase
- [ ] Browser console shows RouterLiveDataUpdated events

### ✅ After Test
- [ ] CPU returns to normal within 1-2 minutes
- [ ] Memory returns close to baseline
- [ ] Router remains online
- [ ] No error messages in console
- [ ] Updates continue every 30 seconds

---

## Troubleshooting

### Issue: No Updates Showing

**Check 1: Is scheduler running?**
```bash
docker exec traidnet-backend ps aux | grep schedule
```

**Check 2: Are queue workers running?**
```bash
docker exec traidnet-backend supervisorctl status | grep router-data
```

**Check 3: Check logs**
```bash
docker exec traidnet-backend tail -f /var/www/html/storage/logs/router-data-queue.log
```

**Check 4: WebSocket connection**
```javascript
// In browser console
window.Echo?.connector?.pusher?.connection?.state
// Should return: "connected"
```

### Issue: Updates Are Slow (> 60 seconds)

**Solution: Check scheduler interval**
```bash
docker exec traidnet-backend php artisan schedule:list
```

Should show:
```
fetch-router-live-data .... Next Due: 30 seconds
```

### Issue: CPU Shows 0% During Test

**Possible causes:**
1. Router is not actually stressed (test not working)
2. MikroTik API not returning CPU data
3. Backend not fetching data correctly

**Debug:**
```bash
# Check what backend is receiving
docker exec traidnet-backend tail -f /var/www/html/storage/logs/laravel.log | grep cpu_load
```

---

## Quick Test Command (All-in-One)

```bash
#!/bin/bash
echo "🧪 Quick Router Stress Test"
echo "=============================="
echo ""
echo "1. Open RouterManagement in browser"
echo "2. Open browser console (F12)"
echo "3. Press Enter to start test..."
read

echo "🔥 Starting 60-second stress test..."
echo ""

# Ping flood
ping -t -l 65500 192.168.56.244 &
PING_PID=$!

echo "⏳ Running for 60 seconds..."
echo "📊 Watch the GUI - CPU should spike!"
echo ""

sleep 60

echo "🛑 Stopping test..."
kill $PING_PID 2>/dev/null

echo ""
echo "✅ Test complete!"
echo "🔍 CPU should return to normal in 30-60 seconds"
echo "📈 Check browser console for WebSocket updates"
```

---

## Performance Benchmarks

### Expected Update Latency

| Event | Time | Description |
|-------|------|-------------|
| Stress test starts | 0s | Begin generating load |
| Router CPU increases | 1-5s | MikroTik detects load |
| Backend fetches data | 0-30s | Next scheduled fetch |
| WebSocket broadcasts | <1s | Instant broadcast |
| Frontend updates | <1s | React re-renders |
| **Total latency** | **1-31s** | From load to display |

### Optimization Tips

**For faster updates (15 seconds):**
```php
// backend/routes/console.php
})->everyFifteenSeconds()->name('fetch-router-live-data');
```

**For real-time updates (10 seconds):**
```php
})->everyTenSeconds()->name('fetch-router-live-data');
```

⚠️ **Warning**: More frequent updates = higher server load

---

## Summary

### Best Testing Method
1. **Easiest**: Ping flood (`ping -t -l 65500 <ip>`)
2. **Most Realistic**: iperf3 bandwidth test
3. **Most Accurate**: MikroTik script on router

### Expected Behavior
- ✅ Updates appear within 30 seconds
- ✅ CPU/Memory bars change color
- ✅ Percentages update
- ✅ WebSocket events in console
- ✅ Values return to normal after test

### Success Criteria
- 🎯 CPU spike visible in GUI
- 🎯 Updates happen automatically
- 🎯 No manual refresh needed
- 🎯 Real-time WebSocket updates working
- 🎯 Color-coded bars (green/yellow/orange/red)

**Your RouterManagement GUI is now a real-time monitoring dashboard!** 📊🚀
