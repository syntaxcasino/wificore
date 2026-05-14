# RADIUS Portal Password Synchronization Implementation

## Critical Issue Fixed

**Problem**: During PPPoE user registration, portal passwords were NOT being synced to RADIUS, causing portal authentication to fail when centralized authentication was implemented.

**Solution**: Implemented comprehensive portal password synchronization to RADIUS with dedicated `Portal-Password` attribute.

## Implementation Details

### 1. Registration Flow (`PppoeUserController::store()`)

```php
// CRITICAL: Sync both PPPoE password and portal password to RADIUS
$this->syncRadiusCredentials(
    $username, 
    $plainPassword, 
    $expiresAt, 
    $rateLimit, 
    $simultaneousUse, 
    $tenantId, 
    $framedPool, 
    $supportsPortalPassword ? $portalPassword : null
);
```

**RADIUS Attributes Created**:
- `Cleartext-Password`: PPPoE connection password
- `NT-Password`: MS-CHAPv2 compatibility
- `Portal-Password`: Dedicated portal access password
- `Simultaneous-Use`: Connection limit
- `Expiration`: Account expiry (if set)

### 2. Portal Password Reset (`PppoeUserController::resetPortalPassword()`)

```php
// CRITICAL: Sync portal password to RADIUS for centralized authentication
DB::table('radcheck')->updateOrInsert(
    ['username' => $pppoeUser->username, 'attribute' => 'Portal-Password'],
    ['op' => ':=', 'value' => $newPortalPassword]
);
```

### 3. PPPoE Password Update (`PppoeUserController::resetPassword()`)

```php
// CRITICAL: Get existing portal password from RADIUS to preserve it
$existingPortalPassword = DB::table('radcheck')
    ->where('username', $pppoeUser->username)
    ->where('attribute', 'Portal-Password')
    ->value('value');

$this->syncRadiusCredentials(
    $username, 
    $newPassword, 
    $expiresAt, 
    $rateLimit, 
    $simultaneousUse, 
    $tenantId, 
    $framedPool, 
    $existingPortalPassword  // Preserve existing portal password
);
```

### 4. Portal Authentication (`PppoePortalController::verifyPortalPassword()`)

```php
// FIRST: Try dedicated Portal-Password attribute
$authenticated = $this->authenticateWithPortalRadiusPassword($username, $inputPassword);

if ($authenticated) {
    return true; // Portal password successful
}

// SECOND: Fallback to PPPoE password (backward compatibility)
$authenticated = $this->radiusService->authenticate($username, $inputPassword);
```

## Authentication Flow

### Primary: Portal Password Authentication
1. User enters portal credentials
2. System checks `Portal-Password` attribute in RADIUS
3. Direct password comparison (no RADIUS protocol needed)
4. Success: Portal access granted

### Fallback: PPPoE Password Authentication
1. If no `Portal-Password` found or doesn't match
2. Use standard RADIUS authentication with PPPoE password
3. Success: Portal access granted (backward compatibility)

### Final Fallback: Account Number
1. If both passwords fail
2. Check if password matches account number
3. Success: Initial setup access

## RADIUS Schema Changes

### radcheck Table Attributes
```sql
-- Standard PPPoE authentication
INSERT INTO radcheck (username, attribute, op, value) VALUES 
('username', 'Cleartext-Password', ':=', 'pppoe_password'),
('username', 'NT-Password', ':=', 'nt_hash'),
('username', 'Portal-Password', ':=', 'portal_password'),  -- NEW
('username', 'Simultaneous-Use', ':=', '1'),
('username', 'Expiration', ':=', 'expiry_date');
```

## Security Considerations

### Password Storage
- **PPPoE Password**: Stored as cleartext and NT-Hash in RADIUS
- **Portal Password**: Stored as cleartext in `Portal-Password` attribute
- **Local Storage**: Both passwords hashed in database

### Why Cleartext in RADIUS?
- **RADIUS Protocol**: Requires cleartext for PAP authentication
- **MikroTik Compatibility**: Some attributes need cleartext
- **Portal Access**: Direct comparison without RADIUS protocol overhead

### Access Control
- Portal passwords only used for web portal authentication
- PPPoE passwords used for network authentication
- Separate passwords prevent credential overflow

## Migration Notes

### For Existing Users
1. Existing PPPoE users without portal passwords continue to work
2. Portal authentication falls back to PPPoE password
3. When portal password is set, it's synced to RADIUS

### For New Users
1. Portal password automatically created (if supported)
2. Both passwords synced to RADIUS during registration
3. Portal password takes precedence for portal access

## Testing

### 1. Registration Test
```bash
# Create new PPPoE user with portal password
curl -X POST https://tenant.wificore.com/api/pppoe/users \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "testuser",
    "package_id": "uuid",
    "router_id": "uuid",
    "customer_name": "Test User"
  }'

# Check RADIUS for Portal-Password attribute
docker compose exec postgres psql -U wificore -d tenant_schema -c \
  "SELECT username, attribute, value FROM radcheck WHERE attribute = 'Portal-Password';"
```

### 2. Portal Authentication Test
```bash
# Test portal login with portal password
curl -X POST https://tenant.wificore.com/api/pppoe/portal/login \
  -H "Content-Type: application/json" \
  -d '{
    "account_number": "TRAP00001",
    "portal_password": "generated_portal_password"
  }'

# Should return success with authentication token
```

### 3. Password Reset Test
```bash
# Reset portal password
curl -X POST https://tenant.wificore.com/api/pppoe/users/uuid/reset-portal-password \
  -H "Authorization: Bearer TOKEN"

# Verify new password in RADIUS
docker compose exec postgres psql -U wificore -d tenant_schema -c \
  "SELECT username, attribute, value FROM radcheck WHERE username = 'testuser' AND attribute = 'Portal-Password';"
```

## Troubleshooting

### Portal Authentication Fails
1. Check if `Portal-Password` exists in RADIUS:
   ```sql
   SELECT * FROM radcheck WHERE username = 'username' AND attribute = 'Portal-Password';
   ```

2. Check logs for authentication flow:
   ```
   grep "PPPoE portal:" storage/logs/laravel.log
   ```

3. Verify tenant schema mapping:
   ```sql
   SELECT * FROM public.radius_user_schema_mapping WHERE username = 'username';
   ```

### Password Not Synced to RADIUS
1. Check registration logs for sync errors
2. Verify tenant migrations include portal_password column
3. Check if tenant supports portal passwords

### Backward Compatibility Issues
1. Users without portal passwords should still authenticate with PPPoE password
2. Check if fallback authentication is working
3. Verify account number fallback for initial setup

## Benefits

1. **Centralized Authentication**: All passwords stored in RADIUS
2. **Separate Credentials**: Portal and PPPoE passwords can be different
3. **Backward Compatibility**: Existing users continue to work
4. **Security**: Compromise of one password doesn't affect the other
5. **Audit Trail**: All authentication attempts logged centrally

## Future Enhancements

1. **Password Policies**: Enforce different policies for portal vs PPPoE passwords
2. **Two-Factor Authentication**: Add 2FA support for portal access
3. **Password History**: Prevent password reuse
4. **Account Lockout**: Centralized lockout policies in RADIUS
