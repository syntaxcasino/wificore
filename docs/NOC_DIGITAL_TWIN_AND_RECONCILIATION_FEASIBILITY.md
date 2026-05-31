# NOC, Digital Twin, and Reconciliation Feasibility

Date: 2026-05-31  
Owner: Platform, Provisioning, Billing, Data teams  
Status: Feasibility and implementation proposal

## Executive Summary

This expansion is feasible with the current stack if implemented incrementally and behind strong boundaries:
1. Keep event-driven orchestration as the backbone.
2. Push vendor-specific behavior into drivers.
3. Treat PostgreSQL scaling as a first-class program (not a future patch).
4. Build Digital Twin + validation first, then NOC scoring and financial reconciliation.

## 1) Multi-Vendor Support Feasibility

### Current State
1. Provisioning orchestration is event/queue-based and reusable.
2. A `DriverRegistry` pattern already exists in deployment/rollback flows.
3. Most generation/validation logic is still MikroTik-centric.

### Feasibility by Vendor
1. MikroTik: already primary, hardening in progress.
2. Ubiquiti: high feasibility, best second vendor candidate.
3. TP-Link ISP gear: medium feasibility due to model/API variability.
4. Huawei/Cisco/Juniper: feasible but higher effort; requires robust capability matrix and parser/verification engines.

### Recommended Architecture
1. Standard driver contract per vendor:
   - `detectCapabilities(router)`
   - `buildDesiredState(serviceIntent)`
   - `validatePlan(plan, capabilities)`
   - `applyPlan(plan)`
   - `verifyState(expected)`
   - `rollback(changeSet)`
2. Route all provisioning through `DriverRegistry` only.
3. Keep shared workflow stages vendor-agnostic; isolate command dialects per driver.

## 2) Partial NOC Platform Feasibility

### Metrics Requested
1. Interface utilization.
2. Packet loss.
3. Customer experience score (CES).
4. Link quality.
5. Outage detection.
6. Capacity planning.

### Feasibility
High, with phased ingestion and scoring.

### Data Pipeline (Target)
1. Device telemetry (SNMP/API/syslog/stream) -> ingestion jobs.
2. Normalize to canonical metrics schema.
3. Store short-window hot data for dashboards.
4. Aggregate/roll up for trend and planning.
5. Emit events for anomaly/outage detectors.

### Scoring Model
1. CES inputs: uptime, latency, packet loss, throughput saturation, auth/session failures, payment-block incidents.
2. Link quality inputs: signal/CCQ (where available), retransmits, error/discard rates, jitter.
3. Outage detection: heartbeat + metric silence + threshold anomalies + correlated multi-interface failure.

## 3) Router Digital Twin Feasibility

### Value
1. Detect drift from approved config.
2. Reduce support burden and bad manual edits.
3. Enable one-click safe repair at scale.

### Twin Model
1. Desired state: generated canonical config model (not just raw script text).
2. Actual state: periodic snapshot from router API/CLI.
3. Diff engine: semantic comparison by resource keys and fields.
4. Drift classes:
   - missing resource
   - extra resource
   - changed attribute
   - ordering-sensitive mismatch (where relevant)
5. Policy actions:
   - observe only
   - warn
   - auto-repair allowed list
   - manual approval required

### Initial Drift Scope
1. Firewall filter/nat rules (keyed identifiers/comments).
2. PPP profiles and PPPoE server settings.
3. IP pools and queue policies.
4. WireGuard interface/peers.

## 4) Biggest Technical Risk: PostgreSQL Growth

### Risk Assessment
As tenant count and telemetry volume scale, PostgreSQL is likely the primary bottleneck across:
1. Payments and financial history.
2. Session/accounting records.
3. Router/system logs.
4. Monitoring/time-series records.
5. Notifications and event audit trails.

### Mitigation Program (Start Now)
1. PgBouncer transaction pooling as default for app workloads.
2. Read replicas for heavy read endpoints and analytics.
3. Partition high-volume tables (time + tenant where appropriate).
4. Separate monitoring/time-series storage path from OLTP hot path.
5. Archive strategy with retention classes and queryable cold data.
6. Query budget/observability:
   - p95 query latency SLO
   - top-N expensive query dashboard
   - index lifecycle review cadence

### Suggested Data Placement
1. OLTP core (Postgres): tenant config, users, billing state, provisioning runs.
2. Time-series/metrics store: interface/quality telemetry and high-frequency monitoring.
3. Archive/object storage: historical snapshots/log exports beyond hot retention window.

## 5) M-Pesa Transaction Reconciliation Engine

### Problem
Callback-only flows can miss delayed reversals/settlement mismatches and cause revenue leakage.

### Target Flow
1. STK Push initiated.
2. Callback received.
3. Reconciliation workflow started.
4. Transaction status API verification.
5. Settlement verification and duplicate/reversal checks.
6. Activate service only after reconciliation policy passes.

### Core Controls
1. Idempotency key per payment intent.
2. State machine with explicit terminal states:
   - pending
   - callback_received
   - reconciled_success
   - reconciled_failed
   - reversed
   - timed_out_manual_review
3. Retry policy with backoff and dead-letter handling.
4. Ledger-grade audit trail linking request, callback, reconciliation polls, and final action.

## 6) Recommended Delivery Order

### Phase 1 (Immediate, 2-4 weeks)
1. Complete RouterOS v7 validator + verification + provisioning run audit.
2. Enforce strict tenant context and add cross-tenant leak tests.
3. Baseline Postgres observability and query budgets.

### Phase 2 (4-8 weeks)
1. Digital Twin v1 for MikroTik (desired/actual/diff/warn).
2. NOC metrics v1 (utilization, packet loss, outage detector).
3. M-Pesa reconciliation state machine and audit trail.

### Phase 3 (8-14 weeks)
1. Auto-repair policies for low-risk drift classes.
2. CES/link quality scoring and capacity planning forecasts.
3. Add second vendor driver (Ubiquiti recommended first).

### Phase 4 (14+ weeks)
1. Expand vendor matrix (TP-Link ISP, Huawei/Cisco/Juniper by demand).
2. Full NOC operator workflows and SLO reporting.

## 7) Go/No-Go Criteria

Proceed if:
1. Driver contract is enforced end-to-end.
2. Digital Twin diff accuracy is validated against real router snapshots.
3. Reconciliation engine proves zero duplicate activations in soak tests.
4. Postgres/metrics storage split plan is approved before telemetry scale-up.

Block rollout if:
1. Provisioning still bypasses driver boundaries.
2. Drift detection has high false-positive rates.
3. Payment activation can occur without reconciliation policy pass.

## 8) Business Impact

If delivered correctly, this moves WiFiCore from provisioning automation into a differentiated ISP operations platform:
1. Lower support tickets via pre-deploy validation and drift detection.
2. Higher service reliability via verification and outage intelligence.
3. Lower revenue leakage via reconciliation.
4. Stronger enterprise adoption in mixed-vendor environments.
