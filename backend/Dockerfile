# -------------------------------------------------------------------
# Stage 1: Build dependencies (Composer)
# -------------------------------------------------------------------
FROM php:8.4.14-fpm-alpine AS builder

WORKDIR /var/www/html

# Install build dependencies
RUN apk add --no-cache --virtual .build-deps \
    libzip-dev \
    postgresql-dev \
    linux-headers \
    autoconf \
    g++ \
    make \
 && apk add --no-cache \
    unzip \
    git \
    curl \
 && docker-php-ext-install -j$(nproc) sockets zip pdo pdo_pgsql ftp \
 && apk del .build-deps

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy composer files first (for better layer caching)
COPY composer.json ./

# Install dependencies (no dev dependencies)
# Note: composer.lock will be generated if it doesn't exist
RUN composer install --optimize-autoloader --no-interaction --no-progress --no-scripts --no-dev \
 && composer clear-cache

# Copy application source
COPY . .

# Regenerate autoloader after copying all files
RUN composer dump-autoload --optimize --no-dev

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
RUN apk add --no-cache --virtual .build-deps \
      postgresql-dev \
      libzip-dev \
      linux-headers \
      autoconf \
      g++ \
      make \
 && apk add --no-cache \
      postgresql-client \
      libzip \
      supervisor \
      sudo \
      bash \
      wireguard-tools \
      iptables \
      iproute2 \
 && docker-php-ext-install -j$(nproc) pdo_pgsql sockets zip opcache ftp \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del .build-deps \
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
    /etc/supervisor/conf.d \
    /etc/wireguard \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data /etc/wireguard \
    && chmod -R 775 /etc/wireguard \
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
