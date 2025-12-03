# IP Unblock Guide

This guide explains how to unblock IPs that have been blocked due to suspicious activity.

## Scripts Available

### 1. `unblock-ip.sh` - General Linux Server
For traditional Linux servers with fail2ban and iptables.

### 2. `unblock-ip-docker.sh` - Docker Environment
For Dockerized applications like this WiFi Hotspot system.

## Quick Start

### Make Scripts Executable

```bash
chmod +x unblock-ip.sh
chmod +x unblock-ip-docker.sh
```

### Unblock an IP (Docker Environment)

```bash
# Basic usage
sudo ./unblock-ip-docker.sh 192.168.1.100

# List all blocked IPs
./unblock-ip-docker.sh list
```

### Unblock an IP (Traditional Server)

```bash
# Basic usage
sudo ./unblock-ip.sh 192.168.1.100

# List all blocked IPs
sudo ./unblock-ip.sh list
```

## Common Blocking Mechanisms

### 1. **Laravel Rate Limiting**
Laravel can block IPs that exceed rate limits.

**Symptoms:**
- HTTP 429 (Too Many Requests)
- "Access denied. Your IP has been temporarily blocked"

**Solution:**
```bash
# Clear rate limit cache
docker exec traidnet-backend php artisan cache:clear

# Or use the script
./unblock-ip-docker.sh YOUR_IP
```

### 2. **fail2ban**
Blocks IPs after multiple failed login attempts.

**Check if IP is banned:**
```bash
sudo fail2ban-client status
sudo fail2ban-client status nginx-limit-req
```

**Unblock manually:**
```bash
sudo fail2ban-client set nginx-limit-req unbanip 192.168.1.100
```

### 3. **iptables**
Firewall rules blocking specific IPs.

**Check iptables:**
```bash
sudo iptables -L -n | grep YOUR_IP
```

**Unblock manually:**
```bash
sudo iptables -D INPUT -s 192.168.1.100 -j DROP
```

### 4. **Nginx**
Can block IPs via deny directives.

**Check nginx config:**
```bash
docker exec traidnet-nginx grep -r "deny" /etc/nginx/
```

**Check nginx logs:**
```bash
docker exec traidnet-nginx tail -f /var/log/nginx/access.log | grep YOUR_IP
```

## What the Scripts Do

### `unblock-ip-docker.sh`

1. ✅ Checks nginx container for IP blocks
2. ✅ Checks fail2ban in containers
3. ✅ Removes IP from host iptables
4. ✅ Clears Laravel rate limiting cache
5. ✅ Checks Redis for IP-related keys
6. ✅ Provides verification and next steps

### `unblock-ip.sh`

1. ✅ Unblocks IP from fail2ban jails
2. ✅ Removes IP from iptables rules
3. ✅ Removes IP from Docker iptables chains
4. ✅ Checks web server logs
5. ✅ Verifies unblock was successful

## Manual Unblock Methods

### Clear Laravel Rate Limit Cache

```bash
# Method 1: Clear all cache
docker exec traidnet-backend php artisan cache:clear

# Method 2: Clear specific rate limit keys
docker exec traidnet-backend php artisan tinker --execute="
Cache::forget('rate-limit:192.168.1.100');
"

# Method 3: Flush Redis (nuclear option)
docker exec traidnet-redis redis-cli FLUSHDB
```

### Check Redis for Blocked IPs

```bash
# List all keys containing IP
docker exec traidnet-redis redis-cli KEYS "*192.168.1.100*"

# Delete specific key
docker exec traidnet-redis redis-cli DEL "rate-limit:192.168.1.100"

# List all rate limit keys
docker exec traidnet-redis redis-cli KEYS "*rate-limit*"
```

### Restart Services

```bash
# Restart nginx
docker restart traidnet-nginx

# Restart backend
docker restart traidnet-backend

# Restart all containers
docker-compose restart
```

## Troubleshooting

### Issue: IP Still Blocked After Running Script

**Solution:**
1. Clear browser cache and cookies
2. Restart the affected service:
   ```bash
   docker restart traidnet-nginx
   docker restart traidnet-backend
   ```
3. Check if IP is in multiple blocking systems
4. Wait a few minutes for cache to expire

### Issue: "Permission Denied" Error

**Solution:**
Run the script with sudo:
```bash
sudo ./unblock-ip-docker.sh 192.168.1.100
```

### Issue: Can't Find Which System Blocked the IP

**Solution:**
1. Check all logs:
   ```bash
   # Nginx access log
   docker exec traidnet-nginx tail -100 /var/log/nginx/access.log | grep YOUR_IP
   
   # Nginx error log
   docker exec traidnet-nginx tail -100 /var/log/nginx/error.log | grep YOUR_IP
   
   # Laravel log
   docker exec traidnet-backend tail -100 /var/www/html/storage/logs/laravel.log | grep YOUR_IP
   ```

2. Check iptables:
   ```bash
   sudo iptables -L -n -v | grep YOUR_IP
   ```

3. Check fail2ban:
   ```bash
   sudo fail2ban-client status
   ```

## Prevention

### Whitelist Trusted IPs

**In Laravel** (`config/rate-limiting.php` or middleware):
```php
// Add to trusted IPs list
protected $trustedIps = [
    '192.168.1.100',
    '10.0.0.0/8',
];
```

**In nginx** (`nginx.conf`):
```nginx
# Allow specific IP
allow 192.168.1.100;

# Allow subnet
allow 192.168.1.0/24;
```

**In fail2ban** (`/etc/fail2ban/jail.local`):
```ini
[DEFAULT]
ignoreip = 127.0.0.1/8 192.168.1.100
```

### Adjust Rate Limits

**Laravel** (`config/rate-limiting.php`):
```php
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(100)->by($request->ip()); // Increase from 60 to 100
});
```

### Monitor Logs

```bash
# Watch nginx access log
docker exec traidnet-nginx tail -f /var/log/nginx/access.log

# Watch Laravel log
docker exec traidnet-backend tail -f /var/www/html/storage/logs/laravel.log

# Watch for 403/429 errors
docker exec traidnet-nginx tail -f /var/log/nginx/access.log | grep -E " (403|429) "
```

## Emergency: Unblock All IPs

**⚠️ WARNING: This will remove all IP blocks. Use with caution!**

```bash
# Flush all iptables rules (DANGEROUS!)
sudo iptables -F

# Restart fail2ban (clears all bans)
sudo systemctl restart fail2ban

# Clear all Laravel cache
docker exec traidnet-backend php artisan cache:clear

# Flush Redis (removes all cached data)
docker exec traidnet-redis redis-cli FLUSHALL
```

## Support

If you continue to experience issues:

1. Check the error message carefully
2. Review the logs for the specific IP
3. Verify the IP is actually blocked (not a different issue)
4. Try accessing from a different IP to confirm it's IP-specific
5. Contact system administrator with log details

## Related Files

- `backend/app/Http/Middleware/ThrottleRequests.php` - Rate limiting middleware
- `backend/config/rate-limiting.php` - Rate limit configuration
- `nginx/nginx.conf` - Nginx configuration
- `/etc/fail2ban/jail.local` - fail2ban configuration (if installed)
