#!/bin/bash
set -e

echo "🚀 Starting WiFi Hotspot Backend..."
echo ""

# Ensure storage and cache directories exist
mkdir -p /var/www/html/storage/framework/{cache,sessions,views} /var/www/html/storage/logs

# Create log files as www-data user to prevent root ownership
su -s /bin/bash www-data -c "touch /var/www/html/storage/logs/laravel.log"

# Supervisor (running as root) creates stdout/stderr log files as root by default.
# Pre-create ALL configured supervisor log files as www-data so workers can write.
if [ -f /etc/supervisor/conf.d/laravel-queue.conf ]; then
  while IFS= read -r logfile; do
    if [ -n "$logfile" ]; then
      su -s /bin/bash www-data -c "touch $logfile" || true
    fi
  done < <(
    grep -E '^(stdout_logfile|stderr_logfile)=' /etc/supervisor/conf.d/laravel-queue.conf \
      | cut -d'=' -f2 \
      | sort -u
  )
fi

# Set proper ownership and permissions
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage/logs

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
###############################################################################
if [ "${AUTO_MIGRATE}" = "true" ] || [ "${AUTO_MIGRATE}" = "1" ]; then
  echo "🔄 Auto-migration enabled..."
  echo ""
  
  # Wait for database to be ready
  echo "⏳ Waiting for database..."
  MAX_TRIES=30
  TRIES=0
  DB_READY=false
  
  # Test database connection using PostgreSQL-specific command
  while [ $TRIES -lt $MAX_TRIES ]; do
    TRIES=$((TRIES+1))
    echo "   Attempt $TRIES/$MAX_TRIES..."
    
    # Try to connect to PostgreSQL directly
    if PGPASSWORD="${DB_PASSWORD}" psql -h "${DB_HOST}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "SELECT 1" > /dev/null 2>&1; then
      DB_READY=true
      break
    fi
    
    # Also try Laravel's database connection as fallback
    if su -s /bin/bash www-data -c "php artisan db:show" > /dev/null 2>&1; then
      DB_READY=true
      break
    fi
    
    sleep 2
  done
  
  if [ "$DB_READY" = false ]; then
    echo "❌ Database connection failed after $MAX_TRIES attempts"
    echo "   DB_HOST: ${DB_HOST}"
    echo "   DB_DATABASE: ${DB_DATABASE}"
    echo "   DB_USERNAME: ${DB_USERNAME}"
    echo "⚠️  Continuing without migrations..."
  else
    echo "✅ Database is ready (attempt $TRIES/$MAX_TRIES)"
    echo ""
    
    # Check if database already has migrations (skip if volume exists with data)
    MIGRATION_COUNT=$(PGPASSWORD="${DB_PASSWORD}" psql -h "${DB_HOST}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'migrations';" 2>/dev/null | xargs || echo "0")
    
    if [ "$MIGRATION_COUNT" -gt 0 ]; then
      # Check if migrations table has records
      MIGRATION_RECORDS=$(PGPASSWORD="${DB_PASSWORD}" psql -h "${DB_HOST}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -c "SELECT COUNT(*) FROM migrations;" 2>/dev/null | xargs || echo "0")
      
      if [ "$MIGRATION_RECORDS" -gt 0 ]; then
        echo "📦 Database volume already exists with $MIGRATION_RECORDS migrations"
        echo "⏭️  Skipping migrations (database already initialized)"
        echo ""
        SKIP_MIGRATIONS=true
      else
        SKIP_MIGRATIONS=false
      fi
    else
      SKIP_MIGRATIONS=false
    fi
    
    # Run migrations only if not skipped
    if [ "$SKIP_MIGRATIONS" != "true" ]; then
      echo "🔄 Running database migrations..."
      if [ "${FRESH_INSTALL}" = "true" ]; then
        echo "⚠️  Fresh install mode - dropping all tables..."
        if su -s /bin/bash www-data -c "php artisan migrate:fresh --force"; then
          echo "✅ Fresh migrations completed"
        else
          echo "❌ Fresh migrations failed"
          exit 1
        fi
      else
        if su -s /bin/bash www-data -c "php artisan migrate --force"; then
          echo "✅ Migrations completed"
        else
          echo "❌ Migrations failed"
          exit 1
        fi
      fi
      echo ""
    fi
    
    # Run seeders
    if [ "${AUTO_SEED}" = "true" ] || [ "${AUTO_SEED}" = "1" ]; then
      echo "🌱 Running database seeders..."
      su -s /bin/bash www-data -c "php artisan db:seed --force"
      echo "✅ Seeders completed"
      echo ""
    fi
    
    # Optimize application
    echo "⚡ Optimizing application..."
    su -s /bin/bash www-data -c "php artisan config:cache"
    su -s /bin/bash www-data -c "php artisan route:cache"
    su -s /bin/bash www-data -c "php artisan view:cache"
    echo "✅ Optimization completed"
    echo ""
    
    # Create storage link
    echo "🔗 Creating storage link..."
    # Ensure public directory exists and has correct permissions
    mkdir -p /var/www/html/public
    chown -R www-data:www-data /var/www/html/public
    chmod -R 775 /var/www/html/public
    
    # Remove existing symlink if it exists
    if [ -L /var/www/html/public/storage ]; then
        rm -f /var/www/html/public/storage
    fi
    
    # Create storage link as www-data user
    su -s /bin/bash www-data -c "php artisan storage:link" || echo "⚠️  Storage link creation failed (may already exist)"
    echo ""
  fi
fi

echo "✅ Backend initialization complete!"
echo "🎉 Starting services..."
echo ""

exec "$@"
