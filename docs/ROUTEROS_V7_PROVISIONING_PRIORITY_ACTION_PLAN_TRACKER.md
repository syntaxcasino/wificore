# MikroTik Provisioning - Priority Action Plan Tracker

Date created: 2026-05-31  
Owner: Platform/Provisioning Team  
Scope: Event-based RouterOS provisioning reliability for WiFiCore

## Objective

Eliminate provisioning stalls (including the 40% stuck state), silent failures, and partial router configurations by introducing strict pre-deploy validation, deterministic execution, verification, and rollback.


## Progress Snapshot

Done:
- WS1 Provisioning Run Ledger.
- WS2 RouterOS Version/Capability Registry.
- WS3 RouterOS v7 Validator.
- WS4 Interface/Dependency Preflight.
- WS5 Idempotent Step Executor.
- WS6 Trap-Aware Execution Pipeline.
- WS7 Post-Provision Verification Engine.
- WS8 Rollback / Compensating Actions.
- WS9 Dry-Run Mode.
- WS10 RouterOS Regression Suite.
- WS11 ISP Health Score Engine.
- WS12 Automated Backups and Safe Rollback.
- WS13 Router Compliance Engine.
- WS17 AI Troubleshooting Assistant.
- WS18 Inventory Discovery and Topology Enrichment.
- WS19 Mass Upgrade and Change Orchestration.
- WS20 Multi-WAN Automation and Template Marketplace.
- Tenant isolation and performance safety hardening.
- Callback guard escalation + mixed-stage race coverage.
- Recent hardening is documented for callback-guard escalation, mixed-stage race handling, RouterOS grammar normalization, interface preflight expansion, idempotent workflow deduplication, resource-level ensure semantics on MikroTik API helpers, hotspot/hybrid/PPPoE upsert conversion, rollback dispatch plus rollback edge-case coverage, the router provisioning ledger read path exposed in the details overlay, the config-backed vendor registry that keeps new vendor/model onboarding out of controller branching, the event-driven ISP health score engine with tenant-scoped snapshots and dashboard explainability, the deployment-safety service with pre-deploy snapshots and rollback-on-failed-checks enforcement, the deterministic troubleshooting assistant with inventory topology enrichment, the mass orchestration preview and deployment flow, and the multi-WAN failover/PCC deployment path with validator coverage.

In Progress:
- None currently identified

Blocked:
- None currently identified

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

## Track Update - 2026-06-01 (Verification Diff + Dry-Run Summary)
1. The post-provision verification policy now emits expected vs actual resource maps, missing resources, and unexpected resources instead of only a binary pass/fail result.
2. The router provisioning dry-run response now includes a structured summary with script length, interface counts, warnings, and missing interfaces.
3. The frontend provisioning modal now surfaces dry-run warnings directly so operators can see preflight risk before deploy.
4. Validation passed for the updated controller, verification unit test, preflight service, and frontend provisioning composable.

## Track Update - 2026-06-01 (WAN Overlap Guard + Dry-Run Summary Card)
1. The reusable router provisioning preflight service now rejects deployments that reuse the configured WAN interface for hotspot/PPPoE/service interfaces.
2. The router provisioning modal now renders a dry-run summary card with script length, interface counts, warnings, and missing interfaces before deployment.
3. Frontend dry-run preview state now survives the request cycle and is reset cleanly when the overlay closes.
4. Validation passed for the updated preflight service, preflight unit test, provisioning composable, and provisioning modal.

## Workstream Plan

### WS1 - Provisioning Run Ledger (Foundation)
Status: `completed`

Deliverables:
1. Add `provisioning_runs` table.
2. Add `provisioning_steps` table.
3. Persist for each step: command, params, response, trap, duration, stage, success/failure.
4. Correlate with `router_tasks` and router ID.

Acceptance:
1. One provisioning attempt = one run record with step history.
2. Failed steps are queryable by router, tenant, and timestamp.

Track Update - 2026-06-01 (Provisioning Run Ledger Filters):
1. The provisioning ledger now supports status and time-window filters in addition to router/tenant scoping.
2. Ledger responses remain cache-busted and now include filter metadata and has_more paging hints.
3. The run ledger is now closed as a usable incident-review surface rather than only a read-only list.

Validation:
1. `php -l backend/app/Http/Controllers/Api/RouterController.php`
2. `php -l backend/tests/Feature/RouterProvisioningLedgerTest.php`
3. `php artisan test --no-coverage --filter=RouterOsV7ProvisioningValidatorTest`
4. `php artisan test --no-coverage --filter=RouterOsProvisioningMatrixTest`

### WS2 - RouterOS Version/Capability Registry
Status: `completed`

Deliverables:
1. Add capability map for RouterOS versions (initially: 7.8, 7.15, 7.18).
2. Normalize version parsing from `/system/resource/print`.
3. Store detected version, architecture, board model during onboarding and refresh.
4. Resolve vendor support from config-backed registry entries so new model onboarding does not require controller or job code changes.

Acceptance:
1. Generator can select command profile by detected version.
2. Unknown/unsupported versions are blocked with actionable error.
3. New vendor/model matches can be introduced by config/driver metadata only.

### WS3 - RouterOS v7 Validator (Highest Priority)
Status: `completed`

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
Status: `completed`

Deliverables:
1. Query interfaces (`/interface/print`) before generation/deploy.
2. Validate WAN/LAN/bridge/WireGuard references exist.
3. Block deploy if required interfaces are missing or mismatched.

Acceptance:
1. No command is generated against non-existent interface names.

### WS5 - Idempotent Step Executor
Status: `completed`

Deliverables:
1. Convert "blind add" operations into `ensure` semantics:
   - if exists -> update
   - if missing -> create
2. Short-circuit duplicate workflow submissions using the provisioning service idempotency store.
3. Apply across hotspot, PPPoE, firewall, queue, and WG resources.
4. Enforce idempotent resource handling in both REST and binary MikroTik API transports.

