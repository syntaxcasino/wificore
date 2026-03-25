# Password Decryption Error - Permanent Fix

## Problem Overview

Password decryption errors occur when the `APP_KEY` used to encrypt router passwords doesn't match the `APP_KEY` currently configured in the application. This typically happens when:

1. **Environment Migration**: Database moved from one environment to another with different `APP_KEY`
2. **Key Regeneration**: `APP_KEY` was regenerated after routers were created
3. **Configuration Mismatch**: `.env` and `.env.production` have different `APP_KEY` values
4. **Backup Restoration**: Database restored from backup encrypted with a different key

---

## Permanent Solution Implemented

### 1. **PasswordEncryptionService** (`backend/app/Services/PasswordEncryptionService.php`)

A comprehensive service that provides:

#### Safe Decryption
```php
$password = PasswordEncryptionService::safeDecrypt($router);
// Returns null on failure instead of throwing exception
```

#### Password Validation
```php
$canDecrypt = PasswordEncryptionService::canDecrypt($encryptedPassword);
// Returns true/false without throwing exceptions
```

#### Password Re-encryption
```php
$success = PasswordEncryptionService::reEncryptPassword($router, $plainPassword);
// Re-encrypts password with current APP_KEY
```

#### Bulk Validation
```php
$failedRouters = PasswordEncryptionService::validateAllPasswords($tenantId);
// Returns array of routers with decryption issues
```

#### APP_KEY Validation
```php
$validation = PasswordEncryptionService::validateAppKey();
// Validates APP_KEY configuration and returns issues
```

---

### 2. **Validation Command** (`backend/app/Console/Commands/ValidateRouterPasswords.php`)

An Artisan command to diagnose and fix password issues:

```bash
# Validate all router passwords
php artisan router:validate-passwords

# Validate specific tenant
php artisan router:validate-passwords --tenant=<tenant_id>

# Generate detailed report
php artisan router:validate-passwords --report

# Interactive fix mode (prompts for passwords)
php artisan router:validate-passwords --fix
```

#### Command Output Example:
```
🔐 Router Password Validation Tool

Checking APP_KEY configuration...
✅ APP_KEY is properly configured

Validating passwords for 3 tenant(s)...

Tenant: Acme Corp (ID: 123)
  ✅ All 5 router passwords validated successfully

Tenant: Beta Inc (ID: 456)
  ❌ 2 out of 8 router passwords failed validation
    - Router: Router-A (ID: abc-123)
    - Router: Router-B (ID: def-456)

═══════════════════════════════════════════════════════════
VALIDATION SUMMARY
═══════════════════════════════════════════════════════════
Total Tenants: 3
Total Routers: 20
Failed Routers: 2
```

---

### 3. **Enhanced SshExecutor** (`backend/app/Services/MikroTik/SshExecutor.php`)

Updated to use `PasswordEncryptionService::safeDecrypt()`:

#### Before:
```php
$this->decryptedPassword = Crypt::decrypt($router->password);
// Throws exception on failure
```

#### After:
```php
$this->decryptedPassword = PasswordEncryptionService::safeDecrypt($router);

if ($this->decryptedPassword === null) {
    throw new \Exception(
        'Failed to decrypt router password. Run "php artisan router:validate-passwords" to fix.'
    );
}
```

**Benefits:**
- Clear error messages with actionable steps
- Graceful handling of decryption failures
- Helpful hints pointing to validation command

---

### 4. **APP_KEY Validation on Startup** (`backend/app/Providers/AppKeyValidationServiceProvider.php`)

Validates `APP_KEY` configuration when the application starts:

- **Development**: Throws exception if `APP_KEY` is invalid
- **Production**: Logs critical error but doesn't crash the app
- **Skips validation** for specific commands (key:generate, router:validate-passwords)

---

## Usage Guide

### Step 1: Check APP_KEY Configuration

```bash
# View current APP_KEY info
php artisan tinker
>>> App\Services\PasswordEncryptionService::getAppKeyInfo()
```

