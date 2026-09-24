#!/bin/sh
# Entrypoint of the production app image (php-fpm, worker, scheduler, one-off artisan).
# - refuses to start without APP_KEY (a missing key would silently break sessions and
#   the encrypted 2FA columns);
# - makes sure the bind-mounted storage/ has its skeleton, owned by www-data (uid 82).
#   docker-compose.yml runs every container of this image as 82:82, so files are created
#   with the right owner; if the container is started as root anyway (a bare `docker run`),
#   the skeleton is created for www-data and stray root-owned files are handed back to it;
# - builds this container's config/route/view/event caches (bootstrap/cache is per
#   container; the image is immutable, so the caches are always fresh for its code);
# - never runs migrations: deploy.sh does that once, explicitly.
set -eu
cd /var/www/html

if [ -z "${APP_KEY:-}" ] || [ "${APP_KEY}" = "base64:" ]; then
    echo "gaeld: APP_KEY is not set (see .env) - refusing to start" >&2
    exit 1
fi

SKELETON="app/private app/public fonts logs framework/cache/data framework/sessions framework/views framework/testing"
if [ "$(id -u)" = 0 ]; then
    for d in $SKELETON; do
        [ -d "storage/$d" ] || install -d -o www-data -g www-data "storage/$d"
    done
    find storage \! -user www-data -exec chown www-data:www-data {} + 2>/dev/null || true
else
    for d in $SKELETON; do
        [ -d "storage/$d" ] || mkdir -p "storage/$d"
    done
    if [ ! -w storage/app/private ] || [ ! -w storage/framework/views ]; then
        echo "gaeld: storage/ is not writable by uid $(id -u) - fix the ownership of the storage bind mount (chown -R 82:82)" >&2
        exit 1
    fi
fi

if [ "${GAELD_SKIP_CACHE:-0}" != "1" ]; then
    php artisan config:cache --no-ansi --quiet
    php artisan route:cache --no-ansi --quiet
    php artisan view:cache --no-ansi --quiet
    php artisan event:cache --no-ansi --quiet
fi

exec "$@"
