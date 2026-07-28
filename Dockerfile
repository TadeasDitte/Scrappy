# syntax=docker/dockerfile:1

# ---- Stage 1: install PHP dependencies ----
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

# ---- Stage 2: build front-end assets ----
# Needs PHP + vendor because the Wayfinder Vite plugin runs `php artisan`.
FROM php:8.5-cli-alpine AS assets

RUN apk add --no-cache nodejs npm

WORKDIR /app

# Vendor first so `php artisan` can boot during the Vite build.
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN cp .env.example .env \
    && npm ci \
    && npm run build

# ---- Stage 3: application runtime (PHP-FPM) ----
FROM php:8.5-fpm-alpine AS app

# System deps + PHP extensions needed by Laravel + Postgres + Redis.
RUN apk add --no-cache \
        bash \
        postgresql-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pcntl \
        bcmath \
        zip \
        intl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

WORKDIR /var/www/html

# Application source, then vendored deps and built assets from earlier stages.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Entrypoint prepares the app (migrate, caches) before handing off.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---- Stage 4: nginx web server ----
# Carries a copy of public/ so it can serve static assets and resolve the
# document root; PHP requests are proxied to the app (php-fpm) container, which
# has the same /var/www/html/public path.
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
