#!/bin/sh
set -e

# Generate userlist.txt with password from environment
if [ -n "$DB_PASSWORD" ]; then
    # Generate MD5 hash for PgBouncer
    # Format: "username" "md5" + md5(password + username)
    PASSWORD_HASH=$(echo -n "${DB_PASSWORD}${DB_USERNAME}" | md5sum | awk '{print $1}')
    echo "\"${DB_USERNAME}\" \"md5${PASSWORD_HASH}\"" > /etc/pgbouncer/userlist.txt
    chmod 600 /etc/pgbouncer/userlist.txt
fi

# Update pgbouncer.ini with database connection details
sed -i "s/host=wificore-postgres/host=${DB_HOST:-wificore-postgres}/g" /etc/pgbouncer/pgbouncer.ini
sed -i "s/port=5432/port=${DB_PORT:-5432}/g" /etc/pgbouncer/pgbouncer.ini
sed -i "s/dbname=wms_770_ts/dbname=${DB_DATABASE:-wms_770_ts}/g" /etc/pgbouncer/pgbouncer.ini

# Start PgBouncer
exec /usr/bin/pgbouncer /etc/pgbouncer/pgbouncer.ini
