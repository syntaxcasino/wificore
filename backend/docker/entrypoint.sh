#!/bin/bash
set -e

echo "🚀 Starting WiFi Hotspot Backend..."
echo ""

# =============================================================================
# 0. RUNTIME GUARDS — Fail fast on missing PHP extensions
# =============================================================================
require_php_ext() {
  local ext="$1"
  if ! php -m | grep -qi "^${ext}$"; then
    echo "❌ FATAL: Required PHP extension '${ext}' is not loaded."
    echo "   Rebuild/redeploy backend image with '${ext}' enabled."
    exit 1
  fi
}

require_php_ext "PDO"
require_php_ext "pdo_pgsql"

REDIS_CLIENT_DRIVER="${REDIS_CLIENT:-predis}"
if [ "${REDIS_CLIENT_DRIVER}" = "phpredis" ]; then
  require_php_ext "redis"
else
  if php -m | grep -qi "^redis$"; then
    echo "✅ PHP extension 'redis' is loaded (client: ${REDIS_CLIENT_DRIVER})"
  else
    echo "ℹ️  PHP extension 'redis' is not loaded; continuing with REDIS_CLIENT=${REDIS_CLIENT_DRIVER}"
  fi
fi

if ! php -m | grep -qi "^pgsql$"; then
  echo "⚠️  Optional PHP extension 'pgsql' is not loaded (continuing with pdo_pgsql)."
fi

configure_php_fpm_runtime() {
  local target="/usr/local/etc/php-fpm.d/zzz-runtime-tuning.conf"

  cat > "${target}" <<EOF
[www]
pm = ${PHP_FPM_PM:-dynamic}
pm.max_children = ${PHP_FPM_PM_MAX_CHILDREN:-24}
pm.start_servers = ${PHP_FPM_PM_START_SERVERS:-6}
pm.min_spare_servers = ${PHP_FPM_PM_MIN_SPARE_SERVERS:-4}
pm.max_spare_servers = ${PHP_FPM_PM_MAX_SPARE_SERVERS:-12}
pm.max_requests = ${PHP_FPM_PM_MAX_REQUESTS:-750}
pm.process_idle_timeout = ${PHP_FPM_PM_PROCESS_IDLE_TIMEOUT:-10s}
pm.status_path = ${PHP_FPM_PM_STATUS_PATH:-/fpm-status}
ping.path = ${PHP_FPM_PING_PATH:-/fpm-ping}
ping.response = ${PHP_FPM_PING_RESPONSE:-pong}
request_terminate_timeout = ${PHP_FPM_REQUEST_TERMINATE_TIMEOUT:-120s}
request_slowlog_timeout = ${PHP_FPM_REQUEST_SLOWLOG_TIMEOUT:-5s}
slowlog = /var/www/html/storage/logs/php-fpm-slow.log
php_admin_value[error_log] = /var/www/html/storage/logs/php-fpm-error.log
php_admin_flag[log_errors] = on
php_admin_value[memory_limit] = ${PHP_MEMORY_LIMIT:-192M}
php_admin_value[max_execution_time] = ${PHP_MAX_EXECUTION_TIME:-120}
EOF

  echo "✅ PHP-FPM runtime tuning written to ${target}"
}

if [ "${APP_RUNTIME_ROLE:-web}" = "web" ] || [ "${APP_RUNTIME_ROLE:-web}" = "sse" ]; then
  configure_php_fpm_runtime
fi

# =============================================================================
# 0.5 SUPERVISOR ROLE — Enable only the process set needed by this container
# =============================================================================
APP_RUNTIME_ROLE="${APP_RUNTIME_ROLE:-web}"
QUEUE_WORKER_GROUP="${QUEUE_WORKER_GROUP:-all}"

disable_all_queue_confs() {
  rm -f /etc/supervisor/conf.d/laravel-queue*.conf
}

