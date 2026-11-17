#!/bin/sh
set -e

# The SQLite database, the audio and the secret live in var/, a volume shared by the web and
# worker containers. The secret is generated once: it also protects the podcast feed address.
mkdir -p var
if [ -z "${APP_SECRET:-}" ]; then
    [ -s var/app_secret ] || php -r 'echo bin2hex(random_bytes(16));' > var/app_secret
    APP_SECRET="$(cat var/app_secret)"
    export APP_SECRET
fi

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
chown -R www-data:www-data var

exec "$@"
