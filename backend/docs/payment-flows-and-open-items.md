# Payment Flows And Open Items

## Scope

This document tracks the end-to-end payment flows for PPPoE and hotspot subscriptions, the current logging and reconciliation strategy, and the remaining open items that affect correctness or performance.

## Shared Rules

- RADIUS remains the authoritative authentication layer. There are no local-auth shortcuts.
- Renewal expiry extends from the later of the current expiry or the payment timestamp. Payments are renewals, not resets.
- Duplicate STK requests are blocked while an unresolved pending payment exists for the same subject.
- Payment traces are logged through the landlord-controlled `payment_trace_mode` switch with `stdout`, `persistent`, or `both`.
- The stale pending payment reconciler is scheduled and runs through `payments:check-pending`.

## PPPoE Payment Flow

1. The PPPoE portal or payment controller initiates STK.
2. A pending payment row is created and transaction mapping is stored.
3. The M-Pesa callback arrives at `PaymentController::callback()` or the PPPoE portal callback path.
4. The system resolves the tenant from the public transaction map or router context.
5. Payment state is updated and the PPPoE billing lifecycle service extends expiry from the later of the current expiry or payment time.
6. Reconnect/disconnect enforcement is queued after commit and projected back into the tenant schema.
7. Portal debug endpoints expose the latest callback payload, cache state, and reconnect job state for support.

## Hotspot Payment Flow

1. The hotspot purchase flow initiates STK using the hotspot user identity and package selection.
2. A pending payment row is created and duplicate unresolved pending payments are rejected.
3. The M-Pesa callback resolves the hotspot user and tenant context from the router/tenant map, not from the username alone.
4. `CreateHotspotUserJob` or `GrantHotspotAccessJob` provisions access after commit.
5. Renewal expiry extends from the later of the current expiry or payment time.
6. Hotspot debug endpoints expose the latest payment, callback payload, cache state, and provisioning job state.
7. Hotspot login, logout, and session checks resolve the tenant first, then query the hotspot user within that tenant schema.

## Open Items Tracker

| Status | Item | Notes |
| --- | --- | --- |
| done | Landlord payment trace mode | `stdout`, `persistent`, and `both` are available. |
| done | Structured payment tracing | STK, callback, mapping, tenant resolution, and job dispatch are traced. |
| done | Renewal expiry helper | PPPoE and hotspot renewals extend from the later of current expiry or payment time. |
| done | Pending payment reconciliation schedule | `payments:check-pending` now runs every 5 minutes. |
| done | Hotspot tenant-scoped login/session/logout resolution | Username alone is not a safe global key. Lookups now resolve tenant first and auth runs in tenant context. |
| done | Hotspot expiry sweep optimization | The sweep now streams per-tenant expired users instead of materializing the full set. |
| done | Live session cold-path cost | Live sessions now prefer the cached `RadiusSession` table before falling back to `radacct`. |
| done | Incident logging retention policy | Landlord can keep payment traces persistent and switch back to stdout through the logging mode toggle. |
| done | End-to-end payment failure reproduction | Sysadmins can pull recent payment traces and raw callbacks from the support endpoint. |

## Operational Notes

- For PPPoE, inspect `storage/logs/payment_trace.log`, `storage/logs/mpesa_raw_callback.log`, and the PPPoE portal debug endpoint.
- For hotspot, inspect the hotspot payment debug endpoint, the payment trace log, and the reconnect/provisioning job state.
- When payment logging is no longer needed for incident response, switch the landlord mode back to `stdout`.