keep_only_queue_group() {
  local target="/etc/supervisor/conf.d/laravel-queue-${QUEUE_WORKER_GROUP}.conf"

  if [ ! -f "${target}" ]; then
    echo "❌ FATAL: Unknown QUEUE_WORKER_GROUP='${QUEUE_WORKER_GROUP}'"
    echo "   Expected one of: all, core, router, metrics, realtime"
    exit 1
  fi

  rm -f /etc/supervisor/conf.d/laravel-queue.conf

  for conffile in /etc/supervisor/conf.d/laravel-queue-*.conf; do
    [ -f "${conffile}" ] || continue
    if [ "${conffile}" != "${target}" ]; then
      rm -f "${conffile}"
    fi
  done
}

case "${APP_RUNTIME_ROLE}" in
  web)
    rm -f /etc/supervisor/conf.d/laravel-scheduler.conf
    disable_all_queue_confs
    echo "🧩 Runtime role: web (PHP-FPM only)"
    ;;
  sse)
    rm -f /etc/supervisor/conf.d/laravel-scheduler.conf
    disable_all_queue_confs
    echo "🧩 Runtime role: sse (dedicated PHP-FPM streaming backend)"
    ;;
  scheduler)
    rm -f /etc/supervisor/conf.d/php-fpm.conf
    disable_all_queue_confs
    echo "🧩 Runtime role: scheduler (schedule:run loop)"
    ;;
  queue)
    rm -f /etc/supervisor/conf.d/php-fpm.conf
    rm -f /etc/supervisor/conf.d/laravel-scheduler.conf
    if [ "${QUEUE_WORKER_GROUP}" = "all" ] || [ -z "${QUEUE_WORKER_GROUP}" ]; then
      rm -f /etc/supervisor/conf.d/laravel-queue-core.conf \
            /etc/supervisor/conf.d/laravel-queue-router.conf \
            /etc/supervisor/conf.d/laravel-queue-metrics.conf \
            /etc/supervisor/conf.d/laravel-queue-realtime.conf
      echo "🧩 Runtime role: queue (all worker groups)"
    else
      keep_only_queue_group
      echo "🧩 Runtime role: queue (${QUEUE_WORKER_GROUP} worker group)"
    fi
    ;;
  migrate)
    rm -f /etc/supervisor/conf.d/php-fpm.conf
    rm -f /etc/supervisor/conf.d/laravel-scheduler.conf
    disable_all_queue_confs
    echo "🧩 Runtime role: migrate (one-shot migrations and seeders)"
    ;;
  *)
    echo "❌ FATAL: Unknown APP_RUNTIME_ROLE='${APP_RUNTIME_ROLE}'"
    echo "   Expected one of: web, sse, scheduler, queue, migrate"
    exit 1
    ;;
esac

# =============================================================================
# 1. DIRECTORY SETUP — Create all required directories
# =============================================================================
mkdir -p /var/www/html/storage/framework/{cache,sessions,views} \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache \
         /var/log/supervisor \
         /var/run

# =============================================================================
# 2. PERMISSIONS — Set ownership FIRST, before any file creation
# =============================================================================
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# =============================================================================
# 3. PRE-CREATE LOG FILES — Ensure all log files exist as www-data
#    This prevents root-owned log files from blocking queue workers.
# =============================================================================

# Core Laravel log
su -s /bin/sh www-data -c "touch /var/www/html/storage/logs/laravel.log"

# PHP-FPM logs (referenced in php-fpm-custom.conf)
su -s /bin/sh www-data -c "touch /var/www/html/storage/logs/php-fpm-error.log"
su -s /bin/sh www-data -c "touch /var/www/html/storage/logs/php-fpm-slow.log"

# Scheduler log (referenced in laravel-scheduler.conf)
su -s /bin/sh www-data -c "touch /var/www/html/storage/logs/scheduler.log"

# Pre-create ALL supervisor queue log files from conf.d/*.conf
for conffile in /etc/supervisor/conf.d/*.conf; do
  [ -f "$conffile" ] || continue
  while IFS= read -r logfile; do
    if [ -n "$logfile" ] && [ "$logfile" != "/dev/stdout" ] && [ "$logfile" != "/dev/stderr" ]; then
      # Ensure parent directory exists
      mkdir -p "$(dirname "$logfile")" 2>/dev/null || true
      su -s /bin/sh www-data -c "touch $logfile" 2>/dev/null || true
    fi
  done < <(
    grep -E '^(stdout_logfile|stderr_logfile)\s*=' "$conffile" \
      | sed 's/^[^=]*=\s*//' \
      | sort -u
  )
