# MikroTik Provisioning - Priority Action Plan Tracker

Date created: 2026-05-31  
Owner: Platform/Provisioning Team  
Scope: Event-based RouterOS provisioning reliability for WiFiCore

## Objective

Eliminate provisioning stalls (including the 40% stuck state), silent failures, and partial router configurations by introducing strict pre-deploy validation, deterministic execution, verification, and rollback.

## Success Criteria (Release Gate)

1. No router provisioning is marked successful unless post-provision verification passes.
2. Every provisioning command has persisted execution outcome, including traps.
3. Provisioning can be safely retried (idempotent behavior).
4. Failed provisioning leaves no partial unmanaged state (rollback or compensating actions).
5. Dry-run mode is available and used in CI for supported RouterOS versions.

## Current Baseline (Observed)

1. Provisioning flow is event/task-based and already emits progress events.
2. Existing validation is mostly service/business validation, not strict RouterOS command-schema validation.
3. Trap handling exists but is not fully persisted as a per-command audit ledger.
4. Version and interface data exist, but command generation is not strictly version-gated.
5. Verification exists in parts, but not as a hard success contract for all expected resources.

## Workstream Plan

### WS1 - Provisioning Run Ledger (Foundation)
Status: `not_started`

Deliverables:
1. Add `provisioning_runs` table.
2. Add `provisioning_steps` table.
3. Persist for each step: command, params, response, trap, duration, stage, success/failure.
4. Correlate with `router_tasks` and router ID.

Acceptance:
1. One provisioning attempt = one run record with step history.
2. Failed steps are queryable by router, tenant, and timestamp.

### WS2 - RouterOS Version/Capability Registry
Status: `not_started`

Deliverables:
1. Add capability map for RouterOS versions (initially: 7.8, 7.15, 7.18).
2. Normalize version parsing from `/system/resource/print`.
3. Store detected version, architecture, board model during onboarding and refresh.

Acceptance:
1. Generator can select command profile by detected version.
2. Unknown/unsupported versions are blocked with actionable error.

### WS3 - RouterOS v7 Validator (Highest Priority)
Status: `not_started`

Deliverables:
1. Preflight validator before deployment:
   - command allowed for RouterOS version
   - parameter allowed for command/version
   - dependency/resource prerequisites present
2. Validation domains:
   - PPP profiles
   - firewall rules
   - queue configuration
   - NTP config
   - bridge config
   - WireGuard config
3. Fail-fast behavior with explicit reasons returned to UI/API.

Acceptance:
1. Invalid/unsupported command is rejected before execution.
2. Validation failure never advances to deployment stage.

### WS4 - Interface/Dependency Preflight
Status: `not_started`

Deliverables:
1. Query interfaces (`/interface/print`) before generation/deploy.
2. Validate WAN/LAN/bridge/WireGuard references exist.
3. Block deploy if required interfaces are missing or mismatched.

Acceptance:
1. No command is generated against non-existent interface names.

### WS5 - Idempotent Step Executor
Status: `not_started`

Deliverables:
1. Convert "blind add" operations into `ensure` semantics:
   - if exists -> update
   - if missing -> create
2. Apply across hotspot, PPPoE, firewall, queue, and WG resources.

Acceptance:
1. Re-running provisioning does not fail with duplicate resource errors.

### WS6 - Trap-Aware Execution Pipeline
Status: `not_started`

Deliverables:
1. Wrap every API command with:
   - send
   - inspect `!trap`/`!fatal`
   - persist outcome
   - decide continue/fail by severity policy
2. Store trap details with command context.

Acceptance:
1. All traps are persisted and visible in run audit.
2. Required-step traps immediately fail the run.

### WS7 - Post-Provision Verification Engine
Status: `not_started`

Deliverables:
1. Verify expected resources after deploy:
   - `/interface bridge`
   - `/ip pool`
   - `/ppp profile`
   - `/interface pppoe-server server`
   - `/ip firewall`
   - `/queue`
   - `/interface wireguard`
2. Return detailed diff: expected vs actual.

Acceptance:
1. Provisioning status = success only if verification passes.

### WS8 - Rollback / Compensating Actions
Status: `not_started`

Deliverables:
1. Record created/updated resources per step in run ledger.
2. On failure: execute reverse/compensating actions in safe order.
3. Persist rollback results in run audit.

Acceptance:
1. Failed run does not leave unmanaged partial state.

### WS9 - Dry-Run Mode
Status: `not_started`

Deliverables:
1. `dry_run=true` path:
   - generate
   - validate
   - dependency check
   - simulated plan output
   - no device mutation
2. Expose dry-run result in UI/API.

Acceptance:
1. Operations team can preview exact commands and validation outcome without deployment.

### WS10 - RouterOS Regression Suite
Status: `not_started`

Deliverables:
1. Automated tests for:
   - PPPoE
   - Hotspot
   - WireGuard
   - Firewall
   - Queues
   - Routing/NAT/DHCP
2. Version matrix: 7.8 / 7.15 / 7.18 profiles.
3. CI gate on validator + generator + dry-run checks.

Acceptance:
1. Release is blocked on regression failures in supported profiles.

## Implementation Sequence (Recommended)

1. WS1 -> WS2 -> WS3 -> WS4
2. WS5 -> WS6 -> WS7
3. WS8 -> WS9 -> WS10

Reason:
1. Establish observability first.
2. Enforce safety before execution scale.
3. Add rollback and CI hardening after deterministic execution is in place.

## Event-Based Flow (Target)

1. Router Added
2. Detect RouterOS version/capabilities
3. Generate config plan
4. Validate syntax + dependencies
5. Optional dry-run
6. Deploy (idempotent executor)
7. Capture traps and command outcomes
8. Verify expected resources
9. Success event OR rollback and failure event

## Operational KPIs

1. Provision success rate (%)
2. Median/95th provisioning duration
3. Validation rejection rate (pre-deploy)
4. Trap incidence rate by command family
5. Rollback invocation rate
6. Stuck-run count (> N minutes without stage transition)

## Immediate Next Deliverable

Build and ship:
1. WS1 (run ledger)
2. WS2 (version registry)
3. WS3 (RouterOS v7 validator)
4. WS7 (verification engine)

These four deliverables should be treated as the minimum safe baseline before fresh production rollout.


## Track Update - 2026-05-31 (Tenant Isolation + Performance Safety)

Status: `in_progress`

Implemented now:
1. Removed unsafe default-tenant fallback from `BelongsToTenant` create hook.
2. Added fail-fast behavior when tenant context is missing (`RuntimeException`).
3. Added unit test coverage for fail-fast tenant enforcement:
   - `backend/tests/Unit/Traits/BelongsToTenantTest.php`

Why this matters:
1. Prevents silent cross-tenant misrouting to a default tenant.
2. Removes per-create fallback tenant lookup query (small but direct write-path performance gain).
3. Forces explicit tenant context in event/queue flows, which is required for safe event-based provisioning.

Remaining hardening tasks (must complete before rollout sign-off):
1. Add static guardrails for `withoutGlobalScope(s)` and raw `DB::table(...)` usage in tenant domains.
2. Add two-tenant integration leak tests for routers, PPPoE, hotspot, payments, and sessions.
3. Add runtime alerting when tenant context is null inside tenant-write paths.
