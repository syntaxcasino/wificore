# Sequential Microservices Migration Plan

## Goal

Extract the current backend into services in this order:

1. Router Provisioning Service
2. Telemetry / Realtime Service
3. Billing / Payments Service
4. Access / AAA Service
5. Identity / Tenant Admin Service

The objective is not service count. The objective is lower p95 latency, reduced shared failure domains, and clearer operational ownership.

## Current Baseline

The current backend is a modular monolith with role-based runtime splitting:

- `wificore-backend` runs Laravel web/API.
- `wificore-backend-sse` runs the same Laravel image in SSE mode.
- `wificore-scheduler` runs the same Laravel image in scheduler mode.
- `wificore-queue-*` containers run the same Laravel image with different queue groups.
- `wificore-provisioning` already exists as a separate Go service, but it does not yet own the full router lifecycle.

This means deployments, database ownership, and most domain logic are still centralized.

## Principles

- Extract one service at a time.
- Each extracted service must own a narrow API surface and a clear set of asynchronous jobs.
- p95 improvement must be measurable after each phase.
- No service should be extracted until its request path no longer depends on synchronous cross-domain database joins.
- Prefer async command submission plus polling/streaming status over long synchronous HTTP requests.
- Keep auth and tenant resolution centralized initially behind the existing Laravel edge.

## Shared Platform Before Extraction

These stay shared initially:

- edge API gateway and auth
- tenant resolution
- event bus / queue backbone
- PostgreSQL infrastructure
- Redis infrastructure
- observability and tracing
- secrets and key management

## Phase 1: Router Provisioning Service

### Why First

This has the highest p95 leverage because router provisioning and health verification involve slow, failure-prone network IO, retries, and long-running workflows. Those workloads should not sit in the main request path.

### Current State

There is already a Go service in `provisioning-service/` and a Laravel client in `backend/app/Services/ProvisioningServiceClient.php`.

Current Laravel responsibilities still include:

- config generation
- deployment orchestration
- rollback orchestration
- interface discovery
- connectivity verification
- progress broadcasting
- status persistence
- MikroTik binary API and SSH fallback logic

Large coupling points:

- `backend/app/Services/MikrotikProvisioningService.php`
- `backend/app/Jobs/DeployRouterServiceJob.php`
- `backend/app/Jobs/RouterProvisioningJob.php`
- `backend/app/Jobs/DiscoverRouterInterfacesJob.php`
- `backend/app/Http/Controllers/Api/RouterController.php`
- `backend/app/Http/Controllers/Api/ServiceConfigurationController.php`

### Target Ownership

Router Provisioning Service should own:

- router command execution
- config deployment workflow
- rollback workflow
- interface discovery
- connectivity verification
- router bootstrap and hardening execution
- deployment progress events
- deployment status state machine
- router task execution history

Laravel should keep only:

- request authentication
- tenant authorization
- command submission
- read-only status proxy during transition
- UI-facing aggregation

### Required API Surface

Synchronous endpoints:

- `POST /api/v1/router-tasks`
- `GET /api/v1/router-tasks/{taskId}`
- `GET /api/v1/router-tasks/{taskId}/events`
- `POST /api/v1/router-connectivity/check`
- `POST /api/v1/router-interfaces/discover`

Async task types:

- `deploy_service_config`
- `apply_router_configs`
- `rollback_router_config`
- `discover_interfaces`
- `verify_connectivity`
- `bootstrap_router`
- `apply_security_hardening`

### Data Ownership

Initial shared-read model:

- read from existing `routers`, `router_services`, `router_configs`, `config_snapshots`
- write task state to a new service-owned table set

Recommended new tables:

- `router_tasks`
- `router_task_events`
- `router_task_artifacts`
- `router_task_locks`
- `router_connection_checks`

### Migration Steps

1. Define router task contract and idempotency keys.
2. Move Laravel endpoints from sync execution to `202 Accepted` task submission.
3. Move deployment orchestration from `MikrotikProvisioningService` into the Go service.
4. Move interface discovery and connectivity verification into the Go service.
5. Keep Laravel polling `router_tasks` until direct UI polling can hit the service through the gateway.
6. Replace direct provisioning broadcasts with task-event fan-out.

