FROM freeradius/freeradius-server:latest-alpine
RUN apk add --no-cache postgresql-client postgresql-libs

# Copy custom dictionary with Tenant-ID attribute
COPY dictionary /opt/etc/raddb/dictionary

# Copy custom SQL module configuration
COPY sql /opt/etc/raddb/mods-available/sql

# Copy custom SQL queries with lowercase column names
COPY queries.conf /opt/etc/raddb/mods-config/sql/main/postgresql/queries.conf

# Copy clients configuration
COPY clients.conf /opt/etc/raddb/clients.conf

# Enable SQL module in default site (copy to sites-available, symlink already exists)
COPY default /opt/etc/raddb/sites-available/default
