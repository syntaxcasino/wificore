#!/bin/bash
# Script to check and diagnose APP_KEY issues in production

echo "==================================="
echo "APP_KEY Diagnostic Script"
echo "==================================="
echo ""

# Check if we're on the production server
if [ -d "/opt/wificore" ]; then
    WIFICORE_PATH="/opt/wificore"
    echo "✓ Running on production server"
else
    WIFICORE_PATH="."
    echo "⚠ Running on local machine"
fi

cd "$WIFICORE_PATH"

echo ""
echo "1. Checking current APP_KEY in .env.production:"
echo "-----------------------------------"
if [ -f ".env.production" ]; then
    grep "^APP_KEY=" .env.production | head -1
else
    echo "❌ .env.production not found!"
fi

echo ""
echo "2. Checking if .env.production.backup exists:"
echo "-----------------------------------"
if [ -f ".env.production.backup" ]; then
    echo "✓ Backup found"
    echo "Backup APP_KEY:"
    grep "^APP_KEY=" .env.production.backup | head -1
else
    echo "❌ No backup found"
fi

echo ""
echo "3. Checking recent git history for APP_KEY changes:"
echo "-----------------------------------"
git log --all --full-history -p -- .env.production | grep -A 2 -B 2 "APP_KEY" | head -20

echo ""
echo "4. Checking router password encryption in database:"
echo "-----------------------------------"
echo "Run this command to check encrypted passwords:"
echo "docker compose -f docker-compose.production.yml exec wificore-backend php artisan tinker"
echo "Then run: \App\Models\Router::first()->password"
echo ""

echo "==================================="
echo "SOLUTIONS:"
echo "==================================="
echo ""
echo "If APP_KEY has changed:"
echo ""
echo "Option A (Recommended): Restore original APP_KEY"
echo "  1. Copy the old APP_KEY from backup or git history"
echo "  2. Edit .env.production and replace current APP_KEY"
echo "  3. Run: docker compose -f docker-compose.production.yml restart"
echo ""
echo "Option B: Re-encrypt router passwords"
echo "  1. Create a migration to re-encrypt all router passwords"
echo "  2. This requires knowing the router's plain text password"
echo "  3. Not recommended if you don't have the original passwords"
echo ""
echo "Option C: Delete and recreate the router"
echo "  1. Delete the current router from the database"
echo "  2. Create a new router with the same configuration"
echo "  3. The new router will use the current APP_KEY"
echo ""
