# ============================================================================
# PostgreSQL 17 with Multi-Tenant Support & Table Partitioning
# ============================================================================
# Bakes all configurations into the image (no volume mounts needed)
# Includes pg_partman and pg_cron extensions for automated partition management
# Uses bookworm (Debian) for full extension support
# ============================================================================

FROM postgres:17-bookworm

# Set timezone
ENV TZ=Africa/Nairobi

# Install pg_partman and pg_cron extensions
# Added retry logic for better build resilience
RUN echo "Acquire::Retries \"3\";" > /etc/apt/apt.conf.d/80-retries && \
    echo "Acquire::http::Timeout \"120\";" > /etc/apt/apt.conf.d/80-timeout && \
    (apt-get update || (sleep 5 && apt-get update) || (sleep 10 && apt-get update)) && \
    apt-get install -y --no-install-recommends \
    postgresql-17-partman \
    postgresql-17-cron \
    tzdata \
    && ln -sf /usr/share/zoneinfo/Africa/Nairobi /etc/localtime \
    && echo "Africa/Nairobi" > /etc/timezone \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Copy initialization scripts into container
# These will run automatically on first database creation (in alphabetical order)
COPY init.sql /docker-entrypoint-initdb.d/01-init.sql
COPY partitioning-setup.sql /docker-entrypoint-initdb.d/02-partitioning-setup.sql
COPY partition-lifecycle-management.sql /docker-entrypoint-initdb.d/03-partition-lifecycle-management.sql

# Replication bootstrap (primary/replica)
COPY replication-entrypoint.sh /usr/local/bin/replication-entrypoint.sh

# Set proper permissions
RUN chmod 644 /docker-entrypoint-initdb.d/*.sql
RUN chmod 755 /usr/local/bin/replication-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/replication-entrypoint.sh"]

# Expose PostgreSQL port
EXPOSE 5432

# Health check - check if PostgreSQL is accepting connections (no database required)
HEALTHCHECK --interval=10s --timeout=5s --retries=5 --start-period=60s \
    CMD pg_isready -U ${POSTGRES_USER:-admin} || exit 1