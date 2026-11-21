# QA-17 · Imagen de producción de la API (Laravel 12 · PHP 8.4) servida por FrankenPHP (servidor web de
# producción sobre Caddy). La misma imagen corre la API, las colas (horizon) y el programador (scheduler).
FROM composer:2 AS vendor
WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY apps/api ./
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

FROM dunglas/frankenphp:1-php8.4-alpine
RUN install-php-extensions pdo_pgsql pgsql bcmath redis intl pcntl opcache zip \
 && apk add --no-cache postgresql17-client tzdata
COPY docker/prod/php.ini /usr/local/etc/php/conf.d/zz-gestia.ini
WORKDIR /app
COPY --from=vendor /app /app
# Catálogos del kit y contrato OpenAPI que la API lee en ejecución (base_path('../kit/...')).
COPY kit/database/catalogos /kit/database/catalogos
COPY kit/api/openapi.yaml /kit/api/openapi.yaml
COPY docker/prod/api-entrypoint.sh /usr/local/bin/gestia-entrypoint
RUN chmod +x /usr/local/bin/gestia-entrypoint \
 && php artisan package:discover --ansi \
 && mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache /data /config
USER www-data
ENV XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data SERVER_NAME=:8000
EXPOSE 8000
ENTRYPOINT ["gestia-entrypoint"]
CMD ["frankenphp", "php-server", "--root", "/app/public", "--listen", ":8000"]
