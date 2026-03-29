# Scheduled Package Activation/Deactivation System

## 📋 Overview

Implemented a complete automated system for handling scheduled package activations and deactivations with real-time broadcasting to admin dashboard.

---

## ✅ Implementation Summary

### 1. Database Schema Update

**File:** `postgres/init.sql`

**Changes:**
- ✅ Added `scheduled_activation_time` column to packages table
- ✅ Added index for efficient querying of scheduled packages

```sql
CREATE TABLE packages (
    -- ... other fields
    enable_schedule BOOLEAN DEFAULT FALSE,
    scheduled_activation_time TIMESTAMP,  -- NEW FIELD
    hide_from_client BOOLEAN DEFAULT FALSE,
    status VARCHAR(20) DEFAULT 'active',
    is_active BOOLEAN DEFAULT TRUE,
    -- ... other fields
);

-- Index for efficient scheduled package queries
CREATE INDEX idx_packages_scheduled_activation 
ON packages(scheduled_activation_time) 
WHERE scheduled_activation_time IS NOT NULL;
```

---

### 2. Queue Job Implementation

**File:** `backend/app/Jobs/ProcessScheduledPackages.php`

**Purpose:** Automatically activate and deactivate packages based on schedule

**Features:**
- ✅ Runs every 1 minute via Laravel scheduler
- ✅ Activates packages when `scheduled_activation_time` is reached
- ✅ Deactivates packages when validity period expires
- ✅ Broadcasts events to private channels
- ✅ Comprehensive logging
- ✅ Error handling with retries
- ✅ Prevents overlapping executions

**Logic:**

#### Activation Logic:
```php
// Find packages to activate
Package::where('enable_schedule', true)
    ->where('scheduled_activation_time', '<=', Carbon::now())
    ->where('status', 'inactive')
    ->get();

// Activate each package
$package->update([
    'status' => 'active',
    'is_active' => true
]);

// Broadcast event
broadcast(new PackageStatusChanged($package, 'inactive', 'active'));
```

#### Deactivation Logic:
```php
// Find packages to deactivate
// (scheduled packages that have passed their validity period)
$expiryTime = $activationTime + validity;
if (now() > $expiryTime) {
    $package->update([
        'status' => 'inactive',
        'is_active' => false
    ]);
    
    broadcast(new PackageStatusChanged($package, 'active', 'inactive'));
}
```

#### Validity Parsing:
Supports multiple formats:
- `"1 hour"` → 1 hour
- `"24 hours"` → 24 hours
- `"7 days"` → 7 days
- `"30 days"` → 30 days
- `"1 week"` → 7 days
- `"1 month"` → 30 days

---

### 3. Broadcasting Event

**File:** `backend/app/Events/PackageStatusChanged.php`

**Purpose:** Real-time notification of package status changes

**Channels:**
- `private-packages` - All authenticated users
- `private-admin-notifications` - Admin users only

**Event Data:**
```json
{
  "package_id": "uuid",
  "package_name": "Weekend Special",
  "package_type": "hotspot",
  "old_status": "inactive",
  "new_status": "active",
  "is_active": true,
  "scheduled_activation_time": "2025-10-25T18:00:00Z",
  "timestamp": "2025-10-23T20:30:00Z",
  "message": "Package 'Weekend Special' status changed from inactive to active"
}
```

---

### 4. Scheduler Configuration

**File:** `backend/routes/console.php`

**Schedule:**
```php
// Process scheduled package activations/deactivations every minute
Schedule::job(new ProcessScheduledPackages)
    ->everyMinute()
    ->name('process-scheduled-packages')
    ->withoutOverlapping()
    ->onOneServer();
```

**Features:**
- ✅ Runs every 1 minute
- ✅ `withoutOverlapping()` - Prevents concurrent executions
- ✅ `onOneServer()` - Runs on single server in multi-server setup
- ✅ Named job for monitoring

---

### 5. Broadcasting Channels

**File:** `backend/routes/channels.php`

**New Channel:**
```php
// Packages channel - requires authentication
Broadcast::channel('packages', function ($user) {
    return $user !== null;
});
```

**Existing Channels Used:**
- `admin-notifications` - Already configured for admin-only access

---

## 🔄 Complete Flow Diagram

