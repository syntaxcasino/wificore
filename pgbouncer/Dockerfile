FROM pgbouncer/pgbouncer:latest

# Copy custom configuration
COPY pgbouncer.ini /etc/pgbouncer/pgbouncer.ini
COPY userlist.txt /etc/pgbouncer/userlist.txt
COPY entrypoint.sh /entrypoint.sh

# Set proper permissions
RUN chmod 644 /etc/pgbouncer/pgbouncer.ini && \
    chmod 600 /etc/pgbouncer/userlist.txt && \
    chmod +x /entrypoint.sh

EXPOSE 6432

ENTRYPOINT ["/entrypoint.sh"]
