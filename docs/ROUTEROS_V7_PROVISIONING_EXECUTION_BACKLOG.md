# RouterOS v7 Provisioning Execution Backlog

Date: 2026-05-31  
Source Tracker: `docs/ROUTEROS_V7_PROVISIONING_PRIORITY_ACTION_PLAN_TRACKER.md`

## Scope

Translate the priority action plan into implementation tickets with concrete code touchpoints in WiFiCore.

## P0 (Must Ship Before Fresh Production Rollout)

### P0-1: Provisioning Run Audit Ledger
Goal: Persist complete run + step history for every provisioning attempt.

Files to change:
1. `backend/database/migrations/*` (new migration for `provisioning_runs`)
2. `backend/database/migrations/*` (new migration for `provisioning_steps`)
3. `backend/app/Models/` (new `ProvisioningRun`, `ProvisioningStep`)
4. `backend/app/Services/RouterTaskExecutionService.php`
5. `backend/app/Http/Controllers/Api/InternalProvisioningTaskController.php`
6. `backend/app/Jobs/RouterProvisioningJob.php`

Definition of done:
1. Each provisioning attempt creates one run row.
2. Each command/step persists command, payload, result, trap, duration, status.
3. Run can be queried by tenant/router/task.

### P0-2: RouterOS Version/Capability Detection and Storage
Goal: Hard version-aware execution.

Files to change:
1. `backend/app/Services/MikroTik/MikroTikBinaryApiService.php`
2. `backend/app/Services/RouterTaskExecutionService.php`
3. `backend/app/Models/Router.php` (if new fields needed)
4. `backend/database/migrations/*` (if fields missing for architecture/board model)
5. `backend/app/Services/RouterDriver/SystemInfo.php` (normalization)

Definition of done:
1. On onboarding/reprovision, system captures `version`, `architecture-name`, `board-name`.
2. Version normalized into capability profile key.
3. Unsupported versions fail pre-deploy with clear error.

### P0-3: RouterOS v7 Validator Engine
Goal: Fail before deploy for invalid syntax/params/dependencies.

Files to change:
1. `backend/app/Services/ServiceDeploymentValidator.php` (or split into dedicated validator service)
2. `backend/app/Services/MikroTik/ScriptBuilder.php`
3. `backend/app/Services/MikroTik/ConfigurationService.php`
4. `backend/app/Services/RouterTaskExecutionService.php`
5. `backend/app/Http/Controllers/Api/ProvisioningController.php` (if request-level validation is needed)

Definition of done:
1. Validator checks command + parameters against RouterOS version profile.
2. Validator checks dependencies (interface/resource existence preconditions).
3. Validation errors are surfaced to UI/API and stop deployment.

### P0-4: Strict Post-Provision Verification Engine
Goal: Provisioning success only after resource verification.

Files to change:
1. `backend/app/Services/RouterTaskExecutionService.php`
2. `backend/app/Http/Controllers/Api/InternalProvisioningTaskController.php`
3. `backend/app/Services/MikroTik/MikroTikBinaryApiService.php`
4. `backend/app/Events/RouterProvisioningProgress.php`
5. `backend/app/Events/ProvisioningFailed.php`

Definition of done:
1. Verifies expected resources (`bridge`, `pool`, `ppp profile`, `pppoe-server`, `firewall`, `queue`, `wireguard`).
2. If verification fails, run marked failed and emits failure event.
3. Router status cannot become `online` without verification pass.

## P1 (Robustness and Recovery)

### P1-1: Idempotent Provisioning Step Executor
Goal: Re-run safe provisioning with no duplicate resource failures.

Files to change:
1. `backend/app/Services/MikroTik/MikroTikBinaryApiService.php`
2. `backend/app/Services/MikroTik/ConfigurationService.php`
3. `backend/app/Services/MikroTik/HotspotApiConfigurator.php`
4. `backend/app/Services/MikroTik/PppoeApiConfigurator.php`
5. `backend/app/Services/MikroTik/HybridApiConfigurator.php`

Definition of done:
1. Existing resources are updated, missing resources created.
2. Re-running same provisioning request does not hard-fail due to duplicates.

### P1-2: Trap-Aware Execution and Persistence
Goal: Capture and persist every API trap per command.

Files to change:
1. `backend/app/Services/MikroTik/MikroTikBinaryApiService.php`
2. `backend/app/Services/RouterTaskExecutionService.php`
3. `backend/app/Models/ProvisioningStep.php` (from P0-1)

Definition of done:
1. Every `!trap`/`!fatal` is attached to step audit row.
2. Severity policy determines whether execution continues or fails.

### P1-3: Rollback/Compensating Actions
Goal: Cleanly recover from mid-run failures.

Files to change:
1. `backend/app/Jobs/RollbackRouterConfigJob.php`
2. `backend/app/Services/RouterTaskExecutionService.php`
3. `backend/app/Services/MikroTik/*` (reverse operation helpers)

Definition of done:
1. Failed runs trigger rollback plan from recorded created/modified resources.
2. Rollback outcome persisted and visible.

## P2 (Quality and Safety Nets)

### P2-1: Dry-Run Mode
Goal: Validate and preview without mutating devices.

Files to change:
1. `backend/app/Http/Controllers/Api/ProvisioningController.php`
2. `backend/app/Services/RouterTaskExecutionService.php`
3. `frontend/src/modules/tenant/composables/data/useRouters.js`
4. `frontend/src/modules/tenant/components/routers/modals/ReprovisionOverlay.vue`

Definition of done:
1. Dry-run performs generation + validation + dependency checks only.
2. UI shows plan and errors before deploy.

### P2-2: RouterOS Regression Matrix
Goal: Prevent command drift across supported versions.

Files to change:
1. `backend/tests/Feature/RouterProvisioningTest.php`
2. `backend/tests/Unit/Services/MikroTikBinaryApiServiceTest.php`
3. `frontend/src/modules/tenant/composables/__tests__/useRouterProvisioning.test.js`
4. `provisioning-service/internal/api/handlers_test.go` (if provisioning service enforces validations)

Definition of done:
1. CI matrix validates generated command compatibility for 7.8 / 7.15 / 7.18.
2. Release fails on compatibility regression.

## Suggested Sprint Split

### Sprint 1 (P0 Core)
1. P0-1 Audit ledger
2. P0-2 Version detection
3. P0-3 Validator scaffold + first rulesets
4. P0-4 Verification engine

### Sprint 2 (P1 Reliability)
1. P1-1 Idempotent executor migration
2. P1-2 Full trap persistence
3. P1-3 Rollback actions

### Sprint 3 (P2 Hardening)
1. P2-1 Dry-run UX/API
2. P2-2 Regression test matrix

## Operational Tracking Template

Use this for each ticket:

1. Ticket ID:
2. Owner:
3. Branch:
4. Start date:
5. Status: `todo | in_progress | blocked | done`
6. Files touched:
7. Risks:
8. Test evidence:
9. Rollout notes:

## Exit Criteria for "Provisioning Ready"

1. P0 complete and deployed.
2. Router provisioning success rate >= 99% over staged sample.
3. No silent 40% stalls in audit window.
4. Every failed run has actionable trap/verification evidence in DB.