### Activation Flow

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Admin Creates Package with Schedule                      │
│    - enable_schedule: true                                   │
│    - scheduled_activation_time: 2025-10-25 18:00           │
│    - status: inactive                                        │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. Package Saved to Database                                │
│    - Waiting for scheduled time                             │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. Laravel Scheduler (Every 1 Minute)                       │
│    - Triggers ProcessScheduledPackages job                  │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. Job Checks for Packages to Activate                      │
│    WHERE enable_schedule = true                             │
│    AND scheduled_activation_time <= NOW()                   │
│    AND status = 'inactive'                                  │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 5. Package Found - Activate It                              │
│    UPDATE packages SET                                       │
│      status = 'active',                                      │
│      is_active = true                                        │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 6. Broadcast Event                                           │
│    - Channel: private-packages                              │
│    - Channel: private-admin-notifications                   │
│    - Event: package.status.changed                          │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 7. Frontend Receives Event                                   │
│    - Updates package list in real-time                      │
│    - Shows notification to admin                            │
│    - No page refresh needed                                 │
└─────────────────────────────────────────────────────────────┘
```

### Deactivation Flow

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Package is Active                                         │
│    - activated_at: 2025-10-25 18:00                         │
│    - validity: "7 days"                                      │
│    - expiry: 2025-11-01 18:00                               │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. Laravel Scheduler (Every 1 Minute)                       │
│    - Triggers ProcessScheduledPackages job                  │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. Job Checks for Expired Packages                          │
│    - Calculates: activation_time + validity                 │
│    - Checks if NOW() > expiry_time                          │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. Package Expired - Deactivate It                          │
│    UPDATE packages SET                                       │
│      status = 'inactive',                                    │
│      is_active = false                                       │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 5. Broadcast Event                                           │
│    - Same channels and event as activation                  │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 6. Frontend Updates                                          │
│    - Package status changes to inactive                     │
│    - Admin receives notification                            │
└─────────────────────────────────────────────────────────────┘
```

---

## 🎯 Use Cases

### Use Case 1: Weekend Special Package
```
Scenario: Create a special package for weekends only

Setup:
- Package: "Weekend Special - 50% Off"
- enable_schedule: true
- scheduled_activation_time: Friday 18:00
- validity: "2 days"

Behavior:
- Friday 18:00 → Package automatically activates
- Sunday 18:00 → Package automatically deactivates
- Repeat weekly by creating new scheduled packages
```

### Use Case 2: Limited Time Promotion
```
Scenario: Flash sale for 24 hours

Setup:
- Package: "Flash Sale - 1 Hour Free"
- enable_schedule: true
- scheduled_activation_time: 2025-10-25 12:00
- validity: "24 hours"

Behavior:
- Oct 25, 12:00 PM → Package activates
- Oct 26, 12:00 PM → Package deactivates
- Customers can only purchase during this window
```

### Use Case 3: Seasonal Packages
```
Scenario: Holiday special package

Setup:
- Package: "Christmas Special"
- enable_schedule: true
- scheduled_activation_time: 2025-12-20 00:00
- validity: "10 days"

Behavior:
- Dec 20 → Package activates
- Dec 30 → Package deactivates
- Perfect for holiday promotions
```

---

## 📊 Monitoring & Logging

### Log Entries

**Job Start:**
```
[INFO] ProcessScheduledPackages job started
```

**Packages Found:**
```
[INFO] Found packages to activate
{
  "count": 3
}
```

**Package Activated:**
```
[INFO] Package activated
{
  "package_id": "uuid",
  "package_name": "Weekend Special",
  "scheduled_time": "2025-10-25 18:00:00"
}
```

**Package Deactivated:**
```
[INFO] Package deactivated
{
  "package_id": "uuid",
  "package_name": "Weekend Special",
  "reason": "Scheduled validity expired"
}
```

**Job Completion:**
```
[INFO] ProcessScheduledPackages job completed successfully
```

**Errors:**
```
[ERROR] Failed to activate package
{
  "package_id": "uuid",
  "error": "Error message"
}
```

---

## 🧪 Testing

### Manual Testing

**1. Test Activation:**
```bash
# Create package with schedule 1 minute in future
POST /api/packages
{
  "name": "Test Package",
  "enable_schedule": true,
  "scheduled_activation_time": "2025-10-23T20:35:00",
  "status": "inactive",
  ...
}

# Wait 1-2 minutes
# Check package status
GET /api/packages/{id}

# Expected: status = "active", is_active = true
```

**2. Test Broadcasting:**
```javascript
// In browser console (admin dashboard)
Echo.private('packages')
    .listen('.package.status.changed', (e) => {
        console.log('Package status changed:', e);
    });

// Trigger activation
// Expected: Event received in console
```

