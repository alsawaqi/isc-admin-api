#!/bin/sh
set -e

# Ensure required runtime dirs exist (idempotent)
mkdir -p /var/www/html/storage/logs \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/framework/cache \
         /var/www/html/storage/app/public \
         /var/www/html/storage/app/private \
         /var/www/html/bootstrap/cache

touch /var/www/html/storage/logs/laravel.log

# Docker volumes mask image-time ownership, so repair writable Laravel mounts
# on every container start. This keeps manually seeded uploads editable by PHP.
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R ug+rwX /var/www/html/storage /var/www/html/bootstrap/cache

exec "$@"