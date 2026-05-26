# Control Plane / Execution Plane Migration Plan

## Purpose

This document captures:

- the end-to-end findings from the Laravel queue and router execution audit
- the target architecture for splitting Laravel and Go responsibilities
- the concrete migration plan
- the execution tracker for carrying the migration out

This document is intended to be updated as work progresses.

Status legend:

- `[todo]` not started
- `[doing]` in progress
- `[done]` completed
- `[blocked]` waiting on a dependency or decision

---

## Executive Summary

Current direction is correct:

- Laravel should remain the control plane and business brain.
- Go should own the execution plane and high-concurrency fleet operations.
- PostgreSQL should remain the source of truth.
- Redis should remain cache, lock, fanout, and hot-state infrastructure.
- RabbitMQ should become the durable command/event bus between Laravel and Go.
- PgBouncer should continue protecting PostgreSQL from Laravel connection churn.

The current SaaS slowdown is not caused by a single job. It is caused by a combination of:

1. execution-heavy router and VPN workloads still consuming Laravel workers
2. long-running queue jobs that block workers with `sleep()` loops
3. frequent scheduler fanout for tenant and router monitoring
4. queue topology drift, where some queues used in code do not appear to have dedicated workers
5. metrics and monitoring pipelines running in Laravel despite being better suited to Go workers

The immediate conclusion:

- all router provisioning execution is now routed through the Go provisioning service
- Laravel still spends too much worker capacity orchestrating and waiting on execution workloads
- the biggest performance wins will come from moving monitoring, provisioning, VPN verification, and service-control queues to Go workers
- creation/deletion hot paths were also spending avoidable time on repeated tenant schema re-entry and long-running VPN key generation inside the DB transaction; that path has now been shortened in Laravel while the broader Go migration continues

---

## Current Architecture

### Current Effective Split

Laravel currently owns:

- API endpoints
- validation and authorization
- tenant context and schema switching
- billing and subscription workflows
- CRUD and admin operations
- queue dispatch
- router task persistence and status projection
- event broadcasting to the frontend

Go currently owns:

- router SSH execution
- provisioning workflows
- script deployment
- live interface and router data collection
- binary API-first router execution, with SSH fallback for unsupported/script-only operations
- command execution against routers

### Current End-to-End Provisioning Flow

1. UI calls Laravel router or provisioning endpoints.
2. Laravel validates request and writes state into PostgreSQL.
3. Laravel creates `router_tasks` records and dispatches queue jobs.
4. Laravel queue jobs call `ProvisioningServiceClient`.
5. `ProvisioningServiceClient` calls the Go provisioning service over HTTP.
6. Go connects to routers and executes the actual device work.
7. Go reports progress back to Laravel.
8. Laravel updates canonical task and router state.
9. Laravel broadcasts task and router status updates to the UI.

This means provisioning execution is in Go, but orchestration is still in Laravel.

---

## Findings

## Finding 1: Router provisioning execution has been migrated behind Go

The current provisioning execution path is Go-backed.

Primary Laravel execution entry points:

- `app/Http/Controllers/Api/RouterController.php`
- `app/Services/RouterTaskExecutionService.php`
- `app/Services/ProvisioningServiceClient.php`

Primary Go execution endpoints:

- `/api/v1/provision-service`
- `/api/v1/deploy-script`
- `/api/v1/verify`
- `/api/v1/live-data`
- `/api/v1/execute`

Conclusion:

- provisioning execution is no longer the main Laravel bottleneck
- orchestration, polling, monitoring, and long-running jobs remain the bigger issue

## Finding 2: Queue usage and worker coverage appear misaligned

Queues referenced in code include:

- `router-provisioning`
- `router-checks`
- `router-data`
- `router-monitoring`
- `provisioning`
- `service-control`
- `hotspot-provisioning`
- `hotspot-sessions`
- `hotspot-access`
- `hotspot-accounting`
- `subscription-reconnection`
- `metrics`
- `vpn-provisioning`
- `reports`
- `messaging`
- `billing`
- `cache`
- `low`
- others