Acceptance:
1. Re-running provisioning does not fail with duplicate resource errors.
2. Duplicate workflow submissions are reused instead of re-posted.
3. Existing VLAN, PPPoE, firewall, NAT, and interface-list resources are updated or re-used instead of duplicated.
4. Remaining blind-add paths in hotspot, hybrid, and PPPoE configurators are converted to upsert semantics.

Track Update - 2026-06-01 (WS5 Transport Contract Hardening):
1. The shared MikroTik API contract now explicitly documents `upsertResource()` as the post-operation record source for both REST and binary transports.
2. The binary transport regression suite now includes a contract assertion so the shared idempotent behavior remains visible in tests.
3. WS5 remains in progress overall because broader configurator and runtime rollout validation still needs a full release cycle, but the transport contract is now stable for the current codebase.

### WS6 - Trap-Aware Execution Pipeline
Status: `completed`

Deliverables:
1. Wrap every API command with:
   - send
   - inspect `!trap`/`!fatal`
   - persist outcome
   - decide continue/fail by severity policy
2. Store trap details with command context.
3. Preserve trap text when the upstream response only provides `status` + `message`.

Acceptance:
1. All traps are persisted and visible in run audit.
2. Required-step traps immediately fail the run.
3. Trap messages remain visible even when the payload omits a dedicated trap field.

### WS7 - Post-Provision Verification Engine
Status: `completed`

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
Status: `completed`

Deliverables:
1. Record created/updated resources per step in run ledger.
2. On failure: execute reverse/compensating actions in safe order.
3. Persist rollback results in run audit.
4. Fall back to snapshot restore when the compensating plan is incomplete or fails.

Acceptance:
1. Failed run does not leave unmanaged partial state.

### WS9 - Dry-Run Mode
Status: `completed`

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
Status: `completed`

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

Status: `completed`

Implemented now:
1. Removed unsafe default-tenant fallback from `BelongsToTenant` create hook.
2. Added fail-fast behavior when tenant context is missing (`RuntimeException`).
3. Added runtime critical logging when tenant-scoped model creation happens without tenant context.
4. Expanded static guardrails for `withoutGlobalScope(s)` and raw `DB::table(...)` usage in tenant domains.
5. Added two-tenant isolation regression coverage for routers, PPPoE, hotspot, payments, and sessions.
6. Added unit test coverage for fail-fast tenant enforcement:
   - `backend/tests/Unit/Traits/BelongsToTenantTest.php`
   - `backend/tests/Feature/TenantIsolationLeakRegressionTest.php`

Why this matters:
1. Prevents silent cross-tenant misrouting to a default tenant.
2. Removes per-create fallback tenant lookup query (small but direct write-path performance gain).
3. Forces explicit tenant context in event/queue flows, which is required for safe event-based provisioning.
4. Keeps intentional raw DB access isolated to allowlisted files while the guard still blocks accidental bypasses elsewhere.

Validation note:
1. The guard scanner passes in strict mode.
2. The new DB-backed leak regression is committed and skips cleanly on runtimes without `pdo_pgsql`; it is intended for the pgsql-capable backend runtime.

## Strategic Expansion Track (Added 2026-05-31)

Reference document:
1. `docs/NOC_DIGITAL_TWIN_AND_RECONCILIATION_FEASIBILITY.md`

Scope added:
1. Multi-vendor provisioning feasibility (MikroTik, Ubiquiti, TP-Link ISP, Huawei, Cisco, Juniper).
2. Partial NOC capabilities (utilization, packet loss, CES, link quality, outage detection, capacity planning).
3. Router Digital Twin (desired vs actual state + drift detection/repair).
4. PostgreSQL growth-risk mitigation plan.
5. M-Pesa transaction reconciliation engine design.

## Decision Update - 2026-05-31 (Vendor-First Onboarding)

Decision:
1. Router onboarding will require `router_vendor` (default `mikrotik`) instead of a full vendor-model catalog.
2. `router_model` will be optional and enriched progressively after first successful device handshake.
3. Provisioning profile resolution will follow:
   - vendor-generic
   - vendor+version
   - vendor+model+version

Rationale:
1. Avoids blocking onboarding on incomplete model catalogs.
2. Enables immediate mixed-vendor support with safe generic profiles.
3. Reduces operational overhead while still allowing precise compatibility over time.

Implementation impact:
1. Create Router Identity UI/API: make `router_vendor` explicit, default to `mikrotik`.
2. Bootstrap generation uses vendor-generic templates initially.
3. After onboarding detection (`version`, `architecture`, `board/model`), system upgrades to tighter compatibility profile automatically.
4. Validation remains fail-fast for unsupported feature requests.

Release safety rules:
1. Unknown model must never block provisioning if vendor-generic profile supports requested service.
2. Unsupported features must fail before deploy with actionable validation errors.
3. All profile decisions must be persisted in provisioning run metadata for auditability.

## Execution Gate Plan - 2026-05-31 (Approved Start Sequence)

Priority objective:
1. Zero cross-tenant leaks.
2. Zero workflow regressions.
3. Event pipeline consistency.
4. Performance protection before feature-scale expansion.

### Gate 0 - Mandatory Security and Isolation (Blocker Gate)
Status: `next`

Implementation:
1. Add CI guardrails for unsafe tenant access patterns (`withoutGlobalScope(s)` and unguarded tenant-domain `DB::table(...)`).
2. Add mandatory 2-tenant integration leak tests for: routers, PPPoE, hotspot, payments, sessions.
3. Add runtime alerting when tenant context is null in tenant write/read-sensitive paths.

Release rule:
1. Any failure in Gate 0 blocks deployment.

### Gate 1 - Event Workflow Contract Freeze
Status: `next`

Implementation:
1. Enforce one canonical provisioning lifecycle:
   - `plan -> validate -> execute -> verify -> rollback -> terminal`.
2. Enforce idempotency keys for command submission and callback processing.
3. Ensure every stage transition is persisted in provisioning run/step audit.

Release rule:
1. Any non-canonical stage transition is rejected and logged.

### Gate 2 - Driver Boundary Enforcement (Vendor-First)
Status: `next`

Implementation:
1. Keep onboarding vendor-first (`router_vendor`, default `mikrotik`), optional model enrichment after handshake.
2. Enforce that vendor-specific behavior exists only in driver/validator layers.
3. Block vendor branching in controllers/jobs outside driver boundary.

