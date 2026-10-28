# Image PHP untuk pengembangan lokal (kode di-mount sebagai volume).
FROM php:8.4-cli-alpine

RUN apk add --no-cache \
    postgresql-dev libzip-dev icu-dev oniguruma-dev \
    libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev \
    zip unzip git postgresql16-client $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip bcmath intl mbstring gd exif pcntl opcache \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
