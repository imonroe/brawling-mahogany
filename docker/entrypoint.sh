#!/bin/sh
set -e

# Wait for the database before anything touches it. Compose health checks cover
# the ordinary case; this covers the restart-after-a-crash case, where Postgres
# is up but still replaying.
if [ -n "${DB_HOST:-}" ]; then
    tries=0
    until php -r "new PDO('pgsql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT') ?: 5432).';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));" 2>/dev/null; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "Database did not become available in time." >&2
            exit 1
        fi
        sleep 1
    done
fi

# Migrations run on boot only where that is safe and wanted: local and CI set
# AUTO_MIGRATE. Staging and production migrate as an explicit deploy step, so a
# bad migration is a failed deploy rather than a container that will not start.
if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
fi

exec "$@"
