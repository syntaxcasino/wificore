# Database Schema Alignment Notes

This document captures the current gaps between the Laravel migration definitions and the `postgres/init.sql` bootstrap script, along with recommended actions.

## Summary of Differences

1. **Tables defined only in migrations**
   - `router_services`, `access_points`, `ap_active_sessions`, `payment_reminders`, `service_control_logs`, `performance_metrics`, and others exist exclusively in Laravel migrations. These tables are absent from `init.sql`, so running only the init script leaves them missing.

2. **Tables defined only in `init.sql`**
   - RADIUS support tables (`radcheck`, `radreply`, `radacct`, `radpostauth`, `nas`) plus associated indexes are created directly in `init.sql`. Fresh Laravel migrations do not create them.

3. **Schema mismatches for shared tables**
   - `tenants`: migrations use soft deletes and fewer operational columns, while `init.sql` defines additional status fields (`is_suspended`, `suspension_reason`, etc.) and different indexes.
   - `users`: migrations lacked security-related columns such as `failed_login_attempts` and `suspended_until`, which exist in `init.sql` and are required by application logic (see fix below).
   - `sessions` and `user_sessions`: Laravel splits framework session storage (string PK, integer timestamps) from business-specific session tracking (UUID PK, metadata). `init.sql` enforces different structures and naming, leading to divergent schemas.
   - Some tables (e.g., `hotspot_users`, `hotspot_sessions`) appear twice in `init.sql` with conflicting definitions, while migrations contain a single canonical schema.

4. **Additional bootstrap logic in `init.sql`**
   - Extensions (`uuid-ossp`, `pgcrypto`), sample seed data, triggers, and comments are defined in `init.sql` only. Running migrations alone omits this logic.

## Recommended Actions

1. **Choose a source of truth:** Decide whether long-term ownership of schema changes lives in Laravel migrations or the `init.sql` script. Ideally, migrate all persistent schema definitions into migrations and reserve `init.sql` for extensions and seed data.
2. **Audit overlapping tables:** For each shared table name, reconcile column definitions, indexes, and constraints. Update either the migrations or `init.sql` to match.
3. **Extend migrations for required columns:** Ensure migrations include all columns used by the application (see `users` migration update below for an example).
4. **Document remaining differences:** Track any intentional deviations (e.g., development-only seed data) so future changes stay consistent.

## Latest Fix

- Added the security-related columns (`failed_login_attempts`, `last_failed_login_at`, `suspended_at`, `suspended_until`, `suspension_reason`) to the `users` migration to resolve runtime errors when incrementing failed login counters.

Keep this document updated as the schema moves toward a unified definition.