Queues with clear worker definitions in `supervisor/laravel-queue.conf` include:

- `default`
- `router-checks`
- `router-data`
- `log-rotation`
- `payments`
- `payment-checks`
- `router-provisioning`
- `dashboard`
- `hotspot-sms`
- `hotspot-sessions`
- `hotspot-accounting`
- `notifications`
- `service-control`
- `provisioning`
- `router-monitoring`
- `packages`
- `broadcasts`
- `security`
- `monitoring`
- `emails`
- `tenant-management`
- `user-management`
- `auth-tracking`
- `hotspot-provisioning`

Initial detailed audit showed that several queues were defined later in the Supervisor file than the first pass captured.

Queues that were already covered by existing workers:

- `metrics`
- `vpn-provisioning`
- `subscription-reconnection`
- `reports`

Queues that were genuinely missing dedicated coverage before Phase 0 stabilization:

- `messaging`
- `hotspot-access`
- `saas-enforcement`
- `hotspot-expirations`
- `cache`
- `billing`
- `low`

Phase 0 stabilization resolves those gaps by consolidating low-volume queues into existing workers:

- `default` now covers `default,low,cache`
- `payment-checks` now covers `billing,payment-checks,saas-enforcement,hotspot-expirations`
- `hotspot-sessions` now covers `hotspot-access,hotspot-sessions`
- `notifications` now covers `messaging,notifications`

Risk:

- jobs can remain pending indefinitely if worker coverage drifts again
- operators may perceive the system as slow when the real issue is worker coverage
- retries and queue priorities become misleading

## Finding 3: Some Laravel jobs are structurally too long-lived

Several jobs hold PHP workers for too long.

Examples:

- `app/Jobs/VerifyVpnConnectivityJob.php`
- `app/Jobs/DiscoverRouterInterfacesJob.php`

Symptoms:

- internal retry loops
- repeated `sleep()` usage
- polling within a single job execution
- worker occupancy measured in tens or hundreds of seconds

Why this hurts:

- PHP queue workers are poor execution engines for long-lived fleet polling
- throughput drops sharply as router count grows
- even small fleets can create worker starvation during incidents

## Finding 4: High-frequency scheduler fanout increases constant background pressure

Current scheduler behavior in `routes/console.php` includes:

- `CheckRoutersJob` every minute
- `ComputeRouterMetricsJob` every minute
- `ScheduleRouterPollingJob` every 15 seconds
- `UpdateVpnStatusJob` every 30 seconds
- dashboard updates every 30 seconds for all tenants

This creates:

- repeated fanout by tenant
- repeated fanout by router chunk
- duplicated coordination work in Laravel
- constant pressure on queue workers and database connections

## Additional Finding: Tenant schema switching and VPN transaction scope were adding avoidable overhead

The slow create/delete experience for routers, PPPoE users, and VPN objects was not only a queue issue.

The main avoidable overhead was:

- repeated tenant re-entry causing redundant `search_path` work inside nested tenant-aware calls
- VPN key generation happening while the DB transaction was still open during router/VPN creation

Mitigation applied:

- `TenantContext` now short-circuits redundant tenant re-entry inside an active transaction
- `VpnService` now generates WireGuard keys before the transaction begins, then only uses the transaction for the database writes that actually need it

## Finding 5: Monitoring and metrics pipelines are execution-plane workloads

Current examples:

- `app/Jobs/ScheduleRouterPollingJob.php`
- `app/Jobs/FetchRouterLiveData.php`
- `app/Jobs/ComputeRouterMetricsJob.php`
- `app/Jobs/CheckRoutersJob.php`
- `app/Jobs/UpdateVpnStatusJob.php`
- `app/Jobs/ProcessWireGuardWebhookJob.php`

These jobs do one or more of the following:

