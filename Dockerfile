# Crieur in one image: FrankenPHP serves the app, the same image runs the queue workers.
# Phonon voices need Ollama on an Apple Silicon host (reached through host.docker.internal);
# anywhere else, set MISTRAL_API_KEY and CRIEUR_VOICE_ENGINE=voxtral.
FROM dunglas/frankenphp:1-php8.4-bookworm

RUN install-php-extensions intl pdo_sqlite zip opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg unzip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
ENV APP_ENV=prod \
    COMPOSER_ALLOW_SUPERUSER=1 \
    SERVER_NAME=:8000

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && composer run-script --no-dev post-install-cmd \
    && php bin/console asset-map:compile \
    && mkdir -p var && chown -R www-data:www-data var

COPY docker/entrypoint.sh /usr/local/bin/crieur-entrypoint
RUN chmod +x /usr/local/bin/crieur-entrypoint
ENTRYPOINT ["crieur-entrypoint"]
CMD ["frankenphp", "php-server", "--root", "public/", "--listen", ":8000"]
