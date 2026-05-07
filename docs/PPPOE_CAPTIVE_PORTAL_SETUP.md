# PPPoE Captive Portal Setup Guide

## Overview

When a PPPoE user's account is unpaid, expired, or suspended, the system redirects their HTTP traffic to the Customer Portal payment page. This ensures users can easily top up their accounts without contacting support.

## How It Works

1. **User Connects**: PPPoE user connects with valid credentials
2. **RADIUS Check**: RADIUS server validates credentials and returns user's status
3. **MikroTik Profile Assignment**:
   - Active users: Get normal `pppoe-active` profile with full internet
   - Unpaid users: Get `pppoe-unpaid` profile with redirect rules
4. **HTTP Redirect**: Unpaid users see the portal payment page when trying to browse
5. **Payment**: User pays via M-Pesa or voucher
6. **Auto-Restore**: Account activated, internet access restored within 1 minute

## MikroTik Router Configuration

### 1. Generate Captive Portal Script

Use the `PppoeCaptivePortalService` to generate the router configuration:

```php
use App\Services\MikroTik\PppoeCaptivePortalService;

$service = new PppoeCaptivePortalService();
$script = $service->generateCaptivePortalConfig($routerId, [
    'portal_url' => 'https://wificore.traidsolutions.com/portal',
    'portal_ip' => '192.168.100.10', // Your server IP
]);

// Apply script to router
```

### 2. Manual MikroTik Configuration

If applying manually, run these commands on the router:

```bash
# Create walled garden address list for portal access
/ip firewall address-list add list=WiFiCore-Portal address=wificore.traidsolutions.com comment="WiFiCore Portal"
/ip firewall address-list add list=WiFiCore-Portal address=*.mpesa.com comment="Safaricom M-Pesa"
/ip firewall address-list add list=WiFiCore-Portal address=api.safaricom.co.ke comment="M-Pesa API"

# Mark unpaid PPPoE connections
/ip firewall mangle add chain=prerouting action=mark-connection new-connection-mark=pppoe-unpaid passthrough=yes \
    comment="WiFiCore: Mark unpaid PPPoE" disabled=yes

# NAT redirect for HTTP traffic (port 80)
/ip firewall nat add chain=dstnat action=dst-nat to-addresses=<PORTAL_IP> to-ports=443 \
    protocol=tcp dst-port=80 connection-mark=pppoe-unpaid \
    comment="WiFiCore: Redirect HTTP to Portal"

# Walled garden - allow portal access
/ip firewall nat add chain=dstnat action=accept connection-mark=pppoe-unpaid \
    dst-address-list=WiFiCore-Portal comment="WiFiCore: Portal Access"
```

### 3. RADIUS Profile Configuration

Add these profiles to your RADIUS configuration (usually in FreeRADIUS `users` file or SQL):

```
# Active user profile
DEFAULT Framed-Protocol == PPP, NAS-Identifier =~ "WiFiCore", Cleartext-Password := "%{User-Password}"
    Service-Type = Framed-User,
    Framed-Protocol = PPP,
    Framed-IP-Address = 255.255.255.255,
    Mikrotik-Group = "pppoe-active",
    Fall-Through = No

# Unpaid/suspended user profile (applied when balance <= 0 or expired)
DEFAULT Framed-Protocol == PPP, NAS-Identifier =~ "WiFiCore", Status == "suspended"
    Service-Type = Framed-User,
    Framed-Protocol = PPP,
    Framed-IP-Address = 255.255.255.255,
    Mikrotik-Group = "pppoe-unpaid",
    Fall-Through = No
```

## Portal URLs

| Route | Purpose | Access |
|-------|---------|--------|
| `/portal/login?captive=1` | Captive login page with payment warning | Public (redirected users) |
| `/portal/login?captive=1&reason=expired` | Specific reason display | Public |
| `/portal/payment?captive=1` | Payment page (simplified) | Auth required |
| `/portal/dashboard` | Full dashboard | Auth required |

## Query Parameters

When redirecting unpaid users, the system uses these query parameters:

- `captive=1` - Indicates user was redirected (shows warning banner)
- `reason=unpaid|expired|suspended` - Specific reason for restriction
- `account=ACC123` - Pre-fill account number (optional)

## Testing the Captive Portal

1. **Create test PPPoE user** with expired/suspended status
2. **Connect via PPPoE** using test credentials
3. **Try to browse** - should redirect to portal
4. **Verify portal loads** with warning message
5. **Make test payment** - use M-Pesa sandbox or test voucher
6. **Check internet access** restored within 1 minute

## Troubleshooting

### User not being redirected
- Check RADIUS is returning `Mikrotik-Group = "pppoe-unpaid"` for unpaid users
- Verify NAT rules are in place: `/ip firewall nat print`
- Check connection marks: `/ip firewall mangle print`
- Ensure portal domain is in walled garden list

### Portal not loading
- Verify portal domain resolves: `:resolve wificore.traidsolutions.com`
- Check walled garden includes portal domain
- Test portal accessibility from router: `/tool fetch url="https://portal..."`

### Payment not restoring access
- Check M-Pesa callback is being received by backend
- Verify PPPoE user status updates after payment
- Confirm RADIUS CoA (Change of Authorization) is triggered
- Check MikroTik receives updated profile assignment

## Security Considerations

1. **HTTPS Only**: Portal must use HTTPS to prevent MITM attacks during payment
2. **Walled Garden**: Carefully control what domains unpaid users can access
3. **Rate Limiting**: Implement rate limiting on login attempts
4. **Token Security**: Portal tokens are HMAC-signed with 24-hour expiration
5. **No Data Leaks**: Portal is tenant-agnostic, only shows user's own data

## Integration with Existing Systems

### RADIUS Integration
The captive portal works with any RADIUS server (FreeRADIUS, Microsoft NPS, etc.) that supports:
- `Mikrotik-Group` attribute (vendor-specific)
- Standard RADIUS accounting

### Payment Integration
- M-Pesa: Uses existing `MpesaService` for STK push
- Vouchers: Integrates with existing voucher system

### Router Management
- Works with existing MikroTik provisioning system
- Scripts generated via `PppoeCaptivePortalService`
- Can be applied via SSH/API using existing connection methods

## Migration Steps

1. Run database migration:
   ```bash
   php artisan migrate --path=database/migrations/2025_05_07_000001_add_portal_password_to_pppoe_users.php
   ```

2. Generate portal passwords for existing PPPoE users:
   ```php
   // One-time script to set default portal passwords
   PppoeUser::whereNull('portal_password')->chunk(100, function ($users) {
       foreach ($users as $user) {
           $user->setPortalPassword($user->account_number); // Default password = account number
           $user->save();
       }
   });
   ```

3. Deploy captive portal config to routers:
   ```php
   // Apply to all routers
   Router::chunk(50, function ($routers) {
       foreach ($routers as $router) {
           $script = app(PppoeCaptivePortalService::class)
               ->generateCaptivePortalConfig($router->id);
           // Apply via SSH/API
       }
   });
   ```

4. Update RADIUS to return `pppoe-unpaid` profile for suspended users

5. Test with a few users before full rollout