- poll routers or VPN state
- perform external IO
- query VictoriaMetrics
- iterate many routers
- perform repeated fanout dispatching
- broadcast high-frequency status updates

These are better moved to Go workers.

## Finding 6: Device control jobs are still consuming Laravel queue capacity

Examples:

- `app/Jobs/DeployRouterServiceJob.php`
- `app/Jobs/RouterProvisioningJob.php`
- `app/Jobs/ExecuteProvisioningServiceRouterTaskJob.php`
- `app/Jobs/ApplyRouterConfigsTaskJob.php`
- `app/Jobs/ProvisionHotspotJob.php`
- `app/Jobs/ProvisionUserInMikroTikJob.php`
- `app/Jobs/DisconnectHotspotUserJob.php`
- `app/Jobs/DisconnectPppoeUserJob.php`
- `app/Jobs/DisconnectPppoeSessionJob.php`

Even when transport is Go-backed, these jobs still:

- occupy Laravel workers
- perform orchestration logic in PHP
- carry retry and lock logic in Laravel
- create scaling pressure on the SaaS control plane

## Finding 7: Some jobs mix business rules with execution rules and should be split

Examples:

- `app/Jobs/CheckPppoePaymentStatusJob.php`
- `app/Jobs/SyncRadiusAccountingJob.php`
- `app/Jobs/ReconnectSubscriptionJob.php`

These jobs combine:

- billing or subscription logic
- router-side enforcement
- direct service-control dispatch

Target:

- Laravel decides what should happen
- Go performs router-side or network-side execution

---

## Target Architecture

## Principle

Use a strict split:

- Laravel = control plane
- Go = execution plane

### Laravel Responsibilities

Laravel should remain authoritative for:

- auth, RBAC, tenancy, billing, CRUD
- validation and request handling
- workflow creation and approvals
- canonical state transitions
- persistence in PostgreSQL
- audit logs
- UI projection and broadcasting
- command issuance
- event consumption and projection

### Go Responsibilities

Go should own:

- router provisioning
- router monitoring
- VPN verification and peer-state refresh
- router live-data collection
- router metrics collection and batching
- hotspot and PPPoE session execution
- service-control execution
- device hardening, SNMP, QoS, SSH key operations
- high-volume and long-lived operational workers

---

## Queue and Messaging Target State

## Laravel Queues to Keep

These should remain Laravel queues:

- `tenant-management`
- `user-management`
- `payments`
- `payment-checks`
- `emails`
- `notifications`
- `auth-tracking`
- `security`
- `dashboard`
- `broadcasts` if still needed for non-device UI events

## Execution Queues to Move Behind Go

These should become RabbitMQ-driven Go worker domains:

- `router-provisioning`
- `router-checks`
- `router-data`
- `router-monitoring`
- `provisioning`
- `service-control`
- `hotspot-provisioning`
- `hotspot-sessions`
- `hotspot-access`
- `metrics`
- `vpn-provisioning`
- execution portion of `subscription-reconnection`

## Transport Strategy

Target messaging model:

- Laravel writes business state and command intent to PostgreSQL
- Laravel publishes commands to RabbitMQ
- Go workers consume commands and execute
- Go emits progress and result events to RabbitMQ
- Laravel consumes events and updates canonical state

Redis should continue to be used for:

- cache
- locks
- rate limiting
- hot status hints
- SSE/WebSocket fanout support

Redis should not be the long-term durable execution bus between Laravel and Go.

---

## Concrete Ownership Plan

## Keep in Laravel

Services:

- `app/Services/TenantContext.php`
- `app/Services/TenantMigrationManager.php`
- `app/Services/TenantSchemaManager.php`
- `app/Services/SaasBillingService.php`
- `app/Services/PaymentConfigService.php`
- `app/Services/MpesaService.php`
- `app/Services/SubscriptionManager.php`
- `app/Services/AuditLogService.php`
- `app/Services/ProvisioningRequestSigner.php`

Controllers:

- all CRUD and business controllers
- `RouterController` as an API façade only
- `ProvisioningController` as an API façade only
- `ServiceConfigurationController` as an API façade only
- `InternalProvisioningTaskController` as a state projector and callback/event intake surface

Jobs:

- tenant lifecycle jobs
- user management jobs
- billing and notification jobs
- report-generation jobs unless they become very CPU-heavy

## Move to Go

Services currently acting as execution logic:

- `app/Services/MikrotikProvisioningService.php`
- `app/Services/MikrotikSshService.php`
- `app/Services/MikrotikSessionService.php`
- `app/Services/RouterStatusCheckService.php`
- `app/Services/RouterTaskExecutionService.php`
- `app/Services/MikroTik/SshExecutor.php`
- `app/Services/MikroTik/QoSManagementService.php`
- `app/Services/MikroTik/RouterHardeningService.php`
- `app/Services/MikroTik/SecurityHardeningService.php`
- `app/Services/MikroTik/SnmpConfigurationService.php`
- `app/Services/MikroTik/SshKeyRotationService.php`
- `app/Services/MikroTik/RscFileCleanupService.php`

Jobs:

- `app/Jobs/DeployRouterServiceJob.php`
- `app/Jobs/RouterProvisioningJob.php`
- `app/Jobs/ExecuteProvisioningServiceRouterTaskJob.php`
- `app/Jobs/ApplyRouterConfigsTaskJob.php`
- `app/Jobs/DiscoverRouterInterfacesJob.php`
- `app/Jobs/VerifyRouterConnectivityTaskJob.php`
- `app/Jobs/CheckRoutersJob.php`
- `app/Jobs/ScheduleRouterPollingJob.php`
- `app/Jobs/FetchRouterLiveData.php`
- `app/Jobs/ComputeRouterMetricsJob.php`
- `app/Jobs/ProcessWireGuardWebhookJob.php`
- `app/Jobs/UpdateVpnStatusJob.php`
- `app/Jobs/VerifyVpnConnectivityJob.php`
- `app/Jobs/ProvisionHotspotJob.php`
- `app/Jobs/ProvisionUserInMikroTikJob.php`
- `app/Jobs/DisconnectHotspotUserJob.php`
- `app/Jobs/DisconnectPppoeUserJob.php`
- `app/Jobs/DisconnectPppoeSessionJob.php`
- `app/Jobs/ReconnectPppoeUserJob.php`
- `app/Jobs/ReconnectUserJob.php`

## Split between Laravel and Go

Jobs needing split responsibilities:

- `app/Jobs/CheckPppoePaymentStatusJob.php`
- `app/Jobs/SyncRadiusAccountingJob.php`
- `app/Jobs/ReconnectSubscriptionJob.php`

Desired split:

- Laravel decides policy and updates business state
- Go performs router-side or network-side changes

---

## Command and Event Contracts

## Commands Laravel should publish

- `router.provision.requested`
- `router.apply_config.requested`
- `router.verify_connectivity.requested`
- `router.discover_interfaces.requested`
- `router.status.refresh.requested`
- `router.live_data.collect.requested`
- `router.metrics.collect.requested`
- `router.session.disconnect.requested`
- `router.session.reconnect.requested`
- `router.hotspot.user.provision.requested`
- `router.pppoe.enforcement.requested`
- `router.qos.apply.requested`
- `router.snmp.configure.requested`
- `router.security.harden.requested`
- `router.ssh_key.rotate.requested`

## Events Go should publish

- `router.task.started`
- `router.task.progress`
- `router.task.succeeded`
- `router.task.failed`
- `router.status.observed`
- `router.live_data.observed`
- `router.metrics.observed`
- `router.interfaces.discovered`
- `router.session.disconnected`
- `router.session.reconnected`
- `router.user.provisioned`
- `router.execution.failed`

## Minimum command envelope

- `command_id`
- `tenant_id`
- `router_id`
- `task_id`
- `type`
- `requested_by`
- `requested_at`
- `payload`
- `idempotency_key`