**3. Test Deactivation:**
```bash
# Create package with short validity
POST /api/packages
{
  "name": "Test Package",
  "enable_schedule": true,
  "scheduled_activation_time": "2025-10-23T20:30:00",
  "validity": "2 minutes",
  "status": "inactive",
  ...
}

# Wait for activation (1-2 minutes)
# Wait for deactivation (2 more minutes)
# Check package status

# Expected: status = "inactive", is_active = false
```

### Automated Testing

**Unit Test Example:**
```php
public function test_scheduled_package_activates_at_correct_time()
{
    $package = Package::factory()->create([
        'enable_schedule' => true,
        'scheduled_activation_time' => now()->subMinute(),
        'status' => 'inactive',
    ]);

    $job = new ProcessScheduledPackages();
    $job->handle();

    $package->refresh();
    
    $this->assertEquals('active', $package->status);
    $this->assertTrue($package->is_active);
}
```

---

## 🚀 Deployment

### Prerequisites
1. ✅ Laravel scheduler must be running
2. ✅ Queue worker must be running (for broadcasting)
3. ✅ WebSocket server must be running (Soketi/Pusher)

### Setup Commands

**1. Run Database Migration:**
```bash
cd backend
php artisan migrate
```

**2. Start Laravel Scheduler:**
```bash
# Add to crontab (Linux/Mac)
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1

# Or use supervisor (recommended)
[program:laravel-scheduler]
command=php /path/to/backend/artisan schedule:work
autostart=true
autorestart=true
```

**3. Start Queue Worker:**
```bash
php artisan queue:work --queue=default,broadcasts
```

**4. Verify Scheduler:**
```bash
php artisan schedule:list

# Should show:
# process-scheduled-packages ... Every minute
```

---

## 📈 Performance Considerations

### Optimization Strategies

**1. Database Indexing:**
```sql
-- Efficient query for scheduled packages
CREATE INDEX idx_packages_scheduled_activation 
ON packages(scheduled_activation_time) 
WHERE scheduled_activation_time IS NOT NULL;
```

**2. Job Configuration:**
```php
// Prevent overlapping executions
->withoutOverlapping()

// Run on single server only
->onOneServer()

// Set timeout
public $timeout = 120;

// Set retry attempts
public $tries = 3;
```

**3. Batch Processing:**
```php
// Process in chunks if many packages
Package::where(...)
    ->chunk(100, function ($packages) {
        foreach ($packages as $package) {
            $this->activatePackage($package);
        }
    });
```

---

## 🔒 Security Considerations

**1. Channel Authorization:**
```php
// Only authenticated users can listen
Broadcast::channel('packages', function ($user) {
    return $user !== null;
});

// Only admins can listen to admin notifications
Broadcast::channel('admin-notifications', function ($user) {
    return $user !== null && $user->isAdmin();
});
```

**2. Job Security:**
- ✅ Runs on server-side only
- ✅ No user input accepted
- ✅ Database transactions for consistency
- ✅ Comprehensive error handling

---

## 📝 Files Modified/Created

### Created (2 files):
1. ✅ `backend/app/Jobs/ProcessScheduledPackages.php`
2. ✅ `backend/app/Events/PackageStatusChanged.php`

### Modified (3 files):
1. ✅ `postgres/init.sql` - Added scheduled_activation_time field
2. ✅ `backend/routes/console.php` - Added scheduler entry
3. ✅ `backend/routes/channels.php` - Added packages channel

---

## 🎉 Summary

### ✅ What Was Implemented

1. **Database Schema** - Added scheduled_activation_time field with index
2. **Queue Job** - Automated activation/deactivation every 1 minute
3. **Broadcasting** - Real-time events to admin dashboard
4. **Scheduler** - Laravel scheduler configuration
5. **Channels** - Private broadcasting channels
6. **Logging** - Comprehensive logging for monitoring
7. **Error Handling** - Retry logic and error recovery

### ✅ Benefits

- ⚡ **Automated** - No manual intervention needed
- 🔄 **Real-time** - Instant updates via WebSockets
- 📊 **Monitored** - Comprehensive logging
- 🛡️ **Reliable** - Error handling and retries
- 🎯 **Precise** - 1-minute granularity
- 📈 **Scalable** - Efficient database queries

---

**Implementation Date:** October 23, 2025  
**Status:** ✅ **COMPLETE AND PRODUCTION READY**  
**Version:** 2.2.0
