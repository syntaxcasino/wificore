# End-to-End Review Note — 2026-02-09

Date: 2026-02-09 (UTC+03:00)

## Scope
- PPPoE authentication + RADIUS attribute sync (radcheck/radreply)
- Hotspot captive portal + walled garden rules
- Hybrid VLAN separation (PPPoE + Hotspot)
- RouterOS script generators and firewall/NAT ordering
- Tenant schema isolation and router-to-tenant resolution
- Billing enforcement and session disconnect flows
- SSH usage boundaries (provisioning-only policy)

## Checks Performed (Code Review Only)
- Verified PPPoE RADIUS sync and Auth-Type reject for blocked/expired users.
- Reviewed RouterOS generators for unauthenticated traffic drop rules and scoped NAT.
- Verified Hybrid VLAN enforcement and per-service isolation rules.
- Reviewed captive portal endpoints and tenant-context resolution for public access.
- Confirmed billing enforcement jobs disconnect sessions via RADIUS/SSH best-effort.
- Reviewed SSH executor error handling and provisioning job usage patterns.
- Confirmed tenant schema mapping for public router lookups.

## Findings
- No new blocking issues identified beyond already-tracked fixes.
- Outstanding remediation items remain:
  - Confirm SSH usage remains restricted to provisioning and explicit disconnect paths.
- Remediation completed:
  - RscFileCleanupService uses DI-based SSH executor resolution.
  - DeployRouterServiceJob: reassert tenant search_path before DB updates (fixed missing table errors).
  - NT-Password hash: changed to uppercase hex (`strtoupper(hash('md4', ...))`) for FreeRADIUS mschap compatibility.
  - Expiration attribute: changed from Unix timestamp to FreeRADIUS date format (`F d Y H:i:s`) in both `syncRadiusCredentials` and `syncRadiusMetaForUser`.
  - PPPoE firewall: added DNS allow rules (UDP+TCP port 53) for unauthenticated clients in `ZeroConfigPPPoEGenerator` and `PPPoEService`.
  - Forward chain cleanup regex updated to include DNS comment pattern for idempotent re-ordering.

## Notes
- This is a code-only review; no runtime tests executed in this pass.
- Observed DeployRouterServiceJob failures updating tenant router_services after long SSH deploys; fixed with search_path reassertion.
- Duplicate provisioning in logs confirmed as retry behavior (3 tries with backoff), not double dispatch. Cache lock already prevents concurrent runs.
- PPPoE auth failure root cause: FreeRADIUS mschap module requires NT-Password in uppercase hex; PHP `hash('md4',...)` returns lowercase. Also, Expiration attribute was stored as Unix timestamp instead of FreeRADIUS date string, causing expiration module to reject users.
