# -------------------------------------------------------------------
# Stage 1: Build dependencies (Composer)
# -------------------------------------------------------------------
FROM php:8.4.14-fpm-alpine AS builder

WORKDIR /var/www/html

# Install build dependencies and runtime libraries separately
# Runtime libs (libpq, libzip) must remain for PHP extensions to work
RUN set -eux; \
    for attempt in 1 2 3 4 5; do \
        apk add --no-cache \
            libpq \
            libzip \
            unzip \
            git \
            curl \
        && apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS \
        && apk add --no-cache --virtual .build-deps \
            libzip-dev \
            postgresql-dev \
            linux-headers && break; \
        if [ "$attempt" -eq 5 ]; then \
            exit 1; \
        fi; \
        sleep 5; \
    done \
 && docker-php-ext-install -j$(nproc) sockets zip pdo pdo_pgsql ftp \
 && apk del .build-deps .phpize-deps

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy composer files first (for better layer caching)
COPY composer.json ./

# Install all dependencies (including dev) so package:discover can load all providers.
# Dev deps are not copied to the production stage — they are stripped at the OS/copy level.
RUN composer install --optimize-autoloader --no-interaction --no-progress --no-scripts \
 && composer clear-cache

# Copy application source
COPY . .

# Regenerate autoloader after copying all files
RUN composer dump-autoload --optimize

# Verify critical files exist in builder
RUN test -f app/Providers/RouteServiceProvider.php || (echo "ERROR: RouteServiceProvider.php not found in builder!" && exit 1) \
    && test -f bootstrap/providers.php || (echo "ERROR: bootstrap/providers.php not found in builder!" && exit 1) \
    && echo "✅ Critical provider files verified in builder"

# Clean any cached bootstrap files and unnecessary files
RUN rm -f bootstrap/cache/*.php \
 && find . -name ".git*" -exec rm -rf {} + 2>/dev/null || true \
 && rm -rf tests/ docs/ storage/logs/* storage/framework/cache/* storage/framework/sessions/* storage/framework/views/*

# -------------------------------------------------------------------
# Stage 2: Production runtime (Alpine for minimal size)
# -------------------------------------------------------------------
FROM php:8.4.14-fpm-alpine AS production

WORKDIR /var/www/html

# Install runtime dependencies
RUN set -eux; \
    for attempt in 1 2 3 4 5; do \
        apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS \
        && apk add --no-cache --virtual .build-deps \
            postgresql-dev \
            libzip-dev \
            icu-dev \
            net-snmp-dev \
            linux-headers \
        && apk add --no-cache \
            postgresql-client \
            libzip \
            icu-libs \
            net-snmp \
            supervisor \
            sudo \
            bash \
            curl \
            wireguard-tools \
            iptables \
            iproute2 && break; \
        if [ "$attempt" -eq 5 ]; then \
            exit 1; \
        fi; \
        sleep 5; \
    done \
 && docker-php-ext-install -j$(nproc) pdo_pgsql sockets zip opcache ftp snmp intl \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del .build-deps .phpize-deps \
 && rm -rf /tmp/* /var/cache/apk/*

# Copy custom PHP-FPM and OPcache configuration
COPY --chown=root:root ./docker/php-fpm-custom.conf /usr/local/etc/php-fpm.d/zzz-custom.conf
COPY --chown=root:root ./docker/php-opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Copy vendor and application from builder
COPY --from=builder /var/www/html /var/www/html

# Verify critical files exist
RUN test -f app/Providers/RouteServiceProvider.php || (echo "ERROR: RouteServiceProvider.php not found!" && exit 1) \
    && test -f bootstrap/providers.php || (echo "ERROR: bootstrap/providers.php not found!" && exit 1) \
    && echo "✅ Critical provider files verified"

# Create Laravel-required directories with correct permissions
RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    /var/log/supervisor \
    /var/run \
    /etc/supervisor/conf.d \
    /etc/wireguard \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data /etc/wireguard \
    && chmod -R 775 /etc/wireguard \
    && chmod 755 /var/log/supervisor \
    && rm -f bootstrap/cache/*.php

# Copy Supervisor configs
COPY --chown=root:root ./supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY --chown=root:root ./supervisor/laravel-queue.conf /etc/supervisor/conf.d/laravel-queue.conf
COPY --chown=root:root ./supervisor/laravel-queue-core.conf /etc/supervisor/conf.d/laravel-queue-core.conf
COPY --chown=root:root ./supervisor/laravel-queue-router.conf /etc/supervisor/conf.d/laravel-queue-router.conf
COPY --chown=root:root ./supervisor/laravel-queue-metrics.conf /etc/supervisor/conf.d/laravel-queue-metrics.conf
COPY --chown=root:root ./supervisor/laravel-queue-realtime.conf /etc/supervisor/conf.d/laravel-queue-realtime.conf
COPY --chown=root:root ./supervisor/php-fpm.conf /etc/supervisor/conf.d/php-fpm.conf
COPY --chown=root:root ./supervisor/laravel-scheduler.conf /etc/supervisor/conf.d/laravel-scheduler.conf

# Configure sudo for www-data to run supervisorctl
COPY --chown=root:root ./supervisor/sudoers-supervisorctl /etc/sudoers.d/supervisorctl
RUN chmod 0440 /etc/sudoers.d/supervisorctl

# Copy entrypoint scripts
COPY --chmod=755 ./docker/entrypoint.sh /entrypoint.sh
COPY --chmod=755 ./docker/telegraf-config-entrypoint.sh /telegraf-config-entrypoint.sh

# Expose PHP-FPM port
EXPOSE 9000

# Run Supervisor via entrypoint
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
