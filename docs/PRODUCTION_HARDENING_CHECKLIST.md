# Production Hardening Checklist

Use this checklist to validate production readiness for the WifiCore backend. Mark each item as you complete it. The automated environment checks in `HealthCheckService::checkEnvironment()` surface many of these warnings in `/api/health`.

## Critical (blockers)
- [ ] Set `APP_ENV=production` and `APP_DEBUG=false`.
- [ ] Generate a real `APP_KEY` (no placeholders like `GENERATE_WITH_*`).
- [ ] Configure strong database credentials (`DB_PASSWORD` not empty or default).
- [ ] Ensure HTTPS is enforced for `APP_URL` (no localhost, no http in production).
- [ ] Remove or gate any test routes/endpoints (confirmed for `/test-broadcast` and `/test/rsc-generation`).
- [ ] Verify router provisioning tokens have TTL (`ROUTER_CONFIG_TOKEN_TTL_MINUTES`) and refresh tokens after expiration.

## High Priority
- [ ] Set `LOG_LEVEL` to `warning` or `error` in production.
- [ ] Configure Redis password (`REDIS_PASSWORD`) and ensure Redis is not exposed publicly.
- [ ] Configure `RADIUS_SECRET` and confirm FreeRADIUS is network-restricted.
- [ ] Set `SESSION_SECURE_COOKIE=true` and verify `SESSION_SAME_SITE` is `lax` or `strict`.
- [ ] Configure `SANCTUM_STATEFUL_DOMAINS` for all production domains.
- [ ] Confirm queue backend is persistent (`QUEUE_CONNECTION` not `sync`) and workers are supervised.
- [ ] Ensure `CACHE_STORE` is persistent (redis/database) for multi-instance deployments.
- [ ] Verify router config fetch endpoint returns 410 for expired tokens and is monitored for abuse.

## Medium Priority
- [ ] Configure `WIREGUARD_API_KEY` and restrict controller access to internal network.
- [ ] Validate CORS origin lists do not include localhost in production.
- [ ] Set `SESSION_DRIVER` to a shared backend (redis/database) for horizontal scaling.
- [ ] Disable `SOKETI_DEBUG` in production.
- [ ] Confirm M-Pesa credentials point to production (`MPESA_ENVIRONMENT=production`).
- [ ] Ensure log rotation/retention is configured (daily or external log pipeline).
- [ ] Enable backups and verify restore procedure (`BACKUP_*` settings, retention, offsite storage).
- [ ] Enforce rate limits for APIs and login endpoints (per tenant and public endpoints).

## Operational Readiness
- [ ] Run `/api/health` and confirm `environment` check is `healthy`.
- [ ] Verify tenant migration manager is up to date and migrations run for all tenants.
- [ ] Confirm monitoring/alerting (Sentry/New Relic/Prometheus) is configured.
- [ ] Verify error budgets and alert thresholds for queue failures, DB latency, and disk usage.
- [ ] Confirm cron/scheduler is running for queue maintenance and token cleanup routines.

## Router Provisioning Security
- [ ] Rotate router `config_token` after initial provisioning or if leaked.
- [ ] Store router credentials encrypted and avoid logging sensitive request payloads.
- [ ] Confirm bootstrap scripts use secure defaults (SSH enabled, API access restricted).

## Post-Deployment Verification
- [ ] Validate login, provisioning, and router config fetch flows end-to-end.
- [ ] Run smoke tests (health check, tenant creation, router provisioning, payment flow).
- [ ] Confirm incident response runbook and backup restore tests are documented.
