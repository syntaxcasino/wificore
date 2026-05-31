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
Status: `in_progress`

Deliverables:
1. Add `provisioning_runs` table.
2. Add `provisioning_steps` table.
3. Persist for each step: command, params, response, trap, duration, stage, success/failure.
4. Correlate with `router_tasks` and router ID.

Acceptance:
1. One provisioning attempt = one run record with step history.
2. Failed steps are queryable by router, tenant, and timestamp.

### WS2 - RouterOS Version/Capability Registry
Status: `in_progress`

Deliverables:
1. Add capability map for RouterOS versions (initially: 7.8, 7.15, 7.18).
2. Normalize version parsing from `/system/resource/print`.
3. Store detected version, architecture, board model during onboarding and refresh.

Acceptance:
1. Generator can select command profile by detected version.
2. Unknown/unsupported versions are blocked with actionable error.

### WS3 - RouterOS v7 Validator (Highest Priority)
Status: `in_progress`

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
Status: `in_progress`

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
Status: `not_started`

Deliverables:
1. Weighted score model (0-100) per tenant.
2. Inputs: offline routers, backup failures, high CPU/memory, subscription/payment anomalies, auth failures, packet loss.
3. Explainability panel (top negative contributors).

Acceptance:
1. Health score updates event-driven with deterministic factor trace.

### WS12 - Automated Backups and Safe Rollback
Status: `not_started`

Deliverables:
1. Pre-deploy snapshot policy.
2. Post-deploy health checks.
3. Auto-rollback on failed checks.

Acceptance:
1. No config deployment without snapshot or explicit policy exemption.

### WS13 - Router Compliance Engine
Status: `not_started`

Deliverables:
1. Baseline policy checks (SSH/API/firewall/NTP/DNS/backup schedule/etc.).
2. Compliance score and missing controls report per router.

Acceptance:
1. Compliance results are queryable and trendable per tenant.

### WS14 - Revenue Assurance and Leakage Detection
Status: `not_started`

Deliverables:
1. Detection rules: active-not-billed, duplicate PPP identity, expired-but-online, callback mismatch, missing accounting records.
2. Daily anomaly reports and alert pipeline.

Acceptance:
1. Rule hits are auditable and linked to remediation workflows.

### WS15 - Customer Self-Service Portal Expansion
Status: `not_started`

Deliverables:
1. Renew/upgrade package, reset PPPoE password, invoice view, ticket creation, payment journey hardening.
2. Strict tenant and account ownership checks.

Acceptance:
1. Self-service completion reduces assisted support actions measurably.

### WS16 - ISP Analytics and Business KPIs
Status: `not_started`

Deliverables:
1. MRR, ARR, ARPU, churn, failed payment rate, revenue by area dashboards.
2. Periodized and tenant-isolated analytics views.

Acceptance:
1. KPI calculations reproducible from ledger source-of-truth.

### WS17 - AI Troubleshooting Assistant (Deterministic Core)
Status: `not_started`

Deliverables:
1. Deterministic troubleshooting graph (session, RADIUS, queue, router logs, payment state).
2. Explainable root-cause response templates.
3. Optional LLM summarization layer after deterministic verdict.

Acceptance:
1. Assistant output always references concrete evidence/events.

### WS18 - Inventory Discovery and Topology Enrichment
Status: `not_started`

Deliverables:
1. Automated discovery of router/interface/SFP/OLT/UPS/AP/switch inventory where supported.
2. Normalize inventory model and freshness timestamps.

Acceptance:
1. Inventory drift alerts available per tenant site.

### WS19 - Mass Upgrade and Change Orchestration
Status: `not_started`

Deliverables:
1. Group-based upgrade scheduling.
2. Canary, phased rollout, health verification, and rollback.

Acceptance:
1. Upgrade batches have deterministic success/fail accounting and rollback evidence.

### WS20 - Multi-WAN Automation and Template Marketplace
Status: `not_started`

Deliverables:
1. Multi-WAN policy templates (failover/PCC/ECMP/WG backup).
2. Config marketplace templates (Home ISP, Fiber ISP, Hotspot, School, Hotel, Apartment).
3. Deployment simulator integration.

Acceptance:
1. Template deployments pass validator + simulator gates before production apply.

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
4. Tracker WS in scope: WS15, WS16, WS17.

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
5. Tracker WS in scope: WS18, WS19, WS20.

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
