#!/bin/bash
# Deploy backend router creation fix to production

echo "=== Deploying Backend Router Creation Fix ==="
echo ""

cd /opt/wificore

echo "1. Pulling latest code..."
git pull origin main

echo ""
echo "2. Rebuilding backend container (includes RouterController fix)..."
docker compose -f docker-compose.production.yml build wificore-backend --no-cache

echo ""
echo "3. Restarting backend..."
docker compose -f docker-compose.production.yml up -d wificore-backend

echo ""
echo "4. Waiting for backend to be ready..."
sleep 15

echo ""
echo "5. Checking backend logs for errors..."
docker compose -f docker-compose.production.yml logs wificore-backend --tail=50 | grep -i error || echo "No errors found"

echo ""
echo "=== Deployment Complete ==="
echo ""
echo "✅ Backend has been rebuilt with the router creation fix"
echo "✅ The null tenant error should now be resolved"
echo ""
echo "Test by creating a new router in the web interface"
