#!/bin/sh
# Arranque de producción (api, horizon, scheduler, reverb). Todo corre como www-data, el mismo usuario de php-fpm,
# así los logs y los archivos privados (firmas, XML, RIDE) nunca quedan con dueño root.
set -e

cd /var/www/html

# El volumen de almacenamiento privado puede llegar vacío o con otro dueño.
mkdir -p storage/private storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views
chown -R www-data:www-data storage bootstrap/cache

artisan() {
    su-exec www-data php artisan "$@"
}

# Sólo el contenedor api (FACSTO_RUN_MIGRATIONS=true): migraciones y catálogo SRI (idempotente, DatabaseSeeder
# sólo carga datos de prueba con APP_ENV=local). Horizon, el programador y Reverb esperan a que api esté sano.
if [ "${FACSTO_RUN_MIGRATIONS:-false}" = "true" ]; then
    artisan migrate --force
    artisan db:seed --force
fi

artisan config:cache
artisan route:cache
artisan event:cache

# php-fpm arranca como root y baja a www-data por su cuenta; el resto corre directamente como www-data.
if [ "$1" = "php-fpm" ]; then
    exec "$@"
fi

exec su-exec www-data "$@"
