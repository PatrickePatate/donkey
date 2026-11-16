FROM php:8.5-cli

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && install-php-extensions pdo_pgsql pgsql pdo_sqlite redis pcntl intl zip bcmath sockets \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# kodus-graph (structural code graph) runs on Bun.
COPY --from=oven/bun:1.3 /usr/local/bin/bun /usr/local/bin/bun
ENV BUN_INSTALL=/usr/local/bun PATH=/usr/local/bun/bin:$PATH
RUN bun install -g @kodus/kodus-graph@0.3.3 && kodus-graph --version

ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} app && useradd -u ${UID} -g ${GID} -m app

WORKDIR /var/www/html
USER app
