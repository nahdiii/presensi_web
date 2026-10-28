# Image produksi untuk Railway (service web, worker, dan scheduler memakai
# image yang sama; hanya start command yang berbeda):
#   web       : (default CMD)
#   worker    : php artisan queue:work --tries=3 --backoff=5
#   scheduler : php artisan schedule:work
# Pre-deploy command di Railway: php artisan migrate --force
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_pgsql pgsql intl zip gd bcmath exif pcntl opcache \
    && apt-get update && apt-get install -y --no-install-recommends postgresql-client \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan filament:assets \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr

CMD ["sh", "-c", "php artisan optimize && frankenphp php-server --root public/ --listen 0.0.0.0:${PORT:-8080}"]
