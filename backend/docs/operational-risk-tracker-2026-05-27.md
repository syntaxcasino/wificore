# Operational Risk Tracker - 2026-05-27

## Scope
Recent Laravel and provisioning-service changes around router task workflows, PPPoE portal auth, scheduler hotfixes, and Redis-outage hardening.

## Findings And Remediation

| Risk | Severity | Status | Remediation |
| --- | --- | --- | --- |
| Workflow store could be corrupted by partial writes and crash provisioning-service on restart | High | Fixed | Replaced direct writes with atomic temp-file writes plus corrupt-file quarantine/recovery in `provisioning-service/internal/api/workflow_store.go`. |
| PPPoE portal auth middleware still depended on raw cache access and could fail when Redis/cache was down | High | Fixed | Added fail-open guarded cache reads/writes in `backend/app/Http/Middleware/PppoePortalAuthOptimized.php`. |
| Non-workflow callback-first task paths did not use durable retry/outbox delivery | Medium | Fixed | Routed generic task callbacks through the workflow store outbox on delivery failure in `provisioning-service/internal/api/handlers.go`. |
| Callback API key was persisted in plaintext in provisioning-service outbox state | Medium | Fixed | Replaced plaintext persistence with encoded callback auth tokens in `provisioning-service/internal/api/workflow_store.go`; default callback key is no longer written to disk in cleartext. |
| Generic callback helper could panic on nil callback config | Medium | Fixed | Added nil guard in `notifyTaskCallback(...)` in `provisioning-service/internal/api/handlers.go`. |
| Callback guard rejections/ignores lacked durable provisioning audit trail, making 40% stalls hard to diagnose | Medium | Fixed | Added callback-guard audit step persistence in `backend/app/Http/Controllers/Api/InternalProvisioningTaskController.php` for identity/freshness rejects and ignored regressive or terminal-mutation callbacks. |

## Validation
- `php -l backend/app/Http/Middleware/PppoePortalAuthOptimized.php`
- `go build ./...` in `provisioning-service`
- `gofmt -w provisioning-service/internal/api/workflow_store.go provisioning-service/internal/api/handlers.go`

## Deployment Follow-up
1. Deploy backend and provisioning-service together.
2. Restart provisioning-service so the new outbox/store code owns callback persistence from startup.
3. Verify PPPoE portal requests during a Redis outage no longer 500 in auth middleware.
4. Verify router workflow callbacks continue updating Laravel if the callback endpoint is temporarily unavailable.
5. If an old `data/provisioning-workflows.json` exists, inspect any `.corrupt-*` quarantine file after deploy and discard it once confirmed stale.
