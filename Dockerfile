# Etapa de compilación del firmador XAdES-BES: Maven sólo existe aquí, nunca en la imagen final.
FROM maven:3.9-eclipse-temurin-21-alpine AS xades-signer

WORKDIR /build
COPY tools/xades-signer/pom.xml ./
RUN mvn -B -q dependency:go-offline
COPY tools/xades-signer/src ./src
RUN mvn -B -q package

FROM php:8.4-cli-alpine AS base

RUN apk add --no-cache \
        curl \
        icu-dev \
        libxml2-dev \
        libzip-dev \
        oniguruma-dev \
        openjdk21-jre-headless \
        postgresql-dev \
        sqlite-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j2 bcmath dom intl mbstring pcntl pdo_pgsql pdo_sqlite xml xmlreader xmlwriter zip \
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