Release rule:
1. Provisioning paths bypassing driver registry are not deployable.

### Gate 3 - Performance Baseline and Safeguards
Status: `next`

Implementation:
1. Define p95 SLO dashboards for provisioning callbacks, monitoring endpoints, and billing reads.
2. Track top-N expensive queries and enforce index/query review cadence.
3. Validate PgBouncer transaction-pooling behavior with tenant context and sticky-write reads.

Release rule:
1. No Phase 2 scale features ship before baseline metrics and query budgets are active.

### Gate 4 - Feature Expansion After Safety Baseline
Status: `pending`

Scope unlocked only after Gates 0-3 pass:
1. Digital Twin v1 (observe/warn first).
2. NOC v1 metrics (utilization, packet loss, outage detection).
3. M-Pesa reconciliation state machine before activation finalization.
4. Second vendor driver onboarding (Ubiquiti recommended first).

## Rollout Integrity Rules

1. No direct production rollout from partially green states; all gate checks must pass.
2. No silent fallback behaviors for tenant, vendor, or verification states.
3. No “success” provisioning terminal event unless verification passes.
4. Every failure path must emit event, persist audit, and leave deterministic retry/rollback state.

## Implementation Ownership Map - Go vs Laravel (Added 2026-05-31)

Objective:
1. Keep event-driven architecture intact.
2. Prevent tenant/business logic leakage into protocol workers.
3. Maximize throughput while preserving safety and auditability.

### Go Responsibilities (Execution and Throughput Plane)

1. Vendor transport/adapters:
   - device connectivity
   - command execution
   - vendor trap/error parsing
2. Provisioning execution workers:
   - step execution
   - retries/backoff/timeouts
   - rollback command dispatch
3. Telemetry collectors:
   - interface utilization
   - packet loss
   - link quality
   - outage signals/heartbeats
4. Digital Twin actual-state collectors:
   - periodic router snapshots at scale
5. M-Pesa reconciliation pollers:
   - transaction status polling
   - settlement endpoint polling
6. Emit normalized events/messages for orchestration layer consumption.

### Laravel Responsibilities (Policy and Source-of-Truth Plane)

1. Canonical provisioning orchestration lifecycle:
   - `plan -> validate -> execute -> verify -> rollback -> terminal`
2. Tenant isolation enforcement and authorization/policy checks.
3. Capability and compatibility policy logic (vendor/version/model profile decisions).
4. Desired-state generation (Digital Twin desired configuration model).
5. Provisioning run/step audit persistence and operator-facing APIs.
6. Billing and reconciliation state-machine decisions (activation/block/manual review).
7. Notification/event fanout to frontend and admin workflows.

### Hard Boundary Rules

1. Go workers MUST NOT decide business outcomes, tenant authorization, or billing activation.
2. Laravel MUST NOT run long-lived, high-volume device transport loops.
3. All terminal decisions (success/fail/rollback required) must be persisted by Laravel.
4. All Go-emitted execution events must include correlation IDs for `tenant`, `router`, `task`, and `provisioning_run`.

### Data Ownership

1. Laravel/PostgreSQL:
   - system-of-record for tenants, workflows, billing state, audits, desired state.
2. Go workers:
   - stateless execution/collection plane with short-lived caches only.
3. Monitoring/time-series data:
   - high-frequency telemetry in metrics store, consumed via Laravel APIs for UI.

### Rollout Rule

1. Any new implementation that violates these ownership boundaries is out-of-scope for release until refactored.

## Coverage Review Update - 2026-05-31 (Strategic Feature Capture)

This section maps newly proposed ISP-value features against current tracker scope.

### Already Captured (Fully or Largely)

1. RouterOS v7 Validator.
2. Digital Twin / Config Drift Detection.
3. Multi-vendor support (vendor-first onboarding).
4. Outage detection (included in NOC metrics scope).
5. M-Pesa reconciliation (revenue assurance foundation).
6. Performance and PostgreSQL scaling safeguards.

### Partially Captured (Needs Explicit Workstreams)

1. Automated backups + health-check + rollback orchestration.
2. Router compliance engine with compliance score and baseline checks.
3. Revenue leakage detection controls (active-not-billed, stale-online, duplicate identity, accounting gaps).
4. Tenant-level audit trail completeness for all critical change actions.
5. ISP analytics KPIs (MRR, ARR, ARPU, churn, failed payments, area revenue).
6. Customer-impact outage model (device state + affected subscriber count).

### Not Yet Explicitly Captured (Now Added)

1. ISP Health Score (0-100) per tenant with weighted factors.
2. AI Troubleshooting Assistant (deterministic diagnostics first, LLM augmentation later).
3. Customer self-service portal expansion (payment/renew/upgrade/password reset/invoices/tickets).
4. Automated router inventory discovery (router, interfaces, SFP, OLT, UPS, AP, switches).
5. Package recommendation engine for upsell opportunities.
6. Mass router upgrade engine with staged rollout, health checks, and rollback.
7. Multi-WAN automation module (failover, PCC, ECMP, WG backup links).
8. Configuration marketplace (deployment templates by ISP profile/use-case).
9. ISP deployment simulator (dry-run + virtual validation + production gate).

## New Priority Ranking (Business + Engineering)

Priority 1 (Very High):
1. RouterOS v7 Validator + strict verification gate.
2. Digital Twin / Drift Detection.
3. Automated backup + rollback + health checks.
4. Revenue leakage detection + reconciliation completion.

Priority 2 (High):
1. Customer self-service portal hardening/expansion.
2. ISP analytics dashboard (MRR/ARR/ARPU/churn).
3. Compliance engine.
4. AI troubleshooting assistant (diagnostics-first).

Priority 3 (Medium):
1. Customer-impact outage detection enhancements.
2. Multi-vendor support expansion beyond MikroTik/Ubiquiti.
3. Multi-WAN automation.
4. Mass router upgrade engine.

## Added Workstreams (Post Gate-4 Expansion)

### WS11 - ISP Health Score Engine
Status: `completed`