Expected output:
```php
[
    "exists" => true,
    "format" => "base64",
    "length" => 51,
    "prefix" => "base64:abc123...",
    "cipher" => "AES-256-CBC"
]
```

### Step 2: Validate All Router Passwords

```bash
php artisan router:validate-passwords
```

This will:
1. Validate `APP_KEY` configuration
2. Check all routers across all tenants
3. Report which routers have decryption issues
4. Provide actionable solutions

### Step 3: Fix Decryption Issues

#### Option A: Restore Original APP_KEY (Recommended)

If you have the original `APP_KEY` from backup:

```bash
# 1. Update .env with original APP_KEY
nano .env
# APP_KEY=base64:original_key_here

# 2. Restart application
docker-compose restart backend

# 3. Verify all passwords work
php artisan router:validate-passwords
```

#### Option B: Re-encrypt Passwords (If Original Key Lost)

If you don't have the original `APP_KEY`:

```bash
# Interactive mode - prompts for each password
php artisan router:validate-passwords --fix
```

Or programmatically:

```php
use App\Services\PasswordEncryptionService;
use App\Models\Router;

$router = Router::find($routerId);
$plainPassword = 'the_actual_password'; // You must know this

PasswordEncryptionService::reEncryptPassword($router, $plainPassword);
```

#### Option C: Update via UI/API

Users can update router passwords through the web interface, which will automatically re-encrypt with the current `APP_KEY`.

---

## Prevention Strategies

### 1. **Sync APP_KEY Across Environments**

Ensure `.env` and `.env.production` have the same `APP_KEY`:

```bash
# Check keys match
diff <(grep APP_KEY .env) <(grep APP_KEY .env.production)

# If different, sync them
grep APP_KEY .env > /tmp/key
cat /tmp/key >> .env.production
```

### 2. **Backup APP_KEY**

Store `APP_KEY` in secure location:

```bash
# Extract APP_KEY to secure backup
grep APP_KEY .env > /secure/backup/app_key_backup.txt
chmod 600 /secure/backup/app_key_backup.txt
```

### 3. **Document Key Changes**

If `APP_KEY` must be changed:

```bash
# 1. Document old key
echo "Old APP_KEY: $(grep APP_KEY .env)" >> /docs/key_history.txt

# 2. Generate new key
php artisan key:generate

# 3. Re-encrypt all passwords
php artisan router:validate-passwords --fix

# 4. Document new key
echo "New APP_KEY: $(grep APP_KEY .env)" >> /docs/key_history.txt
echo "Changed on: $(date)" >> /docs/key_history.txt
```

### 4. **Monitor Decryption Failures**

Add monitoring for decryption errors:

```php
// In your monitoring service
$failedRouters = PasswordEncryptionService::validateAllPasswords();

if (!empty($failedRouters)) {
    // Alert administrators
    Log::critical('Router password decryption failures detected', [
        'count' => count($failedRouters),
        'routers' => $failedRouters
    ]);
}
```

---

## Troubleshooting

### Issue: "Failed to decrypt router credentials"

**Cause**: `APP_KEY` mismatch

**Solution**:
```bash
php artisan router:validate-passwords
# Follow the prompts to fix
```

### Issue: "APP_KEY validation failed"

**Cause**: Invalid or missing `APP_KEY`

**Solution**:
```bash
# Generate new key
php artisan key:generate

# Re-encrypt all passwords
php artisan router:validate-passwords --fix
```

### Issue: "Some router passwords failed validation"

**Cause**: Routers were created with different `APP_KEY`

**Solution**:
```bash
# Option 1: Restore original APP_KEY from backup
# Option 2: Re-encrypt passwords
php artisan router:validate-passwords --fix
```

### Issue: Decryption works in development but fails in production

**Cause**: Different `APP_KEY` in `.env` vs `.env.production`