## Minimum event envelope

- `event_id`
- `command_id`
- `task_id`
- `tenant_id`
- `router_id`
- `type`
- `status`
- `progress`
- `message`
- `payload`
- `occurred_at`

---

## Migration Phases


## Finding 3: Router transport should be binary API first, SSH fallback only

The Go provisioning service still uses SSH directly in `internal/ssh/client.go`. Binary API exists in Laravel and is the better default transport for simple command/query operations.

Conclusion:

- binary API should be the primary router transport in Go
- SSH should remain as fallback for unsupported commands, script deployment edge cases, and recovery
- this optimization is a transport-layer improvement, not a control-plane rewrite

## Phase 0: Queue Stabilization

Goal:

- make current Laravel queues operationally correct before deeper migration

Tasks:

- `[done]` audit all queue names used in code
- `[done]` audit all worker definitions in Supervisor
- `[done]` close current worker coverage gaps by consolidating low-volume queues into existing workers
- `[todo]` standardize queue naming conventions
- `[done]` add queue lag and age monitoring by queue name
- `[done]` document queue ownership and purpose
- `[done]` reduce redundant tenant `search_path` switching in `TenantContext`
- `[done]` shorten VPN config creation transactions by generating WireGuard keys outside the DB transaction

Expected impact:

- immediate reduction in “mysterious slowness”
- fewer stuck jobs
- clearer operational visibility

## Phase 1: Router Provisioning Execution Migration

Goal:

- remove provisioning execution occupancy from Laravel workers

Tasks:

- `[done]` move `DeployRouterServiceJob` execution to Go command workers
- `[done]` move `RouterProvisioningJob` orchestration-execution split to command publishing
- `[done]` move `ExecuteProvisioningServiceRouterTaskJob` to Go worker execution
- `[done]` retire `ApplyRouterConfigsTaskJob` from Laravel execution path
- `[done]` retire `DiscoverRouterInterfacesJob` legacy execution behavior from Laravel
- `[done]` retire `VerifyRouterConnectivityTaskJob` from Laravel execution path
- `[done]` shift progress reporting from Laravel callback orchestration to event-driven projection

Expected impact:

- frees `router-provisioning` workers
- improves throughput for service deployment
- reduces long-lived PHP worker occupancy


Phase 1 implementation note on 2026-05-25:

- the active `router_tasks` provisioning path now submits authenticated command envelopes to Go over `/api/v1/commands`
- Go owns asynchronous execution for `deploy_service_config`, `apply_service_configs`, `verify_connectivity`, and `discover_interfaces`
- Laravel remains the control-plane projector through `InternalProvisioningTaskController` and `router_tasks`
- RabbitMQ is still the target transport, but the command contract is now in place and transport-agnostic
- `DeployRouterServiceJob` now submits Go commands and projects results through the new internal `router_services` callback endpoint


## Phase 2: Monitoring and Polling Migration

Goal:

- move high-frequency router and VPN monitoring loops into Go

Tasks:

- `[done]` move `ScheduleRouterPollingJob` into Go scheduler/worker domain
- `[done]` retire `FetchRouterLiveData` from the active polling path and move tenant live-data collection behind Go telemetry commands
- `[done]` move `CheckRoutersJob` into Go router-state workers
- `[done]` move `UpdateVpnStatusJob` into Go peer-health workers
- `[done]` remove the `ProcessWireGuardWebhookJob` queue hop and project webhook events directly in Laravel
- `[done]` remove `sleep()`-based waiting loops from Laravel queue jobs; `VerifyVpnConnectivityJob` is migrated, remaining waits are legacy/cleanup paths

Expected impact:

- largest overall reduction in background Laravel worker pressure
- better scaling with tenant and router count

Current cut completed:

