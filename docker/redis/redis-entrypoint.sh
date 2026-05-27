#!/bin/sh
set -eu

ROLE="${REDIS_ROLE:-primary}"
DATA_DIR="${REDIS_DATA_DIR:-/data}"
PORT="${REDIS_PORT:-6379}"
PASSWORD="${REDIS_PASSWORD:-}"
MAXMEMORY="${REDIS_MAXMEMORY:-512mb}"
MASTER_HOST="${REDIS_MASTER_HOST:-wificore-redis-primary}"
MASTER_PORT="${REDIS_MASTER_PORT:-6379}"
SENTINEL_PORT="${REDIS_SENTINEL_PORT:-26379}"
SENTINEL_MASTER_NAME="${REDIS_SENTINEL_MASTER_NAME:-wificore-redis}"
SENTINEL_QUORUM="${REDIS_SENTINEL_QUORUM:-2}"
SENTINEL_DOWN_AFTER="${REDIS_SENTINEL_DOWN_AFTER:-5000}"
SENTINEL_FAILOVER_TIMEOUT="${REDIS_SENTINEL_FAILOVER_TIMEOUT:-60000}"
SENTINEL_PARALLEL_SYNCS="${REDIS_SENTINEL_PARALLEL_SYNCS:-1}"

mkdir -p "$DATA_DIR"

write_common_config() {
  local target="$1"
  cat > "$target" <<EOF
bind 0.0.0.0
protected-mode no
port ${PORT}
dir ${DATA_DIR}
daemonize no
appendonly yes
appendfsync everysec
save 60 1000
auto-aof-rewrite-percentage 100
auto-aof-rewrite-min-size 64mb
maxmemory ${MAXMEMORY}
maxmemory-policy allkeys-lru
loglevel notice
latency-monitor-threshold 100
repl-diskless-sync yes
repl-diskless-sync-delay 5
EOF

  if [ -n "$PASSWORD" ]; then
    cat >> "$target" <<EOF
requirepass ${PASSWORD}
masterauth ${PASSWORD}
EOF
  fi
}

case "$ROLE" in
  primary)
    CONF="$DATA_DIR/redis-primary.conf"
    if [ ! -f "$CONF" ]; then
      write_common_config "$CONF"
    fi
    exec redis-server "$CONF"
    ;;
  replica)
    CONF="$DATA_DIR/redis-replica.conf"
    if [ ! -f "$CONF" ]; then
      write_common_config "$CONF"
      cat >> "$CONF" <<EOF
replicaof ${MASTER_HOST} ${MASTER_PORT}
EOF
    fi
    exec redis-server "$CONF"
    ;;
  sentinel)
    CONF="$DATA_DIR/sentinel.conf"
    if [ ! -f "$CONF" ]; then
      cat > "$CONF" <<EOF
bind 0.0.0.0
protected-mode no
port ${SENTINEL_PORT}
dir ${DATA_DIR}
sentinel monitor ${SENTINEL_MASTER_NAME} ${MASTER_HOST} ${MASTER_PORT} ${SENTINEL_QUORUM}
sentinel down-after-milliseconds ${SENTINEL_MASTER_NAME} ${SENTINEL_DOWN_AFTER}
sentinel failover-timeout ${SENTINEL_MASTER_NAME} ${SENTINEL_FAILOVER_TIMEOUT}
sentinel parallel-syncs ${SENTINEL_MASTER_NAME} ${SENTINEL_PARALLEL_SYNCS}
sentinel resolve-hostnames yes
EOF
      if [ -n "$PASSWORD" ]; then
        cat >> "$CONF" <<EOF
sentinel auth-pass ${SENTINEL_MASTER_NAME} ${PASSWORD}
EOF
      fi
    fi
    exec redis-server "$CONF" --sentinel
    ;;
  *)
    echo "Unsupported REDIS_ROLE: $ROLE" >&2
    exit 1
    ;;
esac
