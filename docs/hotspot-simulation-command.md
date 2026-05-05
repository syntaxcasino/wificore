# Hotspot Payment Simulation Command

End-to-end command for simulating hotspot payments without writing SQL. Automatically handles both new user creation and existing user reactivation.

---

## Quick Start

```bash
php artisan hotspot:simulate-payment --tenant=your-tenant --phone=+254712345678
```

---

## Command Signature

```bash
php artisan hotspot:simulate-payment
    {--T|tenant= : Tenant slug or ID}
    {--P|phone= : Phone number (username)}
    {--amount=100 : Payment amount in KES}
    {--package= : Package slug/ID (auto-detects hotspot package if not specified)}
    {--mac= : MAC address (auto-generated if not specified)}
    {--existing : Force use existing user (test reactivation)}
    {--dry-run : Show what would happen without executing}
```

---

## Usage Examples

### 1. Create New Hotspot User (First Payment)

```bash
# Auto-detect tenant and package
php artisan hotspot:simulate-payment --tenant=your-tenant-slug --phone=+254712345678

# Full specification
php artisan hotspot:simulate-payment \
  --tenant=acme-wifi \
  --phone=+254712345678 \
  --amount=100 \
  --package=hotspot-daily-1gb \
  --mac=AA:BB:CC:DD:EE:FF
```

### 2. Test Reactivation (Repeat Payment)

```bash
# Same command detects existing user and reactivates
php artisan hotspot:simulate-payment --tenant=acme-wifi --phone=+254712345678

# Different package to test package upgrade
php artisan hotspot:simulate-payment \
  --tenant=acme-wifi \
  --phone=+254712345678 \
  --package=hotspot-weekly-5gb
```

### 3. Dry Run (Preview Without Executing)

```bash
php artisan hotspot:simulate-payment --tenant=acme-wifi --phone=+254799999999 --dry-run
```

### 4. Shorthand Syntax

```bash
php artisan hotspot:simulate-payment -T acme-wifi -P +254712345678 --amount=150
```

---

## What the Command Does

```
┌─────────────────────────────────────────────────────────────┐
│  1. Validates tenant and sets tenant context                 │
│  2. Normalizes phone number (+254XXXXXXXXX format)           │
│  3. Checks for existing user by phone/username               │
│  4. Auto-detects or validates hotspot package                │
│  5. Creates simulated Payment record (status=completed)      │
│  6. Dispatches CreateHotspotUserJob                          │
│  7. Job detects: NEW user or EXISTING user                   │
│     • New → Creates HotspotUser + RADIUS entries             │
│     • Existing → Reactivates + extends expiry + new password │
│  8. Creates HotspotCredential (for SMS delivery)             │
│  9. Creates RadiusSession record                             │
│  10. Dispatches SMS job (credentials sent to phone)          │
└─────────────────────────────────────────────────────────────┘
```

---

## Complete Test Sequence

### Step 1: Create New User
```bash
php artisan hotspot:simulate-payment \
  --tenant=your-tenant \
  --phone=+254711111111
```

### Step 2: Process the Job
```bash
php artisan queue:work --queue=hotspot-provisioning --once
```

### Step 3: Verify in Database
```bash
psql -d your_db -c "
  SELECT username, status, subscription_expires_at 
  FROM hotspot_users 
  WHERE phone_number = '+254711111111';
"
```

### Step 4: Simulate Repeat Payment (Reactivation Test)
```bash
php artisan hotspot:simulate-payment \
  --tenant=your-tenant \
  --phone=+254711111111 \
  --amount=200
```

### Step 5: Process Again
```bash
php artisan queue:work --queue=hotspot-provisioning --once
```

### Step 6: Verify Expiry Was Extended
```bash
psql -d your_db -c "
  SELECT username, status, subscription_starts_at, subscription_expires_at 
  FROM hotspot_users 
  WHERE phone_number = '+254711111111';
"
```

---

## Sample Output

