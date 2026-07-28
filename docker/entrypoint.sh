#!/usr/bin/env bash
set -e

role="${CONTAINER_ROLE:-app}"

# Wait for Postgres to accept connections before touching the database.
if [ -n "$DB_HOST" ]; then
    echo "Waiting for database at ${DB_HOST}:${DB_PORT:-5432}…"
    until php -r "exit(@fsockopen(getenv('DB_HOST'), (int)(getenv('DB_PORT') ?: 5432)) ? 0 : 1);" 2>/dev/null; do
        sleep 1
    done
    echo "Database is up."
fi

# Only the primary app container runs migrations and (re)builds caches so the
# worker/scheduler don't race each other on boot.
if [ "$role" = "app" ]; then
    php artisan migrate --force --no-interaction
    php artisan config:cache
    php artisan event:cache
    # Note: route:cache is intentionally skipped — the app has closure-based
    # routes (e.g. the Sanctum /user and passkey .well-known endpoints) which
    # cannot be serialized.
fi

case "$role" in
    worker)
        exec php artisan queue:work redis --sleep=1 --tries=1 --max-time=3600
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    *)
        exec "$@"
        ;;
esac
