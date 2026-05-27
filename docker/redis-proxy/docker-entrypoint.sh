#!/bin/sh
set -eu

# Trim trailing carriage returns and whitespace from password
PASSWORD="${REDIS_PASSWORD:-}"
PASSWORD=$(printf '%s' "$PASSWORD" | tr -d '\r' | sed 's/[[:space:]]*$//')

redis_inline_hex() {
  # Encode stdin as hex so HAProxy tcp-check parsing is not broken by
  # spaces or shell-sensitive password characters.
  od -An -tx1 -v | tr -d ' \n'
}

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
  echo "    timeout client  5m"
  echo "    timeout server  5m"
  echo "    default-server inter 2s fall 3 rise 2"
  echo ""
  echo "frontend redis_frontend"
  echo "    bind *:6379"
  echo "    default_backend redis_master"
  echo ""
  # Backend for master (primary) - expects role:master
  echo "backend redis_master"
  echo "    option tcp-check"
  if [ -n "$PASSWORD" ]; then
    printf '    tcp-check send-binary %s\n' "$(printf 'AUTH %s\r\n' "$PASSWORD" | redis_inline_hex)"
    printf '    tcp-check expect string +OK\n'
  else
    echo "    # no auth configured"
  fi
  printf '    tcp-check send-binary %s\n' "$(printf 'PING\r\n' | redis_inline_hex)"
  echo "    tcp-check expect string +PONG"
  printf '    tcp-check send-binary %s\n' "$(printf 'INFO replication\r\n' | redis_inline_hex)"
  echo "    tcp-check expect string role:master"
  printf '    tcp-check send-binary %s\n' "$(printf 'QUIT\r\n' | redis_inline_hex)"
  echo "    tcp-check expect string +OK"
  echo "    server redis-primary wificore-redis-primary:6379 check"
  echo ""
  # Backend for replica - expects role:slave
  echo "backend redis_replica"
  echo "    option tcp-check"
  if [ -n "$PASSWORD" ]; then
    printf '    tcp-check send-binary %s\n' "$(printf 'AUTH %s\r\n' "$PASSWORD" | redis_inline_hex)"
    printf '    tcp-check expect string +OK\n'
  else
    echo "    # no auth configured"
  fi
  printf '    tcp-check send-binary %s\n' "$(printf 'PING\r\n' | redis_inline_hex)"
  echo "    tcp-check expect string +PONG"
  printf '    tcp-check send-binary %s\n' "$(printf 'INFO replication\r\n' | redis_inline_hex)"
  echo "    tcp-check expect string role:slave"
  printf '    tcp-check send-binary %s\n' "$(printf 'QUIT\r\n' | redis_inline_hex)"
  echo "    tcp-check expect string +OK"
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
