#!/bin/sh
set -eu

PASSWORD="${REDIS_PASSWORD:-}"
PASSWORD=$(printf '%s' "$PASSWORD" | tr -d '\r' | sed 's/[[:space:]]*$//')

probe() {
  if [ -n "$PASSWORD" ]; then
    printf 'AUTH %s\r\nPING\r\nQUIT\r\n' "$PASSWORD" | nc -w 2 localhost 6379 | grep -q PONG
  else
    printf 'PING\r\nQUIT\r\n' | nc -w 2 localhost 6379 | grep -q PONG
  fi
}

attempt=1
while [ "$attempt" -le 5 ]; do
  if probe; then
    exit 0
  fi
  sleep 1
  attempt=$((attempt + 1))
done

exit 1
