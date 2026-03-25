#!/bin/bash

# Redis Fix Deployment Script for Production
# This script deploys the custom Redis image with conditional password support

set -e

echo "=========================================="
echo "Redis Fix Deployment Script"
echo "=========================================="
echo ""

# Check if we're in the correct directory
if [ ! -f "docker-compose.production.yml" ]; then
    echo "Error: docker-compose.production.yml not found!"
    echo "Please run this script from /opt/wificore directory"
    exit 1
fi

# Check if .env.production exists
if [ ! -f ".env.production" ]; then
    echo "Error: .env.production not found!"
    exit 1
fi

echo "Step 1: Pulling latest code from repository..."
git pull origin main

echo ""
echo "Step 2: Checking .env.production for REDIS_PASSWORD..."
if grep -q "REDIS_PASSWORD=Redis#\$ts_2026" .env.production; then
    echo "✓ Redis password is set correctly"
else
    echo "⚠ Warning: REDIS_PASSWORD not set to expected value"
    echo "Current value:"
    grep "REDIS_PASSWORD=" .env.production || echo "REDIS_PASSWORD not found"
    echo ""
    read -p "Do you want to continue anyway? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

echo ""
echo "Step 3: Stopping all containers..."
docker compose -f docker-compose.production.yml down

echo ""
echo "Step 4: Building custom Redis image..."
docker compose -f docker-compose.production.yml build wificore-redis

echo ""
echo "Step 5: Starting all services..."
docker compose -f docker-compose.production.yml up -d

echo ""
echo "Step 6: Waiting for services to start (30 seconds)..."
sleep 30

echo ""
echo "Step 7: Checking Redis status..."
if docker exec wificore-redis redis-cli -a "Redis#\$ts_2026" --no-auth-warning ping > /dev/null 2>&1; then
    echo "✓ Redis is running and accepting connections with password"
else
    echo "✗ Redis connection test failed"
    echo "Checking Redis logs..."
    docker compose -f docker-compose.production.yml logs wificore-redis --tail=20
    exit 1
fi

echo ""
echo "Step 8: Checking all service health..."
docker compose -f docker-compose.production.yml ps

echo ""
echo "=========================================="
echo "Deployment Complete!"
echo "=========================================="
echo ""
echo "Redis is now running with password authentication"
echo "Password: Redis#\$ts_2026"
echo ""
echo "Next steps:"
echo "1. Test tenant registration at https://wificore.traidsolutions.com/register"
echo "2. Monitor logs: docker compose -f docker-compose.production.yml logs -f"
echo "3. Check backend can connect to Redis:"
echo "   docker exec wificore-backend php artisan tinker --execute=\"Redis::ping();\""
echo ""
