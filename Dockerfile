# syntax=docker/dockerfile:1
# Production image for Donkey. Targets: `app` (PHP-FPM, also runs Horizon,
# Reverb and the scheduler) and `web` (nginx serving public/ and proxying
# PHP-FPM and Reverb).

FROM php:8.5-fpm AS base

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && install-php-extensions pdo_pgsql pgsql redis pcntl intl zip bcmath sockets opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# kodus-graph (structural code graph) runs on Bun.
COPY --from=oven/bun:1.3 /usr/local/bin/bun /usr/local/bin/bun
ENV BUN_INSTALL=/usr/local/bun PATH=/usr/local/bun/bin:$PATH
RUN bun install -g @kodus/kodus-graph@0.3.3 && kodus-graph --version

COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-donkey.ini

WORKDIR /var/www/html

# ---------------------------------------------------------------- PHP deps
FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ---------------------------------------------------------------- Assets
FROM node:22-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---------------------------------------------------------------- App
FROM base AS app
ENV APP_ENV=production KODUS_GRAPH_BINARY=kodus-graph GRAPH_EXTRA_PATH=/usr/local/bun/bin

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=assets /build/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize --no-interaction --no-scripts \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Set after the build steps above, which must not boot with them. Defaults for the Compose stack (compose.prod.yaml); .env.production overrides any of them.
ENV APP_NAME=Donkey APP_DEBUG=false LOG_CHANNEL=stderr LOG_LEVEL=info TRUSTED_PROXIES=* \
    DB_CONNECTION=pgsql DB_HOST=pgsql DB_PORT=5432 DB_DATABASE=donkey DB_USERNAME=donkey DB_PASSWORD=donkey \
    REDIS_CLIENT=phpredis REDIS_HOST=redis REDIS_PORT=6379 \
    QUEUE_CONNECTION=redis CACHE_STORE=redis SESSION_DRIVER=redis \
    BROADCAST_CONNECTION=reverb REVERB_APP_ID=donkey REVERB_HOST=reverb REVERB_PORT=8080 REVERB_SCHEME=http \
    GITHUB_APP_PRIVATE_KEY_PATH=/var/www/html/storage/app/private/github-app.pem \
    MAIL_MAILER=smtp MAIL_PORT=587 MAIL_FROM_NAME=Donkey

COPY docker/app/entrypoint.sh /usr/local/bin/donkey-entrypoint
RUN chmod +x /usr/local/bin/donkey-entrypoint

USER www-data
ENTRYPOINT ["donkey-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------- Web
FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
