FROM haproxy:2.9-alpine
USER root
RUN apk add --no-cache busybox-extras
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY redis-healthcheck.sh /usr/local/bin/redis-healthcheck.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh /usr/local/bin/redis-healthcheck.sh
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