- `UpdateVpnStatusJob` now submits a tenant-scoped `refresh_vpn_status` command to Go instead of running `WireguardPeerHealthService` inside Laravel workers
- Go now fetches WireGuard dumps from the controller, computes peer health, and calls back into Laravel
- Laravel projects the callback through `InternalMonitoringController` and remains the authority for `routers`, `vpn_configurations`, and `wireguard_peers`
- `CheckRoutersJob` now submits tenant-scoped `refresh_router_status` commands to Go and projects the result through `InternalMonitoringController`
- `ScheduleRouterPollingJob` now submits one tenant-scoped `refresh_live_data` command to Go instead of chunking routers into `FetchRouterLiveData` jobs
- `FetchRouterLiveData` is no longer on the active polling path; live-data updates are projected through the internal monitoring callback
- WireGuard webhook events are now projected directly through `WireGuardWebhookProjectionService` instead of the queued `ProcessWireGuardWebhookJob` hop
- `VerifyVpnConnectivityJob` now submits a Go-owned `wait_vpn_connectivity` command and receives progress/result callbacks through `InternalMonitoringController`
- `VpnConfigurationController::waitForConnectivity()` now submits the same Go-owned `wait_vpn_connectivity` command instead of blocking a Laravel worker

## Phase 3: Session and Service-Control Migration

Goal:

- move router-side disconnect/reconnect/control actions fully into Go

Tasks:

- `[done]` move hotspot disconnect jobs to Go-backed command execution
- `[done]` move PPPoE disconnect jobs to Go-backed command execution
- `[done]` move hotspot user provisioning jobs to Go-backed command execution
- `[done]` move PPPoE enforcement disconnect execution out of Laravel jobs
- `[done]` route the remaining reconnection-side router actions through Go-backed execution where applicable

Expected impact:

- faster reaction during enforcement bursts
- isolates operational load from business queues

Current cut completed:

- `DisconnectHotspotUserJob`, `DisconnectPppoeUserJob`, and `DisconnectPppoeSessionJob` now use the Go provisioning service for router-side disconnect execution instead of direct PHP SSH
- `ProvisionHotspotJob` and `ProvisionUserInMikroTikJob` now use the Go provisioning service for router-side provisioning execution instead of direct PHP SSH
- `CheckPppoePaymentStatusJob` now keeps the billing decision in Laravel and routes the router-side disconnects through Go-backed execution
- `UserProvisioningService` no longer uses direct SSH for hotspot user creation; the legacy router-side helper now goes through Go-backed execution
- the remaining reconnect path is policy-only in Laravel; the router-side fallback used by `RADIUSServiceController` is now Go-backed as well
- service-control disconnect actions now go through the Go async command contract and write `router_tasks` records for observability
- Laravel still owns RADIUS/blocking state updates, subscription state updates, and event broadcasting
- the read/update hot paths are now less sensitive to tenant schema re-entry because `TenantContext` short-circuits redundant active-tenant switches inside a transaction

## Phase 4: Metrics and Telemetry Migration

Goal:

- move metrics collection and shaping to Go

Tasks:

- `[done]` move `ComputeRouterMetricsJob` to Go workers
- `[done]` move router telemetry collection and aggregation to Go
- `[done]` define Go-to-VictoriaMetrics ingestion strategy
- `[done]` simplify Laravel metrics endpoints to read-only projection

Expected impact:

- lower query and broadcast pressure in Laravel
- better concurrency for telemetry workloads

## Phase 5: Split Mixed Business/Execution Jobs

Goal:

- remove mixed responsibilities from single Laravel jobs

Tasks:

- `[done]` split `CheckPppoePaymentStatusJob` into policy and execution
- `[done]` split `SyncRadiusAccountingJob` where external or high-volume execution is involved
- `[done]` split `ReconnectSubscriptionJob` into billing decision and router execution
- `[done]` batch RADIUS accounting reads to reduce tenant transaction time and schema churn

Expected impact:

- clearer ownership boundaries
- easier retries and failure handling

## Phase 6: Control Plane Hardening

Goal:

