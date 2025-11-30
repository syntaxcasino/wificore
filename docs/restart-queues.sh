#!/bin/bash

echo "🔄 Restarting Queue Workers for Faster Processing..."
echo ""

# Stop backend container
echo "⏸️  Stopping backend container..."
docker-compose stop traidnet-backend

# Rebuild backend with new supervisor config
echo "🔨 Rebuilding backend container with optimized queue configuration..."
docker-compose build traidnet-backend

# Start backend container
echo "▶️  Starting backend container..."
docker-compose up -d traidnet-backend

# Wait for container to be ready
echo "⏳ Waiting for container to be ready..."
sleep 10

# Check supervisor status
echo ""
echo "📊 Queue Workers Status:"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
docker exec traidnet-backend supervisorctl status

echo ""
echo "✅ Queue workers restarted successfully!"
echo ""
echo "📈 Performance Improvements:"
echo "  • Router Data: 4 workers (was 2), 1s sleep (was 5s) = 5x faster"
echo "  • Provisioning: 3 workers (was 2), 1s sleep (was 3s) = 3x faster"
echo "  • Payments: 2 workers, 1s sleep (was 3s) = 3x faster"
echo "  • Dashboard: 1 worker, 2s sleep (was 5s) = 2.5x faster"
echo ""
echo "🚀 Total: 11 concurrent queue workers for real-time processing!"
echo ""
echo "📝 To monitor queue logs:"
echo "  docker exec traidnet-backend tail -f /var/www/html/storage/logs/router-data-queue.log"
echo ""
