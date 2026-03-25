# WireGuard Peer Persistence Fix

## Problem

After VPS reboot, all WireGuard peer configurations disappeared, causing routers to lose VPN connectivity.

## Root Cause

The WireGuard controller was creating the interface manually (not using `wg-quick up`) but attempting to persist peers using `wg-quick save`. This command only works when the interface was brought up with `wg-quick up`, resulting in peers not being saved to the config file.

**Sequence of events:**
1. `entrypoint.sh` creates WireGuard interface manually using `ip link add` and `wg set` commands
2. Laravel backend adds router peers via WireGuard Controller API
3. Controller calls `wg set wg0 peer ...` to add peer to running interface ✓
4. Controller calls `wg-quick save wg0` to persist peer ✗ (fails silently)
5. Peer works until VPS reboot
6. After reboot, only the base interface config is restored (no peers)

## Solution

Modified `wireguard-controller/controller.py` to manually update the config file when peers are added or removed:

### Changes Made

1. **Added `add_peer_to_config()` function** (lines 69-110)
   - Reads `/etc/wireguard/wg0.conf`
   - Checks if peer already exists
   - Appends `[Peer]` section with all peer parameters
   - Persists to disk immediately

2. **Added `remove_peer_from_config()` function** (lines 112-162)
   - Reads config file
   - Finds and removes the entire `[Peer]` section for the specified public key
   - Writes updated config back to disk

3. **Updated `/vpn/peer/add` endpoint** (line 484)
   - Calls `add_peer_to_config()` after `wg set` command
   - Returns `persisted: true/false` in response

4. **Updated `/vpn/peer/remove` endpoint** (line 524)
   - Calls `remove_peer_from_config()` after `wg set` command
   - Returns `persisted: true/false` in response

### Config File Format

Peers are now appended to `/etc/wireguard/wg0.conf` in standard WireGuard format:

```ini
[Peer]
PublicKey = <router_public_key>
PresharedKey = <preshared_key>
AllowedIPs = 10.X.Y.Z/32
PersistentKeepalive = 25
```

## Deployment

### On Production VPS

```bash
# Navigate to project directory
cd /path/to/wificore

# Run deployment script
bash deploy-wireguard-fix.sh
```

The script will:
1. Build updated wireguard-controller image
2. Stop current container
3. Start updated container
4. Verify deployment

### Manual Deployment

```bash
# Build the image
docker-compose -f docker-compose.production.yml build wireguard-controller

# Restart the container
docker-compose -f docker-compose.production.yml up -d wireguard-controller

# Check logs
docker-compose -f docker-compose.production.yml logs -f wireguard-controller
```

## Verification

### 1. Check Config File Contains Peers

```bash
docker exec wireguard-controller cat /etc/wireguard/wg0.conf
```

You should see `[Peer]` sections for each router.

### 2. Check Running WireGuard Status

```bash
docker exec wireguard-controller wg show wg0
```

Should show all connected peers with handshake times.

### 3. Test Reboot Persistence

```bash
# Add a test router with VPN
# Note the router's public key

# Reboot the VPS
sudo reboot

# After reboot, check config file still has the peer
docker exec wireguard-controller cat /etc/wireguard/wg0.conf | grep -A5 "PublicKey = <router_public_key>"

# Check router reconnects automatically
docker exec wireguard-controller wg show wg0
```

## Files Modified

- `wireguard-controller/controller.py` - Added peer persistence functions
- `deploy-wireguard-fix.sh` - Deployment script (new)
- `docs/wireguard-persistence-fix.md` - This documentation (new)

## Technical Details

### Why `wg-quick save` Doesn't Work

`wg-quick save` relies on internal state that's only set when the interface is brought up with `wg-quick up`. When the interface is created manually:

1. `wg-quick` doesn't track the interface
2. `wg-quick save` can't find the interface in its state
3. Command fails silently or does nothing
4. Config file remains unchanged

### Why Manual Config Updates Work

By directly manipulating the config file:

1. Changes are immediately written to disk
2. No dependency on `wg-quick` state
3. Standard WireGuard config format ensures compatibility
4. `entrypoint.sh` loads peers from config on startup using `wg setconf`

### Startup Sequence After Reboot

1. Container starts, runs `entrypoint.sh`
2. Script creates interface manually with base config
3. Script calls `wg setconf` to load peers from config file (line 199)
4. All peers are restored to running interface
5. Routers reconnect automatically

## Monitoring

### Check Peer Count

```bash
# Count peers in config file
docker exec wireguard-controller grep -c "^\[Peer\]" /etc/wireguard/wg0.conf

# Count peers in running interface
docker exec wireguard-controller wg show wg0 peers | wc -l
```

Both counts should match.

### Check for Persistence Issues

```bash
# Check controller logs for persistence warnings
docker-compose -f docker-compose.production.yml logs wireguard-controller | grep "persisted"
```

Look for `persisted: False` which indicates a problem.

## Rollback

If issues occur, rollback to previous version:

```bash
# Stop container
docker-compose -f docker-compose.production.yml stop wireguard-controller

# Restore from backup (if needed)
# Config is in /etc/wireguard/wg0.conf on host

# Start previous version
docker-compose -f docker-compose.production.yml up -d wireguard-controller
```

## Future Improvements

1. Add config file locking to prevent race conditions
2. Implement config file validation before writing
3. Add automatic backup before config modifications
4. Create peer sync job to reconcile running config with file
5. Add metrics for peer persistence success rate

## Related Issues

- WireGuard peers disappearing after VPS reboot
- Routers losing VPN connectivity after server restart
- Manual peer re-addition required after reboot

## References

- WireGuard documentation: https://www.wireguard.com/
- `wg-quick` man page: `man wg-quick`
- WireGuard config format: https://git.zx2c4.com/wireguard-tools/about/src/man/wg.8
