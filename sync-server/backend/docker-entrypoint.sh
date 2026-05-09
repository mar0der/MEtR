#!/bin/bash
set -e

mkdir -p /var/www/html/bootstrap/cache
mkdir -p /var/www/html/storage/{app,framework,logs}
mkdir -p /var/www/html/storage/framework/{cache,sessions,views}

chmod -R a+rwX /var/www/html/storage
chmod -R a+rwX /var/www/html/bootstrap/cache

exec docker-php-entrypoint "$@"
