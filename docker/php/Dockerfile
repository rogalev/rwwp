FROM php:8.4-cli-alpine

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
        pcntl \
        pdo_sqlite \
        zip

COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /app
