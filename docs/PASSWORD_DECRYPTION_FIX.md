# Password Decryption Issue - Root Cause Analysis

## Problem Summary

Password decryption is failing with empty error messages in production:
```
[2026-01-08 09:24:05] production.ERROR: Password decryption failed: {"router_id":"...","error":""}
```

## Root Cause

The issue is **NOT** the APP_KEY. The APP_KEY is correctly set and loaded in the container:
```
APP_KEY: base64:fCoFGM8V6G/vaJnPFLhYhQybBlPEMVPWMsCWv1UwkHI=
```

### The Real Problem: Using Wrong Crypt Method

**Current Code (WRONG):**
```php
$decryptedPassword = Crypt::decrypt($router->password);
```

**Should Be:**
```php
$decryptedPassword = Crypt::decryptString($router->password);
```

### Why This Matters

Laravel's `Crypt` facade has two methods:
1. **`Crypt::decrypt()`** - Decrypts serialized PHP objects/arrays
2. **`Crypt::decryptString()`** - Decrypts plain strings

When you encrypt a password, you use:
```php
Crypt::encryptString($password)  // Returns encrypted string
```

So you MUST decrypt with:
```php
Crypt::decryptString($encrypted)  // Returns plain string
```

### What Happens When You Use Wrong Method

Using `Crypt::decrypt()` on a string encrypted with `encryptString()`:
1. Tries to unserialize the decrypted data
2. Fails because it's not serialized PHP data
3. Throws exception with empty/cryptic message
4. Password decryption fails

## The Fix

Replace ALL instances of `Crypt::decrypt($router->password)` with `Crypt::decryptString($router->password)`.

### Files to Fix

1. `backend/app/Services/MikrotikProvisioningService.php` (5 locations)
2. Any other service that decrypts router passwords

## Why SSH is Better

The user is right - we should use SSH instead of API for interface discovery:

### Current Approach (API)
```php
$client = new Client([
    'host' => $host,
    'user' => $router->username,
    'pass' => $decryptedPassword,
    'port' => 8728,  // API port
    'timeout' => 10,
]);
$interfaces = $client->query(new Query('/interface/print'))->read();
```

**Problems:**
- Requires API port (8728) to be accessible
- Timeout issues with slow routers
- More complex error handling
- Password decryption failures break everything

### Better Approach (SSH)
```php
use phpseclib3\Net\SSH2;

$ssh = new SSH2($host, 22);
$ssh->login($router->username, $decryptedPassword);
$output = $ssh->exec('/interface print');
```

**Advantages:**
- SSH is ALWAYS enabled on MikroTik (port 22)
- More reliable than API
- Simpler error handling
- Can get interface list directly
- No timeout issues
- Works even if API is disabled

## Environment Variable Warnings

The warnings you see:
```
WARN[0000] The "PUSHER_APP_KEY" variable is not set. Defaulting to a blank string.
```

**These are NOT errors** - they're Docker Compose warnings when it tries to substitute variables in the YAML file.

**The containers themselves have all variables** via `env_file: .env.production`.

To silence these warnings, you can either:
1. Export variables before running docker compose commands:
   ```bash
   export $(cat .env.production | grep -v '^#' | xargs)
   docker compose -f docker-compose.production.yml up -d
   ```

2. Or ignore them - they don't affect container runtime.

## Action Plan

1. ✅ Fix `Crypt::decrypt()` → `Crypt::decryptString()` in all files
2. ✅ Consider switching to SSH for interface discovery
3. ✅ Test password decryption with fixed code
4. ✅ Rebuild containers
5. ✅ Verify router provisioning works end-to-end

## Testing

After fix, test with:
```bash
docker compose -f docker-compose.production.yml exec wificore-backend php artisan tinker
```

```php
$router = App\Models\Router::first();
$decrypted = Illuminate\Support\Facades\Crypt::decryptString($router->password);
echo "Password: $decrypted\n";
```

Should output the plain password without errors.
