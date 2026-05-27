#!/bin/sh
set -eu

PASSWORD="${REDIS_PASSWORD:-}"

# Generate the AUTH check lines
if [ -n "$PASSWORD" ]; then
  AUTH_LINES="    tcp-check send AUTH ${PASSWORD}\\r\\n
    tcp-check expect string +OK"
else
  AUTH_LINES="    # no auth configured"
fi

# Generate HAProxy config
{
  echo "global"
  echo "    log stdout format raw local0"
  echo "    maxconn 20000"
  echo ""
  echo "defaults"
  echo "    log global"
  echo "    mode tcp"
  echo "    option tcplog"
  echo "    timeout connect 5s"
  echo "    timeout client  2m"
  echo "    timeout server  2m"
  echo "    default-server inter 2s fall 3 rise 2 on-marked-down shutdown-sessions"
  echo ""
  echo "frontend redis_frontend"
  echo "    bind *:6379"
  echo "    default_backend redis_master"
  echo ""
  echo "backend redis_master"
  echo "    option tcp-check"
  printf '%s\n' "$AUTH_LINES"
  echo "    tcp-check send PING\\r\\n"
  echo "    tcp-check expect string +PONG"
  echo "    tcp-check send INFO\\ replication\\r\\n"
  echo "    tcp-check expect string role:master"
  echo "    tcp-check send QUIT\\r\\n"
  echo "    tcp-check expect string +OK"
  echo "    server redis-primary wificore-redis-primary:6379 check"
  echo "    server redis-replica wificore-redis-replica:6379 check"
  echo ""
  echo "listen stats"
  echo "    bind *:8404"
  echo "    mode http"
  echo "    stats enable"
  echo "    stats uri /stats"
  echo "    stats refresh 10s"
} > /usr/local/etc/haproxy/haproxy.cfg

exec haproxy -W -db -f /usr/local/etc/haproxy/haproxy.cfg
