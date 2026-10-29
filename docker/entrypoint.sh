#!/bin/sh
set -eu

cd /var/www/html

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

php artisan storage:link --force >/dev/null 2>&1 || true
php artisan migrate --force
php artisan db:seed --class=AdminUserSeeder --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
