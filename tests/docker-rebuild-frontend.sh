#!/bin/bash

# Rebuild Frontend Container with New Components

echo "=========================================="
echo "Rebuilding Frontend Container"
echo "=========================================="
echo ""

echo "This will rebuild the frontend container with all new components."
echo "This may take a few minutes..."
echo ""

# Stop frontend container
echo "Stopping frontend container..."
docker-compose stop traidnet-frontend

# Rebuild frontend
echo "Rebuilding frontend..."
docker-compose build --no-cache traidnet-frontend

# Start frontend
echo "Starting frontend..."
docker-compose up -d traidnet-frontend

# Wait for it to be healthy
echo ""
echo "Waiting for frontend to be ready..."
sleep 10

# Check health
for i in {1..30}; do
    if curl -s -o /dev/null -w "%{http_code}" http://localhost | grep -q "200"; then
        echo ""
        echo "✅ Frontend is ready!"
        echo ""
        echo "You can now access:"
        echo "  http://localhost"
        echo ""
        exit 0
    fi
    echo -n "."
    sleep 2
done

echo ""
echo "⚠️  Frontend is taking longer than expected to start"
echo "Check logs with: docker-compose logs traidnet-frontend"
