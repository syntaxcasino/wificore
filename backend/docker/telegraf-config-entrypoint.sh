#!/bin/sh
set -e

echo "🔧 Starting Telegraf Config Generator..."
echo ""

OUT_DIR="/var/www/html/storage/app/telegraf/shards"
OUT_FILE="${OUT_DIR}/${TELEGRAF_SHARD_INDEX:-0}.conf"
mkdir -p "$OUT_DIR"

# Create base telegraf config if it doesn't exist
if [ ! -f "$OUT_FILE" ]; then
  cat > "$OUT_FILE" <<EOF
[agent]
interval = "${TELEGRAF_FAST_INTERVAL:-3s}"
round_interval = true
metric_batch_size = 1000
metric_buffer_limit = 50000
flush_interval = "1s"
flush_jitter = "0s"
collection_jitter = "0s"
precision = ""
hostname = ""
omit_hostname = true

[[inputs.internal]]
interval = "${TELEGRAF_SLOW_INTERVAL:-30s}"

[[outputs.influxdb]]
urls = ["${VICTORIA_METRICS_WRITE_URL:-http://wificore-victoriametrics:8428}"]
database = "telegraf"
skip_database_creation = true
timeout = "5s"
content_encoding = "gzip"
EOF
fi

# Ensure inputs section exists
if ! grep -q '^\[\[inputs\.' "$OUT_FILE" 2>/dev/null; then
  cat >> "$OUT_FILE" <<EOF

[[inputs.internal]]
interval = "${TELEGRAF_SLOW_INTERVAL:-30s}"
EOF
fi

# Wait for database and core migrations
while true; do
  export PGPASSWORD="$DB_PASSWORD"
  HOST="$DB_HOST"
  PORT="$DB_PORT"
  
  # Try PgBouncer first
  if ! psql -h "$HOST" -p "$PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" -tAc "select 1" >/dev/null 2>&1; then
    # Fallback to direct postgres
    if [ -n "$DB_DIRECT_HOST" ]; then
      HOST="$DB_DIRECT_HOST"
      PORT="$DB_DIRECT_PORT"
      if ! psql -h "$HOST" -p "$PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" -tAc "select 1" >/dev/null 2>&1; then
        echo "⏳ Waiting for database..."
        sleep 5
        continue
      fi
    else
      echo "⏳ Waiting for database..."
      sleep 5
      continue
    fi
  fi

  # Check if tenants table exists (core migrations complete)
  TENANTS_TABLE=$(psql -h "$HOST" -p "$PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" -tAc "select 1 from information_schema.tables where table_schema='public' and table_name='tenants';" 2>/dev/null | tr -d '[:space:]')
  if [ "$TENANTS_TABLE" != "1" ]; then
    echo "⏳ Waiting for core migrations (tenants table)..."
    sleep 10
    continue
  fi

  # Database is ready, generate config
  echo "✅ Database ready, generating Telegraf config..."
  php artisan telegraf:generate-config \
    --shard-index="${TELEGRAF_SHARD_INDEX:-0}" \
    --shard-count="${TELEGRAF_SHARD_COUNT:-1}" \
    --output-dir=/var/www/html/storage/app/telegraf/shards || true
  
  echo "✅ Config generated, sleeping for 5 minutes before next update..."
  sleep 300
done
