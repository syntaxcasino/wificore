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

# Performance tuning in radiusd.conf
# reject_delay=0: Laravel rate-limits; the 1s FreeRADIUS delay only adds latency
# num_workers=32: match SQL connection pool max; handle burst concurrent requests
# max_requests=0: no cap on request queue (bounded by num_workers + pool)
RUN sed -i 's/^\treject_delay = 1/\treject_delay = 0/' /opt/etc/raddb/radiusd.conf \
 && sed -i 's/^\tnum_networks = .*/\tnum_networks = 1/' /opt/etc/raddb/radiusd.conf \
 && sed -i 's/^\tnum_workers = .*/\tnum_workers = 32/' /opt/etc/raddb/radiusd.conf \
 && sed -i 's/^\tmax_requests = .*/\tmax_requests = 0/' /opt/etc/raddb/radiusd.conf
