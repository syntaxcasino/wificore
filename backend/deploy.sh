#!/bin/bash

###############################################################################
# Deployment Script - WiFi Hotspot Management System
# Automatically runs migrations and seeders after build
###############################################################################

set -e  # Exit on error

echo "🚀 Starting deployment..."
echo ""

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Environment detection
ENVIRONMENT=${APP_ENV:-production}
echo "📍 Environment: $ENVIRONMENT"
echo ""

###############################################################################
# 1. WAIT FOR DATABASE
###############################################################################
echo "⏳ Waiting for database to be ready..."

MAX_TRIES=30
TRIES=0

until php artisan db:show > /dev/null 2>&1 || [ $TRIES -eq $MAX_TRIES ]; do
    TRIES=$((TRIES+1))
    echo "   Attempt $TRIES/$MAX_TRIES..."
    sleep 2
done

if [ $TRIES -eq $MAX_TRIES ]; then
    echo -e "${RED}❌ Database connection failed after $MAX_TRIES attempts${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Database is ready${NC}"
echo ""

###############################################################################
# 2. RUN MIGRATIONS
###############################################################################
echo "🔄 Running database migrations..."

if [ "$ENVIRONMENT" = "production" ]; then
    # Production: Run migrations without prompts
    php artisan migrate --force
else
    # Development/Staging: Run fresh migrations with seeders
    if [ "$FRESH_INSTALL" = "true" ]; then
        echo -e "${YELLOW}⚠️  Running fresh migrations (will drop all tables)${NC}"
        php artisan migrate:fresh --force
    else
        php artisan migrate --force
    fi
fi

echo -e "${GREEN}✅ Migrations completed${NC}"
echo ""

###############################################################################
# 3. RUN SEEDERS
###############################################################################
echo "🌱 Running database seeders..."

if [ "$ENVIRONMENT" = "production" ]; then
    # Production: Only run essential seeders
    php artisan db:seed --class=DefaultTenantSeeder --force
    php artisan db:seed --class=DefaultSystemAdminSeeder --force
    echo -e "${YELLOW}ℹ️  Demo data seeder skipped in production${NC}"
else
    # Development/Staging: Run all seeders including demo data
    php artisan db:seed --force
fi

echo -e "${GREEN}✅ Seeders completed${NC}"
echo ""

###############################################################################
# 4. CACHE OPTIMIZATION
###############################################################################
echo "⚡ Optimizing application..."

php artisan config:cache
php artisan route:cache
php artisan view:cache

echo -e "${GREEN}✅ Optimization completed${NC}"
echo ""

###############################################################################
# 5. STORAGE LINK
###############################################################################
echo "🔗 Creating storage link..."

php artisan storage:link || echo "Storage link already exists"

echo ""

###############################################################################
# 6. PERMISSIONS
###############################################################################
echo "🔐 Setting permissions..."

chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache || echo "Could not change ownership (running as non-root)"

echo -e "${GREEN}✅ Permissions set${NC}"
echo ""

###############################################################################
# DEPLOYMENT COMPLETE
###############################################################################
echo ""
echo -e "${GREEN}═══════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}✅ Deployment completed successfully!${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════════${NC}"
echo ""

if [ "$ENVIRONMENT" != "production" ]; then
    echo "📝 Demo Accounts Created:"
    echo "   System Admin: sysadmin@system.local / Admin@123!"
    echo "   Tenant A Admin: admin-a@tenant-a.com / Password123!"
    echo "   Tenant B Admin: admin-b@tenant-b.com / Password123!"
    echo ""
    echo -e "${YELLOW}⚠️  IMPORTANT: Change default passwords immediately!${NC}"
    echo ""
fi

echo "🎉 Application is ready!"
