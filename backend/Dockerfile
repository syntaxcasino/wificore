#FROM php:8.4.8-fpm
FROM php:8.4.11-fpm
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    libzip-dev \
    && docker-php-ext-install pdo_pgsql sockets zip
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
WORKDIR /app
COPY . .
RUN composer install
ENV COMPOSER_ALLOW_SUPERUSER=1
CMD ["php", "artisan", "serve", "--host=0.0.0.0"]