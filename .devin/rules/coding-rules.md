# MANDATORY RULES - FOLLOW AT ALL TIMES
Execute these steps IMMEDIATELY in every interaction:
1. READ and INTERNALIZE all rules in this file.
2. These rules are BINDING and take absolute precedence.
3. ENFORCE them strictly; never skip or assume.


trigger: always_on
The Stack
ALWAYS CONFIGURE A TAG BEFORE WRITTING OR EDITING CODE.
All jobs, events, and database operations must be tenant-aware to maintain proper isolation in the schema-based multi-tenant system.
Tenant-specific migrations must be created in migrations/tenant/. General/shared table migrations must go in the main migrations/ folder. Always double-check placement and run a dry migration test before applying.
Use Git Bash on the local Windows 11 development machine for all Git and shell operations. On production (Ubuntu 24.04 LTS VPS), use native terminal commands. Test Git Bash commands locally in a safe branch if needed.
Keep .env and .env.production fully synchronized: Any change in one must be immediately mirrored in the other. Do the same for docker-compose.yml and docker-compose.production.yml. Validate sync with a diff tool (e.g., diff .env .env.production) after changes.
This is an event-based system: Prefer events and listeners over direct calls. Always test event dispatching and handling end-to-end after modifications.
Perform a thorough end-to-end check (functionality, tenant isolation, events, API endpoints) before any code edits. Document observations and only proceed if everything is verified working.
Never modify or remove existing working features or stack components without explicit approval and a feature flag/branch for safe testing.
This is a fully Dockerized setup: All services run in containers. Rebuild and restart containers (docker-compose up --build) after code changes, then check logs and health.
Schema-based multi-tenancy is enforced: All queries and models must respect the current tenant schema.
Ensure all changes are persistent (e.g., use migrations for DB changes, commit code).
Always commit changes with clear messages, push to the remote repository, and verify the push (e.g., check remote branches).
Place and update all documentation in the docs/ folder; commit alongside related code changes.
Work in feature branches, use pull requests for review if possible, and resolve conflicts before merging.
After changes, run tests (automated or manual) and stage in a local/test environment before production deployment.
Never assume environment state—always verify (e.g., docker ps, git status, current versions).

Backend & Database

Use the latest Laravel 12.x (current stable: 12.44.0 or higher patch as of January 2026). Check compatibility before updating.
Use PHP 8.5+ (current stable: PHP 8.5, released November 2025).
Use modern PostgreSQL syntax compatible with the current version.
Use Soketi for real-time WebSocket communication, integrated with Laravel broadcasting.
Use FreeRADIUS for RADIUS-based authentication where required.
Use Supervisor for process management, with configs in backend/supervisor/.
Bake all configuration files into Docker images during build; use environment variables for runtime differences.
Use Redis for caching, queues, and session storage. Test cache behavior to avoid stale data issues.
Use PostgreSQL 18.x as the primary database (current minor: 18.1 as of late 2025).
Enforce tenant schema switching in database connections.

Frontend

Use Vue.js 3.x (current stable: 3.5.26 as of January 2026).
Use Tailwind CSS v4.0 (latest major release, optimized for performance).
Use Vue Router 4.x (current: 4.6.4) and Pinia 3.x (current: 3.0.4) for routing and state management.

Additional Safeguards to Minimize Mistakes

Version Compatibility: Before upgrading any package, run dependency checks (e.g., composer update --dry-run, npm outdated) and test in a branch.
Testing Mandate: No change proceeds without passing local tests and end-to-end verification in a Docker environment.
Rollback Readiness: Tag the repository before major changes (e.g., git tag pre-change-2026-01-08). Revert immediately if issues arise.
Logging Actions: Record key steps (edits, builds, deploys) for review.
No Direct Production Changes: All changes go through Git and local verification first.
Human Oversight: For complex changes, summarize proposed edits and request confirmation before applying.
Error Prevention in Code: Add proper exception handling, validation, and tenant guards in new/modified code.
This is a read and write heavy SaaS application , thats why i specifically  have dedicated read and write  pgbouncers  with  primary  and replica DBs inplace.  make sure all reads are using the read pgbouncer  and dont mix reads and write