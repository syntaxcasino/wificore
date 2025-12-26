FROM redis:alpine

# Copy custom entrypoint and healthcheck scripts
COPY redis-entrypoint.sh /usr/local/bin/
COPY redis-healthcheck.sh /usr/local/bin/

# Make scripts executable
RUN chmod +x /usr/local/bin/redis-entrypoint.sh && \
    chmod +x /usr/local/bin/redis-healthcheck.sh

# Use custom entrypoint
ENTRYPOINT ["/usr/local/bin/redis-entrypoint.sh"]
