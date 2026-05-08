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
require_php_ext "redis"

if ! php -m | grep -qi "^pgsql$"; then
  echo "⚠️  Optional PHP extension 'pgsql' is not loaded (continuing with pdo_pgsql)."
fi

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

# Octane/RoadRunner logs
su -s /bin/sh www-data -c "touch /var/www/html/storage/logs/octane.log"

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
  echo "🧹 Clearing Laravel caches..."
  su -s /bin/bash www-data -c "php artisan config:clear" || true
  su -s /bin/bash www-data -c "php artisan cache:clear" || true
  su -s /bin/bash www-data -c "php artisan route:clear" || true
  su -s /bin/bash www-data -c "php artisan view:clear" || true
  echo "✅ Cache cleared successfully"
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
    if [ "${AUTO_SEED}" = "true" ] || [ "${AUTO_SEED}" = "1" ]; then
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
      if [ "${AUTO_SEED}" = "true" ] || [ "${AUTO_SEED}" = "1" ]; then
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
      fi

      # Release lock
      PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "DELETE FROM _migration_lock WHERE id = 1;" > /dev/null 2>&1 || true
      echo "🔓 Migration lock released"
    fi
    echo ""
  fi

  # Optimize application (always, regardless of who ran migrations)
  echo "⚡ Optimizing application..."
  su -s /bin/sh www-data -c "php artisan config:cache" || true
  su -s /bin/sh www-data -c "php artisan route:cache" || true
  su -s /bin/sh www-data -c "php artisan view:cache" || true
  echo "✅ Optimization completed"
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
echo "🎉 Starting services..."
echo ""

exec "$@"