done

echo "✅ Log files pre-created"

if [ "${MIKROTIK_SSH_AUTO_GENERATE}" = "true" ] || [ "${MIKROTIK_SSH_AUTO_GENERATE}" = "1" ]; then
  REAL_KEY_DIR="/var/www/html/storage/ssh"
  REAL_KEY_PATH="${REAL_KEY_DIR}/mikrotik_id_rsa"
  REAL_PUB_PATH="${REAL_KEY_DIR}/mikrotik_id_rsa.pub"
  mkdir -p "${REAL_KEY_DIR}"
  chown -R www-data:www-data "${REAL_KEY_DIR}"
  chmod 700 "${REAL_KEY_DIR}"

  if [ ! -f "${REAL_KEY_PATH}" ]; then
    if [ -f /var/www/html/vendor/autoload.php ]; then
      if ! php -r 'require "/var/www/html/vendor/autoload.php"; $key=\phpseclib3\Crypt\RSA::createKey(2048); file_put_contents($argv[1], $key->toString("PKCS8")); chmod($argv[1], 0600); $pub=$key->getPublicKey()->toString("OpenSSH", ["comment"=>"wificore-global"]); file_put_contents($argv[2], rtrim($pub)."\n"); chmod($argv[2], 0644);' "${REAL_KEY_PATH}" "${REAL_PUB_PATH}"; then
        echo "⚠️  Failed to generate MikroTik SSH keypair (will continue with password fallback if available)"
      fi
    fi
    chown www-data:www-data "${REAL_KEY_PATH}" 2>/dev/null || true
    chown www-data:www-data "${REAL_PUB_PATH}" 2>/dev/null || true
  fi

  mkdir -p /run/secrets
  if [ -f "${REAL_KEY_PATH}" ]; then
    ln -sf "${REAL_KEY_PATH}" /run/secrets/mikrotik_id_rsa
  fi
  if [ -f "${REAL_PUB_PATH}" ]; then
    ln -sf "${REAL_PUB_PATH}" /run/secrets/mikrotik_id_rsa.pub
  fi
fi

# Run Laravel optimizations (after .env is present)
if [ -f /var/www/html/.env ]; then
  echo "⚡ Caching Laravel config, routes and events..."
  su -s /bin/bash www-data -c "php artisan config:cache" || true
  su -s /bin/bash www-data -c "php artisan route:cache" || true
  su -s /bin/bash www-data -c "php artisan event:cache" || true
  # view:cache uses atomic temp-file rename; concurrent containers sharing the
  # same storage volume race on the same temp file → rename ENOENT error.
  # Serialise with flock so only one container compiles views at a time.
  mkdir -p /var/www/html/storage/framework/views
  flock /var/www/html/storage/framework/views/.view-cache.lock \
    su -s /bin/bash www-data -c "php artisan view:cache" || true
  echo "✅ Laravel caches built successfully"
  echo ""
fi

