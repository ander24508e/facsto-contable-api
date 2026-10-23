FROM php:8.4-cli-alpine AS base

RUN apk add --no-cache \
        curl \
        icu-dev \
        libxml2-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
        sqlite-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j2 bcmath dom intl mbstring pcntl pdo_pgsql pdo_sqlite xml xmlreader xmlwriter zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/zz-facsto-uploads.ini

WORKDIR /var/www/html

FROM base AS development

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts

COPY . .
RUN composer dump-autoload --optimize --no-interaction

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
