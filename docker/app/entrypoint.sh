#!/bin/sh
set -e
cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Generate one with:" >&2
    echo "  docker compose -f compose.prod.yaml run --rm --no-deps -e APP_KEY=x app php artisan key:generate --show" >&2
    exit 1
fi

php artisan optimize >/dev/null 2>&1 || true

exec "$@"