###############################################################################
# AUTOMATIC DATABASE SETUP
# - Uses direct PostgreSQL connection (bypasses PgBouncer) for DDL safety
# - Advisory lock ensures only one container runs migrations at a time
# - FAIL-FAST: migration failure = container exit (no silent schema drift)
###############################################################################
if [ "${AUTO_MIGRATE}" = "true" ] || [ "${AUTO_MIGRATE}" = "1" ]; then
  echo "🔄 Auto-migration enabled..."
  echo ""

  # Determine direct DB host (bypass PgBouncer for DDL operations)
  MIGRATE_DB_HOST="${DB_DIRECT_HOST:-${DB_HOST}}"
  MIGRATE_DB_PORT="${DB_DIRECT_PORT:-5432}"
  echo "   Migration target: ${MIGRATE_DB_HOST}:${MIGRATE_DB_PORT}/${DB_DATABASE}"

  # Wait for database to be ready (connect directly, not via PgBouncer)
  echo "⏳ Waiting for database..."
  MAX_TRIES=30
  TRIES=0
  DB_READY=false

  while [ $TRIES -lt $MAX_TRIES ]; do
    TRIES=$((TRIES+1))
    if PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "SELECT 1" > /dev/null 2>&1; then
      DB_READY=true
      break
    fi
    echo "   Attempt $TRIES/$MAX_TRIES..."
    sleep 2
  done

  if [ "$DB_READY" = false ]; then
    echo "❌ FATAL: Database connection failed after $MAX_TRIES attempts"
    echo "   Host: ${MIGRATE_DB_HOST}:${MIGRATE_DB_PORT}"
    echo "   Database: ${DB_DATABASE}"
    echo "   User: ${DB_USERNAME}"
    exit 1
  fi

  echo "✅ Database is ready (attempt $TRIES/$MAX_TRIES)"
  echo ""

  # -------------------------------------------------------------------------
  # DATABASE LOCK — Single-instance migration runner
  # Uses a simple lock table in PostgreSQL to coordinate across containers.
  # This avoids session-scoped advisory lock issues (each psql -c is a new session).
  # -------------------------------------------------------------------------

  # Create lock table if it doesn't exist
  CONTAINER_ID=$(hostname)
  PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "
    CREATE TABLE IF NOT EXISTS _migration_lock (
      id integer PRIMARY KEY DEFAULT 1,
      locked_by text,
      locked_at timestamptz DEFAULT now(),
      CONSTRAINT single_row CHECK (id = 1)
    );" > /dev/null 2>&1 || true

  # Clean up stale locks:
  # 1. Any lock older than 5 minutes (crashed container)
  # 2. Any lock held by a different container (previous instance is dead on restart)
  PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "
    DELETE FROM _migration_lock WHERE id = 1
    AND (locked_at < now() - interval '5 minutes' OR locked_by != '${CONTAINER_ID}');" > /dev/null 2>&1 || true

  # Try to acquire lock — use upsert and then verify we own it
  PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "
    INSERT INTO _migration_lock (id, locked_by, locked_at) VALUES (1, '${CONTAINER_ID}', now())
    ON CONFLICT (id) DO NOTHING;" > /dev/null 2>&1 || true

  # Check if WE hold the lock
  LOCK_OWNER=$(PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -A -c "
    SELECT locked_by FROM _migration_lock WHERE id = 1;" 2>/dev/null || echo "")

  if [ "$LOCK_OWNER" = "$CONTAINER_ID" ]; then
    echo "🔒 Migration lock acquired — this container will run migrations"

    # Check if migrations table exists, then count records
    TABLE_EXISTS=$(PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -A -c "
      SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='migrations');" 2>/dev/null || echo "f")

    if [ "$TABLE_EXISTS" = "t" ]; then
      MIGRATION_RECORDS=$(PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -A -c "
        SELECT COUNT(*)::text FROM migrations;" 2>/dev/null || echo "0")
    else
      MIGRATION_RECORDS=0
    fi

    if ! [[ "$MIGRATION_RECORDS" =~ ^[0-9]+$ ]]; then
      MIGRATION_RECORDS=0
    fi

    if [ "$MIGRATION_RECORDS" -gt 0 ]; then
      echo "📦 Database has $MIGRATION_RECORDS existing migrations — running pending only"
    fi

    SHOULD_RUN_SEEDERS=false
    if [ "${FRESH_INSTALL}" = "true" ]; then
      SHOULD_RUN_SEEDERS=true
      echo "🌱 Fresh install requested — seeders will run after migrations"
    elif [ "$TABLE_EXISTS" != "t" ] || [ "$MIGRATION_RECORDS" -eq 0 ]; then
      SHOULD_RUN_SEEDERS=true
      echo "🌱 Fresh database detected — seeders will run after migrations"
    else
      echo "⏭️  Existing database detected — skipping seeders"
    fi

    # Run migrations with direct DB connection (bypass PgBouncer for DDL)
    echo "🔄 Running database migrations..."
    MIGRATION_EXIT=0
    if [ "${FRESH_INSTALL}" = "true" ]; then
      echo "⚠️  Fresh install mode — dropping all tables..."
      su -s /bin/sh www-data -c "DB_HOST=${MIGRATE_DB_HOST} DB_PORT=${MIGRATE_DB_PORT} php artisan migrate:fresh --force" || MIGRATION_EXIT=$?
    else
      su -s /bin/sh www-data -c "DB_HOST=${MIGRATE_DB_HOST} DB_PORT=${MIGRATE_DB_PORT} php artisan migrate --force" || MIGRATION_EXIT=$?
    fi

    if [ $MIGRATION_EXIT -ne 0 ]; then
      echo "❌ FATAL: Migrations failed with exit code $MIGRATION_EXIT"
      echo "   Container will NOT start with incomplete schema."
      echo "   Fix the migration and redeploy."
      # Release lock before exiting
      PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "DELETE FROM _migration_lock WHERE id = 1;" > /dev/null 2>&1 || true
      exit 1
    fi
    echo "✅ Migrations completed successfully"
    echo ""

    # Run seeders
    if { [ "${AUTO_SEED}" = "true" ] || [ "${AUTO_SEED}" = "1" ]; } && [ "$SHOULD_RUN_SEEDERS" = "true" ]; then
      echo "🌱 Running database seeders..."
      SEED_EXIT=0
      su -s /bin/sh www-data -c "DB_HOST=${MIGRATE_DB_HOST} DB_PORT=${MIGRATE_DB_PORT} php artisan db:seed --force" || SEED_EXIT=$?
      if [ $SEED_EXIT -ne 0 ]; then
        echo "❌ FATAL: Seeders failed with exit code $SEED_EXIT"
        echo "   Container will NOT start with incomplete data."
        echo "   Fix the seeder and redeploy."
        # Release lock before exiting
        PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "DELETE FROM _migration_lock WHERE id = 1;" > /dev/null 2>&1 || true
        exit 1
      fi
      echo "✅ Seeders completed successfully"
      echo ""
    elif [ "${AUTO_SEED}" = "true" ] || [ "${AUTO_SEED}" = "1" ]; then
      echo ""
    fi

    # Release migration lock
    PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "DELETE FROM _migration_lock WHERE id = 1;" > /dev/null 2>&1 || true
    echo "🔓 Migration lock released"
    echo ""
  else
    echo "⏳ Another container holds the migration lock — waiting for it to finish..."
    WAIT_TRIES=0
    LOCK_CLEARED=false
    while [ $WAIT_TRIES -lt 60 ]; do
      WAIT_TRIES=$((WAIT_TRIES+1))
      LOCK_EXISTS=$(PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -A -c "
        SELECT EXISTS (SELECT 1 FROM _migration_lock WHERE id = 1);" 2>/dev/null || echo "f")
      if [ "$LOCK_EXISTS" = "f" ]; then
        echo "✅ Other container finished migrations"
        LOCK_CLEARED=true
        break
      fi
      sleep 2
    done
    if [ "$LOCK_CLEARED" = "false" ]; then
      echo "⚠️  Migration lock wait timed out (120s) — force-clearing stale lock and running migrations ourselves"
      PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "DELETE FROM _migration_lock WHERE id = 1;" > /dev/null 2>&1 || true

      # Acquire lock and run migrations ourselves
      PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "
        INSERT INTO _migration_lock (id, locked_by, locked_at) VALUES (1, '${CONTAINER_ID}', now())
        ON CONFLICT (id) DO UPDATE SET locked_by = '${CONTAINER_ID}', locked_at = now();" > /dev/null 2>&1 || true

      echo "🔒 Migration lock acquired after timeout — running migrations"

      RECOVERY_TABLE_EXISTS=$(PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -A -c "
        SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='migrations');" 2>/dev/null || echo "f")
      if [ "$RECOVERY_TABLE_EXISTS" = "t" ]; then
        RECOVERY_MIGRATION_RECORDS=$(PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -A -c "
          SELECT COUNT(*)::text FROM migrations;" 2>/dev/null || echo "0")
      else
        RECOVERY_MIGRATION_RECORDS=0
      fi

      if ! [[ "$RECOVERY_MIGRATION_RECORDS" =~ ^[0-9]+$ ]]; then
        RECOVERY_MIGRATION_RECORDS=0
      fi

      RECOVERY_SHOULD_RUN_SEEDERS=false
      if [ "${FRESH_INSTALL}" = "true" ]; then
        RECOVERY_SHOULD_RUN_SEEDERS=true
        echo "🌱 Fresh install requested — seeders will run after migrations"
      elif [ "$RECOVERY_TABLE_EXISTS" != "t" ] || [ "$RECOVERY_MIGRATION_RECORDS" -eq 0 ]; then
        RECOVERY_SHOULD_RUN_SEEDERS=true
        echo "🌱 Fresh database detected — seeders will run after migrations"
      else
        echo "⏭️  Existing database detected — skipping seeders"
      fi

      echo "🔄 Running database migrations..."
      MIGRATION_EXIT=0
      if [ "${FRESH_INSTALL}" = "true" ]; then
        echo "⚠️  Fresh install mode — dropping all tables..."
        su -s /bin/sh www-data -c "DB_HOST=${MIGRATE_DB_HOST} DB_PORT=${MIGRATE_DB_PORT} php artisan migrate:fresh --force" || MIGRATION_EXIT=$?
      else
        su -s /bin/sh www-data -c "DB_HOST=${MIGRATE_DB_HOST} DB_PORT=${MIGRATE_DB_PORT} php artisan migrate --force" || MIGRATION_EXIT=$?
      fi

      if [ $MIGRATION_EXIT -ne 0 ]; then
        echo "❌ FATAL: Migrations failed with exit code $MIGRATION_EXIT"
        echo "   Container will NOT start with incomplete schema."
        echo "   Fix the migration and redeploy."
        PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "DELETE FROM _migration_lock WHERE id = 1;" > /dev/null 2>&1 || true
        exit 1
      fi
      echo "✅ Migrations completed successfully (after timeout recovery)"

      # Run seeders if enabled
      if { [ "${AUTO_SEED}" = "true" ] || [ "${AUTO_SEED}" = "1" ]; } && [ "$RECOVERY_SHOULD_RUN_SEEDERS" = "true" ]; then
        echo "🌱 Running database seeders..."
        SEED_EXIT=0
        su -s /bin/sh www-data -c "DB_HOST=${MIGRATE_DB_HOST} DB_PORT=${MIGRATE_DB_PORT} php artisan db:seed --force" || SEED_EXIT=$?
        if [ $SEED_EXIT -ne 0 ]; then
          echo "❌ FATAL: Seeders failed with exit code $SEED_EXIT"
          echo "   Container will NOT start with incomplete data."
          echo "   Fix the seeder and redeploy."
          PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "DELETE FROM _migration_lock WHERE id = 1;" > /dev/null 2>&1 || true
          exit 1
        fi
        echo "✅ Seeders completed successfully"
      elif [ "${AUTO_SEED}" = "true" ] || [ "${AUTO_SEED}" = "1" ]; then
        echo ""
      fi

      # Release lock
      PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "DELETE FROM _migration_lock WHERE id = 1;" > /dev/null 2>&1 || true
      echo "🔓 Migration lock released"
    fi
    echo ""
  fi

  echo "✅ Migrations completed, caches already built above"
  echo ""

  # Create storage link
  echo "🔗 Creating storage link..."
  mkdir -p /var/www/html/public
  chown -R www-data:www-data /var/www/html/public
  chmod -R 775 /var/www/html/public
  if [ -L /var/www/html/public/storage ]; then
    rm -f /var/www/html/public/storage
  fi
  su -s /bin/sh www-data -c "php artisan storage:link" || echo "⚠️  Storage link creation failed (may already exist)"
  echo ""
fi

echo "✅ Backend initialization complete!"

if [ "${APP_RUNTIME_ROLE}" = "migrate" ]; then
  echo "🏁 Migrator role finished successfully."
  exit 0
fi

echo "🎉 Starting services..."
echo ""

exec "$@"
