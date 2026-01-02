# WireGuard Controller Setup Guide

## Quick Fix for Production

The WireGuard container requires the `WIREGUARD_API_KEY` environment variable to be set.

### Generate API Key

On your production server, run:

```bash
openssl rand -base64 32
```

This will output something like: `xK8mP3nQ7vR2wS5tU6yV9zA1bC4dE7fG8hI9jK0lM2n=`

### Update .env.production

Edit `/opt/wificore/.env.production`:

```bash
nano /opt/wificore/.env.production
```

Find the line:
```bash
WIREGUARD_API_KEY=
```

And set it to your generated key:
```bash
WIREGUARD_API_KEY=xK8mP3nQ7vR2wS5tU6yV9zA1bC4dE7fG8hI9jK0lM2n=
```

**IMPORTANT:** Keep this key secure! It's used to authenticate API requests to the WireGuard controller.

### Restart WireGuard Container

```bash
cd /opt/wificore
docker compose -f docker-compose.production.yml restart wificore-wireguard
```

### Verify Container is Running

```bash
docker compose -f docker-compose.production.yml ps wificore-wireguard
```

You should see:
```
NAME                  STATUS
wificore-wireguard    Up X seconds (healthy)
```

### Check Logs

```bash
docker compose -f docker-compose.production.yml logs wificore-wireguard --tail=20
```

You should see:
```
Starting WireGuard Controller...
Loading WireGuard kernel module...
WireGuard module already loaded or not available
Verifying IP forwarding...
IP forwarding already configured via docker-compose
IPv6 forwarding already configured via docker-compose
WireGuard Controller initialization complete
API Key: xK8mP3nQ... (masked)
 * Serving Flask app 'controller'
 * Running on http://0.0.0.0:8080
```

## Troubleshooting

### Container Keeps Restarting

**Symptom:** `Restarting (1) X seconds ago`

**Cause:** Missing `WIREGUARD_API_KEY` environment variable

**Solution:** Follow the steps above to set the API key

### "Read-only file system" Errors

**Symptom:** `sysctl: error setting key 'net.ipv4.ip_forward': Read-only file system`

**Status:** This is normal and handled gracefully. IP forwarding is configured via `sysctls` in docker-compose.production.yml, so the container doesn't need to set it manually.

### Module Not Found Errors

**Symptom:** `modprobe: can't change directory to '/lib/modules': No such file or directory`

**Status:** This is normal. The WireGuard kernel module is loaded on the host system, not inside the container.

## Security Notes

1. **Never commit** the `WIREGUARD_API_KEY` to git
2. **Rotate the key** periodically (every 90 days recommended)
3. **Use different keys** for development and production
4. The key is used for internal API communication between the Laravel backend and WireGuard controller

## Backend Configuration

The Laravel backend uses this key to authenticate with the WireGuard controller. It's configured in:

- `backend/config/services.php` - Reads from `WIREGUARD_API_KEY` env var
- `backend/app/Services/TenantVpnTunnelService.php` - Uses the key in API requests

No code changes needed - just set the environment variable!