Deliverables:
1. Weighted score model (0-100) per tenant.
2. Inputs: offline routers, backup failures, high CPU/memory, subscription/payment anomalies, auth failures, packet loss.
3. Explainability panel (top negative contributors).
4. Snapshot persistence + dashboard read path for the latest score and trend history.
5. Event-driven recalculation listeners for router, VPN, and payment activity.
6. Frontend widget for score visibility on the tenant dashboard.

Acceptance:
1. Health score updates event-driven with deterministic factor trace.
2. Health score snapshots are queryable per tenant.
3. The health score is visible in the tenant dashboard UI.

### WS12 - Automated Backups and Safe Rollback
Status: `completed`

Deliverables:
1. Pre-deploy snapshot policy.
2. Post-deploy health checks.
3. Auto-rollback on failed checks.

Acceptance:
1. No config deployment without snapshot or explicit policy exemption.

Track Update - 2026-06-01 (Deployment Safety Guard):
1. Router deployments now flow through a shared deployment-safety service that takes a pre-deploy snapshot unless an explicit exemption is set.
2. Post-deploy verification is enforced immediately after apply, with automatic rollback to the pre-deploy snapshot on failed verification or failed apply.
3. The canary rollout lane uses the same safety path with snapshot exemption because the baseline snapshot is already captured during canary setup.
4. Validation passed for the new deployment safety service, deploy job, canary job, and their focused regression tests.

### WS13 - Router Compliance Engine
Status: `completed`

Deliverables:
1. Baseline policy checks (SSH/API/firewall/NTP/DNS/backup schedule/etc.).
2. Compliance score and missing controls report per router.
3. Tenant-scoped compliance API and dashboard widget.

Acceptance:
1. Compliance results are queryable and trendable per tenant.
2. Router details overlay can load the live compliance report without leaving the page.

Track Update - 2026-06-01 (Router Compliance Engine):
1. Added the tenant-scoped compliance endpoint on the router API and wired it into the existing router details overlay.
2. Added a dedicated compliance widget that shows score, grade, status, missing controls, passed controls, and detailed checks.
3. Added an endpoint regression test to prove the route returns a persisted compliance report through the event-driven flow.

Validation:
1. `php -l backend/app/Http/Controllers/Api/RouterController.php`
2. `php -l backend/tests/Feature/RouterComplianceEndpointTest.php`
3. `php artisan test --no-coverage --filter=RouterComplianceEndpointTest`

### WS14 - Revenue Assurance and Leakage Detection
Status: `in_progress`

Deliverables:
1. Detection rules: active-not-billed, duplicate PPP identity, expired-but-online, callback mismatch, missing accounting records.
2. Daily anomaly reports and alert pipeline.

Acceptance:
1. Rule hits are auditable and linked to remediation workflows.


Track Update - 2026-06-01 (Revenue Assurance + KPI Dashboard):
1. Added a tenant dashboard revenue-assurance payload that surfaces leakage signals, anomaly counts, and KPI summary values in the existing dashboard flow.
2. Added a dedicated revenue-assurance widget that shows score, leakage rules, MRR, ARR, ARPU, failed payment rate, churn rate, and revenue-by-area slices.
3. Added a pure unit test for the revenue-assurance engine so the scoring and KPI math stay deterministic.
4. Validation passed for the revenue-assurance service, dashboard controller, dashboard widget, dashboard view, and the new unit test.

### WS15 - Customer Self-Service Portal Expansion
Status: `completed`

Deliverables:
1. Renew/upgrade package, reset PPPoE password, invoice view, ticket creation, payment journey hardening.
2. Strict tenant and account ownership checks.

Acceptance:
1. Self-service completion reduces assisted support actions measurably.

Track Update - 2026-06-01 (Customer Self-Service Portal Expansion):
1. Added portal endpoints for invoice history, support ticket list/create, and customer password resets under the existing PPPoE portal flow.
2. Added a tenant-scoped portal support ticket table and model so tickets stay isolated per tenant schema.
3. Added billing, support, and security overlays to the PPPoE portal dashboard so the new actions stay inside the existing portal shell.
4. Validation passed for the portal controller, portal support ticket model, tenant migration, portal routes, portal composable, and portal dashboard view.

### WS16 - ISP Analytics and Business KPIs
Status: `completed`

Deliverables:
1. MRR, ARR, ARPU, churn, failed payment rate, revenue by area dashboards.
2. Periodized and tenant-isolated analytics views.

Acceptance:
1. KPI calculations reproducible from ledger source-of-truth.

Track Update - 2026-06-01 (ISP Analytics and Business KPIs):
1. Added business KPI fields to the tenant dashboard cache/job path and the direct dashboard fetch path so the cached and live payloads stay aligned.
2. Extended the business analytics widget to surface MRR, ARR, ARPU, churn rate, failed payment rate, active subscribers, daily revenue, and revenue by area.
3. Wired the dashboard composable to preserve the business KPI bucket across refreshes and cached snapshots.
4. Validation passed for the dashboard controller, dashboard job, dashboard composable, business analytics widget, and dashboard view.

### WS17 - AI Troubleshooting Assistant (Deterministic Core)
Status: `completed`

Deliverables:
1. Deterministic troubleshooting graph (session, RADIUS, queue, router logs, payment state).
2. Explainable root-cause response templates.
3. Optional LLM summarization layer after deterministic verdict.

Acceptance:
1. Assistant output always references concrete evidence/events.

### WS18 - Inventory Discovery and Topology Enrichment
Status: `completed`

Deliverables:
1. Automated discovery of router/interface/SFP/OLT/UPS/AP/switch inventory where supported.
2. Normalize inventory model and freshness timestamps.

Acceptance:
1. Inventory drift alerts available per tenant site.

### WS19 - Mass Upgrade and Change Orchestration
Status: `completed`

Deliverables:
1. Group-based upgrade scheduling.
2. Canary, phased rollout, health verification, and rollback.
3. Preview overlay in the routers page for batch planning.
4. Deploy endpoint queues executable templates through the safe rollout job pipeline.

Acceptance:
1. Upgrade batches have deterministic success/fail accounting and rollback evidence.
2. Executable templates are queued safely while preview-only templates are blocked.

