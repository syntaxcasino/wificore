#!/bin/sh
set -eu

ROLE="${REDIS_ROLE:-primary}"
PASSWORD="${REDIS_PASSWORD:-}"
PORT="${REDIS_PORT:-6379}"
SENTINEL_PORT="${REDIS_SENTINEL_PORT:-26379}"

redis_ping() {
  if [ -n "$PASSWORD" ]; then
    redis-cli --no-auth-warning -a "$PASSWORD" -p "$1" ping
  else
    redis-cli -p "$1" ping
  fi
}

case "$ROLE" in
  primary|replica)
    redis_ping "$PORT" >/dev/null
    ;;
  sentinel)
    redis-cli -p "$SENTINEL_PORT" ping >/dev/null
    ;;
  *)
    redis_ping "$PORT" >/dev/null
    ;;
esac
