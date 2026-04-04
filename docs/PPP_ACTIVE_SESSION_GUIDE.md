# PPP Active Session Display Guide

## Viewing Connected PPPoE Users

### Terminal / CLI Commands

```bash
# Basic list of active sessions
/ppp active print

# Detailed view with all fields
/ppp active print detail

# Show only specific columns
/ppp active print columns=name,caller-id,address,service,uptime,bytes

# Count only (for monitoring scripts)
/ppp active print count-only

# Filter by specific user
/ppp active print where name="username"

# Show sessions with packet counts
/ppp active print stats
```

### Sample Output
```
# /ppp active print
Flags: R - radius, K - blocked, L - pap, C - chap, M - mschapv1, N - mschapv2, E - encrypted, S - sha256, A - avlan
 0  R E name="user1@domain" service=pppoe caller-id="00:11:22:33:44:55" address=100.64.0.2 uptime=2h15m30s encoding="" session-id=0x81000001 limit-bytes-in=0 limit-bytes-out=0

 1  R E name="user2@domain" service=pppoe caller-id="00:11:22:33:44:66" address=100.64.0.3 uptime=45m12s encoding="" session-id=0x81000002 limit-bytes-in=0 limit-bytes-out=0
```

### WinBox / WebFig GUI

1. Open **WinBox** or **WebFig** (web interface)
2. Navigate to **PPP** menu
3. Click **Active Connections** tab
4. View connected users with real-time data:
   - **Name**: Username (from RADIUS/Local)
   - **Caller ID**: MAC address of client
   - **Service**: pppoe
   - **Address**: Assigned IP (100.64.0.x)
   - **Uptime**: Connection duration
   - **Encoding**: Encryption type (MSCHAP2)
   - **Session ID**: Unique session identifier

## Enabling Enhanced Session Visibility

### 1. PPP Accounting (Already configured)
```rsc
/ppp aaa
set accounting=yes interim-update=5m use-radius=yes
```

### 2. Session Logging
```rsc
# Enable PPP logging to memory
/system logging add action=memory topics=ppp,pppoe

# Optional: Log to disk for persistence
/system logging add action=disk topics=ppp,pppoe

# View logs
/log print where topics~"ppp"
```

### 3. SNMP Monitoring (For external NMS)
```rsc
# Enable SNMP to expose session data
/snmp set enabled=yes

# Query OID for PPP active sessions
# .1.3.6.1.2.1.10.23.2.1.1 (pppLqrTable)
# .1.3.6.1.2.1.10.23.3.1.1 (pppLinkStatusTable)
```

### 4. Real-time Monitoring Script
```rsc
# Create monitoring script
/system script add name=ppp-monitor source={
    :local count [/ppp active print count-only]
    :log info ("PPPoE Active Sessions: " . $count)
    :foreach session in=[/ppp active find] do={
        :local name [/ppp active get $session name]
        :local addr [/ppp active get $session address]
        :local up [/ppp active get $session uptime]
        :log info ("  User: " . $name . " IP: " . $addr . " Uptime: " . $up)
    }
}

# Schedule every minute
/system scheduler add name=ppp-active-logger interval=1m on-event=ppp-monitor
```

## API Access (For Integration)

### REST API Query
```bash
# Fetch active PPP sessions via API
curl -k -u admin:password \
  https://10.100.1.1/rest/ppp/active \
  -H "Content-Type: application/json"
```

### MikroTik API (Python example)
```python
import routeros_api

connection = routeros_api.RouterOsApiPool('10.100.1.1', 
                                            username='admin', 
                                            password='password',
                                            plaintext_login=True)
api = connection.get_api()

# Get active PPP sessions
ppp_active = api.get_resource('/ppp/active')
sessions = ppp_active.get()

for session in sessions:
    print(f"User: {session['name']}, IP: {session['address']}, Uptime: {session['uptime']}")

connection.disconnect()
```

## Troubleshooting Session Display

### Issue: Sessions not showing in /ppp active
**Solutions:**
1. Verify user actually connected: `/interface pppoe-server print` should show sessions
2. Check RADIUS authentication: `/log print where topics~"radius"`
3. Ensure PPP service is running: `/ppp service print`
4. Check interface lists: `/interface list member print`

### Issue: Session drops immediately
**Check:**
```rsc
# View PPP debug logs
/system logging add action=memory topics=debug
/ppp debug add

# Check for IP pool exhaustion
/ip pool used print

# Verify RADIUS connectivity
/radius print stats
```

### Issue: Can't see caller-id (MAC address)
**Fix:** Enable caller-id logging in PPP profile
```rsc
/ppp profile set pppoe-prof-b079c148 incoming-filter=\"\" outgoing-filter=\"\"
```

## Session Limits & Control

### Kick a specific user
```rsc
/ppp active remove [find name="user@domain"]
```

### View session history (if logging enabled)
```rsc
/log print where message~"logged in"
/log print where message~"logged out"
```

### Set session timeout (if needed)
```rsc
/ppp profile set pppoe-prof-b079c148 session-timeout=8h
```

## Integration with Your Application

To display PPPoE sessions in your **WifiCore** dashboard:

1. **Via SNMP**: Poll router for active session count
2. **Via API**: Query `/rest/ppp/active` endpoint
3. **Via SSH**: Execute `/ppp active print` and parse output
4. **Via Syslog**: Forward PPP logs to your central logging system

### Recommended: API Integration
```php
// Laravel/WifiCore example
$client = new \GuzzleHttp\Client([
    'base_uri' => 'https://10.100.1.1/',
    'auth' => ['admin', 'password'],
    'verify' => false,
]);

$response = $client->get('rest/ppp/active');
$sessions = json_decode($response->getBody(), true);

foreach ($sessions as $session) {
    echo $session['name'] . ' - ' . $session['address'];
}
```

---

**Note**: PPP active sessions are automatically displayed by RouterOS when users connect. The session appears immediately after successful RADIUS authentication and remains until disconnect.
