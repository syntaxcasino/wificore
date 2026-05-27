#!/bin/sh
set -eu

PASSWORD="${REDIS_PASSWORD:-}"
if [ -n "$PASSWORD" ]; then
  AUTH_CHECK="    tcp-check send AUTH ${PASSWORD}\\r\\n
    tcp-check expect string +OK"
else
  AUTH_CHECK="    # no auth configured"
fi

export AUTH_CHECK
envsubst '\${AUTH_CHECK}' < /usr/local/etc/haproxy/haproxy.cfg.template > /usr/local/etc/haproxy/haproxy.cfg
exec haproxy -W -db -f /usr/local/etc/haproxy/haproxy.cfg
