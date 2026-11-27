#!/bin/sh
# Entrypoint of the Excubia image (#116). Prepares /app/storage, generates and keeps an APP_KEY
# when none is set, migrates the database (only where EXCUBIA_RUN_MIGRATIONS is not "false"),
# seeds the permissions and the system project (both idempotent), caches the config, then runs
# the command (the web server by default, or queue:work / schedule:work).
set -eu

cd /app

mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs

if [ -z "${APP_KEY:-}" ]; then
    # Kept on the volume, so every container and restart uses the same key.
    if [ ! -s storage/app.key ]; then
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > storage/app.key
        chmod 600 storage/app.key
        echo "excubia: generated APP_KEY in storage/app.key (back it up with the database)"
    fi

    APP_KEY="$(cat storage/app.key)"
    export APP_KEY
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ ! -e "${DB_DATABASE:-/app/storage/database.sqlite}" ]; then
    touch "${DB_DATABASE:-/app/storage/database.sqlite}"
fi

if [ "${EXCUBIA_RUN_MIGRATIONS:-true}" != "false" ]; then
    php artisan migrate --force --no-interaction
    php artisan db:seed --class='Database\Seeders\PermissionSeeder' --force --no-interaction
    php artisan db:seed --class='Database\Seeders\SystemProjectSeeder' --force --no-interaction
fi

php artisan optimize --no-interaction > /dev/null

exec "$@"
