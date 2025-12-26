# ============================================================================
# PostgreSQL 17 Alpine with Multi-Tenant Support & Table Partitioning
# ============================================================================
# Bakes all configurations into the image (no volume mounts needed)
# Includes pg_partman extension from Alpine packages
# Note: pg_cron requires clang/LLVM (~200MB overhead) - partitions managed manually
# ============================================================================

FROM postgres:17-alpine

# Set timezone
ENV TZ=Africa/Nairobi

# Install pg_partman from Alpine packages (much smaller than Debian)
RUN apk add --no-cache \
    postgresql-pg_partman \
    postgresql-contrib \
    tzdata \
    && cp /usr/share/zoneinfo/Africa/Nairobi /etc/localtime \
    && echo "Africa/Nairobi" > /etc/timezone \
    && rm -rf /var/cache/apk/*

# Create symlinks for pg_partman extension files (Alpine installs to postgresql17)
RUN ln -s /usr/share/postgresql17/extension/pg_partman* /usr/local/share/postgresql/extension/ \
    && ln -s /usr/lib/postgresql17/pg_partman_bgw.so /usr/local/lib/postgresql/pg_partman_bgw.so

# Copy initialization scripts into container
# These will run automatically on first database creation (in alphabetical order)
COPY init.sql /docker-entrypoint-initdb.d/01-init.sql
COPY partitioning-setup.sql /docker-entrypoint-initdb.d/02-partitioning-setup.sql

# Set proper permissions
RUN chmod 644 /docker-entrypoint-initdb.d/*.sql

# Expose PostgreSQL port
EXPOSE 5432

# Health check
HEALTHCHECK --interval=10s --timeout=5s --retries=3 --start-period=20s \
    CMD pg_isready -U ${POSTGRES_USER:-admin} -d ${POSTGRES_DB:-wms_770_ts} || exit 1