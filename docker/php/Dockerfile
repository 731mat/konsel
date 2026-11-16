# syntax=docker/dockerfile:1
FROM composer:2 AS composer

FROM php:8.4-apache

ARG UID=1000
ARG GID=1000

# pdo_sqlite, sqlite3, mbstring, fileinfo jsou v oficiálním image už zkompilované
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip sqlite3 \
        libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" intl zip gd opcache pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

ENV APACHE_DOCUMENT_ROOT=/app/www \
    COMPOSER_HOME=/var/www/.composer

RUN a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!/app/!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# www-data = uživatel hostitele (soubory v bind mountu mají správného vlastníka i na Linuxu)
RUN groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g "${GID}" www-data \
    && mkdir -p /var/www/.composer \
    && chown -R www-data:www-data /var/www

COPY php.ini /usr/local/etc/php/conf.d/zz-konsel.ini
COPY apache.conf /etc/apache2/conf-available/konsel.conf
RUN a2enconf konsel

WORKDIR /app
