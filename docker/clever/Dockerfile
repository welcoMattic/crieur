# Crieur on Clever Cloud (Docker runtime): one container for the app, its queue workers and the
# Phonon voices. Ollama serves Phonon on Apple Silicon only, so here the voices come from the Phonon
# servers of Gradium, one per language, listening on 127.0.0.1: they never get a public address.
# Clever Cloud builds this file with CC_DOCKERFILE=docker/clever/Dockerfile.
#
# A Docker application of Clever Cloud has no persistent disk: the library (SQLite and audio in
# var/) starts again empty at each deployment or restart.
# The article extractor: Defuddle (the same release as the browser extension), compiled by Bun into
# one binary, so the image needs no Node at runtime.
FROM oven/bun:1.3.2 AS defuddle
WORKDIR /src
COPY tools/defuddle/package.json tools/defuddle/bun.lock tools/defuddle/extract.mjs ./
RUN bun install --frozen-lockfile && bun build --compile extract.mjs --outfile /out/defuddle

FROM ghcr.io/gradium-ai/ptts-openai-server:0.7.1@sha256:b4f46fb8bbd2dee326a012da6cc33ceadcbafe29e46b827d0e7b1bb01b1b053d AS phonon

FROM dunglas/frankenphp:1-php8.4-bookworm

RUN install-php-extensions intl pdo_sqlite zip opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg unzip libopus0 libmp3lame0 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# The server and its two builds (x86-64-v3 with AVX2, x86-64-v2 without): the first one picks.
COPY --from=phonon /usr/local/bin/ptts-openai-server /usr/local/bin/ptts-openai-server
COPY --from=phonon /usr/local/libexec/ptts /usr/local/libexec/ptts
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
ENV APP_ENV=prod \
    COMPOSER_ALLOW_SUPERUSER=1 \
    SERVER_NAME=:8080 \
    SYMFONY_TRUSTED_PROXIES=REMOTE_ADDR \
    CRIEUR_VOICE_ENGINE=phonon \
    CRIEUR_EDITOR=off \
    CRIEUR_PHONON_SERVERS=fr=http://127.0.0.1:8880,en=http://127.0.0.1:8881 \
    CRIEUR_PHONON_MODEL=phonon:40m \
    HF_HOME=/app/var/huggingface \
    PTTS_QUANT=q8 \
    PTTS_MAX_CONCURRENT_REQUESTS=2

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
COPY --from=defuddle /out/defuddle /app/tools/defuddle/build/defuddle
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && composer run-script --no-dev post-install-cmd \
    && php bin/console asset-map:compile

COPY docker/clever/entrypoint.sh /usr/local/bin/crieur-entrypoint
RUN chmod +x /usr/local/bin/crieur-entrypoint
ENTRYPOINT ["crieur-entrypoint"]
CMD ["frankenphp", "php-server", "--root", "public/", "--listen", ":8080"]
