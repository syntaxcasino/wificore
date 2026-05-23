FROM alpine:3.20

RUN set -eux; \
    for attempt in 1 2 3 4 5; do \
        apk add --no-cache pgbouncer postgresql-client && break; \
        if [ "$attempt" -eq 5 ]; then \
            exit 1; \
        fi; \
        sleep 5; \
    done

RUN addgroup -S pgbouncer && adduser -S -G pgbouncer pgbouncer

# Copy custom configuration
COPY --chmod=644 pgbouncer.ini /etc/pgbouncer/pgbouncer.ini
COPY --chmod=600 userlist.txt /etc/pgbouncer/userlist.txt
COPY --chmod=755 entrypoint.sh /entrypoint.sh

RUN chown -R pgbouncer:pgbouncer /etc/pgbouncer

USER pgbouncer

EXPOSE 6432

ENTRYPOINT ["/entrypoint.sh"]
