# Imagen de producción de la API (demo en Render; en AWS se reutiliza igual).
# Toda la configuración llega por variables de entorno; no hay secretos aquí.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts \
    --prefer-dist --optimize-autoloader --ignore-platform-reqs

FROM php:8.5-cli-alpine
COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
# pdo_pgsql: PostgreSQL · gd: imágenes en los PDF (dompdf) · intl: formato de números y fechas
RUN install-php-extensions pdo_pgsql gd intl

WORKDIR /var/www/html
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && php artisan package:discover --ansi

# Render inyecta PORT; 2 workers por la poca memoria del plan gratis.
ENV PORT=10000 \
    PHP_CLI_SERVER_WORKERS=2
EXPOSE 10000

# La configuración y las rutas se cachean al arrancar, ya con las variables del entorno.
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && exec php artisan serve --host=0.0.0.0 --port=${PORT}"]