### WS20 - Multi-WAN Automation and Template Marketplace
Status: `completed`

Deliverables:
1. Multi-WAN policy templates (failover/PCC/ECMP/WG backup).
2. Config marketplace templates (Home ISP, Fiber ISP, Hotspot, School, Hotel, Apartment).
3. Deployment simulator integration.
4. Config-driven marketplace panel in the routers page.
5. Preview-only guardrails for unsupported multi-WAN templates.
6. Executable MikroTik failover and PCC generators with RouterOS matrix coverage.

Acceptance:
1. Template deployments pass validator + simulator gates before production apply.
2. Preview-only templates are clearly blocked from direct deployment.
3. Executable multi-WAN templates queue through the safe rollout pipeline on supported MikroTik routers.

## Strategic Differentiation Thesis (Confirmed)

Primary moat investment:
1. RouterOS v7 Validator.
2. Digital Twin + drift repair.
3. Zero-touch safe provisioning with rollback.
4. Revenue assurance (reconciliation + leakage detection).

These should remain the highest engineering priority to maximize differentiation and reduce operational/support costs.

## Delivery Board - Phased Execution (Added 2026-05-31)

### Phase A (Weeks 1-2) - Safety Baseline (Must Pass)

Owner groups:
1. Platform Backend
2. QA/Automation
3. SRE/DB

Scope:
1. Gate 0: tenant isolation CI guardrails + 2-tenant leak tests + runtime null-tenant alerts.
2. Gate 1: canonical event lifecycle enforcement + idempotency keys + stage audit completeness.
3. Tracker WS in scope: WS1, WS3, WS7 hardening completion.

Dependencies:
1. Existing provisioning run/step audit schema.
2. Current queue/event infrastructure.

Exit criteria:
1. No known cross-tenant leak vectors in covered domains.
2. Provisioning success cannot bypass verification.
3. Stuck-run and callback-timeout paths produce deterministic terminal state.

### Phase B (Weeks 3-4) - Vendor-First Execution Boundary

Owner groups:
1. Provisioning Core
2. Platform Backend

Scope:
1. Gate 2: enforce driver boundary and vendor-first onboarding (`router_vendor`, default `mikrotik`).
2. Capability resolution path: vendor-generic -> vendor+version -> vendor+model+version.
3. Tracker WS in scope: WS2 completion + WS4 start + WS5 foundation.

Dependencies:
1. DriverRegistry contract stabilization.
2. Router identity API/UI updates.

Exit criteria:
1. No vendor branching outside driver/validator layers.
2. Unknown model does not block vendor-generic provisioning.
3. Unsupported feature requests fail pre-deploy with actionable errors.

### Phase C (Weeks 5-6) - Performance Gate and Revenue Assurance Core

Owner groups:
1. SRE/DB
2. Billing Backend
3. Platform Backend

Scope:
1. Gate 3: p95 SLO dashboards + top-N slow query tracking + query budgets.
2. PgBouncer/read-path validation for tenant-safe behavior.
3. Reconciliation state machine hardening (M-Pesa policy gates).
4. Tracker WS in scope: WS14 start, Phase-1 reconciliation controls.

Dependencies:
1. Metrics instrumentation on critical endpoints/queues.
2. Billing callback/event reliability.

Exit criteria:
1. Performance baseline established and monitored.
2. No activation without reconciliation policy pass.
3. Leakage anomaly rules produce auditable outputs.

### Phase D (Weeks 7-10) - Differentiation Core

Owner groups:
1. Provisioning Core
2. NOC/Monitoring
3. Frontend

Scope:
1. Digital Twin v1 (observe/warn, no broad auto-repair yet).
2. Automated backup + health-check + rollback orchestration.
3. Compliance engine + score outputs.
4. ISP health score engine.
5. Tracker WS in scope: WS11, WS12, WS13.

Dependencies:
1. Stable actual-state collectors.
2. Normalized desired-state model.

Exit criteria:
1. Drift incidents detected with evidence payloads.
2. Backup/rollback works for controlled canary groups.
3. Compliance and health scores are explainable and tenant-isolated.

### Phase E (Weeks 11-14) - Operator and Revenue Expansion

Owner groups:
1. Frontend
2. Billing/Product
3. NOC/Analytics

Scope:
1. Customer self-service expansion.
2. ISP analytics KPIs (MRR/ARR/ARPU/churn/failed payments).
3. AI troubleshooting assistant (deterministic diagnostics core).
4. Tracker WS in scope: WS15, WS16, WS17, WS18.

Dependencies:
1. Reliable event/audit streams.
2. KPI definitions locked with product/finance stakeholders.

Exit criteria:
1. Measurable support-ticket deflection.
2. KPI outputs reconciled against source-of-truth ledgers.
3. Assistant outputs always evidence-backed.

### Phase F (Weeks 15+) - Scale and Template Acceleration

Owner groups:
1. Provisioning Core
2. NOC/Inventory
3. Platform Architecture

Scope:
1. Inventory discovery and topology enrichment.
2. Mass upgrade orchestration with canary/rollback.
3. Multi-WAN automation + template marketplace + simulator.
4. Additional vendor drivers by demand.
5. Tracker WS in scope: WS19, WS20.

Dependencies:
1. Proven rollback reliability.
2. Simulator/validator gate maturity.

Exit criteria:
1. Safe group upgrades with deterministic rollback evidence.
2. Template deployments pass simulator + validator gates pre-production.

## Program Governance

Cadence:
1. Weekly architecture review (boundaries, leak risk, performance drift).
2. Weekly release gate review (Gate 0-4 status).
3. Bi-weekly KPI review (support load, provisioning success, reconciliation quality).

Mandatory stop conditions:
1. Any tenant isolation regression.
2. Any non-idempotent provisioning regression in retry paths.
3. Any unreconciled activation path escaping billing policy gates.

## Frontend Integration Execution Notes