### Success Criteria

- No browser/API request performs direct router deployment work.
- `POST /routers/{router}/apply-configs` and `POST /routers/{router}/deploy-service-config` return `202` with task IDs.
- Router failures do not consume Laravel web workers.
- Deployment p95 for web requests drops to sub-300ms for submit flows.
- Router workflows are traceable by task ID end to end.

### p95 Targets

- submit endpoint p95: `< 300ms`
- provisioning status endpoint p95: `< 150ms`
- queue-to-task-start latency p95: `< 2s`

## Phase 2: Telemetry / Realtime Service

### Why Second

Once provisioning leaves the request path, the next p95 risk is live metrics, polling, stream fan-out, and dashboard reads coupled to active router state.

### Current State

Telemetry concerns are split across:

- `RouterMetricsController`
- `MetricsController`
- `MonitoringController`
- `TenantSseController`
- `SystemAdminSseController`
- `UnifiedStreamController`
- `RouterStatusStreamController`
- queue groups `metrics` and `realtime`
- Telegraf + VictoriaMetrics sidecars
- Soketi/Pusher broadcast flows

### Target Ownership

Telemetry / Realtime Service should own:

- metrics ingestion orchestration
- router polling coordination
- stream fan-out for dashboards
- SSE and WebSocket event publishing
- precomputed dashboard views
- metrics downsampling / aggregation APIs
- router status aggregation

Laravel should keep only:

- auth
- tenant authorization
- thin proxy/gateway routes during transition
- static admin workflows

### Required API Surface

- `GET /api/v1/tenants/{tenantId}/dashboard-snapshot`
- `GET /api/v1/routers/{routerId}/metrics/live`
- `GET /api/v1/routers/{routerId}/status`
- `GET /api/v1/streams/tenant`
- `GET /api/v1/streams/system`
- `POST /api/v1/polling/schedules/reconcile`

### Data Ownership

Service-owned:

- aggregated dashboard projections
- realtime connection state
- router status snapshots
- stream subscriptions / lightweight presence

Continue using VictoriaMetrics as time-series store.

### Migration Steps

1. Isolate dashboard read models from live router calls.
2. Move polling coordination out of Laravel jobs into the telemetry service.
3. Make SSE and WebSocket publication depend on precomputed events, not controller-side queries.
4. Shift metrics endpoints to pre-aggregated views first, then move route ownership.

### Success Criteria

- Dashboard APIs do not call routers directly.
- SSE/WebSocket event fan-out is service-owned.
- Polling cadence is independent of Laravel scheduler throughput.
- Dashboard and live status endpoints remain fast under router instability.

### p95 Targets

- dashboard snapshot p95: `< 200ms`
- live metrics read p95: `< 250ms`
- stream auth p95: `< 100ms`

## Phase 3: Billing / Payments Service

### Why Third

This domain is already relatively cohesive and has clear async workflows. It is a strong extraction candidate after the operationally noisy router and telemetry paths are isolated.

### Current State

Primary code areas:

- `PaymentController`
- `MpesaC2BController`
- `TenantPaybillController`
- `LandlordBillingController`
- `PppoePaymentController`
- `PurchaseController`
- `TenantPaybillService`
- `MpesaService`
- `MpesaC2BService`
- `SaasBillingService`
- reminder and payment jobs

### Target Ownership

Billing / Payments Service should own:

- M-Pesa callbacks and validation
- payment initiation status state machine
- reminders and retries
- receipts and invoices
- subscription billing events
- landlord billing calculations
- reconciliation workflows

### Data Ownership

Strong candidates:

- `payments`
- `tenant_payments`
- `mpesa_transactions`
- `mpesa_transaction_maps`
- `payment_check_logs`
- `payment_reminders`
- `system_payment_settings`
- billing reporting tables

### Success Criteria

- Payment callback handling is fully isolated.
- Payment polling and reconciliation do not compete with router or portal workloads.
- Billing reports and reminders are owned by one service.

### p95 Targets

- payment initiation p95: `< 250ms`
- payment status p95: `< 120ms`
- callback ack p95: `< 100ms`

## Phase 4: Access / AAA Service

### Why Fourth

This is a large service boundary with high domain complexity. It should be split only after the operational services and payment workflows are isolated.

