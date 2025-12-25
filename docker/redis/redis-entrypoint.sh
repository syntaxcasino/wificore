#!/bin/sh
set -e

# Build Redis command with conditional password
REDIS_CMD="redis-server"

# Add password if REDIS_PASSWORD is set and not empty
if [ -n "$REDIS_PASSWORD" ]; then
    REDIS_CMD="$REDIS_CMD --requirepass $REDIS_PASSWORD"
fi

# Add other Redis configurations
REDIS_CMD="$REDIS_CMD --maxmemory ${REDIS_MAXMEMORY:-512mb}"
REDIS_CMD="$REDIS_CMD --maxmemory-policy allkeys-lru"
REDIS_CMD="$REDIS_CMD --save 60 1000"
REDIS_CMD="$REDIS_CMD --appendonly yes"

# Execute Redis with the built command
exec $REDIS_CMD
