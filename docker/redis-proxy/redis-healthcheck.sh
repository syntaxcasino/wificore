#!/bin/sh
set -eu

STATS_RESPONSE=$(printf 'GET /stats;csv HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n' | nc -w 2 localhost 8404 || true)

printf '%s\n' "$STATS_RESPONSE" | awk -F, '
  $1 == "redis_master" && $2 ~ /^redis-/ && $18 == "UP" { found = 1 }
  END { exit(found ? 0 : 1) }
'
