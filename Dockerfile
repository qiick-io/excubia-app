# syntax=docker/dockerfile:1
#
# Production image of Excubia App (#116): FrankenPHP (Caddy + PHP 8.4) in classic mode, no
# Octane. One image runs the web server, the queue worker and the scheduler (docker-compose.yml).
# State lives in /app/storage (SQLite database, uploads, generated APP_KEY): mount a volume there.

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.4-alpine

FROM ${PHP_IMAGE} AS base
RUN install-php-extensions intl pcntl pdo_mysql pdo_pgsql
WORKDIR /app

# Composer dependencies without dev packages; the lock decides every version.
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

# Frontend build. The Wayfinder plugin runs `php artisan wayfinder:generate`, so it builds on the
# PHP stage with Node copied in from the official image (both Alpine/musl).
FROM vendor AS assets
COPY --from=node:24-alpine /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-alpine /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN apk add --no-cache libstdc++ \
    && ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
RUN npm ci --no-audit --no-fund && npm run build

FROM base AS runtime
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/storage/database.sqlite \
    SERVER_NAME=:8080
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-excubia.ini"
COPY docker/entrypoint.sh /usr/local/bin/excubia-entrypoint
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
RUN rm -rf /app/tests /app/node_modules /app/storage/logs/*.log \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data/caddy /config/caddy
USER www-data
EXPOSE 8080
VOLUME ["/app/storage"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up > /dev/null || exit 1
ENTRYPOINT ["excubia-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