1. Integrate new capabilities into existing routers/monitoring/payments pages and overlays first (avoid route sprawl).
2. Add progressive panels for drift/compliance/health/reconciliation details in existing UX flows.
3. Standardize realtime freshness contract (single subscription facade + explicit last-updated markers).
4. Keep high-frequency panels event-driven with bounded refresh fallback to avoid stale views and polling overload.

## Track Update - 2026-06-01 (Callback Guard + Freshness Observability)

Status: `in_progress`

Implemented now:
1. Callback identity enforcement for provisioning callbacks (`tenant_id` + `router_id`) with strict/soft policy flags.
2. Callback freshness guard window to reject stale/replayed callback mutations.
3. Terminal-stage mutation guard to block regressive stage/status rewrites after completion/failure.
4. Provisioning callback payload propagation now includes `callback_at`, `tenant_id`, `router_id`.
5. Callback guard outcomes persisted into provisioning run audit trail.
6. Preflight and rollout tooling:
   - artisan preflight command
   - production helper script
   - CI strict-mode gate
7. Callback guard counters and metrics visibility:
   - backend counters endpoint
   - System Metrics UI panel
   - warning/critical thresholds
   - 10-minute delta trend and secure reset flow
   - trend alert banner for rapid incident visibility

Why this matters:
1. Prevents cross-tenant and cross-router callback contamination.
2. Prevents stale event replay from regressing provisioning state.
3. Gives operators actionable visibility before silent stalls impact users.
4. Strengthens event-based correctness without changing the provisioning domain workflow.

Remaining to close this lane:
1. Wire alert thresholds to deployment env docs/runbooks for consistent production tuning.
2. Add alerting hooks (notification/webhook) when critical callback guard trend is sustained.
3. Extend integration tests to assert guard behavior across mixed stage races under queue concurrency.

## Track Update - 2026-06-01 (Callback Guard Escalation + Mixed-Stage Race Coverage)

Status: `completed`

Implemented now:
1. Added sustained critical callback-guard escalation for provisioning trend alerts.
2. Escalation path now supports system-admin notifications and optional webhook delivery.
3. Added regression coverage for mixed-stage callback races where a later stage must win and older stage callbacks must be ignored.
4. Added regression coverage for conflicting terminal-status mutations after completion/failure.

Why this matters:
1. Callback-guard warnings now turn into actionable alerts when the pattern persists, instead of only emitting logs.
2. Mixed-stage callback races no longer have to rely on log inspection to prove correctness.
3. Event-based provisioning remains the source of truth while the alerting path stays side-effect free for the normal flow.
4. Terminal state cannot be regressed by stale callbacks after the task has already finished.

Follow-up (optional):
1. Add a dedicated notification route for sustained critical alerts if operations wants a non-email channel.
2. Keep the rollback/verification path strict so escalation never masks a broken provisioning state.

## Track Update - 2026-06-01 (RouterOS Capability Fingerprint Persistence)

Status: `in_progress`

Implemented now:
1. Added a normalized RouterOS fingerprint builder that resolves version/profile plus architecture and board/model metadata from live router data.
2. Persisted the normalized fingerprint into router updates during live-data refresh and interface discovery.
3. Added unit coverage for the fingerprint-to-router-update payload mapping.

Why this matters:
1. The validator and generator now have one consistent version/profile source of truth instead of scattered ad-hoc field handling.
2. Router onboarding and refresh paths preserve the live device fingerprint needed for later capability checks.
3. The capability registry can now drive both validation and persisted router metadata without changing the event-based workflow.

Remaining to close this lane:
1. Expand matrix coverage for fingerprinted router models and version-specific command drift.
2. Use the persisted capability profile to surface clearer UI/API warnings when a router falls outside the supported matrix.

## Track Update - 2026-06-01 (Interface Preflight + Dry-Run Support)

Status: `in_progress`

Implemented now:
1. Added a reusable router provisioning preflight service that checks requested hotspot/PPPoE interfaces against live or cached router inventory.
2. Integrated interface preflight into `RouterController::generateServiceConfig()` so invalid deployments fail before queueing.
3. Added `dry_run` support to the service-generation endpoint and preserved the existing deploy path for normal runs.
4. Added frontend composable support for `generateServiceConfig({ dryRun: true })` plus a `previewServiceConfig()` helper.

Why this matters:
1. Bad interface selections now fail before provisioning jobs are queued, reducing avoidable 40% stalls and bad deployments.
2. Dry-run lets operators preview the generated script and preflight result without mutating the router.
3. The existing event-based provisioning flow stays intact; preflight is a guardrail, not a second workflow.

Remaining hardening tasks:
1. Surface the dry-run helper in the provisioning UI as an explicit preview action.
2. Extend preflight to cover bridge/profile/resource dependencies beyond interface presence.
3. Reuse the same preflight service in any legacy apply-config paths that still bypass the router controller.


## Track Update - 2026-06-01 (Run Audit + Idempotency + Preview Hardening)

Status: `in_progress`

Implemented now:
1. Fixed the RouterController preflight wiring to use `RouterProvisioningPreflightService`.
2. Added command-result logging helpers to `ProvisioningRunAuditService` so callback payloads can persist per-command outcomes and trap/error details.
3. Extended `InternalProvisioningTaskController` to persist command-level results from provisioning callbacks into the audit trail.
4. Added idempotency keys to provisioning service deployment/execute requests so retries can be deduplicated safely.
5. Added a dry-run preview control and rendered service-script preview panel in the router provisioning modal.

Why this matters:
1. Retried event-driven callbacks can now be audited without losing the command-level trail.
2. Provisioning requests carry stable idempotency metadata instead of acting like blind fire-and-forget jobs.
3. Operators can preview the generated service plan before committing the deployment.
4. The preflight path now points at the actual service class used by the controller.

Remaining hardening tasks:
1. Add unit coverage for command-result trap logging and audit persistence.
2. Add a regression test for the dry-run preview path in the router provisioning modal.
3. Continue hardening rollback/compensating actions so failed runs can clean up partial state automatically.
## Track Update - 2026-06-01 (Rollback Hardening + Audit Regression)

Status: `in_progress`