- make Laravel a clean control-plane façade after the migration

Tasks:

- `[done]` replace direct execution-style service calls with command publishing
- `[done]` convert `ProvisioningServiceClient` toward command/event bus abstraction
- `[done]` keep `router_tasks` as Laravel-controlled canonical workflow records
- `[done]` project Go result events into router, service, and task tables
- `[done]` review all UI provisioning flows against the new event model

Expected impact:

- stable long-term architecture
- clear control-plane / execution-plane separation


## Phase 7: Router Transport Optimization

Goal:

- move the Go provisioning service to binary API first, SSH fallback only

Tasks:

- `[done]` add Go RouterOS binary API client
- `[done]` make the provisioning service pick binary API first
- `[done]` keep SSH only as fallback
- `[done]` validate E2E provisioning, live-data, and service-control coverage under binary API-first transport

Expected impact:

- lower connection/setup cost
- fewer SSH sessions
- faster read-heavy router operations

---

## Prioritized Performance Wins

If the goal is raw SaaS speed improvement first, this is the recommended execution order:

1. `[done]` fix queue coverage mismatch
2. `[done]` move `VerifyVpnConnectivityJob` wait loop out of Laravel
3. `[done]` move `ScheduleRouterPollingJob`, `FetchRouterLiveData`, and `CheckRoutersJob` to Go
4. `[done]` move the full `router-provisioning` execution family to Go workers
5. `[done]` split and migrate execution parts of `CheckPppoePaymentStatusJob`
6. `[done]` move metrics computation to Go

This ordering is expected to produce the best latency and throughput gains earliest.

---

## Risks and Controls

## Risks

- control-plane and execution-plane state divergence
- duplicated retries in Laravel and Go
- partial migrations that leave ambiguous ownership
- event ordering issues
- missing idempotency on command replay

## Controls

- Laravel remains canonical writer for business state
- every command uses an idempotency key
- Go emits explicit terminal events
- Laravel projects events into `router_tasks`
- one owner per workflow stage
- no dual execution paths after a migration phase is complete

---

## Execution Tracker

## Phase 0: Queue Stabilization

- `[done]` inventory all queues used in code
- `[done]` inventory all configured workers
- `[done]` close worker coverage gaps
- `[done]` validate dead queue names and keep live queues covered
- `[done]` add queue lag monitoring
- `[done]` add queue ownership documentation
- `[done]` reduce redundant tenant `search_path` switching in `TenantContext`
- `[done]` shorten VPN config creation transactions by generating WireGuard keys outside the DB transaction

### Phase 0 Queue Ownership

Laravel-owned control-plane queues:

- `default, low, cache`: light application async work, cache refresh, low-priority housekeeping
- `payments, billing, payment-checks`: billing, payment reconciliation, subscription checks
- `dashboard, reports`: dashboard projections and report generation
- `emails, messaging, notifications`: outbound user and operator communication
- `tenant-management, user-management, auth-tracking, security`: SaaS administration, identity, audit, security tasks

Laravel-owned but execution-heavy queues targeted for later Go migration:

- `router-provisioning, provisioning, hotspot-provisioning`: router and hotspot provisioning orchestration
- `router-checks, router-data, router-monitoring, monitoring, metrics`: router polling, monitoring, and metrics collection
- `service-control, hotspot-sessions, hotspot-access, hotspot-accounting, subscription-reconnection, vpn-provisioning`: router-side session and enforcement operations

Phase 0 status on 2026-05-25:

- every queue referenced in code now has Supervisor worker coverage, including `broadcasts` for queued UI events
- audited `broadcasts` and confirmed it is still a live UI event queue, so Supervisor coverage remains in place
- queue health surfaces now read Redis-backed queue state instead of the `jobs` table
- `queue:stats` now shows per-queue pending, reserved, delayed, failed, worker coverage, and oldest pending age

## Phase 1: Provisioning Execution

