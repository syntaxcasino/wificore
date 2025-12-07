# ============================================================================
# PostgreSQL 16 with Multi-Tenant Support
# ============================================================================
# Bakes all configurations into the image (no volume mounts needed)
# ============================================================================

FROM postgres:16.10-trixie

# Set timezone
ENV TZ=Africa/Nairobi

# Copy initialization script into container
# This will run automatically on first database creation
COPY init.sql /docker-entrypoint-initdb.d/init.sql

# Set proper permissions
RUN chmod 644 /docker-entrypoint-initdb.d/init.sql

# Expose PostgreSQL port
EXPOSE 5432

# Health check
HEALTHCHECK --interval=10s --timeout=5s --retries=3 --start-period=20s \
    CMD pg_isready -U ${POSTGRES_USER:-admin} -d ${POSTGRES_DB:-wifi_hotspot} || exit 1