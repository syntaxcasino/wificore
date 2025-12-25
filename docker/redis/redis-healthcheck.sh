#!/bin/sh

# Health check script that conditionally uses password
if [ -n "$REDIS_PASSWORD" ]; then
    redis-cli --no-auth-warning -a "$REDIS_PASSWORD" ping
else
    redis-cli ping
fi
