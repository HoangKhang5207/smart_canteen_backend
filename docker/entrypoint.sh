#!/bin/sh
set -eu

mkdir -p \
    /var/www/html/storage/app/private \
    /var/www/html/storage/app/public \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/testing \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache

if [ "${APP_ENV:-local}" != "production" ] && [ ! -f /var/www/html/vendor/autoload.php ] && command -v composer >/dev/null 2>&1; then
    composer install --no-interaction --prefer-dist
fi

if [ "${APP_ENV:-local}" = "production" ] && [ -n "${APP_KEY:-}" ]; then
    php /var/www/html/artisan config:cache
    php /var/www/html/artisan route:cache
    php /var/www/html/artisan view:cache
fi

exec "$@"
