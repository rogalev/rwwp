FROM php:8.3-cli-alpine

RUN apk add --no-cache \
        bash \
        git \
        icu-dev \
        libzip-dev \
        sqlite-dev \
        unzip \
        zip \
    && docker-php-ext-install \
        intl \
        opcache \
        pdo_sqlite \
        zip

COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /app
