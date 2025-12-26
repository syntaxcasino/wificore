#!/bin/bash
# ============================================================================
# Partition Maintenance Script
# ============================================================================
# This script runs pg_partman maintenance to create new partitions and
# drop old ones. Can be run via cron, systemd timer, or manually.
# ============================================================================

set -e

# Configuration
POSTGRES_HOST="${POSTGRES_HOST:-wificore-postgres}"
POSTGRES_PORT="${POSTGRES_PORT:-5432}"
POSTGRES_DB="${POSTGRES_DB:-wms_770_ts}"
POSTGRES_USER="${POSTGRES_USER:-admin}"
POSTGRES_PASSWORD="${POSTGRES_PASSWORD}"

# Logging
LOG_FILE="${LOG_FILE:-/var/log/partition-maintenance.log}"
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

log() {
    echo "[$TIMESTAMP] $1" | tee -a "$LOG_FILE"
}

# Check if PostgreSQL is accessible
if ! PGPASSWORD="$POSTGRES_PASSWORD" psql -h "$POSTGRES_HOST" -p "$POSTGRES_PORT" -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT 1" > /dev/null 2>&1; then
    log "ERROR: Cannot connect to PostgreSQL"
    exit 1
fi

log "Starting partition maintenance..."

# Run pg_partman maintenance
PGPASSWORD="$POSTGRES_PASSWORD" psql -h "$POSTGRES_HOST" -p "$POSTGRES_PORT" -U "$POSTGRES_USER" -d "$POSTGRES_DB" <<EOF
-- Run partition maintenance
SELECT partman.run_maintenance_proc();

-- Update statistics
ANALYZE;

-- Show partition status
SELECT 
    parent_table,
    partition_interval,
    premake,
    retention,
    (SELECT count(*) FROM pg_inherits WHERE inhparent = parent_table::regclass) as partition_count
FROM partman.part_config
ORDER BY parent_table;
EOF

if [ $? -eq 0 ]; then
    log "Partition maintenance completed successfully"
    exit 0
else
    log "ERROR: Partition maintenance failed"
    exit 1
fi
