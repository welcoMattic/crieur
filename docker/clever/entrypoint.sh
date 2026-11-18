#!/bin/sh
# Starts what Crieur needs next to the web server, in the same container: the two Phonon servers
# (the gated Phonon 40m models download with HF_TOKEN at the first start) and the queue workers.
# Each one runs in a loop: Clever Cloud restarts the container, not a process that stopped in it.
set -e
mkdir -p var
if [ -z "${APP_SECRET:-}" ]; then
    [ -s var/app_secret ] || php -r 'echo bin2hex(random_bytes(16));' > var/app_secret
    APP_SECRET="$(cat var/app_secret)"
    export APP_SECRET
fi
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

keep() {
    (
        while true; do
            "$@" || true
            echo "crieur: '$*' stopped, starting it again" >&2
            sleep 2
        done
    ) &
}

keep env PTTS_LANG=fr PTTS_MODEL=hf://gradium/phonon-40m-fr@65b5b451d235a8c24245f51d2774ec9806a9443f \
    ptts-openai-server --addr 127.0.0.1:8880
keep env PTTS_LANG=en PTTS_MODEL=hf://gradium/phonon-40m-en@9b95393286eaeeebf1330b2ae90a14a2074c415d \
    ptts-openai-server --addr 127.0.0.1:8881
keep php bin/console messenger:consume extract synthesize --time-limit=3600 --memory-limit=512M
keep php bin/console messenger:consume edit --time-limit=3600 --memory-limit=256M
# The public demo (APP_ENV=demo) empties its library every six hours, as its home page says.
if [ "${APP_ENV:-prod}" = demo ]; then
    (
        while true; do
            php bin/console crieur:demo:reset --at-next-reset || sleep 60
        done
    ) &
fi

exec "$@"
