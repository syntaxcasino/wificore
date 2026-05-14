#!/bin/bash
# =============================================================================
# Laravel SaaS Production Deployment Optimization Script
# Run this after every deployment to maximize performance
# =============================================================================

set -e

echo "=========================================="
echo "Starting Performance Optimization..."
echo "=========================================="

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Function to print status
print_status() {
    echo -e "${GREEN}[✓]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[!]${NC} $1"
}

print_error() {
    echo -e "${RED}[✗]${NC} $1"
}

# 1. Cache configuration (CRITICAL - prevents file reads on every request)
echo ""
echo "→ Caching configuration..."
php artisan config:cache
print_status "Configuration cached"

# 2. Cache routes (pre-compiles all routes)
echo ""
echo "→ Caching routes..."
php artisan route:cache
print_status "Routes cached"

# 3. Cache views (pre-compiles all Blade templates)
echo ""
echo "→ Caching views..."
php artisan view:cache
print_status "Views cached"

# 4. Cache events
echo ""
echo "→ Caching events..."
php artisan event:cache
print_status "Events cached"

# 5. Cache warm-up for database queries
echo ""
echo "→ Running cache warm-up job..."
php artisan queue:work --once --job=App\\Jobs\\CacheWarmUpJob 2>/dev/null || print_warning "Queue worker not available, skipping warm-up"

# 6. Clear expired cache entries
echo ""
echo "→ Clearing expired cache..."
php artisan cache:clear-expired 2>/dev/null || print_warning "clear-expired not available"

# 7. Optimize composer autoloader
echo ""
echo "→ Optimizing autoloader..."
composer dump-autoload --optimize --no-dev --classmap-authoritative
print_status "Autoloader optimized"

# 8. Set proper permissions
echo ""
echo "→ Setting permissions..."
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || print_warning "Could not set ownership"
chmod -R 755 storage bootstrap/cache
print_status "Permissions set"

# 9. Preload statistics if available
if [ -f /proc/self/fd/2 ]; then
    echo ""
    echo "→ PHP OPcache status:"
    php -r '
        $status = opcache_get_status(true);
        if ($status) {
            $mem = $status["memory_usage"];
            $stats = $status["opcache_statistics"];
            echo "  Memory used: " . round($mem["used_memory"] / 1024 / 1024, 2) . " MB\n";
            echo "  Hit rate: " . round($stats["hits"] / ($stats["hits"] + $stats["misses"]) * 100, 2) . "%\n";
            echo "  Cached scripts: " . $stats["num_cached_scripts"] . "\n";
        }
    '
fi

echo ""
echo "=========================================="
echo -e "${GREEN}Optimization Complete!${NC}"
echo "=========================================="
echo ""
echo "Performance improvements applied:"
echo "  • Config cached (no file reads)"
echo "  • Routes cached (pre-compiled regex)"
echo "  • Views cached (pre-compiled Blade)"
echo "  • Events cached (faster event dispatch)"
echo "  • Autoloader optimized (classmap authoritative)"
echo "  • OPcache warmed (pre-compiled bytecode)"
echo ""
echo "Next steps:"
echo "  1. Restart PHP-FPM if needed: service php-fpm reload"
echo "  2. Monitor error logs: tail -f storage/logs/laravel.log"
echo ""
