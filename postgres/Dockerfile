# ============================================================================
# PostgreSQL 17 Alpine with Multi-Tenant Support
# ============================================================================
# Bakes all configurations into the image (no volume mounts needed)
# Uses Alpine for minimal image size
# ============================================================================

FROM postgres:17-alpine

# Set timezone
ENV TZ=Africa/Nairobi

# Install required packages
RUN apk add --no-cache \
    tzdata \
    && cp /usr/share/zoneinfo/Africa/Nairobi /etc/localtime \
    && echo "Africa/Nairobi" > /etc/timezone \
    && rm -rf /var/cache/apk/*

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