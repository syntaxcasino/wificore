#!/bin/sh
set -eu

PASSWORD="${REDIS_PASSWORD:-}"
PASSWORD=$(printf '%s' "$PASSWORD" | tr -d '\r' | sed 's/[[:space:]]*$//')

if [ -n "$PASSWORD" ]; then
  printf 'AUTH %s\r\nPING\r\n' "$PASSWORD" | nc -w 2 localhost 6379 | grep -q PONG
else
  printf 'PING\r\n' | nc -w 2 localhost 6379 | grep -q PONG
fi