**Solution**:
```bash
# Sync APP_KEY from development to production
scp .env production:/path/to/.env.production

# Or manually copy the APP_KEY value
```

---

## Testing

### Unit Tests

```bash
# Test PasswordEncryptionService
php artisan test --filter=PasswordEncryptionServiceTest

# Test validation command
php artisan test --filter=ValidateRouterPasswordsTest
```

### Manual Testing

```bash
# 1. Create test router
php artisan tinker
>>> $router = App\Models\Router::first()

# 2. Test safe decryption
>>> $password = App\Services\PasswordEncryptionService::safeDecrypt($router)

# 3. Test validation
>>> $canDecrypt = App\Services\PasswordEncryptionService::canDecrypt($router->password)

# 4. Test APP_KEY validation
>>> $validation = App\Services\PasswordEncryptionService::validateAppKey()
```

---

## Migration Guide

### For Existing Deployments

If you're experiencing password decryption errors in an existing deployment:

#### Step 1: Backup Current State
```bash
# Backup database
pg_dump -U postgres wificore > backup_$(date +%Y%m%d).sql

# Backup .env files
cp .env .env.backup
cp .env.production .env.production.backup
```

#### Step 2: Identify the Issue
```bash
php artisan router:validate-passwords --report
```

#### Step 3: Choose Fix Strategy

**If you have the original APP_KEY:**
```bash
# Restore it and restart
echo "APP_KEY=base64:original_key" >> .env
docker-compose restart backend
```

**If you don't have the original APP_KEY:**
```bash
# Re-encrypt all passwords (requires knowing plain passwords)
php artisan router:validate-passwords --fix
```

#### Step 4: Verify Fix
```bash
# Should show all passwords valid
php artisan router:validate-passwords
```

#### Step 5: Update Documentation
Document the `APP_KEY` in your secure password manager or vault.

---

## API Integration

### Check Router Password Validity

```php
use App\Services\PasswordEncryptionService;

// In your controller or service
public function checkRouterCredentials(Router $router): array
{
    $password = PasswordEncryptionService::safeDecrypt($router);
    
    if ($password === null) {
        return [
            'valid' => false,
            'error' => 'Password decryption failed - APP_KEY mismatch',
            'action' => 'Contact administrator to fix encryption keys'
        ];
    }
    
    return [
        'valid' => true,
        'password' => $password
    ];
}
```

### Bulk Validation Endpoint

```php
// routes/api.php
Route::get('/admin/validate-passwords', function () {
    $failedRouters = PasswordEncryptionService::validateAllPasswords();
    
    return response()->json([
        'status' => empty($failedRouters) ? 'success' : 'warning',
        'failed_count' => count($failedRouters),
        'failed_routers' => $failedRouters
    ]);
})->middleware(['auth:sanctum', 'admin']);
```

---

## Security Considerations

1. **Never log plain passwords** - The service only logs encrypted data
2. **Secure APP_KEY storage** - Store in environment variables, not in code
3. **Rotate keys periodically** - Use the validation command to re-encrypt
4. **Audit decryption failures** - Monitor logs for suspicious patterns
5. **Limit fix command access** - Only administrators should run `--fix` mode

---

## Summary

The permanent fix for password decryption errors includes:

✅ **PasswordEncryptionService** - Safe decryption with fallback handling  
✅ **Validation Command** - Diagnose and fix issues interactively  
✅ **Enhanced Error Messages** - Clear guidance on resolution  
✅ **Startup Validation** - Catch issues early  
✅ **Comprehensive Documentation** - This guide  

**Key Takeaway**: Always keep `APP_KEY` consistent across environments and backed up securely.

---

## Support

For additional help:
1. Run `php artisan router:validate-passwords --report` for detailed diagnostics
2. Check logs in `storage/logs/laravel.log` for decryption errors
3. Review this documentation for troubleshooting steps
4. Contact the development team if issues persist

**Last Updated**: January 13, 2026  
**Version**: 1.0.0
