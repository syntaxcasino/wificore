# Centralized RADIUS Authentication Implementation

## Overview
All user authentication in the WiFiCore system has been centralized to use FreeRADIUS as the single source of truth for credential verification. This ensures consistent authentication across all user types and eliminates multiple authentication methods.

## Authentication Flow

### 1. UnifiedAuthController (System & Tenant Admins)
- **Location**: `app/Http/Controllers/Api/UnifiedAuthController.php`
- **Method**: `login()`
- **Flow**:
  1. User provides username/password
  2. System finds user in database
  3. Credentials verified via RADIUS service
  4. On success: JWT token issued
  5. On failure: Error returned with attempt tracking

### 2. PPPoE Portal Authentication
- **Location**: `app/Http/Controllers/Api/PppoePortalController.php`
- **Method**: `verifyPortalPassword()`
- **Flow**:
  1. PPPoE user provides account number/password
  2. Credentials verified via RADIUS service
  3. Fallback: Account number as default password (for initial setup)
  4. On success: Portal token issued

### 3. Hotspot User Authentication
- **Location**: `app/Http/Controllers/Api/HotspotController.php`
- **Method**: `login()`
- **Flow**:
  1. Hotspot user provides username/password
  2. Credentials verified via RADIUS service
  3. On success: Internet session created
  4. On failure: Access denied

### 4. Password Change Verification
- **Location**: `app/Http/Controllers/Api/UnifiedAuthController.php`
- **Method**: `changePassword()`
- **Flow**:
  1. User provides current password + new password
  2. Current password verified via RADIUS
  3. On success: Password update job dispatched

## RADIUS Service Configuration

### Timeout Settings
- **Authentication Timeout**: 5 seconds (prevents long delays)
- **Server**: `wificore-freeradius:1812`
- **Secret**: Configured via `config('radius.secret')`

### Schema-Aware Authentication
- PostgreSQL functions automatically determine correct tenant schema
- No manual schema switching required
- High performance without connection state changes

## User Types Supported

1. **System Administrators**
   - Authenticate via RADIUS
   - Can login from any domain/subdomain
   - Full system access

2. **Tenant Administrators**
   - Authenticate via RADIUS
   - Must login from their tenant subdomain
   - Tenant-scoped access

3. **PPPoE Users**
   - Authenticate via RADIUS
   - Portal access for self-service
   - Account number-based identification

4. **Hotspot Users**
   - Authenticate via RADIUS
   - Internet access authentication
   - Session management

## Security Benefits

1. **Single Source of Truth**: All credentials stored and verified in RADIUS
2. **Consistent Policy**: Rate limiting, account suspension applied uniformly
3. **Audit Trail**: All authentication attempts logged centrally
4. **Reduced Attack Surface**: No local password verification bypasses
5. **Scalability**: RADIUS designed for high-volume authentication

## Fallback Mechanisms

### PPPoE Portal
- Account number as default password for initial setup
- Ensures users can access portal before setting custom password

### Service Unavailability
- Graceful error handling when RADIUS is unavailable
- Appropriate HTTP status codes (503 for service unavailable)
- Detailed logging for troubleshooting

## Logging

All authentication attempts are logged with:
- Username/user identifier
- Authentication method (RADIUS)
- Success/failure status
- IP address
- Timestamp
- Error details (if applicable)

## Performance Considerations

1. **Timeout Optimization**: 5-second timeout prevents long delays
2. **Connection Pooling**: RADIUS client manages connections efficiently
3. **Schema Optimization**: PostgreSQL functions handle tenant resolution
4. **Caching**: User lookup cached where appropriate

## Migration Notes

- Local password verification (`Hash::check`, `password_verify`) removed
- Password storage (`bcrypt`) retained for new user creation
- RADIUS becomes the authoritative source for credential verification
- Existing passwords continue to work during transition

## Testing

To verify centralized authentication is working:

1. **System Admin Login**
   ```bash
   curl -X POST https://wificore.traidsolutions.com/api/auth/login \
     -H "Content-Type: application/json" \
     -d '{"username":"admin","password":"password"}'
   ```

2. **PPPoE Portal Login**
   ```bash
   curl -X POST https://tenant.wificore.traidsolutions.com/api/pppoe/portal/login \
     -H "Content-Type: application/json" \
     -d '{"account_number":"TRAP00001","portal_password":"password"}'
   ```

3. **Hotspot Login**
   ```bash
   curl -X POST https://tenant.wificore.traidsolutions.com/api/hotspot/login \
     -H "Content-Type: application/json" \
     -d '{"username":"hotspot_user","password":"password","mac_address":"00:11:22:33:44:55"}'
   ```

## Troubleshooting

### Common Issues

1. **Authentication Timeout**
   - Check RADIUS server connectivity
   - Verify timeout settings (5 seconds)
   - Check network latency

2. **User Not Found in RADIUS**
   - Verify user exists in correct tenant schema
   - Check RADIUS user mapping table
   - Ensure account prefix is set correctly

3. **Service Unavailable**
   - Check FreeRADIUS container status
   - Verify RADIUS configuration
   - Check PostgreSQL connectivity

### Log Locations
- Authentication logs: Laravel logs (`storage/logs/laravel.log`)
- RADIUS logs: FreeRADIUS container logs
- System logs: Docker container logs

## Future Enhancements

1. **Two-Factor Authentication**: Add TOTP support via RADIUS
2. **Certificate-based Auth**: Support EAP-TLS for enhanced security
3. **Rate Limiting**: Implement per-user rate limiting in RADIUS
4. **Account Lockout**: Centralized account lockout policies
