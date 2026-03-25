# Hotspot Deployment – Root Cause Analysis & Permanent Fix

This document explains the root causes behind repeated MikroTik Hotspot deployment failures and the fixes applied to prevent recurrence.

## Symptoms Observed (RouterOS logs)

- Repeated:
  - `syntax error (line 1 column 103)`
  - `syntax error (line 1 column 129)`
  - `input does not match any value of public-key-file (/user/ssh-keys/import (public-key-file); line 1)`
- Follow-on deployment instability:
  - intermittent `Hotspot: bridge port add failed (etherX)`
  - repeated add/remove cycles caused by retries / overlapping sessions

## Root Causes

### 1) Broken SSH public-key auto-bootstrap

The backend SSH connector attempts to auto-install an SSH public key on first connect.

Problems in the previous approach:

- **Invalid file upload technique**: the previous `uploadFile()` attempted to build RouterOS files using RouterOS commands, which produced malformed RouterOS syntax and created invalid file contents.
- **Invalid `/user ssh-keys import` call**: parameters were not quoted, which is sensitive on RouterOS.
- **Non-idempotent detection**: the “already installed” check didn’t match real RouterOS output reliably, causing repeated retries.
- **No rate-limiting / locking**: repeated SSH connects caused repeated failed imports and spammed RouterOS logs.

Fix applied:

- Switch file upload to **SFTP** via `phpseclib3\Net\SFTP`.
- Quote RouterOS import args:
  - `/user ssh-keys import public-key-file="..." user="..."`
- Add **per-router cache lock + cooldown** to prevent repeated attempts.

### 2) Overlapping provisioning runs (concurrency)

Multiple provisioning operations could run simultaneously against the same router, causing partial state and repeated add/remove cycles.

Fix applied:

- Add a **per-router provisioning lock** around `applyConfigs()` (Cache lock).

### 3) Bridge port idempotency

Re-running a Hotspot deployment can fail if a port is already attached to the bridge.

Fix applied:

- Before adding each bridge port, explicitly remove existing `bridge+interface` bindings:
  - `/interface bridge port remove [find bridge="..." interface="..."]`

## Files Changed

- `backend/app/Services/MikroTik/SshExecutor.php`
  - SFTP-based upload
  - Correct RouterOS import quoting
  - Per-router lock + cooldown for auto-bootstrap
  - Improved RouterOS error visibility in thrown exceptions

- `backend/app/Services/MikrotikProvisioningService.php`
  - Per-router provisioning lock around `applyConfigs()`

- `backend/app/Services/MikroTik/ZeroConfigHotspotGenerator.php`
  - Idempotent bridge-port handling

## Deploy / Rebuild Commands (Docker)

Run from the repo root (`d:\traidnet\wificore`).

### Rebuild and recreate backend

```bash
docker compose up -d --build --force-recreate wificore-backend
```

If you also changed shared libraries or want to be safe:

```bash
docker compose up -d --build --force-recreate wificore-backend wificore-nginx
```

## Validation Checklist (must pass)

### A) Confirm SSH auto-bootstrap no longer spams errors

- Router log should no longer show repeated:
  - `syntax error ...` related to key import
  - `public-key-file` mismatch

### B) Run hotspot deployment once

- Hotspot deploy completes without RouterOS `script,error` messages.

### C) Re-run hotspot deployment (idempotency)

- Second run should **not** fail on bridge ports.
- Bridge ports should remain stable.

### D) Captive portal + RADIUS

- Client gets DHCP
- HTTP/HTTPS redirect rules exist
- RADIUS entry exists for service `hotspot`

## Notes

- RouterOS error output is now surfaced in backend exceptions (truncated), so you can see the *real* RouterOS failure reason without guessing.
