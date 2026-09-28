# syntax=docker/dockerfile:1.7
# Production image of the API (target "prod"), published by the CI to ghcr.io/fayouz/rocket-clean-api.
# Alpine FrankenPHP runtime; Composer and the build steps stay in the "build" stage (not shipped).
FROM dunglas/frankenphp:1-php8.4-alpine AS base

WORKDIR /app
RUN install-php-extensions pdo_pgsql intl opcache apcu ldap zip gd \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --link docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY --link docker/Caddyfile /etc/rocket-clean/Caddyfile

ENV APP_ENV=prod \
    SERVER_NAME=":80"

FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --link composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction
COPY --link . .
RUN rm -f .env.local .env.*.local \
    && composer dump-autoload --classmap-authoritative --no-dev \
    && composer dump-env prod \
    && composer run-script --no-dev post-install-cmd \
    && mkdir -p var/cache var/log config/jwt

FROM base AS prod
COPY --link --from=build /app /app
RUN chown -R www-data:www-data var config/jwt
COPY --link --chmod=755 docker/entrypoint.sh /usr/local/bin/app-entrypoint
# Version shown in the app and compared with the releases ("git describe --tags", passed by the CI).
ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}
# Caddy admin endpoint (FrankenPHP metrics): answers once the server is up, whatever SERVER_NAME is.
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:2019/metrics || exit 1
ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/rocket-clean/Caddyfile", "--adapter", "caddyfile"]