### New User Created
```
🔥 Hotspot Payment Simulation
==================================================
✓ Tenant: Acme WiFi (acme-wifi)
✓ Phone: +254712345678
⚠️  No existing user found - will create new account
✓ Package: Daily 1GB (24 hours)
✓ MAC: A1:B2:C3:D4:E5:F6
✓ Amount: KES 100

📋 Creating simulated payment...
✓ Payment created: a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11

🚀 Dispatching CreateHotspotUserJob...
✓ Job dispatched to hotspot-provisioning queue

⏳ Waiting for job processing (2 seconds)...

==================================================
✅ USER CREATED SUCCESSFULLY!
==================================================
+---------------+--------------------------------------+
| Field         | Value                                |
+---------------+--------------------------------------+
| ID            | b1ffcd00-...                         |
| Username      | +254712345678                        |
| Phone         | +254712345678                        |
| Status        | active                               |
| Subscription  | YES                                  |
| Package       | Daily 1GB                            |
| Expires At    | 2026-04-24 07:15:00                  |
| Data Limit    | 1.00 GB                              |
| Created At    | 2026-04-23 07:15:00                  |
+---------------+--------------------------------------+

📱 Credentials sent via SMS:
   Username: +254712345678
   Password: (check HotspotCredential table or SMS logs)

🔧 RADIUS Config: 1 radcheck entries, 3 radreply entries
```

### Existing User Reactivated
```
🔥 Hotspot Payment Simulation
==================================================
✓ Tenant: Acme WiFi (acme-wifi)
✓ Phone: +254712345678
⚠️  Existing user found!
+---------------+---------------------------+
| Field         | Value                     |
+---------------+---------------------------+
| ID            | b1ffcd00-...              |
| Username      | +254712345678             |
| Status        | expired                   |
| Current Expiry| 2026-04-22 10:00:00       |
| Package       | Daily 1GB                 |
| Data Used     | 856.50 MB                 |
+---------------+---------------------------+

 This will REACTIVATE the existing user with new expiry. Continue? (yes/no) [yes]:

✓ Package: Daily 1GB (24 hours)
✓ MAC: F6:E5:D4:C3:B2:A1
✓ Amount: KES 200

📋 Creating simulated payment...
✓ Payment created: c2ggde00-... 

🚀 Dispatching CreateHotspotUserJob...

==================================================
✅ USER REACTIVATED SUCCESSFULLY!
==================================================
```

---

## Behind the Scenes

### Phone Number Normalization
The command automatically normalizes phone numbers:
- `0712345678` → `+254712345678`
- `712345678` → `+254712345678`
- `+254712345678` → `+254712345678` (no change)

### MAC Address Generation
If not provided, generates random valid MAC: `A1:B2:C3:D4:E5:F6`

### Package Auto-Detection
If no package specified, selects first available package where `type = 'hotspot'`, or falls back to any package.

---

## Troubleshooting

### Job Not Processing
```bash
# Check queue status
php artisan queue:monitor hotspot-provisioning

# Process manually
php artisan queue:work --queue=hotspot-provisioning --once -v
```

### Check Logs
```bash
tail -f storage/logs/laravel.log | grep -i 'hotspot\|reactivat'
```

### Verify Database Records
```sql
-- Hotspot user
SELECT * FROM hotspot_users WHERE phone_number = '+254712345678';

-- RADIUS authentication
SELECT * FROM radcheck WHERE username = '+254712345678';

-- RADIUS reply attributes
SELECT * FROM radreply WHERE username = '+254712345678';

-- Credentials for SMS
SELECT * FROM hotspot_credentials WHERE phone_number = '+254712345678' ORDER BY created_at DESC;

-- Payment record
SELECT * FROM payments WHERE phone_number = '+254712345678' ORDER BY created_at DESC;
```

---

## Implementation Notes

### File Location
```
backend/app/Console/Commands/SimulateHotspotPayment.php
```

### Auto-Discovery
Laravel automatically discovers this command via the `Console\Commands` namespace.

### Tenant Context
The command properly initializes tenancy:
```php
tenancy()->initialize($tenant);
config(['database.connections.tenant.search_path' => $tenant->schema_name]);
DB::connection('tenant')->reconnect();
```

---

## Related Documentation

- [Hotspot Auto-Creation System](./hotspot-auto-creation.md)
- [CreateHotspotUserJob](./jobs/CreateHotspotUserJob.md)
- [GrantHotspotAccessJob](./jobs/GrantHotspotAccessJob.md)
- [Hotspot User Reactivation](./hotspot-reactivation.md)
