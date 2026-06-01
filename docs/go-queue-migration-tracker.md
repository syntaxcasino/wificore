# Go Queue Migration Tracker

## Objective
Move safe queue workloads to Go workers without breaking tenant isolation, billing correctness, or provisioning reliability.

## Current Branch
`bug_fix_tenant_plans`

## Scope Summary
- Move first: router IO-heavy/event-driven queues.
- Keep in Laravel: tenant lifecycle, payments, user lifecycle, schema-sensitive jobs.
- Use hybrid model: Laravel orchestrates domain decisions; Go executes external/network-heavy steps.

## Queue Ownership Matrix
| Queue | Current Owner | Target Owner | Phase | Risk | Notes |
|---|---|---|---|---|---|
| `router-data` | Laravel | Go | 1 | Low | Polling/telemetry collection |
| `router-checks` | Laravel | Go | 1 | Low | Health checks and state probes |
| `router-monitoring` | Laravel | Go | 1 | Low | Monitoring burst handling |
| `router-provisioning` | Laravel | Go (executor path) | 2 | Medium | Keep domain/state commit in Laravel |
| `provisioning` | Laravel | Go (executor path) | 2 | Medium | Device execution only |
| `hotspot-provisioning` | Laravel | Go (executor path) | 2 | Medium | Device-side execution/reporting |
| `vpn-provisioning` | Laravel | Go (executor path) | 2 | Medium | Device-side execution/reporting |
| `tenant-management` | Laravel | Laravel | N/A | High | Schema creation/migrations |
| `payments` | Laravel | Laravel | N/A | High | Financial correctness critical |
| `payment-checks` | Laravel | Laravel | N/A | High | Reconciliation correctness |
| `user-management` | Laravel | Laravel | N/A | High | Transaction + policy coupling |
| `service-control` | Laravel | Laravel | N/A | High | Tenant-sensitive state transitions |
| `security` | Laravel | Laravel | N/A | High | Compliance/audit risk |
| `emails` | Laravel | Laravel | N/A | Low | Can move later if needed |
| `notifications` | Laravel | Laravel | N/A | Low | Can move later if needed |
| `broadcasts` | Laravel | Laravel | N/A | Medium | Realtime path already stable |
| `reports` | Laravel | Laravel | N/A | Medium | Batch/offline workload |
| `dashboard` | Laravel | Laravel | N/A | Medium | App-coupled caching logic |
| `metrics` | Laravel | Laravel | N/A | Medium | App-coupled aggregation logic |

## Capacity Baseline (Current Stack)
- Active concurrent API users (safe range): **~200–500**
- Realtime connected users (moderate event rate): **low thousands**
- First degradation point: overlap of provisioning + monitoring + billing bursts causing PgBouncer pool saturation.

### Capacity Inputs Used
- PgBouncer:
  - `PGBOUNCER_DEFAULT_POOL_SIZE=20`
  - `PGBOUNCER_MAX_DB_CONNECTIONS=60`
  - `PGBOUNCER_MAX_USER_CONNECTIONS=60`
- Postgres:
  - `max_connections=200`
  - `statement_timeout=30000`
  - `lock_timeout=10000`
- PHP-FPM:
  - backend max children: `24`
  - backend-sse max children: `16`

## Go Worker Contract (Required)
Every migrated task must use explicit JSON payload (no Laravel serialized job payloads):
- `job_type`
- `event_id`
- `tenant_id`
- `router_id`
- `correlation_id`
- `idempotency_key`
- `attempt`
- `scheduled_at`
- `timeout_s`
- `payload`

## Status Callback Contract (Go -> Laravel)
- `accepted`
- `running` (include `started_at`)
- `progress` (include `step`, `percent`)
- `succeeded` (include `result`)
- `failed` (include `error_code`, `error_detail`, `retryable`)

## Safety Rules
1. Laravel remains source of truth for tenant validation and final DB state transitions.
2. Go workers must be idempotent using `idempotency_key`.
3. Every task must be replay-safe.
4. Dead-letter queue (DLQ) is mandatory for migrated queues.
5. Correlation ID required across Laravel logs, Go logs, and provisioning service logs.

## Rollout Plan

### Phase 0: Foundation
- [ ] Define queue/topic naming convention for Go workloads.
- [ ] Implement JSON envelope contract.
- [ ] Implement callback endpoint validation/auth.
- [ ] Implement idempotency store with TTL.
- [ ] Implement DLQ + replay command.
- [ ] Add queue lag, retry, and failure alerts per migrated queue.

### Phase 1: Low-Risk Migration
- [ ] Move `router-data` to Go.
- [ ] Move `router-checks` to Go.
- [ ] Move `router-monitoring` to Go.
- [ ] Validate SLO gates for 7 consecutive days.

### Phase 2: Provisioning Executor Migration
- [ ] Move executor path for `router-provisioning`.
- [ ] Move executor path for `provisioning`.
- [ ] Move executor path for `hotspot-provisioning`.
- [ ] Move executor path for `vpn-provisioning`.
- [ ] Keep all final domain commits in Laravel.
- [ ] Validate SLO gates for 14 consecutive days.

### Phase 3: Expansion Decision
- [ ] Re-assess payments/user/tenant queues.
- [ ] Approve/Reject further migration with risk sign-off.

## SLO Acceptance Gates
- p95 queue wait < 2s (realtime/middleware-sensitive queues)
- p95 provisioning step latency < 8s
- failed jobs < 1% (excluding offline device class)
- no tenant-boundary violations
- no increase in Postgres lock timeout events

## Rollback Strategy
- Feature flag per queue:
  - `QUEUE_OWNER_ROUTER_DATA=laravel|go`
  - `QUEUE_OWNER_ROUTER_CHECKS=laravel|go`
  - `QUEUE_OWNER_ROUTER_MONITORING=laravel|go`
  - `QUEUE_OWNER_ROUTER_PROVISIONING=laravel|go`
- [ ] Rollback script documented and tested.
- [ ] Rollback dry-run completed in staging.

## Tracking Log
| Date | Queue | Change | Owner | Result | Notes |
|---|---|---|---|---|---|
| YYYY-MM-DD | `router-data` | Cutover to Go |  |  |  |

## Open Risks
- Tenant schema context logic is Laravel-native; moving those flows prematurely risks data isolation bugs.
- Lock-sensitive DB operations during tenant setup can regress if split incorrectly between systems.
- Payload/version drift between Laravel producer and Go consumer needs strict schema versioning.

## Decisions
| Date | Decision | Rationale | Approved By |
|---|---|---|---|
| YYYY-MM-DD | Start with router queues only | Lowest coupling, highest throughput gain |  |
