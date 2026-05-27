#!/bin/sh
set -eu

STATS_RESPONSE=$(printf 'GET /stats;csv HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n' | nc -w 2 127.0.0.1 8404 || true)

printf '%s\n' "$STATS_RESPONSE" | awk -F, '
  $1 == "redis_master" && $2 ~ /^redis-/ && $18 == "UP" { found = 1 }
  END { exit(found ? 0 : 1) }
'
