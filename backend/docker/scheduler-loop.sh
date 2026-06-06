#!/bin/sh
set -eu

cd /var/www/html

: "${CLI_MEMORY_LIMIT:=384M}"

echo "[scheduler-loop] starting with CLI_MEMORY_LIMIT=${CLI_MEMORY_LIMIT}"

while true; do
    started_at="$(date -Iseconds)"
    echo "[scheduler-loop] schedule:run started at ${started_at}"

    /usr/local/bin/php -d "memory_limit=${CLI_MEMORY_LIMIT}" artisan schedule:run --no-interaction || true

    ended_at="$(date -Iseconds)"
    echo "[scheduler-loop] schedule:run ended at ${ended_at}"

    sleep 1
done
