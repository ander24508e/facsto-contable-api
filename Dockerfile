# Etapa de compilación del firmador XAdES-BES: Maven sólo existe aquí, nunca en la imagen final.
FROM maven:3.9-eclipse-temurin-21-alpine AS xades-signer

WORKDIR /build
COPY tools/xades-signer/pom.xml ./
RUN mvn -B -q dependency:go-offline
COPY tools/xades-signer/src ./src
RUN mvn -B -q package

# php-fpm trae también el binario php: desarrollo (artisan serve), Horizon, el programador y Reverb lo usan igual.
FROM php:8.4-fpm-alpine AS base

# gd: dompdf la necesita para las imágenes PNG/JPG (fotos y firma del informe de recepción de la Lavadora).
RUN apk add --no-cache \
        curl \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
        oniguruma-dev \
        openjdk21-jre-headless \
        postgresql-dev \
        sqlite-dev \
        su-exec \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 bcmath dom gd intl mbstring opcache pcntl pdo_pgsql pdo_sqlite xml xmlreader xmlwriter zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/zz-facsto-uploads.ini
# Fuera de /var/www/html: el volumen de desarrollo no lo oculta.
COPY --from=xades-signer /build/target/xades-signer.jar /opt/facsto/xades-signer.jar

WORKDIR /var/www/html

FROM base AS development

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts

COPY . .
RUN composer dump-autoload --optimize --no-interaction

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]

# Producción (Dokploy, compose.prod.yaml): php-fpm en el puerto 9000 detrás del nginx de la web.
FROM base AS production

ENV APP_ENV=production

COPY docker/production/php.ini /usr/local/etc/php/conf.d/zz-facsto-production.ini
COPY docker/production/entrypoint.sh /usr/local/bin/facsto-entrypoint

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-interaction \
    && rm -f bootstrap/cache/*.php \
    && php artisan package:discover --ansi \
    && mkdir -p storage/private storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/facsto-entrypoint

EXPOSE 9000

ENTRYPOINT ["facsto-entrypoint"]
CMD ["php-fpm"]
