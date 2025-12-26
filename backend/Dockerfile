# -------------------------------------------------------------------
# Stage 1: Build dependencies (Composer)
# -------------------------------------------------------------------
FROM composer:latest AS composer-builder

WORKDIR /app

# Copy composer files first (for better layer caching)
COPY composer.json ./

# Install dependencies ignoring platform requirements for sockets
# (sockets will be available in production stage)
RUN composer update \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --no-dev \
    --prefer-dist \
    --ignore-platform-reqs \
    && composer clear-cache

# -------------------------------------------------------------------
# Stage 2: Production runtime (Alpine for minimal size)
# -------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS production

WORKDIR /var/www/html

# Update package index and install runtime dependencies
RUN set -eux; \
    # Clean APK cache first to avoid corrupted packages
    rm -rf /var/cache/apk/* /tmp/*; \
    # Update Alpine repositories with retries
    apk update || (sleep 5 && apk update) || (sleep 10 && apk update); \
    # Install runtime dependencies with retry on failure
    apk add --no-cache \
        bash \
        postgresql-dev \
        libzip-dev \
        supervisor \
        postgresql-client \
        sudo \
        linux-headers \
    || (rm -rf /var/cache/apk/* && apk update && apk add --no-cache \
        bash \
        postgresql-dev \
        libzip-dev \
        supervisor \
        postgresql-client \
        sudo \
        linux-headers); \
    # Install PHP extensions
    docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        sockets \
        zip \
        opcache; \
    # Install PECL extensions
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    # Cleanup
    apk del .build-deps linux-headers; \
    rm -rf /tmp/* /var/cache/apk/*

# Copy custom PHP-FPM and OPcache configuration
COPY --chown=root:root ./docker/php-fpm-custom.conf /usr/local/etc/php-fpm.d/zzz-custom.conf
COPY --chown=root:root ./docker/php-opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Copy vendor from composer builder
COPY --from=composer-builder /app/vendor /var/www/html/vendor

# Copy application source (excluding files in .dockerignore)
COPY --chown=www-data:www-data . .

# Create Laravel-required directories with correct permissions
RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    /var/log/supervisor \
    /etc/supervisor/conf.d \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && rm -f bootstrap/cache/*.php

# Copy Supervisor configs
COPY --chown=root:root ./supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY --chown=root:root ./supervisor/laravel-queue.conf /etc/supervisor/conf.d/laravel-queue.conf
COPY --chown=root:root ./supervisor/php-fpm.conf /etc/supervisor/conf.d/php-fpm.conf
COPY --chown=root:root ./supervisor/laravel-scheduler.conf /etc/supervisor/conf.d/laravel-scheduler.conf

# Configure sudo for www-data to run supervisorctl
COPY --chown=root:root ./supervisor/sudoers-supervisorctl /etc/sudoers.d/supervisorctl
RUN chmod 0440 /etc/sudoers.d/supervisorctl

# Copy entrypoint script
COPY --chmod=755 ./docker/entrypoint.sh /entrypoint.sh

# Expose PHP-FPM port
EXPOSE 9000

# Run Supervisor via entrypoint
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
