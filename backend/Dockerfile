# -------------------------------------------------------------------
# Stage 1: Build dependencies (Composer)
# -------------------------------------------------------------------
FROM php:8.4.13-fpm AS builder
WORKDIR /var/www/html

# Install build dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip \
    libzip-dev \
    git \
    curl \
    libpq-dev \
 && docker-php-ext-install sockets zip pdo pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy composer files first (for better layer caching)
COPY composer.json composer.lock ./

# Install dependencies (no dev dependencies)
RUN composer install --optimize-autoloader --no-interaction --no-progress --no-scripts --no-dev

# Copy application source
COPY . .

# Clean any cached bootstrap files that might have been copied
RUN rm -f bootstrap/cache/*.php

# Fix broadcast driver typo in .env for build
RUN sed -i 's/BROADCAST_DRIVER=sketi/BROADCAST_DRIVER=pusher/g' .env || true

# Run build steps - skip post-autoload-dump to avoid build-time errors
# It will run automatically when container starts
# RUN composer run-script post-autoload-dump

# -------------------------------------------------------------------
# Stage 2: Production runtime
# -------------------------------------------------------------------
FROM php:8.4.13-fpm AS production
WORKDIR /var/www/html

# Install runtime dependencies including SSH2 for SFTP, Redis, and sudo
RUN apt-get update && apt-get install -y --no-install-recommends \
      libpq-dev \
      libzip-dev \
      libssh2-1-dev \
      supervisor \
      postgresql-client \
      sudo \
 && docker-php-ext-install -j$(nproc) pdo_pgsql sockets zip opcache ftp \
 && pecl install ssh2-1.4.1 redis \
 && docker-php-ext-enable ssh2 redis \
 && apt-get purge -y --auto-remove \
 && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Copy custom PHP-FPM and OPcache configuration
COPY --chown=root:root ./docker/php-fpm-custom.conf /usr/local/etc/php-fpm.d/zzz-custom.conf
COPY --chown=root:root ./docker/php-opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Copy vendor and application from builder
COPY --from=builder /var/www/html /var/www/html

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
 && chmod -R 775 storage bootstrap/cache

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
