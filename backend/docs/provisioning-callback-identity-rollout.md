# Provisioning Callback Identity Rollout

## Goal
Enable strict provisioning callback trust checks without breaking active router workflows.

## Guardrails
Laravel callback controller now supports:
- `tenant_id` + `router_id` callback identity validation
- callback freshness window (`callback_at` vs server time)
- stale/replay rejection (optional)
- audit trail for ignored/rejected callback guards

Provisioning-service now sends:
- `callback_at`
- `tenant_id`
- `router_id`

## Phased Activation

### Phase 0: Deploy code only
Set:
- `PROVISIONING_REQUIRE_CALLBACK_IDENTITY=false`
- `PROVISIONING_REJECT_STALE_CALLBACKS=false`
- keep warnings enabled

Validate logs for 24h:
- no sustained `Provisioning callback missing identity fields`
- no unexpected `Provisioning callback tenant mismatch`
- monitor `Provisioning callback outside freshness window`

### Phase 1: Enforce identity
Set:
- `PROVISIONING_REQUIRE_CALLBACK_IDENTITY=true`
- keep stale rejection disabled

Success criteria:
- callback success rate unchanged
- no spike in task failures at router provisioning stages
- no increase in stuck tasks

Rollback:
- flip `PROVISIONING_REQUIRE_CALLBACK_IDENTITY=false`
- restart backend workers

### Phase 2: Enforce stale rejection
Prerequisite:
- NTP verified for backend + provisioning-service hosts
- clock drift < 2s

Set:
- `PROVISIONING_REJECT_STALE_CALLBACKS=true`
- optionally tighten `PROVISIONING_MAX_CALLBACK_SKEW_SECONDS` from `900` to `120`

Success criteria:
- no false stale rejects in steady state
- stale rejects correspond to real retry/replay or delayed network events

Rollback:
- set `PROVISIONING_REJECT_STALE_CALLBACKS=false`

## Operational Checks

1. Backend log checks:
- `Ignoring regressive provisioning stage callback`
- `Ignoring callback status mutation for terminal task`
- `Provisioning callback identity mismatch`
- `Provisioning callback outside freshness window`

2. Functional checks:
- create router + provision end-to-end
- re-run provisioning idempotently
- verify progress updates go past 40% and complete

3. Audit checks:
- confirm callback guard outcomes are written as provisioning run audit steps (`callback_guard` stage)

## Deployment Order
1. Deploy provisioning-service
2. Deploy backend
3. Restart backend queue workers
4. Monitor logs + task completion metrics
5. Enable phase toggles incrementally

## Preflight Command
Run before enabling strict phases:

```bash
php artisan provisioning:callback-guard-preflight
php artisan provisioning:callback-guard-preflight --strict
php artisan provisioning:callback-guard-preflight --probe-provisioning-date --max-probe-skew-seconds=5
```

Use strict mode in CI/CD gates before flipping:
- `PROVISIONING_REQUIRE_CALLBACK_IDENTITY=true`
- `PROVISIONING_REJECT_STALE_CALLBACKS=true`

## CI Gate
`composer test` now runs callback guard preflight in strict mode before test execution:

```bash
composer callback-guard-preflight
composer test
```

This prevents rollout toggles from drifting into unsafe combinations without detection.

## Production Ops Script
Use the helper script from repo root:

```bash
./scripts/preflight-provisioning-callback-guard.sh
```

Examples:

```bash
# custom compose file
./scripts/preflight-provisioning-callback-guard.sh --compose-file docker-compose.production.yml

# run without strict failure (diagnostic mode)
./scripts/preflight-provisioning-callback-guard.sh --no-strict

# skip provisioning Date-header probe
./scripts/preflight-provisioning-callback-guard.sh --no-probe
```

## Callback Guard Counters
New counters are stored in cache for rollout visibility:
- `metrics:provisioning:callback_guard:identity_validation_failed`
- `metrics:provisioning:callback_guard:freshness_validation_failed`
- `metrics:provisioning:callback_guard:terminal_status_mutation_ignored`
- `metrics:provisioning:callback_guard:regressive_stage_ignored`

Inspect/reset via artisan:

```bash
php artisan provisioning:callback-guard-metrics
php artisan provisioning:callback-guard-metrics --reset
```

## API Exposure
System-admin API now exposes callback guard counters:

`GET /api/system/metrics/provisioning/callback-guard`

Response shape:
- `counters` (per-guard outcome)
- `total`
- `last_updated_at`
- Frontend visibility: System Admin -> Monitoring -> Metrics now includes a "Provisioning Callback Guard" panel backed by `/api/system/metrics/provisioning/callback-guard`.

### Reset Counters
System-admin reset endpoint:

`POST /api/system/metrics/provisioning/callback-guard/reset`

UI action available in **System Admin -> Monitoring -> Metrics** via the **Reset Counters** button in the Provisioning Callback Guard panel.

### Trend Signals
Callback guard API now includes recent trend fields:
- `last_10m_delta` (per outcome)
- `last_10m_total_delta`

This is backed by minute-level cache buckets (rolling ~60 minutes) updated on each guard outcome.

## Automatic Alerting
A scheduled command now checks callback-guard 10-minute trends and emits warning logs when thresholds are exceeded:

```bash
php artisan provisioning:callback-guard-alert-check
```

Scheduler hook:
- runs every minute (`provisioning-callback-guard-alert-check`)
- cooldown suppresses duplicate alerts during sustained incidents

Environment controls:
- `PROVISIONING_CALLBACK_GUARD_ALERT_WINDOW_MINUTES` (default: `10`)
- `PROVISIONING_CALLBACK_GUARD_ALERT_WARN_DELTA` (default: `5`)
- `PROVISIONING_CALLBACK_GUARD_ALERT_CRITICAL_DELTA` (default: `20`)
- `PROVISIONING_CALLBACK_GUARD_ALERT_COOLDOWN_SECONDS` (default: `900`)

Manual force emit (ignores cooldown):

```bash
php artisan provisioning:callback-guard-alert-check --force
```