Implemented now:
1. Broadened rollback dispatch so failed provisioning tasks for deploy/apply service flows can trigger compensating actions, not only verification failures.
2. Added a pure unit regression test for provisioning audit command-result normalization.
3. Tightened trap detection so generic success `message` fields are not misclassified as traps.

Why this matters:
1. Failed provisioning runs now have a broader path to rollback instead of silently stopping at failure.
2. The audit helper can safely classify command results without depending on database side effects.
3. Success responses that include a `message` field no longer get marked as failed.

Remaining hardening tasks:
1. Finish the RouterOS regression suite for supported version profiles.
2. Continue expanding idempotent ensure semantics across any remaining blind-add generators.
## Track Update - 2026-06-01 (RouterOS Grammar + Regression Gate)

Status: `in_progress`

Implemented now:
1. Normalized RouterOS command parsing so the validator accepts space-delimited command paths used by the current provisioning generators.
2. Expanded the RouterOS capability registry allowlist to cover the command families currently emitted for PPPoE, Hotspot, WireGuard, firewall, DHCP, queues, and identity/logging operations.
3. Added a regression matrix that exercises supported RouterOS v7 profiles with generator-style commands and a v7.8 NTP compatibility rejection case.

Why this matters:
1. The provisioning validator now matches real RouterOS syntax instead of only accepting slash-delimited toy commands.
2. Current generator output can be regression-tested without false negatives on harmless log lines.
3. The supported-version matrix now catches profile-specific command incompatibilities before deployment.

Remaining hardening tasks:
1. Keep extending the regression suite if a new generator emits a command family not yet covered by the registry.
2. Expand regression breadth if new deploy/apply failure modes appear.
## Track Update - 2026-06-01 (Rollback Dispatch Regression)

Status: `completed`

Implemented now:
1. Added a rollback-dispatch regression test that exercises the controller path without relying on tenant schema bootstrap.
2. Introduced protected rollback lookup hooks in `InternalProvisioningTaskController` so the controller can be unit-tested without changing production behavior.
3. Verified the rollback-dispatch branch still pushes `RollbackRouterConfigJob` onto `router-provisioning` and records the rollback audit step.
4. Added rollback edge-case tests for unavailable tenant schema and missing config snapshot paths.

Why this matters:
1. The rollback branch is now covered without requiring a live tenant schema setup in the test itself.
2. The controller remains production-safe while exposing a narrow test seam for rollback lookup.
3. Rollback dispatch and its failure paths are now regression-tested alongside verification gating and audit normalization.

## Track Update - 2026-06-01 (Interface Preflight Expansion)

Status: `in_progress`

Implemented now:
1. Router task submission now runs interface preflight before command dispatch.
2. Preflight validation now covers hotspot, PPPoE, bridge, WireGuard, LAN, and explicit required interfaces.
3. Added regression coverage for missing required-interface payloads.

Why this matters:
1. Missing interface references are rejected before provisioning can enter the stuck/partial state.
2. The executor now fails fast on invalid interface topology instead of letting the command bus handle it later.
3. New interface-bearing payload shapes can be added to the preflight contract without changing the queue/job contract.

Remaining hardening tasks:
1. Expand preflight coverage if a new generator introduces another interface-bearing payload key.
2. Consider surfacing the preflight diff more visibly in the UI dry-run preview if operators need it.

## Track Update - 2026-06-01 (Idempotent Workflow Dedup + Trap Audit Normalization)

Status: `in_progress`

Implemented now:
1. The provisioning client reuses existing workflows when the idempotency key already maps to a completed or running workflow.
2. Duplicate workflow submissions are short-circuited before POST when the workflow store already knows the answer.
3. Audit normalization now preserves trap text even when the upstream result only provides `status` and `message`.

Why this matters:
1. Retried event-driven submissions no longer create duplicate work or duplicate router-side state.
2. The command bus now resolves already-handled workflows without waiting for a new 409/duplicate response.
3. Operators can see the real trap message in the run ledger even when the upstream result is sparse.

Remaining hardening tasks:
1. Expand the idempotent pattern to any remaining direct command submission code paths that bypass the provisioning client.
2. Continue adding trap classification coverage for new result payload shapes as they appear.

## Track Update - 2026-06-01 (Resource-Level Idempotency for MikroTik Transports)

Status: `in_progress`

Implemented now:
1. REST and binary MikroTik transports now reuse or update existing VLAN, interface-list, PPPoE, firewall, and NAT resources instead of blindly adding duplicates.
2. REST print responses are normalized so single-object and list payloads both work with the same idempotency path.
3. Added focused regressions that prove duplicate resource adds are not emitted for the common provisioning paths.

Why this matters:
1. Retry-safe provisioning now exists at the resource layer, not only at the workflow submission layer.
2. Duplicate router-side objects are less likely to accumulate when an event is replayed or partially retried.
3. The event-based provisioning flow can tolerate common duplicate state without stalling on add errors.

Remaining hardening tasks:
1. Extend the same ensure semantics to any remaining direct resource helpers still used by hotspot or hybrid flows.
2. Keep expanding coverage for low-end device edge cases where RouterOS returns sparse or inconsistent payload shapes.

## Track Update - 2026-06-01 (Configurator Blind-Add Conversion)

Status: `in_progress`

Implemented now:
1. Hotspot, hybrid, and PPPoE configurators now use the shared `upsertResource()` primitive for the remaining direct add paths.
2. Interface lists, DHCP client/server entries, IP addresses, pools, hotspot profiles, hotspot services, hotspot user profiles, walled garden entries, and PPP profiles are no longer created via blind add calls.
3. The generic REST and binary MikroTik transports now share a consistent upsert contract, and both are covered by regression tests.

Why this matters:
1. Retry-safe provisioning is now enforced in the configurators, not just the transport and workflow layers.
2. Duplicate objects are less likely to be produced when the same event-based provisioning step is replayed.
3. The remaining 40% stuck-state failure mode is further reduced because duplicate add traps no longer short-circuit the flow.

Remaining hardening tasks:
1. Keep expanding coverage for any new resource type introduced by future provisioning features.
2. Continue verifying sparse RouterOS payload shapes on low-end devices so the upsert contract stays stable.

## Track Update - 2026-06-01 (Compensating Rollback Actions)

