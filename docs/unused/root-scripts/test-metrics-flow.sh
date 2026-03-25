#!/bin/bash
# Test script to diagnose router metrics issue end-to-end

echo "=========================================="
echo "Router Metrics Diagnostic Test"
echo "=========================================="
echo ""

# 1. Check if VictoriaMetrics is running
echo "1. Checking VictoriaMetrics container status..."
docker compose -f docker-compose.production.yml ps wificore-victoriametrics
echo ""

# 2. Check VictoriaMetrics health
echo "2. Testing VictoriaMetrics API..."
docker compose -f docker-compose.production.yml exec wificore-backend curl -s "http://wificore-victoriametrics:8428/api/v1/query?query=up" | jq '.'
echo ""

# 3. Check if router metrics exist in VictoriaMetrics
echo "3. Checking for router_health_cpu_load metrics..."
docker compose -f docker-compose.production.yml exec wificore-backend curl -s "http://wificore-victoriametrics:8428/api/v1/query?query=router_health_cpu_load" | jq '.data.result | length'
echo ""

# 4. Get a sample router ID from database
echo "4. Getting sample router ID from database..."
ROUTER_ID=$(docker compose -f docker-compose.production.yml exec -T wificore-postgres psql -U wificore -d wificore -t -c "SELECT id FROM public.tenants LIMIT 1;" | tr -d ' ')
TENANT_SCHEMA="ts_${ROUTER_ID}"
echo "Tenant schema: $TENANT_SCHEMA"

SAMPLE_ROUTER=$(docker compose -f docker-compose.production.yml exec -T wificore-postgres psql -U wificore -d wificore -t -c "SELECT id FROM ${TENANT_SCHEMA}.routers LIMIT 1;" | tr -d ' ')
echo "Sample router ID: $SAMPLE_ROUTER"
echo ""

# 5. Test the metrics API endpoint
if [ -n "$SAMPLE_ROUTER" ]; then
    echo "5. Testing /api/routers/{router}/metrics/live endpoint..."
    docker compose -f docker-compose.production.yml exec wificore-backend curl -s \
        -H "Authorization: Bearer YOUR_TOKEN_HERE" \
        "http://localhost/api/routers/${SAMPLE_ROUTER}/metrics/live" | jq '.'
    echo ""
fi

# 6. Check Telegraf logs for errors
echo "6. Checking Telegraf logs (last 20 lines)..."
docker compose -f docker-compose.production.yml logs --tail=20 wificore-telegraf-collector
echo ""

# 7. Check if Telegraf is writing to VictoriaMetrics
echo "7. Checking recent metrics in VictoriaMetrics..."
docker compose -f docker-compose.production.yml exec wificore-backend curl -s \
    "http://wificore-victoriametrics:8428/api/v1/query?query=router_health_cpu_load&time=$(date +%s)" | jq '.data.result[0]'
echo ""

echo "=========================================="
echo "Diagnostic test complete!"
echo "=========================================="
