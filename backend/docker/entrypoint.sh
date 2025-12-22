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
    
    # Run migrations
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
    su -s /bin/bash www-data -c "php artisan storage:link" || echo "Storage link already exists"
    echo ""
  fi
fi

echo "✅ Backend initialization complete!"
echo "🎉 Starting services..."
echo ""

exec "$@"