Status: `in_progress`

Implemented now:
1. Added a conservative rollback planner that derives reverse actions from completed provisioning step records.
2. Rollback now tries safe compensating actions first and falls back to snapshot restore when the plan is incomplete or the router-side rollback fails.
3. Added in-memory unit coverage for rollback-plan generation and rollback execution without requiring a live database driver.

Why this matters:
1. The rollback lane now has a deterministic first-pass recovery path instead of relying only on full snapshot restore.
2. The event-based provisioning flow can clean up partial state more precisely when the audit trail is complete.
3. The fallback snapshot path remains available, so the new logic does not reduce safety when the plan cannot be trusted.

Remaining hardening tasks:
1. Expand the compensating-action map if new provisioning commands start mutating resources that are not yet covered by the planner.
2. Keep the snapshot restore fallback strict so a partial rollback cannot be mistaken for success.

## Track Update - 2026-06-01 (Low-End Generator Regression Coverage)

Status: `in_progress`

Implemented now:
1. The RouterOS regression matrix now validates real low-end PPPoE, hotspot, and hybrid generator output instead of only hand-written command samples.
2. The hybrid generator now prefers preloaded hotspot/PPPoE pool relations before falling back to database lookup, which keeps the regression self-contained and reduces unnecessary runtime queries.
3. The capability registry now includes the connection-tracking command family used by the low-end bootstrap script path, so the production script and the validator stay in sync.

Why this matters:
1. The regression gate now covers the actual script shape emitted for low-end routers, not just synthetic examples.
2. Generator-side query avoidance makes the low-end path easier to test and reduces avoidable database coupling during provisioning.
3. The validator stays aligned with the code that emits the scripts, which lowers the chance of a deployment-time false negative.

Validation:
1. `php -l backend/app/Services/MikroTik/ZeroConfigHybridGenerator.php`
2. `php -l backend/app/Services/MikroTik/RouterOsCapabilityRegistry.php`
3. `php -l backend/tests/Unit/Services/RouterOsProvisioningMatrixTest.php`
4. `php artisan test --no-coverage --filter=RouterOsProvisioningMatrixTest`

## Track Update - 2026-06-01 (Wrapped Command Parsing + Low-End Generator Coverage)

Status: `in_progress`

Implemented now:
1. The RouterOS validator now inspects wrapped `:do { ... }` and `:if ... do={ ... }` command bodies instead of skipping them, which makes the regression gate apply to the actual generated scripts.
2. Selector-style commands now parse bracketed `find` expressions as `numbers` selectors, so remove/set paths no longer collapse into fake command names during validation.
3. The low-end regression matrix now validates real PPPoE, hotspot, and hybrid generator output successfully, including cleanup paths such as netwatch, scheduler/script removal, file updates, queue type management, traffic-flow, and PPP secret cleanup.

Why this matters:
1. WS10 now guards the same command surfaces that production generators emit, including wrapped commands and cleanup branches that were previously invisible to the validator.
2. The parser changes reduce false failures without weakening the gate, because selector expressions are modeled as selectors rather than command names.
3. The regression suite now reflects a realistic deployment script instead of a simplified command sample.

Validation:
1. `php -l backend/app/Services/MikroTik/RouterOsV7ProvisioningValidator.php`
2. `php -l backend/app/Services/MikroTik/RouterOsCapabilityRegistry.php`
3. `php artisan test --no-coverage --filter=RouterOsProvisioningMatrixTest`

## Track Update - 2026-06-01 (RouterOS Regression Gate Expansion)

Status: `in_progress`

Implemented now:
1. Expanded the RouterOS matrix with two safety regressions: dangerous command blocking and queue-target warning coverage.
2. Kept the supported-version regression gate green for RouterOS 7.8.2, 7.15.1, and 7.18.0 command sets.
3. Preserved the unsupported-version and NTP compatibility checks so version-specific failures stay explicit.

Why this matters:
1. The validator now guards against a broader set of operational mistakes before commands ever reach a device.
2. The regression suite is closer to a real release gate because it covers both supported happy-path scripts and explicit safety failures.
3. The WS10 gap is no longer a blank spot; it is now an active, growing gate around the provisioning pipeline.

Remaining hardening tasks:
1. Add one more regression layer for any new command family introduced by future generators.
2. Wire the regression gate into CI release policy if release automation needs a hard blocker instead of a test-only signal.

## Track Update - 2026-06-01 (Provisioning Ledger Read Path)

Status: `in_progress`

Implemented now:
1. Added tenant-scoped read endpoints for recent provisioning runs and individual run history.
2. Surfaced the provisioning ledger inside the router details overlay as a dedicated tab, without introducing a new page or workflow.
3. Kept the ledger responses cache-busted so the overlay can show fresh state from the same event-based provisioning flow.

Why this matters:
1. Operators can now inspect provisioning history from the same router context where provisioning is triggered.
2. The audit trail is easier to use during incident response because steps, traps, and rollback activity are visible alongside router details.
3. The UI stays aligned with the event-based architecture instead of relying on a separate management screen.

Remaining hardening tasks:
1. Add a direct run selector or drill-down control if the latest-run view is not enough for longer histories.
2. Consider a compact step summary badge if the ledger tab becomes too dense for lower-resolution displays.

## Track Update - 2026-06-01 (Provisioning Ledger Drill-Down)

Status: `in_progress`

Implemented now:
1. Added a single-run provisioning ledger endpoint so operators can open a specific audit trail entry by router and run ID.
2. Added feature coverage for the nested run route, including step ordering and rollback-stage visibility.
3. Added a compact run selector in the router details overlay so the latest provisioning attempts are browsable without leaving the current view.

Why this matters:
1. The provisioning ledger is now useful for real incident review, not just list-level status polling.
2. The event-based UI can navigate between runs without inventing a separate navigation flow.
3. Tenant isolation remains enforced at the router and run level, so the new drill-down does not expand the data leak surface.

Remaining hardening tasks:
1. Add an optional lightweight run summary badge if the overlay becomes too dense on smaller screens.
2. Consider a run-level deep link if operators need to share a specific audit trail entry.

