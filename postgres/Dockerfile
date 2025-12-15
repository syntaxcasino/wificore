# ============================================================================
# PostgreSQL 16 with Multi-Tenant Support & Table Partitioning
# ============================================================================
# Bakes all configurations into the image (no volume mounts needed)
# Includes pg_partman for automated partition management
# ============================================================================

FROM postgres:16.10-trixie

# Set timezone
ENV TZ=Africa/Nairobi

# Install pg_partman and pg_cron extensions
RUN apt-get update && apt-get install -y \
    postgresql-16-partman \
    postgresql-16-cron \
    && rm -rf /var/lib/apt/lists/*

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