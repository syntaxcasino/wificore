#!/bin/bash
set -e

echo "🚀 Starting WiFi Hotspot Backend..."
echo ""

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
  # ADVISORY LOCK — Single-instance migration runner
  # Lock key 999999 is reserved for entrypoint migration coordination.
  # pg_try_advisory_lock returns true if lock acquired, false if another
  # container already holds it (meaning another container is migrating).
  # -------------------------------------------------------------------------
  LOCK_ACQUIRED=$(PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -c "SELECT pg_try_advisory_lock(999999);" 2>/dev/null | xargs || echo "f")

  if [ "$LOCK_ACQUIRED" = "t" ]; then
    echo "🔒 Migration lock acquired — this container will run migrations"

    # Check if migrations table exists and has records (already initialized)
    MIGRATION_RECORDS=$(PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -c "
      SELECT CASE
        WHEN EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='migrations')
        THEN (SELECT COUNT(*)::text FROM migrations)
        ELSE '0'
      END;" 2>/dev/null | xargs || echo "0")

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
      # Release advisory lock before exiting
      PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "SELECT pg_advisory_unlock(999999);" > /dev/null 2>&1 || true
      exit 1
    fi
    echo "✅ Migrations completed successfully"
    echo ""

    # Run seeders
    if [ "${AUTO_SEED}" = "true" ] || [ "${AUTO_SEED}" = "1" ]; then
      echo "🌱 Running database seeders..."
      su -s /bin/sh www-data -c "DB_HOST=${MIGRATE_DB_HOST} DB_PORT=${MIGRATE_DB_PORT} php artisan db:seed --force" || echo "⚠️  Seeders failed (non-fatal)"
      echo ""
    fi

    # Release migration lock
    PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "SELECT pg_advisory_unlock(999999);" > /dev/null 2>&1 || true
    echo "🔓 Migration lock released"
    echo ""
  else
    echo "⏳ Another container holds the migration lock — waiting for it to finish..."
    # Wait for the other container to finish by trying to acquire + immediately release
    WAIT_TRIES=0
    while [ $WAIT_TRIES -lt 60 ]; do
      WAIT_TRIES=$((WAIT_TRIES+1))
      LOCK_CHECK=$(PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -c "SELECT pg_try_advisory_lock(999999);" 2>/dev/null | xargs || echo "f")
      if [ "$LOCK_CHECK" = "t" ]; then
        # Got the lock — release immediately, migrations are done
        PGPASSWORD="${DB_PASSWORD}" psql -h "${MIGRATE_DB_HOST}" -p "${MIGRATE_DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "SELECT pg_advisory_unlock(999999);" > /dev/null 2>&1 || true
        echo "✅ Other container finished migrations"
        break
      fi
      sleep 2
    done
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
