#!/bin/sh
set -e

# Generate userlist.txt with password from environment
if [ -n "$DB_PASSWORD" ]; then
    # For SCRAM-SHA-256 authentication, PgBouncer requires plain text passwords
    # Format: "username" "password"
    echo "\"${DB_USERNAME}\" \"${DB_PASSWORD}\"" > /etc/pgbouncer/userlist.txt
    chmod 600 /etc/pgbouncer/userlist.txt
fi

# Update pgbouncer.ini with database connection details
sed -i "s/host=wificore-postgres /host=${DB_HOST:-wificore-postgres} /g" /etc/pgbouncer/pgbouncer.ini
sed -i "s/port=5432/port=${DB_PORT:-5432}/g" /etc/pgbouncer/pgbouncer.ini
sed -i "s/dbname=wms_770_ts/dbname=${DB_DATABASE:-wms_770_ts}/g" /etc/pgbouncer/pgbouncer.ini
sed -i "s/pool_mode=__APP_POOL_MODE__/pool_mode=${DB_APP_POOL_MODE:-transaction}/g" /etc/pgbouncer/pgbouncer.ini
sed -i "s/__DEFAULT_POOL_MODE__/${DB_APP_POOL_MODE:-transaction}/g" /etc/pgbouncer/pgbouncer.ini
sed -i "s/__DEFAULT_POOL_SIZE__/${PGBOUNCER_DEFAULT_POOL_SIZE:-20}/g" /etc/pgbouncer/pgbouncer.ini
sed -i "s/__MIN_POOL_SIZE__/${PGBOUNCER_MIN_POOL_SIZE:-5}/g" /etc/pgbouncer/pgbouncer.ini
sed -i "s/__MAX_DB_CONNECTIONS__/${PGBOUNCER_MAX_DB_CONNECTIONS:-60}/g" /etc/pgbouncer/pgbouncer.ini
sed -i "s/__MAX_USER_CONNECTIONS__/${PGBOUNCER_MAX_USER_CONNECTIONS:-60}/g" /etc/pgbouncer/pgbouncer.ini
sed -i "s/__RESERVE_POOL_SIZE__/${PGBOUNCER_RESERVE_POOL_SIZE:-10}/g" /etc/pgbouncer/pgbouncer.ini

# Start PgBouncer
PGBOUNCER_BIN="$(command -v pgbouncer || true)"
if [ -z "$PGBOUNCER_BIN" ]; then
    if [ -x /usr/local/bin/pgbouncer ]; then
        PGBOUNCER_BIN=/usr/local/bin/pgbouncer
    elif [ -x /usr/sbin/pgbouncer ]; then
        PGBOUNCER_BIN=/usr/sbin/pgbouncer
    elif [ -x /usr/bin/pgbouncer ]; then
        PGBOUNCER_BIN=/usr/bin/pgbouncer
    else
        echo "pgbouncer binary not found in PATH or expected locations" >&2
        exit 1
    fi
fi
exec "$PGBOUNCER_BIN" /etc/pgbouncer/pgbouncer.ini