- `[done]` define transport-agnostic provisioning command schema
- `[done]` define Go worker for provisioning commands
- `[done]` publish provisioning commands from Laravel
- `[done]` consume and project provisioning result events in Laravel
- `[done]` retire remaining legacy provisioning execution jobs outside `router_tasks`

## Phase 2: Monitoring and Polling

- `[done]` define Go worker for router polling
- `[done]` define Go worker for VPN peer refresh
- `[done]` define Go worker for router status evaluation
- `[done]` project router status observations into Laravel for VPN peer refresh callbacks
- `[done]` retire Laravel polling fanout jobs for router monitoring and live-data refresh

## Phase 3: Service-Control

- `[done]` define dedicated async Go command contract for disconnect/reconnect actions
- `[done]` move hotspot control jobs onto Go-backed command execution
- `[done]` move PPPoE control jobs onto Go-backed command execution
- `[done]` move hotspot user provisioning execution onto Go-backed command execution
- `[done]` split PPPoE payment enforcement so Laravel owns policy and Go owns the disconnect execution
- `[done]` route the remaining reconnection-side router actions through Go-backed execution where applicable

## Phase 4: Metrics

- `[done]` define Go metrics collection contract
- `[done]` migrate metrics collection jobs
- `[done]` validate VictoriaMetrics ingestion and query model
- `[done]` simplify Laravel metrics endpoints to read-only projection

## Phase 5: Mixed Workflows

- `[done]` split PPPoE payment enforcement job
- `[done]` split subscription reconnection job
- `[done]` split any remaining mixed router/business jobs
- `[done]` batch RADIUS accounting reads to reduce tenant transaction time and schema churn

## Phase 6: Final Architecture Cleanup

- `[done]` review all router-related services and remove obsolete execution code
- `[done]` collapse Laravel execution wrappers into command publishers
- `[done]` remove legacy queue workers no longer needed
- `[done]` update runbooks and on-call procedures

---


## Phase 7: Router Transport Optimization

- `[done]` add Go RouterOS binary API client
- `[done]` make the provisioning service pick binary API first
- `[done]` keep SSH only as fallback
- `[done]` validate E2E provisioning, live-data, and service-control coverage under binary API-first transport

## Operational Runbook

When router provisioning or service-control slows down, check in this order:

1. Laravel queue backlog with `queue:stats` and the Redis-backed health endpoints.
2. Go provisioning service health and logs for `/api/v1/provision-service`, `/api/v1/deploy-script`, `/api/v1/execute`, `/api/v1/verify`, and `/api/v1/live-data`.
3. Laravel projector logs for `router_tasks`, `router` state, and callback handling.
4. The high-pressure queues first: `router-provisioning`, `service-control`, `hotspot-sessions`, `hotspot-accounting`, `subscription-reconnection`, `router-checks`, `router-data`, and `metrics`.

Hotspot payment debug endpoint:

- `GET /api/hotspot/debug/payment-state` returns the hotspot user state, latest payment, callback payload, cache state, and reconnect-job state for a user.

Operational rules:

- Laravel owns business-state writes and event projection.
- Go owns router execution, polling, and long-running device operations.
- If a router task is stuck in a submitted state, inspect the Go worker and the callback projector before retrying the Laravel job.
- If queue lag rises across multiple queues, treat it as a worker-capacity problem before assuming router failure.

---

## Definition of Done

This migration is complete when:

- all router, VPN, and device execution is handled by Go workers
- Laravel queue workers no longer perform long-lived device polling or execution
- Laravel remains the only control-plane writer for business state
- RabbitMQ is the durable command/event bridge
- Redis is used only for cache, locks, fanout, and hot ephemeral state
- queue coverage is complete and documented
- task, router, and service state remain consistent under retries and failures

---

## Next Update Instructions

When work begins on a task in this document:

1. change its marker from `[todo]` to `[doing]`
2. add links to implementation PRs or files as needed
3. when verified, mark it `[done]`
4. if blocked, mark it `[blocked]` with a short reason

