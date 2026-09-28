# PHP-FPM runtime for SADAR-SPKD (used by the app and scheduler containers).
FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip intl bcmath exif opcache \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-sadar.ini

WORKDIR /var/www/html
