FROM freeradius/freeradius-server:latest-alpine
RUN apk add --no-cache postgresql-client postgresql-libs

# Copy custom SQL module configuration
COPY sql /opt/etc/raddb/mods-available/sql

# Copy custom SQL queries with lowercase column names
COPY queries.conf /opt/etc/raddb/mods-config/sql/main/postgresql/queries.conf

# Enable SQL module in default site (copy to sites-available, symlink already exists)
COPY default /opt/etc/raddb/sites-available/default

# Copy clients configuration
COPY clients.conf /etc/raddb/clients.conf

# Copy custom dictionary with proper permissions
COPY dictionary /opt/etc/raddb/dictionary

# Fix permissions for all config files (FreeRADIUS requires strict permissions)
# Note: Alpine FreeRADIUS runs as root, so we only need to set file permissions
RUN chmod 640 /etc/raddb/clients.conf && \
    chmod 640 /opt/etc/raddb/mods-available/sql && \
    chmod 644 /opt/etc/raddb/dictionary