### Current State

Primary code areas:

- `HotspotController`
- `CaptivePortalController`
- `PppoePortalController`
- `PppoeUserController`
- `PppoeSessionController`
- `VoucherController`
- `RadiusService`
- `HotspotRadiusService`
- `PppoeBillingLifecycleService`
- FreeRADIUS-facing integration

### Target Ownership

Access / AAA Service should own:

- hotspot users and sessions
- PPPoE users and sessions
- vouchers
- AAA/accounting synchronization
- access grants and disconnects
- portal login/session logic
- RADIUS-facing credential and accounting workflows

### Data Ownership

Strong candidates:

- `hotspot_users`
- `hotspot_sessions`
- `user_sessions`
- `vouchers`
- `radius_sessions`
- `pppoe_users`
- `pppoe_payments`
- `pppoe_timed_vouchers`
- public and tenant RADIUS tables

### Success Criteria

- Portal login and access state are owned by one service.
- AAA workflows no longer depend on unrelated admin or billing runtime pressure.
- FreeRADIUS integration boundaries are explicit.

### p95 Targets

- portal login p95: `< 200ms`
- session status p95: `< 120ms`
- voucher validation p95: `< 100ms`

## Phase 5: Identity / Tenant Admin Service

### Why Last

This is the governance layer and touches every other domain. Extracting it too early would increase cross-service auth and tenant resolution complexity before the operational wins are realized.

### Current State

Primary code areas:

- `UnifiedAuthController`
- `TenantController`
- `SystemAdminController`
- `SystemUserManagementController`
- `TenantUserManagementController`
- `TenantRegistrationController`
- `PublicTenantController`
- `TenantPaybillController` settings subset

### Target Ownership

Identity / Tenant Admin Service should own:

- tenants
- users
- roles and permissions
- tenant registration and activation
- paybill setting administration
- landlord/admin workflows
- tenant lifecycle state

### Data Ownership

Strong candidates:

- `tenants`
- `users`
- `tenant_registrations`
- `tenant_ip_pools`
- `tenant_paybill_settings`
- admin audit and lifecycle tables

### Success Criteria

- auth and tenant admin workflows are service-owned.
- downstream services rely on signed identity claims and service APIs rather than direct user-table joins.
- tenant lifecycle changes emit events consumed by the other services.

### p95 Targets

- login p95: `< 150ms`
- tenant admin reads p95: `< 150ms`
- tenant mutation command submit p95: `< 250ms`

## Cross-Cutting Work Required Before and During Extraction

### 1. Contract Stabilization

Before each extraction:

- define command/event payloads
- define idempotency rules
- define ownership for retries and dead-letter handling
- define service-level auth between Laravel edge and downstream services

### 2. Data Strategy

Recommended approach:

- keep PostgreSQL shared initially
- move to schema ownership before separate physical databases
- only move to separate databases once the service no longer requires cross-service joins in write paths

### 3. Observability

Must exist before full extraction:

- per-request trace IDs
- per-task trace IDs
- queue lag metrics
- per-service p50, p95, p99 metrics
- retry, timeout, and circuit-breaker metrics

### 4. Edge Pattern

Recommended near-term pattern:

- Laravel remains the edge API and auth gateway
- extracted services sit behind internal service APIs
- UI does not call every service directly during the first extraction waves

## Risks

- schema-based multitenancy makes service extraction harder than a plain `tenant_id` model
- shared Eloquent model access encourages accidental cross-domain coupling
- p95 can get worse if sync orchestration is replaced with service-to-service fan-out
- destructive and environment-coupled e2e scripts make migration validation fragile

## Recommended Immediate Next Work

1. Finish Router Provisioning Service boundaries first.
2. Add task tables and a stable async task API.
3. Convert router deployment endpoints to `202 Accepted` flows.
4. Add non-destructive contract tests for provisioning submit/status/event flows.
5. Only after that, begin Telemetry / Realtime extraction.

## Definition of Done Per Phase

A phase is complete only when:

- request path is no longer executing the extracted domain synchronously
- the extracted service owns its async workflows
- p95 improvement is measurable in production
- service contracts are versioned
- dashboards and alerts exist for the new failure modes
